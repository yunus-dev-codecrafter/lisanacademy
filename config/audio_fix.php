<?php
/* =========================================================
   config/audio_fix.php
   Audio normalization for uploads.

   Why this exists
   ---------------
    Student recordings used to be stored raw. That caused two kinds of
    broken playback:
      1. Large, non-streamable files — MediaRecorder m4a/webm blobs are big
         and every MP4-family file has its moov metadata atom at the END,
         so browsers cannot start playing until the whole file has been
         downloaded (the player sits on "pause/loading" for a long time).
      2. Unplayable-as-audio files — some students record VIDEO
         (.mov/.mp4 with an H.264 track, e.g. iPhone camera with the lens
         covered) which an <audio> tag can never decode. These must play
         in a <video> tag instead of being rejected.

    Strategy (in priority order)
    ----------------------------
    * If ffmpeg/ffprobe is available (VPS etc.) transcode audio-only files
      to a small, universally-playable MP3 (strip any video track only for
      pure-audio destinations — NEVER for student submissions, where the
      original video must be kept so the admin can hear it).
    * Student/exam/hafiz submissions ACCEPT both audio and video: MP4
      family (m4a/mp4/mov/m4v/3gp) is kept as-is with a pure-PHP faststart
      rewrite (moov moved in front of mdat) so playback starts instantly
      in <audio> (audio-only) or <video> (camera recordings).
    * Otherwise, pure-PHP fallback that works on shared hosts (InfinityFree):
        - MP4 family  -> move moov in front of mdat ("faststart") and fix
                         the chunk-offset tables so playback starts instantly.
        - everything else stays as-is (extension normalized to its real
          container so the right MIME type is served).
    * Server-side size cap so absurdly large files are refused early.
   ========================================================= */

if (!defined('AUDIO_MAX_UPLOAD_BYTES')) {
    define('AUDIO_MAX_UPLOAD_BYTES', 20 * 1024 * 1024); // 20 MB
}

/* ------------------------------------------------------------------ */
/* Small binary helpers                                                */
/* ------------------------------------------------------------------ */

if (!function_exists('audio_is_windows')) {
    function audio_is_windows() {
        return stripos(PHP_OS, 'WIN') === 0;
    }
}

if (!function_exists('audio_read_uint32')) {
    /* Big-endian unsigned 32-bit at $offset inside $bin. */
    function audio_read_uint32($bin, $offset) {
        return (ord($bin[$offset]) << 24)
             | (ord($bin[$offset + 1]) << 16)
             | (ord($bin[$offset + 2]) << 8)
             | ord($bin[$offset + 3]);
    }
}

if (!function_exists('audio_read_uint64')) {
    /* Big-endian unsigned 64-bit at $offset, assembled manually so it also
       works on 32-bit PHP builds. */
    function audio_read_uint64($bin, $offset) {
        $hi = audio_read_uint32($bin, $offset);
        $lo = audio_read_uint32($bin, $offset + 4);
        return ($hi * 4294967296) + $lo;
    }
}

if (!function_exists('audio_write_uint32')) {
    function audio_write_uint32($value) {
        return pack('N', $value & 0xFFFFFFFF);
    }
}

if (!function_exists('audio_write_uint64')) {
    function audio_write_uint64($value) {
        return pack('N2', intdiv($value, 4294967296), $value % 4294967296);
    }
}

/* ------------------------------------------------------------------ */
/* Tool detection (ffmpeg)                                             */
/* ------------------------------------------------------------------ */

if (!function_exists('audio_exec_available')) {
    function audio_exec_available() {
        if (!function_exists('exec')) return false;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }
}

if (!function_exists('audio_ffmpeg_bin')) {
    function audio_ffmpeg_bin() {
        static $cached = false;
        if ($cached !== false) return $cached;

        $candidates = array();
        if (audio_is_windows()) {
            $candidates[] = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
            $candidates[] = 'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe';
            $candidates[] = 'ffmpeg.exe';
        } else {
            $candidates[] = '/usr/bin/ffmpeg';
            $candidates[] = '/usr/local/bin/ffmpeg';
            $candidates[] = '/usr/bin/ffmpeg/ffmpeg';
            $candidates[] = 'ffmpeg';
        }

        $can_exec = audio_exec_available();
        foreach ($candidates as $bin) {
            if (strpos($bin, DIRECTORY_SEPARATOR) !== false || strpos($bin, '/') !== false) {
                if (is_file($bin)) {
                    $cached = $bin;
                    return $bin;
                }
                continue;
            }
            /* bare name — needs exec() */
            if (!$can_exec) continue;
            $nullOut = audio_is_windows() ? 'NUL' : '/dev/null';
            $out = array();
            $rc  = 1;
            @exec(escapeshellarg($bin) . ' -version 2>&1', $out, $rc);
            if ($rc === 0 && count($out) > 0 && stripos($out[0], 'ffmpeg') !== false) {
                $cached = $bin;
                return $bin;
            }
        }

        $cached = '';
        return '';
    }
}

if (!function_exists('audio_transcode_to_mp3')) {
    /* Re-encode $src into $dest (MP3, mono, low bitrate — speech quality).
       Returns true on success. $src/$dest are absolute paths. */
    function audio_transcode_to_mp3($src, $dest) {
        $ffmpeg = audio_ffmpeg_bin();
        if ($ffmpeg === '') return false;

        $nullOut = audio_is_windows() ? 'NUL' : '/dev/null';
        $cmd = escapeshellarg($ffmpeg)
             . ' -y -i ' . escapeshellarg($src)
             . ' -vn -ac 1 -ar 16000 -b:a 32k -f mp3 '
             . escapeshellarg($dest) . ' > ' . $nullOut . ' 2>&1';

        $rc = 1;
        @exec($cmd, $junk, $rc);
        return ($rc === 0) && is_file($dest) && filesize($dest) > 0;
    }
}

/* ------------------------------------------------------------------ */
/* Container sniffing                                                  */
/* ------------------------------------------------------------------ */

if (!function_exists('audio_sniff_type')) {
    /* Returns 'mp4'|'webm'|'ogg'|'mp3'|'aac'|'wav'|'' from the raw bytes. */
    function audio_sniff_type($bin) {
        $len = strlen($bin);
        if ($len < 8) return '';

        /* MP4-family: 'ftyp' box appears at byte 4..8 (offset 0..4 is the
           box size). Major brand tells us the flavour (qt  = QuickTime). */
        if (substr($bin, 4, 4) === 'ftyp') return 'mp4';
        if (substr($bin, 0, 4) === "\x1A\x45\xDF\xA3") return 'webm'; // EBML (Matroska/WebM)
        if (substr($bin, 0, 4) === 'OggS') return 'ogg';
        if (substr($bin, 0, 3) === 'ID3') return 'mp3';
        if (substr($bin, 0, 4) === 'RIFF' && $len >= 12 && substr($bin, 8, 4) === 'WAVE') return 'wav';

        /* Raw ADTS AAC starts with syncword 0xFFF */
        if ($len >= 2 && ord($bin[0]) === 0xFF && (ord($bin[1]) & 0xF6) === 0xF0) return 'aac';
        /* MP3 without an ID3 tag (0xFFE = 11-bit frame sync) */
        if ($len >= 2 && ord($bin[0]) === 0xFF && (ord($bin[1]) & 0xE0) === 0xE0) return 'mp3';

        return '';
    }
}

if (!function_exists('audio_top_level_boxes')) {
    /* Parse top-level ISO-BMFF boxes. Returns list of arrays with
       type / start / size / end. Handles 32-bit, 64-bit and 0 (to-end). */
    function audio_top_level_boxes($bin) {
        $boxes = array();
        $len   = strlen($bin);
        $pos   = 0;
        while ($pos + 8 <= $len) {
            $size  = audio_read_uint32($bin, $pos);
            $type  = substr($bin, $pos + 4, 4);
            $hdr   = 8;
            if ($size === 1 && $pos + 16 <= $len) {
                $size = audio_read_uint64($bin, $pos + 8);
                $hdr  = 16;
            } elseif ($size === 0) {
                $size = $len - $pos;
            }
            if ($pos + $size > $len) $size = $len - $pos;
            if ($size < $hdr) break;
            $boxes[] = array(
                'type'  => $type,
                'start' => $pos,
                'size'  => $size,
                'end'   => $pos + $size,
                'hdr'   => $hdr,
            );
            $pos += $size;
            if (!ctype_alnum($type)) break; // sanity: not a real MP4
        }
        return $boxes;
    }
}

if (!function_exists('audio_detect_video_track')) {
    /* True if the MP4-family file contains a video track (H.264/HEVC/AV1/
       VP9/MPEG-4 sample entries inside moov). Scans the exact moov box span
       so media data inside mdat can never cause false positives. */
    function audio_detect_video_track($bin) {
        $boxes = audio_top_level_boxes($bin);
        $moov  = null;
        foreach ($boxes as $b) {
            if ($b['type'] === 'moov') { $moov = $b; break; }
        }
        if ($moov === null) return false;

        $region = substr($bin, $moov['start'], $moov['size']);
        $videoCodes = array('avc1', 'hvc1', 'hev1', 'av01', 'vp09', 'mp4v', 'dvhe', 'vp08', 'vpx ');
        foreach ($videoCodes as $c) {
            if (strpos($region, $c) !== false) return true;
        }
        /* Track handler "vide" */
        if (strpos($region, 'vide') !== false) return true;
        return false;
    }
}

if (!function_exists('audio_moov_after_mdat')) {
    /* True when the moov atom comes after mdat (content requires the whole
       file download before playback can start). */
    function audio_moov_after_mdat($bin) {
        $moov = strpos($bin, 'moov');
        $mdat = strpos($bin, 'mdat');
        if ($moov === false || $mdat === false) return false;
        return $moov > $mdat;
    }
}

/* ------------------------------------------------------------------ */
/* Pure-PHP MP4 "faststart": move moov before mdat and adjust offsets  */
/* ------------------------------------------------------------------ */

if (!function_exists('audio_is_container_box')) {
    function audio_is_container_box($type) {
        return in_array($type, array(
            'moov', 'trak', 'mdia', 'minf', 'stbl', 'dinf', 'edts',
            'mvex', 'moof', 'traf', 'mfra', 'udta', 'meta',
        ), true);
    }
}

if (!function_exists('audio_collect_chunk_offsets')) {
    /* Recursively walk the boxes inside a moov region and record the byte
       offsets of the stco/co64 entry tables so audio_mp4_faststart can
       patch them (stco lives under trak → mdia → minf → stbl). */
    function audio_collect_chunk_offsets($bin, $start, $end) {
        $found = array();
        $pos   = $start;
        while ($pos + 8 <= $end) {
            $size = audio_read_uint32($bin, $pos);
            $type = substr($bin, $pos + 4, 4);
            $hdr  = 8;
            if ($size === 1 && $pos + 16 <= $end) {
                $size = audio_read_uint64($bin, $pos + 8);
                $hdr  = 16;
            } elseif ($size === 0) {
                $size = $end - $pos;
            }
            if ($pos + $size > $end) $size = $end - $pos;
            if ($size < $hdr) { $pos += 8; continue; }
            $boxEnd = $pos + $size;

            if ($type === 'stco' || $type === 'co64') {
                /* box: [size][type][version+flags(4)][entry_count(4)][entries...] */
                if ($pos + $hdr + 8 <= $end) {
                    $tablePos  = $pos + $hdr + 4;
                    $count     = audio_read_uint32($bin, $tablePos);
                    $payload   = $tablePos + 4;
                    $found[] = array(
                        'type'    => $type,
                        'payload' => $payload,
                        'count'   => $count,
                    );
                }
            }

            if (audio_is_container_box($type)) {
                $inner = audio_collect_chunk_offsets($bin, $pos + $hdr, $boxEnd);
                foreach ($inner as $f) $found[] = $f;
            }

            $pos += $size;
        }
        return $found;
    }
}

if (!function_exists('audio_mp4_faststart')) {
    /* Rebuild $bin so that moov sits right after ftyp (before mdat) and
       patch stco/co64 tables by the amount mdat shifts. Returns the
       rewritten file bytes, or null when nothing needs doing / unsafe. */
    function audio_mp4_faststart($bin) {
        $boxes = audio_top_level_boxes($bin);
        if (count($boxes) === 0) return null;

        $moovIdx = null;
        $mdatIdx = null;
        foreach ($boxes as $i => $b) {
            if ($b['type'] === 'moov') $moovIdx = $i;
            if ($b['type'] === 'mdat') $mdatIdx = $i;
        }
        if ($moovIdx === null || $mdatIdx === null) return null;

        $moov = $boxes[$moovIdx];
        $mdat = $boxes[$mdatIdx];

        /* Already faststart (moov before mdat) — nothing to do. */
        if ($moov['start'] < $mdat['start']) return $bin;

        /* Extract moov raw bytes (patched with new chunk offsets later). */
        $moovBin = substr($bin, $moov['start'], $moov['size']);

        /* New layout: everything except moov keeps its original relative
           order, with moov inserted immediately after the first ftyp box. */
        $order = array();
        foreach ($boxes as $i => $b) {
            if ($i === $moovIdx) continue;
            $order[] = $b;
        }

        /* First pass: decide where moov will live and record the new
           absolute offset of every other box, with moov's size included
           in the running position at its slot. */
        $slotHappened = false;
        $moovSlot     = null;
        $placed       = array();   // old box start => new absolute offset
        $newPos       = 0;
        foreach ($order as $b) {
            if (!$slotHappened && $newPos > 0) {
                /* moov goes right after whichever box is first (ftyp). */
                $moovSlot    = $newPos;
                $newPos     += $moov['size'];
                $slotHappened = true;
            }
            $placed[$b['start']] = $newPos;
            $newPos += $b['size'];
        }
        if (!$slotHappened) {
            /* No leading box to follow — prepend moov. */
            $moovSlot = 0;
            foreach ($placed as $old => $new) {
                $placed[$old] = $new + $moov['size'];
            }
            $newPos += $moov['size'];
        }

        $oldMdatStart = $mdat['start'];
        $newMdatStart = $placed[$oldMdatStart];
        $delta = $newMdatStart - $oldMdatStart;

        /* Patch stco/co64 inside the moov copy by the mdat shift. */
        $moovEnd = strlen($moovBin);
        $tables = audio_collect_chunk_offsets($moovBin, 0, $moovEnd);
        foreach ($tables as $t) {
            $entrySize = ($t['type'] === 'co64') ? 8 : 4;
            for ($n = 0; $n < $t['count']; $n++) {
                $at = $t['payload'] + ($n * $entrySize);
                if ($at + $entrySize > $moovEnd) break;
                if ($entrySize === 4) {
                    $val = audio_read_uint32($moovBin, $at);
                    $moovBin = substr_replace($moovBin, audio_write_uint32($val + $delta), $at, 4);
                } else {
                    $val = audio_read_uint64($moovBin, $at);
                    $moovBin = substr_replace($moovBin, audio_write_uint64($val + $delta), $at, 8);
                }
            }
        }

        /* Assemble: non-moov boxes in order, then splice patched moov in. */
        $out = '';
        foreach ($order as $b) {
            $out .= substr($bin, $b['start'], $b['size']);
        }
        $out = substr($out, 0, $moovSlot) . $moovBin . substr($out, $moovSlot);

        if (strlen($out) !== strlen($bin)) return null; // must be lossless

        return $out;
    }
}

if (!function_exists('audio_write_file_atomic')) {
    /* Write bytes to a temp file in the same dir, then rename over $target. */
    function audio_write_file_atomic($target, $bytes) {
        $dir  = dirname($target);
        $tmp  = $dir . '/' . uniqid('audio_tmp_', true);
        if (file_put_contents($tmp, $bytes) !== strlen($bytes)) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}

/* ------------------------------------------------------------------ */
/* Friendly errors                                                     */
/* ------------------------------------------------------------------ */

if (!function_exists('audio_error_video')) {
    function audio_error_video() {
        return 'That file is a video. It has been saved and can be played '
             . 'in the video player below — listen to the audio track to '
             . 'review the recitation.';
    }
}

if (!function_exists('audio_error_unknown')) {
    function audio_error_unknown($ext) {
        return 'This file could not be read as audio (' . ($ext === '' ? 'no file extension' : htmlspecialchars($ext)) . '). '
             . 'Please record using the in-app recorder, or upload a voice memo (.m4a/.mp3/.webm).';
    }
}

/* ------------------------------------------------------------------ */
/* Main entry point                                                    */
/* ------------------------------------------------------------------ */

if (!function_exists('audio_real_ext')) {
    /* Pick a correct, streamable extension for a sniffed container.
       MP4-family video originals (mov/mp4/m4v/3gp) keep their extension so
       the admin <video> player serves the right MIME type. */
    function audio_real_ext($sniffed, $origExt) {
        switch ($sniffed) {
            case 'mp4':
                if (in_array($origExt, array('m4a', 'mp4', 'mov', 'm4v', '3gp', '3gpp'), true)) return $origExt;
                return 'm4a';
            case 'webm': return 'webm';
            case 'ogg':  return 'ogg';
            case 'mp3':  return 'mp3';
            case 'aac':  return 'aac';
            case 'wav':  return 'wav';
        }
        return 'webm';
    }
}

if (!function_exists('media_is_video_ext')) {
    /* True when the stored file needs a <video> tag (has or may have a
       video track). Audio-only m4a/mp3/webm/ogg/wav/aac use <audio>. */
    function media_is_video_ext($filename) {
        $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
        return in_array($ext, array('mov', 'mp4', 'm4v', '3gp', '3gpp'), true);
    }
}

if (!function_exists('media_player_html')) {
    /* Echo-safe player: <video> for camera recordings, <audio> otherwise.
       $baseUrl is e.g. '../uploads/student_audio/' (with trailing slash). */
    function media_player_html($filename, $baseUrl) {
        $url  = rtrim($baseUrl, '/') . '/' . rawurlencode((string)$filename);
        // rawurlencode encodes for URL path; keep it safe for HTML too.
        $url  = htmlspecialchars($url, ENT_QUOTES);
        if (media_is_video_ext($filename)) {
            return '<video controls preload="metadata" playsinline style="width:100%;max-width:520px;border-radius:12px;background:#000;" src="' . $url . '">'
                 . 'Your browser cannot play this video. <a href="' . $url . '" download>Download it</a>.</video>';
        }
        return '<audio controls preload="metadata" style="width:100%;" src="' . $url . '">'
             . 'Your browser cannot play this audio. <a href="' . $url . '" download>Download it</a>.</audio>';
    }
}

if (!function_exists('audio_save_upload')) {
    /**
     * Process an uploaded audio OR VIDEO file and store a normalized copy.
     *
     * Student iPhone camera recordings (.mov/.mp4 with a video track) are
     * ACCEPTED — they are faststarted in pure PHP so the admin <video>
     * player starts instantly. Nothing is ever transcoded away on shared
     * hosts without ffmpeg; audio-only uploads keep their prior behaviour.
     *
     * @param string $tmpPath  path of the uploaded temp file
     * @param string $destDir  absolute destination directory (must exist)
     * @param string $prefix   filename prefix, e.g. 'student_'
     * @param string $origName original upload file name (used for its extension)
     *
     * @return array  ['ok'=>bool, 'file'=>saved filename|null, 'error'=>msg]
     */
    function audio_save_upload($tmpPath, $destDir, $prefix, $origName = '') {
        if (!is_file($tmpPath)) {
            return array('ok' => false, 'file' => null, 'error' => 'Upload failed.');
        }

        $size = filesize($tmpPath);
        if ($size <= 0) {
            return array('ok' => false, 'file' => null, 'error' => 'The uploaded file is empty.');
        }
        if ($size > AUDIO_MAX_UPLOAD_BYTES) {
            $mb = number_format(AUDIO_MAX_UPLOAD_BYTES / 1048576, 0);
            return array('ok' => false, 'file' => null, 'error' => 'That file is too large (' . number_format($size / 1048576, 1) . ' MB). Maximum allowed is ' . $mb . ' MB.');
        }

        $bin = @file_get_contents($tmpPath);
        if ($bin === false || $bin === '') {
            return array('ok' => false, 'file' => null, 'error' => 'Could not read the uploaded file.');
        }

        $sniffed = audio_sniff_type($bin);
        if ($sniffed === '') {
            return array('ok' => false, 'file' => null, 'error' => audio_error_unknown(pathinfo($origName, PATHINFO_EXTENSION)));
        }

        $isVideo = ($sniffed === 'mp4') && audio_detect_video_track($bin);
        $ext = audio_real_ext($sniffed, strtolower(pathinfo($origName, PATHINFO_EXTENSION)));

        $base = $prefix . time() . '_' . rand(1000, 9999);

        $ffmpeg = audio_ffmpeg_bin();

        /* ---- 1. Video files: ACCEPT and faststart (never reject) ----- */
        if ($isVideo) {
            /* On hosts with ffmpeg, keep the original video as-is for
               student submissions (admin needs the audio track audible in
               a <video> tag). Transcoding to MP3 would discard the upload
               the student actually made, so only faststart here. */
            $fixed = audio_mp4_faststart($bin);
            if ($fixed !== null) $bin = $fixed;
            $target = $destDir . '/' . $base . '.' . $ext;
            if (!audio_write_file_atomic($target, $bin)) {
                return array('ok' => false, 'file' => null, 'error' => 'Could not save the recording. Please try again.');
            }
            return array('ok' => true, 'file' => basename($target), 'error' => '');
        }

        /* ---- 2. ffmpeg available: transcode audio-only to MP3 --------- */
        if ($ffmpeg !== '' && $sniffed !== 'mp3' && !$isVideo) {
            $dest = $destDir . '/' . $base . '.mp3';
            $work = $destDir . '/' . uniqid('trans_', true);
            copy($tmpPath, $work);
            $ok = audio_transcode_to_mp3($work, $dest);
            @unlink($work);
            if ($ok) {
                return array('ok' => true, 'file' => basename($dest), 'error' => '');
            }
            /* fall through to pure-PHP path on transcode failure */
        }

        /* ---- 3. Pure-PHP fallback -------------------------------- */
        if ($sniffed === 'mp4') {
            $fixed = audio_mp4_faststart($bin);
            if ($fixed !== null) $bin = $fixed;
        }

        $target = $destDir . '/' . $base . '.' . $ext;
        if (!audio_write_file_atomic($target, $bin)) {
            return array('ok' => false, 'file' => null, 'error' => 'Could not save the audio file. Please try again.');
        }

        return array('ok' => true, 'file' => basename($target), 'error' => '');
    }
}

/* ------------------------------------------------------------------ */
/* Analysis helper for the admin fix page                              */
/* ------------------------------------------------------------------ */

if (!function_exists('audio_analyze_file')) {
    /**
     * Return a human-readable report for one stored audio file.
     * @param string $path absolute path to the file
     * @return array type,size,video,slow,ext,note
     */
    function audio_analyze_file($path) {
        $out = array(
            'name'   => basename($path),
            'size'   => is_file($path) ? filesize($path) : 0,
            'type'   => '?',
            'video'  => false,
            'slow'   => false,
            'note'   => '',
        );
        if (!is_file($path)) {
            $out['note'] = 'File missing on disk.';
            return $out;
        }
        $bin = @file_get_contents($path);
        if ($bin === false || $bin === '') {
            $out['note'] = 'Could not read file.';
            return $out;
        }
        $out['type'] = audio_sniff_type($bin);
        if ($out['type'] === '') {
            $out['note'] = 'Not a recognized audio/video container.';
            return $out;
        }
        if ($out['type'] === 'mp4') {
            $out['video'] = audio_detect_video_track($bin);
            $out['slow']  = audio_moov_after_mdat($bin);
            if ($out['slow'] && !$out['video']) $out['note'] = 'moov at end — faststart can fix';
            if ($out['video'] && $out['slow'])  $out['note'] = 'Video, moov at end — faststart can fix, plays in <video> player';
            if ($out['video'] && !$out['slow']) $out['note'] = 'Video (e.g. iPhone camera) — plays in <video> player, audio audible';
        } elseif ($out['type'] === 'webm') {
            $out['note'] = 'WebM/Opus — plays in Chrome/Edge/Firefox, not on iOS Safari';
        } elseif ($out['type'] === 'ogg') {
            $out['note'] = 'Ogg — plays in Chrome/Firefox, not on iOS Safari';
        } elseif ($out['type'] === 'aac') {
            $out['note'] = 'Raw AAC (ADTS) — playback varies by browser';
        } elseif ($out['type'] === 'mp3') {
            $out['note'] = 'MP3 — plays everywhere';
        } elseif ($out['type'] === 'wav') {
            $out['note'] = 'WAV — plays everywhere (large)';
        }
        return $out;
    }
}

if (!function_exists('audio_fix_file')) {
    /* Run faststart (and/or transcode) on an existing stored file in place.
       Returns array ['changed'=>bool,'note'=>string]. */
    function audio_fix_file($path) {
        if (!is_file($path)) {
            return array('changed' => false, 'note' => 'Missing');
        }
        $bin = @file_get_contents($path);
        if ($bin === false || $bin === '') {
            return array('changed' => false, 'note' => 'Unreadable');
        }

        $sniffed = audio_sniff_type($bin);
        if ($sniffed === '') {
            return array('changed' => false, 'note' => 'Unknown container');
        }

        /* Videos (e.g. iPhone camera .mov): faststart rewrite in place so
           they start instantly in the admin <video> player. */
        if ($sniffed === 'mp4' && audio_detect_video_track($bin)) {
            $fixed = audio_mp4_faststart($bin);
            if ($fixed !== null && $fixed !== $bin) {
                if (audio_write_file_atomic($path, $fixed)) {
                    return array('changed' => true, 'note' => 'faststart applied to video (moov moved to front)');
                }
                return array('changed' => false, 'note' => 'faststart write failed');
            }
            return array('changed' => false, 'note' => 'video already stream-friendly — plays in <video> player');
        }

        /* MP4 audio: faststart rewrite in place. */
        if ($sniffed === 'mp4') {
            $fixed = audio_mp4_faststart($bin);
            if ($fixed !== null && $fixed !== $bin) {
                if (audio_write_file_atomic($path, $fixed)) {
                    return array('changed' => true, 'note' => 'faststart applied (moov moved to front)');
                }
                return array('changed' => false, 'note' => 'faststart write failed');
            }
            return array('changed' => false, 'note' => 'already stream-friendly');
        }

        return array('changed' => false, 'note' => 'No change needed');
    }
}
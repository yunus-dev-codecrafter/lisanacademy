/* =========================================================
   assets/js/recorder.js
   Shared MediaRecorder helper — Safari/iOS safe.

   Why this exists
   ---------------
   Every recorder page duplicated the same MIME/options snippet, and a
   recent change added `videoBitsPerSecond: 0` to an audio-only
   recorder. Chrome tolerates it, Safari throws NotSupportedError or
   produces an empty blob, which broke iPhone submissions.

   Usage
   -----
     const rec = Recorder.pick(); // {mime, ext}
     const mr = Recorder.create(stream, rec.mime);
     ...
     const blob = Recorder.makeBlob(chunks, mr, rec.mime);
   ========================================================= */
(function () {
    'use strict';

    function pickMime() {
        try {
            if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
            if (MediaRecorder.isTypeSupported('audio/mp4')) return 'audio/mp4';
            if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) return 'audio/webm;codecs=opus';
            if (MediaRecorder.isTypeSupported('audio/webm')) return 'audio/webm';
        } catch (e) { /* ignore */ }
        return '';
    }

    function pick() {
        var mime = pickMime();
        return { mime: mime, ext: mime === 'audio/mp4' ? 'm4a' : 'webm' };
    }

    /* Safari-safe constructor: never pass videoBitsPerSecond for an
       audio-only stream. Fall back to bare options, then undefined. */
    function create(stream, mime) {
        var withMime = mime ? { mimeType: mime } : undefined;
        // Try a modest audio bitrate first (Chrome/Edge/Firefox OK).
        var candidates = [];
        if (withMime) {
            candidates.push({ mimeType: mime, audioBitsPerSecond: 64000 });
            candidates.push({ mimeType: mime });
        } else {
            candidates.push({ audioBitsPerSecond: 64000 });
            candidates.push(undefined);
        }
        var lastErr = null;
        for (var i = 0; i < candidates.length; i++) {
            try {
                return new MediaRecorder(stream, candidates[i]);
            } catch (e) {
                lastErr = e;
            }
        }
        // Final attempt without options so the error surfaces clearly.
        try {
            return new MediaRecorder(stream);
        } catch (e) {
            throw lastErr || e;
        }
    }

    /* Build a playable Blob: prefer the recorder's real mimeType, then
       the first chunk's type, then the picked mime. Never force webm. */
    function makeBlob(chunks, recorder, fallbackMime) {
        var type = '';
        try {
            if (recorder && recorder.mimeType) type = recorder.mimeType;
        } catch (e) { /* ignore */ }
        if (!type && chunks && chunks.length && chunks[0] && chunks[0].type) {
            type = chunks[0].type;
        }
        if (!type) type = fallbackMime || pickMime() || 'audio/mp4';
        return new Blob(chunks, { type: type });
    }

    function supported() {
        return !!(window.MediaRecorder && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }

    window.Recorder = {
        pick: pick,
        pickMime: pickMime,
        create: create,
        makeBlob: makeBlob,
        supported: supported
    };
})();

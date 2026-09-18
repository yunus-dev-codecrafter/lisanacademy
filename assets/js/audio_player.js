/* =========================================================
   assets/js/audio_player.js
   Playback hardening for <audio> elements across the app.

   Browser <audio> tags silently fail on:
     - video files recorded as audio (.mov/.mp4 with a video track)
     - Ogg / raw-AAC files Safari and some browsers cannot decode
     - huge non-streamable MP4s that never finish buffering

   This script:
     1. Forces preload="metadata" so errors surface immediately
        instead of an endless spinner.
     2. For .mov/.mp4 sources, swaps the <audio> for a <video> tag
        (those files may actually be videos and will play that way).
     3. If playback still fails, replaces the player with a
        clear message + a download link, so the student/admin is
        never left staring at a dead player.
   ========================================================= */
(function () {
    'use strict';
    if (!window.MediaSource && !document.createElement('audio').play) return;

    var FALLBACK_LOADED = true;

    function getUrl(el) {
        return el.currentSrc || el.getAttribute('src') || '';
    }

    function looksVideo(url) {
        return /\.(mov|mp4|m4v)$/i.test(url);
    }

    function frag(html) {
        var t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstChild;
    }

    function showDownload(container, url, reason) {
        var box = frag(
            '<div class="audio-fallback" style="display:flex;justify-content:space-between;' +
            'align-items:center;gap:10px;padding:10px 14px;border:1px solid rgba(0,0,0,.15);' +
            'border-radius:12px;background:#fff;flex-wrap:wrap;">' +
            '<div style="flex:1;min-width:180px;font-size:.85rem;color:#555;">' +
            '<strong>Playback failed</strong> &mdash; this recording browsers can&rsquo;t play inline.' +
            (reason ? ' ' + reason : '') +
            '</div>' +
            '<a class="btn btn-sm" href="' + url.replace(/"/g, '&quot;') + '" download>' +
            '&#11015; Download recording</a>' +
            '</div>'
        );
        if (container && container.parentNode) {
            container.parentNode.insertBefore(box, container);
        }
        if (container) container.remove();
    }

    function tryVideo(audio, url) {
        var video = document.createElement('video');
        video.controls = true;
        video.preload = 'metadata';
        video.style.width = '100%';
        video.style.display = 'block';
        video.src = url;
        audio.parentNode.insertBefore(video, audio);
        audio.remove();
        video.addEventListener('error', function () {
            showDownload(video, url, 'Although this file plays on phones, it could not be loaded here.');
        });
    }

    function bindAudio(audio) {
        if (audio.__audioFallbackBound) return;
        audio.__audioFallbackBound = true;

        audio.preload = 'metadata';
        audio.addEventListener('error', function () {
            var url = getUrl(audio);
            if (!url) return;
            if (looksVideo(url)) {
                tryVideo(audio, url);
            } else {
                showDownload(audio, url);
            }
        });
    }

    function scan() {
        var els = document.querySelectorAll('audio');
        for (var i = 0; i < els.length; i++) bindAudio(els[i]);
    }

    scan();

    /* Pick up <audio> elements added dynamically (recorder previews etc.). */
    if (window.MutationObserver) {
        var mo = new MutationObserver(scan);
        document.addEventListener('DOMContentLoaded', function () {
            mo.observe(document.body, { childList: true, subtree: true });
        });
    }
    window.AUDIO_PLAYER_JS = FALLBACK_LOADED;
})();
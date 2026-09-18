/* =========================================================
   assets/js/audio_player.js
   Playback hardening for <audio> elements across the app.

   Browser <audio> tags silently fail on:
     - video files recorded as audio (.mov/.mp4 with a video track)
     - Ogg / raw-AAC files Safari and some browsers cannot decode
     - huge non-streamable MP4s that never finish buffering
     - recordings whose file is missing on the server

   This script:
     1. Forces preload="metadata" so errors surface immediately
        instead of an endless spinner.
     2. For .mov/.mp4 sources, swaps the <audio> for a <video> tag
        (those files may actually be videos and will play that way).
     3. If playback still fails, checks whether the file actually
        exists on the server (HEAD request) so the user gets:
          - a download link, when the file exists but can't play inline
          - a clear "file missing" message, when the server says 404
     (before, a dead "Download recording" button would appear for
     recordings that were never uploaded, and clicking it gave a 404)
   ========================================================= */
(function () {
    'use strict';

    function getUrl(el) {
        return el.currentSrc || el.getAttribute('src') || '';
    }

    /* Turn any relative src (e.g. "../uploads/...") into an absolute URL
       exactly the way the browser would resolve the <audio> source. */
    function absUrl(url) {
        if (!url) return '';
        if (/^(?:[a-z]+:)?\/\//i.test(url) || url.indexOf('blob:') === 0) return url;
        var t = document.createElement('a');
        t.href = url;
        return t.href;
    }

    function looksVideo(url) {
        return /\.(mov|mp4|m4v)$/i.test(url);
    }

    function fileName(url) {
        try {
            var u = new URL(url);
            var seg = u.pathname.split('/').filter(Boolean);
            return seg.length ? decodeURIComponent(seg[seg.length - 1]) : url;
        } catch (e) {
            var parts = url.split('/');
            return parts.length ? decodeURIComponent(parts[parts.length - 1]) : url;
        }
    }

    function makeDownloadBox(absUrl, reason) {
        var text = document.createElement('div');
        var strong = document.createElement('strong');
        strong.textContent = 'Playback failed';
        text.appendChild(strong);
        text.appendChild(document.createTextNode(
            ' \u2014 this recording browsers can\u2019t play inline.' + (reason ? ' ' + reason : '')
        ));

        var a = document.createElement('a');
        a.className = 'btn btn-sm';
        a.setAttribute('download', '');
        a.href = absUrl;
        a.textContent = '\u2B07 Download recording';

        var box = document.createElement('div');
        box.style.cssText = 'display:flex;justify-content:space-between;align-items:center;' +
            'gap:10px;padding:10px 14px;border:1px solid rgba(0,0,0,.15);' +
            'border-radius:12px;background:#fff;flex-wrap:wrap;';
        var textBox = document.createElement('div');
        textBox.style.cssText = 'flex:1;min-width:180px;font-size:.85rem;color:#555;';
        textBox.appendChild(text);
        box.appendChild(textBox);
        box.appendChild(a);
        return box;
    }

    function makeMissingBox(url) {
        var box = document.createElement('div');
        box.style.cssText = 'display:flex;align-items:center;gap:10px;padding:10px 14px;' +
            'border:1px solid rgba(180,0,0,.25);border-radius:12px;background:#fff7f7;' +
            'flex-wrap:wrap;';
        var icon = document.createElement('span');
        icon.textContent = '\u26A0';
        icon.style.cssText = 'font-size:1.1rem;';
        var text = document.createElement('div');
        text.style.cssText = 'flex:1;min-width:180px;font-size:.85rem;color:#a33;';
        var strong = document.createElement('strong');
        strong.textContent = 'Recording file missing on the server';
        text.appendChild(strong);
        text.appendChild(document.createTextNode(
            ' (' + fileName(url) + '). This file was never uploaded, so there is nothing to play or download. ' +
            'Please ask the student to re-record with the in-app recorder.'
        ));
        box.appendChild(icon);
        box.appendChild(text);
        return box;
    }

    /* Ask the server whether the file exists (HEAD is lightweight). */
    function fileStatus(absUrl) {
        var p = window.fetch(absUrl, { method: 'HEAD', credentials: 'same-origin' })
            .then(function (res) {
                return res.status;
            });
        var timeout = new Promise(function (resolve) {
            setTimeout(resolve, 8000);
        });
        return Promise.race([p, timeout]).then(function (status) {
            return status === undefined ? 0 : status;
        });
    }

    function install(result) {
        var target = result.container;
        /* The container may already have been replaced (video fallback). */
        if (!target || !target.parentNode) {
            /* Re-insert somewhere sensible: fall back to the page body. */
            document.body.appendChild(result.box);
            return;
        }
        target.parentNode.insertBefore(result.box, target);
        target.remove();
    }

    function handlePlaybackFailure(container, url, reason) {
        var abs = absUrl(url);
        var box = makeDownloadBox(abs, reason || '');
        fileStatus(abs).then(function (status) {
            if (status === 404 || status === 410) {
                install({ container: container, box: makeMissingBox(abs) });
            } else if (status === 403) {
                install({ container: container, box: makeMissingBox(abs) });
            } else {
                install({ container: container, box: box });
            }
        });
    }

    function tryVideo(audio, url) {
        var abs = absUrl(url);
        var video = document.createElement('video');
        video.controls = true;
        video.preload = 'metadata';
        video.style.width = '100%';
        video.style.display = 'block';
        video.src = abs;
        audio.parentNode.insertBefore(video, audio);
        audio.remove();
        video.addEventListener('error', function () {
            handlePlaybackFailure(video, abs, 'Although this file plays on phones, it could not be loaded here.');
        });
    }

    function bindAudio(audio) {
        if (audio.__audioFallbackBound) return;
        audio.__audioFallbackBound = true;

        audio.preload = 'metadata';
        audio.addEventListener('error', function () {
            var url = getUrl(audio);
            if (!url) return;
            var abs = absUrl(url);
            if (looksVideo(abs)) {
                tryVideo(audio, abs);
            } else {
                handlePlaybackFailure(audio, abs);
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
            if (document.body) {
                mo.observe(document.body, { childList: true, subtree: true });
            }
        });
    }
    window.AUDIO_PLAYER_JS = true;
})();
/* =========================================================
   LISANUN MUBEEN ACADEMY — PWA CLIENT SCRIPT
   pwa.js · Service worker registration, install prompts & iOS guide
   ========================================================= */

(function () {
  'use strict';

  let deferredPrompt = null;

  function isStandalone() {
    return (
      window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true ||
      document.referrer.includes('android-app://')
    );
  }

  function isIos() {
    const ua = window.navigator.userAgent.toLowerCase();
    return /iphone|ipad|ipod/.test(ua) && !window.MSStream;
  }

  // Register Service Worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker
        .register('/sw.js', { scope: '/' })
        .then(function (reg) {
          // Check for service worker updates
          reg.onupdatefound = function () {
            const installingWorker = reg.installing;
            if (installingWorker) {
              installingWorker.onstatechange = function () {
                if (installingWorker.state === 'installed' && navigator.serviceWorker.controller) {
                  console.log('New Lisanun Mubeen PWA content available.');
                }
              };
            }
          };
        })
        .catch(function (err) {
          console.warn('PWA service worker registration error:', err);
        });
    });
  }

  // Handle beforeinstallprompt (Chromium, Android, Edge, Opera, Samsung Internet)
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    showInstallPrompts();
  });

  // When installed successfully
  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    hideInstallPrompts();
    console.log('Lisanun Mubeen Academy PWA was successfully installed.');
  });

  function showInstallPrompts() {
    if (isStandalone()) return;
    const elements = document.querySelectorAll('[data-pwa-install], #sidebarPwaInstall, #navPwaInstall, #heroPwaInstall');
    elements.forEach(function (el) {
      if (el) el.style.display = '';
    });
  }

  function hideInstallPrompts() {
    const elements = document.querySelectorAll('[data-pwa-install], #sidebarPwaInstall, #navPwaInstall, #heroPwaInstall');
    elements.forEach(function (el) {
      if (el) el.style.display = 'none';
    });
  }

  // Global trigger function called by install buttons
  window.triggerPwaInstall = async function () {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      try {
        const choice = await deferredPrompt.userChoice;
        if (choice.outcome === 'accepted') {
          hideInstallPrompts();
        }
      } catch (err) {
        console.warn('Install choice error:', err);
      }
      deferredPrompt = null;
    } else if (isIos()) {
      showIosModal();
    } else {
      showGenericModal();
    }
  };

  // Create iOS Install Modal
  function showIosModal() {
    let modal = document.getElementById('pwaIosModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'pwaIosModal';
      modal.innerHTML = `
        <div class="pwa-modal-backdrop" onclick="window.closePwaModal()"></div>
        <div class="pwa-modal-card">
          <button class="pwa-modal-close" onclick="window.closePwaModal()" aria-label="Close">&times;</button>
          <div class="pwa-modal-header">
            <img src="/assets/icons/icon-192.png" alt="Logo" class="pwa-modal-icon">
            <div>
              <h3>Install Lisanun Mubeen</h3>
              <p>Install on iPhone or iPad</p>
            </div>
          </div>
          <div class="pwa-modal-body">
            <div class="pwa-step">
              <span class="pwa-step-num">1</span>
              <div>Tap the <strong>Share</strong> button at the bottom of Safari <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin:0 2px;"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>.</div>
            </div>
            <div class="pwa-step">
              <span class="pwa-step-num">2</span>
              <div>Scroll down and tap <strong>Add to Home Screen</strong> <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin:0 2px;"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>.</div>
            </div>
            <div class="pwa-step">
              <span class="pwa-step-num">3</span>
              <div>Tap <strong>Add</strong> in the top-right corner to finish.</div>
            </div>
          </div>
          <button class="btn btn-gold btn-block" onclick="window.closePwaModal()">Got it</button>
        </div>
      `;
      document.body.appendChild(modal);
      injectModalStyles();
    }
    modal.classList.add('active');
  }

  // Create Generic Desktop/Android Instructions Modal if prompt is not triggered
  function showGenericModal() {
    let modal = document.getElementById('pwaGenericModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'pwaGenericModal';
      modal.innerHTML = `
        <div class="pwa-modal-backdrop" onclick="window.closePwaModal()"></div>
        <div class="pwa-modal-card">
          <button class="pwa-modal-close" onclick="window.closePwaModal()" aria-label="Close">&times;</button>
          <div class="pwa-modal-header">
            <img src="/assets/icons/icon-192.png" alt="Logo" class="pwa-modal-icon">
            <div>
              <h3>Install Lisanun Mubeen</h3>
              <p>Add to your device</p>
            </div>
          </div>
          <div class="pwa-modal-body">
            <p style="margin-bottom:12px;font-size:0.95rem;color:#4b5563;">You can install Lisanun Mubeen directly from your browser menu:</p>
            <div class="pwa-step">
              <span class="pwa-step-num">1</span>
              <div>Open browser options (<strong>⋮</strong> or <strong>Share</strong> menu).</div>
            </div>
            <div class="pwa-step">
              <span class="pwa-step-num">2</span>
              <div>Select <strong>"Install app"</strong> or <strong>"Add to Home screen"</strong>.</div>
            </div>
          </div>
          <button class="btn btn-gold btn-block" onclick="window.closePwaModal()">Close</button>
        </div>
      `;
      document.body.appendChild(modal);
      injectModalStyles();
    }
    modal.classList.add('active');
  }

  window.closePwaModal = function () {
    const modals = document.querySelectorAll('#pwaIosModal, #pwaGenericModal');
    modals.forEach((m) => m.classList.remove('active'));
  };

  function injectModalStyles() {
    if (document.getElementById('pwaModalStyles')) return;
    const style = document.createElement('style');
    style.id = 'pwaModalStyles';
    style.textContent = `
      #pwaIosModal, #pwaGenericModal {
        position: fixed;
        inset: 0;
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        opacity: 0;
        visibility: hidden;
        transition: opacity .25s ease, visibility .25s ease;
      }
      #pwaIosModal.active, #pwaGenericModal.active {
        opacity: 1;
        visibility: visible;
      }
      .pwa-modal-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(2, 44, 34, 0.65);
        backdrop-filter: blur(4px);
      }
      .pwa-modal-card {
        position: relative;
        background: #ffffff;
        max-width: 420px;
        width: 100%;
        border-radius: 18px;
        padding: 26px 24px;
        box-shadow: 0 20px 40px rgba(0,0,0,0.25);
        z-index: 1;
        transform: translateY(14px);
        transition: transform .25s ease;
      }
      #pwaIosModal.active .pwa-modal-card, #pwaGenericModal.active .pwa-modal-card {
        transform: translateY(0);
      }
      .pwa-modal-close {
        position: absolute;
        top: 14px;
        right: 16px;
        background: none;
        border: none;
        font-size: 24px;
        color: #9ca3af;
        cursor: pointer;
        padding: 4px;
        line-height: 1;
      }
      .pwa-modal-header {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 18px;
        padding-bottom: 14px;
        border-bottom: 1px solid #f3f4f6;
      }
      .pwa-modal-icon {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        box-shadow: 0 4px 12px rgba(6,78,59,0.18);
      }
      .pwa-modal-header h3 {
        margin: 0 0 2px;
        font-size: 1.15rem;
        color: #064e3b;
        font-weight: 700;
      }
      .pwa-modal-header p {
        margin: 0;
        font-size: 0.85rem;
        color: #6b7280;
      }
      .pwa-modal-body {
        margin-bottom: 20px;
      }
      .pwa-step {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 14px;
        font-size: 0.92rem;
        line-height: 1.45;
        color: #374151;
      }
      .pwa-step-num {
        flex: none;
        width: 24px;
        height: 24px;
        background: #ecfdf5;
        color: #047857;
        font-weight: 700;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        border: 1px solid #a7f3d0;
      }
    `;
    document.head.appendChild(style);
  }

  // On DOM ready, show iOS install prompts if on iOS and not standalone
  document.addEventListener('DOMContentLoaded', function () {
    if (isStandalone()) {
      document.body.classList.add('is-pwa-standalone');
      hideInstallPrompts();
    } else if (isIos()) {
      showInstallPrompts();
    }
  });
})();

/* =========================================================
   LISANUN MUBEEN ACADEMY — NOTIFICATIONS CLIENT SYSTEM
   notifications.js · Unread badge, tray, browser push, and 7am reminders
   ========================================================= */

(function () {
  'use strict';

  let highestNotifId = 0;
  let isPolling = false;

  function init() {
    checkPermissionAndBanner();
    loadNotifications();

    // Start background poll every 45s while tab is open
    setInterval(pollForNewNotifications, 45000);

    // Close tray when clicking outside
    document.addEventListener('click', function (e) {
      const wrap = document.getElementById('topbarNotifWrap');
      const tray = document.getElementById('notifTray');
      if (tray && wrap && !wrap.contains(e.target)) {
        tray.classList.remove('open');
      }
    });

    // Close tray with Escape (mobile-friendly) and on resize to desktop widths
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        const tray = document.getElementById('notifTray');
        if (tray) tray.classList.remove('open');
      }
    });
    window.addEventListener('resize', function () {
      if (window.innerWidth > 640) {
        const tray = document.getElementById('notifTray');
        if (tray) tray.classList.remove('open');
      }
    });

    // If permission was already granted earlier, make sure a real Web Push
    // subscription exists so closed-app pushes + icon badges keep working.
    ensurePushSubscription();
  }

  /* Mirror the in-app badge onto the OS/PWA app icon (1, 2, 3 …).
     Only takes effect for the installed PWA (Android/Edge; iOS 16.4+
     installed to Home Screen). No-ops safely in a plain browser tab. */
  function setOsBadge(count) {
    try {
      const num = parseInt(count, 10) || 0;
      if ('setAppBadge' in navigator) {
        if (num > 0) {
          navigator.setAppBadge(num).catch(function () {});
        } else if ('clearAppBadge' in navigator) {
          navigator.clearAppBadge().catch(function () {});
        }
      }
    } catch (e) { /* Badge API unsupported — in-app badge still works */ }
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  function checkPermissionAndBanner() {
    const banner = document.getElementById('pwaNotificationBanner');
    const promptBox = document.getElementById('notifPromptBox');

    if (!('Notification' in window)) {
      if (banner) banner.style.display = 'none';
      if (promptBox) promptBox.style.display = 'none';
      return;
    }

    if (Notification.permission === 'granted') {
      if (banner) banner.style.display = 'none';
      if (promptBox) promptBox.style.display = 'none';
    } else if (Notification.permission === 'default') {
      // Check if user dismissed banner recently (within 3 days)
      const dismissed = localStorage.getItem('lisanun_notif_banner_dismissed');
      const now = Date.now();
      const threeDays = 3 * 24 * 60 * 60 * 1000;

      if (!dismissed || now - parseInt(dismissed, 10) > threeDays) {
        if (banner) banner.style.display = 'flex';
      }
      if (promptBox) promptBox.style.display = 'flex';
    } else {
      // Denied
      if (banner) banner.style.display = 'none';
      if (promptBox) {
        promptBox.style.display = 'block';
        promptBox.innerHTML = '<span style="font-size:0.78rem;color:#b91c1c;">⚠️ Notifications blocked in browser settings.</span>';
      }
    }
  }

  function formatTimeAgo(dateString) {
    const date = new Date(dateString.replace(/-/g, '/'));
    const seconds = Math.floor((new Date() - date) / 1000);

    if (seconds < 60) return 'Just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return minutes + 'm ago';
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return hours + 'h ago';
    const days = Math.floor(hours / 24);
    if (days < 7) return days + 'd ago';
    return date.toLocaleDateString();
  }

  function getIconSvg(type) {
    if (type === 'daily_virtue') {
      return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>`;
    }
    if (type === 'recitation_review' || type === 'hafiz_review' || type === 'murajaah_review' || type === 'test_review') {
      return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`;
    }
    if (type === 'assistance' || type === 'assistance_ready') {
      return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`;
    }
    if (type === 'announcement') {
      return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>`;
    }
    return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`;
  }

  function loadNotifications() {
    fetch('/api/notifications.php?action=get')
      .then(res => res.json())
      .then(data => {
        if (!data || !data.success) return;
        updateBadge(data.unread_count);
        renderTrayList(data.notifications || []);
      })
      .catch(err => {
        console.warn('Failed to load notifications:', err);
      });
  }

  function updateBadge(count) {
    const badge = document.getElementById('notifBadge');
    if (!badge) return;

    const num = parseInt(count, 10) || 0;
    if (num > 0) {
      badge.textContent = num > 99 ? '99+' : num;
      badge.style.display = 'inline-flex';
    } else {
      badge.style.display = 'none';
    }

    // Keep the OS app-icon badge (1, 2, 3 …) in sync while the app is open.
    setOsBadge(num);
  }

  function renderTrayList(items) {
    const listEl = document.getElementById('notifTrayList');
    if (!listEl) return;

    if (!items || items.length === 0) {
      listEl.innerHTML = `
        <div class="notif-empty" style="padding:30px 16px;text-align:center;color:var(--text-muted);font-size:0.88rem;">
          <div style="font-size:1.8rem;margin-bottom:6px;">✨</div>
          <strong>No notifications yet</strong>
          <p style="margin:4px 0 0;font-size:0.8rem;">You're completely up to date.</p>
        </div>
      `;
      return;
    }

    let html = '';
    items.forEach(item => {
      const id = parseInt(item.id, 10);
      if (id > highestNotifId) highestNotifId = id;

      const unreadClass = !item.is_read ? 'unread' : '';
      const typeClass = item.type || 'general';
      const timeStr = formatTimeAgo(item.created_at);
      const iconSvg = getIconSvg(item.type);
      const actionUrl = item.action_url ? item.action_url : '#';

      html += `
        <div class="notif-item ${unreadClass} ${typeClass}" onclick="openNotification(${id}, '${actionUrl}')">
          <div class="notif-item-icon">
            ${iconSvg}
          </div>
          <div class="notif-item-content">
            <div class="notif-item-title">
              <span>${escapeHtml(item.title)}</span>
              ${!item.is_read ? '<span style="width:7px;height:7px;border-radius:50%;background:var(--emerald-600);flex:none;"></span>' : ''}
            </div>
            <div class="notif-item-msg">${escapeHtml(item.message)}</div>
            <div class="notif-item-time">${timeStr}</div>
          </div>
        </div>
      `;
    });

    listEl.innerHTML = html;
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function pollForNewNotifications() {
    if (isPolling) return;
    isPolling = true;

    fetch(`/api/notifications.php?action=poll&since_id=${highestNotifId}`)
      .then(res => res.json())
      .then(data => {
        isPolling = false;
        if (!data || !data.success) return;

        updateBadge(data.unread_count);

        if (data.new_notifications && data.new_notifications.length > 0) {
          // Play notification audio or vibration if user has notifications on
          data.new_notifications.forEach(notif => {
            const id = parseInt(notif.id, 10);
            if (id > highestNotifId) highestNotifId = id;

            // Trigger system / PWA notification
            displaySystemNotification(notif);
          });

          // Reload the list
          loadNotifications();
        }
      })
      .catch(() => {
        isPolling = false;
      });
  }

  function displaySystemNotification(notif) {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
      return;
    }

    const title = notif.title || 'Lisanun Mubeen Academy';
    const options = {
      body: notif.message || '',
      icon: '/assets/icons/icon-192.png',
      badge: '/assets/icons/favicon-32x32.png',
      vibrate: [150, 60, 150],
      data: {
        url: notif.action_url || '/'
      }
    };

    // If Service Worker is ready, use it for rich notification
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
      navigator.serviceWorker.ready.then(reg => {
        reg.showNotification(title, options);
      }).catch(() => {
        new Notification(title, options);
      });
    } else {
      const n = new Notification(title, options);
      n.onclick = function () {
        window.focus();
        if (notif.action_url) window.location.href = notif.action_url;
      };
    }
  }

  // Window-accessible functions
  window.toggleNotifTray = function () {
    const tray = document.getElementById('notifTray');
    if (!tray) return;

    if (tray.classList.contains('open')) {
      tray.classList.remove('open');
    } else {
      tray.classList.add('open');
      loadNotifications();
    }
  };

  window.openNotification = function (id, url) {
    // Mark as read on server
    fetch('/api/notifications.php?action=mark_read', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: id })
    }).catch(() => {});

    // Close tray and navigate
    const tray = document.getElementById('notifTray');
    if (tray) tray.classList.remove('open');

    if (url && url !== '#') {
      window.location.href = url;
    }
  };

  window.markAllNotificationsRead = function () {
    fetch('/api/notifications.php?action=mark_all_read', {
      method: 'POST'
    })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          updateBadge(0);
          loadNotifications();
        }
      });
  };

  window.enablePushNotifications = async function () {
    if (!('Notification' in window)) {
      alert('Notifications are not supported by this browser.');
      return;
    }

    try {
      const permission = await Notification.requestPermission();
      if (permission === 'granted') {
        // Hide banners and update server
        checkPermissionAndBanner();

        fetch('/api/notifications.php?action=update_settings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ notifications_enabled: 1, daily_reminder_enabled: 1 })
        }).catch(() => {});

        // Show welcome confirmation notification
        displaySystemNotification({
          title: '🌟 Notifications Enabled!',
          message: 'You will receive recitation feedback updates and daily 7:00 AM Islamic knowledge reminders.',
          action_url: '/student/dashboard.php'
        });

        // Register a real Web Push subscription so the server can reach
        // this device even when the app is closed (OS notification +
        // app-icon badge). Best effort — in-app polling still works alone.
        subscribeForPush();
      } else if (permission === 'denied') {
        alert('Notifications were blocked. Please enable them in your browser or device site permissions to receive updates.');
      }
    } catch (e) {
      console.warn('Error requesting notification permission:', e);
    }
  };

  window.dismissNotifBanner = function () {
    const banner = document.getElementById('pwaNotificationBanner');
    if (banner) banner.style.display = 'none';
    localStorage.setItem('lisanun_notif_banner_dismissed', Date.now());
  };

  /* Fetch the server's VAPID public key (null when push isn't configured). */
  async function fetchVapidPublicKey() {
    try {
      const res = await fetch('/api/notifications.php?action=public_key');
      const data = await res.json();
      if (data && data.success && data.public_key) return data.public_key;
    } catch (e) { /* ignore */ }
    return null;
  }

  /* Create (or reuse) a push subscription and save it server-side. */
  async function subscribeForPush() {
    try {
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
      if (!('Notification' in window) || Notification.permission !== 'granted') return;

      const reg = await navigator.serviceWorker.ready;
      let sub = await reg.pushManager.getSubscription();
      if (!sub) {
        const publicKey = await fetchVapidPublicKey();
        if (!publicKey) return; // Server push not configured yet — skip silently.
        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(publicKey)
        });
      }
      await fetch('/api/notifications.php?action=update_settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ push_subscription: JSON.stringify(sub) })
      }).catch(function () {});
    } catch (e) {
      console.log('Push subscription:', e && e.message ? e.message : e);
    }
  }

  /* Silent re-subscribe on every page load for users who already granted
     permission (e.g. granted before this update, or subscription expired). */
  function ensurePushSubscription() {
    try {
      if (!('Notification' in window) || Notification.permission !== 'granted') return;
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
      if (document.hidden) return; // avoid work on background preloads
      subscribeForPush();
    } catch (e) { /* best effort */ }
  }

  // Run on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

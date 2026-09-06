// Firebase Web Push Notification Client Setup
(function() {
  const firebaseConfig = {
    apiKey: "AIzaSyCSKm7fQtto31AmYrNH1MRey3bcsjkBoek",
    authDomain: "vy-crm.firebaseapp.com",
    projectId: "vy-crm",
    storageBucket: "vy-crm.firebasestorage.app",
    messagingSenderId: "1081193546240",
    appId: "1:1081193546240:web:4b61825cac088e7df04ee5",
    measurementId: "G-6QYXTXV5X9"
  };

  // Check if browser supports notifications & service workers
  if (!('serviceWorker' in navigator) || !('Notification' in window)) {
    console.warn('⚠️ Web Push Notifications are not supported in this browser.');
    return;
  }

  // Audio notification chime
  function playNotificationChime() {
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      const ctx = new AudioCtx();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
      osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1); // A5
      gain.gain.setValueAtTime(0.15, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.4);
    } catch (_) {}
  }

  // Show floating in-app popup banner
  function showInAppNotification(title, body, data) {
    playNotificationChime();

    let container = document.getElementById('vy-inapp-noti-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'vy-inapp-noti-container';
      container.style.cssText = 'position:fixed; top:20px; right:20px; z-index:999999; display:flex; flex-direction:column; gap:12px; pointer-events:none; max-width:380px; width:calc(100% - 40px);';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.style.cssText = 'background:linear-gradient(135deg, #1e1b4b 0%, #312e81 100%); color:#fff; padding:16px; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.3), 0 8px 10px -6px rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.15); display:flex; gap:12px; align-items:flex-start; animation:vySlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); pointer-events:auto; cursor:pointer; backdrop-filter:blur(8px);';
    
    if (!document.getElementById('vy-noti-style')) {
      const style = document.createElement('style');
      style.id = 'vy-noti-style';
      style.innerHTML = `
        @keyframes vySlideIn {
          from { transform: translateX(110%); opacity: 0; }
          to { transform: translateX(0); opacity: 1; }
        }
        @keyframes vySlideOut {
          from { transform: translateX(0); opacity: 1; }
          to { transform: translateX(110%); opacity: 0; }
        }
      `;
      document.head.appendChild(style);
    }

    toast.innerHTML = `
      <div style="background:rgba(255,255,255,0.2); width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
        <i class="fa-solid fa-bell" style="font-size:18px; color:#a5b4fc;"></i>
      </div>
      <div style="flex:1; min-width:0;">
        <div style="font-weight:700; font-size:14px; margin-bottom:3px; color:#fff; display:flex; justify-content:space-between; align-items:center;">
          <span>${escapeHtml(title)}</span>
          <span style="font-size:10px; color:#cbd5e1; font-weight:normal;">Just now</span>
        </div>
        <div style="font-size:12px; color:#e0e7ff; line-height:1.4; word-break:break-word;">${escapeHtml(body)}</div>
      </div>
      <button type="button" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:14px; padding:0 0 0 8px;" onclick="this.parentElement.remove()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    `;

    toast.onclick = (e) => {
      if (e.target.closest('button')) return;
      window.focus();
      toast.style.animation = 'vySlideOut 0.3s forwards';
      setTimeout(() => toast.remove(), 300);
    };

    container.appendChild(toast);

    setTimeout(() => {
      if (toast.parentElement) {
        toast.style.animation = 'vySlideOut 0.3s forwards';
        setTimeout(() => toast.remove(), 300);
      }
    }, 7000);
  }

  function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Listen for Service Worker broadcast messages
  navigator.serviceWorker.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'VY_CRM_PUSH_RECEIVED') {
      console.log('🔔 Received SW Push broadcast in web page:', event.data);
      showInAppNotification(event.data.title, event.data.body, event.data.data);
    }
  });

  function loadScript(src, callback) {
    if (document.querySelector(`script[src="${src}"]`)) {
      if (callback) callback();
      return;
    }
    const script = document.createElement('script');
    script.src = src;
    script.onload = callback;
    document.head.appendChild(script);
  }

  loadScript('https://www.gstatic.com/firebasejs/9.22.0/firebase-app-compat.js', function() {
    loadScript('https://www.gstatic.com/firebasejs/9.22.0/firebase-messaging-compat.js', function() {
      if (!firebase.apps.length) {
        firebase.initializeApp(firebaseConfig);
      }
      const messaging = firebase.messaging();
      let activeSwRegistration = null;

      // Register Service Worker
      navigator.serviceWorker.register('/firebase-messaging-sw.js')
        .then((registration) => {
          activeSwRegistration = registration;
          registration.update().catch(() => {});
          console.log('✅ Firebase Service Worker registered successfully');
        })
        .catch((err) => {
          console.warn('⚠️ Service Worker registration warning:', err);
        });

      // Handle foreground messages directly
      messaging.onMessage((payload) => {
        console.log('🔔 Foreground FCM Message received in Web App:', payload);
        const title = payload.notification?.title || payload.data?.title || 'VY AI CRM';
        const body = payload.notification?.body || payload.data?.body || '';

        // 1. Show floating in-app UI banner with chime
        showInAppNotification(title, body, payload.data);

        // 2. Trigger native OS notification
        if (Notification.permission === 'granted') {
          const options = {
            body: body,
            icon: payload.notification?.icon || '/images/logo.png',
            badge: '/images/logo.png',
            data: payload.data
          };
          if (activeSwRegistration && activeSwRegistration.showNotification) {
            activeSwRegistration.showNotification(title, options);
          } else {
            try { new Notification(title, options); } catch (_) {}
          }
        }
      });

      // Global function to request permission & get token
      window.requestPushNotificationPermission = function() {
        return Notification.requestPermission().then((permission) => {
          if (permission === 'granted') {
            const tokenOptions = activeSwRegistration ? { serviceWorkerRegistration: activeSwRegistration } : {};
            return messaging.getToken(tokenOptions).then((currentToken) => {
              if (currentToken) {
                console.log('====================================================');
                console.log('🔥 WEB FIREBASE FCM TOKEN FOR TESTING:');
                console.log(currentToken);
                console.log('====================================================');
                localStorage.setItem('fcm_web_token', currentToken);
                window.webFcmToken = currentToken;

                // Sync with server if user has active session
                try {
                  fetch('/api/update_fcm_token.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ fcm_token: currentToken, device_type: 'web' })
                  }).catch(() => {});
                } catch (_) {}

                return currentToken;
              } else {
                console.warn('No registration token available.');
                return null;
              }
            });
          } else {
            console.warn('Notification permission not granted.');
            try {
              fetch('/api/update_fcm_token.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ fcm_token: '', device_type: 'web' })
              }).catch(() => {});
            } catch (_) {}
            return null;
          }
        }).catch((err) => {
          console.error('Error retrieving web FCM token:', err);
          return null;
        });
      };

      // Auto-refresh token on page load if permission was previously granted
      if (Notification.permission === 'granted') {
        const tokenOptions = activeSwRegistration ? { serviceWorkerRegistration: activeSwRegistration } : {};
        messaging.getToken(tokenOptions).then((currentToken) => {
          if (currentToken) {
            localStorage.setItem('fcm_web_token', currentToken);
            window.webFcmToken = currentToken;
          }
        }).catch(() => {});
      }
    });
  });
})();

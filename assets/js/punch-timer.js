// punch-timer.js — Global punch session timer + toast system
// Persists across all pages via localStorage

(function () {
    const PUNCH_KEY = 'vycrm_punch_start';

    // ── Toast System ─────────────────────────────────────────────
    function showToast(msg, type) {
        type = type || 'success';
        const colors = { success: '#10b981', warning: '#f59e0b', info: '#7b5ef0', error: '#ef4444' };
        const icons = { success: '✅', warning: '☕', info: '💼', error: '👋' };

        let container = document.getElementById('vyToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'vyToastContainer';
            container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:10px;';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.style.cssText = `
            background: #fff;
            border-left: 4px solid ${colors[type]};
            border-radius: 10px;
            padding: 14px 20px;
            min-width: 280px;
            max-width: 340px;
            font-size: 14px;
            font-weight: 600;
            color: #2b3674;
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
            display: flex;
            align-items: center;
            gap: 10px;
            opacity: 0;
            transform: translateX(30px);
            transition: all 0.35s cubic-bezier(0.25,0.8,0.25,1);
        `;
        toast.innerHTML = `<span style="font-size:18px;">${icons[type]}</span><span>${msg}</span>`;
        container.appendChild(toast);

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translateX(0)';
            });
        });

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(30px)';
            setTimeout(() => toast.remove(), 400);
        }, 3500);
    }

    // ── Timer Injection into Topbar ───────────────────────────────
    function injectTimer() {
        const topbar = document.querySelector('.topbar');
        if (!topbar || document.getElementById('globalPunchTimer')) return;

        // Inject timer style
        if (!document.getElementById('punchTimerStyle')) {
            const style = document.createElement('style');
            style.id = 'punchTimerStyle';
            style.textContent = `
                @keyframes vyPulse {
                    0%, 100% { transform:scale(1); opacity:1; }
                    50%       { transform:scale(1.5); opacity:0.5; }
                }
                #globalPunchTimer {
                    display: none;
                    align-items: center;
                    gap: 10px;
                    background: linear-gradient(135deg, rgba(123,94,240,0.08), rgba(123,94,240,0.04));
                    border: 1.5px solid rgba(123,94,240,0.25);
                    border-radius: 50px;
                    padding: 8px 24px;
                    font-size: 15px;
                    font-weight: 700;
                    color: #7b5ef0;
                    letter-spacing: 0.5px;
                    position: absolute;
                    left: 50%;
                    transform: translateX(-50%);
                    pointer-events: none;
                }
            `;
            document.head.appendChild(style);
        }

        // Make topbar relative so absolute centering works
        topbar.style.position = 'relative';

        const timerEl = document.createElement('div');
        timerEl.id = 'globalPunchTimer';
        timerEl.innerHTML = `
            <span style="width:9px;height:9px;background:#10b981;border-radius:50%;display:inline-block;animation:vyPulse 1.5s infinite;"></span>
            <span>Work Session:</span>
            <span id="punchTimerValue" style="font-size:16px;">00:00:00</span>
        `;
        topbar.appendChild(timerEl);
    }

    function formatElapsed(ms) {
        const s = Math.floor(ms / 1000);
        const h = String(Math.floor(s / 3600)).padStart(2, '0');
        const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
        const sec = String(s % 60).padStart(2, '0');
        return `${h}:${m}:${sec}`;
    }

    function tick() {
        const start = localStorage.getItem(PUNCH_KEY);
        const timerEl = document.getElementById('globalPunchTimer');
        const valueEl = document.getElementById('punchTimerValue');
        if (!timerEl || !valueEl) return;

        if (start) {
            valueEl.textContent = formatElapsed(Date.now() - parseInt(start, 10));
            timerEl.style.display = 'flex';
        } else {
            timerEl.style.display = 'none';
        }
    }

    // ── Global Punch Action ───────────────────────────────────────
    window.recordPunch = function (type) {
        const msgs = {
            'Check-In': ['Punched In! Timer started.', 'success'],
            'Break-In': ['Break started. Enjoy your break!', 'warning'],
            'Break-Out': ['Break ended. Welcome back!', 'info'],
            'Check-Out': ['Punched Out. See you tomorrow!', 'error'],
        };
        if (type === 'Check-In' && !localStorage.getItem(PUNCH_KEY)) {
            localStorage.setItem(PUNCH_KEY, Date.now().toString());
        }
        if (type === 'Check-Out') {
            localStorage.removeItem(PUNCH_KEY);
        }
        const [msg, t] = msgs[type] || ['Action recorded.', 'success'];
        showToast(msg, t);
        tick();
    };

    // Expose toast globally for other pages to use
    window.vyToast = showToast;

    // ── Init ──────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        injectTimer();
        tick();
        setInterval(tick, 1000);
    });
})();

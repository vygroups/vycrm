/**
 * toast.js - Common Toast System for VY-AI CRM
 */
class VyToast {
    static init() {
        if (!document.getElementById('vyToastContainer')) {
            const container = document.createElement('div');
            container.id = 'vyToastContainer';
            container.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 99999;
                display: flex;
                flex-direction: column;
                gap: 10px;
                pointer-events: none;
            `;
            document.body.appendChild(container);
        }
    }

    static show(message, type = 'success') {
        this.init();
        const container = document.getElementById('vyToastContainer');
        
        // Remove duplicate toasts with the exact same message to avoid clutter
        try {
            const existingToasts = container.querySelectorAll('.vy-toast');
            for (let i = 0; i < existingToasts.length; i++) {
                const t = existingToasts[i];
                const toastText = t.querySelector('.vy-toast-text');
                if (toastText && toastText.innerText === message) {
                    t.remove();
                }
            }
        } catch(e) { console.error('Toast cleanup error:', e); }

        const toast = document.createElement('div');
        toast.className = `vy-toast ${type}`;
        
        let icon = 'fa-check-circle';
        let color = '#10b981'; // green for success
        if (type === 'error' || type === 'danger') {
            icon = 'fa-exclamation-circle';
            color = '#ef4444'; // red
        } else if (type === 'info') {
            icon = 'fa-info-circle';
            color = '#3b82f6'; // blue
        } else if (type === 'warning') {
            icon = 'fa-exclamation-triangle';
            color = '#f59e0b'; // amber
        }

        toast.style.cssText = `
            background: #fff;
            color: #1f2937;
            border-left: 4px solid ${color};
            border-radius: 8px;
            padding: 12px 18px;
            min-width: 280px;
            max-width: 400px;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            pointer-events: auto;
            opacity: 0;
            transform: translateY(-20px);
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.27, 1.55);
        `;

        toast.innerHTML = `
            <i class="fa-solid ${icon}" style="color: ${color}; font-size: 16px; flex-shrink: 0;"></i>
            <span class="vy-toast-text" style="flex-grow: 1; word-break: break-word;">${message}</span>
            <i class="fa-solid fa-xmark" style="color: #9ca3af; cursor: pointer; font-size: 14px; margin-left: 5px; flex-shrink: 0;" onclick="this.parentElement.remove()"></i>
        `;

        container.appendChild(toast);

        // Force reflow
        toast.offsetHeight;

        // Slide/fade in
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';

        // Auto remove
        setTimeout(() => {
            if (toast.parentElement) {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }
        }, 4000);
    }
}

// Expose to window scope
window.vyToast = function(message, type = 'success') {
    VyToast.show(message, type);
};

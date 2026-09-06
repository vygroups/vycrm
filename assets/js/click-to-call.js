// Click-to-Call Telephony & Mobile Bridge Client Engine
(function() {
  function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // Audio dialer chime
  function playDialTone() {
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      const ctx = new AudioCtx();
      const osc1 = ctx.createOscillator();
      const osc2 = ctx.createOscillator();
      const gain = ctx.createGain();

      osc1.type = 'sine';
      osc2.type = 'sine';
      osc1.frequency.setValueAtTime(350, ctx.currentTime); // US Dial tone 350Hz
      osc2.frequency.setValueAtTime(440, ctx.currentTime); // 440Hz

      gain.gain.setValueAtTime(0.08, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.5);

      osc1.connect(gain);
      osc2.connect(gain);
      gain.connect(ctx.destination);

      osc1.start();
      osc2.start();
      osc1.stop(ctx.currentTime + 0.5);
      osc2.stop(ctx.currentTime + 0.5);
    } catch (_) {}
  }

  // Show floating Click-to-Call active HUD
  function showCallHud(title, subtitle, phoneNumber, isSuccess, errorCode) {
    playDialTone();

    let container = document.getElementById('vy-call-hud-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'vy-call-hud-container';
      container.style.cssText = 'position:fixed; bottom:24px; right:24px; z-index:999999; display:flex; flex-direction:column; gap:12px; pointer-events:none; max-width:400px; width:calc(100% - 48px);';
      document.body.appendChild(container);
    }

    const card = document.createElement('div');
    card.style.cssText = 'background:linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%); color:#fff; padding:18px; border-radius:18px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.5), 0 8px 10px -6px rgba(0,0,0,0.5); border:1.5px solid rgba(129,140,248,0.3); display:flex; flex-direction:column; gap:12px; animation:vySlideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1); pointer-events:auto; backdrop-filter:blur(12px);';

    if (!document.getElementById('vy-call-style')) {
      const style = document.createElement('style');
      style.id = 'vy-call-style';
      style.innerHTML = `
        @keyframes vySlideUp {
          from { transform: translateY(110%); opacity: 0; }
          to { transform: translateY(0); opacity: 1; }
        }
        @keyframes vySlideDown {
          from { transform: translateY(0); opacity: 1; }
          to { transform: translateY(110%); opacity: 0; }
        }
        @keyframes vyPulseGlow {
          0% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.7); }
          70% { box-shadow: 0 0 0 12px rgba(99, 102, 241, 0); }
          100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0); }
        }
      `;
      document.head.appendChild(style);
    }

    const iconBg = isSuccess ? 'linear-gradient(135deg, #10b981, #059669)' : 'linear-gradient(135deg, #ef4444, #dc2626)';
    const cleanNum = (phoneNumber || '').replace(/[^\d\+]/g, '');

    card.innerHTML = `
      <div style="display:flex; gap:14px; align-items:flex-start;">
        <div style="background:${iconBg}; width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; animation: ${isSuccess ? 'vyPulseGlow 2s infinite' : 'none'};">
          <i class="fa-solid ${isSuccess ? 'fa-phone-volume' : 'fa-phone-slash'}" style="font-size:18px; color:#fff;"></i>
        </div>
        <div style="flex:1; min-width:0;">
          <div style="font-weight:700; font-size:14px; color:#fff; display:flex; justify-content:space-between; align-items:center;">
            <span>${escapeHtml(title)}</span>
            <span style="font-size:11px; background:rgba(255,255,255,0.15); padding:2px 8px; border-radius:12px; font-weight:600;">${isSuccess ? 'Mobile Bridge' : 'Alert'}</span>
          </div>
          <div style="font-size:12.5px; color:#cbd5e1; margin-top:3px; line-height:1.4;">${escapeHtml(subtitle)}</div>
        </div>
        <button type="button" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:16px; padding:0;" onclick="this.closest('#vy-call-hud-container > div').remove()">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
      
      <div style="display:flex; gap:8px; border-top:1px solid rgba(255,255,255,0.1); padding-top:10px; margin-top:2px; flex-wrap:wrap;">
        ${isSuccess ? `
          <a href="https://wa.me/${cleanNum.replace('+', '')}" target="_blank" style="flex:1; min-width:110px; display:flex; align-items:center; justify-content:center; gap:6px; font-size:12px; font-weight:600; padding:8px 12px; background:#25d366; color:#fff; border-radius:8px; text-decoration:none;">
            <i class="fa-brands fa-whatsapp" style="font-size:14px;"></i> WhatsApp
          </a>
          <a href="tel:${escapeHtml(phoneNumber)}" style="flex:1; min-width:110px; display:flex; align-items:center; justify-content:center; gap:6px; font-size:12px; font-weight:600; padding:8px 12px; background:rgba(255,255,255,0.15); color:#fff; border-radius:8px; text-decoration:none;">
            <i class="fa-solid fa-laptop"></i> Web Dial
          </a>
        ` : `
          <a href="tel:${escapeHtml(phoneNumber)}" style="flex:1; display:flex; align-items:center; justify-content:center; gap:6px; font-size:12px; font-weight:600; padding:8px 12px; background:var(--primary, #7b5ef0); color:#fff; border-radius:8px; text-decoration:none;">
            <i class="fa-solid fa-phone"></i> Direct Call (tel:)
          </a>
        `}
      </div>
    `;

    container.appendChild(card);

    setTimeout(() => {
      if (card.parentElement) {
        card.style.animation = 'vySlideDown 0.35s forwards';
        setTimeout(() => card.remove(), 350);
      }
    }, isSuccess ? 10000 : 8000);
  }

  // Global Trigger Click-to-Call
  window.vyTriggerClickToCall = async function(phoneNumber, customerName, recordId, moduleSlug, fieldLabel) {
    if (!phoneNumber) {
      alert('No phone number provided');
      return;
    }

    try {
      const res = await fetch('/api/click_to_call.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'trigger_call',
          phone_number: phoneNumber,
          customer_name: customerName || 'Customer',
          record_id: recordId || '',
          module_slug: moduleSlug || 'contacts',
          field_label: fieldLabel || 'Phone'
        })
      });

      const data = await res.json();

      if (data.success) {
        showCallHud(
          'Outgoing Call Dispatched 📱',
          `Calling ${customerName || 'Contact'} (${phoneNumber}) via your mobile phone.`,
          phoneNumber,
          true
        );
      } else {
        if (data.error_code === 'NO_MOBILE_DEVICE') {
          showCallHud(
            'Mobile App Not Connected',
            data.message || 'Please sign in to the VY CRM Mobile App on your phone to use Remote Click-to-Call.',
            phoneNumber,
            false,
            'NO_MOBILE'
          );
        } else {
          showCallHud(
            'Call Dispatch Failed',
            data.message || data.error || 'Could not initiate call',
            phoneNumber,
            false
          );
        }
      }
    } catch (err) {
      showCallHud(
        'Connection Error',
        'Unable to reach CRM call server. Falling back to local dialer.',
        phoneNumber,
        false
      );
      window.location.href = 'tel:' + encodeURIComponent(phoneNumber);
    }
  };

  // Smart Multi-Number Selector Modal
  window.vyOpenCallNumberPicker = function(numbersList, customerName, recordId, moduleSlug) {
    if (!numbersList || !numbersList.length) {
      alert('No phone numbers found on this record.');
      return;
    }

    // Filter out invalid/empty numbers
    const validList = numbersList.filter(n => n && (typeof n === 'string' ? n.trim() : (n.number && n.number.trim())));

    if (validList.length === 0) {
      alert('No valid phone numbers found on this record.');
      return;
    }

    // If only 1 number exists, trigger immediately!
    if (validList.length === 1) {
      const item = validList[0];
      const num = typeof item === 'string' ? item : item.number;
      const label = typeof item === 'object' ? (item.label || 'Phone') : 'Phone';
      window.vyTriggerClickToCall(num, customerName, recordId, moduleSlug, label);
      return;
    }

    // Multiple numbers exist -> Show interactive picker modal
    let modal = document.getElementById('vy-number-picker-modal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'vy-number-picker-modal';
      modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); z-index:999998; display:flex; align-items:center; justify-content:center; padding:16px; box-sizing:border-box;';
      document.body.appendChild(modal);
    }

    const initials = (customerName || 'Contact').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();

    modal.innerHTML = `
      <div style="background:var(--surface, #fff); width:100%; max-width:420px; border-radius:20px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); border:1.5px solid var(--border, #e2e8f0); overflow:hidden; animation:vySlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
        <div style="padding:20px 24px; border-bottom:1px solid var(--border, #e2e8f0); display:flex; justify-content:space-between; align-items:center;">
          <div style="display:flex; align-items:center; gap:12px;">
            <div style="width:40px; height:40px; border-radius:12px; background:linear-gradient(135deg, var(--primary, #7b5ef0), #4f46e5); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:15px;">
              ${escapeHtml(initials)}
            </div>
            <div>
              <h3 style="margin:0; font-size:16px; font-weight:800; color:var(--text, #1e293b);">Click to Call</h3>
              <div style="font-size:12.5px; color:var(--text-muted, #64748b);">${escapeHtml(customerName || 'Select number')}</div>
            </div>
          </div>
          <button type="button" style="background:none; border:none; font-size:18px; color:var(--text-muted, #64748b); cursor:pointer; padding:4px;" onclick="document.getElementById('vy-number-picker-modal').style.display='none'">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>

        <div style="padding:16px 24px; max-height:320px; overflow-y:auto; display:flex; flex-direction:column; gap:10px;">
          <div style="font-size:12px; font-weight:600; color:var(--text-muted, #64748b); margin-bottom:2px;">Select number to dial:</div>
          ${validList.map(item => {
            const num = typeof item === 'string' ? item : item.number;
            const label = typeof item === 'object' ? (item.label || 'Phone') : 'Phone';
            return `
              <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:var(--surface-muted, #f8fafc); border:1.5px solid var(--border, #e2e8f0); border-radius:12px; transition:all 0.2s;" onmouseover="this.style.borderColor='var(--primary, #7b5ef0)'" onmouseout="this.style.borderColor='var(--border, #e2e8f0)'">
                <div>
                  <div style="font-size:11px; font-weight:700; color:var(--primary, #7b5ef0); text-transform:uppercase; letter-spacing:0.5px;">${escapeHtml(label)}</div>
                  <div style="font-size:14px; font-weight:700; color:var(--text, #1e293b); font-family:monospace; margin-top:2px;">${escapeHtml(num)}</div>
                </div>
                <button type="button" class="btn-primary" style="width:auto; padding:8px 16px; font-size:12.5px; display:flex; align-items:center; gap:6px; border-radius:8px;" onclick="document.getElementById('vy-number-picker-modal').style.display='none'; window.vyTriggerClickToCall('${escapeHtml(num)}', '${escapeHtml(customerName)}', '${escapeHtml(recordId)}', '${escapeHtml(moduleSlug)}', '${escapeHtml(label)}');">
                  <i class="fa-solid fa-phone"></i> Call
                </button>
              </div>
            `;
          }).join('')}
        </div>

        <div style="padding:14px 24px; background:var(--surface-muted, #f8fafc); border-top:1px solid var(--border, #e2e8f0); display:flex; justify-content:flex-end;">
          <button type="button" class="mm-btn" onclick="document.getElementById('vy-number-picker-modal').style.display='none'">Cancel</button>
        </div>
      </div>
    `;

    modal.style.display = 'flex';
  };
})();

<?php
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/brand.php';
require_once 'includes/commerce.php';

$v = time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(brand_page_title('Communications')) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars(brand_favicon_url()) ?>">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-content { background: white; padding: 30px; border-radius: 20px; width: 100%; max-width: 500px; box-shadow: var(--shadow-lg); max-height: 90vh; overflow-y: auto; }
        .badge-smtp { background: rgba(123, 94, 240, 0.1); color: var(--primary); padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .badge-whatsapp { background: rgba(37, 211, 102, 0.1); color: #25D366; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .badge-default { background: rgba(245, 158, 11, 0.1); color: #f59e0b; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; margin-left: 8px; }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Settings / <span class="current">Communications</span></div>
            <button class="btn-primary" style="width:auto; padding: 10px 20px;" onclick="openModal()">
                <i class="fa-solid fa-plus" style="margin-right:8px;"></i> Add Configuration
            </button>
        </header>

        <div class="content-scroll">
            <div class="table-panel">
                <div class="table-header"><div class="table-title">Communication Configurations</div></div>
                <div class="table-responsive">
                    <table class="crm-table">
                        <thead>
                            <tr><th>Name</th><th>Type</th><th>Default</th><th>Created At</th><th>Actions</th></tr>
                        </thead>
                        <tbody id="configsTableBody">
                            <tr><td colspan="5" class="text-center" style="padding: 20px;">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal" id="configModal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-4">
            <h3 id="modalTitle" style="margin:0;">Add Configuration</h3>
            <button class="btn-icon" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="configForm">
            <input type="hidden" name="id" id="config_id" value="">
            <input type="hidden" name="action" value="save">
            
            <div class="form-group">
                <label class="form-label">Configuration Name</label>
                <input type="text" class="form-control" name="name" required placeholder="e.g. Sales Email, Marketing WhatsApp">
            </div>
            
            <div class="form-group">
                <label class="form-label">Type</label>
                <select class="form-control" name="type" id="configType" required onchange="toggleFields()">
                    <option value="smtp">SMTP Email</option>
                    <option value="whatsapp">WhatsApp API</option>
                </select>
            </div>
            
            <div id="smtpFields">
                <div style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label">SMTP Host</label>
                        <input type="text" class="form-control" name="config_data[smtp_host]" id="smtp_host" placeholder="smtp.example.com">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Port</label>
                        <input type="number" class="form-control" name="config_data[smtp_port]" id="smtp_port" placeholder="587">
                    </div>
                </div>
                <div style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" name="config_data[smtp_user]" id="smtp_user" placeholder="user@example.com">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="config_data[smtp_pass]" id="smtp_pass" placeholder="••••••••">
                    </div>
                </div>
                <div style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">From Name</label>
                        <input type="text" class="form-control" name="config_data[smtp_from_name]" id="smtp_from_name" placeholder="John Doe">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">From Email</label>
                        <input type="email" class="form-control" name="config_data[smtp_from_email]" id="smtp_from_email" placeholder="no-reply@example.com">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Encryption</label>
                    <select class="form-control" name="config_data[smtp_encryption]" id="smtp_encryption">
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="none">None</option>
                    </select>
                </div>
            </div>
            
            <div id="waFields" style="display:none;">
                <div class="form-group">
                    <label class="form-label">WhatsApp API URL</label>
                    <input type="text" class="form-control" name="config_data[wa_url]" id="wa_url" placeholder="https://graph.facebook.com/v20.0/PHONE_NUMBER_ID/messages">
                </div>
                <div class="form-group">
                    <label class="form-label">Access Token</label>
                    <input type="password" class="form-control" name="config_data[wa_token]" id="wa_token" placeholder="EAABwz...">
                </div>
            </div>
            
            <div class="form-group" style="display:flex; align-items:center; gap:10px; margin-top: 15px;">
                <input type="checkbox" name="is_default" id="is_default" value="1" style="width:20px;height:20px;accent-color:var(--primary);cursor:pointer;">
                <label for="is_default" class="form-label" style="margin:0;cursor:pointer;">Set as default for this type</label>
            </div>
            
            <button type="submit" class="btn-primary" style="margin-top:20px; width: 100%;">SAVE CONFIGURATION</button>
        </form>
    </div>
</div>

<script>
function toggleFields() {
    const type = document.getElementById('configType').value;
    if (type === 'smtp') {
        document.getElementById('smtpFields').style.display = 'block';
        document.getElementById('waFields').style.display = 'none';
        
        document.getElementById('smtp_host').required = true;
        document.getElementById('smtp_port').required = true;
        document.getElementById('wa_url').required = false;
        document.getElementById('wa_token').required = false;
    } else {
        document.getElementById('smtpFields').style.display = 'none';
        document.getElementById('waFields').style.display = 'block';
        
        document.getElementById('smtp_host').required = false;
        document.getElementById('smtp_port').required = false;
        document.getElementById('wa_url').required = true;
        document.getElementById('wa_token').required = true;
    }
}

function openModal() { 
    document.getElementById('configModal').style.display = 'flex'; 
    document.getElementById('modalTitle').innerText = 'Add Configuration';
    document.getElementById('configForm').reset();
    document.getElementById('config_id').value = '';
    toggleFields();
}

function closeModal() { 
    document.getElementById('configModal').style.display = 'none'; 
}

function editConfig(id) {
    fetch('/api/communications_api.php?action=get&id=' + id)
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            const config = data.config;
            openModal();
            document.getElementById('modalTitle').innerText = 'Edit Configuration';
            document.getElementById('config_id').value = config.id;
            document.querySelector('input[name="name"]').value = config.name;
            document.getElementById('configType').value = config.type;
            document.getElementById('is_default').checked = config.is_default == 1;
            
            toggleFields();
            
            if (config.type === 'smtp') {
                document.getElementById('smtp_host').value = config.config_data.smtp_host || '';
                document.getElementById('smtp_port').value = config.config_data.smtp_port || '';
                document.getElementById('smtp_user').value = config.config_data.smtp_user || '';
                document.getElementById('smtp_pass').value = config.config_data.smtp_pass || '';
                document.getElementById('smtp_from_name').value = config.config_data.smtp_from_name || '';
                document.getElementById('smtp_from_email').value = config.config_data.smtp_from_email || '';
                document.getElementById('smtp_encryption').value = config.config_data.smtp_encryption || 'tls';
            } else {
                document.getElementById('wa_url').value = config.config_data.wa_url || '';
                document.getElementById('wa_token').value = config.config_data.wa_token || '';
            }
        }
    });
}

function deleteConfig(id) {
    if(confirm('Are you sure you want to delete this configuration? Workflows and campaigns using it will fall back to the default.')) {
        fetch('/api/communications_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'delete', id: id })
        })
        .then(r => r.json())
        .then(data => {
            if(data.success) loadConfigs();
            else alert(data.error || 'Failed to delete');
        });
    }
}

document.getElementById('configForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    // Convert to JSON
    const payload = {};
    const configData = {};
    formData.forEach((value, key) => {
        if (key.startsWith('config_data[')) {
            const innerKey = key.match(/\[(.*?)\]/)[1];
            configData[innerKey] = value;
        } else {
            payload[key] = value;
        }
    });
    payload.config_data = configData;
    // ensure checkbox is captured
    payload.is_default = document.getElementById('is_default').checked ? 1 : 0;

    fetch('/api/communications_api.php', { 
        method: 'POST', 
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            closeModal();
            loadConfigs();
        } else {
            alert(data.error || 'Failed to save');
        }
    });
});

function loadConfigs() {
    fetch('/api/communications_api.php?action=list')
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            const tbody = document.getElementById('configsTableBody');
            tbody.innerHTML = '';
            
            if (data.configs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="padding: 20px;">No configurations found. Add one above.</td></tr>';
                return;
            }
            
            data.configs.forEach(c => {
                const typeBadge = c.type === 'smtp' 
                    ? '<span class="badge-smtp"><i class="fa-solid fa-envelope"></i> SMTP</span>' 
                    : '<span class="badge-whatsapp"><i class="fa-brands fa-whatsapp"></i> WhatsApp</span>';
                    
                const defaultBadge = c.is_default == 1 
                    ? '<span class="badge-default">DEFAULT</span>' 
                    : '';
                
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="text-bold">${escapeHtml(c.name)} ${defaultBadge}</td>
                    <td>${typeBadge}</td>
                    <td>${c.is_default == 1 ? 'Yes' : 'No'}</td>
                    <td>${new Date(c.created_at).toLocaleDateString()}</td>
                    <td>
                        <button class="btn-icon" title="Edit" onclick="editConfig(${c.id})"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn-icon text-red" title="Delete" onclick="deleteConfig(${c.id})"><i class="fa-solid fa-trash"></i></button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }
    });
}

function escapeHtml(unsafe) {
    return (unsafe || '').toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', loadConfigs);
</script>
</body>
</html>

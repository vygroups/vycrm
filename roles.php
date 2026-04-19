<?php
require_once 'auth_check.php';
require_once 'config/database.php';
require_once 'includes/brand.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles Management - <?= $companyName ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/styles.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        .tree-container { overflow-x: auto; padding: 60px; background: #f8fafc; border-radius: 24px; min-height: 700px; display: flex; flex-direction: column; align-items: center; border: 1px solid #e2e8f0; box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.05); }
        
        /* The Tree Structure */
        .tree { position: relative; }
        .tree ul { position: relative; padding-left: 60px; display: flex; flex-direction: column; gap: 24px; transition: all 0.5s; margin: 0; }
        .tree li { position: relative; list-style-type: none; display: flex; align-items: center; padding: 10px 0; }

        /* Connectors */
        .tree li::before { content: ''; position: absolute; left: 0; top: 0; width: 60px; height: 50%; border-left: 2px solid #cbd5e1; border-bottom: 2px solid #cbd5e1; border-bottom-left-radius: 12px; }
        .tree li::after { content: ''; position: absolute; left: 0; top: 50%; width: 60px; height: 50%; border-left: 2px solid #cbd5e1; }
        .tree li:last-child::after { display: none; }
        .tree li:only-child::before { border-radius: 0; height: 0; border-bottom: 2px solid #cbd5e1; top: 50%; width: 60px; border-bottom-left-radius: 0; }

        /* The Node Card */
        .role-card { background: white; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px 24px; min-width: 240px; display: flex; align-items: center; gap: 16px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02); position: relative; z-index: 10; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .role-card:hover { transform: scale(1.02) translateY(-4px); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); border-color: var(--primary); }
        
        .role-avatar { width: 44px; height: 44px; border-radius: 14px; background: rgba(123, 94, 240, 0.1); display: flex; align-items: center; justify-content: center; font-size: 20px; color: var(--primary); }
        .role-info { flex: 1; text-align: left; }
        .role-name { font-weight: 800; color: #1e293b; font-size: 15px; margin-bottom: 2px; }
        .role-desc { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

        /* Multi-Parent Badge */
        .multi-badge { position: absolute; top: -10px; left: 10%; background: #0ea5e9; color: white; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; z-index: 11; }

        /* Actions Overlay */
        .role-actions { position: absolute; bottom: -20px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; opacity: 0; transition: 0.2s; visibility: hidden; }
        .role-card:hover .role-actions { opacity: 1; bottom: -12px; visibility: visible; }
        .action-circle { width: 32px; height: 32px; border-radius: 10px; background: white; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #64748b; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); transition: all 0.2s; font-size: 12px; }
        .action-circle:hover { transform: scale(1.1); color: var(--primary); border-color: var(--primary); }
        .action-add { background: var(--primary); color: white; border: none; }
        .action-add:hover { background: #6b4de6; color: white; }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Studio / <span class="current">Multi-Link Hierarchy</span></div>
            <div class="flex gap-2">
                <button class="btn-primary" style="width:auto; padding: 10px 20px;" onclick="addRootRole()">+ NEW ROOT ROLE</button>
            </div>
        </header>

        <div class="content-scroll">
            <div class="tree-container">
                <div class="tree" id="roleTree">
                    <!-- Tree will render here -->
                    <p class="text-muted">Loading Organizational Chart...</p>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
let roles = [];
let links = [];

async function init() {
    const res = await fetch('/api/roles_api.php?action=get_roles');
    const data = await res.json();
    if (data.success) {
        roles = data.roles;
        links = data.links;
        renderChart();
    }
}

function renderChart() {
    const container = document.getElementById('roleTree');
    container.innerHTML = '';
    
    // Absolute Roots = Roles that are NOT a child of any other role in the links table
    const childIds = new Set(links.map(l => l.child_role_id));
    const rootRoles = roles.filter(r => !childIds.has(r.id));
    
    if (rootRoles.length === 0 && roles.length > 0) {
        // Fallback: If everything is a loop (shouldn't happen), show first role as root
        renderNodeRecursive(container, roles[0]);
    } else {
        const wrapper = document.createElement('div');
        wrapper.style.display = 'flex';
        wrapper.style.flexDirection = 'column';
        wrapper.style.gap = '60px';
        
        rootRoles.forEach(root => {
            const rootWrapper = document.createElement('div');
            renderNodeRecursive(rootWrapper, root);
            wrapper.appendChild(rootWrapper);
        });
        container.appendChild(wrapper);
    }
}

function renderNodeRecursive(container, role, path = []) {
    // Basic infinite loop protection
    if (path.includes(role.id)) {
        container.innerHTML += `<div class="role-card" style="border-color:red; opacity: 0.5;">Loop Detected: ${role.name}</div>`;
        return;
    }
    
    const nodeWrapper = document.createElement('div');
    nodeWrapper.className = 'node-wrapper';
    nodeWrapper.style.display = 'flex';
    nodeWrapper.style.alignItems = 'center';
    
    // Check if it's a multi-parent role
    const parentCount = links.filter(l => l.child_role_id == role.id).length;
    
    nodeWrapper.innerHTML = `
        <div class="role-card">
            ${parentCount > 1 ? '<div class="multi-badge">MULTI-LINKED</div>' : ''}
            <div class="role-avatar"><i class="fa-solid fa-user-circle"></i></div>
            <div class="role-info">
                <div class="role-name">${role.name}</div>
                <div class="role-desc">Organization Member</div>
            </div>
            <div class="role-actions">
                <button class="action-circle action-add" title="Add Sub-Role" onclick="addSubRole(${role.id})"><i class="fa-solid fa-plus"></i></button>
                <button class="action-circle" title="Link to existing Parent" onclick="linkToParent(${role.id})"><i class="fa-solid fa-link"></i></button>
                <button class="action-circle" title="Rename" onclick="renameRole(${role.id}, '${role.name}')"><i class="fa-solid fa-pen"></i></button>
                <button class="action-circle" title="Delete Role" onclick="deleteRole(${role.id})" style="color:var(--hot);"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
    `;

    const nextChildrenIds = links.filter(l => l.parent_role_id == role.id).map(l => l.child_role_id);
    const nextRoles = roles.filter(r => nextChildrenIds.includes(r.id));

    if (nextRoles.length > 0) {
        const ul = document.createElement('ul');
        nextRoles.forEach(child => {
            const li = document.createElement('li');
            renderNodeRecursive(li, child, [...path, role.id]);
            ul.appendChild(li);
        });
        nodeWrapper.appendChild(ul);
    }

    container.appendChild(nodeWrapper);
}

// User Actions
async function addRootRole() {
    const name = prompt("Enter Root Role Name:");
    if (!name) return;
    await apiAction('add_role', { name });
}

async function addSubRole(parentId) {
    const name = prompt("Enter Name of the Subordinate Role:");
    if (!name) return;
    await apiAction('add_role', { name, parent_id: parentId });
}

async function linkToParent(childId) {
    const otherRoles = roles.filter(r => r.id != childId);
    let options = otherRoles.map(r => `${r.id}: ${r.name}`).join('\n');
    const pid = prompt("Enter the ID of the additional Parent / Manager for this team member:\n\n" + options);
    if (!pid || isNaN(pid)) return;
    await apiAction('link_parent', { child_id: childId, parent_id: pid });
}

async function renameRole(id, current) {
    const name = prompt("Rename to:", current);
    if (!name || name === current) return;
    await apiAction('rename_role', { id, name });
}

async function deleteRole(id) {
    if (!confirm("Delete this role entirely? Relationships will be removed.")) return;
    await apiAction('delete_role', { id });
}

async function apiAction(action, params) {
    const body = new URLSearchParams();
    for (let k in params) body.append(k, params[k]);
    await fetch('/api/roles_api.php?action=' + action, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    });
    init();
}

init();
</script>
</body>
</html>

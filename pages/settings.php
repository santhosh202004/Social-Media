<?php
require_once '../includes/auth.php';
if (!isset($_GET['ajax'])) { header("Location: ../dashboard.php?page=settings"); exit; }
// pages/settings.php
require_once '../includes/db_config.php';
?>

<div class="section-header">
    <div>
        <div class="badge badge-blue" style="margin-bottom: 8px;">SYSTEM</div>
        <h1 class="section-title">Settings</h1>
        <p class="section-subtitle">Manage your Facebook API credentials and active page.</p>
    </div>
</div>

<div id="formContainer" style="display: none; margin-bottom: 32px;">
    <div class="card" style="max-width: 600px; margin: 0 auto; padding: 32px;">
    <form id="settingsForm">
        <input type="hidden" id="config_id" name="id" value="">
        <div style="margin-bottom: 20px;">
            <label for="account_name" class="form-label">Account Name</label>
            <input type="text" id="account_name" name="account_name" required class="form-control" placeholder="e.g. My Business Account">
        </div>

        <div style="margin-bottom: 20px;">
            <label for="access_token" class="form-label">Access Token</label>
            <input type="text" id="access_token" name="access_token" required class="form-control" placeholder="Enter Meta Access Token">
        </div>

        <div style="margin-bottom: 20px;">
            <label for="ad_account_id" class="form-label">Ad Account ID</label>
            <input type="text" id="ad_account_id" name="ad_account_id" required class="form-control" placeholder="e.g. act_123456789">
        </div>

        <div style="margin-bottom: 24px; position: relative;">
            <label for="page_id" class="form-label">Facebook Page ID</label>
            <div style="display: flex; gap: 8px;">
                <select id="page_id" name="page_id" required class="form-control" style="flex: 1;">
                    <option value="">Enter Access Token first to load pages</option>
                </select>
                <button type="button" id="btnLoadPages" class="btn btn-outline" style="white-space: nowrap;">Fetch Pages</button>
            </div>
            <div id="pageLoaderSpinner" style="display: none; position: absolute; right: 110px; top: 40px; width: 16px; height: 16px; border: 2px solid var(--outline-variant); border-top-color: var(--primary); border-radius: 50%; animation: spin 1s linear infinite;"></div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid var(--outline-variant); padding-top: 24px;">
            <button type="button" id="btnCancel" class="btn btn-outline" style="display: none;">
                Cancel
            </button>
            <button type="submit" id="btnSave" class="btn btn-primary">
                Save Account
            </button>
        </div>
        
        <div id="settingsMessage" style="margin-top: 1rem; padding: 0.75rem; border-radius: 8px; display: none; text-align: center; font-weight: 500;"></div>
    </form>
    </div>
</div>

<div class="card" style="max-width: 900px; margin: 32px auto;">
    <div class="card-header" style="flex-direction: row; justify-content: space-between; align-items: center;">
        <h2 class="card-title">Saved Accounts</h2>
        <button class="btn btn-primary btn-sm" onclick="window.addNewAccount()">+ Add New Account</button>
    </div>
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Account Name</th>
                    <th>Ad Account ID</th>
                    <th>Page ID</th>
                    <th style="text-align: center;">Status</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody id="accountsTableBody">
                <tr><td colspan="5" style="text-align: center; padding: 1rem;">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
(function() {
    const btnSave = document.getElementById('btnSave');
    const settingsMessage = document.getElementById('settingsMessage');
    const form = document.getElementById('settingsForm');

    function showMessage(msg, isError = false) {
        settingsMessage.textContent = msg;
        settingsMessage.style.display = 'block';
        settingsMessage.style.backgroundColor = isError ? '#fee2e2' : '#d1fae5';
        settingsMessage.style.color = isError ? '#dc2626' : '#059669';
    }

    const btnLoadPages = document.getElementById('btnLoadPages');
    const pageIdSelect = document.getElementById('page_id');
    const pageLoaderSpinner = document.getElementById('pageLoaderSpinner');
    
    btnLoadPages.addEventListener('click', async () => {
        const token = document.getElementById('access_token').value.trim();
        if (!token) {
            if (window.showGlobalToast) {
                window.showGlobalToast('Please enter an Access Token first.', 'warning');
            } else {
                alert('Please enter an Access Token first.');
            }
            return;
        }
        
        btnLoadPages.disabled = true;
        pageLoaderSpinner.style.display = 'block';
        
        try {
            const res = await fetch('api/fetch_fb_pages.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ access_token: token })
            });
            const data = await res.json();
            
            if (data.success && data.pages.length > 0) {
                pageIdSelect.innerHTML = '<option value="">Select a Facebook Page</option>';
                data.pages.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = `${p.name} (${p.id})`;
                    pageIdSelect.appendChild(opt);
                });
            } else {
                if (window.showGlobalToast) {
                    window.showGlobalToast(data.error || 'No pages found for this token.', 'error');
                } else {
                    alert(data.error || 'No pages found for this token.');
                }
                pageIdSelect.innerHTML = '<option value="">No pages found</option>';
            }
        } catch (err) {
            if (window.showGlobalToast) {
                window.showGlobalToast('Failed to fetch pages.', 'error');
            } else {
                alert('Failed to fetch pages.');
            }
        } finally {
            btnLoadPages.disabled = false;
            pageLoaderSpinner.style.display = 'none';
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        btnSave.textContent = 'Saving & Fetching...';
        btnSave.disabled = true;

        const formData = {
            id: document.getElementById('config_id').value,
            account_name: document.getElementById('account_name').value.trim(),
            access_token: document.getElementById('access_token').value.trim(),
            ad_account_id: document.getElementById('ad_account_id').value.trim(),
            page_id: document.getElementById('page_id').value
        };

        try {
            const res = await fetch('api/save_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            });
            const data = await res.json();

            if (data.success) {
                if (window.showGlobalToast) {
                    window.showGlobalToast('Account saved successfully! Redirecting...', 'success');
                }
                showMessage('Account saved! Redirecting to dashboard...', false);
                setTimeout(() => {
                    window.location.href = 'dashboard.php';
                }, 1000);
            } else {
                if (window.showGlobalToast) {
                    window.showGlobalToast(data.error || 'Failed to save settings.', 'error');
                }
                showMessage(data.error || 'Failed to save settings.', true);
                btnSave.textContent = 'Save Account';
                btnSave.disabled = false;
            }
        } catch (err) {
            if (window.showGlobalToast) {
                window.showGlobalToast(err.message, 'error');
            }
            showMessage(err.message, true);
            btnSave.textContent = 'Save Account';
            btnSave.disabled = false;
        }
    });

    async function loadAccounts() {
        try {
            const res = await fetch('api/get_accounts.php');
            const data = await res.json();
            const tbody = document.getElementById('accountsTableBody');
            
            if (data.success) {
                tbody.innerHTML = '';
                if(data.accounts.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 1rem;">No accounts found.</td></tr>';
                    return;
                }
                
                data.accounts.forEach(acc => {
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid var(--outline-variant)';
                    tr.style.transition = 'background 0.2s';
                    
                    const statusBg = acc.is_active == 1 ? '#d1fae5' : '#f3f4f6';
                    const statusColor = acc.is_active == 1 ? '#059669' : '#6b7280';
                    const statusText = acc.is_active == 1 ? 'Active' : 'Inactive';

                    tr.innerHTML = `
                        <td style="padding: 16px 12px; vertical-align: middle;">
                            <div style="font-weight: 700; color: var(--on-surface);">${acc.account_name}</div>
                        </td>
                        <td class="mono" style="padding: 16px 12px; vertical-align: middle; font-size: 12px; color: var(--on-surface-variant);">${acc.ad_account_id}</td>
                        <td class="mono" style="padding: 16px 12px; vertical-align: middle; font-size: 12px; color: var(--on-surface-variant);">${acc.page_id}</td>
                        <td style="padding: 16px 12px; vertical-align: middle; text-align: center;">
                            <div class="badge badge-${acc.is_active == 1 ? 'green' : 'gray'}">
                                ${acc.is_active == 1 ? '<div class="badge-dot"></div>' : ''}
                                ${statusText}
                            </div>
                        </td>
                        <td style="padding: 16px 12px; vertical-align: middle; text-align: right;">
                            <div style="display: flex; gap: 8px; justify-content: flex-end; align-items: center;">
                                ${acc.is_active == 1 
                                    ? '<span style="color:var(--primary); font-size:10px; font-weight:800; margin-right:8px; text-transform:uppercase; letter-spacing:0.05em;">Active</span>' 
                                    : `<button onclick="window.setActiveAccount(${acc.id})" title="Set Active" class="btn btn-outline btn-sm" style="padding:6px;"><span class="material-symbols-outlined" style="font-size: 18px;">check_circle</span></button>`}
                                
                                <button onclick='window.editAccount(${JSON.stringify(acc).replace(/'/g, "&apos;")})' title="Edit Account" class="btn btn-outline btn-sm" style="padding:6px;"><span class="material-symbols-outlined" style="font-size: 18px;">edit</span></button>
                                
                                <button onclick="window.deleteAccount(${acc.id}, ${acc.is_active})" title="Delete" class="btn btn-outline btn-sm" style="padding:6px; color:var(--error);"><span class="material-symbols-outlined" style="font-size: 18px;">delete</span></button>
                            </div>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            }
        } catch (err) {
            console.error('Failed to load accounts:', err);
        }
    }

    // Define globally so inline onclick works
    window.setActiveAccount = async function(id) {
        if(confirm('Switch to this account? This will reload the dashboard.')) {
            try {
                const res = await fetch('api/set_active_account.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await res.json();
                if(data.success) {
                    window.location.href = 'dashboard.php'; // full reload to update top bar
                } else {
                    if (window.showGlobalToast) {
                        window.showGlobalToast(data.error, 'error');
                    } else {
                        alert('Error: ' + data.error);
                    }
                }
            } catch (err) {
                if (window.showGlobalToast) {
                    window.showGlobalToast('Error switching account.', 'error');
                } else {
                    alert('Error switching account.');
                }
            }
        }
    };

    window.editAccount = function(acc) {
        document.getElementById('formContainer').style.display = 'block';
        document.getElementById('config_id').value = acc.id;
        document.getElementById('account_name').value = acc.account_name;
        document.getElementById('access_token').value = acc.access_token;
        document.getElementById('ad_account_id').value = acc.ad_account_id;
        
        pageIdSelect.innerHTML = `<option value="${acc.page_id}">Keep Current Page (${acc.page_id})</option>`;
        pageIdSelect.value = acc.page_id;
        
        // Scroll to form
        window.scrollTo({ top: 0, behavior: 'smooth' });
        
        // Update button text
        btnSave.textContent = 'Update Account';
        document.getElementById('btnCancel').style.display = 'block';
        
        // Highlight form
        form.closest('.card').style.borderColor = 'var(--accent-blue)';
        setTimeout(() => {
            form.closest('.card').style.borderColor = 'var(--glass-border)';
        }, 2000);
    };

    window.addNewAccount = function() {
        document.getElementById('formContainer').style.display = 'block';
        settingsMessage.style.display = 'none';
        form.reset();
        document.getElementById('config_id').value = '';
        pageIdSelect.innerHTML = '<option value="">Enter Access Token first to load pages</option>';
        btnSave.textContent = 'Save Account';
        document.getElementById('btnCancel').style.display = 'block'; // Show cancel even for new
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    window.hideForm = function() {
        document.getElementById('formContainer').style.display = 'none';
        settingsMessage.style.display = 'none';
    };

    document.getElementById('btnCancel').addEventListener('click', window.hideForm);

    window.deleteAccount = async function(id, isActive) {
        if (isActive) {
            if (window.showGlobalToast) {
                window.showGlobalToast('Cannot delete the currently active account. Please switch to another account first.', 'warning');
            } else {
                alert("Cannot delete the currently active account. Please switch to another account first.");
            }
            return;
        }

        if (confirm('Are you sure you want to move this account to trash? This action cannot be undone.')) {
            try {
                const res = await fetch('api/delete_account.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await res.json();
                if (data.success) {
                    loadAccounts(); // Refresh table
                } else {
                    if (window.showGlobalToast) {
                        window.showGlobalToast(data.error, 'error');
                    } else {
                        alert('Error: ' + data.error);
                    }
                }
            } catch (err) {
                if (window.showGlobalToast) {
                    window.showGlobalToast('Error deleting account.', 'error');
                } else {
                    alert('Error deleting account.');
                }
            }
        }
    };

    // Initial load
    loadAccounts();
})();
</script>

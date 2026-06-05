<?php
/**
 * Dashboard Shell
 * The main layout: sidebar + content area.
 * Each sidebar click loads the corresponding page/view.php via AJAX into #dashboard-content.
 */
require_once 'includes/auth.php';
require_once 'includes/db_config.php';

// Get current active account name for the top bar
try {
    $stmt = $pdo->query("SELECT account_name, ad_account_id FROM facebook_config WHERE is_active = 1 LIMIT 1");
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $account = null;
}
$displayName = $account['account_name'] ?? ($account['ad_account_id'] ?? 'No Account');

// Determine which page to load by default
$page = $_GET['page'] ?? 'overview';
$validPages = ['overview', 'campaigns', 'campaign_details', 'content', 'insights', 'audience', 'settings', 'reports'];
if (!in_array($page, $validPages)) $page = 'overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AdMetrics Dashboard</title>
    <link rel="icon" type="image/png" href="assets/images/fav.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <div class="dashboard-container">

        <!-- ===== LEFT SIDEBAR ===== -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <img src="assets/images/logo1.png" alt="AdMetrics Logo" class="sidebar-logo">
                </div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-item <?= $page === 'overview'   ? 'active' : '' ?>" data-page="overview">
                        <span class="material-symbols-outlined nav-icon">dashboard</span>
                        <span class="nav-text">Overview</span>
                    </div>
                    <div class="nav-item <?= $page === 'campaigns'  ? 'active' : '' ?>" data-page="campaigns">
                        <span class="material-symbols-outlined nav-icon">campaign</span>
                        <span class="nav-text">Campaigns</span>
                    </div>
                    <div class="nav-item <?= $page === 'content'    ? 'active' : '' ?>" data-page="content">
                        <span class="material-symbols-outlined nav-icon">perm_media</span>
                        <span class="nav-text">Content</span>
                    </div>
                     <div class="nav-item <?= $page === 'insights'   ? 'active' : '' ?>" data-page="insights">
                        <span class="material-symbols-outlined nav-icon">bar_chart</span>
                        <span class="nav-text">Insights</span>
                    </div> 
                    <div class="nav-item <?= $page === 'audience'   ? 'active' : '' ?>" data-page="audience">
                        <span class="material-symbols-outlined nav-icon">group</span>
                        <span class="nav-text">Audience</span>
                    </div>
                    <div class="nav-item <?= $page === 'reports'    ? 'active' : '' ?>" data-page="reports">
                        <span class="material-symbols-outlined nav-icon">description</span>
                        <span class="nav-text">Reports</span>
                    </div>
                </div>

                <div class="nav-section" style="margin-top:auto; border-top:1px solid var(--outline-variant); padding-top:8px; margin-top:16px;">
                    <!-- <div class="nav-item <?= $page === 'settings'   ? 'active' : '' ?>" data-page="settings">
                        <span class="material-symbols-outlined nav-icon">settings</span>
                        <span class="nav-text">Settings</span>
                    </div> -->
                    <a href="logout.php" class="nav-item" style="text-decoration: none; display: flex; align-items: center; gap: 14px;">
                        <span class="material-symbols-outlined nav-icon" style="color: #ef4444;">logout</span>
                        <span class="nav-text" style="color: #ef4444;">Logout</span>
                    </a>
                </div>
            </nav>

            <div class="sidebar-footer">
                <div class="user-profile">
                    <div class="avatar"><?= strtoupper(substr($displayName, 0, 1)) ?></div>
                    <div class="user-info">
                        <span class="user-name"><?= htmlspecialchars($displayName) ?></span>
                        <span class="user-role">Administrator</span>
                    </div>
                </div>
            </div>
        </aside>

        <!-- ===== MAIN CONTENT AREA ===== -->
        <main class="main-content">

            <!-- Top Bar -->
            <header class="top-bar">
                <!-- Left: Page title (updated by JS) -->
                <div class="top-bar-left">
                    <span class="material-symbols-outlined" style="color:var(--primary);font-size:22px;" id="topbar-icon">dashboard</span>
                    <span class="page-title" id="topbar-title">Overview</span>
                </div>

                <!-- Right Side Tools -->
                <div class="top-bar-right">
                    <!-- Date Range -->
                    <div class="date-range-filter">
                        <span class="material-symbols-outlined" style="font-size:18px;color:var(--on-surface-variant);">calendar_today</span>
                        <input type="date" id="start-date" title="Start Date">
                        <span class="date-sep">—</span>
                        <input type="date" id="end-date" title="End Date">
                    </div>

                    <div style="width:1px;height:22px;background:var(--outline-variant);"></div>

                    <!-- Account Badge -->
                    <div class="acct-badge">
                        <span class="dot-green"></span>
                        <span id="display-acct-name"><?= htmlspecialchars($displayName) ?></span>
                    </div>

                    <!-- Settings shortcut -->
                    <!-- <a href="?page=settings" class="icon-btn" title="Settings">
                        <span class="material-symbols-outlined">manage_accounts</span>
                    </a> -->
                </div>
            </header>

            <!-- Dynamic Content Body — pages load here -->
            <div class="content-body" id="dashboard-content">
                <?php $loaderText = 'Loading...'; include 'includes/loader.php'; ?>
            </div>
        </main>
    </div>

    <script>
        // ===================================================
        // Dashboard Router — Lightweight navigation controller
        // ===================================================
        const contentBody   = document.getElementById('dashboard-content');
        const startDateInput = document.getElementById('start-date');
        const endDateInput   = document.getElementById('end-date');

        // Set default date range (last 30 days)
        const today = new Date();
        const thirtyDaysAgo = new Date();
        thirtyDaysAgo.setDate(today.getDate() - 30);

        const formatYMD = (date) => {
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        };

        startDateInput.value = formatYMD(thirtyDaysAgo);
        endDateInput.value   = formatYMD(today);

        let currentPage = '<?= $page ?>';

        // ---- Page Loader ----
        async function loadPage(page, extraParams = {}) {
            currentPage = page;

            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            
            // Add extraParams to URL
            for (const [key, value] of Object.entries(extraParams)) {
                url.searchParams.set(key, value);
            }
            
            // Remove previous extra params if not in current extraParams
            const urlParamsKeys = Array.from(url.searchParams.keys());
            urlParamsKeys.forEach(k => {
                if (!['page', 'since', 'until'].includes(k) && !(k in extraParams)) {
                    url.searchParams.delete(k);
                }
            });

            window.history.pushState({page, ...extraParams}, '', url);

            // Update topbar title + icon
            const pageLabels = {
                overview: { title: 'Overview', icon: 'dashboard' },
                campaigns: { title: 'Campaigns', icon: 'campaign' },
                campaign_details: { title: 'Campaign Details', icon: 'campaign' },
                content: { title: 'Content', icon: 'perm_media' },
                insights: { title: 'Insights', icon: 'bar_chart' },
                audience: { title: 'Audience', icon: 'group' },
                reports: { title: 'Reports', icon: 'description' },
                settings: { title: 'Settings', icon: 'settings' },
            };
            const meta = pageLabels[page] || { title: page, icon: 'circle' };
            const topbarTitle = document.getElementById('topbar-title');
            const topbarIcon  = document.getElementById('topbar-icon');
            if (topbarTitle) topbarTitle.textContent = meta.title;
            if (topbarIcon)  topbarIcon.textContent  = meta.icon;

            // Update sidebar active state
            document.querySelectorAll('.nav-item[data-page]').forEach(el => {
                // If it's campaign_details, highlight campaigns
                const targetPage = page === 'campaign_details' ? 'campaigns' : page;
                el.classList.toggle('active', el.dataset.page === targetPage);
            });

            // Build query string
            const params = new URLSearchParams({
                since: startDateInput.value,
                until: endDateInput.value,
                ...extraParams
            });

            // Show spinner with detailed progress text
            contentBody.innerHTML = `
                <div class="loader-container">
                    <div class="spinner"></div>
                    <span class="loader-text">Loading ${meta.title}...</span>
                    <span style="display:block; margin-top:8px; font-size:12px; color:var(--on-surface-variant);">Fetching live data from Meta APIs</span>
                </div>`;


            try {
                const res = await fetch(`pages/${page}.php?${params}&ajax=1`);
                if (!res.ok) throw new Error(`Server returned ${res.status}`);
                const html = await res.text();
                contentBody.innerHTML = html;

                // Execute any <script> tags returned by the page
                contentBody.querySelectorAll('script').forEach(oldScript => {
                    const newScript = document.createElement('script');
                    // Wrap the executed script in a try-catch to log errors to console
                    newScript.textContent = `try { ${oldScript.textContent} } catch(e) { console.error('Error executing page script:', e); }`;
                    document.body.appendChild(newScript);
                    document.body.removeChild(newScript);
                });
            } catch (err) {
                contentBody.innerHTML = `
                    <div style="padding:2rem;text-align:center;">
                        <div style="font-size:2rem;margin-bottom:1rem;">⚠️</div>
                        <h2 style="color:#ef4444;">Failed to load page</h2>
                        <p style="color:var(--text-secondary);margin-top:0.5rem;">${err.message}</p>
                        <button onclick='loadPage("${page}", ${JSON.stringify(extraParams).replace(/"/g, '&quot;')})' style="margin-top:1.5rem;padding:0.7rem 1.5rem;border:none;border-radius:10px;background:var(--accent-blue);color:white;font-weight:700;cursor:pointer;">Retry</button>
                    </div>`;
            }
        }

        // ---- Sidebar click listeners ----
        document.querySelectorAll('.nav-item[data-page]').forEach(item => {
            item.addEventListener('click', () => loadPage(item.dataset.page));
        });

        // ---- Date range auto-refresh ----
        [startDateInput, endDateInput].forEach(input => {
            input.addEventListener('change', () => {
                const currentParams = new URLSearchParams(window.location.search);
                const currentExtra = {};
                for (let [k, v] of currentParams.entries()) {
                    if (!['page', 'since', 'until'].includes(k)) {
                        currentExtra[k] = v;
                    }
                }
                loadPage(currentPage, currentExtra);
            });
        });

        // ---- Browser back/forward ----
        window.addEventListener('popstate', (e) => {
            if (e.state && e.state.page) {
                const { page, ...extra } = e.state;
                loadPage(page, extra);
            }
        });

        // ---- Initial load ----
        const initialParams = new URLSearchParams(window.location.search);
        const initExtraParams = {};
        for(let [k,v] of initialParams.entries()) {
            if(!['page', 'since', 'until'].includes(k)) {
                initExtraParams[k] = v;
            }
        }
        loadPage(currentPage, initExtraParams);

        // ===================================================
        // Global Toast Notification Helper
        // ===================================================
        window.showGlobalToast = function(message, type = 'error', actionText = null, actionLink = null) {
            const container = document.getElementById('toast-container');
            if (!container) return;

            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            const icons = {
                error: 'error',
                warning: 'warning',
                success: 'check_circle',
                info: 'info'
            };
            const iconName = icons[type] || 'info';

            const titles = {
                error: 'API Connection Error',
                warning: 'Configuration Warning',
                success: 'Success',
                info: 'Notification'
            };
            const title = titles[type] || 'Notification';

            let actionHtml = '';
            if (actionText && actionLink) {
                actionHtml = `<a href="${actionLink}" class="toast-action">
                    ${actionText}
                    <span class="material-symbols-outlined" style="font-size:14px;">arrow_forward</span>
                </a>`;
            }

            toast.innerHTML = `
                <span class="material-symbols-outlined toast-icon">${iconName}</span>
                <div class="toast-content">
                    <span class="toast-title">${title}</span>
                    <p class="toast-message">${message}</p>
                    ${actionHtml}
                </div>
                <button class="toast-close" title="Dismiss">
                    <span class="material-symbols-outlined" style="font-size:18px;">close</span>
                </button>
            `;

            const dismiss = () => {
                if (toast.classList.contains('toast-hide')) return;
                toast.classList.add('toast-hide');
                toast.addEventListener('animationend', () => {
                    toast.remove();
                });
            };

            toast.querySelector('.toast-close').addEventListener('click', dismiss);

            let timer = setTimeout(dismiss, 7000);

            toast.addEventListener('mouseenter', () => clearTimeout(timer));
            toast.addEventListener('mouseleave', () => {
                timer = setTimeout(dismiss, 4000);
            });

            container.appendChild(toast);
        };
    </script>

    <!-- Global Toast Container -->
    <div id="toast-container" class="toast-container"></div>
</body>
</html>

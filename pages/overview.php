<?php
require_once '../includes/auth.php';
if (!isset($_GET['ajax'])) { header("Location: ../dashboard.php?page=overview"); exit; }
/**
 * Overview Page
 * Fetches and renders unified Facebook + Instagram KPI cards and a growth chart.
 * Loaded via AJAX into #dashboard-content by dashboard.php
 */
$since = $_GET['since'] ?? date('Y-m-d', strtotime('-30 days'));
$until = $_GET['until'] ?? date('Y-m-d');
?>

<div class="section-header">
    <div>
        <div class="badge badge-blue" style="margin-bottom: 8px;">EXECUTIVE SUMMARY</div>
        <h1 class="section-title">Platform Overview</h1>
        <p class="section-subtitle">Combined organic performance for Facebook and Instagram.</p>
    </div>
</div>

<!-- KPI Cards — populated by JS below -->
<div id="overview-kpi" class="kpi-grid">
    <?php $loaderText = 'Loading Overview...'; include __DIR__ . '/../includes/loader.php'; ?>
</div>

<div class="mt-24" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
    <div class="card" style="grid-column: span 2;">
        <div class="card-header">
            <h3 class="card-title">What Audiences Watched</h3>
        </div>
        <div class="card-body">
            <canvas id="watchedCombinedChart" height="250"></canvas>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Followers Split (FB vs IG)</h3>
        </div>
        <div class="card-body" style="display: flex; justify-content: center; align-items: center; min-height: 250px;">
            <canvas id="followersPieChart"></canvas>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Follower vs Non-Follower Reach (IG)</h3>
        </div>
        <div class="card-body" style="display: flex; justify-content: center; align-items: center; min-height: 250px;">
            <canvas id="reachDoughnutChart"></canvas>
        </div>
    </div>
</div>

<script>
;(function() {
const fbIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#1877F2" style="flex-shrink:0;vertical-align:middle;margin-right:2px;"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>`;
const igIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#E1306C" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;vertical-align:middle;margin-right:2px;"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>`;

(async function() {
    const since = '<?= htmlspecialchars($since) ?>';
    const until = '<?= htmlspecialchars($until) ?>';
    const kpiContainer = document.getElementById('overview-kpi');

    try {
        // Add timeout via AbortController (30 seconds)
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000);
        
        // Store controller to window so we can abort previous fetches on reload if needed
        if (window._overviewFetchController) window._overviewFetchController.abort();
        window._overviewFetchController = controller;

        const [res, campaignRes, audienceRes] = await Promise.all([
            fetch(`api/fetch_overview.php?since=${since}&until=${until}`, { signal: controller.signal }).catch(e => ({ok: false, json: async () => ({error: 'fetch_failed', message: e.message})})),
            fetch(`api/fetch_campaigns_index.php?json=1&since=${since}&until=${until}`, { signal: controller.signal }).catch(e => ({ok: false, json: async () => []})),
            fetch(`api/fetch_audience.php?fields=content_consumption&since=${since}&until=${until}`, { signal: controller.signal }).catch(e => ({ok: false, json: async () => ({})}))
        ]);
        
        clearTimeout(timeoutId);

        // If user navigated away while fetching, abort silently
        if (!document.getElementById('overview-kpi')) return;

        let data = { error: 'Unknown Error' };
        try {
            // Check HTTP status before parsing
            if (!res.ok && typeof res.status !== 'undefined') throw new Error(`HTTP error ${res.status}`);
            data = await res.json();
        } catch(e) {
            console.error("Overview data fetch failed:", e);
            data = { error: 'parse_error', message: 'Failed to load valid overview data.' };
        }

        let campaignsData = [];
        try { if (campaignRes.ok !== false) campaignsData = await campaignRes.json(); } catch(e) {}
        let audData = {};
        try { if (audienceRes.ok !== false) audData = await audienceRes.json(); } catch(e) {}

        if (data.error) {
            if (window.showGlobalToast) {
                window.showGlobalToast(data.message || 'API error - check settings.', 'error', 'Configure', '?page=settings');
            }
            kpiContainer.innerHTML = `<div style="padding:2rem;color:var(--on-surface-variant);text-align:center;grid-column:1/-1;">API error occurred. Please check settings.</div>`;
            const emptyStateHtml = '<div style="display:flex;height:100%;align-items:center;justify-content:center;color:var(--on-surface-variant);font-size:14px;">Data unavailable</div>';
            document.getElementById('watchedCombinedChart').parentElement.innerHTML = emptyStateHtml;
            document.getElementById('followersPieChart').parentElement.innerHTML = emptyStateHtml;
            document.getElementById('reachDoughnutChart').parentElement.innerHTML = emptyStateHtml;
            return;
        }

        let totalLeads = 0;
        let activeCampaigns = 0;
        if (Array.isArray(campaignsData)) {
            campaignsData.forEach(c => {
                if (c.insights && c.insights.actions) {
                    const leadAction = c.insights.actions.find(a => a.action_type === 'lead' || a.action_type === 'onsite_conversion.lead_grouped');
                    if (leadAction) {
                        totalLeads += parseInt(leadAction.value);
                    }
                }
                if (c.status === 'ACTIVE') {
                    activeCampaigns++;
                }
            });
        }

        kpiContainer.innerHTML = `
            <div class="kpi-card">
                <div class="kpi-label">Total Followers</div>
                <div class="kpi-value">${Number(data.total.followers).toLocaleString()}</div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
                    <div class="kpi-badge up" style="margin-top:0;">↑ ${Number(data.total.new_followers).toLocaleString()} New</div>
                    <div style="display:flex;gap:12px;font-size:11px;color:var(--on-surface-variant);font-weight:600;align-items:center;">
                        <span style="display:inline-flex;align-items:center;gap:2px;">${fbIcon}${Number(data.facebook.followers).toLocaleString()}</span>
                        <span style="display:inline-flex;align-items:center;gap:2px;">${igIcon}${Number(data.instagram.followers).toLocaleString()}</span>
                    </div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Combined Reach</div>
                <div class="kpi-value">${Number(data.total.reach).toLocaleString()}</div>
                <div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;color:var(--on-surface-variant);margin-top:4px;font-weight:600;">
                    <span style="display:inline-flex;align-items:center;gap:2px;">${fbIcon}${Number(data.facebook.reach).toLocaleString()}</span>
                    <span style="display:inline-flex;align-items:center;gap:2px;">${igIcon}${Number(data.instagram.reach).toLocaleString()}</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Profile / Page Views</div>
                <div class="kpi-value">${Number(data.total.views).toLocaleString()}</div>
                <div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;color:var(--on-surface-variant);margin-top:4px;font-weight:600;">
                    <span style="display:inline-flex;align-items:center;gap:2px;">${fbIcon}${Number(data.facebook.views).toLocaleString()}</span>
                    <span style="display:inline-flex;align-items:center;gap:2px;">${igIcon}${Number(data.instagram.views).toLocaleString()}</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Content Posted</div>
                <div class="kpi-value">${Number(data.total.posts_count).toLocaleString()}</div>
                <div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;color:var(--on-surface-variant);margin-top:4px;font-weight:600;">
                    <span style="display:inline-flex;align-items:center;gap:2px;">${fbIcon}${Number(data.content_inventory.fb_posts + data.content_inventory.fb_videos).toLocaleString()}</span>
                    <span style="display:inline-flex;align-items:center;gap:2px;">${igIcon}${Number(data.content_inventory.ig_posts + data.content_inventory.ig_reels).toLocaleString()}</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">IG Website Clicks</div>
                <div class="kpi-value">${Number(data.instagram.website_clicks).toLocaleString()}</div>
                <div style="font-size:11px;color:var(--on-surface-variant);margin-top:4px;font-weight:600;">
                    <span>Instagram Link Clicks</span>
                </div>
            </div>
          
             <div class="kpi-card">
                <div class="kpi-label">IG Profile Visits</div>
                <div class="kpi-value">${Number(data.instagram.profile_visits).toLocaleString()}</div>
                <div style="font-size:11px;color:var(--on-surface-variant);margin-top:4px;font-weight:600;">
                    <span>Instagram Only</span>
                </div>
            </div>
            <div class="kpi-card" onclick="loadPage('campaigns')" style="grid-column: span 2; background: linear-gradient(135deg, var(--primary) 0%, #1d4ed8 100%); color: white; border: none; position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; min-height: 125px; box-shadow: 0 10px 15px -3px rgba(37,99,235,0.2); cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 12px 20px -3px rgba(37,99,235,0.3)';" onmouseout="this.style.transform='none'; this.style.boxShadow='0 10px 15px -3px rgba(37,99,235,0.2)';">
                <div style="position: absolute; right: 15px; top: -10px; opacity: 0.15; font-size: 80px; color: white;" class="material-icons-round">leaderboard</div>
                <div class="kpi-label" style="color: rgba(255,255,255,0.85); font-weight: 700;">Total Campaign Leads</div>
                <div class="kpi-value" style="color: white; font-size: 34px; line-height: 1.2;">${totalLeads.toLocaleString()}</div>
                <div style="font-size: 11px; color: rgba(255,255,255,0.9); font-weight: 600; display: flex; justify-content: space-between; align-items: center; margin-top: 4px; width: 100%;">
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <span class="material-icons-round" style="font-size: 45px;">campaign</span>
                        <span>${activeCampaigns} Active Campaign${activeCampaigns !== 1 ? 's' : ''}</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 2px; background: rgba(255,255,255,0.2); padding: 2px 6px; border-radius: var(--radius-sm);">
                        <span>View Details</span>
                        <span class="material-icons-round" style="font-size: 10px;">arrow_forward</span>
                    </div>
                </div>
            </div>
        `;

        // Pie chart (Followers Split)
        const ctxPie = document.getElementById('followersPieChart').getContext('2d');
        new Chart(ctxPie, {
            type: 'pie',
            data: {
                labels: ['Facebook', 'Instagram'],
                datasets: [{
                    data: [data.facebook.followers, data.instagram.followers],
                    backgroundColor: [
                        '#1877F2', // FB Blue
                        '#E1306C'  // IG Pink
                    ],
                    borderWidth: 0
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });

        // Audiences Watched Charts (Combined)
        if (audData.instagram && audData.instagram.content_consumption) {
            const fData = audData.instagram.content_consumption.followers || {};
            const nfData = audData.instagram.content_consumption.non_followers || {};
            
            const labels = ['AD', 'REEL', 'POST'];
            const fValues = labels.map(l => fData[l] ? fData[l].pct : 0);
            const nfValues = labels.map(l => nfData[l] ? nfData[l].pct : 0);
            
            const sumF = fValues.reduce((a, b) => a + b, 0);
            const sumNf = nfValues.reduce((a, b) => a + b, 0);

            if (sumF === 0 && sumNf === 0) {
                document.getElementById('watchedCombinedChart').parentElement.innerHTML = '<div style="display:flex;height:100%;align-items:center;justify-content:center;color:#64748b;font-size:14px;">No audience data available for this period.</div>';
            } else {
                const ctxCombined = document.getElementById('watchedCombinedChart').getContext('2d');
                new Chart(ctxCombined, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Followers',
                                data: fValues,
                                backgroundColor: '#2563eb',
                                borderRadius: 4
                            },
                            {
                                label: 'Non-Followers',
                                data: nfValues,
                                backgroundColor: '#10b981',
                                borderRadius: 4
                            }
                        ]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true, 
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { display: true },
                            tooltip: { callbacks: { label: c => c.dataset.label + ': ' + c.raw + '%' } }
                        },
                        scales: {
                            x: { display: true, min: 0, max: 100, ticks: { callback: v => v + '%' } },
                            y: { display: true, grid: { display: false } }
                        }
                    }
                });
            }
        } else {
            document.getElementById('watchedCombinedChart').parentElement.innerHTML = '<div style="display:flex;height:100%;align-items:center;justify-content:center;color:#64748b;font-size:14px;">Failed to load audience data.</div>';
        }

        // Follower vs Non-follower Reach (Doughnut Chart)
        const nonFollowerPct = data.overview.non_follower_views_pct || 0;
        const followerPct = 100 - nonFollowerPct;
        
        if (nonFollowerPct === 0 && (data.instagram && data.instagram._breakdown_error)) {
            document.getElementById('reachDoughnutChart').parentElement.innerHTML = '<div style="display:flex;height:100%;align-items:center;justify-content:center;color:#64748b;font-size:14px;text-align:center;">Breakdown data unavailable.<br>Try a different date range.</div>';
        } else {
            const ctxReach = document.getElementById('reachDoughnutChart').getContext('2d');
            new Chart(ctxReach, {
                type: 'doughnut',
                data: {
                    labels: ['Non-Followers', 'Followers'],
                    datasets: [{
                        data: [nonFollowerPct, followerPct],
                        backgroundColor: [
                            '#10b981', // Green for new audiences
                            '#e2e8f0'  // Grey for existing
                        ],
                        borderWidth: 0
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: { 
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.raw + '%';
                                }
                            }
                        }
                    } 
                }
            });
        }
    } catch (err) {
        if (err.name === 'AbortError') {
            console.log('Fetch aborted');
            return; // Exit silently on intentional abort
        }
        if (window.showGlobalToast) {
            window.showGlobalToast(err.message || 'Failed to load page data', 'error');
        }
        kpiContainer.innerHTML = `<div style="padding:2rem;color:var(--on-surface-variant);text-align:center;grid-column:1/-1;">Failed to load overview data.</div>`;
        const emptyStateHtml = '<div style="display:flex;height:100%;align-items:center;justify-content:center;color:var(--on-surface-variant);font-size:14px;">Data unavailable</div>';
        document.getElementById('watchedCombinedChart').parentElement.innerHTML = emptyStateHtml;
        document.getElementById('followersPieChart').parentElement.innerHTML = emptyStateHtml;
        document.getElementById('reachDoughnutChart').parentElement.innerHTML = emptyStateHtml;
        console.error(err);
    }
})();
})();
</script>


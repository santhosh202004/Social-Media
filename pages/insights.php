<?php
require_once '../includes/auth.php';
if (!isset($_GET['ajax'])) { header("Location: ../dashboard.php?page=insights"); exit; }
/**
 * Insights Page
 * Content overview with tabs: All, Posts, Stories, Reels
 */
$since = $_GET['since'] ?? date('Y-m-d', strtotime('-6 months'));
$until = $_GET['until'] ?? date('Y-m-d');
?>

<div class="section-header" style="margin-bottom: 24px;">
    <div>
        <div class="badge badge-blue" style="margin-bottom: 8px;">INSIGHTS</div>
        <h1 class="section-title">Content Overview</h1>
        <p class="section-subtitle">
            Showing performance from <strong><?= htmlspecialchars($since) ?></strong> to <strong><?= htmlspecialchars($until) ?></strong>
        </p>
    </div>

    <!-- Filter Option Selectors (Aligned to Right Corner) -->
    <div style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
        <!-- Content Filter -->
        <div style="display: flex; flex-direction: column; gap: 6px;">
            <label for="content-filter" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--on-surface-variant); letter-spacing: 0.05em;">Content Type</label>
            <select id="content-filter" class="form-control" style="font-size: 13px; font-weight: 700; padding: 10px 16px; border-radius: var(--radius-md); width: 180px; background: var(--surface-low); border: 1px solid var(--outline-variant); color: var(--on-surface);" onchange="applyInsightsFilters()">
                <option value="all">All Content</option>
                <option value="post">Posts</option>
                <option value="reel">Reels</option>
            </select>
        </div>
        
        <!-- Page/Platform Filter -->
        <div style="display: flex; flex-direction: column; gap: 6px;">
            <label for="page-filter" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--on-surface-variant); letter-spacing: 0.05em;">Platform</label>
            <select id="page-filter" class="form-control" style="font-size: 13px; font-weight: 700; padding: 10px 16px; border-radius: var(--radius-md); width: 180px; background: var(--surface-low); border: 1px solid var(--outline-variant); color: var(--on-surface);" onchange="applyInsightsFilters()">
                <option value="all">All Platforms</option>
                <option value="fb">Facebook</option>
                <option value="instagram">Instagram</option>
            </select>
        </div>
    </div>
</div>

<!-- KPI Metric Cards -->
<div id="insights-kpi" class="kpi-grid" style="margin-bottom: 24px; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px;">
    <?php $loaderText = 'Loading Insights…'; include __DIR__ . '/../includes/loader.php'; ?>
</div>

<!-- Chart Area -->
<div id="insights-chart-area" style="display:none; margin-bottom: 24px;">
    <div class="card" style="padding: 24px; min-width: 0;">
        <div class="card-label" style="margin-bottom:12px; font-size:12px; font-weight:700; text-transform:uppercase; color:var(--on-surface-variant);">Views Trend</div>
        <div class="chart-wrap" style="height: 250px;">
            <canvas id="insightsViewsChart"></canvas>
        </div>
    </div>
</div>

<!-- Engagement Analytics + Type Comparison -->
<div id="insights-engagement-area" style="display:none; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24px; margin-bottom: 24px;">
    <div class="card" style="padding: 24px; min-width: 0;">
        <div class="card-label" style="margin-bottom:16px; font-size:12px; font-weight:700; text-transform:uppercase; color:var(--on-surface-variant);">Engagement Analytics</div>
        <div id="engagement-grid" style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px;"></div>
    </div>
    <div class="card" style="padding: 24px; min-width: 0;">
        <div class="card-label" style="margin-bottom:12px; font-size:12px; font-weight:700; text-transform:uppercase; color:var(--on-surface-variant);">Performance by Type</div>
        <div class="chart-wrap" style="height: 200px;">
            <canvas id="insightsTypeChart"></canvas>
        </div>
    </div>
</div>

<!-- Top Performing Content -->
<div id="insights-top-content-area" style="display:none; margin-bottom: 24px;">
    <h2 class="section-title" style="font-size: 18px; margin-bottom: 16px;">Top Performing Content</h2>
    <div id="top-content-scroll" style="display: flex; overflow-x: auto; gap: 16px; padding-bottom: 8px;"></div>
</div>

<!-- Posting Heatmap
<div id="insights-heatmap-area" style="display:none; margin-bottom: 24px;">
    <div class="card" style="padding: 24px;">
        <div class="card-label" style="margin-bottom:16px; font-size:12px; font-weight:700; text-transform:uppercase; color:var(--on-surface-variant);">Posting Heatmap (Views by Time)</div>
        <div id="heatmap-container" style="overflow-x: auto;"></div>
    </div>
</div>
-->

<!-- Detailed Content Table (Posts / Reels / Stories) -->
<div id="insights-detailed-content" style="display:none; margin-bottom: 24px;">
    <h2 id="detailed-content-title" class="section-title" style="font-size: 24px; margin-bottom: 16px; color: #1a4d3e; font-family: Georgia, serif;">Detailed Content Performance</h2>
    <div class="card" style="border-radius: 12px; overflow: hidden; background: #fff; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
        <div class="data-table-wrap" style="overflow-x: auto;">
            <table class="data-table" style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px;">
                <thead id="detailed-content-head" style="background: #eef6f3; color: #2d4a40; border-bottom: 1px solid #d5e3dc;"></thead>
                <tbody id="detailed-content-body"></tbody>
            </table>
        </div>
    </div>
</div>

<style>
.insights-tabs {
    display: flex;
    gap: 0;
    border-bottom: 1px solid var(--outline-variant);
    margin-bottom: 24px;
}
.insights-tab {
    padding: 12px 24px;
    border: none;
    background: transparent;
    font-size: 13px;
    font-weight: 700;
    color: var(--on-surface-variant);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: all 0.2s;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.insights-tab:hover { color: var(--on-surface); }
.insights-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}
.kpi-card.highlighted {
    border-color: var(--primary-fixed-dim);
    background: var(--surface-low);
}
.no-activity {
    text-align: center;
    padding: 40px 20px;
    color: var(--on-surface-variant);
}
.no-activity .na-icon { font-size: 40px; margin-bottom: 12px; opacity: 0.4; }
.ins-th { cursor: pointer; user-select: none; }
.ins-th:hover { color: var(--primary); }
.ins-td { vertical-align: middle; }
.platform-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    font-weight: 800;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: 6px;
    vertical-align: middle;
}
.badge-ig  { background:#fce7f3; color:#be185d; }
.badge-fb  { background:#dbeafe; color:#1d4ed8; }

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: .5; }
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<script>
(function () {
    /* ───────────── state ───────────── */
    let insightsData  = null;
    let insightsChart = null;
    let insightsTypeChartInst = null;
    let currentTab    = 'all';
    window._insightsSortOrders = {};

    const SINCE = '<?= htmlspecialchars($since) ?>';
    const UNTIL = '<?= htmlspecialchars($until) ?>';

    /* ───────────── helpers ───────────── */
    function fmtNum(n) {
        n = Number(n) || 0;
        if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (n >= 1000)    return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
        return n.toLocaleString();
    }
    function fmtTime(sec) {
        sec = Number(sec) || 0;
        if (sec <= 0) return '--';
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = Math.floor(sec % 60);
        if (h > 0) return `${h}h ${m}m`;
        if (m > 0) return `${m}m ${s}s`;
        return `${s}s`;
    }
    function kpiCard(label, value, highlighted, sub, trendVal = null) {
        let trendHtml = '';
        if (trendVal !== null) {
            const isPos = trendVal > 0;
            const isZero = trendVal === 0;
            const color = isPos ? '#10b981' : (isZero ? 'var(--on-surface-variant)' : '#ef4444');
            const icon = isPos ? '↑' : (isZero ? '-' : '↓');
            trendHtml = `<span style="color:${color}; font-size:11px; font-weight:700; margin-left:8px;">${icon} ${Math.abs(trendVal).toFixed(1)}%</span>`;
        }
        
        return `<div class="kpi-card${highlighted ? ' highlighted' : ''}">
            <div class="kpi-label">${label}</div>
            <div class="kpi-value">${value}${trendHtml}</div>
            ${sub ? `<div style="font-size:11px; color:var(--on-surface-variant); font-weight:600; margin-top:4px;">${sub}</div>` : ''}
        </div>`;
    }
    function showSpinner(el) {
        el.innerHTML = `<div class="loader-container" style="padding: 20px 0; display:flex; flex-direction:column; align-items:center; gap:8px;">
            <div class="spinner" style="width: 32px; height: 32px;"></div>
            <span class="loader-text">Loading Insights…</span>
            <div style="font-size: 11px; color: #1877F2; font-weight: 600; display: flex; align-items: center; gap: 4px; opacity: 0.85;">
                <span style="display: inline-block; width: 4px; height: 4px; background-color: #1877F2; border-radius: 50%;"></span>
                Fetching details from Meta...
            </div>
        </div>`;
    }

    /* ───────────── load ───────────── */
    async function loadInsights() {
        const kpiEl = document.getElementById('insights-kpi');
        const chartArea = document.getElementById('insights-chart-area');
        const engArea = document.getElementById('insights-engagement-area');
        const topArea = document.getElementById('insights-top-content-area');
        const detailEl = document.getElementById('insights-detailed-content');
        
        // Show loading circle in KPI
        kpiEl.innerHTML = `<div class="card" style="grid-column: 1 / -1; display:flex; flex-direction:column; justify-content:center; align-items:center; height:120px; gap:4px;">
            <div class="spinner" style="width: 36px; height: 36px; border-width: 3px; border-top-color: var(--primary); border-right-color: transparent; border-bottom-color: transparent; border-left-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
            <div style="margin-top:8px; font-size:13px; color:var(--on-surface-variant); font-weight:600;">Fetching overview metrics...</div>
            <div style="font-size: 11px; color: #1877F2; font-weight: 600; display: flex; align-items: center; gap: 4px; opacity: 0.85;">
                <span style="display: inline-block; width: 5px; height: 5px; background-color: #1877F2; border-radius: 50%;"></span>
                Fetching details from Meta...
            </div>
        </div>`;
        
        // Show chart card with spinner
        chartArea.style.display = 'block';
        chartArea.innerHTML = `<div class="card" style="height:300px; display:flex; flex-direction:column; justify-content:center; align-items:center; gap:4px;">
            <div class="spinner" style="width: 36px; height: 36px; border-width: 3px; border-top-color: var(--primary); border-right-color: transparent; border-bottom-color: transparent; border-left-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
            <div style="margin-top:8px; font-size:13px; color:var(--on-surface-variant); font-weight:600;">Loading chart data...</div>
            <div style="font-size: 11px; color: #1877F2; font-weight: 600; display: flex; align-items: center; gap: 4px; opacity: 0.85;">
                <span style="display: inline-block; width: 5px; height: 5px; background-color: #1877F2; border-radius: 50%;"></span>
                Fetching details from Meta...
            </div>
        </div>`;
        
        // Show engagement card with spinner
        engArea.style.display = 'grid';
        engArea.innerHTML = `
            <div class="card" style="height:250px; display:flex; flex-direction:column; justify-content:center; align-items:center; gap:8px;">
                <div class="spinner" style="width: 36px; height: 36px; border-width: 3px; border-top-color: var(--primary); border-right-color: transparent; border-bottom-color: transparent; border-left-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                <div style="font-size:13px; color:var(--on-surface-variant); font-weight:600;">Loading engagement...</div>
                <div style="font-size: 11px; color: #1877F2; font-weight: 600; display: flex; align-items: center; gap: 4px; opacity: 0.85;">
                    <span style="display: inline-block; width: 5px; height: 5px; background-color: #1877F2; border-radius: 50%;"></span>
                    Fetching details from Meta...
                </div>
            </div>
            <div class="card" style="height:250px; display:flex; flex-direction:column; justify-content:center; align-items:center; gap:8px;">
                <div class="spinner" style="width: 36px; height: 36px; border-width: 3px; border-top-color: var(--primary); border-right-color: transparent; border-bottom-color: transparent; border-left-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                <div style="font-size:13px; color:var(--on-surface-variant); font-weight:600;">Loading breakdown...</div>
                <div style="font-size: 11px; color: #1877F2; font-weight: 600; display: flex; align-items: center; gap: 4px; opacity: 0.85;">
                    <span style="display: inline-block; width: 5px; height: 5px; background-color: #1877F2; border-radius: 50%;"></span>
                    Fetching details from Meta...
                </div>
            </div>
        `;
        
        // Show top content card with spinner
        topArea.style.display = 'block';
        topArea.innerHTML = `<h2 class="section-title" style="font-size: 18px; margin-bottom: 16px;">Top Performing Content</h2>
        <div class="card" style="height:180px; display:flex; flex-direction:column; justify-content:center; align-items:center; gap:4px;">
            <div class="spinner" style="width: 36px; height: 36px; border-width: 3px; border-top-color: var(--primary); border-right-color: transparent; border-bottom-color: transparent; border-left-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
            <div style="margin-top:8px; font-size:13px; color:var(--on-surface-variant); font-weight:600;">Fetching top content...</div>
            <div style="font-size: 11px; color: #1877F2; font-weight: 600; display: flex; align-items: center; gap: 4px; opacity: 0.85;">
                <span style="display: inline-block; width: 5px; height: 5px; background-color: #1877F2; border-radius: 50%;"></span>
                Fetching details from Meta...
            </div>
        </div>`;
        
        // Show detailed content card with spinner
        detailEl.style.display = 'block';
        detailEl.innerHTML = `<h2 class="section-title" style="font-size: 24px; margin-bottom: 16px;">Detailed Content Performance</h2>
        <div class="card" style="height:300px; display:flex; flex-direction:column; justify-content:center; align-items:center; gap:4px;">
            <div class="spinner" style="width: 36px; height: 36px; border-width: 3px; border-top-color: var(--primary); border-right-color: transparent; border-bottom-color: transparent; border-left-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
            <div style="margin-top:8px; font-size:13px; color:var(--on-surface-variant); font-weight:600;">Fetching detailed performance metrics...</div>
            <div style="font-size: 11px; color: #1877F2; font-weight: 600; display: flex; align-items: center; gap: 4px; opacity: 0.85;">
                <span style="display: inline-block; width: 5px; height: 5px; background-color: #1877F2; border-radius: 50%;"></span>
                Fetching details from Meta...
            </div>
        </div>`;

        // Start progress bar (in UI logic context)
        const topBar = document.querySelector('.top-bar');
        let progressBar = document.getElementById('insights-progress');
        if (!progressBar) {
            progressBar = document.createElement('div');
            progressBar.id = 'insights-progress';
            progressBar.style.cssText = 'position:absolute; bottom:0; left:0; height:3px; background:var(--primary); transition: width 0.4s ease, opacity 0.4s; width: 0%; z-index: 100;';
            topBar.style.position = 'relative';
            topBar.appendChild(progressBar);
        }
        progressBar.style.opacity = '1';
        progressBar.style.width = '10%';

        window.insightsKpiData = null;
        window.insightsContentData = null;

        const kpiUrl = `api/fetch_insights_kpi.php?since=${SINCE}&until=${UNTIL}`;
        const contentUrl = `api/fetch_insights_content.php?since=${SINCE}&until=${UNTIL}`;

        try {
            // Fetch KPI (fast)
            fetch(kpiUrl).then(r => r.json()).then(data => {
                if (!document.getElementById('insights-kpi')) return;
                progressBar.style.width = '50%';
                window.insightsKpiData = data;
                
                // Revert chartArea innerHTML to original canvas markup before rendering
                chartArea.innerHTML = `<div class="card" style="padding: 24px; min-width: 0;"><div class="card-label" style="margin-bottom:12px; font-size:12px; font-weight:700; text-transform:uppercase; color:var(--on-surface-variant);">Views Trend</div><div class="chart-wrap" style="height: 250px;"><canvas id="insightsViewsChart"></canvas></div></div>`;
                
                // Render KPI & Chart using temporary structure
                renderPhase1(data);
            }).catch(e => console.error("KPI Error:", e));

            // Fetch Content (slow)
            fetch(contentUrl).then(r => r.json()).then(data => {
                if (!document.getElementById('insights-kpi')) return;
                progressBar.style.width = '100%';
                window.insightsContentData = data;
                
                // Revert engArea, topArea, detailEl innerHTML to original markup
                engArea.innerHTML = `<div class="card" style="padding: 24px; min-width: 0;"><div class="card-label" style="margin-bottom:16px; font-size:12px; font-weight:700; text-transform:uppercase; color:var(--on-surface-variant);">Engagement Analytics</div><div id="engagement-grid" style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px;"></div></div><div class="card" style="padding: 24px; min-width: 0;"><div class="card-label" style="margin-bottom:12px; font-size:12px; font-weight:700; text-transform:uppercase; color:var(--on-surface-variant);">Performance by Type</div><div class="chart-wrap" style="height: 200px;"><canvas id="insightsTypeChart"></canvas></div></div>`;
                topArea.innerHTML = `<h2 class="section-title" style="font-size: 18px; margin-bottom: 16px;">Top Performing Content</h2><div id="top-content-scroll" style="display: flex; overflow-x: auto; gap: 16px; padding-bottom: 8px;"></div>`;
                detailEl.innerHTML = `<h2 id="detailed-content-title" class="section-title" style="font-size: 24px; margin-bottom: 16px; color: #1a4d3e; font-family: Georgia, serif;">Detailed Content Performance</h2><div class="card" style="border-radius: 12px; overflow: hidden; background: #fff; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);"><div class="data-table-wrap" style="overflow-x: auto;"><table class="data-table" style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px;"><thead id="detailed-content-head" style="background: #eef6f3; color: #2d4a40; border-bottom: 1px solid #d5e3dc;"></thead><tbody id="detailed-content-body"></tbody></table></div></div>`;
                
                setTimeout(() => { progressBar.style.opacity = '0'; }, 500);
                
                // Set insightsData to contentData for backward compatibility in filters
                insightsData = data;
                applyInsightsFilters();
            }).catch(e => {
                console.error("Content Error:", e);
                progressBar.style.width = '100%';
                progressBar.style.background = '#ef4444';
            });
            
        } catch (err) {
            console.error(err);
        }
    }
    
    function renderPhase1(kpiData) {
        const kpiEl = document.getElementById('insights-kpi');
        const pm = kpiData.page_metrics || {};
        const prev = kpiData.previous_period || {};
        
        function calcTrend(curr, pre) {
            if (!pre) return null;
            return ((curr - pre) / pre) * 100;
        }

        const vTrend = calcTrend(pm.page_views, prev.page_views); // approx
        const rTrend = calcTrend(pm.reach, prev.reach);
        const intTrend = calcTrend(pm.interactions, prev.interactions);
        const pvTrend = calcTrend(pm.page_views, prev.page_views);

        kpiEl.innerHTML = 
            kpiCard('Page Views',           fmtNum(pm.page_views),             true,  '', pvTrend) +
            kpiCard('Reach',                fmtNum(pm.reach),                  false, '', rTrend) +
            kpiCard('Video Views',          fmtNum(pm.three_second_views),     false, '', null) +
            kpiCard('Content Interactions', fmtNum(pm.interactions),           false, '', intTrend);
            
        // Mock a structure for renderChart so it doesn't crash before content loads
        const mockD = { daily_timeline: kpiData.daily_timeline };
        renderChart('all', mockD);
    }

    /* ───────────── dynamic filter & aggregation switcher ───────────── */
    window.applyInsightsFilters = function() {
        if (!insightsData) return;
        
        const contentVal = document.getElementById('content-filter').value;
        const pageVal = document.getElementById('page-filter').value;
        
        window._insightsSortOrders = {};
        
        // 1. Filter raw content
        let rawContent = insightsData.all_content || insightsData.data?.all?.top_content || [];
        
        let filtered = [...rawContent];
        
        if (pageVal === 'fb') {
            filtered = filtered.filter(item => item.platform === 'facebook');
        } else if (pageVal === 'instagram') {
            filtered = filtered.filter(item => item.platform === 'instagram');
        }
        
        if (contentVal === 'post') {
            filtered = filtered.filter(item => item.type === 'post');
        } else if (contentVal === 'reel') {
            filtered = filtered.filter(item => item.type === 'reel');
        }
        
        // 2. Aggregate metrics dynamically
        const totalViews = filtered.reduce((sum, item) => sum + (Number(item.views) || 0), 0);
        const totalReach = filtered.reduce((sum, item) => sum + (Number(item.reach) || 0), 0);
        const totalInteractions = filtered.reduce((sum, item) => sum + (Number(item.interactions) || 0), 0);
        const totalWatchTime = filtered.reduce((sum, item) => sum + (Number(item.watch_time) || 0), 0);
        const totalLikes = filtered.reduce((sum, item) => sum + (Number(item.likes) || 0), 0);
        const totalComments = filtered.reduce((sum, item) => sum + (Number(item.comments) || 0), 0);
        const totalShares = filtered.reduce((sum, item) => sum + (Number(item.shares) || 0), 0);
        const totalSaves = filtered.reduce((sum, item) => sum + (Number(item.saved) || 0), 0);
        const totalReplies = filtered.reduce((sum, item) => sum + (Number(item.replies) || 0), 0);
        const totalExits = filtered.reduce((sum, item) => sum + (Number(item.exits) || 0), 0);
        const totalTapsForward = filtered.reduce((sum, item) => sum + (Number(item.taps_forward) || 0), 0);
        const totalTapsBack = filtered.reduce((sum, item) => sum + (Number(item.taps_back) || 0), 0);
        const count = filtered.length;
        
        // Build daily timeline from filtered content
        const dailyViews = {};
        filtered.forEach(item => {
            if (!item.timestamp) return;
            const day = new Date(item.timestamp).toISOString().split('T')[0];
            if (!dailyViews[day]) {
                dailyViews[day] = { views: 0, organic: 0, ads: 0 };
            }
            dailyViews[day].views += (Number(item.views) || 0);
            dailyViews[day].organic += (Number(item.views) || 0);
        });
        
        // Heatmap aggregation
        const postingHeatmap = [];
        for (let d = 0; d <= 6; d++) {
            postingHeatmap[d] = [];
            for (let h = 0; h < 24; h++) {
                postingHeatmap[d][h] = { count: 0, views: 0, avg_views: 0 };
            }
        }
        filtered.forEach(item => {
            if (!item.timestamp) return;
            const ts = new Date(item.timestamp);
            const dayOfWeek = ts.getDay(); // 0 (Sun) - 6 (Sat)
            const hourOfDay = ts.getHours(); // 0 - 23
            postingHeatmap[dayOfWeek][hourOfDay].count++;
            postingHeatmap[dayOfWeek][hourOfDay].views += (Number(item.views) || 0);
        });
        for (let d = 0; d <= 6; d++) {
            for (let h = 0; h < 24; h++) {
                if (postingHeatmap[d][h].count > 0) {
                    postingHeatmap[d][h].avg_views = postingHeatmap[d][h].views / postingHeatmap[d][h].count;
                }
            }
        }
        
        // Top performer lists
        const sortedFiltered = [...filtered].sort((a, b) => (Number(b.views) || 0) - (Number(a.views) || 0));
        const topContent = sortedFiltered.slice(0, 50);
        
        // Engagement metrics calculation
        const engagementRate = totalReach > 0 ? (totalInteractions / totalReach) * 100 : 0;
        const avgViews = count > 0 ? totalViews / count : 0;
        const avgLikes = count > 0 ? totalLikes / count : 0;
        const avgComments = count > 0 ? totalComments / count : 0;
        const saveRate = totalReach > 0 ? (totalSaves / totalReach) * 100 : 0;
        const shareRate = totalReach > 0 ? (totalShares / totalReach) * 100 : 0;
        const likeCommentRatio = totalComments > 0 ? totalLikes / totalComments : totalLikes;
        
        const d = {
            total_views: totalViews,
            total_reach: totalReach,
            total_interactions: totalInteractions,
            total_watch_time: totalWatchTime,
            total_likes: totalLikes,
            total_comments: totalComments,
            total_shares: totalShares,
            total_saves: totalSaves,
            total_replies: totalReplies,
            total_exits: totalExits,
            total_taps_forward: totalTapsForward,
            total_taps_back: totalTapsBack,
            content_count: count,
            daily_timeline: dailyViews,
            top_content: topContent,
            engagement_metrics: {
                engagement_rate: Number(engagementRate.toFixed(2)),
                avg_views_per_post: Number(avgViews.toFixed(1)),
                avg_likes_per_post: Number(avgLikes.toFixed(1)),
                avg_comments_per_post: Number(avgComments.toFixed(1)),
                save_rate: Number(saveRate.toFixed(2)),
                share_rate: Number(shareRate.toFixed(2)),
                like_comment_ratio: Number(likeCommentRatio.toFixed(2))
            },
            posting_heatmap: postingHeatmap
        };
        
        renderTab(contentVal, pageVal, d, filtered);
    };

    /* ───────────── render tab ───────────── */
    function renderTab(contentVal, pageVal, d, filteredItems) {
        if (!insightsData) return;

        let pm = window.insightsKpiData?.page_metrics || {};
        let prev = window.insightsKpiData?.previous_period || {};
        
        if (pageVal === 'instagram') {
            pm = { reach: 0, three_second_views: 0, interactions: 0, page_views: 0 };
        }
        
        const kpiEl      = document.getElementById('insights-kpi');
        const chartArea  = document.getElementById('insights-chart-area');
        const detailEl   = document.getElementById('insights-detailed-content');
        
        const engArea    = document.getElementById('insights-engagement-area');
        const topArea    = document.getElementById('insights-top-content-area');
        const heatArea   = document.getElementById('insights-heatmap-area');

        function calcTrend(curr, pre) {
            if (!pre) return null;
            return ((curr - pre) / pre) * 100;
        }

        /* ── KPI cards ── */
        let kpiHTML = '';
        if (contentVal === 'all') {
            const vTrend = calcTrend(d.total_views, prev.views);
            const rTrend = calcTrend(d.total_reach, prev.reach);
            const vvTrend = calcTrend(d.total_watch_time, prev.video_views);
            const intTrend = calcTrend(d.total_interactions, prev.interactions);
            const pvTrend = calcTrend(pm.page_views || 0, prev.page_views);

            kpiHTML =
                kpiCard('Views',                fmtNum(d.total_views),             true,  '', pageVal === 'all' ? vTrend : null) +
                kpiCard('Reach',                fmtNum(pm.reach),                  false, '', pageVal === 'all' ? rTrend : null) +
                kpiCard('Video Views',          fmtNum(pm.three_second_views),     false, '', pageVal === 'all' ? vvTrend : null) +
                kpiCard('Content Interactions', fmtNum(pm.interactions),           false, '', pageVal === 'all' ? intTrend : null) +
                kpiCard('Page Views',           fmtNum(pageVal === 'instagram' ? 0 : (pm.page_views || 0)),        false, '', pageVal === 'all' ? pvTrend : null) +
                kpiCard('Avg Views / Post',     fmtNum(d.engagement_metrics?.avg_views_per_post || 0), false) +
                kpiCard('Engagement Rate',      (d.engagement_metrics?.engagement_rate || 0) + '%', false) +
                kpiCard('Content Published',    d.content_count, false);
                
            chartArea.style.display = 'block';
            engArea.style.display = 'grid';
            topArea.style.display = 'block';
            if (heatArea) heatArea.style.display = 'block';
        } else {
            if (contentVal === 'post') {
                kpiHTML =
                    kpiCard('Views',        fmtNum(d.total_views),        true,  `${d.content_count} posts`) +
                    kpiCard('Reach',        fmtNum(d.total_reach || 0),   false) +
                    kpiCard('Likes',        fmtNum(d.total_likes || 0),   false) +
                    kpiCard('Comments',     fmtNum(d.total_comments || 0),false) +
                    kpiCard('Interactions', fmtNum(d.total_interactions), false);
            } else if (contentVal === 'reel') {
                kpiHTML =
                    kpiCard('Views',        fmtNum(d.total_views || 0),        true,  `${d.content_count} reels`) +
                    kpiCard('Reach',        fmtNum(d.total_reach || 0),        false) +
                    kpiCard('Likes',        fmtNum(d.total_likes || 0),        false) +
                    kpiCard('Comments',     fmtNum(d.total_comments || 0),     false) +
                    kpiCard('Shares',       fmtNum(d.total_shares || 0),       false) +
                    kpiCard('Saves',        fmtNum(d.total_saves || 0),        false) +
                    kpiCard('Interactions', fmtNum(d.total_interactions || 0), false);
            }
            chartArea.style.display = 'block';
            engArea.style.display = 'grid';
            topArea.style.display = 'none';
            if (heatArea) heatArea.style.display = 'block';
        }
        kpiEl.innerHTML = kpiHTML;

        if (contentVal === 'all') {
            renderChart(contentVal, d);
            renderEngagementGrid(d.engagement_metrics, d.content_count);
            renderTypeChart(filteredItems);
            renderTopContent(d.top_content);
            renderHeatmap(d.posting_heatmap);
        } else {
            renderChart(contentVal, d);
            renderEngagementGrid(d.engagement_metrics, d.content_count);
            renderHeatmap(d.posting_heatmap);
        }
        
        renderTable(contentVal, d);
    }

    /* ───────────── chart ───────────── */
    function renderChart(tab, d) {
        if (insightsChart) { insightsChart.destroy(); insightsChart = null; }
        
        const chartCard = document.querySelector('#insights-chart-area .card');
        if (!chartCard.querySelector('canvas')) {
            chartCard.innerHTML = `<div class="card-label" style="margin-bottom:12px; font-size:12px; font-weight:700; text-transform:uppercase; color:var(--on-surface-variant);">Views Trend</div>
            <div class="chart-wrap" style="height: 250px;">
                <canvas id="insightsViewsChart"></canvas>
            </div>`;
        }
        const canvas = document.getElementById('insightsViewsChart');

        const timeline = d.daily_timeline || {};
        const dates    = Object.keys(timeline).sort();

        if (dates.length === 0) {
            chartCard.innerHTML = `<div class="no-activity">
                <div class="na-icon">🔭</div>
                <h3 style="color:var(--text-primary);margin-bottom:0.5rem;">No activity in this date range</h3>
                <p>Try changing the date range above.</p>
            </div>`;
            return;
        }

        const labels    = dates.map(dt => new Date(dt).toLocaleDateString('en-GB', { day:'numeric', month:'short' }));
        const viewsData = dates.map(dt => timeline[dt].views || 0);

        const datasets = [{
            label: 'Total Views',
            data: viewsData,
            borderColor: '#0d9488',
            backgroundColor: 'rgba(13,148,136,0.15)',
            borderWidth: 2,
            tension: 0.35,
            fill: true,
            pointRadius: 0,
            pointHoverRadius: 5
        }];

        if (tab === 'all') {
            const interactionsData = dates.map(dt => timeline[dt].interactions || 0);
            datasets.push({
                label: 'Interactions',
                data: interactionsData,
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99,102,241,0.15)',
                borderWidth: 2,
                borderDash: [4, 4],
                tension: 0.35,
                fill: true,
                pointRadius: 0
            });
        }

        insightsChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, color: '#64748b', font: { size: 12 } } }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { color: '#94a3b8', font: { size: 11 } } },
                    x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 11 }, maxTicksLimit: 10 } }
                }
            }
        });
    }

    /* ───────────── new sections ───────────── */

    function renderEngagementGrid(metrics, contentCount) {
        const grid = document.getElementById('engagement-grid');
        if (!grid || !metrics) return;

        if (contentCount === 0) {
            grid.innerHTML = '<div style="grid-column: 1 / -1; color:var(--on-surface-variant); font-size:13px; text-align:center; padding: 20px;">No content published in this period to calculate averages.</div>';
            return;
        }

        function engCard(title, val, suffix='') {
            return `<div style="background:var(--surface-low); padding:16px; border-radius:var(--radius-md);">
                <div style="font-size:11px; color:var(--on-surface-variant); font-weight:700; text-transform:uppercase; margin-bottom:8px;">${title}</div>
                <div style="font-size:20px; font-weight:800; color:var(--on-surface);">${val}${suffix}</div>
            </div>`;
        }

        grid.innerHTML = 
            engCard('Engagement Rate', metrics.engagement_rate, '%') +
            engCard('Avg Likes/Post', metrics.avg_likes_per_post) +
            engCard('Avg Comments/Post', metrics.avg_comments_per_post) +
            engCard('Like:Comment Ratio', metrics.like_comment_ratio, ':1') +
            engCard('Save Rate', metrics.save_rate, '%') +
            engCard('Share Rate', metrics.share_rate, '%');
    }

    function renderTypeChart(filteredItems) {
        const canvas = document.getElementById('insightsTypeChart');
        if (!canvas) return;
        
        // Recreate canvas to prevent residual chart state
        const chartWrap = canvas.parentNode;
        chartWrap.innerHTML = '<canvas id="insightsTypeChart"></canvas>';
        const newCanvas = document.getElementById('insightsTypeChart');
        
        if (insightsTypeChartInst) { insightsTypeChartInst.destroy(); insightsTypeChartInst = null; }
        
        if (!filteredItems || filteredItems.length === 0) {
            chartWrap.innerHTML = '<div style="color:var(--on-surface-variant); font-size:13px; text-align:center; padding-top: 60px;">No content data to compare.</div>';
            return;
        }

        const typeConfigs = {
            post: { label: 'Posts', color: '#3b82f6' },
            reel: { label: 'Reels', color: '#ec4899' },
            carousel: { label: 'Carousels', color: '#10b981' },
            story: { label: 'Stories', color: '#f59e0b' },
            live: { label: 'Live', color: '#ef4444' }
        };

        const datasets = [];

        Object.keys(typeConfigs).forEach(tKey => {
            const itemsOfType = filteredItems.filter(item => item.type === tKey);
            if (itemsOfType.length > 0) {
                const totalViews = itemsOfType.reduce((sum, item) => sum + (Number(item.views) || 0), 0);
                const totalReach = itemsOfType.reduce((sum, item) => sum + (Number(item.reach) || 0), 0);
                const totalLikes = itemsOfType.reduce((sum, item) => sum + (Number(item.likes) || 0), 0);
                const totalComments = itemsOfType.reduce((sum, item) => sum + (Number(item.comments) || 0), 0);
                const totalSaves = itemsOfType.reduce((sum, item) => sum + (Number(item.saved) || 0), 0);

                datasets.push({
                    label: typeConfigs[tKey].label,
                    data: [totalViews, totalReach, totalLikes, totalComments, totalSaves],
                    backgroundColor: typeConfigs[tKey].color,
                    borderRadius: 4
                });
            }
        });

        if (datasets.length === 0) {
            chartWrap.innerHTML = '<div style="color:var(--on-surface-variant); font-size:13px; text-align:center; padding-top: 60px;">No content type data to compare.</div>';
            return;
        }
        
        insightsTypeChartInst = new Chart(newCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['Views', 'Reach', 'Likes', 'Comments', 'Saves'],
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top', labels: { boxWidth: 10, font: { size: 11 } } } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    function renderTopContent(items) {
        const container = document.getElementById('top-content-scroll');
        if (!container) return;
        
        if (!items || items.length === 0) {
            container.innerHTML = '<div style="color:var(--on-surface-variant); font-size:13px;">No content data.</div>';
            return;
        }

        const topItems = items.slice(0, 5); // top 5
        let html = '';
        
        topItems.forEach(item => {
            const badgeClass = item.platform === 'instagram' ? 'badge-ig' : 'badge-fb';
            const badgeTxt = item.platform === 'instagram' ? 'IG' : 'FB';
            const imgStyle = item.media_url ? `background-image:url(${item.media_url}); background-size:cover; background-position:center;` : 'background:#e2e8f0; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:24px;';
            const imgHtml = item.media_url ? '' : '<span>📷</span>';
            const cap = item.caption ? (item.caption.length > 60 ? item.caption.substring(0, 60)+'...' : item.caption) : '';
            
            html += `<div class="card" style="min-width: 280px; max-width: 280px; flex-shrink: 0; display: flex; flex-direction: column;">
                <div style="height: 160px; width: 100%; border-top-left-radius: var(--radius-lg); border-top-right-radius: var(--radius-lg); ${imgStyle}">${imgHtml}</div>
                <div style="padding: 16px; flex: 1; display: flex; flex-direction: column;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <span class="platform-badge ${badgeClass}" style="margin-left:0;">${badgeTxt} ${item.type.toUpperCase()}</span>
                        <span style="font-size:11px; color:var(--on-surface-variant);">${new Date(item.timestamp).toLocaleDateString('en-GB')}</span>
                    </div>
                    <div style="font-size:12px; color:var(--on-surface); line-height:1.4; margin-bottom:12px; flex:1;">${cap}</div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; border-top:1px solid var(--outline-variant); padding-top:12px;">
                        <div style="font-size:11px; color:var(--on-surface-variant);">Views<br><strong style="color:var(--on-surface); font-size:13px;">${fmtNum(item.views)}</strong></div>
                        <div style="font-size:11px; color:var(--on-surface-variant);">Interactions<br><strong style="color:var(--on-surface); font-size:13px;">${fmtNum(item.interactions)}</strong></div>
                    </div>
                </div>
            </div>`;
        });
        
        container.innerHTML = html;
    }

    function renderHeatmap(heatmapData) {
        const container = document.getElementById('heatmap-container');
        if (!container || !heatmapData) return;
        
        const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        
        let maxViews = 0;
        for(let d=0; d<=6; d++) {
            for(let h=0; h<24; h++) {
                if (heatmapData[d] && heatmapData[d][h] && heatmapData[d][h].avg_views > maxViews) {
                    maxViews = heatmapData[d][h].avg_views;
                }
            }
        }
        
        if (maxViews === 0) {
            container.innerHTML = '<div style="color:var(--on-surface-variant); font-size:13px;">No posting data available to generate heatmap.</div>';
            return;
        }

        let html = '<div style="display:grid; grid-template-columns: 40px repeat(24, minmax(0, 1fr)); gap:2px; min-width: 680px;">';
        
        // Header row (hours)
        html += '<div></div>';
        for(let h=0; h<24; h++) {
            html += `<div style="font-size:9px; color:var(--on-surface-variant); text-align:center;">${h}</div>`;
        }
        
        // Day rows
        for(let d=0; d<=6; d++) {
            html += `<div style="font-size:10px; font-weight:700; color:var(--on-surface-variant); display:flex; align-items:center;">${days[d]}</div>`;
            for(let h=0; h<24; h++) {
                let cell = heatmapData[d] && heatmapData[d][h] ? heatmapData[d][h] : {count:0, avg_views:0};
                let intensity = cell.avg_views / maxViews; // 0 to 1
                
                let bg = '#f1f5f9';
                if (intensity > 0) {
                    // Color scale from light teal to dark teal
                    let alpha = 0.2 + (intensity * 0.8);
                    bg = `rgba(13, 148, 136, ${alpha})`;
                }
                
                let title = `${days[d]} ${h}:00 - ${cell.count} posts, Avg Views: ${Math.round(cell.avg_views)}`;
                
                html += `<div title="${title}" style="aspect-ratio: 1; background-color: ${bg}; border-radius: 2px; cursor: pointer; transition: transform 0.1s;" onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'"></div>`;
            }
        }
        
        html += '</div>';
        container.innerHTML = html;
    }

    /* ───────────── content table ───────────── */
    function renderTable(tab, d) {
        const detailEl = document.getElementById('insights-detailed-content');
        const thead    = document.getElementById('detailed-content-head');
        const tbody    = document.getElementById('detailed-content-body');

        detailEl.style.display = 'block';

        const dateObj = new Date(UNTIL);
        const monthName = dateObj.toLocaleString('en-US', { month: 'long' });
        document.getElementById('detailed-content-title').textContent = `Detailed Content Performance - ${monthName}`;

        let cols = [
            { label:'Date',     key:'timestamp',    type:'str', sortable: true },
            { label:'Type',     key:'platform_type',type:'str', sortable: true },
            { label:'Caption',  key:'caption',      type:'str', sortable: false },
            { label:'Reach',    key:'reach',        type:'num', sortable: true },
            { label:'Views',    key:'views',        type:'num', sortable: true },
            { label:'Likes',    key:'likes',        type:'num', sortable: true },
            { label:'Comments', key:'comments',     type:'num', sortable: true },
            { label:'Shares',   key:'shares',       type:'num', sortable: true },
            { label:'Saves',    key:'saved',        type:'num', sortable: true },
            { label:'Int.',     key:'interactions', type:'num', sortable: true },
            { label:'Link',     key:'link',         type:'str', sortable: false }
        ];

        thead.innerHTML = `<tr>
            ${cols.map((c, i) => `<th ${c.sortable ? `onclick="sortInsightsTable(${i},'${c.type}')"` : ''} class="${c.sortable ? 'ins-th' : ''}" style="padding: 16px; font-weight: 700; white-space: nowrap; ${c.sortable ? 'cursor:pointer;' : ''}">${c.label} ${c.sortable ? '⇅' : ''}</th>`).join('')}
        </tr>`;

        const items = d.top_content || [];
        if (items.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${cols.length}" style="padding:2rem;text-align:center;color:var(--text-secondary);">No content found for this date range.</td></tr>`;
            return;
        }

        tbody.innerHTML = items.map(item => {
            const dateStr = new Date(item.timestamp).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
            
            let badgeBg = '#e2e8f0';
            let badgeColor = '#475569';
            let badgeText = 'POST';
            
            if (item.platform === 'instagram') {
                badgeBg = '#ec4899'; // pink
                badgeColor = '#ffffff';
                badgeText = 'IG ' + (item.type ? item.type.charAt(0).toUpperCase() + item.type.slice(1) : 'Post');
            } else if (item.platform === 'facebook') {
                badgeBg = '#3b82f6'; // blue
                badgeColor = '#ffffff';
                badgeText = 'FB ' + (item.type ? item.type.charAt(0).toUpperCase() + item.type.slice(1) : 'Post');
            }
            
            const badge = `<span style="background:${badgeBg}; color:${badgeColor}; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700; white-space:nowrap;">${badgeText}</span>`;
            
            const caption = item.caption ? (item.caption.length > 35 ? item.caption.substring(0, 35) + '...' : item.caption) : '(No caption)';
            const link    = item.url || item.permalink || '#';

            return `<tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                <td class="ins-td" data-sort="${item.timestamp}" style="padding: 16px; color: #64748b; white-space: nowrap;">${dateStr}</td>
                <td class="ins-td" data-sort="${badgeText}" style="padding: 16px;">${badge}</td>
                <td class="ins-td" data-sort="${caption}" style="padding: 16px; color: #334155; min-width: 200px;">${caption}</td>
                <td class="ins-td" data-sort="${item.reach||0}" style="padding: 16px; color: #475569;">${fmtNum(item.reach||0)}</td>
                <td class="ins-td" data-sort="${item.views||0}" style="padding: 16px; color: #475569;">${fmtNum(item.views||0)}</td>
                <td class="ins-td" data-sort="${item.likes||0}" style="padding: 16px; color: #475569;">${fmtNum(item.likes||0)}</td>
                <td class="ins-td" data-sort="${item.comments||0}" style="padding: 16px; color: #475569;">${fmtNum(item.comments||0)}</td>
                <td class="ins-td" data-sort="${item.shares||0}" style="padding: 16px; color: #475569;">${fmtNum(item.shares||0)}</td>
                <td class="ins-td" data-sort="${item.saved||0}" style="padding: 16px; color: #475569;">${fmtNum(item.saved||0)}</td>
                <td class="ins-td" data-sort="${item.interactions||0}" style="padding: 16px; color: #475569; font-weight: 700;">${fmtNum(item.interactions||0)}</td>
                <td class="ins-td" style="padding: 16px;">
                    <a href="${link}" target="_blank" style="display:inline-flex; align-items:center; gap:4px; padding:6px 12px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; color:#334155; text-decoration:none; font-size:12px; font-weight:600; white-space:nowrap; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                        View <span class="material-symbols-outlined" style="font-size:14px;">open_in_new</span>
                    </a>
                </td>
            </tr>`;
        }).join('');
    }

    /* ───────────── sortInsightsTable (GLOBAL — called by onclick) ───────────── */
    window.sortInsightsTable = function(colIndex, type) {
        const tbody = document.getElementById('detailed-content-body');
        const rows  = Array.from(tbody.querySelectorAll('tr'));
        if (rows.length === 0 || rows[0].children.length <= 1) return;

        window._insightsSortOrders[colIndex] = !window._insightsSortOrders[colIndex];
        const asc = window._insightsSortOrders[colIndex];

        rows.sort((a, b) => {
            const va = a.cells[colIndex] ? a.cells[colIndex].getAttribute('data-sort') : '';
            const vb = b.cells[colIndex] ? b.cells[colIndex].getAttribute('data-sort') : '';
            if (type === 'num') return asc ? (parseFloat(va)||0) - (parseFloat(vb)||0) : (parseFloat(vb)||0) - (parseFloat(va)||0);
            return asc ? String(va).localeCompare(String(vb)) : String(vb).localeCompare(String(va));
        });
        rows.forEach(r => tbody.appendChild(r));
    };

    /* ───────────── kick off ───────────── */
    loadInsights();

})();
</script>

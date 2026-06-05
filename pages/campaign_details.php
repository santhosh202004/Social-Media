<?php
require_once '../includes/auth.php';
if (!isset($_GET['ajax'])) { header("Location: ../dashboard.php?page=campaign_details"); exit; }
/**
 * Campaign Details Page
 * Fetches and displays detailed insights for a specific campaign.
 */
require_once __DIR__ . '/../includes/db_config.php';

$since = $_GET['since'] ?? date('Y-m-d', strtotime('-30 days'));
$until = $_GET['until'] ?? date('Y-m-d');
$campaignId = $_GET['campaign_id'] ?? null;

if (!$campaignId) {
    echo '<div style="padding:2rem;color:#ef4444;">No campaign ID provided. <button onclick="loadPage(\'campaigns\')">Go Back</button></div>';
    exit;
}

// Fetch credentials
$stmt = $pdo->query("SELECT access_token, ad_account_id FROM facebook_config WHERE is_active = 1 LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config || empty($config['access_token'])) {
    echo '<div style="padding:2rem;color:#ef4444;">API not configured. <a href="settings.php">Go to Settings →</a></div>';
    exit;
}

$accessToken = $config['access_token'];

// Build API request for a single campaign
$timeRange = json_encode(['since' => $since, 'until' => $until]);
$insightsField = "insights.time_range({$timeRange})";

$url = "https://graph.facebook.com/v25.0/" . urlencode($campaignId)
     . "?fields=" . urlencode("id,name,status,objective,{$insightsField}{impressions,reach,actions,spend,date_start,date_stop}")
     . "&access_token=" . urlencode($accessToken);

$context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30, 'ignore_errors' => true]]);
$response = @file_get_contents($url, false, $context);
$apiData  = json_decode($response, true);

// Fetch daily insights for the chart
$dailyUrl = "https://graph.facebook.com/v25.0/" . urlencode($campaignId) . "/insights"
     . "?time_increment=1&time_range=" . urlencode($timeRange)
     . "&fields=impressions,reach,date_start"
     . "&limit=100"
     . "&access_token=" . urlencode($accessToken);
$dailyResponse = @file_get_contents($dailyUrl, false, $context);
$dailyApiData  = json_decode($dailyResponse, true);
$dailyInsights = $dailyApiData['data'] ?? [];

// Sort daily insights chronologically (ascending order)
usort($dailyInsights, function($a, $b) {
    return strcmp($a['date_start'], $b['date_start']);
});

$chartLabels = [];
$chartImpressions = [];
$chartReach = [];
foreach ($dailyInsights as $day) {
    $chartLabels[] = date('M j', strtotime($day['date_start']));
    $chartImpressions[] = (int)($day['impressions'] ?? 0);
    $chartReach[] = (int)($day['reach'] ?? 0);
}

if (isset($apiData['error'])) {
    $errMessage = $apiData['error']['message'];
    echo '<script>
        if (window.showGlobalToast) {
            window.showGlobalToast(' . json_encode($errMessage) . ', "error", "Configure", "?page=settings");
        }
    </script>';
    echo '<div style="padding:1.5rem 2rem;background:var(--surface-low);border:1px solid var(--outline-variant);border-radius:12px;color:var(--on-surface-variant);margin-bottom:2rem;font-weight:600;text-align:center;">
        API connection error occurred. Please check settings.
    </div>';
    exit;
}

if (!isset($apiData['id'])) {
    echo '<div style="padding:3rem;text-align:center;color:var(--text-secondary);">
        <h3 style="margin-bottom:0.5rem;color:var(--text-primary);">Campaign not found</h3>
        <button class="filter-btn active" onclick="loadPage(\'campaigns\')">Back to Campaigns</button>
    </div>';
    exit;
}

$c = $apiData;
$ins = $c['insights']['data'][0] ?? null;

// Determine primary result metric
$resultsValue = 0;
$resultsLabel = 'Results';
$objective = $c['objective'] ?? '';
$actions   = $ins['actions'] ?? [];
$mappings  = [
    'LEAD'        => ['lead', 'onsite_conversion.lead_grouped', 'lead_generation_tax'],
    'TRAFFIC'     => ['link_click', 'post_engagement'],
    'LINK_CLICKS' => ['link_click'],
    'ENGAGEMENT'  => ['post_engagement', 'post_reaction', 'page_like'],
    'SALES'       => ['purchase', 'offsite_conversion.fb_pixel_purchase'],
    'CONVERSIONS' => ['offsite_conversion.fb_pixel_purchase', 'purchase']
];
foreach ($mappings as $key => $types) {
    if (strpos($objective, $key) !== false) {
        $bestType = null;
        foreach ($types as $t) {
            foreach ($actions as $a) {
                if ($a['action_type'] === $t) {
                    $bestType = $t;
                    break 2;
                }
            }
        }
        if ($bestType) {
            foreach ($actions as $a) {
                if ($a['action_type'] === $bestType) {
                    $resultsValue = (int)$a['value'];
                    $parts = explode('.', $bestType);
                    $resultsLabel = strtoupper(str_replace('_', ' ', end($parts)));
                    break 2;
                }
            }
        }
    }
}
if (!$resultsValue && count($actions) > 0) {
    $resultsValue = (int)$actions[0]['value'];
    $parts = explode('.', $actions[0]['action_type']);
    $resultsLabel = strtoupper(str_replace('_', ' ', end($parts)));
}
?>

<div class="section-header" style="align-items: flex-start; margin-bottom: 32px;">
    <div style="flex: 1;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
            <div class="badge badge-blue"><?= htmlspecialchars(str_replace('OUTCOME_', '', $objective)) ?></div>
            <div style="width: 4px; height: 4px; border-radius: 50%; background: var(--outline-variant);"></div>
            <span class="mono" style="font-size: 12px; font-weight: 700; color: var(--on-surface-variant); opacity: 0.8;">ID: <?= htmlspecialchars($c['id']) ?></span>
        </div>
        <h1 class="section-title" style="font-size: 32px; letter-spacing: -0.02em; margin-bottom: 8px;"><?= htmlspecialchars($c['name']) ?></h1>
        <?php if ($ins): ?>
            <p class="section-subtitle" style="display: flex; align-items: center; gap: 6px;">
                <!-- <span class="material-symbols-outlined" style="font-size: 16px; color: var(--primary);">calendar_today</span> -->
                Performance Period: <strong><?= htmlspecialchars($ins['date_start']) ?></strong> to <strong><?= htmlspecialchars($ins['date_stop']) ?></strong>
            </p>
        <?php endif; ?>
    </div>
    <button class="btn btn-outline" onclick="loadPage('campaigns')" style="border-radius: var(--radius-full); padding: 10px 20px;">
        <span class="material-symbols-outlined" style="font-size: 20px;">arrow_back</span>
        Back to Campaigns
    </button>
</div>
<!-- <div style="text-align: right; margin-bottom: 24px;">
    <button id="toggle-financials-btn" class="btn btn-outline btn-sm" onclick="toggleFinancials()" style="border-radius: var(--radius-md);">
        <span class="material-symbols-outlined" style="font-size: 16px;">visibility</span>              
    </button>
</div> -->
<?php if ($ins): ?>
    <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 16px;">
        <div class="kpi-card primary">
            <div class="kpi-label">Total <?= htmlspecialchars($resultsLabel) ?></div>
            <div class="kpi-value"><?= number_format($resultsValue) ?></div>
            <div class="kpi-badge neutral" style="margin-top: 4px;">Primary Metric</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Total Impressions</div>
            <div class="kpi-value"><?= number_format($ins['impressions'] ?? 0) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Engagement Reach</div>
            <div class="kpi-value"><?= number_format($ins['reach'] ?? 0) ?></div>
        </div>
        <div class="kpi-card" id="card-cpr" style="display: none;">
            <div class="kpi-label">Cost per Result</div>
            <div style="display: flex; flex-direction: column; gap: 4px;">
                <div class="kpi-value">₹<?= ($resultsValue > 0 && isset($ins['spend'])) ? number_format($ins['spend'] / $resultsValue, 2) : '0.00' ?></div>
                <?php if (strpos($objective, 'LEAD') !== false): ?>
                    <div style="font-size: 13px; color: var(--on-surface-variant); font-weight: 500;">Per lead (form)</div>
                <?php else: ?>
                    <div style="font-size: 13px; color: var(--on-surface-variant); font-weight: 500;">Per <?= htmlspecialchars(strtolower($resultsLabel)) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="kpi-card" id="card-spend" style="display: none;">
            <div class="kpi-label">Amount Spent</div>
            <div class="kpi-value">₹<?= isset($ins['spend']) ? number_format($ins['spend'], 2) : '0.00' ?></div>
        </div>
    </div>

    

    <script>
    function toggleFinancials() {
        const cpr = document.getElementById('card-cpr');
        const spend = document.getElementById('card-spend');
        const btn = document.getElementById('toggle-financials-btn');
        if (cpr.style.display === 'none') {
            cpr.style.display = ''; 
            spend.style.display = '';
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 16px;">visibility_off</span> Hide Financial Details';
        } else {
            cpr.style.display = 'none';
            spend.style.display = 'none';
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 16px;">visibility</span> Show Financial Details';
        }
    }
    </script>

    <div class="card" style="padding: 32px; margin-top: 24px;">
        <h3 class="card-title" style="margin-bottom: 24px;">Efficiency Performance Timeline</h3>
        <div class="chart-wrap">
            <canvas id="performanceChart" height="120"></canvas>
        </div>
    </div>
<?php else: ?>
    <div class="welcome-screen">
        <div class="logo-icon" style="margin-bottom:2rem;width:64px;height:64px;"></div>
        <h2 style="font-size:1.8rem;color:var(--text-primary);">No insights for this period</h2>
        <p style="color:var(--text-secondary);max-width:400px;margin:1rem auto;">
            Try a broader date range or ensure the campaign was active during that time.
        </p>
    </div>
<?php endif; ?>

<!-- Lead Details Section (Only for Lead Campaigns) -->
<?php if (strpos($objective, 'LEAD') !== false): ?>
<div class="card" style="padding: 32px; margin-top: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 class="card-title" style="margin-bottom: 4px;">Individual Lead Data</h2>
            <p class="section-subtitle">Detailed breakdown of the most recent leads acquired.</p>
        </div>
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <span id="leads-source-status" style="font-size: 13px; font-weight: 600; display: none;"></span>
            <div id="leads-count-badge" class="badge badge-blue" style="display: none;"></div>
            <button id="download-leads-btn" class="btn btn-outline btn-sm" style="display: none;">
                <span class="material-symbols-outlined" style="font-size: 16px;">download</span>
                Export CSV
            </button>
            <button id="load-leads-btn" class="btn btn-primary btn-sm">
                <span class="material-symbols-outlined" style="font-size: 16px;">database</span>
                Load Lead Details
            </button>
            <button id="sync-leads-btn" class="btn btn-outline btn-sm" style="display: none;">
                <span class="material-symbols-outlined" style="font-size: 16px;">sync</span>
                Sync from Meta
            </button>
        </div>
    </div>
    <div id="leads-content" style="min-height: 240px; display: flex; align-items: center; justify-content: center;">
        <div class="no-activity">
            <span class="material-symbols-outlined na-icon" style="font-size: 64px;">analytics</span>
            <p>Individual lead data is not loaded by default. Click <strong>"Load Lead Details"</strong> to fetch data instantly from your local database.</p>
        </div>
    </div>
</div>

<script>
// Init chart if insights exist
<?php if ($ins): ?>
    (function() {
        const ctx = document.getElementById('performanceChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [
                    {
                        label: 'Total Impressions',
                        data: <?= json_encode($chartImpressions) ?>,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37,99,235,0.08)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Engagement Reach',
                        data: <?= json_encode($chartReach) ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16,185,129,0.08)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#64748b' } } },
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    y: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { color: '#64748b' } },
                    x: { grid: { display: false }, ticks: { color: '#64748b' } }
                }
            }
        });
    })();
<?php endif; ?>

// Load leads button
document.getElementById('load-leads-btn').addEventListener('click', () => fetchLeadDetails('<?= htmlspecialchars($campaignId) ?>', '<?= htmlspecialchars($since) ?>', '<?= htmlspecialchars($until) ?>', false));

// Sync leads button
document.getElementById('sync-leads-btn').addEventListener('click', () => fetchLeadDetails('<?= htmlspecialchars($campaignId) ?>', '<?= htmlspecialchars($since) ?>', '<?= htmlspecialchars($until) ?>', true));

async function fetchLeadDetails(campaignId, since, until, forceSync = false) {
    const leadsContent = document.getElementById('leads-content');
    const loadBtn      = document.getElementById('load-leads-btn');
    const syncBtn      = document.getElementById('sync-leads-btn');
    const downloadBtn  = document.getElementById('download-leads-btn');
    const badge        = document.getElementById('leads-count-badge');
    const statusSpan   = document.getElementById('leads-source-status');

    loadBtn.disabled = true;
    syncBtn.disabled = true;
    
    if (forceSync) {
        syncBtn.innerHTML = `<span class="spinner" style="width:16px;height:16px;border-width:2px;border-color:var(--primary) transparent transparent transparent;"></span> Syncing...`;
    } else {
        loadBtn.innerHTML = `<span class="spinner" style="width:16px;height:16px;border-width:2px;"></span> Loading...`;
    }

    downloadBtn.style.display = 'none';
    badge.style.display = 'none';
    statusSpan.style.display = 'none';

    const loaderText = forceSync ? 'Syncing fresh leads from Meta...' : 'Retrieving leads from database...';
    const subText = forceSync ? 'Connecting to Meta Graph API...' : 'Querying local database cache...';

    leadsContent.innerHTML = `
        <div class="loader-container" style="display:flex; flex-direction:column; align-items:center; gap:8px;">
            <div class="spinner"></div>
            <span class="loader-text" style="margin-top:8px;">${loaderText}</span>
            <div style="font-size: 12px; color: #1877F2; font-weight: 600; display: flex; align-items: center; gap: 6px; opacity: 0.85;">
                <span style="display: inline-block; width: 6px; height: 6px; background-color: #1877F2; border-radius: 50%;"></span>
                ${subText}
            </div>
        </div>`;

    try {
        let url = `api/fetch_leads.php?campaign_id=${campaignId}`;
        if (since) url += `&since=${since}`;
        if (until) url += `&until=${until}`;
        if (forceSync) url += `&sync=1`;

        const res   = await fetch(url);
        const responseData = await res.json();

        if (responseData.error) {
            if (window.showGlobalToast) {
                window.showGlobalToast(responseData.error, 'error');
            }
            leadsContent.innerHTML = `<div style="color:var(--on-surface-variant);padding:2rem;text-align:center;">Failed to fetch secure lead details. Check API setup.</div>`;
            loadBtn.disabled = false;
            syncBtn.disabled = false;
            loadBtn.innerHTML = `<span class="material-symbols-outlined" style="font-size: 16px;">database</span> Reload DB Cache`;
            syncBtn.innerHTML = `<span class="material-symbols-outlined" style="font-size: 16px;">sync</span> Sync from Meta`;
            return;
        }

        const leadsData = responseData.leads ?? responseData;
        const totalInDb = responseData.total_in_db ?? leadsData.length;
        const source    = responseData.source ?? 'meta';

        // Auto-sync logic if DB is empty on first load
        if (!forceSync && totalInDb === 0) {
            return fetchLeadDetails(campaignId, since, until, true);
        }

        if (leadsData.length === 0) {
            leadsContent.innerHTML = '<p style="color:var(--text-secondary);text-align:center;padding:2rem;">No individual lead data found for this campaign in the selected date range.</p>';
            syncBtn.style.display = 'inline-flex';
        } else {
            // Deduplicate leads by ID (safety net)
            const uniqueMap = new Map();
            leadsData.forEach(l => { if (!uniqueMap.has(l.id)) uniqueMap.set(l.id, l); });
            const uniqueLeads = Array.from(uniqueMap.values());

            badge.textContent = `${uniqueLeads.length} Leads`;
            if (totalInDb > uniqueLeads.length) {
                badge.textContent += ` (${totalInDb} in DB)`;
            }
            
            badge.style.display = 'inline-flex';
            downloadBtn.style.display = 'inline-flex';
            downloadBtn.onclick = () => downloadLeadsAsCSV(uniqueLeads, `leads_${campaignId}.csv`);

            // Always display the Sync button once loaded
            syncBtn.style.display = 'inline-flex';

            // Show loaded source status
            statusSpan.style.display = 'inline-block';
            if (source === 'meta') {
                statusSpan.innerHTML = `<span style="display:inline-flex; align-items:center; gap:4px; color:#10b981;"><span class="material-symbols-outlined" style="font-size:16px;">check_circle</span> Synced from Meta</span>`;
            } else {
                statusSpan.innerHTML = `<span style="display:inline-flex; align-items:center; gap:4px; color:var(--primary);"><span class="material-symbols-outlined" style="font-size:16px;">database</span> Loaded from Cache</span>`;
            }

            const excluded       = ['id', 'created_time', 'ad_name', 'form_name'];
            const dynamicHeaders = Object.keys(uniqueLeads[0]).filter(k => !excluded.includes(k));

            let tableHTML = `<div class="data-table-wrap"><table class="data-table">
                <thead><tr>
                    <th style="width: 60px; text-align: center;">S.No</th>
                    <th>Date</th>
                    ${dynamicHeaders.map(h => `<th>${h.replace(/_/g,' ')}</th>`).join('')}
                    <th>Form Name</th>
                    <th>Ad Name</th>
                </tr></thead><tbody>`;

            uniqueLeads.forEach((lead, index) => {
                const date = new Date(lead.created_time).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'});
                tableHTML += `<tr>
                    <td class="mono" style="text-align: center; color: var(--on-surface-variant); font-weight: 600;">${index + 1}</td>
                    <td class="mono" style="font-weight:700;">${date}</td>
                    ${dynamicHeaders.map(h => `<td>${lead[h] || 'N/A'}</td>`).join('')}
                    <td style="color:var(--on-surface-variant);">${lead.form_name || 'N/A'}</td>
                    <td style="color:var(--on-surface-variant);">${lead.ad_name}</td>
                </tr>`;
            });
            tableHTML += '</tbody></table></div>';
            leadsContent.innerHTML = tableHTML;
        }
    } catch (err) {
        if (window.showGlobalToast) {
            window.showGlobalToast(err.message || 'Failed to load leads', 'error');
        }
        leadsContent.innerHTML = '<div style="color:var(--on-surface-variant);padding:2rem;text-align:center;">Failed to load leads.</div>';
    }
    loadBtn.disabled = false;
    syncBtn.disabled = false;
    loadBtn.innerHTML = `<span class="material-symbols-outlined" style="font-size: 16px;">database</span> Reload DB Cache`;
    syncBtn.innerHTML = `<span class="material-symbols-outlined" style="font-size: 16px;">sync</span> Sync from Meta`;
}

function downloadLeadsAsCSV(data, filename) {
    if (!data || !data.length) return;
    const headers = Object.keys(data[0]);
    const rows    = [headers.map(h => `"${h}"`).join(',')];
    data.forEach(row => rows.push(headers.map(h => `"${(row[h]||'').toString().replace(/"/g,'""')}"`).join(',')));
    const blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href     = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
}
</script>
<?php endif; ?>


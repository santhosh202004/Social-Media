<?php
require_once '../includes/auth.php';
/**
 * Campaigns (Ads) Page
 * Fetches campaign list from Facebook Ads API and renders campaign cards
 * with Total Leads, Impressions, and Engagement Reach per the selected date range.
 */
if (!isset($_GET['ajax'])) {
    header("Location: ../dashboard.php?page=campaigns");
    exit;
}

require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/SimpleCache.php';

$since = $_GET['since'] ?? date('Y-m-d', strtotime('-30 days'));
$until = $_GET['until'] ?? date('Y-m-d');

// Check API data cache
$cacheKey = 'campaigns_list_' . $since . '_' . $until;

// Fetch credentials
$stmt = $pdo->query("SELECT access_token, ad_account_id FROM facebook_config WHERE is_active = 1 LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config || empty($config['access_token'])) {
    echo '<div style="padding:2rem;color:#ef4444;">API not configured. <a href="dashboard.php?page=settings">Go to Settings →</a></div>';
    exit;
}

$accessToken = $config['access_token'];
$adAccountId = 'act_' . preg_replace('/[^0-9]/', '', $config['ad_account_id']);

// Try to get API data from cache
$apiDataCacheKey = 'api_campaigns_data_' . $adAccountId . '_' . $since . '_' . $until;
$apiData = SimpleCache::get($apiDataCacheKey);

if (!$apiData || isset($_GET['nocache'])) {
    $effectiveStatus = json_encode(['ACTIVE', 'PAUSED']);
    $timeRange = json_encode(['since' => $since, 'until' => $until]);
    $insightsField = "insights.time_range({$timeRange})";

    $url = "https://graph.facebook.com/v25.0/" . urlencode($adAccountId) . "/campaigns"
         . "?effective_status=" . urlencode($effectiveStatus)
         . "&fields=" . urlencode("id,name,status,objective,{$insightsField}{impressions,reach,actions,date_start,date_stop}")
         . "&access_token=" . urlencode($accessToken)
         . "&limit=100";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => "",
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $apiData  = $response ? json_decode($response, true) : null;
    
    if ($apiData && !isset($apiData['error'])) {
        SimpleCache::set($apiDataCacheKey, $apiData, 600); // 10 minutes cache
    }
}

$campaigns = [];
if (isset($apiData['data'])) {
    foreach ($apiData['data'] as $c) {
        $campaign = [
            'id'        => $c['id'],
            'name'      => $c['name'],
            'status'    => $c['status'],
            'objective' => $c['objective'] ?? 'N/A',
            'insights'  => $c['insights']['data'][0] ?? null,
        ];
        $campaigns[] = $campaign;
    }
}

$apiError = isset($apiData['error']) ? $apiData['error']['message'] : null;
?>

<div class="section-header">
    <div>
        <div class="badge badge-blue" style="margin-bottom: 8px;">ADS MANAGER</div>
        <h1 class="section-title">All Campaigns</h1>
        <p class="section-subtitle">
            Showing results from <strong><?= htmlspecialchars($since) ?></strong> to <strong><?= htmlspecialchars($until) ?></strong>.
        </p>
    </div>

    <!-- Status filter dropdown -->
    <div>
        <select class="form-control" style="font-size: 13px; font-weight: 700; padding: 8px 16px; border-radius: var(--radius-md); width: auto;" onchange="filterCampaigns(this.value)">
            <option value="ALL">All Status</option>
            <option value="ACTIVE">Active</option>
            <option value="PAUSED">Paused</option>
        </select>
    </div>
</div>

<?php if ($apiError): ?>
<script>
    if (window.showGlobalToast) {
        window.showGlobalToast(<?= json_encode($apiError) ?>, 'error', 'Configure', '?page=settings');
    }
</script>
<div style="padding:1.5rem 2rem;background:var(--surface-low);border:1px solid var(--outline-variant);border-radius:12px;color:var(--on-surface-variant);margin-bottom:2rem;font-weight:600;text-align:center;">
    API connection error occurred. Please check settings.
</div>
<?php endif; ?>

<?php if (empty($campaigns)): ?>
<div class="no-activity" style="margin-top: 64px;">
    <span class="material-symbols-outlined na-icon">ads_click</span>
    <h3 class="section-title" style="font-size: 20px; margin-bottom: 8px;">No Campaigns Found</h3>
    <p>No campaigns found for this date range. Try selecting a wider range or verify your Ad Account ID in <a href="dashboard.php?page=settings" style="color: var(--primary); font-weight: 700;">Settings</a>.</p>
</div>
<?php else: ?>

<div id="campaign-list" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(400px, 1fr)); gap:32px; margin-top:24px;">
<?php foreach ($campaigns as $c):
    $ins = $c['insights'];
    $totalLeads       = 0;
    $totalImpressions = $ins['impressions'] ?? 0;
    $engagementReach  = $ins['reach']       ?? 0;

    if ($ins && isset($ins['actions'])) {
        foreach ($ins['actions'] as $action) {
            if (in_array($action['action_type'], ['lead', 'onsite_conversion.lead_grouped', 'lead_generation_tax'])) {
                $totalLeads += (int)$action['value'];
                break;
            }
        }
    }

    $objectiveLabel = str_replace('OUTCOME_', '', $c['objective']);
    $statusClass    = $c['status'] === 'ACTIVE' ? 'green' : 'yellow';
?>
    <div class="card campaign-card" 
        data-status="<?= htmlspecialchars($c['status']) ?>"
        data-campaign="<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>"
        style="padding: 32px; cursor: pointer; display: flex; flex-direction: column; gap: 20px; position: relative; overflow: hidden;">
        
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="badge badge-<?= $c['status'] === 'ACTIVE' ? 'green' : 'yellow' ?>">
                <div class="badge-dot"></div>
                <?= htmlspecialchars($c['status']) ?>
            </div>
            <div style="font-size: 11px; font-weight: 700; color: var(--on-surface-variant); text-transform: uppercase; letter-spacing: 0.05em;">
                <?= htmlspecialchars($objectiveLabel) ?>
            </div>
        </div>

        <div class="card-title" style="font-size: 18px; line-height: 1.4; flex-grow: 1; font-weight: 800;">
            <?= htmlspecialchars($c['name']) ?>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; border-top: 1px solid var(--outline-variant); padding-top: 16px;">
            <div style="text-align: center;">
                <div style="font-size: 11px; font-weight: 700; color: var(--on-surface-variant); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 6px;">Leads</div>
                <div class="mono" style="font-size: 22px; font-weight: 800; color: var(--primary);"><?= number_format($totalLeads) ?></div>
            </div>
            <div style="text-align: center; border-left: 1px solid var(--outline-variant); border-right: 1px solid var(--outline-variant);">
                <div style="font-size: 11px; font-weight: 700; color: var(--on-surface-variant); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 6px;">Impressions</div>
                <div class="mono" style="font-size: 22px; font-weight: 800; color: var(--on-surface);"><?= number_format($totalImpressions) ?></div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 11px; font-weight: 700; color: var(--on-surface-variant); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 6px;">Reach</div>
                <div class="mono" style="font-size: 22px; font-weight: 800; color: var(--on-surface);"><?= number_format($engagementReach) ?></div>
            </div>
        </div>

        <div class="btn btn-outline btn-sm" style="width: 100%; justify-content: center; font-weight: 700; margin-top: 8px;">
            View Analytics <span class="material-symbols-outlined" style="font-size: 16px; margin-left: 4px;">analytics</span>
        </div>
    </div>
<?php endforeach; ?>
</div>

<script>
// ---- Filter Buttons ----
function filterCampaigns(status) {
    document.querySelectorAll('#campaign-list .campaign-card').forEach(card => {
        card.style.display = (status === 'ALL' || card.dataset.status === status) ? '' : 'none';
    });
}

// ---- Campaign Card Click → Detail View ----
document.querySelectorAll('#campaign-list .campaign-card').forEach(card => {
    card.addEventListener('click', () => {
        const campaign = JSON.parse(card.dataset.campaign);
        loadPage('campaign_details', { campaign_id: campaign.id });
    });
});
</script>
<?php endif; ?>


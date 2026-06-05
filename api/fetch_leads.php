<?php
/**
 * Fetch Leads Controller
 * Retrieves individual lead data for a specific Facebook Campaign.
 * 
 * New Architecture:
 * - Load from DB reads ALL leads for campaign, filters by date in PHP.
 * - Sync from Meta fetches ALL leads (paginated), UPSERTs to DB, filters by date in PHP.
 * 
 * Called via AJAX: fetch_leads.php?campaign_id=<ID>&since=<Y-m-d>&until=<Y-m-d>&sync=1
 */

header('Content-Type: application/json');

// --- Configuration & Security ---
require_once '../includes/auth.php';
require_once '../includes/db_config.php';

$stmt = $pdo->query("SELECT access_token, page_access_token, ad_account_id, page_id FROM facebook_config WHERE is_active = 1 LIMIT 1");
$config = $stmt->fetch();

if (!$config || empty($config['access_token'])) {
    echo json_encode(['error' => 'API not configured. Please set up your credentials in Settings.']);
    exit;
}

$accessToken = $config['access_token'];
$pageId      = $config['page_id'] ?? '';
$campaignId  = $_GET['campaign_id'] ?? '';
$since       = $_GET['since'] ?? '';
$until       = $_GET['until'] ?? '';

$debugMode = isset($_GET['debug']);
$debugLog  = [];

if (empty($campaignId)) {
    echo json_encode(['error' => 'No campaign_id provided.']);
    exit;
}

$forceSync = isset($_GET['sync']) && $_GET['sync'] == '1';

// ============================================================
// Helper Functions
// ============================================================

/**
 * Fetch ALL pages from a paginated Facebook Graph API endpoint.
 * Follows paging.next cursors until all data is collected.
 */
function fbApiGetAllPages($url, &$debugLog = []) {
    $allData = [];
    $nextUrl = $url;
    $pageCount = 0;

    while ($nextUrl && $pageCount < 50) {  // safety cap: max 50 pages
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 30, 'ignore_errors' => true]
        ]);
        $response = @file_get_contents($nextUrl, false, $context);

        if ($response === false) {
            $debugLog[] = ['step' => 'network_error', 'url' => preg_replace('/access_token=[^&]+/', 'access_token=REDACTED', $nextUrl)];
            break;
        }

        $data = json_decode($response, true);
        if (isset($data['error'])) {
            $debugLog[] = ['step' => 'api_error', 'error' => $data['error']];
            break;
        }

        if (!empty($data['data'])) {
            $allData = array_merge($allData, $data['data']);
        }

        $nextUrl = $data['paging']['next'] ?? null;
        $pageCount++;
    }
    
    $debugLog[] = [
        'step' => 'fetch_paginated',
        'base_url' => preg_replace('/access_token=[^&]+/', 'access_token=REDACTED', explode('?', $url)[0]),
        'pages_fetched' => $pageCount,
        'records_fetched' => count($allData)
    ];

    return $allData;
}

function formatLeadsForOutput(array $dbRows): array {
    $formatted = [];
    foreach ($dbRows as $row) {
        $entry = [
            'id'           => $row['id'],
            'created_time' => $row['created_time'],
            'ad_name'      => $row['ad_name'],
            'form_name'    => $row['form_name'] ?? ''
        ];
        $fieldData = json_decode($row['field_data'], true);
        if (is_array($fieldData)) {
            foreach ($fieldData as $k => $v) {
                $entry[$k] = $v;
            }
        }
        $formatted[] = $entry;
    }
    return $formatted;
}

function filterByDateRange(array $leads, string $since, string $until): array {
    $filtered = [];
    // Convert ranges to UTC timestamps for safe comparison
    $sinceTs = !empty($since) ? strtotime($since . ' 00:00:00 +00:00') : null;
    $untilTs = !empty($until) ? strtotime($until . ' 23:59:59 +00:00') : null;

    foreach ($leads as $lead) {
        $leadTs = strtotime($lead['created_time']);
        
        if ($sinceTs && $leadTs < $sinceTs) continue;
        if ($untilTs && $leadTs > $untilTs) continue;
        
        $filtered[] = $lead;
    }
    
    // Sort by created_time descending (most recent first)
    usort($filtered, function ($a, $b) {
        return strtotime($b['created_time']) - strtotime($a['created_time']);
    });
    
    return $filtered;
}

function upsertLeadsToDb(PDO $pdo, array $dbRows, &$debugLog) {
    if (empty($dbRows)) return 0;
    
    try {
        $pdo->beginTransaction();
        $upsertStmt = $pdo->prepare("INSERT INTO leads (id, campaign_id, ad_id, ad_name, form_id, form_name, created_time, field_data) 
                                     VALUES (:id, :campaign_id, :ad_id, :ad_name, :form_id, :form_name, :created_time, :field_data)
                                     ON DUPLICATE KEY UPDATE 
                                         ad_id = VALUES(ad_id), 
                                         ad_name = VALUES(ad_name), 
                                         form_id = VALUES(form_id), 
                                         form_name = VALUES(form_name),
                                         created_time = VALUES(created_time), 
                                         field_data = VALUES(field_data)");
        
        $count = 0;
        foreach ($dbRows as $row) {
            if (!array_key_exists('form_name', $row)) $row['form_name'] = '';
            $upsertStmt->execute($row);
            $count++;
        }
        $pdo->commit();
        $debugLog[] = ['step' => 'db_save_success', 'count' => $count];
        return $count;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $debugLog[] = ['step' => 'db_save_error', 'message' => $e->getMessage()];
        return 0;
    }
}

// ============================================================
// DB Cache Path
// ============================================================
if (!$forceSync) {
    // Load ALL leads for this campaign from DB
    $dbStmt = $pdo->prepare(
        "SELECT id, created_time, ad_name, form_name, field_data 
         FROM leads 
         WHERE campaign_id = :cid 
         ORDER BY created_time DESC"
    );
    $dbStmt->execute([':cid' => $campaignId]);
    $allDbLeads = $dbStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($allDbLeads)) {
        $formatted = formatLeadsForOutput($allDbLeads);
        $filtered  = filterByDateRange($formatted, $since, $until);
        
        $response = [
            'source'       => 'database',
            'total_in_db'  => count($formatted),
            'leads'        => $filtered,
        ];
        
        if ($debugMode) {
            $response['debug'] = true;
            $response['campaign_id'] = $campaignId;
        }
        
        echo json_encode($response);
        exit;
    }
    // If empty, fall through to sync
}

// ============================================================
// META SYNC PATH
// ============================================================
$pageAccessToken = !empty($config['page_access_token']) ? $config['page_access_token'] : $accessToken;

// Step 1: Fetch ALL Ads (paginated)
$adsUrl = "https://graph.facebook.com/v19.0/" . urlencode($campaignId) . "/ads"
    . "?fields=" . urlencode("id,name,creative{id}")
    . "&access_token=" . urlencode($accessToken)
    . "&limit=100";
$ads = fbApiGetAllPages($adsUrl, $debugLog);
$adIds = array_column($ads, 'id');

// Step 2: Fetch ALL Lead Forms (paginated)
$formIds = [];
$formToAdName = [];
$formToFormName = [];

if (!empty($pageId)) {
    $pageFormsUrl = "https://graph.facebook.com/v19.0/" . urlencode($pageId) . "/leadgen_forms"
        . "?fields=" . urlencode("id,name,status")
        . "&access_token=" . urlencode($pageAccessToken)
        . "&limit=100";
    
    $pageForms = fbApiGetAllPages($pageFormsUrl, $debugLog);
    foreach ($pageForms as $form) {
        $formIds[] = $form['id'];
        $formToAdName[$form['id']] = 'Unknown Form'; // Default
        $formToFormName[$form['id']] = $form['name'] ?? '';
    }
}

// Fallback to ad creative if no forms found via Page
if (empty($formIds) && !empty($ads)) {
    foreach ($ads as $ad) {
        $creativeUrl = "https://graph.facebook.com/v19.0/" . urlencode($ad['id'])
            . "?fields=" . urlencode("creative{object_story_spec,asset_feed_spec}")
            . "&access_token=" . urlencode($accessToken);

        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 15, 'ignore_errors' => true]]);
        $creativeRes = @file_get_contents($creativeUrl, false, $context);
        if ($creativeRes) {
            $creativeResult = json_decode($creativeRes, true);
            $fid = null;
            $assetFeed = $creativeResult['creative']['asset_feed_spec'] ?? [];
            if (isset($assetFeed['call_to_actions']) && is_array($assetFeed['call_to_actions'])) {
                foreach ($assetFeed['call_to_actions'] as $cta) {
                    if (isset($cta['value']['lead_gen_form_id'])) {
                        $fid = $cta['value']['lead_gen_form_id'];
                        break;
                    }
                }
            }
            if (!$fid && isset($creativeResult['creative']['object_story_spec']['link_data']['call_to_action']['value']['lead_gen_form_id'])) {
                $fid = $creativeResult['creative']['object_story_spec']['link_data']['call_to_action']['value']['lead_gen_form_id'];
            }

            if ($fid && !in_array($fid, $formIds)) {
                $formIds[] = $fid;
                $formToAdName[$fid] = $ad['name'] ?? 'Unknown Ad';
                $formToFormName[$fid] = '';
            }
        }
    }
}

// Step 3: Fetch ALL Leads from each form (paginated, no date filter)
$allLeads = [];
$seenLeadIds = [];
$dbRows = [];

if (!empty($formIds)) {
    foreach ($formIds as $formId) {
        $leadsUrl = "https://graph.facebook.com/v19.0/" . urlencode($formId) . "/leads"
            . "?fields=" . urlencode("id,created_time,field_data,ad_id,ad_name,campaign_id,campaign_name")
            . "&access_token=" . urlencode($pageAccessToken)
            . "&limit=100";

        $formLeads = fbApiGetAllPages($leadsUrl, $debugLog);
        
        foreach ($formLeads as $lead) {
            // Deduplicate
            if (isset($seenLeadIds[$lead['id']])) continue;
            $seenLeadIds[$lead['id']] = true;

            // Filter: only include leads that belong to THIS campaign
            $leadCampaignId = $lead['campaign_id'] ?? '';
            if (!empty($leadCampaignId) && $leadCampaignId !== $campaignId) continue;
            
            if (empty($leadCampaignId) && !empty($adIds)) {
                $leadAdId = $lead['ad_id'] ?? '';
                if (!empty($leadAdId) && !in_array($leadAdId, $adIds)) continue;
            }

            $entry = [
                'id'           => $lead['id'],
                'created_time' => $lead['created_time'],
                'ad_name'      => $lead['ad_name'] ?? $formToAdName[$formId] ?? 'N/A',
                'form_name'    => $formToFormName[$formId] ?? ''
            ];

            // Flatten field_data
            $dynamicFields = [];
            if (isset($lead['field_data'])) {
                foreach ($lead['field_data'] as $field) {
                    $key = strtolower(str_replace(' ', '_', $field['name']));
                    $val = implode(', ', $field['values']);
                    $entry[$key] = $val;
                    $dynamicFields[$key] = $val;
                }
            }

            $allLeads[] = $entry;

            // Prepare row for DB
            $dbRows[] = [
                'id'            => $lead['id'],
                'campaign_id'   => $campaignId,
                'ad_id'         => $lead['ad_id'] ?? '',
                'ad_name'       => $entry['ad_name'],
                'form_id'       => $formId,
                'form_name'     => $formToFormName[$formId] ?? '',
                'created_time'  => date('Y-m-d H:i:s', strtotime($lead['created_time'])),
                'field_data'    => json_encode($dynamicFields)
            ];
        }
    }
}

// Step 4: UPSERT ALL leads to DB (no date filter — store everything)
$dbSavedCount = upsertLeadsToDb($pdo, $dbRows, $debugLog);

// Step 5: Apply date filter ONLY for the response
$filtered = filterByDateRange($allLeads, $since, $until);

$response = [
    'source'       => 'meta',
    'total_in_db'  => count($allLeads),
    'leads'        => $filtered,
];

if ($debugMode) {
    $response['debug'] = true;
    $response['campaign_id'] = $campaignId;
    $response['ads_found'] = count($ads);
    $response['forms_found'] = count($formIds);
    $response['db_saved_count'] = $dbSavedCount;
    $response['log'] = $debugLog;
}

echo json_encode($response);
?>

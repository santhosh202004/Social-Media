<?php
require_once '../includes/auth.php';
if (!isset($_GET['ajax'])) {
    header('Location: ../dashboard.php?page=reports');
    exit;
}

// Get the current active account to display name
require_once '../includes/db_config.php';
$stmt = $pdo->query("SELECT account_name, access_token FROM facebook_config WHERE is_active = 1 LIMIT 1");
$account = $stmt->fetch(PDO::FETCH_ASSOC);
$accountName = $account ? htmlspecialchars($account['account_name']) : 'Selected Account';
$hasToken = $account && !empty($account['access_token']);
?>

<div class="section-header">
    <div>
        <div class="badge badge-blue" style="margin-bottom: 8px;">REPORTING</div>
        <h1 class="section-title">Report Generator</h1>
        <p class="section-subtitle">Generate presentation-style monthly reports for <strong><?= $accountName ?></strong>.</p>
    </div>
</div>

<div class="card" style="padding: 40px; max-width: 500px; margin: 40px auto; text-align: center;">
    
    <div style="margin-bottom: 32px;">
        <div style="width: 64px; height: 64px; background: var(--primary-fixed); color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
            <span class="material-symbols-outlined" style="font-size: 32px;">picture_as_pdf</span>
        </div>
        <h2 class="card-title" style="font-size: 18px;">Monthly Performance Report</h2>
        <p class="section-subtitle" style="margin-top: 8px;">Select a month to generate a comprehensive digital marketing report matching your corporate identity.</p>
    </div>

    <form id="generate-report-form" action="reports/report_template.php" target="_blank" method="GET">
        <div style="margin-bottom: 24px; text-align: left;">
            <label for="report-month" class="form-label">Select Month & Year</label>
            <input type="month" id="report-month" name="month" required class="form-control" style="font-size: 14px; padding: 12px;">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; justify-content: center; font-size: 14px;">
            <span class="material-symbols-outlined">print</span>
            Generate & View Report
        </button>
    </form>
</div>

<script>
    // Set default month to current month
    const today = new Date();
    const month = (today.getMonth() + 1).toString().padStart(2, '0');
    const year = today.getFullYear();
    document.getElementById('report-month').value = `${year}-${month}`;

    // Active account and token validation check
    (async function() {
        const hasToken = <?= $hasToken ? 'true' : 'false' ?>;
        if (!hasToken) {
            if (window.showGlobalToast) {
                window.showGlobalToast('No active account configured. Please go to Settings to add/select an account.', 'warning', 'Configure', '?page=settings');
            }
            return;
        }

        try {
            // Fetch overview with minimal date range to perform a lightweight connection check
            const checkUrl = `api/fetch_overview.php?since=${year}-${month}-01&until=${year}-${month}-01`;
            const res = await fetch(checkUrl);
            const data = await res.json();
            if (data && data.error) {
                if (window.showGlobalToast) {
                    window.showGlobalToast(data.message || 'API error occurred. Please check settings.', 'error', 'Configure', '?page=settings');
                }
            }
        } catch (err) {
            if (window.showGlobalToast) {
                window.showGlobalToast(err.message || 'API connection error occurred.', 'error', 'Configure', '?page=settings');
            }
        }
    })();
</script>

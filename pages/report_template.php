<?php
require_once '../includes/auth.php';
// Report Template for PDF Generation/Viewing
$monthParam = $_GET['month'] ?? date('Y-m');
$timestamp = strtotime($monthParam . '-01');
$monthName = date('F Y', $timestamp);
$monthNameOnly = date('F', $timestamp);

$since = date('Y-m-01', $timestamp);
$until = date('Y-m-t', $timestamp);

// Fetch data from local API endpoints
$baseUrl = "http://" . $_SERVER['HTTP_HOST'] . "/add_campign_test/api";

function fetchApiData($url) {
    $cookies = [];
    foreach ($_COOKIE as $name => $value) {
        $cookies[] = "$name=$value";
    }
    if (session_id()) {
        $cookies[] = session_name() . '=' . session_id();
    }
    $cookieHeader = implode('; ', array_unique($cookies));
    
    $context = stream_context_create([
        'http' => [
            'header' => "Cookie: " . $cookieHeader . "\r\n",
            'ignore_errors' => true
        ]
    ]);
    $res = @file_get_contents($url, false, $context);
    return $res ? json_decode($res, true) : null;
}

$overview = fetchApiData("$baseUrl/fetch_overview.php?since=$since&until=$until");
$audience = fetchApiData("$baseUrl/fetch_audience.php?since=$since&until=$until");
// Demographics is lifetime
$demographics = fetchApiData("$baseUrl/fetch_demographics.php");

// Prepare data
$totalViews = $overview['overview']['total_views'] ?? 0;
$accountsReached = $overview['overview']['accounts_reached'] ?? 0;
$nonFollowerPct = $overview['overview']['non_follower_views_pct'] ?? 0;
$profileVisits = $overview['overview']['profile_visits'] ?? 0;
$websiteClicks = $overview['overview']['website_clicks'] ?? 0;

$totalFollowersLife = $overview['total']['followers'] ?? 0;
$fbFollowersLife = $overview['facebook']['followers'] ?? 0;
$igFollowersLife = $overview['instagram']['followers'] ?? 0;

$totalFollowersNew = $overview['total']['new_followers'] ?? 0;
$fbFollowersNew = $overview['facebook']['new_followers'] ?? 0;
$igFollowersNew = $overview['instagram']['new_followers'] ?? 0;

$igFollowers = $audience['instagram']['followers'] ?? 0;
$igAges = $audience['instagram']['age_breakdown'] ?? [];
$igGenders = $audience['instagram']['gender_breakdown'] ?? [];
$igCities = $audience['instagram']['cities'] ?? [];
$igCountries = $audience['instagram']['countries'] ?? [];

$igContentFollowers = $audience['instagram']['content_consumption']['followers'] ?? [];
$igContentNonFollowers = $audience['instagram']['content_consumption']['non_followers'] ?? [];

$fbPostsCount = $overview['content_inventory']['fb_posts'] ?? 0;
$fbVideosCount = $overview['content_inventory']['fb_videos'] ?? 0;
$igPostsCount = $overview['content_inventory']['ig_posts'] ?? 0;
$igReelsCount = $overview['content_inventory']['ig_reels'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report - <?= $monthName ?></title>
    <link rel="icon" type="image/png" href="../assets/images/fav.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../assets/css/reports.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
</head>
<body>

    <div class="action-bar no-print">
        <button class="btn btn-close" onclick="window.close()">
            <span class="material-icons-round">close</span>
            Close
        </button>
        <button class="btn btn-download" onclick="window.print()">
            <span class="material-icons-round">file_download</span>
            Download PDF
        </button>
    </div>

    <!-- PAGE 1: Cover -->
    <div class="page" style="justify-content: center;">
        <div style="background: rgba(255,255,255,0.5); border-radius: 20px; padding: 40px; text-align: left; max-width: 100%;">
            <h1 class="title">DPS Vijayapura</h1>
            <h2 class="subtitle">Digital Marketing Report — <?= $monthName ?></h2>
            <p style="font-size: 1.1rem; color: var(--text-muted); max-width: 600px;">
                A comprehensive overview of Instagram and Facebook performance, audience insights,results for the month of <?= $monthNameOnly ?>.
            </p>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE 2: Reach at a Glance -->
    <div class="page">
        <!-- <span class="badge">ACCOUNT OVERVIEW</span> -->
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;"><?= $monthNameOnly ?> Reach at a Glance</h1>
        
        <div class="card-grid">
            <div class="card" style="background: transparent; border: none; padding: 0;">
                <div class="metric-value"><?= number_format($totalViews) ?></div>
                <div class="metric-label">Total Views</div>
            </div>
            <div class="card" style="background: transparent; border: none; padding: 0;">
                <div class="metric-value"><?= number_format($accountsReached) ?></div>
                <div class="metric-label">Accounts Reached</div>
            </div>
            <div class="card" style="background: transparent; border: none; padding: 0;">
                <div class="metric-value"><?= $nonFollowerPct ?>%</div>
                <div class="metric-label">Non-Follower Views</div>
            </div>
            <div class="card" style="background: transparent; border: none; padding: 0;">
                <div class="metric-value"><?= number_format($profileVisits) ?></div>
                <div class="metric-label">Profile Activity</div>
            </div>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE 3: Audience Growth -->
    <div class="page">
        <!-- <span class="badge">AUDIENCE OVERVIEW</span> -->
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">Follower Growth</h1>
        
        <div style="margin-bottom: 30px;">
            <h2 style="font-size: 1.8rem; margin-bottom: 20px;">Lifetime Followers</h2>
            <div class="card-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                    <div class="metric-value" style="font-size: 2.5rem;"><?= number_format($totalFollowersLife) ?></div>
                    <div class="metric-label">Total Followers</div>
                </div>
                <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                    <div class="metric-value" style="font-size: 2.5rem;"><?= number_format($igFollowersLife) ?></div>
                    <div class="metric-label">Instagram</div>
                </div>
                <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                    <div class="metric-value" style="font-size: 2.5rem;"><?= number_format($fbFollowersLife) ?></div>
                    <div class="metric-label">Facebook</div>
                </div>
            </div>
        </div>

        <div>
            <h2 style="font-size: 1.8rem; margin-bottom: 20px;">New Followers (<?= $monthNameOnly ?>)</h2>
            <div class="card-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                    <div class="metric-value" style="font-size: 2.5rem; color: var(--accent-green);">+<?= number_format($totalFollowersNew) ?></div>
                    <div class="metric-label">Total New</div>
                </div>
                <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                    <div class="metric-value" style="font-size: 2.5rem; color: var(--accent-green);">+<?= number_format($igFollowersNew) ?></div>
                    <div class="metric-label">Instagram</div>
                </div>
                <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                    <div class="metric-value" style="font-size: 2.5rem; color: var(--accent-green);">+<?= number_format($fbFollowersNew) ?></div>
                    <div class="metric-label">Facebook</div>
                </div>
            </div>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE: Content Inventory -->
    <div class="page">
        <!-- <span class="badge">CONTENT INVENTORY</span> -->
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">Content Inventory (<?= $monthNameOnly ?>)</h1>
        
        <div style="margin-bottom: 30px;">
            <h2 style="font-size: 1.8rem; margin-bottom: 20px;">Facebook Content</h2>
            <div class="card-grid" style="grid-template-columns: 1fr 1fr;">
                <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                    <div class="metric-value" style="font-size: 3rem;"><?= $fbPostsCount ?></div>
                    <div class="metric-label">Posts Published</div>
                </div>
                <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                    <div class="metric-value" style="font-size: 3rem;"><?= $fbVideosCount ?></div>
                    <div class="metric-label">Videos Published</div>
                </div>
            </div>
        </div>

        <div>
            <h2 style="font-size: 1.8rem; margin-bottom: 20px;">Instagram Content</h2>
            <div class="card-grid" style="grid-template-columns: 1fr 1fr;">
                <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                    <div class="metric-value" style="font-size: 3rem;"><?= $igPostsCount ?></div>
                    <div class="metric-label">Posts Published</div>
                    <div class="metric-sub">(Images/Carousels)</div>
                </div>
                <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                    <div class="metric-value" style="font-size: 3rem;"><?= $igReelsCount ?></div>
                    <div class="metric-label">Reels Published</div>
                </div>
            </div>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE 4: What Audiences Watched -->
    <div class="page">
        <!-- <span class="badge">CONTENT CONSUMPTION</span> -->
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">What Audiences Watched</h1>
        
        <div class="card-grid">
            <div>
                <h3 style="font-size: 1.5rem; margin-bottom: 20px;">Followers</h3>
                <div class="chart-container">
                    <canvas id="chart-followers"></canvas>
                </div>
            </div>
            <div>
                <h3 style="font-size: 1.5rem; margin-bottom: 20px;">Non-Followers</h3>
                <div class="chart-container">
                    <canvas id="chart-nonfollowers"></canvas>
                </div>
            </div>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE 4: Profile Engagement -->
    <div class="page">
        <!-- <span class="badge">PROFILE ACTIVITY</span> -->
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">Profile Engagement — <?= $monthNameOnly ?></h1>
        
        <div class="card-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="card">
                <div style="font-size: 1.5rem; font-family: 'Lora'; margin-bottom: 10px;">Profile Visits</div>
                <div style="font-size: 1.2rem; font-weight: 600; color: var(--text-dark);">
                    <?= number_format($profileVisits) ?> <span style="font-weight: 400; color: var(--text-muted); font-size: 1rem;">visits</span>
                </div>
            </div>
            <div class="card">
                <div style="font-size: 1.5rem; font-family: 'Lora'; margin-bottom: 10px;">External Link Taps</div>
                <div style="font-size: 1.2rem; font-weight: 600; color: var(--text-dark);">
                    <?= number_format($websiteClicks) ?> <span style="font-weight: 400; color: var(--text-muted); font-size: 1rem;">taps</span>
                </div>
            </div>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE 5: IG Demographics -->
    <div class="page">
        <!-- <span class="badge">INSTAGRAM AUDIENCE</span> -->
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">Instagram Follower Demographics</h1>
        
        <div style="display: flex; gap: 40px;">
            <div style="flex: 2;">
                <div class="chart-container" style="height: 400px;">
                    <canvas id="chart-demographics"></canvas>
                </div>
            </div>
            
            </div>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE 6: Location Demographics -->
    <div class="page">
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">Audience Locations</h1>
        
        <div class="card-grid" style="grid-template-columns: 1fr 1fr; gap: 40px;">
            <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                <h3 style="font-size: 1.8rem; margin-bottom: 20px;">Top Cities</h3>
                <div class="chart-container" style="height: 350px;">
                    <canvas id="chart-cities"></canvas>
                </div>
            </div>
            <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                <h3 style="font-size: 1.8rem; margin-bottom: 20px;">Top Countries</h3>
                <div class="chart-container" style="height: 350px;">
                    <canvas id="chart-countries"></canvas>
                </div>
            </div>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- Script for Charts -->
    <script>
        // Set defaults
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#123e2b';

        // 1. What Audiences Watched (Followers)
        const fTypes = <?= json_encode(array_keys($igContentFollowers)) ?>;
        const fPcts = <?= json_encode(array_column($igContentFollowers, 'pct')) ?>;
        
        new Chart(document.getElementById('chart-followers'), {
            type: 'bar',
            data: {
                labels: fTypes,
                datasets: [{
                    data: fPcts,
                    backgroundColor: '#10b981',
                    borderRadius: 4,
                    barThickness: 30
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { borderDash: [4, 4] }, max: 100 },
                    y: { grid: { display: false } }
                }
            }
        });

        // 2. What Audiences Watched (Non-Followers)
        const nfTypes = <?= json_encode(array_keys($igContentNonFollowers)) ?>;
        const nfPcts = <?= json_encode(array_column($igContentNonFollowers, 'pct')) ?>;

        new Chart(document.getElementById('chart-nonfollowers'), {
            type: 'bar',
            data: {
                labels: nfTypes,
                datasets: [{
                    data: nfPcts,
                    backgroundColor: '#10b981',
                    borderRadius: 4,
                    barThickness: 30
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { borderDash: [4, 4] }, max: 100 },
                    y: { grid: { display: false } }
                }
            }
        });

        // 3. Demographics
        const ages = <?= json_encode(array_keys($igAges)) ?>;
        const ageVals = <?= json_encode(array_values($igAges)) ?>;

        new Chart(document.getElementById('chart-demographics'), {
            type: 'bar',
            data: {
                labels: ages,
                datasets: [{
                    label: 'Followers',
                    data: ageVals,
                    backgroundColor: '#10b981',
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { borderDash: [4, 4] } }
                }
            }
        });

        // 4. Top Cities
        const cityLabels = <?= json_encode(array_keys(array_slice($igCities, 0, 5, true))) ?>;
        const cityData = <?= json_encode(array_values(array_slice($igCities, 0, 5, true))) ?>;

        new Chart(document.getElementById('chart-cities'), {
            type: 'bar',
            data: {
                labels: cityLabels,
                datasets: [{
                    data: cityData,
                    backgroundColor: '#10b981',
                    borderRadius: 4,
                    barThickness: 25
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { borderDash: [4, 4] } },
                    y: { grid: { display: false } }
                }
            }
        });

        // 5. Top Countries
        const countryLabels = <?= json_encode(array_keys(array_slice($igCountries, 0, 5, true))) ?>;
        const countryData = <?= json_encode(array_values(array_slice($igCountries, 0, 5, true))) ?>;

        new Chart(document.getElementById('chart-countries'), {
            type: 'bar',
            data: {
                labels: countryLabels,
                datasets: [{
                    data: countryData,
                    backgroundColor: '#10b981',
                    borderRadius: 4,
                    barThickness: 25
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { borderDash: [4, 4] } },
                    y: { grid: { display: false } }
                }
            }
        });
        
        // Auto print prompt after a small delay
        setTimeout(() => {
            // window.print();
        }, 1000);
    </script>
</body>
</html>

<?php
require_once '../includes/auth.php';
// Report Template for PDF Generation/Viewing
$monthParam = $_GET['month'] ?? date('Y-m');
$timestamp = strtotime($monthParam . '-01');
$monthName = date('F Y', $timestamp);
$monthNameOnly = date('F', $timestamp);

$since = date('Y-m-01', $timestamp);
$until = date('Y-m-t', $timestamp);

// Fetch account name from DB
require_once '../includes/db_config.php';
$stmt = $pdo->query("SELECT account_name FROM facebook_config WHERE is_active = 1 LIMIT 1");
$accInfo = $stmt->fetch();
$account_name = $accInfo['account_name'] ?? 'Social Media Report';

// Close session write lock so that our local API calls below don't deadlock
session_write_close();

// Fetch data from local API endpoints
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$basePath = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'])));
if ($basePath === '/') {
    $basePath = '';
}
$baseUrl = "{$protocol}://{$host}{$basePath}/api";

function fetchApiData($url)
{
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

$contentData = fetchApiData("$baseUrl/fetch_content.php?since=$since&until=$until");
$postDetails = [];
if (is_array($contentData)) {
    foreach ($contentData as $item) {
        $postDetails[] = [
            'date_raw' => $item['timestamp'], // For sorting
            'date' => date('M j, Y', strtotime($item['timestamp'])),
            'type' => ($item['platform'] === 'instagram' ? 'IG ' : 'FB ') . $item['display_type'],
            'caption' => $item['caption'] ?? '',
            'reach' => $item['reach'] ?? 0,
            'interactions' => $item['total_engagement'] ?? 0,
            'views' => $item['views'] ?? 0,
            'likes' => $item['likes'] ?? 0,
            'comments' => $item['comments'] ?? 0,
            'shares' => $item['shares'] ?? 0,
            'saves' => $item['saves'] ?? 0,
            'permalink' => $item['url'] ?? '#'
        ];
    }
}

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

$igDailyFollows = $audience['instagram']['daily_follows'] ?? [];
$fbDailyFollows = $audience['facebook']['daily_follows'] ?? [];

$netGrowthDates = [];
$igNetGrowth = [];
$fbNetGrowth = [];

$startTs = strtotime($since);
$endTs = strtotime($until);
for ($t = $startTs; $t <= $endTs; $t += 86400) {
    $dateStr = date('Y-m-d', $t);
    $netGrowthDates[] = date('M j', $t);

    $igNet = 0;
    foreach ($igDailyFollows as $igD) {
        if ($igD['date'] === $dateStr) {
            $igNet = $igD['value'] ?? ($igD['follows'] - $igD['unfollows']);
            break;
        }
    }
    $igNetGrowth[] = $igNet;

    $fbNet = 0;
    foreach ($fbDailyFollows as $fbD) {
        if ($fbD['date'] === $dateStr) {
            $fbNet = ($fbD['follows'] ?? 0) - ($fbD['unfollows'] ?? 0);
            break;
        }
    }
    $fbNetGrowth[] = $fbNet;
}
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
    <link
        href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
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
        <div
            style="background: rgba(255,255,255,0.5); border-radius: 20px; padding: 40px; text-align: left; max-width: 100%;">
            <h1 class="title"><?= $account_name ?></h1>
            <h2 class="subtitle">Digital Marketing Report — <?= $monthName ?></h2>
            <p style="font-size: 1.1rem; color: var(--text-muted); max-width: 600px;">
                A comprehensive overview of Instagram and Facebook performance, audience insights, and boosted ad
                results for the month of <?= $monthNameOnly ?>.
            </p>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE 2: Reach at a Glance -->
    <div class="page">
        <!-- <span class="badge">ACCOUNT OVERVIEW</span> -->
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;"> Reach at a Glance - <?= $monthNameOnly ?>
        </h1>

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
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">Follower Growth <?= $monthNameOnly ?></h1>

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

        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE 4: Daily Net Growth -->
    <!-- <div class="page">
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">Daily Net Growth</h1>
        <div class="card" style="background: transparent; border: 1px solid var(--card-border); padding: 20px; flex: 1; display: flex; flex-direction: column; justify-content: center;">
            <div class="chart-container" style="height: 450px; width: 100%;">
                <canvas id="chart-net-growth"></canvas>
            </div>
            <p style="margin-top: 20px; color: var(--text-muted); font-size: 0.95rem; text-align: center;">
                This chart tracks the daily net fluctuation of followers across platforms throughout <?= $monthNameOnly ?>.
            </p>
        </div>
    </div> -->

    <!-- PAGE: Content Inventory -->
    <div class="page">
        <!-- <span class="badge">CONTENT INVENTORY</span> -->
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">Content Inventory - <?= $monthNameOnly ?></h1>

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
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">What Audiences Watched - <?= $monthNameOnly ?>
        </h1>

        <div class="card-grid">
            <div>
                <h3 style="font-size: 1.5rem; margin-bottom: 20px;">Followers</h3>
                <div class="chart-container" style="height: 250px;">
                    <canvas id="chart-followers"></canvas>
                </div>
                <div style="margin-top: 30px;">
                    <?php foreach ($igContentFollowers as $type => $data): ?>
                        <div
                            style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(0,0,0,0.05); padding: 5px 0;">
                            <span style="font-weight: 500; font-size: 0.95rem;"><?= htmlspecialchars($type) ?></span>
                            <span style="color: var(--text-muted); font-size: 0.95rem;"><?= $data['pct'] ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <h3 style="font-size: 1.5rem; margin-bottom: 20px;">Non-Followers</h3>
                <div class="chart-container" style="height: 250px;">
                    <canvas id="chart-nonfollowers"></canvas>
                </div>
                <div style="margin-top: 30px;">
                    <?php foreach ($igContentNonFollowers as $type => $data): ?>
                        <div
                            style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(0,0,0,0.05); padding: 5px 0;">
                            <span style="font-weight: 500; font-size: 0.95rem;"><?= htmlspecialchars($type) ?></span>
                            <span style="color: var(--text-muted); font-size: 0.95rem;"><?= $data['pct'] ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE 4: Profile Engagement -->
    <div class="page">
        <!-- <span class="badge">PROFILE ACTIVITY</span> -->
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">Profile Engagement — <?= $monthNameOnly ?>
        </h1>

        <div class="card-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="card">
                <div style="font-size: 1.5rem; font-family: 'Lora'; margin-bottom: 10px;">Profile Visits</div>
                <div style="font-size: 1.2rem; font-weight: 600; color: var(--text-dark);">
                    <?= number_format($profileVisits) ?> <span
                        style="font-weight: 400; color: var(--text-muted); font-size: 1rem;">visits</span>
                </div>
            </div>
            <div class="card">
                <div style="font-size: 1.5rem; font-family: 'Lora'; margin-bottom: 10px;">External Link Taps</div>
                <div style="font-size: 1.2rem; font-weight: 600; color: var(--text-dark);">
                    <?= number_format($websiteClicks) ?> <span
                        style="font-weight: 400; color: var(--text-muted); font-size: 1rem;">taps</span>
                </div>
            </div>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE 5: IG Demographics -->
    <div class="page">
        <!-- <span class="badge">INSTAGRAM AUDIENCE</span> -->
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">Instagram Follower Demographics -
            <?= $monthNameOnly ?></h1>

        <div style="display: flex; gap: 40px;">
            <div style="flex: 2;">
                <div class="chart-container" style="height: 300px;">
                    <canvas id="chart-demographics"></canvas>
                </div>
                <div style="margin-top: 25px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                    <?php foreach ($igAges as $age => $val): ?>
                        <div
                            style="display: flex; justify-content: space-between; background: rgba(255,255,255,0.4); padding: 10px 15px; border-radius: 8px; border: 1px solid var(--card-border);">
                            <span style="font-weight: 600;"><?= htmlspecialchars($age) ?></span>
                            <span style="color: var(--text-muted); font-weight: 500;"><?= number_format($val) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="flex: 1;">
                <h3 style="font-size: 1.5rem; margin-bottom: 20px;">Gender</h3>
                <div
                    style="background: transparent; border: 1px solid var(--card-border); border-radius: 12px; padding: 25px;">
                    <?php
                    $totalGender = array_sum($igGenders);
                    $male = $igGenders['M'] ?? $igGenders['MALE'] ?? 0;
                    $female = $igGenders['F'] ?? $igGenders['FEMALE'] ?? 0;
                    $others = $igGenders['U'] ?? $igGenders['UNKNOWN'] ?? 0;

                    $malePct = $totalGender > 0 ? round(($male / $totalGender) * 100, 1) : 0;
                    $femalePct = $totalGender > 0 ? round(($female / $totalGender) * 100, 1) : 0;
                    $othersPct = $totalGender > 0 ? round(($others / $totalGender) * 100, 1) : 0;
                    ?>

                    <div style="margin-bottom: 25px;">
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="font-weight: 600; font-size: 1.1rem;">Female</span>
                            <span style="font-size: 1.2rem; font-weight: 700; color: #ec4899;"><?= $femalePct ?>%</span>
                        </div>
                        <div style="background: rgba(0,0,0,0.05); height: 12px; border-radius: 6px; overflow: hidden;">
                            <div
                                style="width: <?= $femalePct ?>%; background: #ec4899; height: 100%; border-radius: 6px;">
                            </div>
                        </div>
                        <div style="font-size: 0.9rem; color: var(--text-muted); margin-top: 5px;">
                            <?= number_format($female) ?> followers</div>
                    </div>

                    <div style="margin-bottom: 25px;">
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="font-weight: 600; font-size: 1.1rem;">Male</span>
                            <span style="font-size: 1.2rem; font-weight: 700; color: #3b82f6;"><?= $malePct ?>%</span>
                        </div>
                        <div style="background: rgba(0,0,0,0.05); height: 12px; border-radius: 6px; overflow: hidden;">
                            <div
                                style="width: <?= $malePct ?>%; background: #3b82f6; height: 100%; border-radius: 6px;">
                            </div>
                        </div>
                        <div style="font-size: 0.9rem; color: var(--text-muted); margin-top: 5px;">
                            <?= number_format($male) ?> followers</div>
                    </div>

                    <?php if ($others > 0): ?>
                    <div>
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="font-weight: 600; font-size: 1.1rem;">Others</span>
                            <span style="font-size: 1.2rem; font-weight: 700; color: #8b5cf6;"><?= $othersPct ?>%</span>
                        </div>
                        <div style="background: rgba(0,0,0,0.05); height: 12px; border-radius: 6px; overflow: hidden;">
                            <div
                                style="width: <?= $othersPct ?>%; background: #8b5cf6; height: 100%; border-radius: 6px;">
                            </div>
                        </div>
                        <div style="font-size: 0.9rem; color: var(--text-muted); margin-top: 5px;">
                            <?= number_format($others) ?> followers</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE 6: Location Demographics -->
    <div class="page">
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">Audience Locations -<?= $monthNameOnly ?></h1>

        <div class="card-grid" style="grid-template-columns: 1fr 1fr; gap: 40px;">
            <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                <h3 style="font-size: 1.8rem; margin-bottom: 20px;">Top Cities</h3>
                <div class="chart-container" style="height: 220px;">
                    <canvas id="chart-cities"></canvas>
                </div>
                <div style="margin-top: 30px;">
                    <?php
                    if (!empty($igCities)) {
                        $totalCity = array_sum($igCities);
                        $top5Cities = array_slice($igCities, 0, 5, true);
                        foreach ($top5Cities as $city => $val) {
                            $pct = $totalCity > 0 ? round(($val / $totalCity) * 100, 1) : 0;
                            echo '<div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid rgba(0,0,0,0.05);">';
                            echo '  <div style="font-size: 0.95rem; font-weight: 500;">' . htmlspecialchars($city) . '</div>';
                            echo '  <div style="font-size: 0.9rem; color: var(--text-muted);">' . number_format($val) . ' (' . $pct . '%)</div>';
                            echo '</div>';
                        }
                    }
                    ?>
                </div>
            </div>
            <div class="card" style="background: transparent; border: 1px solid var(--card-border);">
                <h3 style="font-size: 1.8rem; margin-bottom: 20px;">Top Countries</h3>
                <div class="chart-container" style="height: 220px;">
                    <canvas id="chart-countries"></canvas>
                </div>
                <div style="margin-top: 30px;">
                    <?php
                    if (!empty($igCountries)) {
                        $totalCountry = array_sum($igCountries);
                        $top5Countries = array_slice($igCountries, 0, 5, true);
                        foreach ($top5Countries as $country => $val) {
                            $pct = $totalCountry > 0 ? round(($val / $totalCountry) * 100, 1) : 0;
                            echo '<div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid rgba(0,0,0,0.05);">';
                            echo '  <div style="font-size: 0.95rem; font-weight: 500;">' . htmlspecialchars($country) . '</div>';
                            echo '  <div style="font-size: 0.9rem; color: var(--text-muted);">' . number_format($val) . ' (' . $pct . '%)</div>';
                            echo '</div>';
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
        <!-- <div class="footer">Made with AdMetrics</div> -->
    </div>

    <!-- PAGE 7: Detailed Content Performance -->
    <div class="page" style="height: auto; min-height: 210mm; page-break-inside: auto; overflow: visible;">
        <h1 class="title" style="font-size: 2.5rem; margin-bottom: 40px;">Detailed Content Performance -
            <?= $monthNameOnly ?></h1>

        <div class="card"
            style="background: transparent; border: 1px solid var(--card-border); padding: 0; overflow: hidden;">
            <table id="content-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr
                        style="background: rgba(18, 62, 43, 0.05); border-bottom: 2px solid var(--card-border); cursor: pointer;">
                        <th onclick="sortTable(0, 'str')" style="padding: 10px; font-weight: 600; font-size: 0.85rem;">
                            Date &#8645;</th>
                        <th onclick="sortTable(1, 'str')" style="padding: 10px; font-weight: 600; font-size: 0.85rem;">
                            Type &#8645;</th>
                        <th style="padding: 10px; font-weight: 600; font-size: 0.85rem;">Caption</th>
                        <th onclick="sortTable(3, 'num')"
                            style="padding: 10px; font-weight: 600; font-size: 0.85rem; text-align: right;">Reach
                            &#8645;</th>
                        <th onclick="sortTable(4, 'num')"
                            style="padding: 10px; font-weight: 600; font-size: 0.85rem; text-align: right;">Views
                            &#8645;</th>
                        <th onclick="sortTable(5, 'num')"
                            style="padding: 10px; font-weight: 600; font-size: 0.85rem; text-align: right;">Likes
                            &#8645;</th>
                        <th onclick="sortTable(6, 'num')"
                            style="padding: 10px; font-weight: 600; font-size: 0.85rem; text-align: right;">Comments
                            &#8645;</th>
                        <th onclick="sortTable(7, 'num')"
                            style="padding: 10px; font-weight: 600; font-size: 0.85rem; text-align: right;">Shares
                            &#8645;</th>
                        <th onclick="sortTable(8, 'num')"
                            style="padding: 10px; font-weight: 600; font-size: 0.85rem; text-align: right;">Saves
                            &#8645;</th>
                        <th onclick="sortTable(9, 'num')"
                            style="padding: 10px; font-weight: 600; font-size: 0.85rem; text-align: right;">Int. &#8645;
                        </th>
                        <th style="padding: 10px; font-weight: 600; font-size: 0.85rem; text-align: center;">Link</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($postDetails)): ?>
                        <?php foreach ($postDetails as $post): ?>
                            <tr style="border-bottom: 1px solid rgba(0,0,0,0.05); page-break-inside: avoid;">
                                <td data-sort="<?= htmlspecialchars($post['date_raw']) ?>"
                                    style="padding: 10px; font-size: 0.85rem; white-space: nowrap; color: var(--text-muted);">
                                    <?= htmlspecialchars($post['date']) ?></td>
                                <td style="padding: 10px; font-size: 0.85rem;">
                                    <span
                                        style="background: <?= strpos($post['type'], 'IG') !== false ? '#ec4899' : '#3b82f6' ?>; color: white; padding: 3px 6px; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                        <?= htmlspecialchars($post['type']) ?>
                                    </span>
                                </td>
                                <td style="padding: 10px; font-size: 0.85rem; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                                    title="<?= htmlspecialchars($post['caption']) ?>">
                                    <?= htmlspecialchars($post['caption']) ?>
                                </td>
                                <td style="padding: 10px; font-size: 0.85rem; font-weight: 500; text-align: right;">
                                    <?= number_format($post['reach']) ?></td>
                                <td style="padding: 10px; font-size: 0.85rem; font-weight: 500; text-align: right;">
                                    <?= number_format($post['views']) ?></td>
                                <td style="padding: 10px; font-size: 0.85rem; font-weight: 500; text-align: right;">
                                    <?= number_format($post['likes']) ?></td>
                                <td style="padding: 10px; font-size: 0.85rem; font-weight: 500; text-align: right;">
                                    <?= number_format($post['comments']) ?></td>
                                <td style="padding: 10px; font-size: 0.85rem; font-weight: 500; text-align: right;">
                                    <?= number_format($post['shares']) ?></td>
                                <td style="padding: 10px; font-size: 0.85rem; font-weight: 500; text-align: right;">
                                    <?= number_format($post['saves']) ?></td>
                                <td style="padding: 10px; font-size: 0.85rem; font-weight: 500; text-align: right;">
                                    <?= number_format($post['interactions']) ?></td>
                                <td style="padding: 10px; font-size: 0.85rem; text-align: center;">
                                    <a href="<?= htmlspecialchars($post['permalink']) ?>" target="_blank"
                                        style="display: inline-flex; align-items: center; justify-content: center; background: white; border: 1px solid var(--card-border); border-radius: 6px; padding: 4px 8px; color: var(--text-dark); text-decoration: none; font-size: 0.8rem; font-weight: 500; transition: all 0.2s;">

                                        View <span class="material-icons-round"
                                            style="font-size: 1rem; margin-left: 4px;">open_in_new</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="padding: 30px; text-align: center; color: var(--text-muted);">No content
                                posted in this date range.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
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

        // 6. Net Growth
        const growthDates = <?= json_encode($netGrowthDates) ?>;
        const igGrowth = <?= json_encode($igNetGrowth) ?>;
        const fbGrowth = <?= json_encode($fbNetGrowth) ?>;

        new Chart(document.getElementById('chart-net-growth'), {
            type: 'line',
            data: {
                labels: growthDates,
                datasets: [
                    {
                        label: 'Instagram',
                        data: igGrowth,
                        borderColor: '#ec4899',
                        backgroundColor: 'rgba(236, 72, 153, 0.1)',
                        borderWidth: 3,
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Facebook',
                        data: fbGrowth,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 3,
                        tension: 0.3,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { borderDash: [4, 4] } }
                }
            }
        });

        // Auto print prompt after a small delay
        setTimeout(() => {
            // window.print();
        }, 1000);
    </script>
    <script>
        // Sortable table logic for Content Performance
        let sortOrders = {};
        function sortTable(colIndex, type) {
            const table = document.getElementById("content-table");
            const tbody = table.tBodies[0];
            const rows = Array.from(tbody.querySelectorAll("tr"));

            sortOrders[colIndex] = !sortOrders[colIndex];
            const asc = sortOrders[colIndex];

            rows.sort((a, b) => {
                let valA = a.cells[colIndex].getAttribute('data-sort') || a.cells[colIndex].innerText.replace(/,/g, '').trim();
                let valB = b.cells[colIndex].getAttribute('data-sort') || b.cells[colIndex].innerText.replace(/,/g, '').trim();

                if (type === 'num') {
                    valA = parseFloat(valA) || 0;
                    valB = parseFloat(valB) || 0;
                    return asc ? valA - valB : valB - valA;
                } else {
                    return asc ? valA.localeCompare(valB) : valB.localeCompare(valA);
                }
            });

            rows.forEach(row => tbody.appendChild(row));
        }
    </script>
</body>

</html>
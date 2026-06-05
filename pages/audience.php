<?php
require_once '../includes/auth.php';
if (!isset($_GET['ajax'])) { header("Location: ../dashboard.php?page=audience"); exit; }
/**
 * Audience Demographics & Trends Page
 * Dynamically fetches data from api/fetch_audience.php API.
 */
?>

<div class="section-header">
    <div>
        <div class="badge badge-blue" style="margin-bottom: 8px;">AUDIENCE</div>
        <h1 class="section-title">Audience Demographics & Trends</h1>
        <p class="section-subtitle">Insights into your followers' age, gender, and growth patterns across platforms.</p>
    </div>
</div>

<div id="audience-loader" class="loader-container" style="padding: 60px 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;">
    <div class="spinner" style="width: 32px; height: 32px;"></div>
    <span class="loader-text" style="margin-top: 8px; font-weight: 600;">Loading Audience Data…</span>
    <div style="font-size: 13px; color: #1877F2; font-weight: 600; display: flex; align-items: center; gap: 6px; opacity: 0.85;">
        <span style="display: inline-block; width: 6px; height: 6px; background-color: #1877F2; border-radius: 50%;"></span>
        Fetching details from Meta...
    </div>
</div>

<div id="audience-main" style="display:none;">

<div class="card">
    <!-- Main Audience Tabs & Platform Toggle -->
    <div class="card-header" style="flex-direction: row; align-items: center; padding-bottom: 0;">
        <div style="flex: 1;">
            <div class="insights-tabs" style="margin-bottom: 0; border-bottom: none; display: flex; align-items: center; gap: 8px;">
                <button class="insights-tab aud-main-tab active" data-target="tab-demographics" onclick="switchAudienceTab('tab-demographics')">Demographics</button>
                <button class="insights-tab aud-main-tab" data-target="tab-trends" onclick="switchAudienceTab('tab-trends')">Trends</button>
            </div>
        </div>
        <div id="platform-toggle">
            <select class="form-control" style="font-size: 13px; font-weight: 700; padding: 8px 16px; border-radius: var(--radius-md); width: auto;" onchange="switchPlatform(this.value)">
                <option value="instagram">Instagram</option>
                <option value="facebook">Facebook</option>
            </select>
        </div>
    </div>

    <!-- DEMOGRAPHICS TAB -->
    <div id="tab-demographics" class="aud-tab-content card-body">
        
        <!-- Followers KPI -->
        <div style="margin-bottom:40px; display: flex; gap: 24px; flex-wrap: wrap;">
            <div style="padding: 24px 32px; background: var(--surface-low); border-radius: var(--radius-lg); flex: 1; min-width: 200px;">
                <div class="kpi-label" style="font-size: 13px; font-weight: 600; color: var(--on-surface-variant); margin-bottom: 8px; text-transform: uppercase;">Lifetime Followers ⓘ</div>
                <div class="kpi-value" id="demo-total-followers" style="font-size: 36px; font-weight: 800; color: var(--on-surface);">--</div>
            </div>
            <div style="padding: 24px 32px; background: var(--surface-low); border-radius: var(--radius-lg); flex: 1; min-width: 200px;">
                <div class="kpi-label" style="font-size: 13px; font-weight: 600; color: var(--on-surface-variant); margin-bottom: 8px; text-transform: uppercase;">Instagram Followers ⓘ</div>
                <div class="kpi-value" id="demo-ig-followers" style="font-size: 36px; font-weight: 800; color: var(--on-surface);">--</div>
            </div>
            <div style="padding: 24px 32px; background: var(--surface-low); border-radius: var(--radius-lg); flex: 1; min-width: 200px;">
                <div class="kpi-label" style="font-size: 13px; font-weight: 600; color: var(--on-surface-variant); margin-bottom: 8px; text-transform: uppercase;">Facebook Followers ⓘ</div>
                <div class="kpi-value" id="demo-fb-followers" style="font-size: 36px; font-weight: 800; color: var(--on-surface);">--</div>
            </div>
        </div>

        <!-- Age & Gender Bar Chart + Gender Breakdown -->
        <div class="demographics-grid">
            <!-- Left: Age & Gender Bar Chart -->
            <div>
                <h3 class="card-title" style="margin-bottom:24px; display:flex; align-items:center; gap:8px;">Age & gender ⓘ</h3>
                <div style="height:300px; width:100%;"><canvas id="ageGenderChart"></canvas></div>
            </div>

            <!-- Right: Gender Percentage Breakdown -->
            <div class="gender-breakdown-wrapper">
                <h3 class="card-title" style="margin-bottom:24px; display:flex; align-items:center; gap:8px;">Gender ⓘ</h3>
                <div id="gender-breakdown-container">
                    <div style="background:var(--surface-lowest); padding:24px; border-radius:var(--radius-md); text-align:center; margin:auto;"><p style="color:var(--on-surface-variant); font-size:13px; font-weight:600; margin:0;">Loading gender data...</p></div>
                </div>
            </div>
        </div>

        <!-- Locations -->
        <div class="locations-grid">
            <div>
                <h3 class="card-title" style="margin-bottom:20px;">Top towns/cities</h3>
                <div id="cities-container"><p class="text-muted">No data</p></div>
            </div>
            <div>
                <h3 class="card-title" style="margin-bottom:20px;">Top countries</h3>
                <div id="countries-container"><p class="text-muted">No data</p></div>
            </div>
        </div>
    </div>

    <!-- TRENDS TAB -->
    <div id="tab-trends" class="aud-tab-content card-body" style="display:none;">
        <!-- KPI Cards -->
        <div id="trends-kpi-row" class="kpi-grid" style="margin-bottom:32px;"></div>

        <div class="trends-grid">
            <div class="chart-wrap"><div style="height:350px; width:100%;"><canvas id="trendsLineChart"></canvas></div></div>
            <div style="border-left:1px solid var(--outline-variant); padding-left:32px;" id="trends-sidebar"></div>
        </div>
    </div>
</div>
</div>

<style>
.aud-main-tab {
    padding: 12px 24px;
    border: none;
    background: transparent;
    font-size: 13px;
    font-weight: 700;
    color: var(--on-surface-variant);
    cursor: pointer;
    border-bottom: 3px solid transparent;
    margin-bottom: -1px;
    transition: all 0.2s;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.aud-main-tab:hover {
    color: var(--on-surface);
}
.aud-main-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}
.location-bar-container { margin-bottom: 16px; }
.loc-label { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 6px; color: var(--on-surface); }
.loc-label span:first-child { font-weight: 700; }
.loc-label span:last-child { color: var(--on-surface-variant); font-weight: 600; }
.loc-bar-bg { height: 6px; background: var(--surface-low); border-radius: var(--radius-full); overflow: hidden; }
.loc-bar-fill { height: 100%; background: var(--primary); border-radius: var(--radius-full); transition: width 0.6s ease; }

.platform-btn {
    padding: 6px 16px;
    border: none;
    background: transparent;
    color: var(--on-surface-variant);
    font-weight: 700;
    cursor: pointer;
    font-size: 12px;
    border-radius: var(--radius-sm);
    transition: all 0.2s;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.platform-btn.active {
    background: var(--surface-lowest);
    color: var(--primary);
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.demographics-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 48px;
    margin-bottom: 48px;
}
.gender-breakdown-wrapper {
    border-left: 1px solid var(--outline-variant);
    padding-left: 48px;
    display: flex;
    flex-direction: column;
}
#gender-breakdown-container {
    background: var(--surface-lowest);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-lg);
    padding: 28px 24px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 24px;
    flex: 1;
}
@media (max-width: 992px) {
    .demographics-grid {
        grid-template-columns: 1fr;
        gap: 32px;
    }
    .gender-breakdown-wrapper {
        border-left: none !important;
        padding-left: 0 !important;
    }
    .locations-grid { grid-template-columns: 1fr; gap: 32px; }
    .trends-grid { grid-template-columns: 1fr; gap: 32px; }
    #trends-sidebar { border-left: none !important; padding-left: 0 !important; }
}

.locations-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; }
.trends-grid { display: grid; grid-template-columns: 1fr 300px; gap: 32px; }
.kpi-badge { font-size: 12px; font-weight: 700; padding: 2px 6px; border-radius: 4px; display: inline-flex; align-items: center; }
.kpi-badge.up { background: rgba(16, 185, 129, 0.1); color: #10b981; }
.kpi-badge.down { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
</style>

<script>
(function(){
    let audienceData = null;
    let ageGenderChart = null;
    let trendsChart = null;
    let currentPlatform = 'instagram';

    const since = new URLSearchParams(window.location.search).get('since') || document.getElementById('start-date')?.value || '';
    const until = new URLSearchParams(window.location.search).get('until') || document.getElementById('end-date')?.value || '';

    (async () => {
        try {
            const res = await fetch(`api/fetch_audience.php?since=${since}&until=${until}`);
            
            // If user navigated away while fetching, abort silently
            if (!document.getElementById('audience-loader')) return;

            const data = await res.json();
            audienceData = data;
            document.getElementById('audience-loader').style.display = 'none';
            document.getElementById('audience-main').style.display = 'block';
            let errorMsg = '';
            if (data.error) errorMsg = data.message || data.error;
            else if (data.facebook && data.facebook.error) errorMsg = data.facebook.error;
            else if (data.instagram && data.instagram.error) errorMsg = data.instagram.error;

            if (errorMsg) {
                if (window.showGlobalToast) {
                    window.showGlobalToast(errorMsg, 'error', 'Configure', '?page=settings');
                }
                document.getElementById('audience-loader').style.display = 'flex';
                document.getElementById('audience-loader').innerHTML = `<div style="color:var(--on-surface-variant);padding:2rem;text-align:center;font-weight:600;">API Connection Error occurred. Please check settings.</div>`;
                document.getElementById('audience-main').style.display = 'none';
                return; // Stop execution if API is not configured or failed entirely
            }

            renderDemographics(currentPlatform);
            renderTrends();
        } catch (err) {
            if (window.showGlobalToast) {
                window.showGlobalToast(err.message || 'Failed to load audience data', 'error');
            }
            const loader = document.getElementById('audience-loader');
            if (loader) {
                loader.style.display = 'flex';
                loader.innerHTML = '<div class="no-activity">Failed to load audience data.</div>';
            }
            const main = document.getElementById('audience-main');
            if (main) main.style.display = 'none';
        }
    })();

    window.switchAudienceTab = function(tabId) {
        document.querySelectorAll('.aud-tab-content').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.aud-main-tab').forEach(el => el.classList.remove('active'));
        document.getElementById(tabId).style.display = 'block';
        document.querySelector(`.aud-main-tab[data-target="${tabId}"]`).classList.add('active');
        if (tabId === 'tab-trends' && !trendsChart) renderTrends();
    };

    window.switchPlatform = function(platform) {
        currentPlatform = platform;
        renderDemographics(platform);
        renderTrends();
    };

    function fmtNum(n) {
        return Number(n).toLocaleString();
    }

    // ===== DEMOGRAPHICS RENDERING =====
    function renderDemographics(platform) {
        if (!audienceData) return;
        
        // Update the 3 new KPIs
        const fbFollowers = audienceData.facebook ? (audienceData.facebook.followers || 0) : 0;
        const igFollowers = audienceData.instagram ? (audienceData.instagram.followers || 0) : 0;
        const totalFollowers = fbFollowers + igFollowers;

        document.getElementById('demo-total-followers').textContent = fmtNum(totalFollowers);
        document.getElementById('demo-ig-followers').textContent = fmtNum(igFollowers);
        document.getElementById('demo-fb-followers').textContent = fmtNum(fbFollowers);

        const d = audienceData[platform];
        const followers = d.followers || 0;

        // Age & Gender chart
        renderAgeGenderChart(platform);

        // Gender breakdown
        renderGenderBreakdown(platform);

        // Cities
        const cities = d.cities || {};
        const cityKeys = Object.keys(cities);
        const totalCityFans = Object.values(cities).reduce((a,b) => a+b, 0) || 1;
        document.getElementById('cities-container').innerHTML = cityKeys.length === 0
            ? '<div style="background:var(--surface-lowest); padding:24px; border-radius:var(--radius-md); text-align:center;"><p style="color:var(--on-surface-variant); font-size:13px; font-weight:600; margin:0;">No city data available</p></div>'
            : cityKeys.slice(0, 6).map(c => {
                const pct = ((cities[c] / totalCityFans) * 100).toFixed(1);
                return `<div class="location-bar-container">
                    <div class="loc-label"><span>${c}</span><span>${pct}%</span></div>
                    <div class="loc-bar-bg"><div class="loc-bar-fill" style="width:${Math.min(pct, 100)}%"></div></div>
                </div>`;
            }).join('');

        // Countries
        const countries = d.countries || {};
        const countryKeys = Object.keys(countries);
        const totalCountryFans = Object.values(countries).reduce((a,b) => a+b, 0) || 1;
        document.getElementById('countries-container').innerHTML = countryKeys.length === 0
            ? '<div style="background:var(--surface-lowest); padding:24px; border-radius:var(--radius-md); text-align:center;"><p style="color:var(--on-surface-variant); font-size:13px; font-weight:600; margin:0;">No country data available</p></div>'
            : countryKeys.slice(0, 6).map(c => {
                const pct = ((countries[c] / totalCountryFans) * 100).toFixed(1);
                return `<div class="location-bar-container">
                    <div class="loc-label"><span>${c}</span><span>${pct}%</span></div>
                    <div class="loc-bar-bg"><div class="loc-bar-fill" style="width:${Math.min(pct, 100)}%"></div></div>
                </div>`;
            }).join('');
    }

    function renderAgeGenderChart(platform) {
        if (ageGenderChart) { ageGenderChart.destroy(); ageGenderChart = null; }
        const d = audienceData[platform];

        // Age groups to display
        const ageLabels = ['13-17','18-24','25-34','35-44','45-54','55-64','65+'];
        let womenData = ageLabels.map(() => 0);
        let menData = ageLabels.map(() => 0);
        let othersData = ageLabels.map(() => 0);

        if (platform === 'facebook') {
            // FB format: { "M.18-24": 10, "F.25-34": 20, "U.18-24": 5... }
            const ga = d.gender_age || {};
            Object.entries(ga).forEach(([key, val]) => {
                const parts = key.split('.');
                const gender = parts[0]; // M, F, U
                const age = parts[1];    // 18-24, etc.
                const idx = ageLabels.indexOf(age);
                if (idx === -1) return;
                if (gender === 'F') womenData[idx] += val;
                if (gender === 'M') menData[idx] += val;
                if (gender === 'U') othersData[idx] += val;
            });
        } else {
            // IG format: age_breakdown { "18-24": 100, ... } + gender_breakdown { "F": 500, "M": 300, "U": 100 }
            const ageBk = d.age_breakdown || {};
            const genderBk = d.gender_breakdown || {};
            const totalGender = (genderBk['F'] || 0) + (genderBk['M'] || 0) + (genderBk['U'] || 0) || 1;
            const womenRatio = (genderBk['F'] || 0) / totalGender;
            const menRatio = (genderBk['M'] || 0) / totalGender;
            const othersRatio = (genderBk['U'] || 0) / totalGender;

            ageLabels.forEach((label, idx) => {
                const ageVal = ageBk[label] || 0;
                womenData[idx] = Math.round(ageVal * womenRatio);
                menData[idx] = Math.round(ageVal * menRatio);
                othersData[idx] = Math.round(ageVal * othersRatio);
            });
        }

        // Convert to percentages
        const total = womenData.reduce((a,b)=>a+b,0) + menData.reduce((a,b)=>a+b,0) + othersData.reduce((a,b)=>a+b,0) || 1;
        womenData = womenData.map(v => +((v/total)*100).toFixed(1));
        menData = menData.map(v => +((v/total)*100).toFixed(1));
        othersData = othersData.map(v => +((v/total)*100).toFixed(1));

        const ctx = document.getElementById('ageGenderChart');
        ageGenderChart = new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: ageLabels,
                datasets: [
                    { label: 'Women', data: womenData, backgroundColor: '#ec4899', borderRadius: {topLeft:4,topRight:4}, barPercentage: 0.6, categoryPercentage: 0.8 },
                    { label: 'Men', data: menData, backgroundColor: '#3b82f6', borderRadius: {topLeft:4,topRight:4}, barPercentage: 0.6, categoryPercentage: 0.8 },
                    { label: 'Others', data: othersData, backgroundColor: '#8b5cf6', borderRadius: {topLeft:4,topRight:4}, barPercentage: 0.6, categoryPercentage: 0.8 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + ctx.raw + '%' } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => v+'%', color: '#64748b' }, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false }, ticks: { color: '#64748b', font: { weight: '600' } } }
                }
            }
        });
    }

    function renderGenderBreakdown(platform) {
        const d = audienceData[platform];
        let female = 0;
        let male = 0;
        let others = 0;

        if (platform === 'facebook') {
            const ga = d.gender_age || {};
            Object.entries(ga).forEach(([key, val]) => {
                const parts = key.split('.');
                const gender = parts[0];
                if (gender === 'F') female += val;
                else if (gender === 'M') male += val;
                else if (gender === 'U') others += val;
            });
        } else {
            const genderBk = d.gender_breakdown || {};
            female = genderBk['F'] || genderBk['FEMALE'] || 0;
            male = genderBk['M'] || genderBk['MALE'] || 0;
            others = genderBk['U'] || genderBk['UNKNOWN'] || 0;
        }

        const totalGender = female + male + others;
        if (totalGender === 0) {
            document.getElementById('gender-breakdown-container').innerHTML = '<div style="background:var(--surface-lowest); padding:24px; border-radius:var(--radius-md); text-align:center; margin:auto;"><p style="color:var(--on-surface-variant); font-size:13px; font-weight:600; margin:0;">No gender data available</p></div>';
            return;
        }

        const femalePct = ((female / totalGender) * 100).toFixed(1);
        const malePct = ((male / totalGender) * 100).toFixed(1);
        const othersPct = ((others / totalGender) * 100).toFixed(1);

        let html = `
            <div style="margin-bottom: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-weight: 700; font-size: 14px; color: var(--on-surface);">Female</span>
                    <span style="font-size: 15px; font-weight: 800; color: #ec4899;">${femalePct}%</span>
                </div>
                <div style="background: rgba(0,0,0,0.06); height: 10px; border-radius: var(--radius-full); overflow: hidden;">
                    <div style="width: ${femalePct}%; background: #ec4899; height: 100%; border-radius: var(--radius-full); transition: width 0.6s ease;"></div>
                </div>
                <div style="font-size: 12px; color: var(--on-surface-variant); font-weight: 600; margin-top: 6px;">${fmtNum(female)} followers</div>
            </div>

            <div style="margin-bottom: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-weight: 700; font-size: 14px; color: var(--on-surface);">Male</span>
                    <span style="font-size: 15px; font-weight: 800; color: #3b82f6;">${malePct}%</span>
                </div>
                <div style="background: rgba(0,0,0,0.06); height: 10px; border-radius: var(--radius-full); overflow: hidden;">
                    <div style="width: ${malePct}%; background: #3b82f6; height: 100%; border-radius: var(--radius-full); transition: width 0.6s ease;"></div>
                </div>
                <div style="font-size: 12px; color: var(--on-surface-variant); font-weight: 600; margin-top: 6px;">${fmtNum(male)} followers</div>
            </div>
        `;

        if (others > 0) {
            html += `
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-weight: 700; font-size: 14px; color: var(--on-surface);">Others</span>
                        <span style="font-size: 15px; font-weight: 800; color: #8b5cf6;">${othersPct}%</span>
                    </div>
                    <div style="background: rgba(0,0,0,0.06); height: 10px; border-radius: var(--radius-full); overflow: hidden;">
                        <div style="width: ${othersPct}%; background: #8b5cf6; height: 100%; border-radius: var(--radius-full); transition: width 0.6s ease;"></div>
                    </div>
                    <div style="font-size: 12px; color: var(--on-surface-variant); font-weight: 600; margin-top: 6px;">${fmtNum(others)} followers</div>
                </div>
            `;
        }

        document.getElementById('gender-breakdown-container').innerHTML = html;
    }

    // ===== TRENDS RENDERING =====
    function renderTrends() {
        if (!audienceData) return;
        const fb = audienceData.facebook;
        const ig = audienceData.instagram;
        const dr = audienceData.date_range || {};

        // Calculate KPIs from the currently selected platform
        const platformData = audienceData[currentPlatform];
        const dailyF = platformData.daily_follows || [];
        const totalFollows = dailyF.reduce((s,d) => s + d.follows, 0);
        const totalUnfollows = dailyF.reduce((s,d) => s + d.unfollows, 0);
        const netFollows = totalFollows - totalUnfollows;
        const platformName = currentPlatform === 'facebook' ? 'Facebook' : 'Instagram';

        // KPI Cards
        document.getElementById('trends-kpi-row').innerHTML = `
            <div class="kpi-card active" onclick="this.classList.toggle('active')">
                <div class="kpi-label">${platformName} Follows ⓘ</div>
                <div class="kpi-value">${fmtNum(totalFollows)}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">${platformName} Unfollows ⓘ</div>
                <div class="kpi-value">${fmtNum(totalUnfollows)}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">${platformName} Net follows ⓘ</div>
                <div class="kpi-value">
                    ${fmtNum(netFollows)}
                    <span class="kpi-badge ${netFollows >= 0 ? 'up' : 'down'}" style="margin-left: 8px;">${netFollows >= 0 ? '↑' : '↓'}</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Total Followers ⓘ</div>
                <div class="kpi-value">${fmtNum(platformData.followers)}</div>
                <div style="font-size:11px; color:var(--on-surface-variant); font-weight:600; margin-top:4px;">Lifetime Count</div>
            </div>`;

        // Sidebar
        document.getElementById('trends-sidebar').innerHTML = `
            <div class="card-label" style="margin-bottom:8px; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--on-surface-variant);">Followers breakdown</div>
            <p style="font-size:13px; color:var(--on-surface-variant); margin-bottom:24px; font-weight:600;">${dr.since || ''} — ${dr.until || ''}</p>
            <div style="margin-bottom:20px; padding: 16px; background: var(--surface-low); border-radius: var(--radius-md);">
                <div style="font-size:12px; color:var(--on-surface-variant); font-weight:600; margin-bottom:4px;">${platformName} Unfollows</div>
                <div style="font-size:24px; font-weight:800; color:var(--on-surface);">${fmtNum(totalUnfollows)}</div>
            </div>
            <div style="margin-bottom:20px; padding: 16px; background: var(--surface-low); border-radius: var(--radius-md);">
                <div style="font-size:12px; color:var(--on-surface-variant); font-weight:600; margin-bottom:4px;">${platformName} Net follows</div>
                <div style="font-size:24px; font-weight:800; color:var(--primary);">${fmtNum(netFollows)}</div>
            </div>
            <div class="divider"></div>
            <div style="margin-bottom:16px;">
                <div style="font-size:11px; font-weight:700; color:var(--on-surface-variant); text-transform:uppercase;">FB Followers</div>
                <div style="font-size:24px; font-weight:800; color:var(--on-surface);">${fmtNum(fb.followers)}</div>
            </div>
            <div>
                <div style="font-size:11px; font-weight:700; color:var(--on-surface-variant); text-transform:uppercase;">IG Followers</div>
                <div style="font-size:24px; font-weight:800; color:var(--on-surface);">${fmtNum(ig.followers)}</div>
            </div>`;

        // Line Chart — dynamically plot selected platform
        if (trendsChart) { trendsChart.destroy(); trendsChart = null; }

        const labels = dailyF.map(d => {
            const dt = new Date(d.date);
            return dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
        });
        const followsData = dailyF.map(d => d.follows);

        const datasets = [];

        if (followsData.length > 0) {
            datasets.push({
                label: platformName + ' Follows', data: followsData,
                borderColor: currentPlatform === 'facebook' ? '#1877f2' : '#e1306c',
                backgroundColor: currentPlatform === 'facebook' ? 'rgba(24,119,242,0.05)' : 'rgba(225,48,108,0.05)',
                borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, tension: 0.2, fill: true
            });
        }

        const scalesConfig = {
            y: { beginAtZero: true, ticks: { color: '#64748b' }, grid: { color: 'rgba(0,0,0,0.05)' } },
            x: { ticks: { color: '#64748b', maxTicksLimit: 7 }, grid: { display: false } }
        };

        const ctx = document.getElementById('trendsLineChart');
        trendsChart = new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
                interaction: { mode: 'index', intersect: false },
                scales: scalesConfig
            }
        });
    }
})();
</script>


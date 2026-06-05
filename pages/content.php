<?php
require_once '../includes/auth.php';
if (!isset($_GET['ajax'])) { header("Location: ../dashboard.php?page=content"); exit; }
/**
 * Content Performance Page
 * Fetches Instagram + Facebook posts and renders ranked content cards.
 */
$since = $_GET['since'] ?? date('Y-m-d', strtotime('-30 days'));
$until = $_GET['until'] ?? date('Y-m-d');
?>

<div class="section-header">
    <div>
        <div class="badge badge-blue" style="margin-bottom: 8px;">CONTENT</div>
        <h1 class="section-title">Content Performance</h1>
        <p class="section-subtitle">Top performing posts, reels, and stories ranked by reach and engagement.</p>
    </div>
        
        <div style="display: flex; gap: 12px; align-items: center; background: var(--surface-lowest); padding: 8px 16px; border-radius: var(--radius-md); border: 1px solid var(--outline-variant); box-shadow: var(--shadow-card); flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <label style="font-size: 11px; font-weight: 700; color: var(--on-surface-variant); text-transform: uppercase; letter-spacing: 0.05em;">Platform:</label>
                <select id="filter-platform" class="form-control" style="width: auto; padding: 4px 8px;">
                    <option value="all">All Platforms</option>
                    <option value="facebook">Facebook Only</option>
                    <option value="instagram">Instagram Only</option>
                </select>
            </div>
            <div style="width: 1px; height: 20px; background: var(--outline-variant);"></div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <label style="font-size: 11px; font-weight: 700; color: var(--on-surface-variant); text-transform: uppercase; letter-spacing: 0.05em;">Content Type:</label>
                <select id="filter-type" class="form-control" style="width: auto; padding: 4px 8px;">
                    <option value="all">All Content</option>
                    <option value="post">Posts Only</option>
                    <option value="reel">Reels Only</option>
                </select>
            </div>
            <div style="width: 1px; height: 20px; background: var(--outline-variant);"></div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <label style="font-size: 11px; font-weight: 700; color: var(--on-surface-variant); text-transform: uppercase; letter-spacing: 0.05em;">Sort By:</label>
                <select id="sort-by" class="form-control" style="width: auto; padding: 4px 8px;">
                    <option value="views">Views</option>
                    <option value="total_engagement">Total Engagement</option>
                    <option value="likes">Likes</option>
                    <option value="comments">Comments</option>
                    <option value="shares">Shares</option>
                    <option value="saves">Saves</option>
                    <option value="timestamp">Recent Date</option>
                </select>
            </div>
        </div>
    </div>
</div>

<div id="content-loader">
    <?php $loaderText = 'Loading Content Performance...'; include __DIR__ . '/../includes/loader.php'; ?>
</div>

<div id="content-main" style="display:none;">
<!-- Distribution + Summary charts -->
<div class="top-charts-grid">
    <div class="card" style="padding: 24px; display: flex; flex-direction: column; align-items: center;">
        <h3 class="card-title" style="margin-bottom: 16px;">Content Types</h3>
        <div style="position: relative; height: 180px; width: 100%;">
            <canvas id="contentTypeChart"></canvas>
        </div>
    </div>
    <div class="card" style="padding: 24px; display: flex; flex-direction: column; justify-content: center;">
        <h3 class="card-title" style="margin-bottom: 12px;">Performance Insights</h3>
        <div id="content-summary" style="color: var(--on-surface-variant);">Loading...</div>
    </div>
</div>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <h2 class="section-title" style="font-size: 18px;">Top Content</h2>
    <span id="showing-count" style="font-size: 12px; color: var(--on-surface-variant); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;"></span>
</div>

<div id="content-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
</div>
</div> <!-- End content-main -->

<style>
.top-charts-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 24px;
    margin-bottom: 24px;
}
@media (min-width: 992px) {
    .top-charts-grid {
        grid-template-columns: 1fr 1.8fr;
    }
}
.metric-box { text-align: center; background: var(--surface-low); padding: 8px 4px; border-radius: var(--radius-md); transition: transform 0.2s ease, background 0.2s; border: 1px solid transparent; }
.metric-box:hover { transform: translateY(-2px); background: var(--surface-high); border-color: var(--outline-variant); }
.metric-val { font-size: 15px; font-weight: 800; color: var(--on-surface); line-height: 1.2; }
.metric-label { font-size: 10px; color: var(--on-surface-variant); text-transform: uppercase; font-weight: 700; margin-top: 2px; letter-spacing: 0.03em; }
.content-card { transition: all 0.3s ease; border: 1px solid var(--outline-variant); }
.content-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); border-color: var(--primary-fixed-dim); }
</style>

<script>
(async function () {
    const since = '<?= htmlspecialchars($since) ?>';
    const until = '<?= htmlspecialchars($until) ?>';
    let allData = [];
    let chartInstance = null;

    try {
        const res  = await fetch(`api/fetch_content.php?since=${since}&until=${until}`);
        
        // If user navigated away while fetching, abort silently
        if (!document.getElementById('content-loader')) return;

        const data = await res.json();

        if (data.error || !Array.isArray(data)) {
            const errorMsg = data.message || 'API error occurred. Please check settings.';
            if (window.showGlobalToast) {
                window.showGlobalToast(errorMsg, 'error', 'Configure', '?page=settings');
            }
            document.getElementById('content-loader').innerHTML = `<div style="color:var(--on-surface-variant);padding:2rem;text-align:center;">API error occurred. Please check settings.</div>`;
            return;
        }

        allData = data;
        document.getElementById('content-loader').style.display = 'none';
        document.getElementById('content-main').style.display = 'block';

        // Add event listeners for filters
        document.getElementById('filter-platform').addEventListener('change', renderUI);
        document.getElementById('filter-type').addEventListener('change', renderUI);
        document.getElementById('sort-by').addEventListener('change', renderUI);

        renderUI();

    } catch (err) {
        if (window.showGlobalToast) {
            window.showGlobalToast(err.message || 'Failed to load content data', 'error');
        }
        document.getElementById('content-loader').innerHTML = `<div style="color:var(--on-surface-variant);padding:2rem;text-align:center;">Failed to load content data.</div>`;
        console.error(err);
    }

    function renderUI() {
        const platformFilter = document.getElementById('filter-platform').value;
        const typeFilter = document.getElementById('filter-type').value;
        const sortBy = document.getElementById('sort-by').value;

        // 1. Filter
        let filteredData = allData.filter(item => {
            // Platform Filter
            if (platformFilter !== 'all' && item.platform !== platformFilter) {
                return false;
            }
            // Content Type Filter
            if (typeFilter !== 'all') {
                const itemType = (item.display_type || '').toLowerCase();
                if (typeFilter === 'post') {
                    if (itemType !== 'post' && itemType !== 'carousel') return false;
                } else if (typeFilter === 'reel') {
                    if (itemType !== 'reel' && itemType !== 'video') return false;
                }
            }
            return true;
        });

        // 2. Sort
        filteredData.sort((a, b) => {
            if (sortBy === 'timestamp') {
                return new Date(b.timestamp) - new Date(a.timestamp);
            }
            return (b[sortBy] || 0) - (a[sortBy] || 0);
        });

        // Update count
        document.getElementById('showing-count').textContent = `Showing ${filteredData.length} items`;

        // 3. Render Top Section Summary based on FILTERED data
        updateSummarySection(filteredData);

        // 4. Render Grid
        renderGrid(filteredData);
    }

    function updateSummarySection(data) {
        // Type distribution
        const typeCounts = { Post: 0, Reel: 0, Carousel: 0, Video: 0 };
        data.forEach(item => { if (typeCounts[item.display_type] !== undefined) typeCounts[item.display_type]++; });
        const labels = Object.keys(typeCounts).filter(k => typeCounts[k] > 0);
        const values = labels.map(k => typeCounts[k]);

        const maxViews  = data.length ? Math.max(0, ...data.map(d => d.views || d.reach || 0)) : 0;
        const maxEngage = data.length ? Math.max(0, ...data.map(d => d.total_engagement || 0)) : 0;
        
        const totalContent = data.length;
        const fbContent = data.filter(d => d.platform === 'facebook').length;
        const igContent = data.filter(d => d.platform === 'instagram').length;

        document.getElementById('content-summary').innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 20px; flex-wrap: wrap;">
                <div style="flex: 1;">
                    <p style="font-size: 14px; margin-bottom: 12px;">Analyzed <strong style="color:var(--primary);">${totalContent}</strong> pieces of content.</p>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 0;">
                        <div class="kpi-card" style="padding: 12px; background: var(--surface-low); border: none; box-shadow: none;">
                            <div class="kpi-value" style="font-size: 24px; color: var(--primary);">${maxViews.toLocaleString()}</div>
                            <div class="kpi-label" style="font-size: 10px;">Top Views</div>
                        </div>
                        <div class="kpi-card" style="padding: 12px; background: var(--surface-low); border: none; box-shadow: none;">
                            <div class="kpi-value" style="font-size: 24px; color: #ec4899;">${maxEngage.toLocaleString()}</div>
                            <div class="kpi-label" style="font-size: 10px;">Top Engagement</div>
                        </div>
                    </div>
                </div>
                <div style="background: var(--surface-low); padding: 16px; border-radius: var(--radius-md); border: 1px solid var(--outline-variant); min-width: 150px;">
                    <div style="font-size: 11px; font-weight: 800; color: var(--on-surface-variant); text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.05em;">Content Posted</div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 13px; font-weight: 600; color: var(--on-surface);">Total</span>
                        <span style="font-size: 14px; font-weight: 800;">${totalContent}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 13px; font-weight: 600; color: #1877f2;">Facebook</span>
                        <span style="font-size: 14px; font-weight: 800;">${fbContent}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="font-size: 13px; font-weight: 600; color: #e1306c;">Instagram</span>
                        <span style="font-size: 14px; font-weight: 800;">${igContent}</span>
                    </div>
                </div>
            </div>`;

        // Content type donut
        if (chartInstance) {
            chartInstance.destroy();
            chartInstance = null;
        }

        if (labels.length > 0) {
            const ctx = document.getElementById('contentTypeChart').getContext('2d');
            chartInstance = new Chart(ctx, {
                type: 'doughnut',
                data: { labels, datasets: [{ data: values, backgroundColor: ['#3b82f6','#ec4899','#f59e0b','#10b981'], borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }, cutout: '75%' }
            });
        }
    }

    function renderGrid(data) {
        const grid = document.getElementById('content-grid');
        grid.innerHTML = '';
        
        if (data.length === 0) {
            grid.innerHTML = `<div style="grid-column:1/-1; padding:3rem; text-align:center; color:var(--text-secondary);">No content found matching these filters.</div>`;
            return;
        }

        // Limit to 24 for performance in grid
        data.slice(0, 24).forEach((item, i) => {
            const isIg = item.platform === 'instagram';
            const iconUrl = isIg 
                ? 'https://upload.wikimedia.org/wikipedia/commons/e/e7/Instagram_logo_2016.svg'
                : 'https://upload.wikimedia.org/wikipedia/commons/b/b8/2021_Facebook_icon.svg';
                
            const platformName = isIg ? 'Instagram' : 'Facebook';
            const platformColor = isIg ? '#e1306c' : '#1877f2';

            const date    = new Date(item.timestamp).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
            const caption = item.caption ? (item.caption.length > 70 ? item.caption.substring(0, 70) + '…' : item.caption) : 'No caption available';
            
            // Metrics
            const views    = Number(item.views || item.reach || 0).toLocaleString();
            const engage   = Number(item.total_engagement || 0).toLocaleString();
            const likes    = Number(item.likes || 0).toLocaleString();
            const comments = Number(item.comments || 0).toLocaleString();
            const shares   = Number(item.shares || 0).toLocaleString();
            const saves    = Number(item.saves || 0).toLocaleString();

            const card = document.createElement('div');
            card.className = 'card content-card';
            card.style.cssText = 'padding: 20px; display: flex; flex-direction: column; gap: 16px; position: relative; overflow: hidden;';
            
            // Background blur accent
            card.innerHTML = `
                <div style="position: absolute; top: -10px; right: -10px; width: 50px; height: 50px; background: ${platformColor}; opacity: 0.08; border-radius: 50%; filter: blur(15px);"></div>
                
                <div style="display:flex;justify-content:space-between;align-items:center; z-index: 1;">
                    <div style="display:flex;gap:10px;align-items:center;">
                        <img src="${iconUrl}" alt="${platformName}" style="width:20px; height:20px; object-fit:contain;" title="${platformName}">
                        <div>
                            <div style="font-size:11px; font-weight:800; color:${platformColor}; text-transform:uppercase; letter-spacing:0.05em;">${item.display_type}</div>
                            <div style="font-size:11px; color:var(--on-surface-variant); font-weight:500;">${date}</div>
                        </div>
                    </div>
                    <div style="font-size:18px;font-weight:900;color:var(--surface-high)">#${i + 1}</div>
                </div>
                
                <p style="font-size:13px;color:var(--on-surface);line-height:1.5;flex-grow:1; font-weight: 500; z-index: 1;">${caption}</p>
                
                <div style="background: var(--surface-low); border-radius: var(--radius-md); padding: 12px; z-index: 1; border: 1px solid var(--outline-variant);">
                    <div style="display:flex; justify-content:space-between; margin-bottom: 12px; border-bottom: 1px solid var(--outline-variant); padding-bottom: 8px;">
                        <div style="text-align:left;">
                            <div style="font-size:10px; color:var(--on-surface-variant); text-transform:uppercase; font-weight:700; letter-spacing:0.03em;">Reach/Views</div>
                            <div style="font-size:18px; font-weight:800; color:var(--on-surface);">${views}</div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:10px; color:var(--on-surface-variant); text-transform:uppercase; font-weight:700; letter-spacing:0.03em;">Engagement</div>
                            <div style="font-size:18px; font-weight:800; color:#ec4899;">${engage}</div>
                        </div>
                    </div>
                    
                    <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap: 6px;">
                        <div class="metric-box">
                            <div class="metric-val">${likes}</div>
                            <div class="metric-label">Likes</div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-val">${comments}</div>
                            <div class="metric-label">Cmnts</div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-val">${shares}</div>
                            <div class="metric-label">Shares</div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-val">${saves}</div>
                            <div class="metric-label">Saves</div>
                        </div>
                    </div>
                </div>
                
                <a href="${item.url}" target="_blank" class="btn btn-ghost btn-sm" style="width:100%; justify-content:center; color:var(--primary); font-weight:700;">View Original Post <span class="material-symbols-outlined" style="font-size:14px; margin-left:4px;">open_in_new</span></a>
            `;
            grid.appendChild(card);
        });
    }
})();
</script>


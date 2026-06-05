/**
 * Dashboard Logic Controller
 * Manages UI state, data fetching, and dynamic rendering.
 */

document.addEventListener('DOMContentLoaded', () => {
    // --- Global State ---
    let campaignData = [];
    let currentFilter = 'ALL';
    let currentView = 'OVERVIEW';
    let selectedCampaignId = null;

    // UI Elements
    const acctDisplay = document.getElementById('display-acct-name');
    const contentBody = document.getElementById('dashboard-content');
    const navOverview = document.getElementById('nav-overview');
    const navCampaigns = document.getElementById('nav-campaigns');
    const navContent = document.getElementById('nav-content');
    const navAudience = document.getElementById('nav-audience');

    const navSettings = document.getElementById('nav-settings');
    const startDateInput = document.getElementById('start-date');
    const endDateInput = document.getElementById('end-date');

    // ... (rest of the date setup and loadAccountInfo)
    // Set default dates (Last 30 days)
    const today = new Date();
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(today.getDate() - 30);

    startDateInput.value = thirtyDaysAgo.toISOString().split('T')[0];
    endDateInput.value = today.toISOString().split('T')[0];

    // Load Account Info
    async function loadAccountInfo() {
        try {
            const res = await fetch('includes/get_settings.php');
            const config = await res.json();
            if (config.account_name) {
                acctDisplay.textContent = config.account_name;
            } else if (config.ad_account_id) {
                acctDisplay.textContent = 'Account: ' + config.ad_account_id;
            } else {
                 acctDisplay.textContent = 'No Account Selected';
            }
        } catch (e) { console.error('Failed to load account info'); }
    }
    loadAccountInfo();

    /**
     * Core Data Fetching Logic
     * Retrieves campaign data from index.php based on current filters.
     */
    async function fetchCampaignData() {
        // Show loading state
        contentBody.innerHTML = `
            <div style="display: flex; justify-content: center; align-items: center; height: 300px; flex-direction: column; gap: 1rem;">
                <div class="logo-icon" style="width: 48px; height: 48px; border: 4px solid #f1f5f9; border-top-color: var(--accent-blue); border-radius: 50%; animation: spin 1s infinite linear;"></div>
                <p style="color: var(--text-secondary); font-weight: 600; font-size: 1.1rem;">Fetching live data from Facebook...</p>
            </div>
        `;

        try {
            const since = startDateInput.value;
            const until = endDateInput.value;
            const res = await fetch(`api/fetch_campaigns_index.php?json=1&since=${since}&until=${until}`);
            const data = await res.json();

            // Handle logical errors (e.g. account not configured or API errors)
            if (data.error) {
                if (data.error === 'not_configured') {
                    switchView('SETTINGS');
                } else {
                    console.error('API Error:', data);
                    contentBody.innerHTML = `
                        <div style="padding: 3rem; text-align: center; background: white; border-radius: 20px; border: 1px solid var(--glass-border); margin: 2rem;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">⚠️</div>
                            <h2 style="color: #ef4444; margin-bottom: 1rem;">API Connection Error</h2>
                            <p style="color: var(--text-secondary); margin-bottom: 2rem; font-size: 1.1rem;">${data.message || 'An unexpected error occurred while fetching data.'}</p>
                            <button onclick="location.reload()" class="filter-btn active" style="padding: 0.8rem 2rem; font-size: 1rem;">Try Again</button>
                        </div>
                    `;
                }
                return;
            }

            campaignData = data;
            
            if (currentView === 'DETAILS' && selectedCampaignId) {
                const updatedCampaign = campaignData.find(c => c.id === selectedCampaignId);
                if (updatedCampaign) {
                    renderCampaignDetails(updatedCampaign);
                } else {
                    switchView('CAMPAIGNS');
                }
            } else {
                switchView('CAMPAIGNS');
            }
        } catch (err) {
            console.error('Network or Parse failed:', err);
            contentBody.innerHTML = '<div style="padding: 2rem; color: #ef4444; font-weight: 700; font-size: 1.1rem; text-align: center;">Network Error: Failed to connect to the internal API. Please check your local server.</div>';
        }
    }

    // --- Navigation Logic ---
    navOverview.addEventListener('click', () => switchView('OVERVIEW'));
    navCampaigns.addEventListener('click', () => switchView('CAMPAIGNS'));
    navContent.addEventListener('click', () => switchView('CONTENT'));
    navAudience.addEventListener('click', () => switchView('AUDIENCE'));

    navSettings.addEventListener('click', () => switchView('SETTINGS'));

    // Auto-refresh on date change
    startDateInput.addEventListener('change', () => {
        if(currentView === 'CAMPAIGNS') fetchCampaignData();
        else if(currentView === 'OVERVIEW') renderOverviewDashboard();
    });
    endDateInput.addEventListener('change', () => {
         if(currentView === 'CAMPAIGNS') fetchCampaignData();
         else if(currentView === 'OVERVIEW') renderOverviewDashboard();
    });

    function switchView(view) {
        currentView = view;
        const dateFilter = document.querySelector('.date-range-filter');
        document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));

        if (view === 'OVERVIEW') {
            navOverview.classList.add('active');
            dateFilter.classList.add('show-filter');
            renderOverviewDashboard();
        } else if (view === 'CAMPAIGNS') {
            navCampaigns.classList.add('active');
            dateFilter.classList.add('show-filter'); // Dates are essential for retrieving the insights range
            selectedCampaignId = null;
            renderCampaignOverview();
            if(campaignData.length === 0) fetchCampaignData();
        } else if (view === 'CONTENT') {
             navContent.classList.add('active');
             dateFilter.classList.add('show-filter');
             renderContentView();
        } else if (view === 'AUDIENCE') {
             navAudience.classList.add('active');
             dateFilter.classList.remove('show-filter'); // Demographics are usually lifetime or specific snapshots
             renderAudienceView();
        } else if (view === 'SETTINGS') {
            navSettings.classList.add('active');
            dateFilter.classList.remove('show-filter');
            selectedCampaignId = null;
            // Redirect to settings page instead of rendering inline to handle multi-client better
            window.location.href = 'settings.php';
        } else if (view === 'DETAILS') {
            dateFilter.classList.add('show-filter');
            navCampaigns.classList.add('active'); // Keep campaigns active when viewing details
        }
    }

    // --- Rendering Modules ---
    
    /**
     * Renders Executive Summary Dashboard
     */
    async function renderOverviewDashboard() {
        const fbIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#1877F2" style="flex-shrink:0;vertical-align:middle;margin-right:2px;"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>`;
        const igIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#E1306C" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;vertical-align:middle;margin-right:2px;"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>`;

        contentBody.innerHTML = `
            <div style="display: flex; justify-content: center; align-items: center; height: 300px; flex-direction: column; gap: 1rem;">
                <div class="logo-icon" style="width: 48px; height: 48px; border: 4px solid #f1f5f9; border-top-color: var(--accent-blue); border-radius: 50%; animation: spin 1s infinite linear;"></div>
                <p style="color: var(--text-secondary); font-weight: 600; font-size: 1.1rem;">Fetching unified platform insights...</p>
            </div>
        `;

        try {
            const since = startDateInput.value;
            const until = endDateInput.value;
            const res = await fetch(`api/fetch_overview.php?since=${since}&until=${until}`);
            const data = await res.json();

            if (data.error) {
                contentBody.innerHTML = `<div style="padding: 2rem; color: #ef4444;">Error: ${data.message} <br> <a href="settings.php">Configure API</a></div>`;
                return;
            }

            contentBody.innerHTML = `
                <div class="campaign-header">
                    <div class="tag">EXECUTIVE SUMMARY</div>
                    <h1>Platform Overview</h1>
                    <p style="color: var(--text-secondary); font-size: 1.1rem;">Combined organic performance for Facebook and Instagram.</p>
                </div>
                
                <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
                    <div class="card" style="border-left: 4px solid var(--accent-blue);">
                        <div class="card-label">Total Followers</div>
                        <div class="card-value">${Number(data.total.followers).toLocaleString()}</div>
                        <div class="card-trend trend-up">↑ ${Number(data.total.new_followers).toLocaleString()} New</div>
                    </div>
                    <div class="card">
                        <div class="card-label">Combined Reach</div>
                        <div class="card-value">${Number(data.total.reach).toLocaleString()}</div>
                        <div style="display:flex; justify-content: space-between; align-items: center; font-size: 0.8rem; color: var(--text-secondary); margin-top: 1rem;">
                            <span style="display:inline-flex; align-items: center; gap: 2px;">${fbIcon}${Number(data.facebook.reach).toLocaleString()}</span>
                            <span style="display:inline-flex; align-items: center; gap: 2px;">${igIcon}${Number(data.instagram.reach).toLocaleString()}</span>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-label">Profile / Page Views</div>
                        <div class="card-value">${Number(data.total.views).toLocaleString()}</div>
                        <div style="display:flex; justify-content: space-between; align-items: center; font-size: 0.8rem; color: var(--text-secondary); margin-top: 1rem;">
                            <span style="display:inline-flex; align-items: center; gap: 2px;">${fbIcon}${Number(data.facebook.views).toLocaleString()}</span>
                            <span style="display:inline-flex; align-items: center; gap: 2px;">${igIcon}${Number(data.instagram.views).toLocaleString()}</span>
                        </div>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem; margin-top: 2rem;">
                    <div class="card" style="padding: 2rem;">
                        <h3 style="margin-bottom: 1.5rem; color: var(--text-primary);">Audience Growth</h3>
                        <canvas id="growthChart" height="200"></canvas>
                    </div>

                </div>
            `;
            
            // Render placeholder chart for growth
            const ctx = document.getElementById('growthChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                    datasets: [{
                        label: 'Total Followers',
                        data: [data.total.followers - 20, data.total.followers - 10, data.total.followers - 5, data.total.followers],
                        borderColor: '#2563eb',
                        tension: 0.4
                    }]
                },
                options: { responsive: true, plugins: { legend: { display: false } } }
            });

        } catch (err) {
             contentBody.innerHTML = '<div style="padding: 2rem; color: #ef4444;">Failed to load overview data.</div>';
        }
    }

    /**
     * Renders the Grid of Campaigns
     */
    function renderCampaignOverview() {
        contentBody.innerHTML = `
            <div class="campaign-header">
                <div class="tag">OVERVIEW</div>
                <h1>All Campaigns</h1>
                <p style="color: var(--text-secondary); font-size: 1.1rem;">Manage and monitor all your Facebook advertising campaigns.</p>
                
                <div class="filter-header" style="margin-top: 2rem;">
                    <div class="filter-options" style="padding: 0; width: 300px;">
                        <button class="filter-btn ${currentFilter === 'ALL' ? 'active' : ''}" data-filter="ALL">All</button>
                        <button class="filter-btn ${currentFilter === 'ACTIVE' ? 'active' : ''}" data-filter="ACTIVE">Active</button>
                        <button class="filter-btn ${currentFilter === 'PAUSED' ? 'active' : ''}" data-filter="PAUSED">Paused</button>
                    </div>
                </div>
            </div>
            <ul id="campaign-list">
                <!-- Data injected here -->
            </ul>
        `;

        // Filter Action Listeners
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                currentFilter = btn.dataset.filter;
                renderCampaignOverview();
            });
        });

        const listContainer = document.getElementById('campaign-list');
        const filteredData = campaignData.filter(c =>
            currentFilter === 'ALL' || c.status === currentFilter
        );

        filteredData.forEach((campaign) => {
            let totalLeads = 0;
            let totalImpressions = 0;
            let engagementReach = 0;

            if (campaign.insights) {
                totalImpressions = campaign.insights.impressions || 0;
                engagementReach = campaign.insights.reach || 0;
                
                if (campaign.insights.actions) {
                    const leadAction = campaign.insights.actions.find(a => 
                        a.action_type === 'lead' || 
                        a.action_type === 'onsite_conversion.lead_grouped' || 
                        a.action_type === 'lead_generation_tax'
                    );
                    if (leadAction) {
                        totalLeads = parseInt(leadAction.value) || 0;
                    }
                }
            }

            const li = document.createElement('li');
            li.innerHTML = `
                <div class="campaign-status">
                    <span class="dot ${campaign.status === 'ACTIVE' ? 'green' : 'yellow'}"></span>
                    ${campaign.status}
                </div>
                <span class="campaign-name">${campaign.name}</span>
                <div style="font-size: 0.95rem; color: var(--text-secondary); margin-top: auto;">
                    Objective: ${campaign.objective.replace('OUTCOME_', '')}
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 1rem; border-top: 1px solid var(--glass-border); padding-top: 1rem; width: 100%;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                        <span style="color: var(--text-secondary);">Total Leads:</span>
                        <span style="font-weight: 700; color: var(--accent-blue);">${Number(totalLeads).toLocaleString()}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                        <span style="color: var(--text-secondary);">Impressions:</span>
                        <span style="font-weight: 700; color: var(--text-primary);">${Number(totalImpressions).toLocaleString()}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                        <span style="color: var(--text-secondary);">Engagement Reach:</span>
                        <span style="font-weight: 700; color: var(--text-primary);">${Number(engagementReach).toLocaleString()}</span>
                    </div>
                </div>
                <button class="filter-btn active" style="margin-top: 1rem; width: 100%; padding: 0.6rem 1.2rem; font-size: 0.95rem;">View Analytics</button>
            `;
            li.addEventListener('click', () => renderCampaignDetails(campaign));
            listContainer.appendChild(li);
        });

        if (filteredData.length === 0) {
            listContainer.innerHTML = '<div style="padding: 2rem; color: var(--text-secondary); text-align: center; grid-column: 1/-1; font-size: 1.1rem; font-weight: 500;">No campaigns found matching this filter.</div>';
        }
    }

    /**
     * Renders Content Performance View
     */
    async function renderContentView() {
        contentBody.innerHTML = `
            <div style="display: flex; justify-content: center; align-items: center; height: 300px; flex-direction: column; gap: 1rem;">
                <div class="logo-icon" style="width: 48px; height: 48px; border: 4px solid #f1f5f9; border-top-color: var(--accent-blue); border-radius: 50%; animation: spin 1s infinite linear;"></div>
                <p style="color: var(--text-secondary); font-weight: 600; font-size: 1.1rem;">Fetching content performance data...</p>
            </div>
        `;

        try {
            const res = await fetch(`api/fetch_content.php`);
            const data = await res.json();

            if (data.error) {
                 contentBody.innerHTML = `<div style="padding: 2rem; color: #ef4444;">Error: API not configured. <br> <a href="settings.php">Configure API</a></div>`;
                 return;
            }

            // Calculate content type distribution
            let typeCounts = { 'Post': 0, 'Reel': 0, 'Carousel': 0, 'Video': 0 };
            data.forEach(item => {
                if (typeCounts[item.display_type] !== undefined) {
                    typeCounts[item.display_type]++;
                }
            });

            const labels = Object.keys(typeCounts).filter(k => typeCounts[k] > 0);
            const values = labels.map(k => typeCounts[k]);

            let html = `
                <div class="campaign-header">
                    <div class="tag">CONTENT</div>
                    <h1>Content Performance</h1>
                    <p style="color: var(--text-secondary); font-size: 1.1rem;">Top performing posts, reels, and stories based on reach and engagement.</p>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem; margin-bottom: 2rem;">
                     <div class="card" style="padding: 2rem;">
                         <h3 style="margin-bottom: 1rem; color: var(--text-primary); text-align: center;">Content Types</h3>
                         <div style="position: relative; height: 200px; width: 100%; display: flex; justify-content: center;">
                             <canvas id="contentTypeChart"></canvas>
                         </div>
                     </div>
                     <div class="card" style="padding: 2rem; display: flex; flex-direction: column; justify-content: center;">
                         <h3 style="margin-bottom: 1rem; color: var(--text-primary);">Performance Insights</h3>
                         <p style="color: var(--text-secondary); line-height: 1.6;">
                            Analyzed <strong>${data.length}</strong> total pieces of content across platforms.
                         </p>
                         <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                             <div style="flex: 1; background: #f8fafc; padding: 1rem; border-radius: 12px; text-align: center;">
                                 <div style="font-size: 1.5rem; font-weight: 700; color: var(--accent-blue);">${Math.max(0, ...data.map(d => d.views)).toLocaleString()}</div>
                                 <div style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 700;">Top Views</div>
                             </div>
                             <div style="flex: 1; background: #f8fafc; padding: 1rem; border-radius: 12px; text-align: center;">
                                 <div style="font-size: 1.5rem; font-weight: 700; color: #ec4899;">${Math.max(0, ...data.map(d => d.total_engagement)).toLocaleString()}</div>
                                 <div style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 700;">Top Engagement</div>
                             </div>
                         </div>
                     </div>
                </div>
                
                <h2 style="font-size: 1.5rem; color: var(--text-primary); margin-bottom: 1rem;">Top Content</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;">
            `;

            data.slice(0, 12).forEach((item, index) => {
                const icon = item.platform === 'instagram' ? '📸' : '📘';
                const date = new Date(item.timestamp).toLocaleDateString();
                const captionSnippet = item.caption ? (item.caption.length > 60 ? item.caption.substring(0, 60) + '...' : item.caption) : 'No caption';
                
                html += `
                    <div class="card" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <span style="font-size: 1.5rem;">${icon}</span>
                                <span style="font-size: 0.8rem; font-weight: 700; background: #f1f5f9; padding: 0.2rem 0.6rem; border-radius: 20px; color: var(--text-secondary); text-transform: uppercase;">${item.display_type}</span>
                            </div>
                            <div style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary);">#${index + 1}</div>
                        </div>
                        
                        <p style="font-size: 0.9rem; color: var(--text-primary); line-height: 1.5; flex-grow: 1;">"${captionSnippet}"</p>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; border-top: 1px solid var(--glass-border); padding-top: 1rem;">
                            <div>
                                <div style="font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 700;">Views</div>
                                <div style="font-size: 1.1rem; font-weight: 700;">${Number(item.views || item.reach).toLocaleString()}</div>
                            </div>
                            <div>
                                <div style="font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 700;">Engagement</div>
                                <div style="font-size: 1.1rem; font-weight: 700;">${Number(item.total_engagement).toLocaleString()}</div>
                            </div>
                        </div>
                        <a href="${item.url}" target="_blank" style="text-align: center; display: block; font-size: 0.85rem; color: var(--accent-blue); text-decoration: none; font-weight: 600; margin-top: 0.5rem;">View Original ↗</a>
                    </div>
                `;
            });

            html += `</div>`;
            contentBody.innerHTML = html;

            if (labels.length > 0) {
                 const ctx = document.getElementById('contentTypeChart').getContext('2d');
                 new Chart(ctx, {
                     type: 'doughnut',
                     data: {
                         labels: labels,
                         datasets: [{
                             data: values,
                             backgroundColor: ['#3b82f6', '#ec4899', '#f59e0b', '#10b981'],
                             borderWidth: 0
                         }]
                     },
                     options: { 
                         responsive: true, 
                         maintainAspectRatio: false,
                         plugins: { 
                             legend: { position: 'bottom', labels: { boxWidth: 12, font: { family: 'Plus Jakarta Sans' } } } 
                         },
                         cutout: '70%'
                     }
                 });
            }

        } catch (err) {
             console.error(err);
             contentBody.innerHTML = '<div style="padding: 2rem; color: #ef4444;">Failed to load content data.</div>';
        }
    }

    /**
     * Renders Audience Demographics View
     */
    async function renderAudienceView() {
        contentBody.innerHTML = `
            <div style="display: flex; justify-content: center; align-items: center; height: 300px; flex-direction: column; gap: 1rem;">
                <div class="logo-icon" style="width: 48px; height: 48px; border: 4px solid #f1f5f9; border-top-color: var(--accent-blue); border-radius: 50%; animation: spin 1s infinite linear;"></div>
                <p style="color: var(--text-secondary); font-weight: 600; font-size: 1.1rem;">Loading demographic insights...</p>
            </div>
        `;

        try {
            const res = await fetch(`api/fetch_demographics.php`);
            const data = await res.json();

            if (data.error) {
                 contentBody.innerHTML = `<div style="padding: 2rem; color: #ef4444;">Error: API not configured. <br> <a href="settings.php">Configure API</a></div>`;
                 return;
            }

            // Process data for charts
            // This is a simplified processing for the demo
            let fbMen = 0, fbWomen = 0, igMen = 0, igWomen = 0;
            
            if(data.facebook && data.facebook.gender_age) {
                Object.entries(data.facebook.gender_age).forEach(([key, value]) => {
                    if (key.startsWith('M')) fbMen += value;
                    if (key.startsWith('F')) fbWomen += value;
                });
            }
            if(data.instagram && data.instagram.gender_age) {
                Object.entries(data.instagram.gender_age).forEach(([key, value]) => {
                    if (key.startsWith('M')) igMen += value;
                    if (key.startsWith('F')) igWomen += value;
                });
            }

            let fbTotal = fbMen + fbWomen || 1; // avoid div/0
            let igTotal = igMen + igWomen || 1;

            let fbMenPct = Math.round((fbMen / fbTotal) * 100);
            let fbWomenPct = Math.round((fbWomen / fbTotal) * 100);
            let igMenPct = Math.round((igMen / igTotal) * 100);
            let igWomenPct = Math.round((igWomen / igTotal) * 100);

            let html = `
                <div class="campaign-header">
                    <div class="tag">AUDIENCE</div>
                    <h1>Demographics</h1>
                    <p style="color: var(--text-secondary); font-size: 1.1rem;">Understand who is engaging with your content across platforms.</p>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                     <div class="card" style="padding: 2rem;">
                         <h3 style="margin-bottom: 1rem; color: var(--text-primary); text-align: center;">Facebook Gender Split</h3>
                         <div style="position: relative; height: 200px; width: 100%; display: flex; justify-content: center;">
                             <canvas id="fbGenderChart"></canvas>
                         </div>
                         <div style="text-align: center; margin-top: 1rem;">
                             <span style="font-weight: 700; color: #3b82f6;">${fbMenPct}% Men</span> &bull; 
                             <span style="font-weight: 700; color: #ec4899;">${fbWomenPct}% Women</span>
                         </div>
                     </div>
                     <div class="card" style="padding: 2rem;">
                         <h3 style="margin-bottom: 1rem; color: var(--text-primary); text-align: center;">Instagram Gender Split</h3>
                         <div style="position: relative; height: 200px; width: 100%; display: flex; justify-content: center;">
                             <canvas id="igGenderChart"></canvas>
                         </div>
                         <div style="text-align: center; margin-top: 1rem;">
                             <span style="font-weight: 700; color: #3b82f6;">${igMenPct}% Men</span> &bull; 
                             <span style="font-weight: 700; color: #ec4899;">${igWomenPct}% Women</span>
                         </div>
                     </div>
                </div>
            `;

            contentBody.innerHTML = html;

            // Render Charts
            const fbCtx = document.getElementById('fbGenderChart').getContext('2d');
            new Chart(fbCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Men', 'Women', 'Other'],
                    datasets: [{
                        data: [fbMen, fbWomen, fbTotal - fbMen - fbWomen],
                        backgroundColor: ['#3b82f6', '#ec4899', '#cbd5e1'],
                        borderWidth: 0
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, cutout: '70%' }
            });

            const igCtx = document.getElementById('igGenderChart').getContext('2d');
            new Chart(igCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Men', 'Women', 'Other'],
                    datasets: [{
                        data: [igMen, igWomen, igTotal - igMen - igWomen],
                        backgroundColor: ['#3b82f6', '#ec4899', '#cbd5e1'],
                        borderWidth: 0
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, cutout: '70%' }
            });

        } catch (err) {
             console.error(err);
             contentBody.innerHTML = '<div style="padding: 2rem; color: #ef4444;">Failed to load audience data.</div>';
        }
    }



    /**
     * Renders API Configuration View
     */
    async function renderSettingsView() {
        contentBody.innerHTML = `
            <div class="campaign-header">
                <div class="tag">SYSTEM</div>
                <h1>API Configuration</h1>
                <p style="color: var(--text-secondary); font-size: 1.1rem;">Manage your Facebook Graph API credentials and ad account settings.</p>
            </div>
            
            <div class="card" style="max-width: 600px; margin-top: 1.5rem;">
                <form id="settings-form">
                    <div style="margin-bottom: 1.25rem;">
                        <label style="display:block; margin-bottom: 0.25rem; font-weight:700; font-size:0.85rem; color:var(--text-secondary); text-transform: uppercase;">Account Name</label>
                        <input type="text" id="set-name" style="width:100%; padding:0.85rem 1rem; border-radius:12px; border:1px solid var(--glass-border); outline:none; font-size: 0.95rem;" placeholder="Loading...">
                    </div>
                    <div style="margin-bottom: 1.25rem;">
                        <label style="display:block; margin-bottom: 0.25rem; font-weight:700; font-size:0.85rem; color:var(--text-secondary); text-transform: uppercase;">Access Token</label>
                        <input type="password" id="set-token" style="width:100%; padding:0.85rem 1rem; border-radius:12px; border:1px solid var(--glass-border); outline:none; font-size: 0.95rem;" placeholder="Loading...">
                    </div>
                    <div style="margin-bottom: 1.25rem;">
                        <label style="display:block; margin-bottom: 0.25rem; font-weight:700; font-size:0.85rem; color:var(--text-secondary); text-transform: uppercase;">Ad Account ID</label>
                        <div style="font-size:0.75rem; color:#94a3b8; margin-bottom:0.4rem;">Format: act_xxxxxxxxxxxxxxx</div>
                        <input type="text" id="set-account" style="width:100%; padding:0.85rem 1rem; border-radius:12px; border:1px solid var(--glass-border); outline:none; font-size: 0.95rem;" placeholder="Loading...">
                    </div>
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display:block; margin-bottom: 0.25rem; font-weight:700; font-size:0.85rem; color:var(--text-secondary); text-transform: uppercase;">Facebook Page ID</label>
                        <div style="font-size:0.75rem; color:#94a3b8; margin-bottom:0.4rem;">The numeric ID of the Page running lead ads</div>
                        <input type="text" id="set-pageid" style="width:100%; padding:0.85rem 1rem; border-radius:12px; border:1px solid var(--glass-border); outline:none; font-size: 0.95rem;" placeholder="Loading...">
                    </div>
                    <button type="submit" class="filter-btn active" style="width:fit-content; padding:0.85rem 2rem; font-size:0.95rem; font-weight: 700; border-radius: 12px; background: linear-gradient(135deg, var(--accent-blue), var(--accent-purple)); color: white; border: none;">SAVE SETTINGS</button>
                    <div id="settings-msg" style="margin-top: 1rem; text-align:center; font-weight:600; display:none;"></div>
                </form>
            </div>
        `;

        try {
            const res = await fetch('includes/get_settings.php');
            const config = await res.json();
            document.getElementById('set-name').value = config.account_name || '';
            document.getElementById('set-token').value = config.access_token;
            document.getElementById('set-account').value = config.ad_account_id;
            document.getElementById('set-pageid').value = config.page_id || '';
        } catch (e) {
            console.error('Failed to load settings');
        }

        document.getElementById('settings-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = e.target.querySelector('button');
            const msg = document.getElementById('settings-msg');
            btn.textContent = 'SAVING...';

            const formData = new FormData();
            formData.append('account_name', document.getElementById('set-name').value);
            formData.append('access_token', document.getElementById('set-token').value);
            formData.append('ad_account_id', document.getElementById('set-account').value);
            formData.append('page_id', document.getElementById('set-pageid').value);

            try {
                await fetch('settings.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                msg.textContent = 'Settings saved successfully!';
                msg.style.color = 'var(--success)';
                msg.style.display = 'block';
            } catch (err) {
                msg.textContent = 'Failed to save settings.';
                msg.style.color = 'red';
                msg.style.display = 'block';
            }
            btn.textContent = 'SAVE SETTINGS';
        });
    }

    /**
     * Renders Individual Campaign Analytics Dashboard
     */
    function renderCampaignDetails(campaign) {
        let insightsHTML = '';

        if (campaign.insights) {
            const freq = (campaign.insights.impressions / campaign.insights.reach).toFixed(1);

            // Calculate Results based on Campaign Objective with Priority Mapping
            let resultsValue = 0;
            let resultsLabel = 'Results';

            if (campaign.insights.actions) {
                const objective = campaign.objective || '';
                const actions = campaign.insights.actions;
                
                // Priority mappings for different objectives
                const mappings = {
                    'LEAD': ['lead', 'onsite_conversion.lead_grouped', 'lead_generation_tax'],
                    'TRAFFIC': ['link_click', 'post_engagement'],
                    'LINK_CLICKS': ['link_click'],
                    'ENGAGEMENT': ['post_engagement', 'post_reaction', 'page_like'],
                    'SALES': ['purchase', 'offsite_conversion.fb_pixel_purchase', 'add_to_cart'],
                    'CONVERSIONS': ['offsite_conversion.fb_pixel_purchase', 'purchase']
                };

                // Find the best single match for the objective to avoid double-counting
                let foundMatch = false;
                for (const [key, types] of Object.entries(mappings)) {
                    if (objective.includes(key)) {
                        const bestType = types.find(t => actions.some(a => a.action_type === t));
                        if (bestType) {
                            const actionData = actions.find(a => a.action_type === bestType);
                            resultsValue = parseInt(actionData.value) || 0;
                            resultsLabel = bestType.split('.').pop().replace(/_/g, ' ').toUpperCase();
                            foundMatch = true;
                            break;
                        }
                    }
                }

                if (!foundMatch && actions.length > 0) {
                    resultsValue = parseInt(actions[0].value) || 0;
                    resultsLabel = actions[0].action_type.split('.').pop().replace(/_/g, ' ').toUpperCase();
                }
            }

            insightsHTML = `
                <div class="stats-grid">
                    <div class="card">
                        <div class="card-label">Total ${resultsLabel}</div>
                        <div class="card-value">${resultsValue.toLocaleString()}</div>
                        <div class="card-trend trend-up">↑ Primary Metric</div>
                    </div>
                    <div class="card">
                        <div class="card-label">Total Impressions</div>
                        <div class="card-value">${Number(campaign.insights.impressions).toLocaleString()}</div>
                    </div>
                    <div class="card">
                        <div class="card-label">Engagement Reach</div>
                        <div class="card-value">${Number(campaign.insights.reach).toLocaleString()}</div>
                    </div>
                    <div class="card">
                        <div class="card-label">Avg. Frequency</div>
                        <div class="card-value">${freq}x</div>
                    </div>
                </div>
                <div class="card insights-chart" style="padding: 2.5rem;">
                    <div class="card-label" style="margin-bottom: 2rem; font-size: 0.95rem;">Efficiency Performance Timeline</div>
                    <canvas id="performanceChart" height="120"></canvas>
                </div>
            `;
        } else {
            insightsHTML = `
                <div class="welcome-screen">
                    <div class="logo-icon" style="margin-bottom: 2rem; width: 64px; height: 64px;"></div>
                    <h2 style="font-size: 1.8rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--text-primary);">No insights for this period</h2>
                    <p style="color: var(--text-secondary); max-width: 400px; margin: 1rem auto; font-size: 1.1rem; line-height: 1.6;">
                        Performance data is not available for the selected date range. 
                        Try selecting a broader range or ensure the campaign was active then.
                    </p>
                </div>
            `;
        }

        selectedCampaignId = campaign.id;
        switchView('DETAILS');

        contentBody.innerHTML = `
            <div class="campaign-header">
                <div class="tag">${campaign.objective.replace('OUTCOME_', '')}</div>
                <h1 style="font-size: 3.2rem; margin: 1rem 0;">${campaign.name}</h1>
                <p style="color: var(--text-secondary); font-size: 1.1rem;">ID: ${campaign.id} ${campaign.insights ? ` &bull; Period: ${campaign.insights.date_start} to ${campaign.insights.date_stop}` : ''}</p>
            </div>
            ${insightsHTML}
            
            <div id="leads-container" style="margin-top: 3rem;">
                <div class="card" style="padding: 2.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <div class="card-label">Individual Lead Data</div>
                            <h2 style="font-size: 1.5rem; color: var(--text-primary); margin-top: 0.5rem;">
                                Recent Leads <span id="leads-count-badge" style="font-size: 0.9rem; background: var(--accent-blue); color: white; padding: 0.2rem 0.6rem; border-radius: 20px; margin-left: 0.5rem; display: none;">0</span>
                            </h2>
                        </div>
                        <div style="display: flex; gap: 1rem;">
                            <button id="download-leads-btn" class="filter-btn" style="width: auto; padding: 0.6rem 1.5rem; display: none; border-color: var(--success); color: var(--success);">Excel Download</button>
                            <button id="load-leads-btn" class="filter-btn active" style="width: auto; padding: 0.6rem 1.5rem;">📥 Load Details</button>
                        </div>
                    </div>
                    <div id="leads-content">
                        <p style="color: var(--text-secondary); text-align: center; padding: 2rem;">Click "Load Details" to fetch individual lead information from Facebook.</p>
                    </div>
                </div>
            </div>
        `;

        if (campaign.insights) initChart();

        // Lead Details Listener
        document.getElementById('load-leads-btn').addEventListener('click', () => fetchLeadDetails(campaign.id));
    }

    /**
     * Fetches and renders individual lead details
     */
    async function fetchLeadDetails(campaignId) {
        const leadsContent = document.getElementById('leads-content');
        const btn = document.getElementById('load-leads-btn');
        const downloadBtn = document.getElementById('download-leads-btn');
        const countBadge = document.getElementById('leads-count-badge');
        
        btn.disabled = true;
        btn.textContent = '🔄 Loading...';
        downloadBtn.style.display = 'none';
        countBadge.style.display = 'none';
        leadsContent.innerHTML = '<div style="text-align: center; padding: 3rem;"><div class="logo-icon" style="width: 32px; height: 32px; margin: 0 auto 1rem auto; animation: spin 1s infinite linear;"></div><p>Fetching secure lead data...</p></div>';

        try {
            const res = await fetch(`api/fetch_leads.php?campaign_id=${campaignId}`);
            const leads = await res.json();

            if (leads.error) {
                leadsContent.innerHTML = `<div style="color: #ef4444; padding: 2rem; text-align: center; font-weight: 600;">Error: ${leads.error}</div>`;
            } else if (leads.length === 0) {
                leadsContent.innerHTML = '<p style="color: var(--text-secondary); text-align: center; padding: 2rem;">No individual lead data found for this campaign in the current period.</p>';
            } else {
                // Update Badge
                countBadge.textContent = leads.length;
                countBadge.style.display = 'inline-block';
                
                // Show Download Button
                downloadBtn.style.display = 'block';
                downloadBtn.onclick = () => downloadLeadsAsCSV(leads, `leads_${campaignId}.csv`);

                // Determine headers (dynamic based on form fields)
                const excludedKeys = ['id', 'created_time', 'ad_name'];
                const dynamicHeaders = Object.keys(leads[0]).filter(k => !excludedKeys.includes(k));
                
                let tableHTML = `
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; min-width: 600px;">
                            <thead>
                                <tr style="border-bottom: 2px solid var(--glass-border);">
                                    <th style="text-align: left; padding: 1rem; font-size: 0.85rem; color: var(--text-secondary); text-transform: uppercase;">Date</th>
                                    ${dynamicHeaders.map(h => `<th style="text-align: left; padding: 1rem; font-size: 0.85rem; color: var(--text-secondary); text-transform: uppercase;">${h.replace(/_/g, ' ')}</th>`).join('')}
                                    <th style="text-align: left; padding: 1rem; font-size: 0.85rem; color: var(--text-secondary); text-transform: uppercase;">Ad Name</th>
                                </tr>
                            </thead>
                            <tbody>
                `;

                leads.forEach(lead => {
                    const date = new Date(lead.created_time).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                    tableHTML += `
                        <tr style="border-bottom: 1px solid var(--glass-border); transition: background 0.2s;" onmouseover="this.style.background='rgba(37, 99, 235, 0.02)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 1rem; font-size: 0.95rem; font-weight: 500;">${date}</td>
                            ${dynamicHeaders.map(h => `<td style="padding: 1rem; font-size: 0.95rem;">${lead[h] || 'N/A'}</td>`).join('')}
                            <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-secondary);">${lead.ad_name}</td>
                        </tr>
                    `;
                });

                tableHTML += '</tbody></table></div>';
                leadsContent.innerHTML = tableHTML;
            }
        } catch (err) {
            console.error(err);
            leadsContent.innerHTML = '<div style="color: #ef4444; padding: 2rem; text-align: center;">Failed to load leads. Please check your API permissions.</div>';
        }

        btn.disabled = false;
        btn.textContent = '📥 Refresh Details';
    }

    /**
     * Converts lead data to CSV and triggers download
     */
    function downloadLeadsAsCSV(data, filename) {
        if (!data || !data.length) return;
        
        const headers = Object.keys(data[0]);
        const csvRows = [];
        
        // Add Header Row
        csvRows.push(headers.map(h => `"${h.replace(/"/g, '""')}"`).join(','));
        
        // Add Data Rows
        data.forEach(row => {
            const values = headers.map(h => {
                const val = row[h] || '';
                return `"${val.toString().replace(/"/g, '""')}"`;
            });
            csvRows.push(values.join(','));
        });
        
        const csvString = csvRows.join('\n');
        const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement("a");
        
        if (link.download !== undefined) {
            const url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }

    /**
     * Initialize Chart.js Component
     */
    function initChart() {
        const ctx = document.getElementById('performanceChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                datasets: [{
                    label: 'Efficiency Trend',
                    data: [12000, 19000, 15000, 25000],
                    borderColor: '#2563eb',
                    background: 'rgba(37, 99, 235, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { labels: { color: '#64748b', font: { family: 'Plus Jakarta Sans' } } }
                },
                scales: {
                    y: { grid: { color: 'rgba(0, 0, 0, 0.05)' }, ticks: { color: '#64748b' } },
                    x: { grid: { display: false }, ticks: { color: '#64748b' } }
                }
            }
        });
    }

    // Initial Fetch on Load
    fetchCampaignData();
});


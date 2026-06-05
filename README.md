# Social Media Analytics Dashboard

A lightweight, PHP-based marketing analytics dashboard designed to track Meta (Facebook & Instagram) ad campaigns, retrieve lead generation form data, analyze audiences, and generate reports.

## How It Works

This project connects directly to the **Facebook Graph API** to pull real-time data from Meta Ad Accounts and Facebook Pages. It features a custom PHP backend that interacts with the Graph API and a responsive frontend dashboard for visualizing the metrics.

The system performs the following core functions:
1. **Campaign Tracking:** Retrieves all active and paused campaigns, displaying high-level metrics like reach, impressions, frequency, and spend.
2. **Lead Discovery & Extraction:** Uses a multi-step discovery process to find lead generation forms tied to ad creatives, extracts the submitted lead data, and allows downloading as a CSV/Excel file.
3. **Insight Visualization:** Aggregates and charts key performance indicators (KPIs) over custom date ranges.
4. **Audience & Demographics:** Breaks down campaign reach by age, gender, and geographical location.
5. **Content Performance:** Tracks the engagement and performance of individual ad creatives, posts, reels, and stories.

## Required Details

To interact with the Facebook Graph API, the system requires the following configuration details from your Meta Business Manager and Developer App:

*   **Page Access Token:** An API token linked to the specific Facebook Page (Requires a Page Token, not a User Token).
*   **Ad Account ID:** The unique identifier for the Meta Ad Account (e.g., `act_XXXXXXXXXX`).
*   **Facebook Page ID:** The numeric ID of the Facebook Page running the campaigns.

### Required Meta App Permissions
Your Meta Developer App must be granted the following scopes:
*   `pages_show_list`
*   `ads_management`
*   `ads_read`
*   `business_management`
*   `leads_retrieval`
*   `pages_read_engagement`
*   `pages_manage_ads`

## API Endpoints Used

The backend relies on the following Facebook Graph API endpoints (v19.0 to v25.0) to fetch data:

| Endpoint | Purpose |
| :--- | :--- |
| `GET /{ad_account_id}/campaigns` | Lists all campaigns and their active/paused status. |
| `GET /{ad_account_id}/insights` | Aggregates KPIs like impressions, clicks, CTR, and spend. |
| `GET /{campaign_id}/ads` | Retrieves the specific ads running under a campaign. |
| `GET /{page_id}/leadgen_forms` | Retrieves all lead generation forms associated with a Page. |
| `GET /{ad_id}?fields=creative` | Parses ad creatives to locate attached lead form IDs. |
| `GET /{form_id}/leads` | Fetches the actual user-submitted lead data from a specific form. |

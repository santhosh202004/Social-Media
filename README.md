# AdMetrics — Facebook Campaign Lead Analytics Dashboard

A PHP-based web dashboard for fetching, viewing, and exporting Facebook Ad Campaign lead data using the Facebook Graph API.

---

## Table of Contents

1. [Overview](#overview)
2. [Project Structure](#project-structure)
3. [Prerequisites](#prerequisites)
4. [Installation & Setup](#installation--setup)
5. [Configuration](#configuration)
6. [Application Flow](#application-flow)
7. [API Endpoints](#api-endpoints)
8. [Database Schema](#database-schema)
9. [Frontend Architecture](#frontend-architecture)
10. [Facebook API Integration](#facebook-api-integration)
11. [Troubleshooting](#troubleshooting)

---

## Overview

AdMetrics connects to the **Facebook Graph API** to:
- List all campaigns (Active + Paused) for a given Ad Account.
- Display campaign-level metrics: impressions, reach, frequency, and primary results.
- Fetch individual lead data from Facebook Lead Gen Forms tied to each campaign's ads.
- Export leads as a downloadable CSV/Excel file.

---

## Project Structure

```
add_campign_test/
├── assets/
│   ├── css/
│   │   └── style.css              # Global design tokens & component styles
│   └── js/
│       └── dashboard.js           # Frontend logic: routing, rendering, API calls
├── includes/
│   ├── db_config.php              # PDO database connection (campaign_db)
│   ├── db_setup.php               # One-time DB & table creation script
│   └── get_settings.php           # API endpoint: returns saved credentials as JSON
├── fetch_leads.php                # API endpoint: fetches leads for a given campaign
├── index.php                      # API endpoint: fetches campaign list from Facebook
├── index_dashboard.html           # Main dashboard HTML (entry point for users)
├── settings.php                   # Standalone settings page (view + save credentials)
└── README.md                      # This documentation
```

---

## Prerequisites

| Requirement         | Version / Detail                          |
|---------------------|-------------------------------------------|
| **PHP**             | 7.4+ (with `pdo_mysql` extension enabled) |
| **MySQL/MariaDB**   | 5.7+ / 10.4+                             |
| **XAMPP**           | Any recent version (bundles PHP + MySQL)  |
| **Web Browser**     | Chrome, Edge, or Firefox (modern)         |
| **Facebook App**    | A Meta Developer App with appropriate permissions |

---

## Installation & Setup

### Step 1: Clone / Copy Files
Place the `add_campign_test` folder inside your XAMPP `htdocs` directory:
```
C:\xampp\htdocs\add_campign_test\
```

### Step 2: Start XAMPP
Start **Apache** and **MySQL** from the XAMPP Control Panel.

### Step 3: Initialize the Database
Open your browser and navigate to:
```
http://localhost/add_campign_test/includes/db_setup.php
```
This will:
- Create the `campaign_db` database (if it doesn't exist).
- Create the `facebook_config` table with all required columns.

You should see: `"Database and table created/updated successfully!"`

### Step 4: Configure API Credentials
Navigate to:
```
http://localhost/add_campign_test/settings.php
```
Fill in the four fields (see [Configuration](#configuration) below) and click **SAVE CONFIGURATION**.

### Step 5: Open the Dashboard
Navigate to:
```
http://localhost/add_campign_test/index_dashboard.html
```
Your campaigns should load automatically.

---

## Configuration

The following credentials are required and stored in the `facebook_config` database table:

| Field                | Where to Find It                                                                 |
|----------------------|----------------------------------------------------------------------------------|
| **Account Name**     | Any label you want (e.g., "Ctrl Next Technologies")                              |
| **Page Access Token**| [Graph API Explorer](https://developers.facebook.com/tools/explorer/) → Select your **Page** (not User) under "User or Page" → Generate Access Token |
| **Ad Account ID**    | Meta Business Suite → Settings → Ad Account → Copy the ID (format: `act_XXXXXXXXXX`) |
| **Facebook Page ID** | Your Facebook Page → About → Page Transparency → Page ID (numeric)               |

> **⚠️ Important:** The token MUST be a **Page Access Token**, not a User Access Token. The `/{page_id}/leadgen_forms` endpoint requires a Page-scoped token. If you see Error #190, your token is a User token.

### Required Facebook App Permissions

Your Facebook App must have these permissions granted:

- `pages_show_list`
- `ads_management`
- `ads_read`
- `business_management`
- `leads_retrieval`
- `pages_read_engagement`
- `pages_manage_ads`

---

## Application Flow

```
┌──────────────────────────────────────────────────────────────┐
│                    USER OPENS DASHBOARD                      │
│              index_dashboard.html                            │
└──────────────────┬───────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────┐
│  dashboard.js sends AJAX GET to index.php?json=1             │
│  (with optional ?since=YYYY-MM-DD&until=YYYY-MM-DD)         │
└──────────────────┬───────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────┐
│  index.php                                                   │
│  1. Reads credentials from DB (db_config.php)                │
│  2. Calls Facebook Graph API:                                │
│     GET /{ad_account_id}/campaigns                           │
│     ?fields=id,name,status,objective,insights{...}           │
│  3. Returns JSON array of campaigns with insights            │
└──────────────────┬───────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────┐
│  dashboard.js renders campaign cards in the UI               │
│  User clicks "View Analytics" on a campaign                  │
│  → Shows stats grid (Results, Impressions, Reach, Frequency) │
│  → Shows performance chart                                   │
│  → Shows "Load Details" button for leads                     │
└──────────────────┬───────────────────────────────────────────┘
                   │
                   ▼ (User clicks "Load Details")
┌──────────────────────────────────────────────────────────────┐
│  dashboard.js sends AJAX GET to                              │
│  fetch_leads.php?campaign_id={ID}                            │
└──────────────────┬───────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────┐
│  fetch_leads.php — Multi-Strategy Lead Discovery             │
│                                                              │
│  STEP 1: GET /{campaign_id}/ads                              │
│          → Collects all Ad IDs under this campaign           │
│                                                              │
│  STEP 2: GET /{page_id}/leadgen_forms  (PRIMARY)             │
│          → Fetches all lead forms from the Facebook Page      │
│          → If this works, skips Step 3                        │
│                                                              │
│  STEP 3: GET /{ad_id}?fields=creative{...}  (FALLBACK)      │
│          → Reads ad creative → extracts lead_gen_form_id      │
│          → Checks both asset_feed_spec (dynamic creative)     │
│            and object_story_spec (standard creative)          │
│                                                              │
│  STEP 4: GET /{form_id}/leads                                │
│          → Fetches actual lead records from each form         │
│          → Filters leads by campaign_id or ad_id match        │
│          → Flattens field_data into key-value pairs           │
│          → Sorts by created_time (newest first)               │
│                                                              │
│  Returns: JSON array of lead objects                          │
└──────────────────┬───────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────┐
│  dashboard.js renders:                                       │
│  - Lead count badge (e.g., "107")                            │
│  - Dynamic table with all form fields as columns             │
│  - "Excel Download" button → triggers CSV export             │
└──────────────────────────────────────────────────────────────┘
```

---

## API Endpoints

### `GET index.php?json=1`
Returns all campaigns for the configured Ad Account.

| Parameter | Required | Description |
|-----------|----------|-------------|
| `json`    | Yes      | Must be `1` to get JSON response |
| `since`   | No       | Start date filter (`YYYY-MM-DD`) |
| `until`   | No       | End date filter (`YYYY-MM-DD`)   |
| `debug`   | No       | Set to `1` for raw API debug output |

**Response:**
```json
[
  {
    "id": "120239881881900549",
    "name": "CN Job vacancy Leads campaign",
    "status": "ACTIVE",
    "objective": "OUTCOME_LEADS",
    "insights": {
      "impressions": "45230",
      "reach": "32100",
      "actions": [...],
      "date_start": "2025-01-01",
      "date_stop": "2026-04-03"
    }
  }
]
```

---

### `GET fetch_leads.php?campaign_id={ID}`
Returns individual lead records for a specific campaign.

| Parameter     | Required | Description |
|---------------|----------|-------------|
| `campaign_id` | Yes      | Facebook Campaign ID |
| `debug`       | No       | Set to `1` for detailed debug log |

**Response:**
```json
[
  {
    "id": "123982760090575",
    "created_time": "2026-04-01T10:30:00+0000",
    "ad_name": "CN Job Vacancy Ad",
    "full_name": "John Doe",
    "email": "john@example.com",
    "phone_number": "+919876543210"
  }
]
```

> Field keys are dynamically generated from the Lead Gen Form's `field_data`.

---

### `GET includes/get_settings.php`
Returns saved API credentials (used by the dashboard's inline settings form).

**Response:**
```json
{
  "access_token": "EAAVg...",
  "ad_account_id": "act_482545668044206",
  "account_name": "Ctrl Next Technologies",
  "page_id": "400232369832222"
}
```

---

### `POST settings.php`
Saves API credentials to the database.

| POST Field       | Required | Description |
|------------------|----------|-------------|
| `access_token`   | Yes      | Facebook Page Access Token |
| `ad_account_id`  | Yes      | Ad Account ID (`act_XXX`)  |
| `account_name`   | No       | Display label              |
| `page_id`        | No       | Facebook Page ID           |

---

## Database Schema

**Database:** `campaign_db`

**Table:** `facebook_config`

| Column         | Type         | Description                          |
|----------------|--------------|--------------------------------------|
| `id`           | INT (PK, AI) | Auto-increment primary key           |
| `access_token` | TEXT         | Facebook Page Access Token           |
| `ad_account_id`| VARCHAR(255) | Ad Account ID (e.g., `act_XXXX`)     |
| `account_name` | VARCHAR(255) | Display name for the account         |
| `page_id`      | VARCHAR(255) | Facebook Page ID (numeric)           |
| `updated_at`   | TIMESTAMP    | Auto-updated on every save           |

---

## Frontend Architecture

### `index_dashboard.html`
The single-page application shell. Contains:
- **Sidebar**: Navigation (Campaigns / Settings), user profile display.
- **Top Bar**: Date range filter inputs, account status badge.
- **Content Body**: Dynamic container rendered by `dashboard.js`.

### `dashboard.js` — Views

| View        | Trigger                          | What It Renders                              |
|-------------|----------------------------------|----------------------------------------------|
| **Campaigns** | Click "Campaigns" in sidebar   | Grid of campaign cards with status + objective |
| **Details**   | Click "View Analytics" on a card | Stats grid, performance chart, leads section |
| **Settings**  | Click "Settings" in sidebar    | Inline API configuration form                |

### Key Features
- **Status Filtering**: Filter campaigns by ALL / ACTIVE / PAUSED.
- **Date Range Filtering**: Custom from/to date pickers for insight data.
- **Lead Count Badge**: Shows total leads found (e.g., blue "107" badge).
- **Excel Export**: Downloads all loaded leads as a `.csv` file.
- **Dynamic Table Columns**: Columns are auto-generated from form field names.

---

## Facebook API Integration

### API Version
- Campaign listing: `v25.0`
- Lead retrieval: `v19.0`

### Endpoints Used

| Endpoint | Purpose |
|----------|---------|
| `GET /{ad_account_id}/campaigns` | List campaigns with insights |
| `GET /{campaign_id}/ads` | Get ads under a campaign |
| `GET /{page_id}/leadgen_forms` | Get all lead forms from a Page |
| `GET /{ad_id}?fields=creative{...}` | Get ad creative to extract form ID |
| `GET /{form_id}/leads` | Get actual lead submissions |

### Lead Form Discovery Strategy

The system uses a **multi-strategy approach** to find the Lead Gen Form ID:

1. **Page-Level (Primary)**: Calls `/{page_id}/leadgen_forms` — returns all forms on the Page. Requires a **Page Access Token**.
2. **Ad Creative (Fallback)**: If the Page endpoint fails, the system reads each ad's creative and extracts the `lead_gen_form_id` from:
   - `asset_feed_spec.call_to_actions[].value.lead_gen_form_id` (Dynamic Creative)
   - `object_story_spec.link_data.call_to_action.value.lead_gen_form_id` (Standard Creative)

### Lead Filtering

When leads are fetched from a form, they are filtered to only include leads belonging to the requested campaign:
1. If `campaign_id` exists in the lead object → direct match.
2. If not → check if the lead's `ad_id` is in the campaign's ad list.
3. If neither is available → include the lead (edge case for older forms).

---

## Troubleshooting

### Error: `(#190) This method must be called with a Page Access Token`
**Cause:** The stored token is a User Access Token, not a Page Access Token.
**Fix:** Go to [Graph API Explorer](https://developers.facebook.com/tools/explorer/), change the "User or Page" dropdown to your **Page name** (not your personal name), generate a new token, and save it in Settings.

### Error: `(#100) Tried accessing nonexisting field (lead_gen_form_id)`
**Cause:** This was a legacy issue where the code tried to access `lead_gen_form_id` as a top-level field on the creative. This has been fixed — the system now reads the full `asset_feed_spec` and `object_story_spec`.

### Leads return empty `[]`
**Possible causes:**
- The campaign does not use Lead Gen Forms (e.g., it's a Traffic or Engagement campaign).
- The Page Access Token doesn't have `leads_retrieval` permission.
- The token has expired — generate a new one from Graph API Explorer.

### Settings page fields not visible
Scroll down — all fields (Account Name, Access Token, Ad Account ID, Page ID) are present but the page may require scrolling on smaller screens.

### Debug Mode
Append `?debug=1` to any API endpoint for detailed logging:
- `index.php?debug=1` — Shows raw Facebook API request/response for campaigns.
- `fetch_leads.php?campaign_id=XXX&debug=1` — Shows step-by-step lead discovery log.

---

## Token Management

Facebook tokens expire periodically. When you notice campaigns or leads failing to load:

1. Go to [Graph API Explorer](https://developers.facebook.com/tools/explorer/)
2. Select your App → Select your **Page** under "User or Page"
3. Click **Generate Access Token**
4. Copy the new token → Paste into Settings → Save

> **Tip:** For long-lived tokens, use the [Access Token Debugger](https://developers.facebook.com/tools/debug/accesstoken/) to check expiry dates.

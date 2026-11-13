<?php
// Signal Desk (PHP): copy this file to config.php in the SAME folder and fill it in.
// config.php holds secrets: keep it in the private folder (outside the web root, or in _private/
// behind "Require all denied"). Never commit it, never put it in a zip, never paste it in chat.
// Environment variables with the same names override these values.
return [
    // production = writes to the master workbook (GRAPH_MASTER_ITEM_ID)
    // development = writes to the COPY (GRAPH_DEV_ITEM_ID); refuses to start if it equals the master
    'APP_ENV' => 'development',

    // graph = live Microsoft Graph; mock = fake in-memory data (refused when APP_ENV=production)
    'WORKBOOK_BACKEND' => 'mock',

    // Exact public URL of the app, for the Origin (CSRF) check. Required in production.
    'APP_ORIGIN' => 'https://signals.swooscale.com',

    // Used for "Date Verified" (today) on the Decision Makers tab.
    'TIMEZONE' => 'Europe/Brussels',

    // Entra ID app registration "Signal Desk" (client credentials, Sites.Selected).
    'AZURE_TENANT_ID' => '',
    'AZURE_CLIENT_ID' => '',
    'AZURE_CLIENT_SECRET' => '',   // the NEW, rotated secret; never the one pasted in chat

    // From `npm run find-ids` (see the main README).
    'GRAPH_DRIVE_ID' => '',
    'GRAPH_MASTER_ITEM_ID' => '',
    'GRAPH_DEV_ITEM_ID' => '',

    // ";"-separated entries of  email|Display Name|base64(bcrypt hash)
    // Create one with:  php bin/hash-password.php
    'AUTH_USERS' => '',

    // Optional: where storage lives. Default: storage/ next to this file.
    'STORAGE_DIR' => '',

    // Candidate Desk (Atlas, read-only). Create the key in Atlas: Settings -> Integrations -> API Keys.
    // The key "acts as its user": it sees that user's lists plus company-wide/public lists.
    // ATLAS_BACKEND: '' = on when ATLAS_API_KEY is set; 'mock' = fake candidates (refused in production); 'off'.
    'ATLAS_BACKEND' => '',
    'ATLAS_API_KEY' => '',
    'ATLAS_BASE_URL' => 'https://api.recruitwithatlas.com',
    'ATLAS_PROFILE_URL' => 'https://my.recruitwithatlas.com/people-v2?profile={id}',

    // Optional AI scoring for JD matching (Anthropic API). OFF by default: JD matching is rule-based and free.
    // To switch it on later: 'anthropic' plus a key from console.anthropic.com. 'mock' = fake AI (refused in production).
    // ANTHROPIC_MONTHLY_LIMIT_EUR: AI calls stop for the month once this is spent (default 50).
    'ANTHROPIC_BACKEND' => 'off',
    'ANTHROPIC_API_KEY' => '',
    'ANTHROPIC_MODEL' => 'claude-opus-5-5',
    'ANTHROPIC_MONTHLY_LIMIT_EUR' => '50',
    'ANTHROPIC_USD_TO_EUR' => '0.92',

    // "In the market" (Prospeo people search). Key: app.prospeo.io -> API. Searches cost 1 credit per page of 25,
    // revealing an email 1 credit, a mobile 10. PROSPEO_DAILY_CREDIT_CAP: credits the whole app may spend per day.
    // PROSPEO_BACKEND: '' = on when PROSPEO_API_KEY is set; 'mock' = fake people (refused in production); 'off'.
    'PROSPEO_BACKEND' => '',
    'PROSPEO_API_KEY' => '',
    'PROSPEO_DAILY_CREDIT_CAP' => '200',

    // lemlist (Outreach and Calls tabs). Key: lemlist -> Settings -> Integrations -> Generate a new API key.
    // LEMLIST_BACKEND: '' = on when LEMLIST_API_KEY is set; 'mock' = fake data (refused in production); 'off'.
    // LEMLIST_WEBHOOK_SECRET: a long random string (32+ characters) you make up; register it once with bin/lemlist-webhook.php --register.
    // LEMLIST_SHARED_SENDER: the lemlist user every allowed Signal Desk user sends/logs calls as (usr_…).
    // LEMLIST_SENDER_ALLOWED: comma-separated Signal Desk login emails allowed to send; everyone else can only view.
    // LEMLIST_USER_MAP: later, for separate lemlist users: {"login@email": {"userId": "usr_…", "email": "…", "mailboxId": "usm_…"}}
    // LEMLIST_ATTRIBUTION: first_positive (credit the channel of the first positive response) or all_touches.
    'LEMLIST_BACKEND' => '',
    'LEMLIST_API_KEY' => '',
    'LEMLIST_WEBHOOK_SECRET' => '',
    'LEMLIST_SHARED_SENDER' => 'usr_ppJJtGkzzXRXK7sxi',
    'LEMLIST_SENDER_ALLOWED' => '',
    'LEMLIST_USER_MAP' => '',
    'LEMLIST_ATTRIBUTION' => 'first_positive',
];

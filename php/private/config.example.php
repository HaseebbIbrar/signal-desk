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
];

# Deploy Signal Desk (PHP) on 20i: signals.swooscale.com

Plain PHP 8.1+. No build step and no Composer on the server. You upload two folders over SFTP:

| Folder in the zip | What it is | Where it goes |
|---|---|---|
| `public/` | the web root: `index.php`, `health.php`, `assets/`, `.htaccess` | the subdomain's **document root** |
| `private/` | `src/` (the app code), `config.example.php`, `bin/`, `storage/` | **outside** the web root if possible (layout A), otherwise inside it as `_private/` (layout B) |

Don't upload `tests/`, `README.md` or this file.

`private/` holds `config.php` (all secrets and settings) and `storage/` (the Microsoft token cache, sessions, login lockout, rejections and audit log). The app finds it automatically in either layout, and `/health.php` tells you which layout it found.

---

## 1. Find out what your SFTP login sees (layout A or B)

I couldn't confirm 20i's exact SFTP layout from their documentation, so check it yourself once:

1. In **My20i → Manage Hosting → (swooscale.com package) → FTP Details / FTP Accounts**, note the host, username and port. Use SFTP if it's offered, otherwise FTPS.
2. In **My20i → Manage Hosting → Subdomains**, check the **Document Root** of `signals.swooscale.com`, e.g. `public_html/signals`. 20i lets you set this; their docs use `public_html/<folder>` as the example. Write it down.
3. Connect with WinSCP or FileZilla. In FileZilla, turn on *Server → Force showing hidden files* so the `.htaccess` files are visible.
4. Look at the first listing after you log in:
   - **You see `public_html`** (and maybe other folders next to it): you're **above** the web root. Use **layout A**.
   - **You land inside the website files and can't go up**: use **layout B**.

## 2. Upload

**Layout A (preferred: secrets are outside the web root)**

```
/                               <- where SFTP lands
├── signal-desk-private/        <- upload the CONTENTS of private/ here (NOT inside public_html)
│   ├── .htaccess
│   ├── config.example.php
│   ├── config.php              <- you create this (step 3)
│   ├── bin/  src/
│   └── storage/  (.htaccess only)
└── public_html/
    └── signals/                <- the subdomain's document root: upload the CONTENTS of public/ here
        ├── .htaccess
        ├── index.php  health.php  locate.inc.php
        └── assets/  (app.css  extra.css  app.js)
```

The app looks for `signal-desk-private` one, two or three folders above the document root. Put it **next to** `public_html`, never inside it: `public_html` belongs to the main domain's website too.

**Layout B (fallback: everything inside the web root)**

```
(document root of signals.swooscale.com)
├── .htaccess  index.php  health.php  locate.inc.php  assets/
└── _private/                   <- upload the CONTENTS of private/ here
    ├── .htaccess               <- "Require all denied" (keep it!)
    ├── config.php  config.example.php  bin/  src/
    └── storage/  (.htaccess)
```

Both the root `.htaccess` and `_private/.htaccess` block `_private/` over HTTP. `/health.php` checks this by actually requesting those files and expecting a refusal.

Make sure the **hidden `.htaccess` files are uploaded** in both layouts.

## 3. Create `config.php` (on your own computer first)

1. Copy `private/config.example.php` to `config.php` in the same folder.
2. For the first test, leave `APP_ENV` = `development` and `WORKBOOK_BACKEND` = `mock`, and set `APP_ORIGIN` to `https://signals.swooscale.com`.
3. Create the login line for `AUTH_USERS`. This needs PHP on your computer, run from the `private/` folder:
   ```bash
   php bin/hash-password.php
   ```
   It asks for the email, name and password and prints `email|Name|base64hash`. Paste that whole line between the quotes. Join several users with `;`.

   **The existing `AUTH_USERS` line from the Vercel/Next.js setup works here unchanged**, because the format is the same and `$2b$` hashes are accepted.
4. Upload `config.php` into the private folder. Never put it in a zip, never commit it, and never paste it in chat.

`storage/` is created and filled by the app. If it isn't writable, set the folder to 700 (or 750) in your SFTP client.

## 4. PHP version

**My20i → Manage Hosting → PHP Version**: choose **PHP 8.1 or newer** (8.3 recommended). The app needs the `curl`, `openssl` and `json` extensions, which are standard on 20i. `/health.php` lists them.

## 5. SSL (HTTPS)

1. **DNS:** `swooscale.com` uses 20i's nameservers (`ns1–ns4.stackdns.com`), so creating the subdomain in My20i normally adds its DNS record automatically. Check in **My20i → Manage DNS** that `signals` points at the package.
   - The Vercel guide earlier suggested a CNAME for `signals` → Vercel. If you added that, **remove it**: the subdomain now lives on 20i.
2. **My20i → Manage Hosting → SSL/TLS → Free SSL** (Let's Encrypt): issue a certificate for `signals.swooscale.com`.
3. HTTPS is required, because the login cookie is `Secure`. The `.htaccess` already redirects `http://` to `https://`. If 20i offers a "Force HTTPS" switch, turning it on as well does no harm.

## 6. Mock-mode test checklist

Use fake data first; nothing touches SharePoint.

1. Open `https://signals.swooscale.com`. You should be sent to **/login**, with the yellow **MOCK DATA** banner.
2. Log in. Wrong passwords say "Invalid email or password." After 5 wrong tries the account is locked for 15 minutes.
3. Open **`/health.php`**. Every row should be **OK**, and it shows **layout A** or **layout B**. In layout B, it requests each private file over HTTP:
   - **OK**: the server answered **403 or 404** (blocked). This is the only result that proves a file is protected.
   - **FAIL**: the server answered **200** or returned the file's contents, so the file is **reachable**. Fix `.htaccess` before going any further.
   - **WARN**: no response, a timeout or any other status, so it **could not verify**. Open that URL in a browser yourself and confirm you get 403 or 404.
4. Try these URLs directly. Each must be **refused** (403/404 or the app's "Page not found"), never show file contents:
   - `/locate.inc.php`
   - `/_private/config.php` (layout B)
   - `/_private/storage/audit.log` (layout B)
5. **Signals:** tick one card to **verify** it ("Verified: added … (row N)"). Click **Reject** on another; it disappears. Turn on **Show rejected**, then **Undo reject**.
6. **Signals:** untick the card you verified. It asks, then removes it.
7. **Verified:** use the filters *Waiting for decision maker*, *Ready to review*, *Sent* and *All*. Cards show the decision maker's LinkedIn (M), name (T), title (U) and note (S).
8. **Verified:** paste `linkedin.com/in/test-person` into a waiting card and click **Save**. You should see "✓ Saved to master sheet", and the card moves to *Ready to review*. A `linkedin.com/company/...` link is refused.
9. **Verified:** **✓ Approve → Decision Makers** shows **Sent ✓ <today>**. **Undo** removes that row again, and Approve then works again.
10. To reset the fake data, delete `storage/mock-workbook.json`.

## 7. Switch to live

1. **Rotate the Azure client secret.** The old one was pasted in chat. In **Entra → App registrations → Signal Desk → Certificates & secrets → New client secret**, copy its **Value**, then **delete the old secret**.
2. **Prepare the workbooks:**
   - The master needs the new layout: verify tab R1:U1 = `Role Sub-Group`, `Decision maker note`, `Decision Maker Name`, `Decision Maker Title`, and the **Decision Makers** tab with its 17 headers.
   - **Re-copy the master over the DEV COPY**, so the copy has them too.
3. In `config.php`:
   ```php
   'WORKBOOK_BACKEND' => 'graph',
   'APP_ENV' => 'development',            // first on the DEV COPY
   'AZURE_TENANT_ID' => '…', 'AZURE_CLIENT_ID' => '…',
   'AZURE_CLIENT_SECRET' => '…',          // the NEW secret
   'GRAPH_DRIVE_ID' => '…', 'GRAPH_MASTER_ITEM_ID' => '…', 'GRAPH_DEV_ITEM_ID' => '…',
   ```
   The banner now says **DEV COPY**. Repeat checklist steps 5–9 against the DEV COPY in Excel.
4. Go live: set `'APP_ENV' => 'production'` (this needs `APP_ORIGIN` set). The banner disappears and writes go to the **master**.
5. Open `/health.php` once more. The first real tick appends right after the verify tab's last row; check it in Excel.

The app refuses to start if `APP_ENV=development` points at the master's item id, if dev and master ids are equal, or if `WORKBOOK_BACKEND=mock` is combined with `APP_ENV=production`.

## What the app writes

| Tab | Allowed writes |
|---|---|
| Signals | **never** |
| Signals to verify decision make | append a row (A:R if R1 = "Role Sub-Group", else A:Q plus a warning); column **M** only; remove an unticked row only while **M, S, T and U** are all empty. **Never S, T or U.** |
| Decision Makers | append one row (A:Q) after checking the header row; undo only the row the app just added, while it's still last and unchanged |
| anything else | **never**. Headers are never written. |

Rejections are stored only in `storage/rejections.json`, never in the workbook. **Back up that file** if you move servers. Every write is logged in `storage/audit.log`.

## Troubleshooting

| What you see | Fix |
|---|---|
| "the private folder … was not found" | `private/` isn't where the app looks. Layout A: name it `signal-desk-private` and put it next to `public_html`. Layout B: `_private` inside the document root. |
| "Signal Desk: setup needed" | The message says what's wrong in `config.php` (missing value, mock in production, dev id equals master…). |
| Login loops back to the login page | The site was opened over `http://`. Use `https://` (step 5). |
| "Your session expired. Please try again." on login | `APP_ORIGIN` doesn't exactly match the address bar (`https://`, no trailing slash, `www` or not). |
| "Microsoft Graph denied access" | Check the Sites.Selected site grant and the **new** client secret. |
| "Sheet busy, retrying…" | The workbook is locked (often the nightly job). The app retries by itself. |
| `/health.php` shows a WARN for "… over HTTP" | The server couldn't reach itself (no response, timeout) or got an unexpected status. Open the URL shown in a browser: 403 or 404 means it's fine, while showing the file means it's reachable. |
| `/health.php` shows a FAIL for "… over HTTP" | `.htaccess` isn't being applied. Make sure both hidden `.htaccess` files were uploaded, and ask 20i whether `AllowOverride` is enabled. Until then, use layout A. |

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
7. **Verified:** cards are grouped by job. Each verify-tab row is one decision maker's card, showing their LinkedIn (M), name (T), title (U) and note (S). A job with several people shows e.g. "Sample MSSP BV · Full-stack Developer · 2 decision makers" with "0 of 2 sent". The filters *Waiting for decision maker*, *Ready to review*, *Sent* and *All* count decision-maker cards.
8. **Verified:** open **Edit LinkedIn (column M)** on a waiting card, paste `linkedin.com/in/test-person` and click **Save**. You should see "✓ Saved to master sheet", and the card moves to *Ready to review*. A `linkedin.com/company/...` link is refused.
9. **Verified:** on a job with two people, **✓ Approve → Decision Makers** each card separately. The heading goes "1 of 2 sent" → "2 of 2 sent", and each card shows **Sent ✓ <today>**. **Undo** removes the row just added.
10. **Verified:** **+ Add another decision maker** on a job: paste a new person's LinkedIn and click **Add**. A new card appears (a new verify-tab row). Pasting someone already listed for that job is refused.
11. **Candidates (Atlas):** set `'ATLAS_BACKEND' => 'mock'` in `config.php` to test with fake candidates.
    - Signals and Verified cards show **"Atlas: N (P / F)"** (or "No matching list").
    - Clicking a badge opens **Candidates** with the role filled in.
    - That page shows "N candidates (P permanent / F freelance)", the matched lists, and 25 people per page with **Open in Atlas** and **CV**.
    - **Lists** shows the families. Edit a keyword and click **Save** ("✓ Saved"). **Refresh from Atlas** works once, then says to wait a minute.
12. To reset the fake data, delete `storage/mock-workbook.json`.

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

## Candidate Desk (Atlas, read-only)

The **Candidates** page and the **"Atlas: N (P / F)"** badges use your Atlas candidate lists. Each role family has a "– Permanent" and a "– Freelance" list, for example "Security Architects – Permanent" and "Security Architects – Freelance".

**Turn it on:**
1. In Atlas go to **Settings → Integrations → API Keys** and create a key, e.g. "Signal Desk (read-only)". The key acts as its user and sees that user's own lists plus public/company-wide ones. All 47 candidate lists are public, so any agency user's key works.
2. In `config.php`:
   ```php
   'ATLAS_BACKEND' => '',            // '' = on when the key is set; 'off' to switch the feature off
   'ATLAS_API_KEY' => '…',           // never commit, zip or paste this key in chat
   'ATLAS_BASE_URL' => 'https://api.recruitwithatlas.com',
   'ATLAS_PROFILE_URL' => 'https://my.recruitwithatlas.com/people-v2?profile={id}',
   ```
3. Open `/health.php`. It should show **Atlas API reachable: OK** and **Atlas lists in the map: OK (46 found)**.

**Which lists match a signal: `storage/atlas-list-map.json`.** It's created from `private/atlas-list-map.default.json` on first use. Edit it on the **Lists** page (or the copy in `storage/` over SFTP); no code changes are needed. Each family lists:
- its **Permanent** and **Freelance** list (matched by id, or by name if the id isn't found, so renaming a list in Atlas doesn't break it);
- `roleSubGroups`: values from column R, or just their code, e.g. `"A2"`;
- `keywords`: whole words matched in the job title, after removing gender markers like (m/f/x) and seniority words;
- `roleGroups`: values from column G.

The first rule that matches anything decides, in the order Role Sub-Group → keywords → Role Group. Several families can match, and their lists are combined. If the file is broken, `/health.php` shows FAIL and the app uses the default map.

**New lists are picked up automatically.** At most once an hour the app compares Atlas's candidate lists with the map, by list id:
- **A new list whose family already exists** (the name before "– Permanent", "– Freelance", "– Contract", etc. matches a family name) is added to that family.
- **A new list with an unknown family** creates that family, with one keyword taken from the list name: lower-case, last word made singular (e.g. "SAP Consultants – Permanent" → keyword `sap consultant`). It is marked **New – please review**.
- **A list deleted in Atlas** is marked "missing in Atlas" and counts as 0 candidates. Nothing breaks.
- Lists in `ignoredLists` (e.g. "To verify – Belgian eligibility") are never treated as role families.

**The Lists page** (`/lists`, login required, shown in the menu when Atlas is on):
- Shows every family with its lists and member counts. New families are listed first, with a **New – please review** badge.
- **Matching rules** lets you edit a family's title keywords (one per line) and Role Sub-Groups (comma-separated). **Save** writes only `storage/atlas-list-map.json` on this server, never Atlas. It also marks the family reviewed and adds an `atlas_map_edit` entry to the audit log.
- **Refresh from Atlas** clears the cached counts and list names and checks for new lists straight away. It can be used at most once a minute.

The **Candidates** search box also matches list names, so typing "SAP" finds the "SAP Consultants" lists even when no job title matches.

**Keywords are deliberately narrow.** Generic words such as "risk", "audit", "compliance", "identity" and "simulation" were replaced with phrases ("it risk", "cyber risk", "it audit", "identity and access", "cyber simulation", …). This stops jobs like "Credit Risk Analyst", "Financial Auditor" or "Simulation Engineer" being matched to security lists. If you add a keyword, prefer two words over one.

**What it reads and stores:**
- **Reads only:** the app can only send GET requests to `/lists`, `/lists/{id}/members`, `/people/{id}`, `/files`, `/files/{id}/download` and `/users`. Anything else, including every create, update or delete, is refused in code before any request is made.
- **Rate limits:** Atlas allows 1,200 reads per minute per agency. Badges load in batches of 10, one request at a time. On HTTP 429 the app waits for Atlas's `retryAfterSec` (up to 10 seconds) and retries; for a longer wait it shows "Atlas: busy, try later".
- **Stored on disk:** only counts and list names, in `storage/atlas-cache/`, for 6 hours. Candidate names, roles, companies and locations are fetched live for the 25 people on screen and never written to disk.
- **CVs:** the CV link is fetched only when you click **CV**. It's a short-lived Atlas download link.
- **Audit log:** each Atlas call is recorded as method, endpoint pattern and status, with no ids and no names.

## What the app writes

| Tab | Allowed writes |
|---|---|
| Signals | **never** |
| Signals to verify decision make | (a) **Verify**: append a row; (b) **Add another decision maker**: append a row copying A:R from the job's first verify row, with the new link in M and N:Q empty; both A:R if R1 = "Role Sub-Group", else A:Q plus a warning. (c) Column **M** on an identified row. (d) **Untick**: remove the row only when it's the job's **only** verify row and its **M, S, T and U** are empty. **Never S, T or U.** |
| Decision Makers | append one row (A:Q) per approved decision maker, after checking the header row; the same person is never sent twice for the same job; undo only the row the app just added, while it's still last and unchanged |

**Several decision makers per job:** each person is a separate verify-tab row with the same Job URL, and is reviewed, approved and sent separately. Every action on a card sends its row number **and** Job URL. If rows have moved in Excel since the page loaded, the app refuses with "The sheet changed, please refresh" instead of writing to the wrong row.

**Job URL keys:** LinkedIn job links are matched by their numeric job id. Indeed links by their job key (`jk`). All other sites by the full URL with tracking parameters (`utm_*`, `gclid`, `fbclid`, `trk`, `refId`, `trackingId`…) removed, so different `?id=` jobs stay different.
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
| "Atlas refused the API key" | Check `ATLAS_API_KEY` in `config.php`, or create a new key in Atlas (Settings → Integrations → API Keys). |
| "Atlas lists in the map" WARN: "not visible to this key" | The list is private to another user. Make it public in Atlas, or use that user's key. |
| A card says "No matching list" | No family matches its Role Sub-Group or title. On the **Lists** page, add the sub-group code or a keyword to a family. |
| A family shows "New – please review" | Atlas has a new list the app hadn't seen. Check its keywords on the **Lists** page and click **Save & mark reviewed**. |
| A list shows "missing in Atlas" | It was deleted or made private in Atlas. It counts as 0. Remove it from `storage/atlas-list-map.json` over SFTP if it's gone for good. |
| "Refresh from Atlas" says wait | It can be used once a minute. New lists are also picked up automatically every hour. |
| "Atlas: busy, try later" | Atlas's rate limit was reached. Badges retry on the next page load, and counts are cached for 6 hours. |
| The Verified page or badges look out of date after an upload | 20i caches `/assets/*`. The pages use versioned asset URLs (`?v=`), so make sure your SFTP client sets the upload time; then reload. |

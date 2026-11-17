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
11. **Candidates (Atlas):** set `'ATLAS_BACKEND' => 'mock'` and `'PROSPEO_BACKEND' => 'mock'` in `config.php` to test with fake candidates and fake market data (no credits are used).
    - **Lists** shows the 6 pools. Click **Rebuild index**. It builds in 20-second steps (about 40 s for the fake data) and ends with "Title index built: 138 people". A second click says to wait an hour.
    - Signals and Verified cards show **"Atlas: N matching titles in <pool> (M in pool)"** (or "No matching list"), plus a **Match candidates** link.
    - Clicking a badge opens **Candidates**: the matching people, 25 per page, with **Open in Atlas** and **CV**. **Show whole pool** lists everyone.
    - **Match candidates**: the fake job URLs can't be read, so it asks you to paste a JD. Paste a JD (English, Dutch or French), click **Extract requirements**, check them, then **Find candidates**. Ranked cards show the score, ✓ matched and ✗ missing items. **Deep match in Claude** copies a prompt with person IDs only.
    - **In the market**: click **Prepare a market search**, then **Search the market**. Five fake people show **Already in Atlas**. **Reveal contact** asks for confirmation (1 credit), then shows a fake email; **Reveal mobile** asks again (10 credits).
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

The **Candidates** page and the **Atlas** badges on Signals and Verified cards use your **6 Atlas candidate pools**. Each pool is one list with no Permanent/Freelance split:

| Pool | Atlas list |
|---|---|
| Cyber Security | Candidates – Cyber Security |
| Infrastructure & Cloud | Candidates – Infrastructure & Cloud |
| Defence & Public Sector | Candidates – Defence & Public Sector |
| Software & Architecture | Candidates – Software & Architecture |
| Leadership & Management | Candidates – Leadership & Management |
| Data & AI | Candidates – Data & AI |

A badge reads **"Atlas: 7 matching titles in Cyber Security (2,573 in pool)"**:
- First the signal's **pool** is found from its Role Sub-Group or job-title keywords.
- Then the **title index** counts the people in that pool whose current title matches.

Until the index is built, the badge shows the pool size with "title index building…".

**Turn it on:**
1. In Atlas go to **Settings → Integrations → API Keys** and create a key, for example "Signal Desk (read-only)". The key acts as its user. All 6 pools are public, so any agency user's key works.
2. In `config.php`:
   ```php
   'ATLAS_BACKEND' => '',            // '' = on when the key is set; 'off' to switch the feature off
   'ATLAS_API_KEY' => '…',           // never commit, zip or paste this key in chat
   'ATLAS_BASE_URL' => 'https://api.recruitwithatlas.com',
   'ATLAS_PROFILE_URL' => 'https://my.recruitwithatlas.com/people-v2?profile={id}',
   ```
3. Set up the **cron job** (next section) and build the index once: **Lists → Rebuild index**, or wait for the cron job.
4. Open `/health.php`. It should show:
   - **Atlas API reachable: OK**
   - **Atlas pools found: OK (6 found)**
   - the three **Atlas title index** rows OK

### Title index (nightly cron job on 20i)

The index is `storage/atlas-title-index.json`. For each person in the pools it stores **only** these fields:
- Atlas person id
- current title, plus a lower-cased copy for matching
- up to 5 past titles
- skills: only words from a fixed technology/certification list
- city and country
- whether a CV file exists
- which pools the person is in
- the profile's last-updated date

It never stores names, e-mail addresses, phone numbers, notes, salaries or free-text descriptions.

**Set up the cron job:** in **My20i → Manage Hosting → Scheduled Tasks (Cron Jobs)**, add a task that runs **every 15 minutes**. The command is the PHP binary followed by the script's full server path:

```
php /home/sites/<site id>/signal-desk-private/bin/atlas-index.php
```

- **Layout A:** the path is next to `public_html`, as in the example above.
- **Layout B:** the path is `…/public_html/signals/_private/bin/atlas-index.php`.
- **Finding the full path:** My20i shows the home path under **File Manager / FTP details**.
- **Choosing the PHP version:** 20i may show a menu, or you can type the binary instead, such as `php83`.

How it behaves:
- Each run takes at most 4 minutes, then stops. The next run carries on where it left off; progress is kept in `storage/atlas-index-build.json`.
- Once the index is complete and less than 20 hours old, a run exits straight away. In practice it rebuilds once a day.
- It spaces its Atlas reads to **500 per minute**, safely under Atlas's 1,200/min, so the rest of the app still has room.
- Each person costs 2 reads: the profile, and whether a CV exists. About 3,500 people take roughly 15 minutes in total.
- If Atlas asks it to slow down (HTTP 429), it stops and resumes on the next run.
- If Atlas fails for one person, the build carries on with everyone else and tries that person again in the next runs; after 3 failed runs the person is **skipped** (counted as `skipped=N` in the cron output and on `/health.php`). Short Atlas hiccups (5xx, timeouts) are retried after 2, 5 and 15 seconds in the same run. When Atlas fails for everyone (an outage) nobody is skipped: the run stops and the next one carries on.
- The cron output ends with `error="…"` when something went wrong (Atlas's own message, no personal data), e.g. `result=error … error="Atlas error 503"`.
- Progress goes to `storage/audit.log` as counts only. Check it from SSH with `php …/bin/atlas-index.php --status`.

**Rebuild index** on the Lists page starts a build straight away, at most once an hour. It keeps building in 20-second steps while the page stays open.

### Which pool matches a signal: `storage/atlas-list-map.json`

The file is created from `private/atlas-list-map.default.json` on first use. Edit it on the **Lists** page, or edit the copy in `storage/` over SFTP; no code changes are needed. Each pool has:
- `roleSubGroups`: values from column R, or just their code, e.g. `"A2"`;
- `keywords`: whole words matched in the job title, after removing gender markers like (m/f/x) and seniority words;
- `roleGroups`: values from column G.

The first rule that matches anything decides, in the order Role Sub-Group → keywords → Role Group. Each Role Sub-Group and each keyword belongs to exactly one pool. If the file is broken, `/health.php` shows FAIL and the app uses the default map.

**Upgrading from the 46 Permanent/Freelance lists:** this happens once, on the first page load after the upload.
- The old `storage/atlas-list-map.json` is saved as `atlas-list-map.before-pools-2026-10.json`, and the new 6-pool map replaces it.
- Keywords and Role Sub-Groups **you added yourself** move to the matching pool. Families found in Atlas since then (for example "SAP Consultants") are kept.
- The 46 old lists become **retired lists**: they are never shown as "missing" or turned into new families.

**New lists are picked up automatically.** At most once an hour the app compares Atlas's candidate lists with the map, by list id:
- **A new list for an existing pool** is added to that pool. A list matches a pool when its name, without "Candidates –" and any "– Permanent", "– Freelance" or "– Contract" ending, is the pool's name. The app can still handle extra list types like these.
- **A new list with an unknown name** creates a new family, with one keyword taken from the list name (lower-case, last word singular). It is marked **New – please review**.
- **A list deleted in Atlas** is marked "missing in Atlas" and counts as 0 candidates. Nothing breaks.
- Lists in `ignoredLists` (e.g. "To verify – Belgian eligibility") and `retiredLists` are never treated as families.

**The Lists page** (`/lists`, login required):
- Shows each pool with its member count, the title-index status and the **Rebuild index** button.
- **Matching rules:** edit a pool's keywords and Role Sub-Groups. **Save** writes only `storage/atlas-list-map.json`, never Atlas, and adds an `atlas_map_edit` audit entry.
- **Refresh from Atlas** clears the cached counts and checks for new lists straight away. It can be used at most once a minute.

The **Candidates** page shows the people in the pool whose current title matches, 25 per page, newest profiles first. **Show whole pool** lists everyone in it. Names, companies and locations are fetched live from Atlas for the 25 people on screen.

**Keywords are deliberately narrow.** Generic words such as "risk", "audit", "identity" and "simulation" are phrases instead ("it risk", "it audit", "identity and access", "cyber simulation"…), so "Credit Risk Analyst" or "Financial Auditor" don't match. If you add a keyword, prefer two words over one.

### JD matching (rule-based, no paid AI)

On **Candidates**, the **Match a job description** box ranks Atlas candidates for one job. The **Match candidates** link on a Signals or Verified card opens it for that job.

1. **The job description.**
   - The app tries to read the job page itself. Rules:
     - https only, public websites only (it checks the address first)
     - 10-second timeout, at most 500 KB
     - HTML is stripped
   - **LinkedIn, Indeed and Glassdoor block automated reading**, so for those, and whenever reading fails, the app asks you to paste the JD.
2. **Extract requirements.** Signal Desk's dictionary reads the JD in English, Dutch or French. It finds:
   - the role (via title synonyms)
   - seniority (from title words, or years of experience)
   - must-haves and nice-to-haves (skills and certifications)
   - languages, location, clearance and contract type

   Skills under headings such as "Requirements / Vereisten / Jouw profiel / Profil recherché / Exigé" count as **must-haves**. Skills under "Nice to have / Pluspunten / Un atout / Bij voorkeur", or on a line saying "is a plus", count as **nice-to-haves**. Skills under "What we offer / Wat bieden wij / Nous offrons" are ignored. **Check and edit the requirements** before you continue.
3. **Find candidates.** Everyone in the matching pool is scored from the title index, 0–100:

   | Part | Points | Scored on |
   |---|---|---|
   | Title | 35 | the person's current title (or a past title for 20) against the role and its synonyms |
   | Must-haves | 35 | share of must-have skills found in the person's skills/titles |
   | Nice-to-haves | 10 | share of nice-to-haves found |
   | Seniority | 10 | seniority words in the title against the job's level |
   | Location | 10 | same city area, same province, or elsewhere in Belgium |

   The top 50 are shown 25 per page, each with the items **matched (✓)** and **missing (✗)**. Names are read live from Atlas.
4. **Deep match in Claude** copies a prompt to the clipboard. The prompt contains:
   - the JD, with e-mail addresses, phone numbers and links masked
   - the requirements
   - the pool
   - the **top 20 Atlas person IDs**

   It contains **no names**. Paste it into Claude with the **Atlas connector** switched on. Claude looks the IDs up with `getCandidateProfile` and ranks them with evidence, gaps and risks. If the browser refuses to copy, the text appears in a box to copy by hand.

**The dictionary: `storage/jd-dictionary.json`.** The default is `private/jd-dictionary.default.json`, with about 480 skills and certifications plus 53 job titles with English, Dutch and French synonyms. It is updated together with the app.
- **Your file holds only your additions.** It is created on first use with a small template. Anything in it is **added to** the default, and lists such as synonyms or aliases are combined. Example:
  ```json
  {
    "titles": { "SAP Consultant": { "synonyms": ["sap fico consultant"] } },
    "skills": { "sap fico": { "aliases": ["fico"], "category": "dev" } },
    "remove": { "skills": ["go"] }
  }
  ```
- `"remove"` switches a default entry off.
- The skills are also the **title index's vocabulary**: after changing skills, click **Lists → Rebuild index**.
- If the file is broken, the default is used and `/health.php` still works.

**AI scoring stays off.** The earlier Anthropic code is still in the app, but `ANTHROPIC_BACKEND` is `'off'` by default, and a key on its own does not switch it on. `/health.php` shows **AI scoring: off (rule-based)**. To use it later:
- set `'ANTHROPIC_BACKEND' => 'anthropic'` plus `ANTHROPIC_API_KEY`;
- the AI then scores the top 40 from their Atlas profile and CV;
- each user gets 20 matches a day, within the monthly euro budget in `ANTHROPIC_MONTHLY_LIMIT_EUR`;
- a match costs about €1.

### In the market (Prospeo)

Below the Atlas results, **In the market (Prospeo)** finds people outside Atlas who fit the role.

1. **Prepare a market search (free).** The requirements become Prospeo filters that you can edit before anything is spent:
   - **job titles:** the role and its synonyms, matched as similar titles
   - **locations:** Prospeo's exact name for the job location, looked up free with search-suggestions; Belgium when the job has none
   - **seniority**
   - **years of experience**
   - **up to 5 must-have keywords**
2. **Search the market (1 credit / 25 people).**
   - Each card shows name, title, company, location and a LinkedIn link.
   - A **fit score, "based on title & company only"**, comes from the same rule-based scorer.
   - **Already in Atlas** appears when the person's exact LinkedIn URL is in Atlas. That's one read-only Atlas `GET /people/search` per page.
   - A page Prospeo has charged you for in the last 30 days is free again.
3. **Reveal contact.**
   - A confirmation shows the cost first: **email, 1 credit** (nothing if no verified email is found).
   - **Reveal mobile** is a separate button with its own confirmation, at **10 credits**.
   - You can only reveal people who appeared in your own search results in this session.

**Turn it on:** copy the API key from your Prospeo dashboard, then in `config.php`:
```php
'PROSPEO_BACKEND' => '',                // '' = on when the key is set; 'mock' = fake people (refused in production); 'off'
'PROSPEO_API_KEY' => '…',               // never commit, zip or paste this key in chat
'PROSPEO_DAILY_CREDIT_CAP' => '200',    // credits the whole app may spend per day (searches + reveals)
```
`/health.php` shows:
- **Prospeo API reachable**
- **Prospeo credits left** (WARN under 1,000)
- **Prospeo credits used today**, against the cap

**Credits and limits:**
- The daily cap is checked **before** every paid call: a search page needs 1 credit, an email 1, a mobile 10.
- The header shows the credits left in Prospeo (refreshed every 10 minutes) and today's use.
- Prospeo's own rate limit applies too. On the Starter plan that's 1 search a second and 30 a minute; the app waits briefly or says "try again in a minute".

**Privacy:**
- Prospeo results go to your browser only. Names, titles, emails and phone numbers are **never written to disk or to the audit log**.
- The app keeps only:
  - the Prospeo person ids of a search, for 24 h
  - today's credit count
  - cached location names
- The app can call only four Prospeo endpoints: search-suggestions, search-person, account-information and enrich-person.
  - Enrich-person runs only from a confirmed **Reveal contact**.
  - Every other endpoint is refused in code.

**What the Atlas side reads and stores:**
- **Reads only.** The app can send GET requests only to:
  - `/lists` and `/lists/{id}/members`
  - `/people/{id}`, and `/people/search` (exact LinkedIn URL lookup for "Already in Atlas")
  - `/files` and `/files/{id}/download`
  - `/users`

  Anything else, including every create, update or delete, is refused in code before any request is made.
- **Rate limits:** badges load in batches of 10. On HTTP 429 the app waits for Atlas's `retryAfterSec` (up to 10 s) and retries; for a longer wait it shows "Atlas: busy, try later".
- **Stored on disk:**
  - pool counts and list names in `storage/atlas-cache/`, for 6 hours
  - the title index described above
  - nothing else about candidates
- **Audit log:** each Atlas call is logged as its method, endpoint pattern and status, with no ids and no names.

## HR signals (second decision-maker track)

The **HR signals** page (menu: HR signals) works exactly like **Verified**, but on its own two tabs:

| | Verified | HR signals |
|---|---|---|
| Cards come from | Signals to verify decision make | **Hr signals** (same A:U columns) |
| JP edits | column **M** (LinkedIn) | column **M** (LinkedIn) |
| + Add another decision maker | appends a row to the verify tab | appends a row to **Hr signals** (A:R copied from the job's first row) |
| ✓ Approve appends one row to | Decision Makers | **HR Decision Makers** |

**One-time setup in the master:** create a tab named **HR Decision Makers** and paste this header row into row 1, A1:Q1 (exactly the Decision Makers headers):

```
Date Verified	Verified By	Date Found	Company	Domain	Job Title	Role Group	Role Sub-Group	Company Type	Location	Job URL	Decision Maker LinkedIn	Decision Maker Name	Decision Maker Title	Status	Source Row	Notes
```

(Copy the line above and paste it into A1: the tabs split it into the 17 columns.) Until the tab exists, the HR signals page still shows the cards with a warning, and **Approve** refuses with a clear message. Nothing else is written.

- The tab names are matched without minding capitals or extra spaces, so the existing tab "Hr signals " (with a trailing space) works as it is.
- On the **Hr signals** tab the app only ever writes column **M** and appends rows; it never clears or deletes rows, and never writes S, T or U (fill name, title and note in Excel, as on the verify tab).
- Approved rows get Status **Ready to push** and **Source Row** = the row number on the Hr signals tab. The same person is never sent twice for the same job, and **Undo** works only for the app's own last HR approval.

## lemlist (Outreach & Calls) — Phase 1: data sync and webhook

Signal Desk reads your lemlist campaigns into its own database, **storage/lemlist.sqlite**, so the Outreach and Calls tabs (next phases) are fast. Phase 1 adds no new screen yet; check it on `/health.php`.

**1. config.php**
```php
'LEMLIST_BACKEND' => '',                          // '' = on when the key is set; 'mock' = fake data (refused in production); 'off'
'LEMLIST_API_KEY' => '…',                         // lemlist → Settings → Integrations → Generate a new API key (Jonathan's account)
'LEMLIST_WEBHOOK_SECRET' => '…',                  // make up a long random string, 32+ characters; never share it
'LEMLIST_SHARED_SENDER' => 'usr_ppJJtGkzzXRXK7sxi', // Jonathan Perkins: everyone allowed below sends / logs calls as him
'LEMLIST_SENDER_ALLOWED' => 'jonathan@…, haseeb@…', // the Signal Desk LOGIN emails allowed to send; everyone else can only view
'LEMLIST_USER_MAP' => '',                         // later, for separate lemlist users
'LEMLIST_ATTRIBUTION' => 'first_positive',        // or all_touches
```
Every send and call log will say **"Sending as Jonathan Perkins (jonathan@swooscale.com)"**, and the audit log records the real Signal Desk user behind each action.

**2. Second cron line** (My20i → Scheduled Tasks), every 15 minutes, next to the Atlas one:
```
php /home/sites/11a/6/6202f25165/signal-desk-private/bin/lemlist-sync.php
```
Each run lasts at most 4 minutes and carries on where it stopped. It reads the running campaigns (steps, leads, stats), new activities, open tasks and call statuses (about 8 requests a second; lemlist allows 20 per 2 s), then links each lead to its approved decision maker in **Decision Makers** / **HR Decision Makers** (read-only, by LinkedIn profile) and its Atlas pool count. Data older than 12 months is removed once a day.

**3. Register the webhook once** (needs Jonathan's "Edit integrations" permission). Over SSH on 20i, or on your own computer with the live config.php:
```
php bin/lemlist-webhook.php --register
php bin/lemlist-webhook.php --status
```
It registers **https://signals.swooscale.com/hooks/lemlist** for all events, with your secret. lemlist sends the secret back in every event; Signal Desk refuses any event without it. If lemlist ever disables the webhook (health shows FAIL), run `--status`, then `--delete=<id>` and `--register` again.

**If events don't arrive** (health: "nothing in 24 h" or "deliveries refused"):
```
php bin/lemlist-webhook.php --status
php bin/lemlist-webhook.php --test
```
- `--status` lists lemlist's webhooks (disabled? last error code) and what Signal Desk saw: the last accepted event and refused deliveries with the reason (`secret mismatch`, `no secret field`, `not JSON`, `empty body`, `too large`). No values are ever shown or logged.
- `--test` sends a delivery signed with this `config.php`'s secret from the server to `https://…/hooks/lemlist` through 20i. **OK** means the route and the secret work; if lemlist's own deliveries are still refused with `secret mismatch` / `no secret field`, its webhook was created with another secret (or in the lemlist UI without one). A webhook's secret can't be changed: `--delete=<id>` it and `--register` again.
- An answer that is not the app's JSON points at 20i (firewall) instead.

**Storage on 20i (since 17 Nov 2026).** 20i's storage is a network file system. SQLite's WAL mode needs a shared-memory file (`lemlist.sqlite-shm`) that fails there with `disk I/O error`, so:
- the database uses the classic rollback journal (`journal_mode=DELETE`, `synchronous=NORMAL`, 15 s busy timeout). A database left in WAL mode (or with `-wal`/`-shm` files) is checkpointed and switched over once, the first time it is opened, without touching the `-shm` file;
- every write runs under `storage/lemlist.lock` in short transactions (200 rows), so the cron and the web pages never write at the same time. The cron closes the database at the end of each run;
- the webhook no longer opens the database: events go to `storage/lemlist-hooks.jsonl` (ids, types and a masked one-line reply, no emails or phones) and the cron imports them. The pages still react at once (inbox refresh, tasks done in the dialer disappear) through `storage/lemlist-hook-state.json`;
- app-only data (the call log and lead outcomes) is in its own small file `storage/lemlist-app.sqlite`, backed up daily to `lemlist-app.sqlite.bak-<date>` (7 kept). Everything in `lemlist.sqlite` can be synced again from lemlist;
- an I/O error is retried once after 2 s; if it fails again the reason, free disk space, file sizes and leftover journal files go to `storage/lemlist-db-status.json` and `/health.php` shows **lemlist storage: ERROR …**;
- a damaged database (daily `integrity_check`, or "malformed"/"not a database") is renamed to `lemlist.sqlite.broken-<date>` after backing up the app data, and the next cron run rebuilds it from lemlist (a full sync).

Check the database at any time (prints journal mode, sizes, free space, integrity, buffered webhook events; no data):
```
php bin/lemlist-sync.php --check-db
```

**What it reads and keeps:**
- Lead names, companies, titles, LinkedIn, **emails and phones** are stored only in `storage/lemlist.sqlite` (outside the web root), never in logs. Webhook events are kept as ids and types only.
- The app can only *read* from lemlist, except for the writes the Calls tab will use: log a call, complete / reschedule / skip a task, reply by email or LinkedIn, set an inbox label, create our 4 labels (Interested, Meeting, Not interested, Out of office), and the webhook setup above. Anything else (deleting leads, changing campaigns, bulk sends) is refused in code before any request.
- **Calls API:** lemlist's calls API needs **Email Pro or above**. `/health.php` shows the plan; without it, the call logger will be replaced by "Calls API needs Email Pro+" and manual tasks and the inbox keep working.

**/health.php rows:** key set and reachable · plan / calls API · webhook registered (FAIL if lemlist disabled it) · last webhook event (WARN after 24 h without one) · last sync (WARN after 1 h) · campaigns, leads (linked to DMs) and open tasks synced · who may send.

## lemlist — Phase 2: the Outreach tab

A new tab **Outreach** (shown when lemlist is on). It reads only Signal Desk's own copy (`storage/lemlist.sqlite`, filled by the cron above) and refreshes every minute. It sends nothing to lemlist.

**Look:** the Outreach tab follows the approved mockup (`signal-desk-design/outreach-mockup.dc.html`). The top bar (logo, tabs, **Calls** with its open-task count, the green "Live · last lemlist event …" status and Log out) is one row on every page. **Calls** shows "coming soon" until the Calls tab is built. The Inter font is served by Signal Desk itself from `public/assets/fonts/` (no Google Fonts); upload that folder with the rest, and the new `.htaccess` rule that serves it.

**Running campaigns only.** Paused, ended, archived and draft campaigns are not shown anywhere on the tab. When a campaign stops running, it disappears at the next sync; its history stays in the database (12-month pruning) and comes back if the campaign is restarted. To also show stopped campaigns:
```php
'LEMLIST_SHOW_STOPPED' => 'false',   // 'true' = also show stopped campaigns
```

**Top of the tab:** Today / 7 days / 30 days (numbers compared with the period before; "Today" is compared with yesterday up to the same hour) and **Campaigns: All running (N)**:
- tick campaigns (with search, Select all, Clear), or use a quick group made from the names ("All MPC", "All HR");
- **Save** a selection under a name; saved selections are per Signal Desk user (`storage/outreach-selections.json`) and can be overwritten or deleted (×);
- the selection is in the address bar (`/outreach?c=<campaign ids>&p=7`), so a link opens the same view; without one, your last selection opens;
- if a campaign in your selection stops running, it is dropped and a small notice says so.
- **Compare**: pick 2 or 3 campaigns to see them side by side.

**Sections (all follow the selection):** tiles (leads reached, by email, LinkedIn, phone, meetings, new jobs, deals, new clients) · 4 channel cards (email reply rate = replies ÷ delivered, LinkedIn invite acceptance, LinkedIn message reply rate, call connect rate; each with its responses/actions) · **From reach to business** (per channel; credited per `LEMLIST_ATTRIBUTION`) · **Needs action** (replies without an outcome, meetings booked, bounces, lemlist alerts, approved DMs not yet in lemlist; call-back requests arrive with the Calls tab) · **Funnel** (the first three steps come from the master sheet and cover all campaigns) · **Running campaigns** with a Total row · **Live lead board** (table or board by stage; filters for status, campaign, channel state, IT/HR, role group, needs action; search).

Click a lead for its timeline (signal found → approval → pushed → every step) with links to LinkedIn, lemlist and the job, the Atlas pool count and the outcome buttons (Interested, Not now, Not interested, Meeting, New job, Deal, New client). **Outcomes are stored in Signal Desk only** and audited with the lead id, never sent to lemlist.

**New jobs, deals, new clients** come from Atlas (read-only, once an hour from the lemlist cron): projects created, opportunities won and placements, matched to the lead's company by name (legal forms such as NV/BV/SA ignored). A "new client" is a company's first placement.

**Known limits:** lemlist's API has no activity for calls logged through the API, so call numbers come from lemlist's dialer events plus the calls Signal Desk logs (Calls tab). The "lemlist ↗" link opens the campaign; lemlist has no public link format for a single lead.

## lemlist — Phase 3: the Calls tab (SDR desk)

**Calls** in the top bar (its number = open call and manual tasks due) opens the SDR desk, in the same mockup design: **Call tasks**, **Manual tasks** and **Inbox**.

**1. config.php: the kill switch**
```php
'LEMLIST_WRITES' => 'off',   // default. 'on' after testing: calls, tasks, replies and labels are sent to lemlist
```
With `'off'` the whole tab works in **preview mode**: every button runs all the checks and shows what it *would* send (for example "POST /calls + PATCH /tasks"), but nothing reaches lemlist, nothing changes in Signal Desk, and threads are not marked as read. `/health.php` shows the setting.

**2. Who can act:** only logins in `LEMLIST_SENDER_ALLOWED`, and always as `LEMLIST_SHARED_SENDER` (Jonathan). Every send and call log says **"Sending as Jonathan Perkins (jonathan@swooscale.com)"**. Anyone else sees the tab in view-only mode. The audit log records the real Signal Desk user, the action and the task/lead/contact ids (never message text or notes).

**Call tasks:** lemlist phone tasks plus manual tasks titled "Call…", "Calling again…" or "Final call…", overdue first. "Now calling" shows the last email/LinkedIn events, the Atlas count, the linked signal, a phone (tel:) link and LinkedIn. Answered / Not answered → the outcome (your lemlist call statuses, read live) → note → optional call-back date → **Save & complete task in lemlist**, and the next call opens.
- Phone task: the call is logged on the task (lemlist completes it and the sequence moves on).
- Manual call task: the call is logged on the lead, then the task is marked done.
- Call-back date: the call is logged on the lead and the task moves to that date (09:00 Brussels), so it stays open.
- A double click or a retry never logs a call twice (each attempt has its own id). If someone else completed the task, the queue refreshes. "Done today" counts the calls logged here.

**Call script:** "Now calling" shows the lead's script for that step, with a **Copy** button. In our campaigns a call step's text is a lead variable (for example "Call {{phone}}" → `{{Touch 1 Call}}`, "Calling Again" → `{{Call-again}}`, "Final Call" → `{{Final call}}`, "Voice mail" → `{{Touch 2 mail}}`). The sync stores the lead's values for every variable the campaign's steps use (in `storage/lemlist.sqlite` only, pruned with the lead after 12 months), so a changed script in lemlist shows after the next sync. The task is matched to its step by the step id, else its position, else its title. When the lead's field is empty: "No script for this lead" and the raw step text. When the lead has a voicemail script, it is under **If no answer: voicemail**. Phone numbers in task titles are not stored ("Calling Again (phone)").

**Manual tasks:** the other manual steps, with filters. Message steps show the campaign step text with the lead's details filled in (**approximate preview**: lemlist sends the step as it is in the campaign). **Send** asks for confirmation, then lemlist sends the step. **Done** closes other tasks, **Skip** ignores the task in lemlist, and call-type tasks have **Log call**.

**Inbox:** your lemlist conversations (All / Unread / Interested / Meeting); opening one marks it read. Labels: only our 4 (Interested, Meeting, Not interested, Out of office); other labels on the conversation are kept; missing ones are created if Jonathan is a lemlist admin. Reply by **Email** (in the same thread, from Jonathan's lemlist mailbox) or **LinkedIn** (campaign leads only), after a confirmation, without attachments. New replies show up live through the webhook (with a 60-second refresh as a fallback).

**Limits per user and hour:** 30 messages sent, 100 calls logged, 200 task changes, 100 label changes.

**Outreach numbers:** calls logged here count as calls and conversations; a positive call sets the lead to Interested (negative: Not interested); the Interested / Meeting / Not interested labels set the same outcomes.

**Calls API:** logging calls needs lemlist **Email Pro or above**. Without it the tab says so, and manual tasks and the inbox keep working.

## What the app writes

| Tab | Allowed writes |
|---|---|
| Signals | **never** |
| Signals to verify decision make | (a) **Verify**: append a row; (b) **Add another decision maker**: append a row copying A:R from the job's first verify row, with the new link in M and N:Q empty; both A:R if R1 = "Role Sub-Group", else A:Q plus a warning. (c) Column **M** on an identified row. (d) **Untick**: remove the row only when it's the job's **only** verify row and its **M, S, T and U** are empty. **Never S, T or U.** |
| Hr signals | column **M** on an identified row; **Add another decision maker** appends a row (A:R from the job's first row, new link in M). Never cleared, deleted, or written in S, T or U. |
| HR Decision Makers | as Decision Makers (one A:Q row per approved HR decision maker; undo of the app's own last row) |
| Decision Makers | append one row (A:Q) per approved decision maker, after checking the header row; the same person is never sent twice for the same job; undo only the row the app just added, while it's still last and unchanged |

**Several decision makers per job:** each person is a separate verify-tab row with the same Job URL, and is reviewed, approved and sent separately. Every action on a card sends its row number **and** Job URL. If rows have moved in Excel since the page loaded, the app refuses with "The sheet changed, please refresh" instead of writing to the wrong row.

**Job URL keys:** LinkedIn job links are matched by their numeric job id. Indeed links by their job key (`jk`). All other sites by the full URL with tracking parameters (`utm_*`, `gclid`, `fbclid`, `trk`, `refId`, `trackingId`…) removed, so different `?id=` jobs stay different.
| anything else | **never**. Headers are never written. |

Rejections are stored only in `storage/rejections.json`, never in the workbook. **Back up that file** if you move servers. Every write is logged in `storage/audit.log`.

## Troubleshooting

| What you see | Fix |
|---|---|
| Cron mail `lemlist-sync: … disk I/O error` / health "lemlist storage: ERROR" | Upload this version (no WAL any more) and run `php bin/lemlist-sync.php --check-db`. If it still fails: check free space in the line it prints; if `-wal`/`-shm` files remain and the error persists, rename `storage/lemlist.sqlite` (and its `-wal`/`-shm`) to `lemlist.sqlite.old`: the next cron run rebuilds it from lemlist. Outcomes and the call log are in `lemlist-app.sqlite` (from the first successful open of this version). |
| Health "lemlist storage: WARN … damaged … renamed" | Nothing to do: the next cron run rebuilds from lemlist. Delete the `lemlist.sqlite.broken-*` file after a week if all is fine. |
| Health "lemlist webhook events waiting … is bin/lemlist-sync.php running?" | Events are buffered but the cron hasn't imported them for over an hour: check the cron job and its mails. |
| "the private folder … was not found" | `private/` isn't where the app looks. Layout A: name it `signal-desk-private` and put it next to `public_html`. Layout B: `_private` inside the document root. |
| "Signal Desk: setup needed" | The message says what's wrong in `config.php` (missing value, mock in production, dev id equals master…). |
| Login loops back to the login page | The site was opened over `http://`. Use `https://` (step 5). |
| "Your session expired. Please try again." on login | `APP_ORIGIN` doesn't exactly match the address bar (`https://`, no trailing slash, `www` or not). |
| "Microsoft Graph denied access" | Check the Sites.Selected site grant and the **new** client secret. |
| "Sheet busy, retrying…" | The workbook is locked (often the nightly job). The app retries by itself. |
| `/health.php` shows a WARN for "… over HTTP" | The server couldn't reach itself (no response, timeout) or got an unexpected status. Open the URL shown in a browser: 403 or 404 means it's fine, while showing the file means it's reachable. |
| `/health.php` shows a FAIL for "… over HTTP" | `.htaccess` isn't being applied. Make sure both hidden `.htaccess` files were uploaded, and ask 20i whether `AllowOverride` is enabled. Until then, use layout A. |
| HR signals: "The HR Decision Makers tab is empty" or "tab was not found" | Create the **HR Decision Makers** tab with the header row (see HR signals). |
| lemlist: "PHP extension pdo_sqlite is missing" | Ask 20i to enable **pdo_sqlite** for your PHP version (it is standard). |
| lemlist webhook FAIL "DISABLED" | lemlist stopped sending after a failed delivery: `bin/lemlist-webhook.php --status`, `--delete=<id>`, `--register`. |
| lemlist "last webhook event" WARN | Nothing arrived for 24 h: check the webhook row; quiet weekends are normal. |
| "Atlas refused the API key" | Check `ATLAS_API_KEY` in `config.php`, or create a new key in Atlas (Settings → Integrations → API Keys). |
| "Atlas pools found" WARN: "not visible to this key" | The list is private to another user. Make it public in Atlas, or use that user's key. |
| Badges say "title index not built yet" / "building…" | Set up the cron job (Candidate Desk → Title index) or click **Lists → Rebuild index** and keep the page open until it finishes. |
| "Atlas title index age" WARN (older than 36 h) | The cron job isn't running. Check the command and path in My20i → Scheduled Tasks, and run it once by hand with `--status`. |
| "Atlas title index build" WARN (running without progress for over 6 h) | The cron job stopped or keeps failing. The row shows the last error; run the cron command by hand to see the `error="…"` part, or click **Lists → Rebuild index**. |
| "Atlas title index skipped" WARN | Atlas kept failing for some people (3 runs); the index was finished without them. They are included again in the next nightly build. |
| "JD matching needs it" / "INDEX_NOT_READY" | Build the title index first (see above). |
| A JD's must-haves look wrong | Edit them before **Find candidates**. If a skill or title is often missed, add it to `storage/jd-dictionary.json` (see JD matching), then rebuild the index. |
| "Deep match in Claude" shows a text box instead of copying | The browser blocked the clipboard (it needs https). Select the text and copy it yourself. |
| "Today's Prospeo credit cap is reached" | The whole app has spent `PROSPEO_DAILY_CREDIT_CAP` credits today. It resets at midnight UTC; raise the cap in `config.php` if needed. |
| "Prospeo refused the API key" / "no credits left" | Check `PROSPEO_API_KEY`, or top up the Prospeo account. `/health.php` shows the credits left. |
| "Search for this person first" on Reveal | Reveals only work for people in your own search results; search again (a page you already paid for is free for 30 days). |
| "This site blocks automated reading" / "Paste the job description" | LinkedIn, Indeed and Glassdoor (and pages that fail to load) can't be read by the app. Paste the JD text instead. |
| "Anthropic refused the API key" | Only if you switched AI scoring on: check `ANTHROPIC_API_KEY` in `config.php`. |
| A card says "No matching list" | No pool matches its Role Sub-Group or title. On the **Lists** page, add the sub-group code or a keyword to a pool. |
| A family shows "New – please review" | Atlas has a new list the app hadn't seen. Check its keywords on the **Lists** page and click **Save & mark reviewed**. |
| A list shows "missing in Atlas" | It was deleted or made private in Atlas. It counts as 0. Remove it from `storage/atlas-list-map.json` over SFTP if it's gone for good. |
| "Refresh from Atlas" says wait | It can be used once a minute. New lists are also picked up automatically every hour. |
| "Atlas: busy, try later" | Atlas's rate limit was reached. Badges retry on the next page load, and counts are cached for 6 hours. |
| The Verified page or badges look out of date after an upload | 20i caches `/assets/*`. The pages use versioned asset URLs (`?v=`), so make sure your SFTP client sets the upload time; then reload. |
| The Calls tab says "Preview mode" | `LEMLIST_WRITES` is `off` (the default): nothing is sent. Set `'LEMLIST_WRITES' => 'on'` in `config.php` when you are ready. |
| "View only" on the Calls tab | Your login email is not in `LEMLIST_SENDER_ALLOWED`. |
| "Limit reached: at most 30 messages sent per hour" | The per-user hourly limit. Wait a bit, or ask for a higher limit. |
| "This task is already closed in lemlist" | Someone completed it in lemlist (or the dialer) meanwhile. The queue refreshed; nothing was logged twice. |
| Outreach shows "No running lemlist campaigns yet" | The lemlist cron hasn't run since the upload, or no campaign is running. Wait for the next run (every 15 minutes) and check `/health.php`. |
| A campaign is missing from the Outreach picker | Only running campaigns are shown. Restart it in lemlist (it comes back with its history at the next sync) or set `'LEMLIST_SHOW_STOPPED' => 'true'`. |
| Outreach shows New jobs / Deals / New clients as 0 | They come from Atlas once an hour and match the lead's company name; check the Atlas rows on `/health.php` and that the company is spelled the same in Atlas. |

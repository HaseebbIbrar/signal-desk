# Signal Desk (SwooScale)

A small, secure web app where JP:

1. reviews the latest daily block of job signals from the **Signals** tab,
2. ticks the signals worth pursuing (each tick appends the full row to **Signals to verify decision make**),
3. adds decision-maker LinkedIn links to the ticked rows (column M).

There is no database. Everything is read from and written to **`SwooScale Master Data.xlsx`** on the *Swooscale Hub* SharePoint site, live, through the **Microsoft Graph Excel API**. Cells are edited in place, so the file is never downloaded and re-uploaded. This lets the nightly job and SharePoint sync carry on alongside the app.

## What the app may write

| Allowed | Where |
|---|---|
| Append a ticked row | next empty row of *Signals to verify decision make*: columns A:R if that tab's R1 is "Role Sub-Group", otherwise A:Q (with a logged warning) |
| Write decision-maker link(s) | column M of the row whose Job URL matches |
| Remove an unticked row | only if its column M is empty: clear, then delete with shift-up, the same width that's appended (A:R or A:Q) |

Nothing else is written, and headers are never written. If you want ticks to copy column R, add the header `Role Sub-Group` to R1 of the verify tab yourself. The **Signals** tab and every other tab are read-only. `src/lib/workbook/guard.ts` enforces this inside every backend write method, and the tests check it. Every write, and every refused write, is logged as one JSON line (`"type":"audit"`) in the server logs. On Vercel you'll find them under Project → Logs.

---

## 1. Microsoft Entra ID: app registration (admin)

1. Go to **entra.microsoft.com → Identity → Applications → App registrations → New registration**.
   - Name: `SwooScale Signal Desk`
   - Supported account types: *Accounts in this organizational directory only*
   - Redirect URI: none. The app uses client credentials, with no user sign-in to Microsoft.
2. On the app's **Overview** page, copy the **Application (client) ID** into `AZURE_CLIENT_ID` and the **Directory (tenant) ID** into `AZURE_TENANT_ID`.
3. **Certificates & secrets → Client secrets → New client secret**. Set an expiry (for example 12 months) and put a reminder in the calendar. Copy the **Value**, not the Secret ID, into `AZURE_CLIENT_SECRET`. It's shown once only.
4. **API permissions → Add a permission → Microsoft Graph → Application permissions → `Sites.Selected`** → Add.
   Then click **Grant admin consent for <tenant>**. The status should turn green.
   > `Sites.Selected` on its own grants access to **nothing**. The app can only reach sites it is explicitly granted in step 2. Don't add `Sites.ReadWrite.All` or `Files.ReadWrite.All`.

## 2. Grant the app write access to the Swooscale Hub site only (admin)

The grant is made per site, by someone who is a SharePoint/Global admin. Choose **one** of these options.

### Option A: Graph Explorer (browser)
1. Open https://developer.microsoft.com/graph/graph-explorer and sign in as an admin.
2. Under **Modify permissions**, consent to `Sites.FullControl.All` for Graph Explorer. This is delegated to you as the admin, not to the app.
3. Find the site id:
   `GET https://graph.microsoft.com/v1.0/sites/swoosearch.sharepoint.com:/sites/SwooscaleHub`
   (Adjust the path to the real site URL, e.g. open the site and copy the part after `.sharepoint.com`.) Copy `id` (it looks like `swoosearch.sharepoint.com,<guid>,<guid>`) into `GRAPH_SITE_ID`.
4. Grant the app **write** on that site:
   ```
   POST https://graph.microsoft.com/v1.0/sites/{GRAPH_SITE_ID}/permissions
   Content-Type: application/json

   {
     "roles": ["write"],
     "grantedToIdentities": [
       { "application": { "id": "<AZURE_CLIENT_ID>", "displayName": "SwooScale Signal Desk" } }
     ]
   }
   ```
5. Check it worked: `GET https://graph.microsoft.com/v1.0/sites/{GRAPH_SITE_ID}/permissions` should list the app with role `write`.

### Option B: PnP PowerShell
```powershell
Connect-PnPOnline -Url https://swoosearch-admin.sharepoint.com -Interactive -ClientId <your PnP admin app id>
Grant-PnPAzureADAppSitePermission -AppId <AZURE_CLIENT_ID> -DisplayName "SwooScale Signal Desk" `
  -Site https://swoosearch.sharepoint.com/sites/SwooscaleHub -Permissions Write
```

To revoke later, delete the permission (`DELETE /sites/{id}/permissions/{permissionId}`) or remove the client secret.

## 3. Find the drive and item ids (no guessing)

1. Install Node 20.9+ and run `npm install`.
2. Copy `.env.example` to `.env.local`. Fill in `AZURE_*`, plus either `GRAPH_SITE_ID` or `SHAREPOINT_SITE_PATH`.
3. Run:
   ```bash
   npm run find-ids
   ```
   The script uses the app's own credentials and does the following. It writes nothing.
   - resolves the site and lists its document libraries,
   - resolves `SwooScale Signal Folder/New workflow JP/Master sheet Signals/SwooScale Master Data.xlsx` in **Documents**,
   - does a **read-only** `usedRange` call on *Signals* and *Signals to verify decision make* to prove the grant works,
   - prints `GRAPH_SITE_ID`, `GRAPH_DRIVE_ID` and `GRAPH_MASTER_ITEM_ID` lines to paste into `.env.local`.

   If it fails, a `403` means the site grant (step 2) is missing, and a `404` means the site path, library or file path is wrong.

## 4. Dev mode: run against a COPY of the workbook

1. In SharePoint, open the *Master sheet Signals* folder. Select `SwooScale Master Data.xlsx`, choose **Copy to**, pick the same folder, and rename the copy to `SwooScale Master Data - DEV COPY.xlsx`.
2. Get its id:
   ```bash
   npm run find-ids -- --dev-file "SwooScale Signal Folder/New workflow JP/Master sheet Signals/SwooScale Master Data - DEV COPY.xlsx"
   ```
   Put the printed `GRAPH_DEV_ITEM_ID` in `.env.local` and keep `APP_ENV=development`.
3. `npm run dev` and open http://localhost:3000.

Safety nets in development:
- the app refuses to start if `GRAPH_DEV_ITEM_ID` equals `GRAPH_MASTER_ITEM_ID`,
- it reads the target file's name first and refuses if it's `SwooScale Master Data.xlsx`,
- a yellow **DEV COPY** banner is shown on every page.

For UI work with no Microsoft access at all, set `WORKBOOK_BACKEND=mock`. This uses fake in-memory data, shows a **MOCK DATA** banner, and is refused when `APP_ENV=production`.

## 5. Create login accounts

There is no sign-up page. Accounts live in `AUTH_USERS`.

```bash
npm run hash-password
```

The script asks for the email, the display name and a password of at least 12 characters (input is hidden). It prints one entry like this:

```
jp@swooscale.com|Jonathan Perkins|JDJiJDEy...base64...
```

Join several users with `;`, for example JP now and Haseeb later:

```
AUTH_USERS=jp@swooscale.com|Jonathan Perkins|JDJi...;haseeb@swooscale.com|Haseeb|JDJi...
```

The bcrypt hash is base64-encoded because raw hashes contain `$`, which `.env` files and some dashboards expand. Remove an entry to revoke that person immediately, including any session they already have open. Changing `SESSION_SECRET` signs everyone out.

Also generate the session secret:
```bash
node -e "console.log(require('crypto').randomBytes(48).toString('base64url'))"
```

## 6. Deploy to Vercel

1. Push this folder to a private GitHub repo and **Import** it in Vercel. The framework is detected as Next.js.
2. **Settings → Environment Variables**:
   - **Production**: `APP_ENV=production`, `WORKBOOK_BACKEND=graph`, `APP_ORIGIN=https://signals.swooscale.com`, plus all `AZURE_*`, `GRAPH_DRIVE_ID`, `GRAPH_MASTER_ITEM_ID`, `SESSION_SECRET` and `AUTH_USERS`.
   - **Preview**: `APP_ENV=development` with `GRAPH_DEV_ITEM_ID`, so preview deployments can never touch the master. Use a different `SESSION_SECRET`.
3. Recommended: **Storage → Upstash Redis** (free tier). Add `UPSTASH_REDIS_REST_URL` and `UPSTASH_REDIS_REST_TOKEN`. Without them, failed-login limits are kept per server instance, which is weaker on Vercel.
4. Deploy. Functions run on the Node.js runtime with `maxDuration = 60` seconds, which allows for Graph retries while the file is locked.

## 7. Point the domain (signals.swooscale.com)

1. Vercel → Project → **Settings → Domains → Add** `signals.swooscale.com`.
2. At your DNS provider for `swooscale.com`, add the record Vercel shows. Normally that's
   `CNAME  signals  →  cname.vercel-dns.com` (use the exact target Vercel displays).
3. Wait for the green check. Vercel issues the HTTPS certificate automatically.
4. Make sure `APP_ORIGIN` exactly matches `https://signals.swooscale.com`.

---

## How it behaves

- **Today (/signals)** shows the rows under the most recent separator date in *Signals*. The "Block date" picker lists every block date. The filters are role group, company type, text search and "hide already ticked". The role-group and company-type options are built from the values in the data, so old and new group names both appear exactly as written in the sheet. Each card shows the Role Sub-Group (column R) next to the role group when there is one. Counters read `X signals today · Y ticked`.
- **Tick** appends the full row (A:R, or A:Q when the verify tab has no "Role Sub-Group" header in R1) to the next empty row of the verify tab. The next empty row is found from columns A:Q only. Row identity is the Job URL, normalised as follows: trim, drop the query string and `#fragment`, drop trailing slashes, lower-case the host. Query parameters that identify the job (`jk`, `currentJobId`, `gh_jid`, `jobId`, …) are kept so different jobs don't collide; see `src/lib/job-url.ts`. If the Job URL is already in the verify tab, nothing is written and the row simply shows as ticked. Ticks are worked out from the verify tab on every load, so they survive reloads and show on other devices.
- **Untick** asks for confirmation. It's blocked when column M already has a link, and the app says why. Otherwise the row's cells are cleared, then deleted with shift-up: A:R when R1 is "Role Sub-Group" (so a sub-group never stays behind, out of line), otherwise A:Q. Columns past that width are not moved.
- **Verified (/verified)** groups the verify tab by Date Found, newest first. The filters are "Missing decision maker", "Done" and "All". Each row has one input per LinkedIn link, an "+ Add another" button, and Save. Only `linkedin.com/in/…` and `linkedin.com/sales/…` links are accepted. The row is found again by Job URL right before writing, then the cell is read back, and a green **✓ Saved to master sheet** appears.
- **Busy workbook**: on `409/423/429/503/504` the server retries with backoff. If the file is still busy, the page shows **"Sheet busy, retrying…"** and retries up to 5 more times before it shows a clear error. It never fails silently. The range delete is never retried blindly, because a retry re-locates the row by Job URL first.
- **Caching**: reads are cached for about 60 seconds per server instance. Every write invalidates the verify-tab cache. *Signals* is never written by the app, so its cache only expires by time.

## Security notes

- Login uses an email and password checked against bcrypt hashes (cost 12), with a timing-equalised check for unknown emails. Failed logins are limited per email (5 per 15 minutes) and per IP (20 per 15 minutes).
- Sessions are an HS256-signed JWT in an `httpOnly`, `Secure`, `SameSite=Lax` cookie (`__Host-` prefix in production) that lasts 12 hours.
- Write routes need a session, a same-origin `Origin` header, a JSON body, and the per-session CSRF token in `x-csrf-token`.
- Every page and API route except `/login` requires a session.
- No secrets reach the browser. Graph is only called server-side, and the server modules are marked `server-only`.
- Input is validated on the server: Job URLs and LinkedIn links are checked, and bodies are capped at 20 KB. Text starting with `= + - @` is stored as text so it can't become a formula.
- The app sends a strict CSP, `X-Frame-Options: DENY`, HSTS and `noindex`.

## Development

```bash
npm install
npm test          # vitest
npm run typecheck
npm run dev
```

Tests cover the date-serial conversion, Job URL normalisation, finding the latest block (separator detection), the append-only rule, refusing to write to *Signals*, refusing to untick a row that has a decision maker, and Graph retry behaviour.

## Test on a new server in mock mode

For a beginner-friendly, step-by-step SiteGround walkthrough, see **[DEPLOY-SITEGROUND.md](DEPLOY-SITEGROUND.md)**.

Mock mode runs the full app (login, ticking, decision makers) on **fake in-memory data**. It needs no Microsoft or SharePoint access, so it's safe for a hosting provider to test. The data resets every time the app restarts. Mock mode is refused when `APP_ENV=production`.

**Requirements:** Node.js **22 or 24 LTS** (the minimum is 20.9).

**Build and start:**
```bash
npm ci                 # works with NODE_ENV=production too: build tools are regular dependencies
npm run build
PORT=3000 npm start    # or let the host set PORT; the app listens on whatever PORT says
```
On a managed Node.js host (for example SiteGround Site Tools), use these settings: install `npm ci`, build `npm run build`, start `npm start`, framework Next.js, output `.next`.

**Environment variables for mock mode only** (the values below are dummies, so replace them):
```bash
APP_ENV=development
WORKBOOK_BACKEND=mock
APP_ORIGIN=https://your-test-domain.example        # the exact public URL of the test site
SESSION_SECRET=replace-with-at-least-32-random-characters-0000
AUTH_USERS=tester@example.com|Test User|<base64 bcrypt hash, see below>
```
Leave every `AZURE_*` and `GRAPH_*` variable **unset**. Mock mode doesn't use them.

**Create the test login.** After `npm ci`, on any machine with Node, run this and paste the line it prints into `AUTH_USERS`:
```bash
node -e "const b=require('bcryptjs');console.log('tester@example.com|Test User|'+Buffer.from(b.hashSync(process.argv[1],12)).toString('base64'))" "choose-a-test-password"
```
Or run `npm run hash-password` for an interactive prompt.

**Things to know:**
- **Serve it over HTTPS.** `npm start` runs in production mode, so the login cookie is `Secure`. Over plain `http://` (other than `localhost`) the browser drops the cookie, and logging in just returns you to the login page.
- **Set `APP_ORIGIN`** to the exact public URL, including `https://` and with no trailing slash. Logins and writes from any other origin are refused with "Cross-site request refused".
- **Expect a yellow "MOCK DATA" banner.** It shows on every page and confirms nothing is connected to SharePoint.

## Troubleshooting

- **403 from Graph**: the Sites.Selected site grant is missing or was given to a different app id (repeat step 2).
- **404 "worksheet not found"**: a tab was renamed. The app expects exactly `Signals` and `Signals to verify decision make`.
- **Excel API refuses app-only access** (rare, depends on tenant policy): check that admin consent was granted (step 1.4). As a last resort, `Sites.ReadWrite.All` (application) works but gives access to every site, so prefer fixing the grant.
- **Range delete fails** on the verify tab: if that tab is formatted as an Excel *Table*, range deletes behave differently. Convert it to a normal range or ask for a table-row delete variant.

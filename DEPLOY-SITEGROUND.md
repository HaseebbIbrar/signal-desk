# Deploy Signal Desk on SiteGround (GoGeek) in mock mode

This guide puts Signal Desk on SiteGround for **testing only**. It runs in **mock mode**, which means:

- it uses **fake data** held in memory, with no Microsoft, SharePoint or Excel access;
- every page shows a yellow **"MOCK DATA: not connected to SharePoint"** banner;
- the fake data **resets** whenever the app restarts or redeploys.

You need no secrets from SwooScale. You will create your own test password and session secret.

> SiteGround added Node.js hosting recently and its menus may change. If a name below doesn't match what you see, look for the closest option, or ask SiteGround's live chat, "How do I deploy a Next.js app as a Node.js Project?".

---

## What you need

1. A SiteGround **GoGeek** (or GrowBig/Cloud) account. StartUp can't run Node.js.
2. Either access to the GitHub repo `HaseebbIbrar/signal-desk`, or the file `signal-desk.zip`.
3. A computer with **Node.js 22 or 24 LTS**, used once to create the test login hash (step 2). If you don't have one, you can do step 2 over SSH instead (Part D).

---

## Part A: prepare the values (5 minutes)

**Step 1. Pick the test address.** Use the free temporary domain SiteGround gives the project (simplest), or a subdomain such as `signals-test.example.com` (Part C). Write it down with `https://` in front and no slash at the end, e.g. `https://signals-test.example.com`. This is your **APP_ORIGIN**.

**Step 2. Create the test login.**
1. Unzip `signal-desk.zip` into an empty folder (the files, including `package.json`, are at the top level of the zip), or clone the repo.
2. Open a terminal in that folder and run:
   ```bash
   npm ci
   node -e "const b=require('bcryptjs');console.log('tester@example.com|Test User|'+Buffer.from(b.hashSync(process.argv[1],12)).toString('base64'))" "PUT-A-TEST-PASSWORD-HERE"
   ```
3. Copy the whole line it prints (it starts `tester@example.com|Test User|`). This is your **AUTH_USERS** value. Your password is the one you typed in the command.

**Step 3. Create a session secret** (any 32 or more random characters):
```bash
node -e "console.log(require('crypto').randomBytes(48).toString('base64url'))"
```
Copy the result. This is your **SESSION_SECRET**.

---

## Part B: create the Node.js project in SiteGround

**Step 4.** Log in to SiteGround and go to **Client Area → Websites → Node.js Projects → Add Node.js Project**.

**Step 5. Choose the source.**
- **GitHub:** connect your GitHub account, pick the repo `signal-desk` and the branch `main`. The repo is private, so the GitHub account you connect must have been given access to it.
- **Or upload:** choose the manual / ZIP upload option and upload `signal-desk.zip`.

**Step 6. Build settings.** Change the pre-filled values to these:

| Setting | Value |
|---|---|
| Framework preset | **Next.js** (or "Other / Node.js" if Next.js isn't listed) |
| Node version | **22** or **24** |
| Package manager | **npm** |
| Install command (if shown) | `npm ci` |
| Build command | `npm run build`. If there is **no** separate install field, use `npm ci && npm run build` |
| Start command (if shown) | `npm start` |
| Output directory (if asked) | `.next` |

The build works even when SiteGround installs in production mode (`NODE_ENV=production`, which skips devDependencies), because everything the build needs (Next.js, Tailwind, TypeScript and the type packages) is a regular dependency. Only the test tool (`vitest`) is a devDependency, and the build doesn't need it.

**Step 7. Environment variables.** Add exactly these five, using your values from Part A:

| Name | Value |
|---|---|
| `APP_ENV` | `development` |
| `WORKBOOK_BACKEND` | `mock` |
| `APP_ORIGIN` | your address from step 1, e.g. `https://signals-test.example.com` |
| `SESSION_SECRET` | the value from step 3 |
| `AUTH_USERS` | the line from step 2 |

Don't add any `AZURE_*` or `GRAPH_*` variables. Mock mode doesn't use them.
`PORT`: leave it unset if SiteGround sets it automatically. The app listens on whatever `PORT` says. If SiteGround asks you which port your app uses, enter `3000` and also add `PORT=3000`.

**Step 8.** Click **Deploy** / **Create** and wait for the build to finish. Then open the build log and check it ends without errors. A good build lists routes such as `/login`, `/signals` and `/verified`.

**Step 9. Keeping it running.** A SiteGround **Node.js Project** is managed by SiteGround: it is started for you after each deploy and kept running. You don't need `pm2`, `nohup` or `screen`.
- **To restart:** use the project's restart/redeploy button, or push a new commit to `main` (GitHub deploys rebuild automatically).
- **Don't** start the app yourself over SSH with `npm start &`. On shared hosting, processes started by hand are not supervised and are normally stopped after a while.

---

## Part C: HTTPS on a subdomain (optional)

You can skip this part if you test on SiteGround's temporary domain, which already has HTTPS.

**Step 10.** In **Client Area → Websites → (your Node.js project)**, choose the option to **point / change domain** and enter your subdomain, e.g. `signals-test.example.com`.

**Step 11. DNS.** SiteGround shows which DNS record the subdomain needs, usually an **A record** to an IP address, or a **CNAME**.
- If the domain's DNS is managed **at SiteGround**, it's added for you.
- If the DNS is somewhere else, add that record there yourself. For example, SwooScale's DNS is at 20i/StackCP (`ns1–ns4.stackdns.com`).
- **Don't** use `signals.swooscale.com`. That name is reserved for the real production app.

**Step 12. SSL.** Once DNS has updated (usually minutes, sometimes up to a few hours), go to **Site Tools → Security → SSL Manager**. Install a free **Let's Encrypt** certificate for the subdomain, if SiteGround hasn't done it automatically, and turn on **HTTPS Enforce**.

**Step 13.** If you changed the address, update the `APP_ORIGIN` variable to the new `https://…` address (no trailing slash) and redeploy.

HTTPS is **required**. The login cookie is marked `Secure`, so over plain `http://` the browser drops it and you go straight back to the login page.

---

## Part D: SSH (optional, for checks)

You don't need SSH to deploy. It's useful for checking the site and as an alternative way to do step 2.

**Step 14. Turn on SSH.**
1. Go to **Site Tools → Devs → SSH Keys Manager**.
2. Create a key, set a passphrase, and download the **private key**.
3. Note the **host, username and port** that SiteGround shows (the port is usually **18765**).

**Step 15. Connect** from a terminal:
```bash
ssh -i path/to/private-key -p 18765 USERNAME@HOSTNAME
```

**Step 16. Useful commands once connected:**
```bash
node -v                                   # should be v22 or v24
curl -sI https://YOUR-ADDRESS/login | head -5      # expect HTTP 200
curl -s https://YOUR-ADDRESS/api/signals           # expect {"error":"Please sign in again.","code":"UNAUTHENTICATED"}
```

---

## Part E: check it works

**Step 17.** Open `https://YOUR-ADDRESS`. You should be sent to **/login**, with the yellow **MOCK DATA** banner on every page after login.

**Step 18.** Log in as `tester@example.com` with your test password. You should land on **Today** with a message like "8 signals today · 0 ticked".

**Step 19.** Tick one signal. A green message should say *"Added … to the verify tab (row 6)."*

**Step 20.** Open **Verified**. Paste `https://www.linkedin.com/in/test-person` into a row and click **Save**. You should see a green **✓ Saved to master sheet**. In mock mode this only saves to the fake in-memory data.

**Step 21.** Try `https://www.linkedin.com/company/test`. It should be **refused**, because only `/in/` and `/sales/` links are allowed.

**Step 22.** Log out, then try a wrong password. You should see "Invalid email or password." After 5 wrong tries you are locked out for 15 minutes. That is expected.

If all of this works, the deployment is good.

---

## Troubleshooting

| What you see | Cause | Fix |
|---|---|---|
| Build fails: `Cannot find module '@tailwindcss/postcss'` / `typescript` | An old copy of the code, from before the build tools were moved into `dependencies` | Deploy the latest `main` branch or the latest `signal-desk.zip`. As a stop-gap, use `npm ci --include=dev` |
| Site 404s and the log lists only /404, even with the Custom preset | SiteGround's fix_typescript deletes our config files | Latest zip (build script recreates them; see the [sg-prepare] lines in the build log) |
| Build succeeds but the site shows 404 and the log lists only `/404` | SiteGround's TypeScript step rewrote next.config.ts | Use the latest zip (next.config.mjs) |
| Build crashes in "Running TypeScript" with `readlink /proc/self/exe` | TypeScript 7 native binary | Use the latest zip, which uses TypeScript 5.9 |
| Build fails: `Cannot find module 'vitest'` or type errors in `tests/` | An old `tsconfig.json` that still type-checks the test files | Deploy the latest code (its `tsconfig.json` excludes `tests`, `tests-integration` and the vitest configs) |
| Upload fails or the build can't find `package.json` | The zip has an extra folder level | Use the latest `signal-desk.zip`, which has `package.json` at the top level |
| Build fails: `npm ci` needs a lock file | `package-lock.json` is missing from the upload | Re-zip with `package-lock.json` included, or use the GitHub repo |
| Build fails: unsupported Node / syntax errors | Node version too old | Set Node **22** or **24** (minimum 20.9) |
| Login sends you straight back to the login page | Site opened over `http://`, so the Secure cookie was dropped | Use `https://` and turn on HTTPS Enforce (step 12) |
| "Cross-site request refused." | `APP_ORIGIN` doesn't exactly match the address in the browser | Set `APP_ORIGIN` to the exact `https://…` address, with no trailing slash and the same `www` or no `www`. Redeploy |
| "Invalid email or password." with the right password | `AUTH_USERS` was copied incompletely, or the password differs | Re-run step 2 and paste the **whole** line again. Redeploy |
| "Too many failed attempts…" | 5 wrong logins in 15 minutes | Wait 15 minutes, or restart the project |
| "Something went wrong on the server" on login or on every page | A required variable is missing or invalid | Check the runtime/deploy log for the exact message (see the next rows) |
| Log: `SESSION_SECRET must be set to at least 32 random characters` | Session secret too short or missing | Redo step 3 |
| Log: `AUTH_USERS: the hash for … is not a base64-encoded bcrypt hash` | Wrong value in `AUTH_USERS` | Redo step 2. The format is `email\|Name\|base64hash` |
| Log: `Missing required environment variable GRAPH_MASTER_ITEM_ID` (or `AZURE_…`) | `WORKBOOK_BACKEND` is not `mock` | Set `WORKBOOK_BACKEND=mock` |
| Log: `WORKBOOK_BACKEND=mock is not allowed when APP_ENV=production` | `APP_ENV` is set to `production` | Set `APP_ENV=development` for testing |
| 502 / 503 / "Application not available" | The app isn't running or isn't on the expected port | Check that the build succeeded, then restart. If SiteGround asked for a port, also set `PORT` to that number |
| Ticks disappear after a while | Mock data lives in memory and resets on every restart or redeploy | Expected in mock mode |
| Ticks sometimes show and sometimes don't | More than one copy of the app is running, each with its own fake data | Expected in mock mode. Ask SiteGround to run a single instance if it matters |
| Subdomain shows a certificate warning | SSL not issued yet, or DNS not pointing to SiteGround | Wait for DNS, then repeat step 12 |

**Still stuck?** Send SwooScale the build log and the exact error message. **Never** send passwords or the `SESSION_SECRET`.

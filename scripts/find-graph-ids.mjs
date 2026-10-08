#!/usr/bin/env node
// Resolve GRAPH_SITE_ID / GRAPH_DRIVE_ID / item ids with the app's own credentials, and prove the
// Sites.Selected grant works by doing a READ-ONLY usedRange call on both tabs. Writes nothing.
//
// Usage (reads .env.local via `npm run find-ids`):
//   npm run find-ids
//   npm run find-ids -- --site-path /sites/SwooscaleHub
//   npm run find-ids -- --dev-file "SwooScale Signal Folder/New workflow JP/Master sheet Signals/SwooScale Master Data - DEV COPY.xlsx"
//
// Env used: AZURE_TENANT_ID, AZURE_CLIENT_ID, AZURE_CLIENT_SECRET, and optionally GRAPH_SITE_ID,
// SHAREPOINT_HOST (default swoosearch.sharepoint.com), SHAREPOINT_SITE_PATH (e.g. /sites/SwooscaleHub).

const args = process.argv.slice(2);
const arg = (name) => {
  const i = args.indexOf(name);
  return i >= 0 ? args[i + 1] : undefined;
};

const MASTER_PATH = "SwooScale Signal Folder/New workflow JP/Master sheet Signals/SwooScale Master Data.xlsx";
const tenant = process.env.AZURE_TENANT_ID;
const clientId = process.env.AZURE_CLIENT_ID;
const secret = process.env.AZURE_CLIENT_SECRET;
const host = process.env.SHAREPOINT_HOST || "swoosearch.sharepoint.com";
const sitePath = arg("--site-path") || process.env.SHAREPOINT_SITE_PATH;
let siteId = arg("--site-id") || process.env.GRAPH_SITE_ID;
const masterPath = arg("--file") || MASTER_PATH;
const devPath = arg("--dev-file");
const libraryName = arg("--library") || "Documents";

if (!tenant || !clientId || !secret) {
  console.error("Set AZURE_TENANT_ID, AZURE_CLIENT_ID and AZURE_CLIENT_SECRET in .env.local first.");
  process.exit(1);
}

const tokenRes = await fetch(`https://login.microsoftonline.com/${tenant}/oauth2/v2.0/token`, {
  method: "POST",
  headers: { "Content-Type": "application/x-www-form-urlencoded" },
  body: new URLSearchParams({ grant_type: "client_credentials", client_id: clientId, client_secret: secret, scope: "https://graph.microsoft.com/.default" }),
});
if (!tokenRes.ok) {
  console.error("Token request failed:", tokenRes.status, await tokenRes.text());
  process.exit(1);
}
const { access_token: token } = await tokenRes.json();

async function graph(path) {
  const res = await fetch(`https://graph.microsoft.com/v1.0${path}`, { headers: { Authorization: `Bearer ${token}` } });
  const text = await res.text();
  if (!res.ok) throw new Error(`${res.status} on GET ${path}\n${text.slice(0, 600)}`);
  return JSON.parse(text);
}
const encodePath = (p) => p.split("/").map(encodeURIComponent).join("/");

try {
  if (!siteId) {
    if (!sitePath) {
      console.error("Pass --site-path /sites/<SiteName> (or set GRAPH_SITE_ID). See README step 3.");
      process.exit(1);
    }
    const site = await graph(`/sites/${host}:${sitePath}`);
    siteId = site.id;
    console.log(`Site: ${site.displayName}  (${site.webUrl})`);
  }

  const drives = (await graph(`/sites/${siteId}/drives?$select=id,name,webUrl`)).value;
  console.log("\nDocument libraries on this site:");
  for (const d of drives) console.log(`  - ${d.name}  ${d.id}`);
  const drive = drives.find((d) => d.name === libraryName) || drives.find((d) => /documents/i.test(d.name));
  if (!drive) throw new Error(`No library called "${libraryName}" found`);

  async function describe(label, p) {
    const item = await graph(`/drives/${drive.id}/root:/${encodePath(p)}?$select=id,name,webUrl,lastModifiedDateTime`);
    console.log(`\n${label}: ${item.name}\n  item id: ${item.id}\n  modified: ${item.lastModifiedDateTime}\n  ${item.webUrl}`);
    for (const tab of ["Signals", "Signals to verify decision make"]) {
      const ws = `worksheets('${encodeURIComponent(tab)}')`;
      const r = await graph(`/drives/${drive.id}/items/${item.id}/workbook/${ws}/usedRange(valuesOnly=true)?$select=address,rowCount`);
      console.log(`  read OK  "${tab}"  used range ${r.address} (${r.rowCount} rows)`);
    }
    return item;
  }

  const master = await describe("MASTER", masterPath);
  const dev = devPath ? await describe("DEV COPY", devPath) : null;

  console.log("\n# Put these in .env.local / Vercel:");
  console.log(`GRAPH_SITE_ID=${siteId}`);
  console.log(`GRAPH_DRIVE_ID=${drive.id}`);
  console.log(`GRAPH_MASTER_ITEM_ID=${master.id}`);
  if (dev) console.log(`GRAPH_DEV_ITEM_ID=${dev.id}`);
} catch (e) {
  console.error("\nFailed:", e.message);
  console.error("403 = the Sites.Selected grant is missing for this site. 404 = wrong site path, library or file path.");
  process.exit(1);
}

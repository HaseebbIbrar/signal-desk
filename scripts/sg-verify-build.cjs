#!/usr/bin/env node
// Runs after `next build` (see package.json "build"). Fails the build, loudly, if the production
// output does not contain the app's pages. That is what a SiteGround build that silently produced
// only /404 looked like.
//
// It reads <distDir>/server/app-paths-manifest.json, which `next build` writes for App Router routes
// (keys such as "/login/page"). distDir is .next unless SG_DIST_DIR says otherwise.

"use strict";

const fs = require("node:fs");
const path = require("node:path");

const P = "[sg-verify]";
const REQUIRED = ["/login/page", "/signals/page", "/verified/page"];

function findManifest(root, env = process.env) {
  const candidates = [];
  if (env.SG_DIST_DIR) candidates.push(env.SG_DIST_DIR);
  candidates.push(".next");
  for (const dir of candidates) {
    const p = path.join(root, dir, "server", "app-paths-manifest.json");
    if (fs.existsSync(p)) return p;
  }
  return null;
}

function verify(root, log = console.log, env = process.env) {
  const manifestPath = findManifest(root, env);
  if (!manifestPath) {
    log(`${P} FAILED: app routes missing (no server/app-paths-manifest.json in .next${env.SG_DIST_DIR ? ` or ${env.SG_DIST_DIR}` : ""})`);
    return false;
  }
  let keys = [];
  try {
    keys = Object.keys(JSON.parse(fs.readFileSync(manifestPath, "utf8")));
  } catch (e) {
    log(`${P} FAILED: app routes missing (could not read ${manifestPath}: ${e.message})`);
    return false;
  }
  const missing = REQUIRED.filter((k) => !keys.includes(k));
  const api = keys.filter((k) => k.startsWith("/api/")).length;
  const rel = path.relative(root, manifestPath).split(path.sep).join("/");
  if (missing.length) {
    log(`${P} FAILED: app routes missing: ${missing.map((k) => k.replace(/\/page$/, "")).join(", ")} (read ${rel}; it lists ${keys.length} route(s): ${keys.join(", ") || "none"})`);
    return false;
  }
  log(`${P} OK: /login, /signals and /verified are built, plus ${api} /api route(s) (${rel})`);
  return true;
}

module.exports = { verify, findManifest, REQUIRED };

if (require.main === module) {
  if (!verify(path.resolve(__dirname, ".."))) process.exit(1);
}

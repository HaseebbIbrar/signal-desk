// Source snapshot: every file under src/ plus a few root files, packed into ONE plain JSON file
// (scripts/src-snapshot.json, path -> base64 contents + sha256). SiteGround's hidden
// `fix_typescript` step alters files after `npm install`; the build restores them from here.
//
// Plain CommonJS on purpose: no TypeScript, and not named like a config file.

"use strict";

const crypto = require("node:crypto");
const fs = require("node:fs");
const path = require("node:path");

const SNAPSHOT_FILE = "scripts/src-snapshot.json";
const ROOT_FILES = ["next.config.mjs", "postcss.config.mjs", "tsconfig.json"];
const BACKUP_DIR = ".sg-removed";
// Text files are stored with LF line endings so a Windows checkout and a Linux server agree.
const TEXT_EXT = new Set([".ts", ".tsx", ".js", ".jsx", ".mjs", ".cjs", ".json", ".css", ".md", ".txt", ".svg", ".html"]);

function toPosix(p) {
  return p.split(path.sep).join("/");
}

/** Content as stored in the snapshot (LF for text files, raw bytes otherwise). */
function normalised(rel, buf) {
  if (!TEXT_EXT.has(path.extname(rel).toLowerCase())) return buf;
  return Buffer.from(buf.toString("utf8").replace(/\r\n/g, "\n"), "utf8");
}

const sha256 = (buf) => crypto.createHash("sha256").update(buf).digest("hex");

function walk(dir, root, out) {
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, root, out);
    else if (entry.isFile()) out.push(toPosix(path.relative(root, full)));
  }
  return out;
}

/** Files the snapshot covers right now: everything under src/ plus ROOT_FILES that exist. */
function currentFiles(root) {
  const files = walk(path.join(root, "src"), root, []);
  for (const f of ROOT_FILES) if (fs.existsSync(path.join(root, f))) files.push(f);
  return files.sort();
}

/** Build the snapshot object from the files on disk. Deterministic: no timestamps. */
function buildSnapshot(root) {
  const files = {};
  for (const rel of currentFiles(root)) {
    const buf = normalised(rel, fs.readFileSync(path.join(root, rel)));
    files[rel] = { size: buf.length, sha256: sha256(buf), base64: buf.toString("base64") };
  }
  return { format: "signal-desk-src-snapshot/1", covers: ["src/**", ...ROOT_FILES], files };
}

function serialise(snapshot) {
  return JSON.stringify(snapshot, null, 1) + "\n";
}

function readSnapshot(root) {
  const p = path.join(root, SNAPSHOT_FILE);
  if (!fs.existsSync(p)) return null;
  return JSON.parse(fs.readFileSync(p, "utf8"));
}

/** Compare the disk against the snapshot: missing, changed (with sizes) and extra files. */
function diffAgainstSnapshot(root, snapshot) {
  const expected = snapshot.files;
  const onDisk = new Set(currentFiles(root));
  const missing = [];
  const changed = [];
  const extra = [];
  for (const [rel, meta] of Object.entries(expected)) {
    const full = path.join(root, rel);
    if (!fs.existsSync(full)) {
      missing.push(rel);
      continue;
    }
    const buf = normalised(rel, fs.readFileSync(full));
    if (sha256(buf) !== meta.sha256) changed.push({ path: rel, oldSize: meta.size, newSize: buf.length, preview: buf.toString("utf8").slice(0, 300) });
  }
  for (const rel of onDisk) {
    // Extra = under src/ but not in the snapshot. (Root files outside ROOT_FILES are not ours to judge.)
    if (rel.startsWith("src/") && !(rel in expected)) extra.push(rel);
  }
  return { missing, changed, extra };
}

function backup(root, rel, stamp) {
  const dest = path.join(root, BACKUP_DIR, stamp, rel);
  fs.mkdirSync(path.dirname(dest), { recursive: true });
  return dest;
}

/**
 * Make the disk match the snapshot exactly. Nothing is deleted: changed files are copied to
 * .sg-removed/<stamp>/ before being overwritten, and extra files under src/ are moved there.
 */
function restoreSnapshot(root, snapshot, diff, stamp = new Date().toISOString().replace(/[:.]/g, "-")) {
  for (const c of diff.changed) fs.copyFileSync(path.join(root, c.path), backup(root, c.path, stamp));
  for (const rel of diff.extra) fs.renameSync(path.join(root, rel), backup(root, rel, stamp));
  for (const rel of [...diff.missing, ...diff.changed.map((c) => c.path)]) {
    const full = path.join(root, rel);
    fs.mkdirSync(path.dirname(full), { recursive: true });
    fs.writeFileSync(full, Buffer.from(snapshot.files[rel].base64, "base64"));
  }
  return path.join(BACKUP_DIR, stamp);
}

module.exports = {
  SNAPSHOT_FILE,
  ROOT_FILES,
  BACKUP_DIR,
  buildSnapshot,
  serialise,
  readSnapshot,
  diffAgainstSnapshot,
  restoreSnapshot,
  currentFiles,
};

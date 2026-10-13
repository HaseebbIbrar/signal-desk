#!/usr/bin/env node
// Regenerate scripts/src-snapshot.json from src/ and the root configs.
// Run after ANY change under src/ (or to next.config.mjs, postcss.config.mjs, tsconfig.json):
//   npm run snapshot
// tests/src-snapshot.test.ts fails while the snapshot is stale.

"use strict";

const fs = require("node:fs");
const path = require("node:path");
const { SNAPSHOT_FILE, buildSnapshot, serialise } = require("./src-snapshot-lib.cjs");

const root = path.resolve(__dirname, "..");
const snapshot = buildSnapshot(root);
fs.writeFileSync(path.join(root, SNAPSHOT_FILE), serialise(snapshot));
const files = Object.keys(snapshot.files);
const bytes = Object.values(snapshot.files).reduce((n, f) => n + f.size, 0);
console.log(`[snapshot] wrote ${SNAPSHOT_FILE}: ${files.length} files, ${bytes} bytes of source`);

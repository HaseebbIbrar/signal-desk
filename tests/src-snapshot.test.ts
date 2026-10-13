// Source snapshot (scripts/src-snapshot.json), restore-before-build and verify-after-build.

import { spawnSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { createRequire } from "node:module";
import { afterEach, describe, expect, it } from "vitest";

const require = createRequire(import.meta.url);
const root = path.resolve(import.meta.dirname, "..");
const lib = require(path.join(root, "scripts/src-snapshot-lib.cjs")) as {
  SNAPSHOT_FILE: string;
  buildSnapshot: (dir: string) => { files: Record<string, { size: number; sha256: string; base64: string }> };
  serialise: (s: unknown) => string;
};
const sg = require(path.join(root, "scripts/sg-prepare-build.cjs")) as {
  prepare: (dir: string, log?: (s: string) => void, env?: Record<string, string | undefined>) => string[];
};
const verifier = require(path.join(root, "scripts/sg-verify-build.cjs")) as {
  verify: (dir: string, log?: (s: string) => void, env?: Record<string, string | undefined>) => boolean;
};

const dirs: string[] = [];
afterEach(() => {
  for (const d of dirs.splice(0)) fs.rmSync(d, { recursive: true, force: true });
});
function tmpDir(): string {
  const d = fs.mkdtempSync(path.join(os.tmpdir(), "sg-snap-"));
  dirs.push(d);
  return d;
}
/** A copy of the project containing only what the snapshot restores from: package.json + the snapshot. */
function freshProjectFromSnapshot(): string {
  const d = tmpDir();
  fs.mkdirSync(path.join(d, "scripts"));
  fs.copyFileSync(path.join(root, "package.json"), path.join(d, "package.json"));
  fs.copyFileSync(path.join(root, lib.SNAPSHOT_FILE), path.join(d, lib.SNAPSHOT_FILE));
  sg.prepare(d, () => {}, {}); // restores every snapshot file (all "missing")
  return d;
}
const text = (d: string, f: string) => fs.readFileSync(path.join(d, f), "utf8");
const run = (d: string, env: Record<string, string | undefined> = {}) => {
  const lines: string[] = [];
  const actions = sg.prepare(d, (s) => lines.push(s), env);
  return { actions, log: lines.join("\n") };
};

describe("source snapshot", () => {
  it("is up to date with src/ and the root configs (run `npm run snapshot` if this fails)", () => {
    const committed = fs.readFileSync(path.join(root, lib.SNAPSHOT_FILE), "utf8").replace(/\r\n/g, "\n");
    expect(committed).toBe(lib.serialise(lib.buildSnapshot(root)));
  });

  it("covers every file under src/ plus next.config.mjs, postcss.config.mjs and tsconfig.json", () => {
    const files = Object.keys(lib.buildSnapshot(root).files);
    expect(files).toEqual(expect.arrayContaining(["src/app/layout.tsx", "src/app/login/page.tsx", "src/lib/service.ts", "next.config.mjs", "postcss.config.mjs", "tsconfig.json"]));
    expect(files.some((f) => f.startsWith("tests/") || f.startsWith("scripts/"))).toBe(false);
  });

  it("restores a fresh project exactly from the snapshot", () => {
    const d = freshProjectFromSnapshot();
    expect(lib.serialise(lib.buildSnapshot(d))).toBe(lib.serialise(lib.buildSnapshot(root)));
  });
});

describe("restore before build (the SiteGround fix_typescript worst case)", () => {
  it("logs each tampered file, restores exactly, and keeps the tampered versions in .sg-removed/", () => {
    const d = freshProjectFromSnapshot();
    const original = lib.serialise(lib.buildSnapshot(d));
    fs.rmSync(path.join(d, "src/app/layout.tsx"));
    fs.renameSync(path.join(d, "src/app/login/page.tsx"), path.join(d, "src/app/login/page.tsx.bak"));
    fs.writeFileSync(path.join(d, "src/app/signals/page.tsx"), "");
    fs.rmSync(path.join(d, "next.config.mjs"));
    fs.rmSync(path.join(d, "postcss.config.mjs"));
    fs.writeFileSync(path.join(d, "next.config.js"), 'module.exports = { pageExtensions: ["js","jsx"] }\n');

    const { log } = run(d, { NODE_ENV: "production", NEXT_TELEMETRY_DISABLED: "1", SECRET_TOKEN: "x" });

    expect(log).toContain("[sg-prepare] env var names (NEXT_*, NODE_*, *TYPESCRIPT*): NEXT_TELEMETRY_DISABLED, NODE_ENV");
    expect(log).not.toContain("SECRET_TOKEN");
    expect(log).toContain("[sg-prepare] MISSING src/app/layout.tsx");
    expect(log).toContain("[sg-prepare] MISSING src/app/login/page.tsx");
    expect(log).toContain("[sg-prepare] MISSING next.config.mjs");
    expect(log).toContain("[sg-prepare] MISSING postcss.config.mjs");
    expect(log).toMatch(/\[sg-prepare\] CHANGED src\/app\/signals\/page\.tsx \(snapshot \d+ bytes, now 0 bytes\)/);
    expect(log).toContain("[sg-prepare] EXTRA src/app/login/page.tsx.bak");
    expect(log).toContain("renamed host config next.config.js -> next.config.host.cjs");

    // src/ and the root configs are exactly the snapshot again
    expect(lib.serialise(lib.buildSnapshot(d))).toBe(original);
    expect(fs.existsSync(path.join(d, "src/app/login/page.tsx.bak"))).toBe(false);
    // nothing deleted: the extra file and the emptied file's tampered version are kept
    const kept = fs.readdirSync(path.join(d, ".sg-removed"));
    expect(kept).toHaveLength(1);
    expect(fs.existsSync(path.join(d, ".sg-removed", kept[0], "src/app/login/page.tsx.bak"))).toBe(true);
    expect(text(path.join(d, ".sg-removed", kept[0]), "src/app/signals/page.tsx")).toBe("");
    expect(text(d, "next.config.host.cjs")).toContain('pageExtensions: ["js","jsx"]');
  });

  it("first 300 characters of a changed file are logged", () => {
    const d = freshProjectFromSnapshot();
    fs.writeFileSync(path.join(d, "src/app/page.tsx"), `// tampered\n${"x".repeat(400)}`);
    const { log } = run(d);
    expect(log).toContain("[sg-prepare] |   // tampered");
    expect(log).toContain(`[sg-prepare] |   ${"x".repeat(288)}`);
    expect(log).not.toContain("x".repeat(289));
  });

  it("SG_PREPARE_NO_RESTORE=1 reports differences without changing anything", () => {
    const d = freshProjectFromSnapshot();
    fs.writeFileSync(path.join(d, "src/app/signals/page.tsx"), "");
    const { log } = run(d, { SG_PREPARE_NO_RESTORE: "1" });
    expect(log).toContain("CHANGED src/app/signals/page.tsx");
    expect(log).toContain("reporting only, nothing restored");
    expect(text(d, "src/app/signals/page.tsx")).toBe("");
  });
});

describe("verify after build", () => {
  function withManifest(keys: string[] | null): string {
    const d = tmpDir();
    if (keys) {
      fs.mkdirSync(path.join(d, ".next/server"), { recursive: true });
      fs.writeFileSync(path.join(d, ".next/server/app-paths-manifest.json"), JSON.stringify(Object.fromEntries(keys.map((k) => [k, "app" + k + ".js"]))));
    }
    return d;
  }
  const verifyLog = (d: string) => {
    const lines: string[] = [];
    const ok = verifier.verify(d, (s) => lines.push(s), {});
    return { ok, log: lines.join("\n") };
  };

  it("passes when /login, /signals and /verified are built", () => {
    const { ok, log } = verifyLog(withManifest(["/login/page", "/signals/page", "/verified/page", "/api/signals/route", "/page"]));
    expect(ok).toBe(true);
    expect(log).toContain("[sg-verify] OK");
  });

  it("fails clearly when a route is missing, or when there is no manifest (a /404-only build)", () => {
    const partial = verifyLog(withManifest(["/login/page", "/verified/page"]));
    expect(partial.ok).toBe(false);
    expect(partial.log).toContain("[sg-verify] FAILED: app routes missing: /signals");
    const none = verifyLog(withManifest(null));
    expect(none.ok).toBe(false);
    expect(none.log).toContain("[sg-verify] FAILED: app routes missing");
  });

  it("exits with code 1 when run as the build step and routes are missing", () => {
    const d = withManifest(["/login/page"]);
    fs.mkdirSync(path.join(d, "scripts"));
    fs.copyFileSync(path.join(root, "scripts/sg-verify-build.cjs"), path.join(d, "scripts/sg-verify-build.cjs"));
    const r = spawnSync(process.execPath, [path.join(d, "scripts/sg-verify-build.cjs")], { encoding: "utf8" });
    expect(r.status).toBe(1);
    expect(r.stdout).toContain("[sg-verify] FAILED: app routes missing: /signals, /verified");
  });
});

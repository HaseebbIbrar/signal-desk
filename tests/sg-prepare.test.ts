// scripts/sg-prepare-build.cjs: restores our Next/PostCSS config after SiteGround's
// fix_typescript step deletes it and injects its own next config.

import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { createRequire } from "node:module";
import { pathToFileURL } from "node:url";
import { afterEach, describe, expect, it } from "vitest";

const require = createRequire(import.meta.url);
const root = path.resolve(import.meta.dirname, "..");
const sg = require(path.join(root, "scripts/sg-prepare-build.cjs")) as {
  MARKER: string;
  NEXT_CONFIG: string;
  POSTCSS_CONFIG: string;
  prepare: (dir: string, log?: (s: string) => void) => string[];
};

const dirs: string[] = [];
function tmp(files: Record<string, string>): string {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "sg-prepare-"));
  dirs.push(dir);
  fs.writeFileSync(path.join(dir, "package.json"), JSON.stringify({ name: "x", private: true }));
  for (const [name, text] of Object.entries(files)) {
    fs.mkdirSync(path.dirname(path.join(dir, name)), { recursive: true });
    fs.writeFileSync(path.join(dir, name), text);
  }
  return dir;
}
const read = (dir: string, f: string) => fs.readFileSync(path.join(dir, f), "utf8");
const run = (dir: string) => {
  const lines: string[] = [];
  const actions = sg.prepare(dir, (s) => lines.push(s));
  return { actions, log: lines.join("\n") };
};

afterEach(() => {
  for (const d of dirs.splice(0)) fs.rmSync(d, { recursive: true, force: true });
});

describe("sg-prepare-build", () => {
  it("the committed next.config.mjs and postcss.config.mjs are exactly the script's embedded copies", () => {
    expect(read(root, "next.config.mjs")).toBe(sg.NEXT_CONFIG);
    expect(read(root, "postcss.config.mjs")).toBe(sg.POSTCSS_CONFIG);
  });

  it("is a no-op (logging only) when the configs are already ours", () => {
    const dir = tmp({ "next.config.mjs": sg.NEXT_CONFIG, "postcss.config.mjs": sg.POSTCSS_CONFIG });
    const { actions, log } = run(dir);
    expect(actions).toEqual([]);
    expect(log).toContain("[sg-prepare] nothing to change");
    expect(read(dir, "next.config.mjs")).toBe(sg.NEXT_CONFIG);
  });

  it("SiteGround case: prints the host config, renames it (CommonJS -> .cjs), writes ours and postcss", () => {
    const host = 'module.exports = { pageExtensions: ["js","jsx"] }\n';
    const dir = tmp({ "next.config.js": host, "src/app/page.tsx": "x", ".env": "SECRET=1" });
    const { log } = run(dir);
    expect(log).toContain("[sg-prepare] ----- next.config.js");
    expect(log).toContain('[sg-prepare] | module.exports = { pageExtensions: ["js","jsx"] }');
    expect(fs.existsSync(path.join(dir, "next.config.js"))).toBe(false);
    expect(read(dir, "next.config.host.cjs")).toBe(host);
    expect(read(dir, "next.config.mjs")).toBe(sg.NEXT_CONFIG);
    expect(read(dir, "postcss.config.mjs")).toBe(sg.POSTCSS_CONFIG);
    expect(read(dir, "src/app/page.tsx")).toBe("x"); // untouched
    expect(read(dir, ".env")).toBe("SECRET=1"); // untouched
  });

  it("keeps an ESM host config as .mjs and a foreign next.config.mjs is renamed, not overwritten", () => {
    const esmJs = tmp({ "next.config.js": "export default { output: 'standalone' }\n" });
    run(esmJs);
    expect(read(esmJs, "next.config.host.mjs")).toContain("standalone");

    const foreignMjs = tmp({ "next.config.mjs": "export default { reactStrictMode: true }\n" });
    run(foreignMjs);
    expect(read(foreignMjs, "next.config.host.mjs")).toContain("reactStrictMode");
    expect(read(foreignMjs, "next.config.mjs")).toBe(sg.NEXT_CONFIG);
  });

  it("only writes postcss.config.mjs when no postcss config exists, and warns if one lacks Tailwind", () => {
    const dir = tmp({ "next.config.mjs": sg.NEXT_CONFIG, "postcss.config.js": "module.exports = { plugins: {} }\n" });
    const { log } = run(dir);
    expect(fs.existsSync(path.join(dir, "postcss.config.mjs"))).toBe(false);
    expect(log).toContain("WARNING: postcss.config.js does not load @tailwindcss/postcss");
  });

  it("our config merges the host's: our pageExtensions/poweredByHeader win, host headers are kept, ours appended", async () => {
    const dir = tmp({
      "next.config.js":
        'module.exports = (phase) => ({ pageExtensions: ["js","jsx"], poweredByHeader: true, compress: false, output: "export",' +
        ' headers: async () => [{ source: "/host", headers: [{ key: "X-Host", value: "1" }] }] });\n',
    });
    run(dir);
    const mod = await import(pathToFileURL(path.join(dir, "next.config.mjs")).href + `?t=${Date.now()}`);
    const cfg = await mod.default("phase-production-build", {});
    expect(cfg.pageExtensions).toEqual(["tsx", "ts", "jsx", "js"]);
    expect(cfg.poweredByHeader).toBe(false);
    expect(cfg.compress).toBe(false); // host setting kept
    expect(cfg.output).toBeUndefined(); // static export would break the API routes
    const headers = await cfg.headers();
    expect(headers[0]).toEqual({ source: "/host", headers: [{ key: "X-Host", value: "1" }] });
    expect(headers.at(-1).headers.map((h: { key: string }) => h.key)).toContain("Content-Security-Policy");
  });
});

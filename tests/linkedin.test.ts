import { describe, expect, it } from "vitest";
import { normaliseLinkedInUrl, splitCellLinks, validateLinks } from "@/lib/linkedin";

describe("LinkedIn link validation", () => {
  it("accepts /in/ and /sales/ links and normalises them", () => {
    expect(normaliseLinkedInUrl("linkedin.com/in/jane-doe")).toEqual({ ok: true, url: "https://www.linkedin.com/in/jane-doe" });
    expect(normaliseLinkedInUrl("https://be.linkedin.com/in/jane-doe/?originalSubdomain=be")).toEqual({ ok: true, url: "https://www.linkedin.com/in/jane-doe" });
    expect(normaliseLinkedInUrl("https://www.linkedin.com/sales/lead/ACwAA,NAME_SEARCH?_ntb=1")).toEqual({
      ok: true,
      url: "https://www.linkedin.com/sales/lead/ACwAA,NAME_SEARCH?_ntb=1",
    });
  });

  it("rejects everything else", () => {
    for (const bad of [
      "https://www.linkedin.com/company/acme",
      "https://www.linkedin.com/in/",
      "https://evil.com/in/jane",
      "https://linkedin.com.evil.com/in/jane",
      "javascript:alert(1)",
      "https://www.linkedin.com:8443/in/jane",
      "https://user:pw@www.linkedin.com/in/jane",
      "https://www.linkedin.com/in/jane doe",
    ]) {
      expect(normaliseLinkedInUrl(bad).ok, bad).toBe(false);
    }
  });

  it("validates a list: skips blanks, de-duplicates, reports per-input errors", () => {
    const r = validateLinks(["linkedin.com/in/a", "", "https://www.linkedin.com/in/a/", "nope"]);
    expect(r.links).toEqual(["https://www.linkedin.com/in/a"]);
    expect(r.errors).toEqual([null, null, null, expect.any(String)]);
  });

  it("splits a multi-link cell", () => {
    expect(splitCellLinks("a\r\nb\n\n c ")).toEqual(["a", "b", "c"]);
    expect(splitCellLinks(null)).toEqual([]);
  });
});

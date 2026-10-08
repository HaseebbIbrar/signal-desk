import { describe, expect, it } from "vitest";
import { normaliseJobUrl } from "@/lib/job-url";

describe("Job URL normalisation", () => {
  it("trims, strips the query string and trailing slash", () => {
    expect(normaliseJobUrl("  https://jobs.example.com/acme/123/?utm_source=x&ref=y  ")).toBe("https://jobs.example.com/acme/123");
  });

  it("treats variants of the same job as equal", () => {
    const a = normaliseJobUrl("https://Jobs.Example.com/acme/123");
    expect(normaliseJobUrl("https://jobs.example.com/acme/123/")).toBe(a);
    expect(normaliseJobUrl("HTTPS://JOBS.EXAMPLE.COM/acme/123?x=1")).toBe(a);
    expect(normaliseJobUrl("https://jobs.example.com/acme/123#apply")).toBe(a);
    expect(normaliseJobUrl("https://jobs.example.com/acme/123//")).toBe(a);
  });

  it("keeps path case (paths are case-sensitive)", () => {
    expect(normaliseJobUrl("https://x.com/Jobs/ABC")).toBe("https://x.com/Jobs/ABC");
  });

  it("keeps only job-identity query parameters, so different jobs do not collide", () => {
    const a = normaliseJobUrl("https://be.indeed.com/viewjob?jk=abc123&from=serp");
    const b = normaliseJobUrl("https://be.indeed.com/viewjob?jk=zzz999&from=serp");
    expect(a).toBe("https://be.indeed.com/viewjob?jk=abc123");
    expect(a).not.toBe(b);
  });

  it("handles empty and non-string input", () => {
    expect(normaliseJobUrl("")).toBe("");
    expect(normaliseJobUrl("   ")).toBe("");
    expect(normaliseJobUrl(null)).toBe("");
    expect(normaliseJobUrl(undefined)).toBe("");
    expect(normaliseJobUrl(12345)).toBe("12345");
  });
});

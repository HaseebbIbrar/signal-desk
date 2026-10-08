// Fake data for WORKBOOK_BACKEND=mock (local UI work only; never used in production).

import { type Cell, HEADERS, SIGNALS_SHEET, VERIFY_SHEET } from "../constants";
import { isoToSerial } from "../excel-date";

const COMPANIES = [
  ["Example Logistics NV", "example-logistics.test", "End user", "Antwerp"],
  ["Demo Bank SA", "demo-bank.test", "End user", "Brussels"],
  ["Sample MSSP BV", "sample-mssp.test", "MSSP", "Ghent"],
  ["Placeholder Systems", "placeholder-systems.test", "Vendor", "Leuven"],
  ["Mock Integrators NV", "mock-integrators.test", "Systems integrator", "Mechelen"],
  ["Test Energy Group", "test-energy.test", "End user", "Liège"],
] as const;

const TITLES = [
  ["Security Architect", "security architect", "A"],
  ["Cloud Platform Engineer", "cloud engineer", "B"],
  ["SOC Analyst L2", "soc analyst", "C"],
  ["Head of IT Infrastructure", "head of it", "D"],
  ["Data Engineer", "data engineer", "E"],
  ["IAM Specialist", "identity", "F"],
  ["Network Engineer", "network engineer", "G"],
] as const;

const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

function isoDaysAgo(today: Date, n: number): string {
  const d = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate() - n));
  return d.toISOString().slice(0, 10);
}

function signalRow(iso: string, i: number): Cell[] {
  const [company, domain, type, city] = COMPANIES[i % COMPANIES.length];
  const [title, keyword, group] = TITLES[(i * 3) % TITLES.length];
  const day = DAYS[new Date(`${iso}T00:00:00Z`).getUTCDay()];
  return [
    isoToSerial(iso),
    day,
    company,
    domain,
    title,
    keyword,
    group,
    type,
    `${city}, Belgium`,
    "New",
    i % 2 ? "LinkedIn Jobs" : "Company careers page",
    `https://jobs.example.com/${domain.split(".")[0]}/${iso}-${i}?utm_source=feed`,
    "",
    i % 3 === 0 ? "Contacted in 2025 via Lemlist (no reply)" : "",
    `Fake note for ${company}. Hiring signal around ${keyword}.`,
    "Mock evidence: careers page listing seen.",
    "",
  ];
}

export function buildFixture(today = new Date()): Record<string, Cell[][]> {
  const header: Cell[] = [...HEADERS];
  const signals: Cell[][] = [header];
  let n = 0;
  for (const back of [3, 2, 1, 0]) {
    const iso = isoDaysAgo(today, back);
    signals.push([isoToSerial(iso), ...Array<Cell>(16).fill("")]);
    const count = back === 0 ? 8 : 5;
    for (let i = 0; i < count; i++) signals.push(signalRow(iso, n++));
  }

  // The verify tab already holds a few approved rows from earlier days (two with decision makers).
  const verify: Cell[][] = [header];
  const earlier = signals.filter((r) => r[2] && r[2] !== "Company").slice(0, 4);
  earlier.forEach((r, i) => {
    const copy = [...r];
    if (i < 2) copy[12] = `https://www.linkedin.com/in/example-person-${i + 1}`;
    verify.push(copy);
  });
  return { [SIGNALS_SHEET]: signals, [VERIFY_SHEET]: verify };
}

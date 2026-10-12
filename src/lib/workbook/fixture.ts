// Fake data for WORKBOOK_BACKEND=mock (local UI work only; never used in production).
//
// Mirrors the real sheet after the 9 Oct 2026 change: the two newest days use the new A–K role
// groups with a Role Sub-Group in column R; the two older days keep the old group names and an
// empty column R. Both tabs have "Role Sub-Group" in R1.

import { type Cell, HEADERS, SIGNALS_SHEET, SUB_GROUP_HEADER, VERIFY_SHEET } from "../constants";
import { isoToSerial } from "../excel-date";

const COMPANIES = [
  ["Example Logistics NV", "example-logistics.test", "End user", "Antwerp"],
  ["Demo Bank SA", "demo-bank.test", "End user", "Brussels"],
  ["Sample MSSP BV", "sample-mssp.test", "MSSP/MSP", "Ghent"],
  ["Placeholder Systems", "placeholder-systems.test", "Vendor", "Leuven"],
  ["Mock Integrators NV", "mock-integrators.test", "Consultancy", "Mechelen"],
  ["Test Energy Group", "test-energy.test", "End user", "Liège"],
] as const;

// [title, keyword, role group (col G), role sub-group (col R)] in the new A–K scheme.
const NEW_ROLES = [
  ["Security Architect", "security architect", "A - Security", "A2 - Security Architecture"],
  ["Data Engineer", "data engineer", "B - Data & BI", "B1 - Data Engineering"],
  ["Back-end Developer (Java)", "java developer", "C - Software Development", "C1 - Back-end (Java, .NET, Python, PHP)"],
  ["Cloud Platform Engineer", "cloud engineer", "D - Cloud & DevOps", "D1 - Cloud Platform (Azure, AWS, GCP)"],
  ["Network Engineer", "network engineer", "E - Infrastructure & Network", "E2 - Network & Connectivity"],
  ["Functional Analyst", "functional analyst", "F - Business & Functional Analysis", "F1 - Functional Analysis"],
  ["Enterprise Architect", "enterprise architect", "G - Architecture", "G1 - Enterprise Architecture"],
  ["SAP S/4HANA Consultant", "sap consultant", "H - ERP, CRM & Business Apps", "H1 - SAP"],
  ["Machine Learning Engineer", "machine learning", "I - AI & Machine Learning", "I1 - ML Engineering"],
  ["IT Project Manager", "project manager", "J - Project & Delivery", "J1 - Project Management"],
  ["Service Desk Engineer", "service desk", "K - IT Service, Support & Workplace", "K1 - Service Desk 2nd / 3rd Line"],
] as const;

// Older rows: the previous group names, and no sub-group.
const OLD_ROLES = [
  ["SOC Analyst L2", "soc analyst", "A - Security"],
  ["Data Scientist", "data scientist", "B - Data & AI"],
  ["Full-stack Developer", "full stack", "C - Software Development / Engineering"],
  ["Head of IT Infrastructure", "head of it", "E - Infrastructure & Networking"],
  ["Business Analyst", "business analyst", "F - Project Management / Functional Analysis / Business Analysis"],
] as const;

const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

function isoDaysAgo(today: Date, n: number): string {
  const d = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate() - n));
  return d.toISOString().slice(0, 10);
}

function signalRow(iso: string, i: number, newScheme: boolean): Cell[] {
  const [company, domain, type, city] = COMPANIES[i % COMPANIES.length];
  const [title, keyword, group, subGroup] = newScheme
    ? NEW_ROLES[(i * 3) % NEW_ROLES.length]
    : [...OLD_ROLES[i % OLD_ROLES.length], ""];
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
    subGroup,
  ];
}

export function buildFixture(today = new Date()): Record<string, Cell[][]> {
  const header: Cell[] = [...HEADERS, SUB_GROUP_HEADER];
  const signals: Cell[][] = [header];
  let n = 0;
  for (const back of [3, 2, 1, 0]) {
    const iso = isoDaysAgo(today, back);
    signals.push([isoToSerial(iso), ...Array<Cell>(17).fill("")]);
    const count = back === 0 ? 8 : 5;
    const newScheme = back <= 1; // the two newest days use the new A–K groups + sub-groups
    for (let i = 0; i < count; i++) signals.push(signalRow(iso, n++, newScheme));
  }

  // The verify tab already holds a few approved rows from the older days (two with decision makers).
  const verify: Cell[][] = [header];
  const earlier = signals.filter((r) => r[2] && r[2] !== "Company").slice(0, 4);
  earlier.forEach((r, i) => {
    const copy = [...r];
    if (i < 2) copy[12] = `https://www.linkedin.com/in/example-person-${i + 1}`;
    verify.push(copy);
  });
  return { [SIGNALS_SHEET]: signals, [VERIFY_SHEET]: verify };
}

/** Raw cell value as returned by the Graph Excel API (`values`). */
export type Cell = string | number | boolean | null;

/** Read-only for the app. The nightly job appends here; the app never writes to it. */
export const SIGNALS_SHEET = "Signals";

/** The ONLY tab the app may write to (Excel cut the name to 31 characters). */
export const VERIFY_SHEET = "Signals to verify decision make";

/** File name of the production master, used to stop dev mode from pointing at it. */
export const MASTER_FILE_NAME = "SwooScale Master Data.xlsx";

export const HEADERS = [
  "Date Found",
  "Day",
  "Company",
  "Domain",
  "Job Title",
  "Matched Keyword",
  "Role Group",
  "Company Type",
  "Location",
  "Status",
  "Source",
  "Job URL",
  "Decision Maker LinkedIn",
  "Lemlist / Prior Outreach",
  "Notes",
  "Verified Evidence",
  "My Comments",
] as const;

/** Columns A:Q. */
export const NUM_COLS = 17;
export const FIRST_COL_LETTER = "A";
export const LAST_COL_LETTER = "Q";

/** Zero-based column indexes within A:Q. */
export const C = {
  dateFound: 0, // A
  day: 1, // B
  company: 2, // C
  domain: 3, // D
  jobTitle: 4, // E
  matchedKeyword: 5, // F
  roleGroup: 6, // G
  companyType: 7, // H
  location: 8, // I
  status: 9, // J
  source: 10, // K
  jobUrl: 11, // L
  decisionMaker: 12, // M
  priorOutreach: 13, // N
  notes: 14, // O
  verifiedEvidence: 15, // P
  myComments: 16, // Q
} as const;

export const DECISION_MAKER_COL_LETTER = "M";
export const JOB_URL_COL_LETTER = "L";
export const DATE_NUMBER_FORMAT = "yyyy-mm-dd";

// Data shapes shared by API routes and client components. No secrets, no server code.

export interface SignalRowDTO {
  rowNumber: number;
  key: string;
  jobUrl: string;
  dateFound: string;
  day: string;
  company: string;
  domain: string;
  jobTitle: string;
  matchedKeyword: string;
  roleGroup: string;
  /** Column R; empty on rows from before 9 Oct 2026. */
  roleSubGroup: string;
  companyType: string;
  location: string;
  status: string;
  source: string;
  priorOutreach: string;
  notes: string;
  verifiedEvidence: string;
  myComments: string;
  ticked: boolean;
  verifyRow: number | null;
  hasDecisionMaker: boolean;
}

export interface SignalsView {
  dates: string[];
  latestDate: string | null;
  selectedDate: string | null;
  rows: SignalRowDTO[];
}

export interface VerifiedRowDTO {
  rowNumber: number;
  key: string;
  jobUrl: string;
  dateFound: string;
  company: string;
  jobTitle: string;
  roleGroup: string;
  /** Column R; empty on rows from before 9 Oct 2026, or when the verify tab has no R1 header. */
  roleSubGroup: string;
  companyType: string;
  location: string;
  priorOutreach: string;
  notes: string;
  verifiedEvidence: string;
  myComments: string;
  decisionMakers: string[];
  hasDecisionMaker: boolean;
  duplicate: boolean;
}

export interface VerifiedView {
  rows: VerifiedRowDTO[];
}

export interface TickResult {
  status: "appended" | "already";
  row: number;
  hasDecisionMaker: boolean;
}

export interface UntickResult {
  status: "removed" | "not_ticked";
  row: number | null;
}

export interface SaveDecisionMakerResult {
  row: number;
  links: string[];
  value: string;
}

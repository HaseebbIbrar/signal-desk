// Excel (1900 date system) serial numbers <-> ISO yyyy-mm-dd.
//
// Excel counts 1900-01-01 as serial 1 and wrongly treats 1900 as a leap year, so serial 60 is the
// fictional 1900-02-29. With an epoch of 1899-12-30, every serial >= 61 maps directly; serials
// below 60 are one day off and need a correction.

const MS_PER_DAY = 86_400_000;
const EPOCH_UTC = Date.UTC(1899, 11, 30);
const MAX_SERIAL = 2_958_465; // 9999-12-31

function pad(n: number, width = 2): string {
  return String(n).padStart(width, "0");
}

/** Convert an Excel serial (date or date-time) to yyyy-mm-dd. Returns null if not a plausible date. */
export function serialToISO(serial: number): string | null {
  if (typeof serial !== "number" || !Number.isFinite(serial)) return null;
  const whole = Math.floor(serial);
  if (whole < 1 || whole > MAX_SERIAL) return null;
  if (whole === 60) return "1900-02-29";
  const days = whole < 60 ? whole + 1 : whole;
  const d = new Date(EPOCH_UTC + days * MS_PER_DAY);
  return `${pad(d.getUTCFullYear(), 4)}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
}

/** Strictly parse yyyy-mm-dd into [y, m, d] or null if it is not a real calendar date. */
function parseISODate(iso: string): [number, number, number] | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!m) return null;
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])];
  const dt = new Date(Date.UTC(y, mo - 1, d));
  if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo - 1 || dt.getUTCDate() !== d) return null;
  return [y, mo, d];
}

/** Convert yyyy-mm-dd to an Excel serial number. Returns null for invalid dates. */
export function isoToSerial(iso: string): number | null {
  const parts = parseISODate(iso);
  if (!parts) return null;
  const [y, mo, d] = parts;
  if (y === 1900 && mo === 2 && d === 29) return 60;
  const days = Math.round((Date.UTC(y, mo - 1, d) - EPOCH_UTC) / MS_PER_DAY);
  const serial = days < 61 ? days - 1 : days;
  return serial >= 1 && serial <= MAX_SERIAL ? serial : null;
}

/**
 * Interpret any cell as a date and return yyyy-mm-dd, or null.
 * Accepts serial numbers, ISO strings (optionally with a time part) and European dd/mm/yyyy text.
 */
export function cellToISODate(value: unknown): string | null {
  if (typeof value === "number") return serialToISO(value);
  if (typeof value !== "string") return null;
  const s = value.trim();
  if (!s) return null;
  const iso = /^(\d{4}-\d{2}-\d{2})(?:[T ].*)?$/.exec(s);
  if (iso) return parseISODate(iso[1]) ? iso[1] : null;
  const eu = /^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$/.exec(s);
  if (eu) {
    const candidate = `${eu[3]}-${pad(Number(eu[2]))}-${pad(Number(eu[1]))}`;
    return parseISODate(candidate) ? candidate : null;
  }
  // A plain numeric string such as "46301" (e.g. a serial stored as text).
  if (/^\d{4,6}(\.\d+)?$/.test(s)) return serialToISO(Number(s));
  return null;
}

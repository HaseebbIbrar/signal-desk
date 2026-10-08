/** Only http(s) links from the sheet become clickable; anything else (e.g. javascript:) is shown as text. */
export function safeHref(value: string | null | undefined): string | null {
  if (!value) return null;
  const s = value.trim();
  if (!/^https?:\/\//i.test(s)) return null;
  try {
    const u = new URL(s);
    return u.protocol === "https:" || u.protocol === "http:" ? u.toString() : null;
  } catch {
    return null;
  }
}

import "server-only";

// Accounts come from AUTH_USERS (no sign-up page). Format, one entry per user, ";"-separated:
//   email|Display Name|<bcrypt hash, base64-encoded>
// The hash is base64-encoded because raw bcrypt hashes contain "$", which .env files expand.
// Generate entries with `npm run hash-password`.

export interface AppUser {
  email: string;
  name: string;
  hash: string;
}

let cache: { raw: string; users: AppUser[] } | null = null;

export function parseUsers(raw: string): AppUser[] {
  const users: AppUser[] = [];
  for (const entry of raw.split(";").map((s) => s.trim()).filter(Boolean)) {
    const parts = entry.split("|");
    if (parts.length !== 3) throw new Error("AUTH_USERS entries must look like email|Name|base64hash");
    const email = parts[0].trim().toLowerCase();
    const name = parts[1].trim();
    const hash = Buffer.from(parts[2].trim(), "base64").toString("utf8");
    if (!/^[^@\s]+@[^@\s]+$/.test(email)) throw new Error(`AUTH_USERS: invalid email "${email}"`);
    if (!/^\$2[aby]\$\d{2}\$[./A-Za-z0-9]{53}$/.test(hash)) throw new Error(`AUTH_USERS: the hash for ${email} is not a base64-encoded bcrypt hash`);
    users.push({ email, name: name || email, hash });
  }
  return users;
}

export function getUsers(): AppUser[] {
  const raw = process.env.AUTH_USERS ?? "";
  if (cache?.raw !== raw) cache = { raw, users: parseUsers(raw) };
  return cache.users;
}

export function findUser(email: unknown): AppUser | undefined {
  if (typeof email !== "string") return undefined;
  const e = email.trim().toLowerCase();
  return getUsers().find((u) => u.email === e);
}

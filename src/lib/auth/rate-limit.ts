import "server-only";

// Failed-login limiter. Default store is in-memory (per server instance). On Vercel, set
// UPSTASH_REDIS_REST_URL + UPSTASH_REDIS_REST_TOKEN so all instances share one counter.

export const LIMITS = {
  email: { max: 5, windowS: 15 * 60 },
  ip: { max: 20, windowS: 15 * 60 },
};

interface Store {
  get(key: string): Promise<number>;
  incr(key: string, windowS: number): Promise<number>;
  del(key: string): Promise<void>;
}

class MemoryStore implements Store {
  private m = new Map<string, { n: number; exp: number }>();
  async get(key: string) {
    const e = this.m.get(key);
    if (!e || e.exp < Date.now()) return 0;
    return e.n;
  }
  async incr(key: string, windowS: number) {
    const e = this.m.get(key);
    if (!e || e.exp < Date.now()) {
      this.m.set(key, { n: 1, exp: Date.now() + windowS * 1000 });
      return 1;
    }
    e.n++;
    return e.n;
  }
  async del(key: string) {
    this.m.delete(key);
  }
}

class UpstashStore implements Store {
  constructor(
    private url: string,
    private token: string,
  ) {}
  private async pipeline(cmds: (string | number)[][]): Promise<{ result: unknown }[]> {
    const res = await fetch(`${this.url.replace(/\/$/, "")}/pipeline`, {
      method: "POST",
      headers: { Authorization: `Bearer ${this.token}`, "Content-Type": "application/json" },
      body: JSON.stringify(cmds),
      cache: "no-store",
    });
    if (!res.ok) throw new Error(`Rate-limit store error ${res.status}`);
    return res.json();
  }
  async get(key: string) {
    const [r] = await this.pipeline([["GET", key]]);
    return Number(r.result ?? 0);
  }
  async incr(key: string, windowS: number) {
    const [r] = await this.pipeline([
      ["INCR", key],
      ["EXPIRE", key, windowS, "NX"],
    ]);
    return Number(r.result ?? 0);
  }
  async del(key: string) {
    await this.pipeline([["DEL", key]]);
  }
}

const g = globalThis as unknown as { __sdRateStore?: Store };
function store(): Store {
  if (!g.__sdRateStore) {
    const url = process.env.UPSTASH_REDIS_REST_URL;
    const token = process.env.UPSTASH_REDIS_REST_TOKEN;
    g.__sdRateStore = url && token ? new UpstashStore(url, token) : new MemoryStore();
  }
  return g.__sdRateStore;
}

const keys = (email: string, ip: string) => ({ email: `sd:login:email:${email}`, ip: `sd:login:ip:${ip}` });

export async function isLoginLocked(email: string, ip: string): Promise<boolean> {
  const k = keys(email, ip);
  const [e, i] = await Promise.all([store().get(k.email), store().get(k.ip)]);
  return e >= LIMITS.email.max || i >= LIMITS.ip.max;
}

export async function recordLoginFailure(email: string, ip: string): Promise<void> {
  const k = keys(email, ip);
  await Promise.all([store().incr(k.email, LIMITS.email.windowS), store().incr(k.ip, LIMITS.ip.windowS)]);
}

export async function resetLoginFailures(email: string): Promise<void> {
  await store().del(keys(email, "").email);
}

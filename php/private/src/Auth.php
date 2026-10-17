<?php
// Login (AUTH_USERS: email|Name|base64(bcrypt), ";"-separated), PHP sessions, CSRF, Origin check, lockout.
declare(strict_types=1);

namespace SignalDesk;

final class Auth
{
    public const SESSION_MAX_AGE = 12 * 3600;

    /** @return array<int,array{email:string,name:string,hash:string}> */
    public static function parseUsers(string $raw): array
    {
        $users = [];
        foreach (array_filter(array_map('trim', explode(';', $raw)), fn ($s) => $s !== '') as $entry) {
            $parts = explode('|', $entry);
            if (count($parts) !== 3) throw new ConfigError('AUTH_USERS entries must look like email|Name|base64hash');
            $email = strtolower(trim($parts[0]));
            $name = trim($parts[1]);
            $hash = base64_decode(trim($parts[2]), true);
            if (!preg_match('/^[^@\s]+@[^@\s]+$/', $email)) throw new ConfigError("AUTH_USERS: invalid email \"$email\"");
            if ($hash === false || !preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash)) {
                throw new ConfigError("AUTH_USERS: the hash for $email is not a base64-encoded bcrypt hash");
            }
            // $2b$ (bcryptjs, Node) and $2y$ (PHP) are the same algorithm; normalise for password_verify.
            if (str_starts_with($hash, '$2b$')) $hash = '$2y$' . substr($hash, 4);
            $users[] = ['email' => $email, 'name' => $name !== '' ? $name : $email, 'hash' => $hash];
        }
        return $users;
    }

    public function __construct(private array $users, private Lockout $lockout) {}

    public function find(string $email): ?array
    {
        $e = strtolower(trim($email));
        foreach ($this->users as $u) if ($u['email'] === $e) return $u;
        return null;
    }

    public static function startSession(string $storageDir, bool $https): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $dir = $storageDir . '/sessions';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        session_save_path($dir);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) self::SESSION_MAX_AGE);
        session_name($https ? '__Host-sd' : 'sd_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }

    /** The signed-in user, or null. Removing someone from AUTH_USERS signs them out immediately. */
    public function user(): ?array
    {
        $email = $_SESSION['uid'] ?? null;
        if (!is_string($email)) return null;
        if ((int) ($_SESSION['login_at'] ?? 0) < time() - self::SESSION_MAX_AGE) {
            $this->logout();
            return null;
        }
        return $this->find($email);
    }

    /** @return array{ok:bool,error?:string} */
    public function login(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim(substr($email, 0, 200)));
        if ($email === '' || $password === '' || strlen($password) > 200) return ['ok' => false, 'error' => 'Invalid email or password.'];
        if ($this->lockout->isLocked($email, $ip)) {
            Audit::event('auth', ['event' => 'login_locked', 'email' => $email, 'ip' => $ip]);
            return ['ok' => false, 'error' => 'Too many failed attempts. Please wait 15 minutes and try again.'];
        }
        $user = $this->find($email);
        // Compare against a dummy hash for unknown emails so timing doesn't reveal which emails exist.
        $ok = password_verify($password, $user['hash'] ?? self::dummyHash());
        if (!$user || !$ok) {
            $this->lockout->fail($email, $ip);
            Audit::event('auth', ['event' => 'login_failed', 'email' => $email, 'ip' => $ip]);
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }
        $this->lockout->reset($email);
        session_regenerate_id(true);
        $_SESSION['uid'] = $user['email'];
        $_SESSION['login_at'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Audit::event('auth', ['event' => 'login_ok', 'email' => $email, 'ip' => $ip]);
        return ['ok' => true];
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    public static function csrfValid(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    /**
     * POSTs must come from this app: Origin (or, failing that, Referer) must equal APP_ORIGIN.
     * With "Referrer-Policy: no-referrer", browsers send "Origin: null" on plain form POSTs; those are
     * accepted only when the browser itself marks the request Sec-Fetch-Site: same-origin (a header
     * pages cannot set). The CSRF token is required in every case as well.
     */
    public static function originValid(string $expected): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (($origin === '' || $origin === 'null') && ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin') return true;
        if ($origin === 'null') $origin = '';
        if ($origin === '' && !empty($_SERVER['HTTP_REFERER'])) {
            $p = parse_url($_SERVER['HTTP_REFERER']);
            if ($p && isset($p['scheme'], $p['host'])) $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        }
        return $origin !== '' && hash_equals(rtrim($expected, '/'), rtrim($origin, '/'));
    }
}

/** Failed-login lockout: 5 per email and 20 per IP within 15 minutes (storage/lockout.json). */
final class Lockout
{
    public const EMAIL_MAX = 5;
    public const IP_MAX = 20;
    public const WINDOW = 900;

    /** @var callable */
    public $now;

    public function __construct(private string $file)
    {
        $this->now = fn () => time();
    }

    private function count(array $d, string $k): int
    {
        $e = $d[$k] ?? null;
        if (!$e || ($e['first'] + self::WINDOW) <= ($this->now)()) return 0;
        return (int) $e['count'];
    }

    public function isLocked(string $email, string $ip): bool
    {
        $d = JsonStore::read($this->file, []);
        return $this->count($d, "email:$email") >= self::EMAIL_MAX || $this->count($d, "ip:$ip") >= self::IP_MAX;
    }

    public function fail(string $email, string $ip): void
    {
        $now = ($this->now)();
        JsonStore::update($this->file, [], function ($d) use ($email, $ip, $now) {
            $d = is_array($d) ? $d : [];
            foreach (["email:$email", "ip:$ip"] as $k) {
                $e = $d[$k] ?? null;
                if (!$e || ($e['first'] + self::WINDOW) <= $now) $d[$k] = ['count' => 1, 'first' => $now];
                else $d[$k]['count']++;
            }
            // drop expired entries so the file stays small
            foreach ($d as $k => $e) if (($e['first'] + self::WINDOW) <= $now) unset($d[$k]);
            return $d;
        });
    }

    public function reset(string $email): void
    {
        JsonStore::update($this->file, [], function ($d) use ($email) {
            $d = is_array($d) ? $d : [];
            unset($d["email:$email"]);
            return $d;
        });
    }
}

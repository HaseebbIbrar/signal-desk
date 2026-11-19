<?php
// Navigation: 5 top tabs (Business Development · Candidate Sourcing · Dashboard · Calls · Inbox) with sub-tabs,
// clear paths, 301s from the old URLs, and the last-used sub-tab per user (storage/nav-prefs.json, keyed by a hash).
declare(strict_types=1);

namespace SignalDesk;

final class Nav
{
    /** page => [top tab, path, label]; the page name is also data-page for app.js */
    public const PAGES = [
        'signals' => ['bd', '/bd/signals', 'Signals'],
        'verified' => ['bd', '/bd/verified', 'Verified'],
        'hr' => ['bd', '/bd/hr', 'HR signals'],
        'candidates' => ['sourcing', '/sourcing/candidates', 'Candidates'],
        'lists' => ['sourcing', '/sourcing/lists', 'Lists'],
        'dashboard' => ['dashboard', '/dashboard', 'Dashboard'],
        'calls' => ['calls', '/calls/tasks', 'Call tasks'],
        'manual' => ['calls', '/calls/manual', 'Manual tasks'],
        'inbox' => ['inbox', '/inbox', 'Inbox'],
    ];
    /** top tab => [label, pages in order, needs] */
    public const TOPS = [
        'bd' => ['Business Development', ['signals', 'verified', 'hr'], null],
        'sourcing' => ['Candidate Sourcing', ['candidates', 'lists'], null],
        'dashboard' => ['Dashboard', ['dashboard'], 'lemlist'],
        'calls' => ['Calls', ['calls', 'manual'], 'lemlist'],
        'inbox' => ['Inbox', ['inbox'], 'lemlist'],
    ];
    /** old path => new path (query kept) */
    public const OLD = ['/signals' => '/bd/signals', '/verified' => '/bd/verified', '/hr' => '/bd/hr', '/candidates' => '/sourcing/candidates',
        '/lists' => '/sourcing/lists', '/outreach' => '/dashboard'];
    public const FILE = 'nav-prefs.json';

    public static function pageFor(string $path): ?string
    {
        foreach (self::PAGES as $page => [, $p]) if ($p === $path) return $page;
        return null;
    }

    /** Sub-pages of a top tab that exist with this config (Lists needs Atlas). */
    public static function subs(string $top, array $config): array
    {
        return array_values(array_filter(self::TOPS[$top][1], fn ($p) => $p !== 'lists' || Atlas::mode($config) !== 'off'));
    }

    /** @return list<string> top tabs shown with this config */
    public static function tops(array $config): array
    {
        $lemlist = Lemlist::mode($config) !== 'off';
        return array_values(array_filter(array_keys(self::TOPS), fn ($t) => self::TOPS[$t][2] !== 'lemlist' || $lemlist));
    }

    /**
     * An old URL (or a bare top-tab path): where to send it. 301 for old pages (saved links, ?c=…&p=… and other queries kept),
     * 302 for "/", "/bd", "/sourcing" (they depend on the user's last sub-tab). null = not a redirect.
     * @return array{0:string,1:int}|null
     */
    public static function redirect(string $path, array $query, ?string $storageDir, ?string $email, array $config): ?array
    {
        $qs = fn (array $q) => $q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '';
        if (isset(self::OLD[$path])) return [self::OLD[$path] . $qs($query), 301];
        if ($path === '/calls') { // the old Calls page with ?sub=calls|manual|inbox
            $sub = (string) ($query['sub'] ?? '');
            unset($query['sub']);
            return [($sub === 'inbox' ? '/inbox' : ($sub === 'manual' ? '/calls/manual' : '/calls/tasks')) . $qs($query), 301];
        }
        if ($path === '/') return [self::href('bd', $storageDir, $email, $config), 302];
        if ($path === '/bd' || $path === '/sourcing') return [self::href(substr($path, 1), $storageDir, $email, $config) . $qs($query), 302];
        return null;
    }

    private static function key(string $email): string
    {
        return substr(hash('sha256', 'nav|' . strtolower(trim($email))), 0, 24);
    }

    /** The user's last-used page of a top tab (the first one the first time). */
    public static function last(string $top, ?string $storageDir, ?string $email, array $config): string
    {
        $subs = self::subs($top, $config);
        if ($storageDir && $email) {
            $p = JsonStore::read("$storageDir/" . self::FILE, [])[self::key($email)][$top] ?? null;
            if (is_string($p) && in_array($p, $subs, true)) return $p;
        }
        return $subs[0];
    }

    public static function href(string $top, ?string $storageDir, ?string $email, array $config): string
    {
        return self::PAGES[self::last($top, $storageDir, $email, $config)][1];
    }

    /** Remember the page as its top tab's last-used sub-tab (written only when it changes). */
    public static function remember(string $page, string $storageDir, string $email): void
    {
        $top = self::PAGES[$page][0] ?? null;
        if (!$top || count(self::TOPS[$top][1]) < 2) return;
        $k = self::key($email);
        $cur = JsonStore::read("$storageDir/" . self::FILE, [])[$k][$top] ?? null;
        if ($cur === $page) return;
        JsonStore::update("$storageDir/" . self::FILE, [], function ($d) use ($k, $top, $page) {
            $d = is_array($d) ? $d : [];
            $d[$k][$top] = $page;
            return $d;
        });
    }
}

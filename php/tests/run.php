<?php
// Plain-PHP test runner (no Composer):  php tests/run.php
declare(strict_types=1);

ob_start(); // hold all output until the end, as a web request would (session ids can't change after output)
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path(sys_get_temp_dir());
session_start(); // before any output (Auth::login regenerates the session id)

require __DIR__ . '/../private/src/bootstrap.php';

use SignalDesk\Audit;

$GLOBALS['__tests'] = [];
function test(string $name, callable $fn): void
{
    $only = (string) getenv('TEST_FILTER'); // e.g. TEST_FILTER=inbox php tests/run.php
    if ($only === '' || stripos($name, $only) !== false) $GLOBALS['__tests'][] = [$name, $fn];
}

final class AssertionFailed extends \Exception {}
function fail(string $m): never { throw new AssertionFailed($m); }
function show(mixed $v): string { return var_export($v, true); }
function eq(mixed $expected, mixed $actual, string $m = ''): void
{
    if ($expected !== $actual) fail(($m ? "$m\n" : '') . 'expected ' . show($expected) . "\n     got " . show($actual));
}
function ok(bool $cond, string $m = 'expected true'): void { if (!$cond) fail($m); }
function throws(callable $fn, string $class, ?string $contains = null): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) fail("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        if ($contains !== null && !str_contains($e->getMessage(), $contains)) fail("message \"{$e->getMessage()}\" does not contain \"$contains\"");
        return $e;
    }
    fail("expected $class to be thrown");
}

function tmpdir(): string
{
    $d = sys_get_temp_dir() . '/sd-test-' . bin2hex(random_bytes(5));
    mkdir($d, 0700, true);
    $GLOBALS['__tmp'][] = $d;
    return $d;
}
function rrmdir(string $d): void
{
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$d/$f") ? rrmdir("$d/$f") : @unlink("$d/$f");
    }
    @rmdir($d);
}

require __DIR__ . '/helpers.php';
foreach (glob(__DIR__ . '/*Test.php') as $f) require $f;

$passed = 0;
$failed = [];
foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    $events = [];
    Audit::$sink = function (array $e) use (&$events) { $events[] = $e; };
    $GLOBALS['__events'] = &$events;
    try {
        $fn();
        $passed++;
        echo "  \u{2713} $name\n";
    } catch (\Throwable $e) {
        $failed[] = $name;
        echo "  \u{2717} $name\n      " . str_replace("\n", "\n      ", $e->getMessage()) . "\n      at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
    unset($GLOBALS['__events']);
}
foreach ($GLOBALS['__tmp'] ?? [] as $d) rrmdir($d);

echo "\n" . ($failed ? 'FAILED' : 'OK') . ": $passed passed, " . count($failed) . " failed (" . count($GLOBALS['__tests']) . " tests, PHP " . PHP_VERSION . ")\n";
exit($failed ? 1 : 0);

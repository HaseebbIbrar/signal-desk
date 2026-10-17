<?php
// Finds the private folder (config.php, src/, storage/). Not an entry point: .htaccess denies it,
// and it only returns a path.
//   Layout A (preferred): "signal-desk-private" OUTSIDE the web root, e.g. next to public_html.
//   Layout B (fallback):  "_private" INSIDE the web root, blocked by .htaccess (Require all denied).
//   Repo / local dev:     php/private next to php/public.
// SIGNAL_DESK_PRIVATE (environment variable) overrides all of these.
declare(strict_types=1);

return (static function (): array {
    $candidates = [];
    $env = getenv('SIGNAL_DESK_PRIVATE');
    if ($env) $candidates[] = [$env, 'env'];
    foreach (['/..', '/../..', '/../../..'] as $up) $candidates[] = [__DIR__ . $up . '/signal-desk-private', 'A'];
    $candidates[] = [__DIR__ . '/_private', 'B'];
    $candidates[] = [__DIR__ . '/../private', 'repo'];
    foreach ($candidates as [$dir, $layout]) {
        if (is_file($dir . '/src/bootstrap.php')) return [realpath($dir) ?: $dir, $layout];
    }
    return [null, null];
})();

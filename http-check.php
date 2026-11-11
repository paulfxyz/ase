<?php
/**
 * http-check.php — "does the web server answer?" (v6.2.0)
 *
 *   GET http-check.php?domains=a.com,b.com   (up to 25)   → [ {domain, ok, status, ms, scheme, error}, … ]
 *   GET http-check.php?domain=a.com                        → {domain, ok, status, ms, scheme, error}
 *
 * Browsers cannot read HTTP status codes from other sites (CORS), so the
 * dashboard asks the server. Rules are in ase_http_probe_many() (_ase.php):
 * status < 500 = ok, 5xx or no answer = down, redirects are not followed,
 * private/reserved addresses are refused.
 *
 * Sign-in required (the public demo is limited to its own watchlist). Results
 * are cached for 2 minutes so many visitors cannot turn the demo into a
 * scanner, and every caller is rate limited.
 */
require_once __DIR__ . '/_ase.php';
ase_headers();
header('Cache-Control: private, max-age=60');

$demo = ase_is_demo();
if (!$demo) ase_require_auth();
if (!ase_rate_hit('http:' . hash('sha256', ase_ip()), 300, 600)) ase_fail(429, 'Rate limit reached');
@set_time_limit(60);

$raw = isset($_GET['domains']) ? (string)$_GET['domains'] : (string)($_GET['domain'] ?? '');
$single = !isset($_GET['domains']);
$list = array_values(array_unique(array_filter(array_map(function ($d) { return strtolower(trim($d)); }, explode(',', $raw)))));
if (!$list) ase_fail(400, 'Missing domain(s)');
if (count($list) > 25) $list = array_slice($list, 0, 25);

$allowed = null;
if ($demo) {
    $wl = is_file(__DIR__ . '/domains.list') ? file(__DIR__ . '/domains.list', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $allowed = array_flip(array_map(function ($l) { return strtolower(trim($l)); }, $wl));
}

$cacheFile = __DIR__ . '/http_cache.json';
$cache = ase_read_json($cacheFile);
$now = time(); $results = []; $todo = []; $dirty = false;
foreach ($list as $d) {
    if ($allowed !== null && !isset($allowed[$d])) { $results[$d] = ['domain' => $d, 'ok' => false, 'status' => null, 'ms' => null, 'scheme' => null, 'error' => 'Not in the demo watchlist']; continue; }
    if (isset($cache[$d]) && ($now - ($cache[$d]['at'] ?? 0)) < 120) { $results[$d] = ['domain' => $d] + $cache[$d]['r']; continue; }
    $todo[] = $d;
}
if ($todo) {
    foreach (ase_http_probe_many($todo) as $d => $r) {
        $results[$d] = ['domain' => $d] + $r;
        $cache[$d] = ['at' => $now, 'r' => $r];
        $dirty = true;
    }
}
if ($dirty) {
    foreach ($cache as $k => $v) { if ($now - ($v['at'] ?? 0) > 3600) unset($cache[$k]); }   /* prune */
    if (count($cache) > 600) $cache = array_slice($cache, -500, null, true);
    ase_write_json($cacheFile, $cache);
}

$ordered = [];
foreach ($list as $d) $ordered[] = $results[$d] ?? ['domain' => $d, 'ok' => false, 'status' => null, 'ms' => null, 'scheme' => null, 'error' => 'no result'];
ase_out($single ? $ordered[0] : $ordered);

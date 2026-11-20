<?php
/**
 * fresh_load_check.php — QuickTasks "fresh pull won't fail on first load" gate.
 *
 * A fresh clone only needs to LOAD (GET /api.php?board=<key>) without erroring,
 * so this mirrors what api.php::load_board()/load_registry() do on a cold start:
 *   - every data file must be valid JSON
 *   - the registry (boards.json) must expose the boards
 *   - each board's config must merge cleanly over DEFAULT_CONFIG and carry the
 *     required keys the front-end reads
 *   - no task may carry the RENAMED field `startUntil` (v1.25/26 -> canStartOnUTC)
 *   - any `repeat` spec must be a sane {every,unit[,at]} (or null) — an invalid
 *     spec would otherwise risk a spawn loop; any `canStartOnUTC` must be a string.
 *
 * It does NOT touch disk and does NOT run spawns/prune — pure read, so it is safe
 * on CI and locally and is the authoritative "first load" check.
 *
 * Usage:  php scripts/fresh_load_check.php [REPO_ROOT]
 *          (REPO_ROOT defaults to the parent of this file)
 * Exit 0 = clean, non-zero = at least one board failed.
 */

$root = $argv[1] ?? dirname(__DIR__);
$root = rtrim($root, '/');

const DEFAULT_STATUSES = ['Pending', 'InProgress', 'Halted', 'Completed'];
const DEFAULT_CONFIG = [
    'name'              => 'Untitled',
    'units'             => 'days',
    'durationOptions'   => [1, 2, 3, 4, 5],
    'completedWindowDays' => 2,
    'pollMs'            => 15000,
    'descMaxChars'      => 140,
    'statuses'          => DEFAULT_STATUSES,
    'workers'           => [],
    'pointGoal'         => 0,
    'logPruneDays'      => 30,
];
const REQUIRED_CONFIG = ['name', 'units', 'durationOptions', 'completedWindowDays', 'pollMs', 'statuses', 'logPruneDays'];

$fail = 0;
function err(string $msg): void {
    global $fail;
    $fail++;
    echo "  [FAIL] $msg\n";
}
function ok(string $msg): void {
    echo "  [ok]  $msg\n";
}

function load_json(string $path): ?array {
    $raw = @file_get_contents($path);
    if ($raw === false) { err("$path not readable"); return null; }
    $d = json_decode($raw, true);
    if ($d === null && $raw !== 'null') { err("$path is not valid JSON (" . json_last_error_msg() . ")"); return null; }
    return $d;
}

// ---- 1. Registry --------------------------------------------------------------
$registryPath = "$root/boards.json";
echo "== registry ($registryPath) ==\n";
$registry = load_json($registryPath);
$keys = [];
if (is_array($registry)) {
    if (!isset($registry['boards']) || !is_array($registry['boards'])) {
        err("registry missing 'boards' array");
    } else {
        foreach ($registry['boards'] as $b) {
            if (is_array($b) && isset($b['key']) && (string)$b['key'] !== '') {
                $keys[] = (string)$b['key'];
            }
        }
    }
    $def = (string)($registry['default'] ?? $keys[0] ?? 'adults');
    if (!in_array($def, $keys, true)) err("registry default '$def' not in board list");
    ok("registry: " . count($keys) . " board(s) [" . implode(', ', $keys) . "]");
} else {
    err("registry failed to parse");
    // Fall back to scanning the boards dir so we still check every file.
    $dir = "$root/boards";
    foreach (glob("$dir/*.json") ?: [] as $f) {
        if (!str_ends_with($f, '.archive.json')) $keys[] = basename($f, '.json');
    }
}

// ---- 2. Each board file -------------------------------------------------------
$boardDir = "$root/boards";
$scanned = 0;
$files = is_dir($boardDir) ? (glob("$boardDir/*.json") ?: []) : [];
foreach ($files as $file) {
    $base = basename($file, '.json');
    if (str_ends_with($file, '.archive.json')) {
        // Archive files are historical; just make sure they're valid JSON.
        $d = load_json($file);
        ok("archive $base is valid JSON" . (is_array($d) ? '' : ''));
        continue;
    }
    $scanned++;
    echo "== board $base ($file) ==\n";
    $d = load_json($file);
    if (!is_array($d)) { err("$base skipped — invalid JSON"); continue; }

    // config must merge over defaults and carry the keys the front-end reads
    $cfg = array_merge(DEFAULT_CONFIG, is_array($d['config'] ?? null) ? $d['config'] : []);
    $missing = array_diff(REQUIRED_CONFIG, array_keys($cfg));
    if ($missing) err("$base config missing key(s): " . implode(', ', $missing));
    else ok("$base config complete (name={$cfg['name']}, window={$cfg['completedWindowDays']}, prune={$cfg['logPruneDays']})");

    $tasks = $d['tasks'] ?? [];
    if (!is_array($tasks)) { err("$base 'tasks' is not an array"); continue; }
    $stale = 0; $badTask = 0; $badRepeat = 0; $badCan = 0;
    $validStatuses = is_array($cfg['statuses']) && $cfg['statuses'] ? $cfg['statuses'] : DEFAULT_STATUSES;
    foreach ($tasks as $i => $t) {
        if (!is_array($t)) { err("$base task #$i is not an object"); $badTask++; continue; }
        $tid = isset($t['id']) ? (string)$t['id'] : "#$i";
        foreach (['id', 'title', 'status'] as $req) {
            if (!array_key_exists($req, $t)) { err("$base task $tid missing '$req'"); $badTask++; }
        }
        // renamed field: startUntil -> canStartOnUTC (v1.25/26)
        if (array_key_exists('startUntil', $t)) { err("$base task $tid still has stale 'startUntil'"); $stale++; }
        // status must be one of the board's known statuses
        if (isset($t['status']) && !in_array($t['status'], $validStatuses, true)) {
            err("$base task $tid has unknown status '{$t['status']}'"); $badTask++;
        }
        // repeat spec must be sane (or null)
        if (array_key_exists('repeat', $t)) {
            $r = $t['repeat'];
            if ($r !== null && !is_array($r)) { err("$base task $tid repeat must be an object or null"); $badRepeat++; }
            elseif (is_array($r) && (!isset($r['every']) || !isset($r['unit']) ||
                  !in_array(strtolower((string)($r['unit'] ?? '')), ['hours', 'days', 'weeks'], true))) {
                err("$base task $tid repeat has invalid every/unit"); $badRepeat++;
            }
        }
        // canStartOnUTC, when present, must be a string
        if (array_key_exists('canStartOnUTC', $t) && !is_string($t['canStartOnUTC'])) {
            err("$base task $tid canStartOnUTC must be a string"); $badCan++;
        }
    }
    if (!$stale && !$badTask && !$badRepeat && !$badCan) {
        ok("$base: " . count($tasks) . " task(s), 0 stale, 0 malformed");
    }
}

// ---- 3. api.php must at least parse (first-load would 500 otherwise) ---------
$api = "$root/api.php";
if (is_file($api)) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($api) . ' 2>&1', $out, $rc);
    if ($rc === 0) ok("api.php passes php -l (first-load handler is syntactically sound)");
    else { err("api.php FAILED php -l:\n" . implode("\n", $out)); }
} else {
    err("api.php not found at $root");
}

// ---- summary ------------------------------------------------------------------
echo "\n";
if ($scanned === 0) err("no board files found in $boardDir");
if ($fail) {
    echo "RESULT: FAIL — $fail problem(s) across " . $scanned . " board(s). Fresh pull would break.\n";
    exit(1);
}
echo "RESULT: PASS — " . $scanned . " board(s) load clean; registry, config, and api.php all good. Fresh pull is safe.\n";
exit(0);

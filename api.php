<?php
// QuickTasks — multi-board JSON store for kanban boards.
// No database. Each board is its own file under boards/<key>.json holding a
// `config` block + a `tasks` array. A registry (boards.json) lists the boards
// and the default. The UI switches boards with ?board=<key>.
//
// Layout:
//   boards.json                  -> registry {default, boards:[{key,name}]}
//   boards/adults.json           -> {config:{...}, tasks:[...]}
//   boards/children.json         -> {config:{...}, tasks:[...]}
//
// Endpoints (all under /api.php):
//   GET    /api.php?boards=1             -> registry + per-board config (for the switcher)
//   GET    /api.php?board=<key>          -> {board, config, tasks} for that board
//   GET    /api.php                      -> same, for the default board
//   POST   /api.php?board=<key>          -> create a task {title, duration, status?}
//   PATCH  /api.php?board=<key>          -> update {id, status} or {id, title, description, duration}
//   DELETE /api.php?board=<key>&id=t2    -> delete a task
//
// Runs on the PHP built-in server (0.0.0.0:8085). Also works under Apache+mod_php.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const DEFAULT_STATUSES = ['Pending', 'InProgress', 'Halted', 'Completed'];

function default_config(): array {
    return [
        'name'              => 'Untitled',
        'units'             => 'days',                 // 'days' or 'minutes'
        'durationOptions'   => [1, 2, 3, 4, 5],        // allowed estimate values
        'completedWindowDays' => 2,                    // days a completed card stays in its column
        'pollMs'            => 15000,
        'descMaxChars'      => 140,
        'statuses'          => DEFAULT_STATUSES,
        'workers'           => [],                 // named people on this board (config-only for now)
        'pointGoal'         => 0,                 // weekly team-points target; 0 = hide the points header
        'logPruneDays'      => 30,                // completed tasks older than this move to the archive file
    ];
}

// ---- Registry (boards.json) --------------------------------------------------

function load_registry(): array {
    $file = __DIR__ . '/boards.json';
    if (!is_file($file)) {
        return ['default' => 'adults', 'boards' => [['key' => 'adults', 'name' => 'Adults']]];
    }
    $raw = file_get_contents($file);
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data) || !isset($data['boards']) || !is_array($data['boards'])) {
        return ['default' => 'adults', 'boards' => [['key' => 'adults', 'name' => 'Adults']]];
    }
    $boards = [];
    foreach ($data['boards'] as $b) {
        if (!is_array($b)) continue;
        $key = (string)($b['key'] ?? '');
        if ($key === '') continue;
        $boards[] = ['key' => $key, 'name' => (string)($b['name'] ?? $key)];
    }
    if ($boards === []) {
        $boards = [['key' => 'adults', 'name' => 'Adults']];
    }
    $default = (string)($data['default'] ?? $boards[0]['key']);
    // If the default key isn't in the list, fall back to the first one.
    if (!in_array($default, array_column($boards, 'key'), true)) {
        $default = $boards[0]['key'];
    }
    return ['default' => $default, 'boards' => $boards];
}

// ---- Per-board file ----------------------------------------------------------

function board_file(string $key): string {
    return __DIR__ . '/boards/' . $key . '.json';
}

function load_board(string $key): array {
    // Returns [config, tasks] with config filled from defaults where absent.
    $cfg = default_config();
    $tasks = [];
    $file = board_file($key);
    if (is_file($file)) {
        $raw = file_get_contents($file);
        $data = json_decode($raw ?: '{}', true);
        if (is_array($data)) {
            if (isset($data['config']) && is_array($data['config'])) {
                $cfg = array_merge(default_config(), $data['config']);
            }
            if (isset($data['tasks']) && is_array($data['tasks'])) {
                $tasks = $data['tasks'];
            }
        }
    }
    // Guarantee the config carries a name (the registry name wins if present).
    // Bridge old data: earlier tasks stored the estimate as `days`. Promote it to
    // `duration` (units-aware) so new front-end code reading `t.duration` works
    // on legacy rows without a manual migration.
    $opts = $cfg['durationOptions'] ?? [];
    foreach ($tasks as &$t) {
        if (is_array($t) && !array_key_exists('duration', $t) && array_key_exists('days', $t)) {
            $t['duration'] = normalize_duration($t['days'], $opts);
        }
    }
    unset($t);

    // Keep the working board file lean: completed tasks older than logPruneDays
    // leave the live file and land in a separate per-board archive file
    // (boards/<key>.archive.json), so the active JSON never grows unbounded.
    $pruned = prune_archived($key, $tasks, $cfg);
    if ($pruned) {
        $tasks = $pruned;
        save_board($key, $cfg, $tasks);
    }
    return [$cfg, $tasks];
}

// Move completed tasks older than $cfg['logPruneDays'] out of $tasks into the
// board's archive file. Idempotent (skips tasks already archived) and atomic.
// Returns the trimmed task list, or null when nothing was archived.
function prune_archived(string $key, array $tasks, array $cfg): ?array {
    $days = (int)($cfg['logPruneDays'] ?? 30);
    if ($days <= 0) {
        return null; // feature disabled
    }
    $cutoff = time() - $days * 86400;
    $keep = [];
    $archivedNow = [];
    foreach ($tasks as $t) {
        if (($t['status'] ?? '') === 'Completed') {
            $ref = $t['completed'] ?? $t['created'] ?? null;
            $ts = $ref ? strtotime((string)$ref) : false;
            if ($ts !== false && $ts < $cutoff) {
                $archivedNow[] = $t;
                continue; // leave it out of the live file
            }
        }
        $keep[] = $t;
    }
    if (count($archivedNow) === 0) {
        return null; // nothing to move
    }
    $dir = __DIR__ . '/boards';
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $file = __DIR__ . '/boards/' . $key . '.archive.json';
    $archive = [];
    if (is_file($file)) {
        $a = json_decode(file_get_contents($file) ?: '{}', true);
        if (is_array($a) && isset($a['archived']) && is_array($a['archived'])) {
            $archive = $a['archived'];
        }
    }
    $existing = [];
    foreach ($archive as $a) {
        if (is_array($a) && isset($a['id'])) { $existing[(string)$a['id']] = true; }
    }
    $added = 0;
    foreach ($archivedNow as $t) {
        $id = (string)($t['id'] ?? '');
        if ($id === '' || isset($existing[$id])) { continue; } // already archived
        $archive[] = $t;
        $existing[$id] = true;
        $added++;
    }
    if ($added > 0) {
        $tmp = $file . '.tmp-' . getmypid();
        file_put_contents($tmp, json_encode(['archived' => $archive], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $file);
    }
    return $keep;
}

function save_board(string $key, array $config, array $tasks): void {
    $payload = json_encode(
        ['config' => $config, 'tasks' => $tasks],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    $dir = __DIR__ . '/boards';
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $file = board_file($key);
    $tmp = $file . '.tmp-' . getmypid();      // atomic write
    file_put_contents($tmp, $payload, LOCK_EX);
    rename($tmp, $file);
}

// ---- Helpers -----------------------------------------------------------------

function make_id(): string {
    return 't' . bin2hex(random_bytes(6));
}

function now_iso(): string {
    return (new DateTime('now', new DateTimeZone('UTC')))->format('c');
}

// Snap a requested estimate to the nearest allowed value for this board.
// Falls back to clamping 1..5 when a board has no durationOptions.
function normalize_duration($value, array $options): int {
    $v = (int) $value;
    if ($v < 0) $v = 0;
    if (!$options) {
        if ($v < 1) $v = 1;
        if ($v > 5) $v = 5;
        return $v;
    }
    // Closest option wins; ties go to the lower option.
    $best = $options[0];
    $bestDist = abs($v - $best);
    foreach ($options as $o) {
        $d = abs($v - $o);
        if ($d < $bestDist) {
            $best = $o;
            $bestDist = $d;
        }
    }
    return $best;
}

function normalize_desc($desc, int $maxChars): string {
    $maxChars = $maxChars > 0 ? $maxChars : 140;
    return mb_substr(trim((string) $desc), 0, $maxChars);
}

function status_ok(string $s, array $statuses): bool {
    return in_array($s, $statuses, true);
}

function respond($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// Resolve the active board key from ?board= (or default), validated against the
// registry. A request for an unknown board falls back to the default.
function resolve_board_key(array $registry): string {
    $keys = array_column($registry['boards'], 'key');
    $req = isset($_GET['board']) ? trim((string) $_GET['board']) : '';
    if ($req === '' || !in_array($req, $keys, true)) {
        return $registry['default'];
    }
    return $req;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Read optional JSON body for POST/PATCH/DELETE.
$body = [];
$rawBody = file_get_contents('php://input');
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) { $body = $decoded; }
}

$registry = load_registry();

// ---- Switcher listing: registry + each board's config ------------------------
if (isset($_GET['boards'])) {
    $out = ['default' => $registry['default'], 'boards' => []];
    foreach ($registry['boards'] as $b) {
        [$cfg, $tasks] = load_board($b['key']);
        $out['boards'][] = [
            'key'   => $b['key'],
            'name'  => $b['name'] !== '' ? $b['name'] : ($cfg['name'] ?? $b['key']),
            'config' => $cfg,
            'count' => count($tasks),
        ];
    }
    respond($out);
}

$boardKey = resolve_board_key($registry);
[$config, $tasks] = load_board($boardKey);
$statuses = $config['statuses'] ?? DEFAULT_STATUSES;
$units    = (string)($config['units'] ?? 'days');

switch ($method) {
    case 'GET':
        $statusFilter = isset($_GET['status']) ? $_GET['status'] : null;
        if ($statusFilter !== null && status_ok($statusFilter, $statuses)) {
            $tasks = array_values(array_filter($tasks, fn($t) => ($t['status'] ?? '') === $statusFilter));
        }
        usort($tasks, function ($a, $b) {
            return strcmp((string)($a['created'] ?? ''), (string)($b['created'] ?? ''));
        });
        respond([
            'board'  => ['key' => $boardKey, 'name' => $config['name']],
            'config' => $config,
            'tasks'  => $tasks,
        ]);
        break;

    case 'POST':
        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            respond(['error' => 'title is required'], 400);
        }
        $duration = normalize_duration($body['duration'] ?? $body['days'] ?? ($config['durationOptions'][0] ?? 1), $config['durationOptions']);
        $desc = normalize_desc($body['description'] ?? '', (int)($config['descMaxChars'] ?? 140));
        // New tasks always start in Pending; validate only if a status was supplied.
        $status = status_ok((string)($body['status'] ?? 'Pending'), $statuses)
            ? (string)($body['status'] ?? 'Pending')
            : 'Pending';
        $now = now_iso();
        $task = [
            'id'          => make_id(),
            'title'       => mb_substr($title, 0, 500),
            'description' => $desc,
            'duration'    => $duration,
            'status'      => $status,
            'created'     => $now,
        ];
        // Stamp lifecycle dates at creation so the log is meaningful even for tasks
        // dropped straight into InProgress / Completed (not via a Pending transition).
        if ($status === 'InProgress') { $task['started'] = $now; }
        if ($status === 'Completed')  { $task['started'] = $now; $task['completed'] = $now; }
        // Optional worker assignment (a board's own `workers` list). Empty = unassigned.
        $assignee = mb_substr(trim((string)($body['assignee'] ?? '')), 0, 50);
        if ($assignee !== '') { $task['assignee'] = $assignee; }
        $tasks[] = $task;
        save_board($boardKey, $config, $tasks);
        respond(['ok' => true, 'task' => $task], 201);
        break;

    case 'PATCH':
    case 'PUT':
        $id = (string)($body['id'] ?? $_GET['id'] ?? '');
        if ($id === '') {
            respond(['error' => 'id is required'], 400);
        }
        // Field-driven update: whichever editable fields are present get updated.
        //   - board status moves / chip cycles send { id, status }
        //   - card "edit" sends { id, title, description, duration }
        // Status (and its lifecycle stamping) only runs when status is supplied.
        $hasStatus = array_key_exists('status', $body) && $body['status'] !== null && $body['status'] !== '';
        $hasTitle  = array_key_exists('title', $body);
        $hasDesc   = array_key_exists('description', $body);
        $hasDur    = array_key_exists('duration', $body) || array_key_exists('days', $body);
        // `assignee` is a status-like move (who is doing it), NOT a content edit -- so
        // it is allowed on ANY status, exactly like moving a card between columns.
        $hasAssignee = array_key_exists('assignee', $body);
        // A content edit (title / description / duration) is allowed ONLY while the
        // task is still Pending. Once it has started or moved to any other status,
        // its content is locked. A status-only or assignee change is unaffected.
        $isContentEdit = $hasTitle || $hasDesc || $hasDur;
        if (!$hasStatus && !$isContentEdit && !$hasAssignee) {
            respond(['error' => 'nothing to update (send at least one of title, description, duration, status, assignee)'], 400);
        }
        $now = now_iso();
        $found = false;
        $updated = null;
        foreach ($tasks as &$t) {
            if (($t['id'] ?? '') === $id) {
                if ($isContentEdit && ($t['status'] ?? 'Pending') !== 'Pending') {
                    respond([
                        'error' => 'locked — only Pending tasks can be edited (current status: ' . (($t['status'] ?? 'Pending')) . ')',
                    ], 403);
                }
                if ($hasTitle) {
                    $t['title'] = mb_substr(trim((string) $body['title']), 0, 500);
                }
                if ($hasDesc) {
                    $t['description'] = normalize_desc($body['description'] ?? '', (int)($config['descMaxChars'] ?? 140));
                }
                if ($hasDur) {
                    $t['duration'] = normalize_duration(
                        $body['duration'] ?? $body['days'] ?? ($config['durationOptions'][0] ?? 1),
                        $config['durationOptions']
                    );
                }
                // Lifecycle timestamps -- only set when the status actually changes.
                //   started  : set ONLY on a Pending -> InProgress transition, and cleared
                //              on a move back to Pending.
                //   completed: set when a task lands in Completed.
                if ($hasStatus) {
                    $newStatus = (string) $body['status'];
                    if (!status_ok($newStatus, $statuses)) {
                        respond(['error' => 'invalid status (use one of ' . implode('|', $statuses) . ')'], 400);
                    }
                    $oldStatus = $t['status'] ?? 'Pending';
                    $t['status'] = $newStatus;
                    if ($oldStatus === 'Pending' && $newStatus === 'InProgress') {
                        $t['started'] = $now;
                    }
                    if ($newStatus === 'Pending') {
                        unset($t['started']); // back to the wall: not started yet
                    }
                    if ($newStatus === 'Completed') {
                        $t['completed'] = $now;
                    }
                }
                // Worker assignment -- a status-like move, allowed on any status.
                // An explicit empty string clears the assignment; null leaves it as-is.
                if ($hasAssignee) {
                    $worker = mb_substr(trim((string)$body['assignee']), 0, 50);
                    if ($worker === '') {
                        unset($t['assignee']);
                    } else {
                        $t['assignee'] = $worker;
                    }
                }
                // A task can never be left with a blank title.
                if (trim((string)($t['title'] ?? '')) === '') {
                    respond(['error' => 'title cannot be empty'], 400);
                }
                $found = true;
                $updated = $t;
                break;
            }
        }
        unset($t);
        if (!$found) {
            respond(['error' => 'task not found'], 404);
        }
        save_board($boardKey, $config, $tasks);
        respond(['ok' => true, 'task' => $updated]);
        break;

    case 'DELETE':
        $id = (string)($body['id'] ?? $_GET['id'] ?? '');
        if ($id === '') {
            respond(['error' => 'id is required'], 400);
        }
        $before = count($tasks);
        $tasks = array_values(array_filter($tasks, fn($t) => ($t['id'] ?? '') !== $id));
        if (count($tasks) === $before) {
            respond(['error' => 'task not found'], 404);
        }
        save_board($boardKey, $config, $tasks);
        respond(['ok' => true, 'id' => $id]);
        break;

    default:
        respond(['error' => 'method not allowed'], 405);
}

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
        @chmod($file, 0600); // keep board data owner-only (atomic write resets umask to 644)
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
    @chmod($file, 0600); // keep board data owner-only (atomic write resets umask to 644)
}

// ---- Helpers -----------------------------------------------------------------

function make_id(): string {
    return 't' . bin2hex(random_bytes(6));
}

function now_iso(): string {
    return (new DateTime('now', new DateTimeZone('UTC')))->format('c');
}

// ---- Repeating / scheduling --------------------------------------------------
// A task may carry two optional, additive fields (absent on legacy rows):
//   "canStartOnUTC" : ISO-8601 UTC timestamp ("can start on" gate). Absent/past =
//     visible now. The UI hides any task whose canStartOnUTC is still in the
//     future, but the JSON keeps it.
//   "repeat"     : { every:int(>=1), unit:"hours"|"days"|"weeks", at?:ISO-UTC } | null.
//     When a task with a repeat lands in Completed, a fresh Pending copy is
//     spawned so recurring chores re-appear without anyone recreating them. The
//     copy is UNASSIGNED.
//     The optional `at` is a clock ANCHOR (an absolute UTC instant). When present,
//     the next occurrence is the first grid line `at + k*interval` that is >= now,
//     so the task re-appears at a fixed WALL-CLOCK time (e.g. 07:00 every morning)
//     regardless of when the previous one was finished -- this stops the schedule
//     from creeping later day over day. When `at` is absent, the legacy behavior
//     holds: next = completed_at + every*unit. Both are additive/absent-by-default,
//     so legacy repeating tasks are untouched (no migration).

// Normalize a repeat spec. null/blank/invalid => one-time (null). A valid spec
// always returns {every, unit}. An invalid unit falls back to one-time so a bad
// payload can never create a runaway spawn loop.
function normalize_repeat($val): ?array {
    if ($val === null || $val === '' || $val === false) return null;
    $every = 0; $unit = ''; $at = null;
    if (is_array($val)) {
        $every = (int)($val['every'] ?? 0);
        $unit  = strtolower((string)($val['unit'] ?? ''));
        $at    = normalize_can_start_on_utc($val['at'] ?? null);
    }
    if ($every < 1) $every = 1;
    if (!in_array($unit, ['hours', 'days', 'weeks'], true)) {
        return null; // invalid unit => treat as one-time
    }
    // Keep `at` only when it parsed to a real instant; otherwise the chain falls
    // back to the legacy completed_at + interval behavior.
    return $at !== null ? ['every' => $every, 'unit' => $unit, 'at' => $at]
                       : ['every' => $every, 'unit' => $unit];
}

// "Can start on" gate. null/blank/invalid => visible now (null). Accepts an
// ISO-8601 UTC timestamp (with offset/Z, as the browser sends it). Stored as a
// UTC 'c' string to match created/completed/started.
function normalize_can_start_on_utc($val): ?string {
    if ($val === null || $val === '' || $val === false) return null;
    $s = trim((string)$val);
    if ($s === '') return null;
    $ts = strtotime($s);
    if ($ts === false) return null;
    return (new DateTime('@' . $ts))->format('c');
}

// Advance an ISO timestamp by `every` units and return the new UTC 'c' string.
// (Legacy helper; next_occurrence() supersedes this for spawning.)
function add_interval(string $iso, int $every, string $unit): string {
    $ts = strtotime($iso);
    if ($ts === false) $ts = time();
    $units = ['hours' => 3600, 'days' => 86400, 'weeks' => 604800];
    $sec = $units[$unit] ?? 86400;
    $ts += max(1, $every) * $sec;
    return (new DateTime('@' . $ts))->format('c');
}

// Compute the next occurrence of a repeating task and return it as a UTC 'c' string.
// Clock-anchored: if the repeat spec carries an `at` anchor, the sequence is a fixed
// grid starting at `at`, and we return the first line `at + k*interval` that is >= now.
// Because the gate comes from a fixed clock (not from when the last one was finished),
// a late completion CANNOT push the schedule later -- this is what stops the day-over-day
// drift. With no `at`, fall back to the legacy behavior: next = completed_at + interval.
//   - anchor in the future  -> first appearance is the anchor itself (k = 0).
//   - anchor in the past    -> the next grid line at or after now.
function next_occurrence(?string $atIso, string $completedAt, int $every, string $unit, string $nowIso): string {
    $units = ['hours' => 3600, 'days' => 86400, 'weeks' => 604800];
    $step = (int)($units[$unit] ?? 86400);
    if ($step < 1) $step = 86400;
    $nowTs = strtotime($nowIso);
    if ($nowTs === false) $nowTs = time();
    // ---- No clock anchor: preserve the LEGACY behavior exactly. ----
    // A task whose repeat spec has no `at` keeps its old semantics: the next
    // occurrence is simply completed_at + every*unit. This is what existing
    // repeating tasks rely on, so they must NOT start spawning early.
    if ($atIso === null || $atIso === '') {
        $compTs = strtotime($completedAt);
        if ($compTs === false) $compTs = $nowTs;
        return (new DateTime('@' . ($compTs + $step)))->format('c');
    }
    // ---- Clock anchor present: build a fixed grid starting at `at`. ----
    $anchorTs = strtotime((string)$atIso);
    if ($anchorTs === false) $anchorTs = $nowTs;
    if ($anchorTs >= $nowTs) {
        // Anchor hasn't passed yet: the first appearance is the anchor itself.
        return (new DateTime('@' . $anchorTs))->format('c');
    }
    // Anchor is in the past: advance by whole intervals to the first that is >= now.
    $elapsed = $nowTs - $anchorTs;
    $k = (int)floor($elapsed / $step);
    $next = $anchorTs + $k * $step + $step;
    return (new DateTime('@' . $next))->format('c');
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

// ---- Write safety: reject an explicitly-requested board key that isn't registered.
// GET falls back to the default (a harmless read), but a WRITE (POST/PATCH/PUT/DELETE)
// to an unknown board key would otherwise silently mutate the DEFAULT board -- exactly
// the hazard that let a throwaway test key pollute a live board. So any write that
// names a board must name a REAL one, or it gets a 400. Writes with NO ?board= still
// target the default, which is always registered.
if (in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
    $reqKey = isset($_GET['board']) ? trim((string) $_GET['board']) : '';
    if ($reqKey !== '' && !in_array($reqKey, array_column($registry['boards'], 'key'), true)) {
        respond(['error' => 'unknown board key "' . $reqKey . '" -- writes must target a registered board'], 400);
    }
}

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
        // Scheduling fields (repeating + "available from"). Both optional and
        // additive: a blank/omitted value means "no repeat" / "available now",
        // so a plain task never gains these keys and legacy data stays identical.
        $repeat = normalize_repeat($body['repeat'] ?? null);
        if ($repeat !== null) { $task['repeat'] = $repeat; }
        $canStartOnUTC = normalize_can_start_on_utc($body['canStartOnUTC'] ?? null);
        if ($canStartOnUTC !== null) { $task['canStartOnUTC'] = $canStartOnUTC; }
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
        // Scheduling (`repeat` / `canStartOnUTC`) is also a status-like move: rescheduling
        // when or how often a task appears. Like a card move it is allowed while the
        // task is still Pending (so you can move a future task's date / enable a repeat)
        // but is locked once the task has started -- same rule as content edits.
        $hasReschedule = array_key_exists('repeat', $body) || array_key_exists('canStartOnUTC', $body);
        // A content edit (title / description / duration) is allowed ONLY while the
        // task is still Pending. Once it has started or moved to any other status,
        // its content is locked. A status-only or assignee change is unaffected.
        $isContentEdit = $hasTitle || $hasDesc || $hasDur;
        if (!$hasStatus && !$isContentEdit && !$hasAssignee && !$hasReschedule) {
            respond(['error' => 'nothing to update (send at least one of title, description, duration, status, assignee, repeat, canStartOnUTC)'], 400);
        }
        $now = now_iso();
        $found = false;
        $updated = null;
        foreach ($tasks as &$t) {
            if (($t['id'] ?? '') === $id) {
                // Capture the task's ORIGINAL status up front. Both locks below read
                // this -- never the post-mutation value -- so a combined PATCH that
                // moves a card out of Completed AND reassigns in the same request
                // cannot sneak past either gate.
                $origStatus = $t['status'] ?? 'Pending';
                if ($isContentEdit && $origStatus !== 'Pending') {
                    respond([
                        'error' => 'locked — only Pending tasks can be edited (current status: ' . $origStatus . ')',
                    ], 403);
                }
                // Owner lock: once a task has reached Completed, its owner is frozen.
                // A reassign (including an "unassign") is refused on a completed task,
                // even when combined with a move back to another column -- you cannot
                // reassign the moment a task is done. Pending/InProgress/Halted may
                // change owners freely, exactly like a card move.
                if ($hasAssignee && $origStatus === 'Completed') {
                    respond([
                        'error' => 'owner locked — a completed task\'s owner cannot be changed',
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
                // Worker assignment -- a status-like move, allowed on any non-completed
                // status (a Completed task is already gated above). An explicit empty
                // string clears the assignment; null leaves it as-is.
                if ($hasAssignee) {
                    $worker = mb_substr(trim((string)$body['assignee']), 0, 50);
                    if ($worker === '') {
                        unset($t['assignee']);
                    } else {
                        $t['assignee'] = $worker;
                    }
                }
                // Scheduling -- a status-like move, allowed only while the task is still
                // Pending (same lock as content edits): you can move a future task's
                // "available from" date or enable/clear its repeat before it starts, but
                // not after. A null/blank value clears the field.
                if ($hasReschedule) {
                    if ($origStatus !== 'Pending') {
                        respond([
                            'error' => 'locked — only Pending tasks can be rescheduled (current status: ' . $origStatus . ')',
                        ], 403);
                    }
                    if (array_key_exists('repeat', $body)) {
                        $rep = normalize_repeat($body['repeat']);
                        if ($rep === null) { unset($t['repeat']); } else { $t['repeat'] = $rep; }
                    }
                    if (array_key_exists('canStartOnUTC', $body)) {
                        $su = normalize_can_start_on_utc($body['canStartOnUTC']);
                        if ($su === null) { unset($t['canStartOnUTC']); } else { $t['canStartOnUTC'] = $su; }
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
        // REPEAT TRIGGER: when a task with a repeat spec just reached Completed, spawn a
        // fresh occurrence so a recurring chore re-appears without anyone recreating it.
        // The copy is a brand-new independent Pending task: it carries the same
        // title/description/duration and the same repeat spec (so it chains forever),
        // but it is UNASSIGNED -- each occurrence is a fresh claim for the kids. Its
        // canStartOnUTC is next_occurrence(at, completed_at, every, unit, now): when the
        // task carries an `at` clock anchor, the copy re-appears at the fixed wall-clock
        // time (drift-proof); otherwise it falls back to completed_at + every*unit. The
        // copy stays hidden in Pending until that time (the UI only shows tasks whose
        // canStartOnUTC has passed).
        // We append
        // it to $tasks here so the single save_board() below persists BOTH the completed
        // original and the new copy atomically.
        $spawned = null;
        if ($hasStatus
            && ($updated['status'] ?? '') === 'Completed'
            && ($origStatus ?? 'Pending') !== 'Completed') {
            $rep = normalize_repeat($updated['repeat'] ?? null);
            if ($rep !== null) {
                $completedAt = $updated['completed'] ?? $now;
                $copy = [
                    'id'          => make_id(),
                    'title'       => $updated['title'],
                    'duration'    => $updated['duration'] ?? 1,
                    'status'      => 'Pending',
                    'created'     => $now,
                    'repeat'      => $rep,                 // carried forward -> chains
                    'canStartOnUTC'  => next_occurrence(
                        $rep['at'] ?? null,
                        $completedAt,
                        $rep['every'], $rep['unit'], $now,
                    ),
                ];
                $desc = trim((string)($updated['description'] ?? ''));
                if ($desc !== '') { $copy['description'] = $desc; }
                // No 'assignee' key -> the new occurrence is unassigned by design.
                $tasks[] = $copy;
                $spawned = $copy;
            }
        }
        save_board($boardKey, $config, $tasks);
        if ($spawned !== null) {
            respond(['ok' => true, 'task' => $updated, 'spawned' => $spawned]);
        } else {
            respond(['ok' => true, 'task' => $updated]);
        }
        break;

    case 'DELETE':
        $id = (string)($body['id'] ?? $_GET['id'] ?? '');
        if ($id === '') {
            respond(['error' => 'id is required'], 400);
        }
        // Delete lock: a task that has reached Completed is permanent. Refuse the
        // delete (403) BEFORE any mutation, so a completed task is never removed --
        // its owner and its record both stay frozen.
        $target = null;
        foreach ($tasks as $t) {
            if (($t['id'] ?? '') === $id) { $target = $t; break; }
        }
        if ($target !== null && ($target['status'] ?? 'Pending') === 'Completed') {
            respond([
                'error' => 'cannot delete — a completed task is locked and permanent',
            ], 403);
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

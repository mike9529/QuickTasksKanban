# QuickTasks — kanban

A tiny, **database-free** kanban board for a household. Tasks live in JSON files
on disk, a thin PHP layer serves/updates them, and two static pages render the
board and an admin form. No build step, no package manager, no database — it runs
on the PHP built-in server *or* Apache + mod_php.

```
┌──────────────┐   fetch/POST/PATCH/DELETE   ┌─────────────┐   read/write   ┌──────────────────────┐
│  browser UI   │  ─────────────────────────▶  │  api.php     │  ────────────▶  │  boards/*.json      │
│ index.html    │  ◀─────────────────────────  │ (JSON store) │  ◀──────────────  │  boards.json        │
│ admin.html    │        JSON                  └─────────────┘                │  tasks.json (legacy)│
└──────────────┘                                                               └──────────────────────┘
```

---

## Status

| | |
|---|---|
| **Version** | **1.29** |
| **State** | Stable, in production |
| **Runs** | Kids' household use — this snapshot is the live **staging** board |
| **Multi-board** | Yes — `Adults` + `Children`, each its own JSON file |
| **Team points** | Yes — weekly goal with a progress bar + 25/50/75/100% milestone badges |
| **Repeating tasks** | Yes — a task can recur on its own, sit hidden until a future start time, or lock to a fixed clock time (v1.26) |

Versioning convention: a version number is assigned **when a feature set is
completed and shipped**. `1.29` (this release) adds a live InProgress timer pill
(`hh:mm:ss.d`, decisecond refresh) and swaps the completed-items log's "Added"
column for a "Done by" owner bubble, and includes a `chmod 0600` hardening on the
board writes; `1.28` tidied the Completed-column window into a single per-board
config variable; `1.27` puts the most-recently-completed task on top of the
Completed column; `1.26` added clock-anchored repeating tasks (the drift stop);
`1.25` renamed the task's `startUntil` "can start on" gate to `canStartOnUTC`
(a schema rename, see the migration policy below); `1.2` added repeating /
scheduled tasks plus two completed-task locks; `1.1` added the milestone badges;
`1.0` was the initial multi-board build. See [Revisions](#revisions) below.

---

## What it does

- **Kanban board** — columns are the board's `statuses` (e.g. Pending → In
  Progress → Halted → Completed). Drag a card between columns to change status.
- **Multi-board** — a registry (`boards.json`) lists the boards; a dropdown in the
  top bar switches between them. Each board has its own tasks, settings, and data
  file.
- **Per-board config** — estimate **units** (`days` or `minutes`), allowed
  **duration options**, a **completed-window** (how long a finished card stays in
  its column), a **team-points goal**, **workers** (assignees), and more — all per
  board.
- **Team points** — when a board has a non-zero `pointGoal`, a header shows
  points earned this week toward the goal, a progress bar, "X pts to go", a live
  countdown to Monday's reset, and **25/50/75/100% milestone badges** (100% = a
  golden 👑 crown). `pointGoal: 0` hides the header entirely.
- **Repeating / scheduled tasks** — a task can be set to **recur** (complete it and
  the next occurrence spawns automatically) or to stay **hidden until a future
  start time** (a one-time "buy milk tomorrow"). The next copy is always created
  **unassigned**, so each occurrence is a fresh claim. In **v1.26** a recurring
  task can also lock to a **fixed wall-clock time** (a `repeat.at` anchor), so it
  re-appears at the same time every day instead of drifting later as days go by.
- **Admin form** — add/edit/delete tasks. Editing a task's content *or* its
  schedule is allowed only while it is still `Pending`; once started, the content
  and schedule lock (status can still move).
- **Auto-archive** — completed tasks older than `logPruneDays` are moved out of the
  live file into `boards/<key>.archive.json`, so the working JSON never grows
  unbounded.

---

## Requirements

- **PHP** (5.6+, built-in server or mod_php). Nothing else — no DB, no npm.
- Any modern browser.

That's it. The UI is plain HTML/CSS/JS that talks to `api.php` over JSON.

---

## Files

```
quicktasks/
├── index.html          # Board view: columns + team-points header + completed log
├── admin.html          # Admin view: add/edit/delete task form + full task list
├── api.php             # The JSON store + HTTP API (read/write/delete tasks & boards)
├── css/
│   └── style.css       # All styling (dark theme, responsive, mobile stack <760px)
├── boards.json         # REGISTRY: which boards exist + which is the default
├── boards/
│   ├── adults.json     # Adults board: { config, tasks }
│   └── children.json   # Children board: { config, tasks }
├── tasks.json          # LEGACY single-board store (unused by the current UI)
└── docs/
    └── screenshots/      # Release screenshots (Team Points bonus states)
```

- `index.html` / `admin.html` — the two pages. The board view is what people use
  day-to-day; the admin view is where tasks are created and edited.
- `api.php` — the entire backend. One file. It loads the registry, resolves the
  requested board, and handles GET/POST/PATCH/DELETE over JSON. All writes are
  **atomic** (temp file + `rename`) with `LOCK_EX` to avoid corruption under the
  multi-worker Apache prefork setup.
- `css/style.css` — presentation only; a CSS grid for the board and a responsive
  layout that stacks on phones.
- `boards/*.json` — the **source of truth for each board**: a `config` block plus
  a `tasks` array. This is where the data lives.
- `boards.json` — the **registry** (see below).
- `tasks.json` — the original single-board store, kept for reference.
- `docs/screenshots/` — release screenshots (the Team Points bonus-prize states).
  UI and `api.php` **no longer read or write it** — data now lives under
  `boards/`. Safe to delete later; left in place so nothing breaks if something
  old still points at it.

---

## How the JSON is configured

### 1) The registry — `boards.json`

Lists the boards and which one loads by default:

```json
{
  "default": "adults",
  "boards": [
    { "key": "adults",   "name": "Adults" },
    { "key": "children", "name": "Children" }
  ]
}
```

- `key` → the board's id and its data file (`boards/<key>.json`).
- `name` → the label shown in the switcher dropdown.
- `default` → which board loads when you open the page without `?board=…`.
- To **add a board**: add a `{ "key", "name" }` entry here **and** create
  `boards/<key>.json` with a `config` + `tasks` block (see below). Unknown board
  keys in requests fall back to `default`; a missing data file just starts empty
  from the built-in defaults.

### 2) Each board — `boards/<key>.json`

Every board is one file: a `config` block + a `tasks` array. Any config field you
omit is filled from built-in defaults, so a board file can be minimal.

```json
{
  "config": {
    "name": "Children",
    "units": "minutes",
    "durationOptions": [5, 10, 15, 30, 45, 60],
    "completedWindowDays": 2,
    "pollMs": 15000,
    "descMaxChars": 140,
    "statuses": ["Pending", "InProgress", "Halted", "Completed"],
    "workers": ["Alex", "Chris", "Daddy", "Mommy"],
    "pointGoal": 10,
    "logPruneDays": 30
  },
  "tasks": [ /* … */ ]
}
```

| Field | Meaning |
|---|---|
| `name` | Board display name (registry `name` wins if both are set). |
| `units` | `"days"` or `"minutes"` — the unit for estimates and points. |
| `durationOptions` | Allowed estimate values (the dropdown in admin). New/edited estimates are **snapped to the nearest** of these. |
| `completedWindowDays` | How long a Completed card stays in its column before it drops off the board (older completions roll into the "Completed items" log). |
| `pollMs` | How often the UI re-fetches from the API to stay in sync across tabs. |
| `descMaxChars` | Max length of a task description. |
| `statuses` | The columns, in order. Drives the board columns and the admin status cycle. |
| `workers` | Named people for the "Assigned to" dropdown. Empty list hides the field. |
| `pointGoal` | Weekly team-points target. **`0` hides the points header.** Points are summed from completed tasks (1 pt per completed task) in the current week. |
| `logPruneDays` | Completed tasks older than this are archived to `boards/<key>.archive.json`. `0` disables archiving. |

### 3) A task

```json
{
  "id": "t0b9e2576957e",
  "title": "Do one (1) iReady Math",
  "description": "",
  "duration": 30,
  "status": "Completed",
  "created": "2026-11-17T20:07:31+00:00",
  "started": "2026-11-17T20:33:09+00:00",
  "completed": "2026-11-17T20:33:42+00:00",
  "assignee": "Chris",
  "repeat": { "every": 1, "unit": "days" },
  "canStartOnUTC": "2026-11-20T14:00:00+00:00"
}
```

| Field | Meaning |
|---|---|
| `id` | Server-generated (`t` + random hex). Unique per board. |
| `title` | Required. |
| `description` | Optional note, capped at `descMaxChars`. |
| `duration` | Estimate in the board's `units`. (Legacy rows used `days`; the API auto-promotes `days` → `duration` on read.) |
| `status` | One of the board's `statuses`. |
| `created` | ISO-8601, set at creation. |
| `started` | Set on a `Pending → InProgress` transition; cleared if moved back to `Pending`. |
| `completed` | Set when the task lands in `Completed`. |
| `assignee` | Optional; one of the board's `workers`. Absent = unassigned. |
| `repeat` | **Optional, v1.2.** `{ every: int≥1, unit: "hours"\|"days"\|"weeks", at?: ISO-UTC }`. When present and the task is completed, the API spawns a fresh **Pending** copy. With no `at`, the copy's `canStartOnUTC = completed_at + every·unit` (legacy). With an `at` **clock anchor**, the copy's `canStartOnUTC` is the **first grid line `at + k·interval ≥ now`** — a fixed wall-clock time, so a late completion can't push the schedule later (v1.26 drift stop). Absent = one-time. |
| `canStartOnUTC` | **Optional, v1.2** (renamed from `startUntil` in **v1.25**). ISO-8601 UTC "can start on" gate. Past/absent = show now; future = the task sits in `Pending` but is **hidden on the board** until the time arrives (also powers one-time future tasks). |

Both `repeat` and `canStartOnUTC` are **additive and absent by default**, so legacy
board files need no migration — old tasks simply have neither field and behave
exactly as before.

---

## The API — `api.php`

All endpoints are under `/api.php`, all bodies/responses are JSON.

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/api.php?boards=1` | Registry + each board's config + task count (powers the switcher). |
| `GET` | `/api.php?board=<key>` | `{ board, config, tasks }` for one board. |
| `GET` | `/api.php` | Same, for the **default** board. |
| `GET` | `/api.php?board=<key>&status=Pending` | Filter the returned tasks by status. |
| `POST` | `/api.php?board=<key>` | Create a task: `{ title, duration, description?, status?, assignee? }`. New tasks start in `Pending`. |
| `PATCH` / `PUT` | `/api.php?board=<key>` | Update `{ id, … }` — any of `status`, `title`, `description`, `duration`, `assignee`, `repeat`, `canStartOnUTC`. |
| `DELETE` | `/api.php?board=<key>&id=<id>` | Delete a task. |

**Rules enforced by the API:**

- **Content edits (title/description/duration) and schedule edits (`repeat` /
  `canStartOnUTC`) are allowed only while a task is `Pending`.** Once it starts (or
  moves to any other status) its content and schedule are locked — such an edit on
  a non-Pending task returns `403`.
- **Status is a "move"**, allowed at any time.
- **Lifecycle stamps** are automatic: `started` on `Pending → InProgress`
  (cleared when moved back to `Pending`), `completed` when it lands in
  `Completed`.
- **Owner lock (v1.2):** a `PATCH` that sets `assignee` on a task whose *original*
  status is `Completed` is refused with `403` ("owner locked — a completed task's
  owner cannot be changed"). The check reads the pre-mutation status, so a combined
  "move out of Completed + reassign" cannot bypass it; a refused change is rejected
  before the board is saved. (Pending / InProgress / Halted can still be reassigned.)
- **Delete lock (v1.2):** a `DELETE` of a `Completed` task is refused with `403`
  ("cannot delete — a completed task is locked and permanent"), checked before any
  mutation. A completed task is permanent.
- **Spawn on complete (v1.2):** when a task carrying a `repeat` spec transitions
  into `Completed`, the API appends a fresh **independent `Pending`** copy (same
  title/description/duration/`repeat`) with `canStartOnUTC = completed_at + every·unit`,
  written in the **same atomic save** as the parent. The spawned copy is
  **unassigned** by design, and the response includes a `spawned` field so the UI
  can acknowledge it. A one-time task (no `repeat`) spawns nothing.
- **Estimates snap** to the nearest `durationOptions` value; titles cap at 500
  chars, descriptions at `descMaxChars`.
- **Unknown board key** → falls back to the default. **Unknown task id** → `404`.
- Writes are **atomic** (`temp + rename`, `LOCK_EX`) — safe under Apache prefork.
- `GET /api.php` returns tasks **sorted by `created`** (oldest first).
- `Access-Control-Allow-Origin: *` is set, so the pages can be served over any
  origin.

---

## Run it

**Option A — PHP built-in server (dev, single-process):**

```bash
cd quicktasks
php -S <host>:<port>   # pick any free port
# then open http://<host>:<port>/index.html
```

**Option B — Apache + mod_php (the staging/prod setup, multi-process):**

Point an Apache vhost `DocumentRoot` at this folder, ensure `mod_php` is loaded,
and restart Apache. No rewrite rules needed — `index.html`, `admin.html`,
`css/`, and `api.php` are served as-is.

> **Note:** the built-in server is single-process and is not intended for heavy
> concurrent load; Apache prefork + the atomic writes in `api.php` is the
> supported multi-process deployment.

---

## Data & backup

- **The data is the JSON files.** Back up `boards.json`, `boards/`, and
  `tasks.json` and you have everything. `boards/<key>.archive.json` files are
  created automatically as old completions are pruned — back those up too if you
  keep them.
- **This repo's code is version 1.29; the board JSON is a point-in-time
  snapshot as of version 1.27.** The live staging board's JSON (the kids' current
  tasks) is a **separate working copy** and is **not** kept in sync with the repo
  automatically — commit/publish a new version only when you intend to snapshot the
  data.
- To add a brand-new board: add it to `boards.json` and drop in
  `boards/<key>.json` (or just let the API create it empty on first write).

### Migration policy — schema always migrates, data never does

Standing rule (2026-11-19), applied to every schema change and recorded
here so it's not lost:

- **Schema migrates everywhere.** When a field is **renamed, restructured, or
  added to the documented schema** (this README, `VERSION`, `REVISIONS.md`, the
  code), the **schema definition itself is updated in every copy** — dev, staging,
  and the GitHub repo — and the change is committed. A renamed field must never
  leave an old-schema ghost behind in the docs.
- **Data is never auto-migrated by the app.** The server **never** rewrites the
  stored JSON to rename a field on its own; it degrades gracefully instead. A
  field the code no longer recognizes (e.g. an old `startUntil` after v1.25) is
  **ignored as an unknown key** — an old client simply drops that gate rather than
  crashing. No 500s, no silent corruption.
- **Data migration, when it matters, is a deliberate one-time human/agent step** —
  a scripted key rename on the *live* board files **only where that data actually
  exists**, with a backup kept. Example: v1.25 renamed `startUntil` →
  `canStartOnUTC`; dev and the git data carried no such key (nothing to do), but
  staging's live `children.json` held 11 of them, so those 11 keys were renamed
  in-place there (JSON-validated, backup retained) so their gates didn't silently
  die. Dev/git needed no data change; only the location that had the data did.
- **If the GitHub schema ever falls behind the data** (a field the live data uses
  that the repo docs don't describe, or a renamed field left undocumented), that's
  a gap: **open a commit to bring the schema in sync** — don't leave it stale.

---

## Roadmap / in progress

- **iPad read-only gating** (User-Agent based) — **on hold**; the live app is
  being tested with the kids, so the boards stay fully writable until that is
  otherwise.
- **iCloud sync of the board JSON** — parked; revisit when the app stabilizes.
- Nothing else in progress — **1.29 is the current release.**

---

## Revisions
## Revisions

### v1.33 — 2026-11-21 (RELEASED on GitHub, tag v1.33)
- **Bug fix — bonus badge row no longer wraps.** At narrow user-agent widths the
  milestone + "Bonus - .NN" badge row wrapped to a second line, changing the
  banner's width. Forced `flex-wrap: nowrap` on `.points-miles-row` (plus tighter
  badge sizing) so the badges + bonus badge always stay on one line. `css/style.css`
  only; no data change; fresh-load guard still PASSes.


### v1.32 — 2026-11-21 (RELEASED on GitHub, tag v1.32)
- **Post-goal bonus prizes on the Team Points banner.** After the team clears the
  100% weekly point goal, each whole point of overage reveals the next prize in
  order, shown as compact chips **under the milestone badges and above the
  progress meter** (inside `.points-middle`, not a new row) — so the team-points
  section **never grows in height**. A gold, gently-pulsing **"Bonus - .NN"**
  badge shows the live overage as the headline reward number.
- **Progressive thresholds.** Prizes are per-board `config.bonusPrizes` (array of
  strings or `{text, threshold}` objects, or a single value). Each prize lights
  when `points >= threshold[i]`; the Children sample is goal +10% / +20% / +30%
  (11.0 / 12.0 / 13.0). No prizes configured → the whole section is hidden.
- **Compact locked chips.** A not-yet-earned prize shows just `[gift] [lock]`
  (the name is hidden so the chip stays small); only the **next pending** prize
  shows its "N.NN to go" count, to the right of the lock. When earned the chip
  pops gold and the name reveals.
- **Screenshots** in `docs/screenshots/`: `TeamPoints_stateA_allDimmed.png`
  (goal met, all locked) and `TeamPoints_stateB_oneLit.png` (first prize earned).
- **Files:** `index.html` (bonus HTML into `.points-middle` + per-threshold reveal
  loop), `css/style.css` (`.points-middle` stack, `.points-bonus-badge`,
  `.bonus-chip` locked/lit, `.bonus-need`), `boards/children.json` (the
  `bonusPrizes` sample — prod data otherwise untouched). No `api.php` /
  `admin.html` change; the fresh-load guard still PASSes.


### v1.27 — 2026-11-19 (RELEASED on dev + staging + GitHub, tag v1.27)
- **Completed column: most-recently-completed on top.** A task that's just been
  completed now jumps to the **top** of the Completed column and pushes the rest
  down, so the latest finished chore is the first thing you see. It sorts by
  `completed` (descending), with `created` as the tiebreaker, so even chores
  finished in the same moment still land newest-first. The rest of the board is
  untouched — this only affects the Completed column.

### v1.26 — 2026-11-19 (RELEASED on dev + staging + GitHub, tag v1.26)
- **Feature — clock-anchored repeating tasks (the drift stop).** A recurring task now
  can lock to a **fixed wall-clock time** so it re-appears at the same time every
  cycle instead of creeping later day over day.
  - **Proposed by Alexander O.** — the idea of anchoring a repeat to a *fixed time*
    (rather than relative to when it was last finished) was his, and it was
    instrumental in landing the final design. Credit where it's due.
  - **The problem (why):** the old spawn rule set the next occurrence to
    `completed_at + interval`. Because the gate was derived from *when the task was
    finished* (not a fixed clock), every cycle the task appeared *after* the gate
    and got finished *after* its appearance, so the next gate inherited that
    lateness and the schedule ratcheted later each day. A daily chore finished at
    08:00 the first day would be appearing at 08:15, 08:30, 09:00… over successive
    days. It was "daily" in name only.
  - **The fix — a clock anchor `repeat.at`** (optional, additive, UTC ISO-8601). When
    present, the spawn is the **first grid line `at + k·interval ≥ now`**
    (`k = floor((now − at) / interval)`), so the sequence is a fixed clock and a late
    completion **cannot** push it. Examples: `at = 07:00`, daily → always 07:00;
    hourly → the next hour on the hour; weekly → the next 07:00 on a 7-day grid. A
    future `at` is the first appearance itself (`k = 0`); a past `at` snaps to the
    next line at or after now.
  - **Backward compatible:** with no `at`, the legacy `completed_at + interval`
    behavior is preserved exactly — existing repeating tasks are **untouched, no
    migration**. (This is the schema-migrates / data-doesn't policy in practice: the
    new field is additive and absent by default.)
  - **UI (`admin.html`):** when the **Repeat** toggle is on, the existing **"Can start
    on"** field doubles as the clock anchor (sent as `repeat.at`); it **defaults to
    now**, so a new recurring task appears immediately and then locks onto its clock.
    No new field was added — the knob you reach for to "start it earlier" is the one
    that sets the anchor.
  - **UI (`index.html`):** the repeat chip shows the anchor, e.g. **`↻ 07:00 d`**,
    so a recurring chore reads its re-appearance time at a glance.
  - **Fix — double repeat icon:** the chip template already prints the `↻` glyph; the
    anchored branch had prepended a second one. Removed the duplicate so a card shows
    **one** icon.
  - **Safety — reject unknown board keys on writes (`api.php`):** a write
    (POST/PATCH/PUT/DELETE) that names a board key not in the registry now returns
    `400` instead of silently falling back to the **default** board. A typo or a
    throwaway test key can no longer pollute a live board. `GET` keeps the default
    fallback (a harmless read), and writes with **no** `?board=` still target the
    default (always registered). This guard was added after a dev test wrote throwaway
    tasks to the default board via an unregistered key.
  - **Verified on the dev server** (throwaway registered board, then removed): daily past-
    anchor → next 07:00; daily future-anchor → that anchor; hourly → next hour; weekly
    → next 07:00 on a 7-day grid; legacy no-`at` → +1 day; one-time future → no spawn;
    and a drift-proof trio — finishing at 08:00 / 11:30 / 23:00 all spawned 07:00. The
    write guard was verified to 400 on an unknown key while leaving the default board
    unchanged. `php -l` clean.

### v1.25 — 2026-11-19 (RELEASED, on dev + staging + GitHub)
- **Rename — `startUntil` → `canStartOnUTC`.** The task's "can start on" gate field
  is renamed so the direction of the constraint reads clearly: a task cannot start
  before this **UTC** datetime. Behavior is unchanged — only the name changed.
  - **`api.php`:** the JSON key, the `normalize_start_until()` helper
    (`→ normalize_can_start_on_utc()`), the POST-create + PATCH-reschedule handlers,
    the spawn-on-complete copy key, the "nothing to update" 400 message, and every
    comment.
  - **`index.html`:** the Pending "hidden until it arrives" filter reads
    `t.canStartOnUTC`; comments updated.
  - **`admin.html`:** the form control `id`/`name` → `canStartOnUTC`, the JS vars
    `canStartOnUTCEl` / `canStartOnUTCHint`, the visible label **"Available from"
    → "Can start on"**, and all comments.
  - **`css/style.css`:** the schedule-control comment updated to match.
  - **No migration / no data touched:** no committed or live JSON (`boards/*.json`,
    `tasks.json`) carried `startUntil`, so this is a clean code-only rename. Any
    payload that still sends `startUntil` is now ignored by the server as an unknown
    key, so an old client degrades to "no gate" — no crash, no 500. `php -l` clean;
    zero residual `startUntil` / `normalize_start_until` / "Available from" references
    remain in the dev code.
  - **Verified on the dev server** (throwaway `scratch` board, then removed): create a
    future-gate task; create a past-gate + repeat task; reschedule a Pending task;
    clear via explicit `null`; complete → spawned copy carries `canStartOnUTC`
    (`completed_at + every·unit`), is unassigned, and does **not** carry a legacy
    `startUntil` key; rescheduling a `Completed` task → 403; a legacy-only body → 400
    and a legacy key inside a valid body is ignored. Kids' boards left byte-identical.

### v1.2 — 2026-11-19
- **Feature — repeating / scheduled tasks.** Two new optional per-task fields,
  `repeat` (`{every, unit: hours|days|weeks}`) and `startUntil` (ISO-8601
  "available from"), both additive/absent-by-default (no migration). Completing a
  repeating task **spawns the next occurrence** server-side (a fresh, **unassigned**
  `Pending` copy at `completed_at + every·unit`, in the same atomic save). A task
  can also be a **one-time future task** hidden until its `startUntil` arrives.
  Admin UI: one quiet, collapsed **"Schedule"** control ("Available from" + a
  **Repeat** toggle); the board hides future tasks and shows a `↻ N` chip on
  recurring ones. See the [task fields](#3-a-task) table.
- **Bug fix — completed tasks: owner is locked.** Reassigning a `Completed` task is
  refused (`403`); the check reads the pre-mutation status so a combined move+
  reassign can't bypass it. Board shows the owner as a 🔒 lock on completed cards.
- **Bug fix — completed tasks cannot be deleted.** A `DELETE` of a `Completed` task
  is refused (`403`, it's permanent). Board shows the delete ✕ as a 🔒 lock.
- **Cosmetic:** fixed the Schedule chevron (a literal `\u25be` was rendering
  instead of ▾), kept the repeat spec on one line, and removed a stray "All tasks"
  section from the admin add/edit page (which had been throwing
  "Can't find variable: n").

### v1.1 — 2026-11-18
- **Team-points milestone badges** (25/50/75/100%) in the free space between the
  points figures, 100% = a golden 👑 crown; the whole banner still hides when the
  goal is 0.
- **Static / read-only fallback** (carried from v1.0.1): when `api.php` can't run
  (GitHub Pages, a static host, a downloaded copy), the UI loads the committed JSON
  and shows a "read-only preview" banner instead of an empty board.

### v1.0.1 — 2026-11-18
- Static / read-only fallback introduced so the GitHub Pages showcase renders boards
  instead of a blank board.

### v1.0 — 2026-11-18
- Initial public release: multi-board Kanban (Pending / InProgress / Halted /
  Completed), drag-and-drop between columns, per-board effort estimates (days or
  minutes), team-points goal with a live progress bar, a Completed-items log with
  on/off/over-estimate coloring, worker assignment, an admin editor, and a static
  registry of boards.

---

## License

Private / personal project. No license granted.

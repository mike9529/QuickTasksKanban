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
| **Version** | **1.2** |
| **State** | Stable, in production |
| **Runs** | Kids' household use — this snapshot is the live **staging** board |
| **Multi-board** | Yes — `Adults` + `Children`, each its own JSON file |
| **Team points** | Yes — weekly goal with a progress bar + 25/50/75/100% milestone badges |
| **Repeating tasks** | Yes — a task can recur on its own or sit hidden until a future start time |

Versioning convention: a version number is assigned **when a feature set is
completed and shipped**. `1.2` (this release) adds repeating / scheduled tasks
plus two completed-task locks; `1.1` added the milestone badges; `1.0` was the
initial multi-board build. See [Revisions](#revisions) below.

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
  **unassigned**, so each occurrence is a fresh claim.
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
└── tasks.json          # LEGACY single-board store (unused by the current UI)
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
- `tasks.json` — the original single-board store, kept for reference. The current
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
| `repeat` | **Optional, v1.2.** `{ every: int≥1, unit: "hours"\|"days"\|"weeks" }`. When present and the task is completed, the API spawns a fresh **Pending** copy with `canStartOnUTC = completed_at + every·unit`. Absent = one-time. |
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
php -S 0.0.0.0:8085
# then open http://<host>:8085/index.html
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
- **This repo is a point-in-time snapshot of version 1.2.** The live staging
  board's JSON (the kids' current tasks) is a **separate working copy** and is
  **not** kept in sync with the repo automatically — commit/publish a new version
  only when you intend to snapshot the data.
- To add a brand-new board: add it to `boards.json` and drop in
  `boards/<key>.json` (or just let the API create it empty on first write).

---

## Roadmap / in progress

- **iPad read-only gating** (User-Agent based) — **on hold**; Michael is testing
  the live app with the kids, so 8085/8086 stay fully writable until he says
  otherwise.
- **iCloud sync of the board JSON** — parked; revisit when the app stabilizes.
- Nothing else in progress — **1.2 is the current release.**

---

## Revisions

### v1.25 — 2026-11-19 (RELEASING)
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
  - **Verified on dev 8085** (throwaway `scratch` board, then removed): create a
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

# QuickTasks — Revision Log

Changelog for the QuickTasks Kanban board. Keep this in sync with `VERSION` and the
GitHub README "Revisions" section.

## v1.25 — 2026-11-19 (IN DEVELOPMENT, dev only / port 8085)
A naming-only refactor: the task "can start on" gate field is renamed **`startUntil`
-> `canStartOnUTC`** so the direction of the constraint reads clearly — a task
cannot start before this UTC datetime. Behavior is unchanged; only the name
changed. **Not yet shipped** — staging (8086) and GitHub are untouched.
- **`api.php` (authoritative):** the JSON key, the `normalize_start_until()` helper
  (`-> normalize_can_start_on_utc()`), the POST-create + PATCH-reschedule handlers,
  the spawn-on-complete copy key, the "nothing to update" 400 message, and all
  comments are renamed to `canStartOnUTC` / `normalize_can_start_on_utc`.
- **`index.html` (board UI):** the Pending "hidden until it arrives" filter now
  reads `t.canStartOnUTC`; comments updated.
- **`admin.html` (admin UI):** the form control `id`/`name` -> `canStartOnUTC`, the
  JS variables `canStartOnUTCEl` / `canStartOnUTCHint`, the visible label
  **"Available from" -> "Can start on"**, and all comments updated.
- **`css/style.css`:** the schedule-control comment updated to match.
- **No migration / no data touched:** verified that NO committed or live JSON
  (`boards/*.json`, `tasks.json`) carried `startUntil`, so this is a clean
  code-only rename. Any payload that still sends `startUntil` is now ignored by the
  server as an unknown key, so an old client degrades to "no gate" — no crash, no
  500. `php -l` clean; zero residual `startUntil` / `normalize_start_until` /
  "Available from" references remain in the dev code.
- **Scope:** dev only (port 8085). Staging (8086) and GitHub were NOT touched; ship
  together when Michael says so.

## v1.2 — 2026-11-19 (RELEASED)
One new feature (repeating / scheduled tasks) + two small bug fixes + a few
cosmetic fixes, shipped **together** to staging (8086, code-only) and GitHub
(main @ 51136d2, tag v1.2). Data was NOT overwritten — the repeat fields are
additive so the kids' live children.json stayed byte-identical.

### Feature — Repeating / scheduled tasks ("set it and forget it")
A task can now recur on its own: create it once (e.g. "Empty dishwasher" every day),
and completing it spawns the next occurrence automatically. It can also be a one-time
future task ("buy milk tomorrow") that stays hidden until its start time arrives.
- **Data (additive, no migration):** two optional per-task fields —
  - `repeat`: `{ every: int>=1, unit: "hours"|"days"|"weeks" }` (absent = one-time).
  - `startUntil`: an ISO-8601 timestamp (absent/past = show now). The task sits in
    Pending but is **hidden on the board until `now >= startUntil`**.
  - Both are absent by default, so legacy tasks are unchanged and the kids' board
    files stay byte-identical.
- **Spawn on complete (server-side, authoritative):** in `api.php`'s PATCH handler,
  when a task with a `repeat` spec transitions into Completed, a fresh **independent
  Pending copy** is appended (same title/description/duration/repeat) with
  `startUntil = completed_at + every*unit`. It is written in the **same atomic save**
  as the parent, so both persist together. The spawned copy is **UNASSIGNED** by design
  (each occurrence is a fresh claim for the kids). The response includes a `spawned`
  field so the UI can acknowledge it. One-time tasks (no `repeat`) spawn nothing.
- **Rescheduling:** a Pending task's `startUntil` / `repeat` can be changed via PATCH;
  that is **locked once the task has started** (same Pending-only rule as content
  edits — a non-Pending reschedule returns 403).
- **UI (`admin.html`):** one quiet **"Schedule"** control, **collapsed by default**
  ("None" = create now, no repeat). Tapping reveals two rows: **"Available from"**
  (`datetime-local`, defaults to now) and a **Repeat** toggle (`Every [n] [unit]`, off
  by default). Only sent to the server when meaningful, so a plain task gains neither
  key. Edit mode prepopulates both from the task; add mode resets to defaults.
- **UI (`index.html`):** the Pending filter skips tasks whose `startUntil` is still in
  the future; a small **"\u21bb N{h/d/w}"** chip renders on cards that carry a repeat
  spec so a recurring chore reads as recurring at a glance.
- **Timezone story:** the browser converts the local `datetime-local` value to a UTC
  ISO string (`new Date(...).toISOString()`) before sending; the server stores everything
  in UTC `c` format (matching `created`/`completed`/`started`) and computes the next
  occurrence from `completed_at + delta`. Consistent, no offset drift.
- **Verified on dev 8085** (24/24 automated checks on a throwaway `scratch` board, then
  removed): plain task gains no keys; future `startUntil` hidden / past shown; a
  completed repeating task spawns an **unassigned** copy at `completed_at + 1h` that
  persists in the store; a one-time completion spawns nothing; rescheduling a
  non-Pending task -> 403. Kids' boards left byte-identical.

### Bug fix — Completed tasks: owner is locked
A task's **owner (assignee) can no longer be changed once the task is Completed**.
- Backend (`api.php`, authoritative): a PATCH that sets `assignee` is refused with
  HTTP 403 (`"owner locked — a completed task's owner cannot be changed"`) when the
  task's *original* status is `Completed`. The check reads the pre-mutation status,
  so a combined "move out of Completed + reassign in the same request" cannot bypass
  it. A refused change is rejected before the board is saved, so it leaves no trace.
- Board UI (`index.html`): a Completed task shows its owner as a **🔒 locked** tag;
  tapping it does nothing (the reassign picker won't open). Tasks that are
  Pending / InProgress / Halted can still be reassigned freely, just like a card move.
- Admin UI (`admin.html`): already safe — its edit form only opens for Pending tasks,
  so the "Assigned to" dropdown never applies to a completed task.
- Style: new `.assignee.locked` (frozen look).
- Verified on dev: reassign / unassign / combined-move on a completed task → 403;
  reassign on a Pending task → 200.

### Bug fix — Completed tasks cannot be deleted
A task that is **Completed is permanent and cannot be deleted**.
- Backend (`api.php`, authoritative): a DELETE is refused with HTTP 403
  (`"cannot delete — a completed task is locked and permanent"`) when the task's
  status is `Completed`, checked before any mutation.
- Board UI (`index.html`): a Completed task's delete ✕ renders as a **🔒 lock** and
  the click is refused; `deleteTask()` also guards it client-side.
- Admin UI (`admin.html`): the row delete renders as a **🔒 lock** for Completed
  tasks; the delete handler is only wired for non-locked rows.
- Style: the delete ✕ and the owner tag on a completed card both render the **same
  🔒 lock**. The two `.locked` styles are unified into one rule (`not-allowed`
  cursor, `--muted` color, `opacity .55`, `14px`, `2px 5px`) so the edit and delete
  locks look identical — new `.del.locked` / `.mini-del.locked`.
- Verified on dev: delete a completed task (URL + body forms) → 403; delete a Pending
  task → 200; kids' board data untouched.

## v1.1 — 2026-11-18 (RELEASED)
### Feature — Team Points milestone badges
Milestone badges for **25 / 50 / 75 / 100%** of the team points goal, rendered in
the free space between the points-left and points-right figures.
- New `.points-middle` element holds the badges in the middle column; a 3-column grid
  (`1fr 1fr 1fr`) with the progress bar spanning full width below
  (`grid-column: 1 / -1`). Mobile (<760px) stacks cleanly.
- 25% lights at 30.8% of the way (so it doesn't hug the "0" figure); 50 / 75 / 100
  stay dim until reached. **100% = a golden 👑 crown.**
- Badge lighting is wired into `renderPoints()`; the whole banner is hidden when the
  goal is 0.
- Fix during this pass: badges initially rendered on the *right* instead of the middle
  (the card was a 4-col grid `1.35fr 1fr auto 1fr` with `points-middle` pinned to the
  auto slot). Corrected to 3 equal columns with `points-middle` auto-flowing to col 2.

### Feature — Static / read-only fallback (carried from v1.0.1)
When `api.php` can't run (GitHub Pages, a static host, a downloaded copy), the UI
loads the committed JSON directly (`boards.json` + `boards/*.json`) and shows a
"read-only preview" banner instead of an empty board. On a real PHP host this stays
dormant and the live API is used exactly as before.

## v1.0.1 — 2026-11-18 (released)
- Static / read-only fallback introduced so the GitHub Pages showcase renders boards
  instead of a blank board.

## v1.0 — 2026-11-18 (released)
- Initial public release: multi-board Kanban (Pending / InProgress / Halted /
  Completed), drag-and-drop between columns, per-board effort estimates (days or
  minutes), team-points goal with a live progress bar, a Completed-items log with
  on/off/over-estimate coloring, worker assignment, an admin editor, and a static
  registry of boards.

---
### Process notes
- **Staging publish reverted (2026-11-18):** a staging publish of the owner-lock was
  done by mistake and fully reverted from a backup. Staging was restored to the v1.1
  release. Going forward: changes stay in dev until confirmed, then **all** changes
  ship together to staging + GitHub when Michael says so — never auto-published.
- **Versioning:** the two bug fixes above are treated as small fixes, not a full
  `.x` bump. Their version (v1.2 or folded into a future release) is Michael's call.
- **Staging data migration (v1.25 publish):** staging's live `boards/children.json`
  carried 11 `startUntil` tasks (e.g. "Empty Dishwasher", "iReady Reading"). Because
  the server now ignores `startUntil`, those gates would have silently died, so the
  key was renamed in-place in staging's `children.json` (11/11 keys, JSON-validated,
  backup kept at `QuickTasks.backup-canstartonutc-*/`). Dev data and the committed
  git data never carried the key, so no migration was needed there — only staging's
  point-in-time snapshot.

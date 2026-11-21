# QuickTasks — Revision Log

Changelog for the QuickTasks Kanban board. Keep this in sync with `VERSION` and the
GitHub README "Revisions" section.

## v1.33 — 2026-11-21 (RELEASED on GitHub, tag v1.33)
One-line bug fix to the v1.32 bonus feature.

### Fix — bonus badge row no longer wraps
- At narrow user-agent widths the `.points-miles-row` (25% / 50% / 75% / 100% +
  the "Bonus - .NN" badge) wrapped onto a second line, which changed the banner's
  height/width. **Forced `flex-wrap: nowrap`** on `.points-miles-row` so the
  badges + bonus badge always stay on one line.
- Tightened the badge sizing so the row fits without overflowing at narrow widths:
  `.mile` padding `8px → 6px` (height `20px → 19px`), `.points-miles-row` gap
  `6px → 5px`, `.points-bonus-badge` padding `10px → 8px` / font `13px → 12px` /
  margin-left `4px → 2px`, plus `.points-miles-row .mile, .points-bonus-badge {
  flex: 0 0 auto; }` so nothing shrinks or wraps.
- Re-screenshotted both states (single-line) in `docs/screenshots/`.
- `css/style.css` only — no data change; fresh-load guard still PASSes.

## v1.32 — 2026-11-21 (RELEASED on GitHub, tag v1.32)
New feature: **post-goal bonus prizes** on the Team Points banner. Code-only;
the prod boards data is untouched (prod-only, not carried in the repo).

### Feature — post-goal "bonus prizes"
- After the team clears the **100% weekly point goal**, each whole point of
  **overage** (points past the goal) reveals the next prize in order. A gold,
  gently-pulsing **"Bonus - .NN"** badge shows the live overage as the headline
  reward number (e.g. `Bonus - 1.15`).
- Prizes render as **compact chips under the milestone badges and above the
  progress meter**, inside `.points-middle` (not a new row) — so the
  team-points section **never grows in height**.
- **Progressive thresholds** (per prize, configured): #1 = goal +10%, #2 = goal
  +20%, #3 = goal +30% (each a `{text, threshold}` object). The threshold is the
  total team-points value at which that prize lights. (A random 0..1 extra can
  be layered per week; this release ships fixed +10/20/30% for testability and
  reproducible data.)
- **Locked (not-yet-earned)** prizes show a small dimmed slot: gift icon + a lock
  glyph. The prize **name is hidden** (`display:none`) so the chip stays compact —
  it reads just `[gift] [lock] [N.NN to go]`. Only the **next pending** prize
  shows its "N.NN to go" count (right of the lock); the others show just the lock.
  When earned, the chip **pops gold** and the name reveals.
- **Config:** per-board `config.bonusPrizes` — an array of strings or
  `{text, threshold}` objects (or a single value). No prizes configured → the
  whole section is hidden. Children sample:
  `[{text:"wday - Nitro",threshold:11.0},{text:"wday - Flanigans",threshold:12.0},{text:"wday - JapanInn",threshold:13.0}]`.
- Reveal is **per-prize threshold** based (prize *i* lights when
  `points >= threshold[i]`), so the progressive +10/20/30% bars are honored
  exactly. The whole section auto-hides when a board has no goal or no prizes, and
  resets with the week's points.
- **Screenshots** in `docs/screenshots/`: `TeamPoints_stateA_allDimmed.png`
  (goal met, all locked) and `TeamPoints_stateB_oneLit.png` (first prize earned).

### Files
- `index.html` — bonus HTML into `.points-middle` (badges row → bonus button →
  prize row, above the meter); chip build + per-threshold reveal loop; "to go" on
  the next-pending prize only.
- `css/style.css` — `.points-middle` column stack; `.points-miles-row`;
  `.points-bonus-badge` (gold pulse); `.bonus-chip` (compact pill, locked = hidden
  name + lock, lit = gold pop); `.bonus-need` (next-pending count).
- `boards/children.json` — the `config.bonusPrizes` sample (prod data otherwise
  untouched).
- `docs/screenshots/` — the two Team Points state images.
- No `api.php` / `admin.html` change; the fresh-load guard still **PASS**es.

## v1.29 — 2026-11-19 (RELEASED on dev + staging + GitHub, tag v1.29)
Two features plus one bug fix, all in the InProgress/Completed area of the board.

### Feature — live InProgress timer (stopwatch) on the card
- Every task sitting in **InProgress** shows a small elapsed-time pill in its meta
  row, formatted `hh:mm:ss.d` (hours, minutes, seconds, decisecond). Leading
  zero-groups collapse: 27.5s reads `27.5`, 27m15.1s reads `27:15.1`, and it rolls
  up to `1:05:02.3` past an hour. Pending/Halted/Completed cards show nothing.
- **Origin** is the task's `started` stamp (set on the Pending → InProgress
  transition in `api.php`), falling back to `created`, then render time, so the
  pill is always present on an InProgress card.
- **Refresh:** one global 100ms ticker recomputes every InProgress pill in place
  (100ms = one decisecond, so the last digit visibly ticks). Because it updates the
  pill DOM rather than rebuilding the board, it survives the 15s poll re-render and
  any drag/move, and recomputing from the absolute start each tick means it can't
  drift even if a frame slips. Guarded on `!STATIC` so the read-only GitHub Pages
  showcase never runs a stopwatch off a stale `started`.
- **Aesthetics:** the pill matches the card's chip system (rounded 999px, same
  meta-row footprint) but is tinted to the InProgress accent with a soft 1s
  "live" pulse on a dot and `font-variant-numeric: tabular-nums` (plus a
  `min-width` reserve) so the ticking digits don't jiggle the layout. Respects
  `prefers-reduced-motion`.

### Bug fix — the move-flash
- **Symptom:** dragging a task into InProgress briefly flashed a huge elapsed value
  (e.g. `38:22:30.6` for a task moved ~11s earlier), then corrected a few seconds
  later. **Cause:** `moveTask` did an optimistic `t.status = "InProgress"` re-render
  *before* the PATCH returned; at that instant the task had no `started` yet (the
  server stamps it), so the pill fell back to the task's `created` time
  (hours/days old) and showed that. **Fix:** the optimistic move now mirrors the
  server's lifecycle stamping — entering InProgress stamps `started` ≈ now
  immediately (moving back to Pending clears it, so re-entering restarts fresh),
  and after the PATCH the card is reconciled from the response (`data.task`, plus
  any `data.spawned`); a failed PATCH rolls back status/assignee/started/completed.
  The pill now reads ~0 the instant a task moves in and ticks up from there. Verified
  headlessly by delaying the PATCH ~1.2s: the value read `0.0` (old bug: `38:22:30.6`).

### Log — Completed-items grid "Added" column → "Done by" owner bubble
- In the completed-items log below the board, the **Added** column (task `created`
  date) was removed and replaced with a **Done by** column showing the task's owner,
  rendered as the **same rounded bubble as the card's owner tag** — a new
  non-interactive `.log-owner` class that mirrors `.card .assignee` (accent-tinted
  pill, person glyph, uppercase, rounded 999px) but has no click/lock, since the log
  is a read-only record. An owned task shows `☗ Chris` (tooltip "Done by Chris"); an
  unowned task shows a quiet dash (`.log-owner.unassigned`, tooltip "No owner"). The
  Started / Completed / Days-worked columns are unchanged, so the log's date info is
  preserved. Verified on the live children board (a temporary owned + unowned
  completed task, then removed): the owned one rendered the owner bubble and the
  unowned rendered a dash, matching the card tag.
- **Files:** `index.html` (timer pill + `fmtTimer` + global ticker in `bootstrap`;
  log row + "Done by" header), `css/style.css` (`.timer` pill + `timer-pulse`;
  `.log-owner`).
- **`api.php` hardening (folded in):** the atomic write now `chmod 0600` after both
  `save_board()` and `prune_archived()` rename, keeping board data owner-only — the
  atomic temp file otherwise inherits umask 0644. Server-agnostic (works under Apache
  and `php -S`). A security improvement, included in this ship.

## v1.28 — 2026-11-19 (RELEASED on dev + staging + GitHub, tag v1.28)
A cleanup pass: the Completed-column window was already a per-board config variable
(`config.completedWindowDays`) that the board read dynamically; this removed the
two magic `|| 2` fallbacks so there's a single named default and the value is
unambiguously per-board.
- **`index.html`:** the column filter and the column header both read
  `config.completedWindowDays ?? DEFAULT_CONFIG.completedWindowDays` (one source of
  truth — the `DEFAULT_CONFIG` value, 2). Switching `||` → `??` also makes an
  explicit `0` valid ("hide everything from the column, keep it in the log only")
  instead of silently coercing back to 2.
- **Per-board, proven on dev:** setting the window independently on two boards
  (adults=3 / children=1) rendered different column headers at the same time; the
  column also tracks 1/2/3 live. No new in-app control yet (a possible follow-up
  if a settings knob is wanted).

## v1.27 — 2026-11-19 (RELEASED on dev + staging + GitHub, tag v1.27)
A small UX change: the **Completed column now shows the most-recently-completed task
on top**, so the latest finished chore is the first thing you see and the rest fall
behind it. No data change, no migration — Completed column only.
- **`index.html` (render):** when building the Completed column, the filtered in-
  status list is sorted **descending by `completed`**, with `created` as the
  tiebreaker, so a task just completed jumps to the top and even chores finished in
  the same instant still land newest-first. Everything else on the board is
  unchanged.
- **Verified on the dev server:** the Completed column renders newest-first (top = most
  recent completed, bottom = oldest); inline JS parses clean.

## v1.26 — 2026-11-19 (RELEASED on dev + staging + GitHub, tag v1.26)
A new feature plus two fixes, shipped together to staging + GitHub:
**clock-anchored repeating tasks** stop the schedule from drifting later day over day, the card's **double repeat icon** is
fixed, and a **write guard** keeps an unregistered board key from polluting the
default board.

### Feature — clock-anchored repeating tasks (the drift stop)
- **Proposed by Alexander O.** — anchoring a repeat to a *fixed time* (rather than
  relative to when it was last finished) was his idea and instrumental in the final
  design. Credit where it's due.
- **Why:** the old spawn rule set the next occurrence to `completed_at + interval`.
  Because the gate was derived from *when the task was finished* rather than a fixed
  clock, every cycle the task appeared after its gate and was finished after its
  appearance, so the next gate inherited that lateness and the schedule ratcheted
  later each day. A daily chore finished at 08:00 the first day started appearing at
  08:15, 08:30, 09:00... over successive days — "daily" in name only.
- **Fix — a clock anchor `repeat.at`** (optional, additive, UTC ISO-8601). When
  present, the spawn is the **first grid line `at + k*interval ≥ now`**
  (`k = floor((now − at) / interval)`), so the sequence is a fixed clock and a late
  completion **cannot** push it. Future `at` = the first appearance itself (`k = 0`);
  a past `at` snaps to the next line at or after now. Examples: daily `at = 07:00` →
  always 07:00; hourly → the next hour on the hour; weekly → the next 07:00 on a
  7-day grid.
- **Backward compatible:** with no `at`, the legacy `completed_at + interval` behavior
  is preserved exactly — existing repeating tasks are **untouched, no migration**
  (the schema-migrates / data-doesn't policy in practice: new field, additive, absent
  by default).
- **UI (`admin.html`):** when **Repeat** is on, the existing **"Can start on"** field
  doubles as the anchor (sent as `repeat.at`), **defaulting to now** so a new
  recurring task appears immediately and then locks onto its clock. No new field
  added — the "start it earlier" knob *is* the anchor.
- **UI (`index.html`):** the repeat chip shows the anchor, e.g. **`↻ 07:00 d`**.
- **Fix — double repeat icon:** the chip template already prints the `↻` glyph and the
  anchored branch had prepended a second one; removed the duplicate so a card shows
  **one** icon.
- **Safety — reject unknown board keys on writes (`api.php`):** a write
  (POST/PATCH/PUT/DELETE) naming a board key not in the registry now returns `400`
  instead of silently falling back to the **default** board, so a typo or a throwaway
  test key can no longer pollute a live board. `GET` keeps the default fallback
  (harmless read); writes with **no** `?board=` still target the default (always
  registered). Added after a dev test wrote throwaway tasks to the default board via
  an unregistered key.
- **Verified on the dev server** (throwaway registered board, then removed): daily past-
  anchor → next 07:00; daily future-anchor → that anchor; hourly → next hour; weekly
  → next 07:00 on a 7-day grid; legacy no-`at` → +1 day; one-time future → no spawn;
  drift-proof trio — finishing at 08:00 / 11:30 / 23:00 all spawned 07:00. The write
  guard 400s on an unknown key while leaving the default board unchanged. `php -l`
  clean.

## v1.25 — 2026-11-19 (RELEASED on dev + staging + GitHub, tag v1.25)
A naming-only refactor: the task "can start on" gate field is renamed **`startUntil`
-> `canStartOnUTC`** so the direction of the constraint reads clearly — a task
cannot start before this UTC datetime. Behavior is unchanged; only the name
changed. **Not yet shipped** — staging and GitHub are untouched.
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
- **Scope:** dev + staging + GitHub (shipped together the same day). The kids' live
  children.json data was NOT overwritten (the schema change is additive, no migration).

## v1.2 — 2026-11-19 (RELEASED)
One new feature (repeating / scheduled tasks) + two small bug fixes + a few
cosmetic fixes, shipped **together** to staging (code-only) and GitHub
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
- **Verified on the dev server** (24/24 automated checks on a throwaway `scratch` board, then
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
  ship together to staging + GitHub only on explicit approval — never auto-published.
- **Versioning:** the two bug fixes above are treated as small fixes, not a full
  `.x` bump. Their version (v1.2 or folded into a future release) is a release
  decision to make when shipping.
- **Staging data migration (v1.25 publish):** staging's live `boards/children.json`
  carried 11 `startUntil` tasks (e.g. "Empty Dishwasher", "iReady Reading"). Because
  the server now ignores `startUntil`, those gates would have silently died, so the
  key was renamed in-place in staging's `children.json` (11/11 keys, JSON-validated,
  backup kept at `QuickTasks.backup-canstartonutc-*/`). Dev data and the committed
  git data never carried the key, so no migration was needed there — only staging's
  point-in-time snapshot.

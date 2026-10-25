# Plan v5: simple version for one user

Put this file in the repo as `docs/PLAN_V5.md`. Read AGENTS.md first: its rules always apply.

## Rules for every phase

- Never commit. At the end of each phase: full PHP suite (`php artisan test`), `npm run test`,
  `npm run build`, `git add -A`, `git status`, then a short summary and STOP.
- Back up the dev DB with mysqldump to `storage/app/private/backups/` before any migration.
- Never run migrate:fresh, migrate:rollback, db:wipe, db:seed or deletes on the dev DB without asking.
- The Excel oracle (2,204 figures), the 151-row import proof and the chart oracle must pass in every phase.
- The client's Excel files stay outside the repo (`CLIENT_WORKBOOKS_DIR`). Never put their data in code,
  seeders, tests or committed files. Tests use small hand-made data with dates worked out by hand.
- This app has ONE real user. No roles, sharing or invites.

## Phase 1: login, owner-only data, profile (DONE)

- `/register` logs the user straight in; `/signup` redirects (301) to `/register`. `REGISTRATION_OPEN` in .env
  (false = both 404, no sign-up text on the login page).
- Roles and approval are removed: the `role` and `approved_at` columns are dropped.
- `projects.owner_id`; every policy checks it; other users get 404. OwnershipTest loops over the route list.
- My profile: name, email, phone; change password (current password required, other sessions logged out).
- Commands: `user:create`, `project:transfer {id} {email}`.
- The migration needs `PROJECT_OWNER_EMAIL=... php artisan migrate` when projects exist.

## Phase 2: activity dialog and insert between

### Dialog fields (Add and Edit), in this order

1. Activity name *
2. Floor: hidden until phase 3.
3. Comes after: dropdown; default is the row above; "Building start" for the first row.
4. Planned date *, next to "N days after <Comes after>". Typing either one updates the other.
   - The real offset is stored.
   - The request accepts `planned_date` or `offset_days`. The server works out
     offset = planned_date − own adjustment − (start + day of the "Comes after" row).
   - A negative offset is refused with a plain message.
   - The same converter runs in JS for the live update, and has a vitest test.
   - When the "Comes after" row has an adjustment, show under the field:
     "<name>'s date includes +17 days adjustment, which doesn't carry over".
5. Actual date: optional.
   - Same rules as RecordActualRequest: not in the future, not before the building's actual start,
     never on a break.
   - Keep the quick "Actual" button on each row.
6. Break (Diwali, Holi, festival): checkbox. A break has no actual date and no floor.
7. Note: collapsed behind "Add a note".

Remove "Date adjustment" and "Reason" from the dialog, but keep the columns. When an adjustment exists,
show it read-only as "Includes +17 days adjustment" with a "Clear" link (sets it to 0 and the reason to null).

### Preview

SchedulePreview returns the changed activities. The dialog shows "This changes N later activities - finish
moves from X to Y" with the first 5 changed activities, or "No other dates change".

### Insert between

- Each row's menu gets "Insert activity below". On desktop, a "+" also shows between rows on hover.
- The store and preview requests take `after_id` and `push` (default true).
  `ScheduleEditor::insert` does all of this in one transaction:
  - moves the rows below down one place (sort_order + 1)
  - with push: relinks ONLY the next row in list order, and only if it comes after the row above.
    Otherwise the dialog says "Nothing to push".
  - recalculates
- Actual dates are never changed.

### Tests

- insert with push: the dates and the finish move by N, actuals are unchanged, % is recalculated
- insert without push
- typing a planned date sets the offset, and the other way round, including the adjustment case
- the actual date rules
- a break can't have an actual date
- the oracle and the 151-row proof still pass

## Phase 3: floors and 3-level progress

### Schema

- `floors` table: building_id, name, level INT (for sorting; basements are negative), soft delete.
  Names are unique per building, using the same active_name trick as buildings.
- `activities.floor_id`: nullable FK. NULL means "Whole building / common work".
- Copying a building also copies its floors.

### ProgressService: one service for all three levels, in work-days

- Building %: unchanged (BuildingMetricsService; Excel's max work-day reached ÷ total). Must still match
  the oracle.
- Activity work-days = offset_days (its own days after its "Comes after" row). Breaks count 0.
- Floor:
  - % = work-days of the finished activities on the floor ÷ total work-days on that floor
  - "3 of 5 done"
  - planned finish (the last planned date on the floor)
  - actual finish, or the forecast finish (planned − building lead)
  - status: Done / In progress / Not started / Behind. Behind = not done, and at least one activity on
    the floor is past its planned date with no actual date.
  - A floor with only one activity shows "Done" or "Not started" with no % bar.
- Project %: sum of work-days done ÷ sum of total work-days across buildings (as now).
- ProjectSummaryService uses ProgressService.

### UI

- Building page: a Floors section above the activity list.
  - One row per floor: name, % bar, done/total, planned finish, forecast or actual finish, status.
  - "Whole building" is the last row.
  - Clicking a row filters the list. On phones, the rows become cards.
- Filter chips: All | Ground | 1st | ... | Whole building.
- "Add floors" dialog, with a "Ground + 1 to N" quick option.
- Activity dialog: a Floor dropdown. Row menu: "Move to floor".
- Deleting a floor asks first, then moves its activities to Whole building.
- Project page: an overall % bar, then one card per building (%, lead, forecast finish).
- A building with no floors looks exactly as it does today.

### Tests

A hand-made building with 3 floors plus common work, with every number worked out by hand, and the floor
delete.

## Phase 4: Excel → JSON → DB (same approach as ~/lead-crm1, which is read only)

### Step 1: `php artisan timeline:excel-to-json {--dir=} {--out=}`

- Reads the workbooks from `CLIENT_WORKBOOKS_DIR` (default `~/client-files/building-timeline/references`),
  reusing ScheduleWorkbook.
- Move the Chart-sheet reader from tests/Support/WorkbookOracle into app/Import, so the app can fill
  `expected`. The test oracle uses it too.
- Writes `storage/app/private/imports/<date>/project.json`. This folder is git-ignored; prove it with
  `git check-ignore`.
- JSON shape:
  `{ project {name, timezone}, buildings [ {name, type, planned_start, actual_start, floors [ {name, level} ],
  activities [ {row, position, name, is_break, floor, comes_after_row, offset_days, adjustment, planned_date,
  actual_date, note} ] } ], expected { per building: total work-days, %, lead, planned finish, forecasts } }`
- Floor guesses, printed as a table:
  - "GF SLAB" / "DAY 1 TO GF SLAB" → Ground floor
  - "Nth SLAB" → Nth floor
  - everything else → Whole building

### Step 2: `php artisan timeline:import-json {file} --owner=<email> {--dry-run} {--as=}`

- Validates with PlanDocumentValidator: Validator rules, plus a cycle check through
  ScheduleRecalculator::compute.
- Recalculates every planned date and compares it with the JSON value. Then compares %, lead and
  finish with `expected`. Any mismatch stops it with a report.
- `--dry-run` does the real inserts in one transaction, then rolls back.
- Refuses a project name the owner already has, and suggests `--as`.
- Logs each import in a `plan_imports` table (owner, file, sha256, project, counts, time). The same
  file can't be imported twice.
- The UI upload goes through the same validator, so it can carry floors too.
- Tests use a small hand-made fixture in tests/Fixtures.

## Docs (last)

- README: the import commands, `CLIENT_WORKBOOKS_DIR`, `REGISTRATION_OPEN`, `PROJECT_OWNER_EMAIL`.
- A new Help page: the activity dialog, insert between, floors, the profile, and one line on why floor %
  doesn't add up exactly to building % (breaks and common work have no floor).
- CLAUDE.md, AGENTS.md and docs/system-design.md: one user, owner only, no roles, the import flow.
- Phone check in Chrome at 360, 768 and 1280 px: the dialog, the Floors section, the profile and the
  header menu.

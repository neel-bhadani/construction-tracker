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

## Phase 2: activity dialog and insert between (DONE)

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

## Phase 3: floors and 3-level progress (DONE; removed in v5.1, see below)

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

> Floors were removed again in v5.1 (phase A): no table, column, route or screen is left. This phase is kept as
> the record of what was built and why it went.

## Phase 4: Excel → JSON → DB (same approach as ~/lead-crm1, which is read only) (DONE)

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

## Docs (last) (DONE)

- README: the import commands, `CLIENT_WORKBOOKS_DIR`, `REGISTRATION_OPEN`, `PROJECT_OWNER_EMAIL`.
- A new Help page: the activity dialog, insert between, floors, the profile, and one line on why floor %
  doesn't add up exactly to building % (breaks and common work have no floor).
- CLAUDE.md, AGENTS.md and docs/system-design.md: one user, owner only, no roles, the import flow.
- Phone check in Chrome at 360, 768 and 1280 px: the dialog, the Floors section, the profile and the
  header menu.

---

# v5.1: client requirements (October 2026)

Done in four phases, each stopped for review and committed by the client's developer: A = remove floors and the
import, B = Work ID and Common activities, C = sub-activities, D = All activities view and docs.

## Decisions taken

1. The first sub-activity added to an activity that is already done starts at 100%, done on the activity's
   actual date. The dialog says: "This activity is done, so the new sub-activity starts at 100%. Change it to
   reopen the activity."
2. Deleting the last sub-activity leaves the activity's state and date as they are; it is marked done by hand
   again.
3. A done date follows the actual date's rules: not in the future, not before the building's actual start.
4. Copying a plan copies sub-activity names and planned dates, at 0%, with no done dates and new Work IDs.
5. Sub-activity numbers follow creation and are never renumbered; the list shows them in position order.
6. `.claude/.headroom_*` is untracked and ignored.
7. Deploy: migrate before importing (README).

## Phase A: remove floors (DONE)

- Migration `…000014_remove_floors`: drops `activities.floor_id` and `floors`; `down()` recreates them empty.
- Gone: floor routes, controller, requests, policy, `FloorEditor`, `Floor`, `FloorProgress`, `FloorStatus`, the
  floor part of `ProgressService`, the floors section, chips, tags, "Move to floor", "+ Add floors", the dialog's
  Floor field, the Help section. `FloorsRemovedTest` checks no table, column, route or page text is left.
- Import: no floor guesses or `floors` keys. `database/data/shaligram-vrundavan.json` regenerated with
  `--force`; the dry run gives 151 of 151 dates and 27 of 27 figures.

## Phase B: Work ID and Common activities (DONE)

- Migration `…000015_work_ids_and_common_activities`: `projects.code` (+ `active_code`, unique per owner),
  `projects.next_activity_number`, `activities.work_number` / `work_id`, backfilled common activities first,
  then live buildings in order, then deleted ones. Each `common_work` building renamed "Common activities"
  (same id); a live project without one gets an empty one; `buildings.live_common` keeps one per project.
  Rehearsed on a copy of the dev data in the test database, up, down and up, before the dev run.
- `WorkIds` gives `{code}-{nnn}` inside the writing transaction with the project row locked
  (`WorkIdConcurrencyTest`). Shown in tables, cards, dialog titles and toasts.
- Common activities: created with each project, shown as a project-page tab (`/projects/{id}/common`), not a
  building tab or card; cannot be renamed, moved or deleted on its own. The import fills it from the file's
  common timeline, written first.

## Phase C: sub-activities (DONE)

- Migration `…000016_create_sub_activities_table`: `sub_activities`, `sub_activity_revisions`,
  `activities.next_sub_number`.
- `SubActivityEditor`: create, update, soft delete, reorder; after each change the activity is done (actual
  date = latest done date) when all are at 100%, not done otherwise. Breaks have none; an activity with
  sub-activities has no hand-set actual date and cannot become a break.
- UI: a % bar on every row; a chevron opens the list (Work ID, name, planned date, 0–100 quick buttons, done
  date, note, ⋯ Edit / Move up / Move down / Delete), drag to reorder, "+ Add sub-activity".
- Building and project % unchanged: the oracles stay green.

## Phase D: All activities and docs (DONE)

- `/projects/{id}/activities`: overall %, lead and forecast finish; one collapsible group per timeline (Common
  activities first) with its own % and lead; rows with Work ID, name, bar, planned, actual, status, forecast,
  opening to their sub-activities; search by name or Work ID; filters by group and status; the same Edit
  dialog; cards on phones.
- `AllActivitiesPage` reads activities and sub-activities in two queries for the project
  (`AllActivitiesTest::test_the_queries_do_not_grow_with_the_rows`). Measured with 151 activities and 500
  sub-activities on the dev machine: full page about 0.45 s, the Inertia request about 0.25 s.
- Help page: the project page, Work ID, sub-activities, Common activities, All activities. README,
  CLAUDE.md = AGENTS.md, this file, `system-design.md` section 11 and `database-structure.md` updated.

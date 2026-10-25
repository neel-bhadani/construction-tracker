# Plan v5: as simple as the client's Excel

One real user (the client). Four phases, each finished with the full PHP suite, `npm run test`,
`npm run build`, `git add -A` and `git status`, then a stop for review; nothing is committed by the
agent. The Excel oracle, the 151-row import proof and the chart oracle pass at every phase. The
client's workbooks stay in `CLIENT_WORKBOOKS_DIR` and never enter the repository.

## Status

| Phase | What | Status |
| --- | --- | --- |
| 1 | Simple login, own data, profile | Done (committed) |
| 2 | Activity dialog: planned + actual date, insert between | Done (committed) |
| 3a | Floors and 3-level progress: backend | Done, awaiting review |
| 3b | Floors and 3-level progress: UI | Next, after the go-ahead |
| 4 | Import: Excel → JSON → DB | To do |
| Docs | README, Help page, CLAUDE.md, phone check | To do |

## Decisions

1. Existing projects belong to `neel5@59gmail.com`. The other accounts stay as they are.
   `php artisan project:transfer {project id} {email}` hands a project over, in one transaction, and
   refuses if the new owner already has a project of that name.
2. `/register`. `/signup` redirects (301) there while registration is open; both are 404 when
   `REGISTRATION_OPEN=false`.
3. `users.role` and `users.approved_at` are dropped.
4. The offset is stored and shown as it really is. When "Comes after" has an adjustment, the dialog
   says "X's date includes +17 days adjustment, which doesn't carry over." The Help page uses the
   same wording. The person still types the planned date; the offset follows.
5. Insert with push relinks only the next row in list order, and only if it comes after the row
   above. Otherwise the dialog says "Nothing to push".
6. A floor is Behind when it is not done and at least one activity on it is past its planned date
   with no actual date.
7. "Whole building" is the last row of the Floors section when a building has floors.
8. Imports are logged in a `plan_imports` table.
9. Laravel Boost is not installed.
10. In the Floors section, a floor with only one activity shows "Done" / "Not started", not a
    0% / 100% bar.
11. `OwnershipTest` walks the route list and fails when a signed-in route has no ownership case.

## Phase 3: floors

### 3a, backend

- `floors`: `building_id`, `name`, `level` (sort order; ground 0, basements negative), soft delete,
  name unique among a building's live floors. `activities.floor_id`, nullable: null is "Whole
  building / common".
- `FloorPolicy` (owner only, 404 otherwise); routes in `OwnershipTest`.
- `FloorEditor`: add one, add "Ground + 1 to N" (skips names already there), rename, delete. Deleting
  moves the floor's activities to Whole building, each with a revision, then soft-deletes it.
- "Move to floor": `PATCH /activities/{id}/floor`. The activity dialog takes `floor_id` too. A break is
  on no floor.
- Copying a building copies its floors and which floor each activity is on.
- `ProgressService`, all three levels in work-days:
  - Building: unchanged, the workbook's C12.
  - Activity work-days: its work-day minus that of the activity it comes after (at least 0); breaks 0.
  - Floor: work-days done / work-days, "n of m done", planned finish, actual finish once all done,
    else the Lead Tracker's forecast, and the status.
  - Project: work-days done / total work-days across buildings. `ProjectSummaryService` uses it.
- Page prop `floors` on the building page, and `GET …/buildings/{id}/floors` for the same rows.
- `FloorProgressTest`: a hand-made building with three floors and common work, every number worked
  out by hand in the test's header.

### 3b, UI

- Building page: "Add floors" dialog with the quick option; a Floors section above the activity list
  (name, % bar, done/total, planned finish, forecast/actual finish, status), clicking a row filters
  the list; filter chips All | Ground | 1st | … | Whole building; cards on phones.
- Floor dropdown in the activity dialog; "Move to floor" in the row menu; delete a floor after a
  confirm.
- Project page: an overall % bar, then one card per building (%, lead, forecast finish).
- Help page: one line on why floor % need not add up to building %.
- A building without floors looks exactly as before.

## Phase 4: import

1. `php artisan timeline:excel-to-json {--dir=} {--out=}` writes
   `storage/app/private/imports/<date>/project.json` (git-ignored), with floors guessed from names and
   printed as a table for correction, and `expected` figures from the workbooks' Chart sheets.
2. `php artisan timeline:import-json {file} --owner=<email> {--dry-run} {--as=}` validates, recalculates
   and compares every planned date and the expected figures, then imports in one transaction and logs
   to `plan_imports`. `--dry-run` runs the inserts and rolls them back, as in lead-crm1.
3. The upload import in the UI goes through the same validator. Tests use a small hand-made fixture.

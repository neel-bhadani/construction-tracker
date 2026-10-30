# Shaligram Timeline

A Laravel 13 + Inertia/Vue app that replaces the client's Excel construction timelines. Design:
`docs/system-design.md` (section 11 is the current model), schema: `docs/database-structure.md`, v5 plan and
decisions: `docs/PLAN_V5.md`.

## The model

- **One real user** (the client). No roles, approval, sharing or invites; don't add them.
- **Owner-only access.** `projects.owner_id`; every query and policy checks it, and another account's project,
  building, activity or sub-activity is a 404 on every page and API route. A new signed-in route needs an ownership
  case in `tests/Feature/OwnershipTest.php`, which fails otherwise.
- Planned dates are written only by `ScheduleRecalculator`. Building % is the workbook's own figure
  (`BuildingMetricsService`); project % comes from `ProgressService`, in work-days. There are no floors.
- **Work IDs** (`SV-012`, sub-activities `SV-012.3`) are given only by `WorkIds` / `SubActivityEditor` inside
  the writing transaction; never typed, reused or renumbered. Anything that inserts activities must go through
  `ScheduleEditor` or `PlanWriter`.
- **Common activities**: the project's one `common_work` building, created with the project, shown on the
  project page; never a building tab or card, never renamed, moved or deleted on its own.
- **Sub-activities** change only through `SubActivityEditor`, which sets the activity's actual date (all at
  100%: latest done date; otherwise none). They never move planned dates or building/project %.

## Import flow

1. Back up the dev DB (below).
2. `php artisan timeline:excel-to-json`: workbooks in `CLIENT_WORKBOOKS_DIR` → `storage/app/private/imports/<date>/project.json`
   (git-ignored).
3. `php artisan timeline:import-json <file> --owner=<email> --dry-run`, then without `--dry-run`; `--as=` for
   another project name. Validated by `PlanDocumentValidator`, written by `PlanImporter` in one transaction,
   logged in `plan_imports`. The UI's Excel upload uses the same validator. The file's common timeline fills
   the project's Common activities. Migrate before importing.

## Dev database safety

- The dev database is MySQL `construction_tracker`. Before any migration, back it up with
  `mysqldump` to `storage/app/private/backups/`.
- Never run `migrate:fresh`, `migrate:rollback`, `db:wipe`, `db:seed` or any delete against the dev
  database without asking first.
- Tests run only against `construction_tracker_test`; `tests/TestCase.php` refuses any other database.
- `.env.testing` names the dev database: `php artisan … --env=testing` runs against dev. To migrate the test
  database, set `DB_DATABASE=construction_tracker_test` explicitly.
- For a browser check, use a throwaway account and project created and removed by a script outside the repo;
  never a real account's password, never the client's project.

## Client data

- The client's workbooks live outside the repository, in `CLIENT_WORKBOOKS_DIR`. Never copy their data
  into code, seeders, tests or committed files. Tests use small hand-made data (`tests/Fixtures`).
- The one exception: `database/data/shaligram-vrundavan.json`, the import file for the live server (the repo is
  private). Regenerate it with `timeline:excel-to-json --out=database/data/shaligram-vrundavan.json --force`,
  never by hand; no other client data in the repo.
- The Excel oracle, the 151-row import proof and the chart oracle must keep passing.

## Working on a phase

- Finish with the full PHP suite, `npm run test` and `npm run build`, then `git add -A` and
  `git status`. Never commit.

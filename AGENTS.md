# Shaligram Timeline

A Laravel 13 + Inertia/Vue app that replaces the client's Excel construction timelines. Design:
`docs/system-design.md` (section 11 is the current model), schema: `docs/database-structure.md`, v5 plan and
decisions: `docs/PLAN_V5.md`.

## The model

- **One real user** (the client). No roles, approval, sharing or invites; don't add them.
- **Owner-only access.** `projects.owner_id`; every query and policy checks it, and another account's project,
  building, floor or activity is a 404 on every page and API route. A new signed-in route needs an ownership
  case in `tests/Feature/OwnershipTest.php`, which fails otherwise.
- Planned dates are written only by `ScheduleRecalculator`. Building % is the workbook's own figure
  (`BuildingMetricsService`); floor and project % come from `ProgressService`, in work-days.

## Import flow

1. Back up the dev DB (below).
2. `php artisan timeline:excel-to-json`: workbooks in `CLIENT_WORKBOOKS_DIR` → `storage/app/private/imports/<date>/project.json`
   (git-ignored), with floor guesses printed for checking.
3. `php artisan timeline:import-json <file> --owner=<email> --dry-run`, then without `--dry-run`; `--as=` for
   another project name. Validated by `PlanDocumentValidator`, written by `PlanImporter` in one transaction,
   logged in `plan_imports`. The UI's Excel upload uses the same validator.

## Dev database safety

- The dev database is MySQL `construction_tracker`. Before any migration, back it up with
  `mysqldump` to `storage/app/private/backups/`.
- Never run `migrate:fresh`, `migrate:rollback`, `db:wipe`, `db:seed` or any delete against the dev
  database without asking first.
- Tests run only against `construction_tracker_test`; `tests/TestCase.php` refuses any other database.
- For a browser check, use a throwaway account and project created and removed by a script outside the repo;
  never a real account's password, never the client's project.

## Client data

- The client's workbooks live outside the repository, in `CLIENT_WORKBOOKS_DIR`. Never copy their data
  into code, seeders, tests or committed files. Tests use small hand-made data (`tests/Fixtures`).
- The Excel oracle, the 151-row import proof and the chart oracle must keep passing.

## Working on a phase

- Finish with the full PHP suite, `npm run test` and `npm run build`, then `git add -A` and
  `git status`. Never commit.

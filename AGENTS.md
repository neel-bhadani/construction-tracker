# Shaligram Timeline

A Laravel 13 + Inertia/Vue app that replaces the client's Excel construction timelines.

## Dev database safety

- The dev database is MySQL `construction_tracker`. Before any migration, back it up with
  `mysqldump` to `storage/app/private/backups/`.
- Never run `migrate:fresh`, `migrate:rollback`, `db:wipe`, `db:seed` or any delete against the dev
  database without asking first.
- Tests run only against `construction_tracker_test`; `tests/TestCase.php` refuses any other database.

## Client data

- The client's workbooks live outside the repository, in `CLIENT_WORKBOOKS_DIR`. Never copy their data
  into code, seeders, tests or committed files.
- The Excel oracle, the 151-row import proof and the chart oracle must keep passing.

## Working on a phase

- Finish with the full PHP suite, `npm run test` and `npm run build`, then `git add -A` and
  `git status`. Never commit.

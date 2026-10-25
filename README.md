# Shaligram Timeline

Construction timeline tracker for Shaligram's projects. It replaces the client's Excel workbooks while
reproducing them exactly: each building's schedule is a dependency graph, its planned dates are recalculated from
the chain, and progress, pace, forecasts and lead follow the workbook's own formulas.

- **Projects** hold **buildings**; a building is one timeline (a tower, or a project's common work).
- Site staff record actual dates; admins shape the plan; every change is audited.
- Same family as the Shaligram CRM: Laravel 13, Inertia, Vue 3, MySQL 8, Tailwind 4.

Design and schema: [`docs/system-design.md`](docs/system-design.md) and
[`docs/database-structure.md`](docs/database-structure.md).

## Setup

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate   # then set DB_* and MAIL_*
php artisan migrate
php artisan db:seed --class=WorkbookSeeder          # Shaligram Vrundavan: Tower A, Tower B, Overall Timeline
php artisan user:create-admin                        # the first admin; never through sign-up
```

`WorkbookSeeder` parses the plan from the formulas in `docs/workbooks/` and refuses to finish unless every one of
the 151 computed dates equals the workbook's own cell.

## Roles

Global, approved by an admin after sign-up (`/signup`). Accounts start as viewers.

| Role | Can |
| --- | --- |
| viewer | Read everything |
| editor | Record and change actual dates and notes; add, edit and delete projects; add buildings (empty, copied or imported from Excel), rename, reorder and delete them |
| admin | Everything: durations, predecessors, adjustments, a building's start dates, approving users. Roles are changed in the database for now; there is no screen for it yet |

Every endpoint is checked by a policy; buttons are only hidden to match.

## Pages

| Address | Page |
| --- | --- |
| `/projects` | Home: every project with % done, most-behind building, expected handover |
| `/projects/{id}` | One project: a card per building, add / edit / reorder / delete buildings |
| `/projects/{id}/buildings/{id}` | The building's timeline: status, activity table, record actual, edit plan with a preview of what moves |

## Settings worth knowing

- `APP_TIMEZONE` (default `Asia/Kolkata`); each project also has its own timezone, which decides "today".
- Password reset mail: `MAIL_MAILER=log` locally. Production needs a real mailer, a `MAIL_FROM_ADDRESS` on a domain
  with SPF/DKIM/DMARC, and `APP_URL` set to the real HTTPS address.
- Excel uploads are held in `storage/app/private/imports` for at most an hour.

## Tests

```bash
php artisan test
```

Tests run on MySQL against `construction_tracker_test` only; `tests/TestCase.php` refuses any other database,
because `RefreshDatabase` wipes whatever it points at. The workbooks are the test oracle: the import proof and
`WorkbookMetricsTest` compare every figure with Excel's cached values.

`php artisan workbook:document` regenerates the seed-data section of `docs/database-structure.md`;
`WorkbookDocumentTest` fails if it drifts from the workbooks.

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

`WorkbookSeeder` parses the plan from the formulas in the client's workbooks and refuses to finish unless every one of
the 151 computed dates equals the workbook's own cell.

**The client's workbooks are never in the repository.** They are read from `CLIENT_WORKBOOKS_DIR`
(default `~/client-files/building-timeline/references`), and `docs/workbooks/` and `*.xlsx` are git-ignored.

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
| `/projects/{id}/buildings/{id}` | The building's timeline: status, the progress chart, activity table, record actual, edit plan with a preview of what moves |

## Progress chart

The building page's "Planned vs actual progress" chart is the client's Chart-sheet graph, computed on request by
`BuildingChartService` from the same figures as the status row (nothing is stored). It also answers at
`GET /projects/{id}/buildings/{id}/chart`. Drawn with Apache ECharts, pinned at 6.1.0 and loaded only on building
pages.

## Settings worth knowing

- `APP_TIMEZONE` (default `Asia/Kolkata`); each project also has its own timezone, which decides "today".
- Password reset mail: `MAIL_MAILER=log` locally. Production needs a real mailer, a `MAIL_FROM_ADDRESS` on a domain
  with SPF/DKIM/DMARC, and `APP_URL` set to the real HTTPS address.
- Excel uploads are held in `storage/app/private/imports` for at most an hour.

## Tests

```bash
php artisan test   # PHP: services, endpoints, policies, the workbook oracles
npm run test       # Vue: the progress chart (Vitest + jsdom, ECharts mocked)
```

Copy `.env.testing.example` to `.env.testing` and fill in the database credentials: Laravel loads it instead of
`.env` when testing. Tests run on MySQL against `construction_tracker_test` only; `tests/TestCase.php` refuses any other database,
because `RefreshDatabase` wipes whatever it points at. The workbooks are the test oracle: the import proof and
`WorkbookMetricsTest` compare every figure with Excel's cached values, and `WorkbookChartTest` every point of the
progress chart with the Calc sheets behind the client's Chart sheets. They are read from `CLIENT_WORKBOOKS_DIR`;
where it does not hold all three, the tests that need them are skipped, not failed.

`php artisan workbook:document` regenerates the seed-data section of `docs/database-structure.md`;
`WorkbookDocumentTest` fails if it drifts from the workbooks.

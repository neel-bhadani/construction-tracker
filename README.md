# Shaligram Timeline

Construction timeline tracker for Shaligram's projects. It replaces the client's Excel workbooks while
reproducing them exactly: each building's schedule is a dependency graph, its planned dates are recalculated from
the chain, and progress, pace, forecasts and lead follow the workbook's own formulas.

- **Projects** hold **buildings**; a building is one timeline (a tower, or a project's common work), optionally
  split into **floors**.
- One account, its own data: whoever owns a project can do everything with it; every change is audited.
- Same family as the Shaligram CRM: Laravel 13, Inertia, Vue 3, MySQL 8, Tailwind 4.

Design and schema: [`docs/system-design.md`](docs/system-design.md),
[`docs/database-structure.md`](docs/database-structure.md) and the v5 plan [`docs/PLAN_V5.md`](docs/PLAN_V5.md).
In the app, the header menu's **Help** page explains it in plain words.

## Setup

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate   # then set DB_*, MAIL_* and the settings below
php artisan migrate
php artisan user:create                             # an account; or register at /register
```

Then import the client's project: see [Importing the client's workbooks](#importing-the-clients-workbooks).

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| `CLIENT_WORKBOOKS_DIR` | `~/client-files/building-timeline/references` | Where the client's workbooks are. **They are never in the repository**: `docs/workbooks/` and `*.xlsx` are git-ignored. The import and the oracle tests read them from here; tests that need them are skipped where they are missing. |
| `REGISTRATION_OPEN` | `true` | `false`: `/register` and the old `/signup` answer 404 and the sign-in page says nothing about signing up. `php artisan user:create` still adds an account. |
| `PROJECT_OWNER_EMAIL` | — | Only for `php artisan migrate` on a database that has projects from before owners existed: the account that owns them. The migration stops if it is needed and missing. |
| `APP_TIMEZONE` | `Asia/Kolkata` | Each project also has its own timezone, which decides "today". |
| `MAIL_*` | `log` | Password-reset mail. Production needs a real mailer, a `MAIL_FROM_ADDRESS` on a domain with SPF/DKIM/DMARC, and `APP_URL` set to the real HTTPS address. |

## Commands

| Command | What it does |
| --- | --- |
| `php artisan user:create` | Asks for name, email and password, and creates an account. Works while registration is closed. |
| `php artisan project:transfer {project id} {email}` | Hands a project, with its buildings, floors, activities and history, to another account, in one transaction. Refuses if that account already has a project of the same name. Use it when the client registers. |
| `php artisan timeline:excel-to-json {--dir=} {--out=} {--force}` | Import step 1: the workbooks to a JSON file. |
| `php artisan timeline:import-json {file} --owner=<email> {--dry-run} {--as=}` | Import step 2: the JSON file to a new project of that account. |
| `php artisan workbook:document` | Regenerates the seed-data section of `docs/database-structure.md`; `WorkbookDocumentTest` fails if it drifts. |

## Importing the client's workbooks

1. **Back up the database first.**

   ```bash
   mysqldump -u <user> -p --single-transaction construction_tracker > storage/app/private/backups/construction_tracker-$(date +%Y%m%d-%H%M%S).sql
   ```

2. **Excel → JSON.** `php artisan timeline:excel-to-json` reads `SV_A3.xlsx`, `SV_B3.xlsx` and `SV_Overall3.xlsx`
   from `CLIENT_WORKBOOKS_DIR` and writes `storage/app/private/imports/<today>/project.json`. That folder is
   git-ignored (`git check-ignore -v` on the file shows the rule), so the client's data never reaches the
   repository. It parses the formulas, never evaluates them, and stores Excel's own planned dates and Chart-sheet
   figures in the file. It refuses to overwrite a file that is there already (`--force` to do it anyway).

3. **Check the floors.** The command prints, per tower, the floor it guessed for every activity: "GF SLAB",
   "DAY 1 TO GF SLAB" and "GROUND FLOOR …" (not "GROUND LEVEL …") → Ground floor, "1st SLAB" … "13th SLAB" → that floor, everything else (and every break)
   → Whole building. The common-work timeline gets no floors. To correct one, change the activity's `"floor"` in
   the JSON and make sure the building's `"floors"` list has it.

4. **Dry run.** `php artisan timeline:import-json <file> --owner=<email> --dry-run` checks the file (shape, rows,
   "comes after", floors, breaks, loops, every planned date against Excel), then does every insert in one
   transaction, reads back every planned date and the %, lead, finish and forecasts against the file — and rolls
   it all back. Its report is exactly what a real run would do.

5. **Import.** The same command without `--dry-run`. Any mismatch stops it and writes nothing. It refuses a project
   name the owner already has (`--as="Another name"` imports it under a different one) and a file already imported
   for that owner, and logs each import in `plan_imports`.

The "Import from Excel" option when adding a building goes through the same checks.

## Deploying to the live server

The live server is Hostinger shared hosting: no Node, so the assets are built here and uploaded. `public/build/`
is git-ignored; run `npm run build` locally and upload the whole folder to the server's `public/build/` with every
frontend change, or it serves the old assets.

The client's project goes in from `database/data/shaligram-vrundavan.json`, the only client data in the
repository (it is private). It is made by `php artisan timeline:excel-to-json
--out=database/data/shaligram-vrundavan.json --force`, never edited by hand, and dry-run locally before it is
committed. The live `.env` needs `APP_TIMEZONE=Asia/Kolkata`, the file's timezone.

First install, in the app folder on the server:

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan user:create          # the client's name, email and password, typed on the server
php artisan timeline:import-json database/data/shaligram-vrundavan.json --owner=<client email> --dry-run
php artisan timeline:import-json database/data/shaligram-vrundavan.json --owner=<client email>
php artisan optimize
```

Read the dry run before the real one: three buildings, "Planned dates equal to the file (Excel): 151 of 151" and
every figure equal to `expected`.

Running the import again is safe. The same file twice is refused (`plan_imports` holds its sha256 per owner), and so
is a changed file while the owner has a project of that name; a failed run is one rolled-back transaction and
writes nothing. **Never use `--as` on live**: it imports the file as a second project.

A later deploy is `git pull`, `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`,
`php artisan optimize`, plus the uploaded `public/build/`.

**Never run on the live server:** `migrate:fresh`, `migrate:rollback`, `migrate:refresh`, `db:wipe`, `db:seed`,
`php artisan test`, `npm run dev`.

## Accounts

There are no roles. Registering at `/register` creates an account and signs it in; it can then do everything with
the projects it owns, and nothing else exists for it: another account's project, building or activity is a 404 on
every page and API route. `OwnershipTest` walks the route list and fails for any signed-in route without a case.
"My profile" (the header menu): name, email, phone, and a password change that signs out every other session.

## Pages

| Address | Page |
| --- | --- |
| `/projects` | Home: every project with % done, most-behind building, expected handover |
| `/projects/{id}` | One project: overall progress, a card per building (%, lead, forecast finish) |
| `/projects/{id}/buildings/{id}` | The building's timeline: status, the progress chart, floors, the activity list, record actual, edit the plan with a preview of what moves |
| `/profile` | My profile: details and password |
| `/help` | Help: how to use the app, in plain words |

## Progress chart

The building page's "Planned vs actual progress" chart is the client's Chart-sheet graph, computed on request by
`BuildingChartService` from the same figures as the status row (nothing is stored). It also answers at
`GET /projects/{id}/buildings/{id}/chart`. Drawn with Apache ECharts, pinned at 6.1.0 and loaded only on building
pages.

## Tests

```bash
php artisan test   # PHP: services, endpoints, ownership, the import, the workbook oracles
npm run test       # Vue: the dialogs, floors, the progress chart (Vitest + jsdom, ECharts mocked)
```

Copy `.env.testing.example` to `.env.testing` and fill in the database credentials: Laravel loads it instead of
`.env` when testing. Tests run on MySQL against `construction_tracker_test` only; `tests/TestCase.php` refuses any
other database, because `RefreshDatabase` wipes whatever it points at. The workbooks are the test oracle: the
151-row import proof and `WorkbookMetricsTest` compare every figure with Excel's cached values, and
`WorkbookChartTest` every point of the progress chart with the Calc sheets behind the client's Chart sheets.
Everything else uses small hand-made data with every figure worked out by hand (`tests/Fixtures`, and the test
headers).

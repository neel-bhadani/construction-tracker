# Construction Timeline Tracker — Workbook Analysis & System Design

Source workbooks: `SV_A3.xlsx`, `SV_B3.xlsx`, `SV_Overall3.xlsx`

---

## 1. What the workbook actually is

Three files, one engine. Each file tracks one **scope** and repeats the same four sheets:

| Sheet | Role |
| --- | --- |
| `<Scope>` (e.g. Tower A) | The only sheet anyone edits. Activity rows, durations, actual dates. |
| `<X> Calc` | Chart helper tables. Labelled "do not edit". |
| `<X> Chart` | Inputs, status, pace, forecasts, and the row-by-row forecast table. Two scatter charts. |
| `<X> Lead Tracker` | Applies the current lead to every pending activity; what-if comparison. |

The three scopes:

| Scope | Activities | Total planned days | Actuals entered | Finish (planned) |
| --- | --- | --- | --- | --- |
| Tower A | 52 | 853 | 1 | 5 Aug 2028 |
| Tower B | 52 | 843 | 3 | 26 Jul 2028 |
| Overall Timeline | 47 | 825 | 1 | 8 Jul 2028 |

All three share a start date of **5 April 2026**.

**Overall Timeline is not a roll-up.** It is a third independent list covering site-wide work — basement,
compound wall, landscaping, drainage, transformers, NOCs. It shares some activity names with the towers but has
its own durations and its own chain. Do not build it as a computed total of Tower A and Tower B; that would
change the client's numbers.

**The files overlap.** `SV_B3.xlsx` contains Tower A, Tower B *and* Overall Timeline. `SV_A3.xlsx` contains its
own copy of Tower A. The two Tower A copies are currently identical, but nothing keeps them that way — this is
the duplication the application removes.

---

> In the application each scope is a **building** of a project — Tower A and Tower B as buildings, Overall
> Timeline as the project's common work. Section 9 describes the project and building level.

## 2. The data model hidden in the formulas

The activity sheet looks like a flat table but is a **dependency graph**. Every row's day offset is computed
from another row's.

| Column | Header | What it really is |
| --- | --- | --- |
| A | SR. NO. | Unused — empty in all three files |
| B | DESCRIPTION | Activity name, with the duration often written into the text |
| C | ESTIMATED COMPLETION DAY | Cumulative day number from project start |
| D | ESTIMATED DATE | `start + C`, sometimes plus a hidden nudge |
| E | ACTUAL DATE | Entered by hand when the activity completes |
| F | AHEAD IN DAYS | `D - E`. Positive = ahead of plan |

Column C is never a plain number except on the first row. It is always `= <another row's C> + <duration>`:

```
C4  = 180                 DAY 1 TO GF SLAB
C5  = C4 + 20             1st SLAB
C7  = C5 + 20 + 20        2nd SLAB
C25 = C24 + 75            INT. & EXT. PLASTER
C31 = C25 + 20 + 20       GROUND LEVEL PLASTER   <- branches back to C25, not C30
```

So each activity carries two facts: **which activity it follows**, and **how many days after it**. Rows 25 to 55
branch heavily — many activities hang off the same predecessor and run in parallel. A flat "previous row +
duration" model would be wrong.

This is the single most important thing to preserve. Store the predecessor as a foreign key and the duration as
a number, then recompute the chain. Do not store only the final day number, or editing a duration mid-chain will
silently stop moving everything after it — which is exactly what the client gets from Excel today and the reason
the tool is worth building.

**Holiday breaks are activities.** `DIWALI (08-11-2026) BREAK 20`, `HOLI (22-03-2027) BREAK 30` and
`OTHER FESTIVAL BREAKS 13` sit in the chain and consume days like any other row. They need a flag so they can be
styled differently, but they must stay in the chain — and they count toward progress like any other row. The
workbook's "planned work-days completed" is the furthest completed row's day number, breaks included.

---

## 3. Business logic to preserve exactly

Every number below is computed, never stored. These are the client's definitions, taken from the formulas in each
scope's Chart and Lead Tracker sheets — not improved versions. The formulas are identical across all three scopes.
`BuildingMetricsService` and `LeadProjectionService` implement them, and `WorkbookMetricsTest` compares every figure
for all three scopes with Excel's cached values (2,204 figures, clock frozen at 2026-10-15, the date the
workbooks were last calculated).

"Day" is the planned day number (`planned_day`, column C). "Date" is the planned date (`planned_date`, column D),
which includes any date adjustment.

### Scope status

| Figure | Cell | Definition |
| --- | --- | --- |
| Total planned days | C8 | `MAX(day)` across the scope |
| Planned finish | C9 | `MAX(date)` |
| Latest actual milestone | C10 | `MAX(actual_date)` |
| Planned days completed | C11 | `MAX(day)` over every row with an actual date, **breaks included** |
| Progress % | C12 | days completed ÷ total planned days |
| Current lead | C13 | `date − actual` of the row whose actual equals the latest actual; if several share it, the first in row order (`MATCH`) |
| Days since last milestone | C14 | `today − latest actual` |

Note what progress is **not**: it is not a count of completed activities. It is the day-number of the furthest
completed one, divided by the total. Progress therefore jumps in steps, and a single out-of-order entry
overstates it.

### Pace

```
average pace = days completed / (latest actual - actual start)                                  C17
               = 1 when latest actual is not after actual start
previous     = latest actual date strictly before the latest one                                C18
days at prev = MAX(day) over rows whose actual is on or before previous                         C19
recent pace  = (days completed - days at prev) / (latest actual - previous)                     C20
               = average pace when there is no previous actual date
```

Both are floored at 0.01. Above 1.00 means running ahead of plan. Actual start is the Chart sheet's C5, which
equals the planned start in all three workbooks; the app uses `projects.actual_start_date`, falling back to
`planned_start_date`.

Recent pace can reach the floor but cannot go negative: days completed is a maximum over a superset of the rows
counted at the previous milestone. It reaches 0 only when an out-of-order entry finishes the furthest row before
the latest one.

### Three forecasts, shown side by side

| Method | Formula | Meaning |
| --- | --- | --- |
| Average pace | `ROUND(latest actual + (total − done) ÷ avg pace)` | Whole-project trend continues |
| Recent pace | `ROUND(latest actual + (total − done) ÷ recent pace)` | Last two milestones continue |
| Hold current lead | `planned finish − current lead` | Today's lead is simply kept |

`ROUND` is Excel's: half away from zero. Each forecast's ahead/delay figure is `planned finish − forecast`.

Per-activity forecasts follow the same three methods: a completed activity shows its actual date; a pending one
shows `ROUND(latest actual + MAX(0, day − done) ÷ pace)` for the two pace methods, and `date − current lead` for
hold-lead. A pending row already overtaken by progress is forecast at the latest actual, not in the past.

**The pace forecasts read the day; the hold-lead forecast reads the date.** So a date adjustment moves the
hold-lead column and not the pace columns. On Tower A's Diwali row the two are 20 days apart where neighbouring
rows are 1 and 4 apart: 17 of those days are the hidden nudge. This is finding 1 (section 4) surfacing a second
time. Reproduced, not reconciled.

### Lead tracker

Status per activity is three-valued: **Done** (has an actual date), **NEXT** (the first row without one in
**row order** — `COUNTBLANK(E$4:E_row)=1`, not date order), **Pending** (the rest, including a blank row that sits
after a later completed row). The tracker applies the current lead to every pending activity; a completed
activity keeps its own achieved lead. A what-if lead replaces the current lead only when one is supplied: blank
means use the current lead, and 0 is a lead like any other. The quick-comparison table (−5, 0, 5, 7, 9, 12 days)
is `planned finish − lead` repeated.

### Before the first actual date

The workbook shows `#N/A` or meaningless dates for lead, pace and forecasts until an actual exists. The app
reports them as null rather than inventing a value: progress is 0, the first row is NEXT, and the lead tracker
forecasts only once a what-if lead is supplied.

### Charts

Two scatter charts per scope. The main one plots planned %, actual %, and the three projections against date —
an S-curve. The second zooms to the first twelve months. Both derive entirely from the figures above.

---

## 4. Problems found in the workbook

These are defects in the client's file, not in the plan.

| # | Finding | Where | Impact |
| --- | --- | --- | --- |
| 1 | Hidden date nudges | Tower A `D6 = start + C6 + 17`; Tower B `D8 = start + C8 + 12` and `D17 = start + C17 + 7` (Holi); Overall `D26`, `D27` = `+ 7` | The day number and the date disagree. |
| 2 | Duration in the name contradicts the formula | Tower A (10 rows) and Tower B (11 rows) only. E.g. `ALL FINISHING WORK 90` computes `C28 + 60`; `MASONRY 30` computes `C23 + 30 - 30`, a net zero; Tower B `13th SLAB 20` computes `C18 + 30`. Overall Timeline has none — its `MASONRY 30` is `+ 30` and `ALL FINISHING WORK 90` is `+ 90` | Masonry takes no time at all in either tower. |
| 3 | Wrong sheet title | Tower A sheet in both files is titled `TOWER B - OVERALL PROJECT TIMELINE` | Cosmetic. |
| 4 | Tower A duplicated across two files | `SV_A3.xlsx` and `SV_B3.xlsx` both hold it | Identical today. Nothing keeps them so. |
| 5 | Unexplained second date | `F2 = 9 May 2026`, beside `START DT: 5 Apr 2026`, unlabelled | Possibly actual site start. Unknown. |
| 6 | Progress assumes in-order entry | Progress uses the furthest completed day number | Out-of-order entry overstates progress. |
| 7 | Recent pace needs two actuals | `C18` returns zero when only one exists | Falls back to average pace, invisibly. |

### DECISION TAKEN

**Reproduce the workbook exactly, including these defects.** Where an activity's name disagrees with its
formula, **the formula wins**:

- in Tower A and Tower B, `MASONRY 30` → offset **0** days and `ALL FINISHING WORK 90` → offset **60** days;
  in Overall Timeline they are 30 and 90, as named
- Tower B `13th SLAB 20` → offset **30** days
- the +17 / +12 / +7 nudges → carried into `date_adjustment_days` with reason `"carried from original sheet"`

The complete per-scope list of every name-vs-formula mismatch and every nudge is in the Seed data section of
`database-structure.md`. It is generated from the parsed workbooks, so treat it, not this summary, as the record.

Do not correct, improve or tidy any of these. If a computed date does not match the workbook, the code is wrong,
not the workbook.

---

## 5. Database design

Five tables at first — `database-structure.md` is the current schema, and adds `project_revisions`. The schedule is a graph, so the predecessor is a self-referencing key on `activities`.

### projects

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| name | varchar(150) | |
| planned_start_date | date | The `START DT` — 5 Apr 2026 |
| actual_start_date | date null | Defaults to planned; drives average pace |
| timestamps | | |

### scopes

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| project_id | FK → projects | cascade on delete |
| name | varchar(100) | Tower A, Tower B, Overall Timeline |
| sort_order | smallint | |
| timestamps | | |

Unique on `(project_id, name)`.

### activities

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| scope_id | FK → scopes | cascade on delete |
| description | varchar(255) | |
| predecessor_id | FK → activities, null | Null = chain root |
| offset_days | smallint unsigned | Days after the predecessor |
| date_adjustment_days | smallint, default 0 | Finding 1 — explicit, not hidden |
| adjustment_reason | varchar(255) null | Required when adjustment ≠ 0 |
| is_break | boolean, default false | Diwali, Holi, festival breaks |
| planned_day | integer | **Derived, stored.** Recomputed on change |
| planned_date | date | **Derived, stored.** Recomputed on change |
| actual_date | date null | The only field site staff edit daily |
| sort_order | integer | Display order, independent of the chain |
| notes | text null | |
| timestamps | | |

Indexes: `(scope_id, sort_order)`, `(scope_id, actual_date)`, `predecessor_id`.

### activity_revisions

An audit trail. Excel has none, and "who moved this date" is the first question asked when a schedule slips.

| Column | Type |
| --- | --- |
| id | bigint PK |
| activity_id | FK → activities |
| user_id | FK → users |
| field | varchar(40) |
| old_value | varchar(255) null |
| new_value | varchar(255) null |
| created_at | timestamp |

### users

Standard Laravel, plus an optional `phone`. There are no roles and no approval (section 11).

### Stored versus computed

Stored and recomputed on write: `planned_day`, `planned_date`. Everything else from section 3 — progress, pace,
forecasts, lead, status — is computed at read time in a service class. Nothing derived is persisted beyond those
two columns, so there is no second copy of the truth to drift.

The two are stored rather than computed because the chart needs the whole scope sorted by date on every page
load, and walking a 52-node graph per request to get there is work the database can do once at write time
instead.

### Validation and integrity

- `predecessor_id` must belong to the same scope.
- The predecessor graph must be acyclic — reject on save with the cycle named, do not rely on a recursion limit.
- `offset_days` ≥ 0.
- `actual_date` not before `project.actual_start_date`.
- `adjustment_reason` required when `date_adjustment_days` ≠ 0.
- Deleting an activity that others depend on: block it, and name the dependants.
- Recompute the whole scope inside a transaction on any change to `offset_days`, `predecessor_id`,
  `date_adjustment_days`, or the project start date.

---

## 6. Application architecture

Same stack as Shaligram CRM: Laravel 13, Inertia, Vue 3, MySQL, Tailwind 4 through `@tailwindcss/vite` (not
PostCSS). Same component conventions, same modal patterns, same table styling.

### Layering

| Layer | Responsibility |
| --- | --- |
| `ScheduleRecalculator` | Walks the predecessor graph for one scope, writes `planned_day` and `planned_date`. The only writer of those two columns. |
| `BuildingMetricsService` | Progress, pace, the three forecasts, current lead. Pure functions over loaded activities. |
| `LeadProjectionService` | Applies a lead (current or what-if) to pending activities. |
| `ScheduleEditor` | Every user change to activities and to a building's start dates: one transaction, one audit row per changed field, recalculation when dates can move. |
| `ProjectEditor` | Every user change to projects and buildings: create, edit, reorder, soft delete; a new building's plan empty, copied or imported. Audited. |
| `PlanWriter` | Writes a whole plan into a building — the workbook seeder, a copied plan, an Excel import — and recalculates it. |
| `ProjectSummaryService` | A project across its buildings: work-day-weighted % done, most behind, expected handover. Combines BuildingMetricsService output; computes nothing new per building. |
| Controllers | Thin. Validate, call a service, return an Inertia response. |
| Form requests | All validation, including the cycle check. |

### Recalculation

One algorithm, run inside a transaction whenever the chain changes:

1. Load the scope's activities.
2. Topologically sort by `predecessor_id`; abort if a cycle is found.
3. For each in order: `planned_day = predecessor.planned_day + offset_days` (roots use `offset_days` alone).
4. `planned_date = project.planned_start_date + planned_day + date_adjustment_days`.
5. Bulk update.

Fifty-two rows makes this a few milliseconds. Do not optimise it into incremental updates — a full recompute is
simple enough to be obviously correct, and correctness is the entire value over Excel.

### Authorisation

One account, its own data. Each project has an owner (`projects.owner_id`); the owner can do everything with
it, its buildings, activities and sub-activities, and to every other account they do not exist: every policy answers
404. `OwnershipTest` walks the route list and fails for a signed-in route without an ownership case. (Until
v5 there were viewer, editor and admin roles; see section 11.)

### Security

Standard Laravel auth with hashed passwords, CSRF, rate-limited login, policy checks on every write (not only
hidden buttons), `APP_DEBUG=false` in production. No secrets in the repository. The audit trail records who
changed what.

### Importing the workbook

Each activity's formula is read once into `predecessor_id` + `offset_days`, with the nudges going into
`date_adjustment_days`. After import, every computed `planned_date` must match the workbook cell exactly; any
mismatch fails loudly. That assertion is the migration's proof. Since v5 the import is two commands, Excel →
JSON → database (section 11); `WorkbookSeeder` remains for the test oracle.

---

## 7. The pages

Section 9 adds the Projects and Project pages; the main page below is now the **building page**, one per
building, at `/projects/{id}/buildings/{id}`, unchanged apart from its breadcrumb and building selector.

### Login

Email and password, rate limited, with a forgotten-password flow. Nothing else.

### Registration

Name, email, password, at `/register`; the new account is signed in straight away. It sees only its own
projects, so registering reveals nothing of anyone else's schedule. `REGISTRATION_OPEN=false` closes it
(`/register` and the old `/signup` answer 404); `php artisan user:create` still adds an account.

### Main page

One page, one scope at a time, chosen by a selector at the top.

**Status row** — six figures: progress %, days ahead or behind, planned finish, and the three forecast finishes.
The ahead/behind figure is the one the client looks at first, so it leads.

**S-curve chart** — planned against actual, with the three projections as dashed lines, mirroring the existing
scatter chart. A toggle switches to the first-twelve-months zoom rather than drawing a second chart.

**What-if lead** — a single input. Type a lead in days, every pending date and the finish move. This is the Lead
Tracker sheet, and it is the feature most likely to get used daily.

**Activity table** — one row per activity: description, planned date, actual date, ahead/behind, status chip
(Done / NEXT / Pending), forecast date. The NEXT row is highlighted, breaks are styled apart, and a row whose
date was nudged shows that with its reason on hover.

**Modals** — two:

| Modal | Fields |
| --- | --- |
| Record actual date | Date, note |
| Add / edit activity | Name, comes after, planned date ⇄ days after, actual date, break, note (since v5; section 11) |
| Add / edit sub-activity | Name, planned date, progress 0–100%, done date, note (since v5.1; section 11) |

The edit modal shows what will move before saving — "this changes 23 later dates; finish moves from 5 Aug to
12 Aug" — and the save is refused with the cycle named if the chosen predecessor would create one.

**Responsive** — on a phone the table collapses to cards and the chart scrolls horizontally. Site engineers will
record actuals from a phone on site, which is the main daily use.

---

## 8. Build order

Stop after each stage.

1. **Schema and migrations.** Five tables, constraints, indexes. No UI.
2. **`ScheduleRecalculator`.** The graph walk, topological sort, cycle rejection. Unit tests first, using the
   real Tower A chain as the fixture.
3. **Import and prove it.** Seed all three scopes from the workbook, then assert every computed `planned_date`
   equals the workbook's cell. Any mismatch fails the build. Do not continue until all 151 activities match.
4. **`BuildingMetricsService`.** Progress, pace, the three forecasts, current lead.
5. **Auth.** Login, registration, policies (roles and approval until v5).
6. **Main page.** Status row, activity table, both modals, recalculation preview on save.
7. **Charts and what-if.** S-curve with projections, zoom toggle, lead input.
8. **Projects and buildings.** Many projects, many buildings each; the timeline screen and its calculations unchanged.

### Verification targets

The workbook is the test oracle. Every figure below has a known value today, and the application must reproduce
all of them:

| Check | Expected |
| --- | --- |
| Tower A planned finish | 5 Aug 2028 |
| Tower A progress | 21.1% |
| Tower A current lead | 12 days |
| Tower A average pace | 1.071 |
| Tower A forecast (average pace) | 9 Jun 2028 |
| Tower B planned finish | 26 Jul 2028 |
| Overall planned finish | 8 Jul 2028 |

If any of these differ, the port is wrong — not the workbook.

---

## 9. Projects and buildings

The client has several projects, each with several buildings. The timeline screen, its Excel-faithful calculations
and its audit trail are unchanged; the project and building level sits around them.

### Data

- A **project** has a name (unique among live projects), optional details, a planned start, a timezone and its
  creator. Its planned start is **only the default for a new building**: changing it moves nothing. Its timezone
  decides "today" — days since the latest milestone, and the rule that an actual date cannot be in the future.
- A **building** is one timeline, of type `building` or `common_work`, with its own planned and actual start. Every
  planned date in it counts from its planned start. Changing that start recalculates that building only.
- Both are soft-deleted. A project is deleted only once it has no buildings, and the refusal names them. A deleted
  building's timeline leaves the app; its activities and audit trail stay.
- The former `scopes` became `buildings` in place, keeping every id, row, actual date and revision. The migration
  checks its own counts and aborts on any difference; `ProjectsAndBuildingsMigrationTest` runs it against the old
  schema and compares every row, and again after rolling back and migrating once more.

### Pages

| Page | Address | Shows |
| --- | --- | --- |
| Projects (home) | `/projects` | One card per project: buildings, % done, most-behind building and its lead, expected handover. + New project, edit, delete |
| Project | `/projects/{id}` | A summary line, then one card per building: type, % done, ahead/behind, NEXT activity, expected handover. + Add building, edit, reorder (↑ ↓), delete |
| Building | `/projects/{id}/buildings/{id}` | The timeline screen of section 7, with the breadcrumb "Projects › Shaligram Vrundavan › Tower A" |

The header carries a project switcher. Links to the old `/scopes/{id}` addresses redirect (301) to the building page.

### The project figures

All three combine `BuildingMetricsService` output; nothing is calculated in a controller or in Vue.

| Figure | Definition |
| --- | --- |
| % done | Work-day weighted: the buildings' completed planned days ÷ their total planned days, so a long tower counts for more than a short one. Breaks count, as in each building |
| Most behind | The building with the lowest current lead. Buildings with no actual date yet are skipped |
| Expected handover | Per building, the hold-current-lead forecast — the planned finish until the first actual. Per project, the latest of its buildings' |

### Adding a building

A new building's plan starts one of three ways:

- **Empty** — activities are added one by one.
- **Copy** from any building in any project: the chain, offsets, adjustments with their reasons, and break flags.
  Not actual dates or notes, which belong to the site they were recorded on. Dates are computed from the new
  building's own start, so they follow it.
- **Import from Excel**: upload a workbook laid out like the client's (activities from row 4, start date in `E2`),
  pick the sheet. The chain is parsed from the formulas, as the seeder does; actual dates are not imported. Day
  numbers must equal Excel's cached column C, and when the building starts on the workbook's `E2` date every date
  must equal column D. Any difference, or a formula the parser does not recognise, is refused with the cell named
  and nothing is written. Uploads are `.xlsx` up to 2 MB, held for an hour under a random token.

Every copied or imported activity gets a `created` revision naming its source.

### Access

The owner of a project manages it all, start dates included; anyone else gets a 404 (section 11).

---

## 10. Progress chart

The building page draws the client's Chart-sheet graph under the status row: "Planned vs actual progress".
`BuildingChartService` computes it on request from `BuildingMetricsService` output; nothing is stored. Its `rows()`
reproduce the workbook's Calc sheet (`TA Calc`, `TB Calc`, `OT Calc`) row for row, and `WorkbookChartTest` compares
every one of them with Excel's cached values — 948 figures across the three buildings.

### The series, as the Calc sheet defines them

| Series | Calc | Points |
| --- | --- | --- |
| Planned | A/B | (planned start, 0%), then each planned date in date order — nudged dates included — at `MAX(day of rows planned on or before it) ÷ total days` |
| Actual | D/E | (actual start, 0%), then one row per activity: the k-th actual date in date order, the latest once they run out; `MAX(day of rows finished on or before it) ÷ total` |
| Average pace | G/H | (latest actual, progress) → (average-pace forecast, 100%) |
| Recent pace | J/K | (latest actual, progress) → (recent-pace forecast, 100%) |
| Hold current lead | M/N | (latest actual, progress), then per planned row `(MAX(planned date − lead, latest actual), MAX(planned %, progress))` |

Breaks count, as everywhere else, so the actual line ends at the status row's progress. With no actual date, the
Calc sheet repeats the actual start at 0% and the projections are `#N/A`; `rows()` keeps that, and the chart shows
only the planned line and Today.

### What the page draws

- Consecutive identical points are merged, their activities listed together: the repeated rows of the actual and
  hold-lead columns would otherwise stack thirty dots on one spot.
- Lines and colours are the client's: Planned blue, solid; Actual green, solid and thicker; average pace orange
  dashed; recent pace red dotted; hold lead purple dashed. Plus a Today line and a Planned finish line, which the
  workbook does not have.
- Tickboxes show and hide each line; average and recent pace start off, the rest on. A projection's label carries its
  finish date, e.g. "Average pace – finish 3 Feb 2030", which matters when it ends beyond the axis.
- **Full timeline**: planned start to the later of the planned finish and all three forecasts — ticked or not, so
  the axis does not jump — plus a month, capped at a year after the planned finish; quarterly ticks.
  **First 12 months**: planned start to twelve calendar months later, monthly ticks, 0–50% like the client's zoom
  chart unless a visible point in that window is above 50%.
- The tooltip gives the date, each visible line's percentage reached by then, and the activities whose points sit
  on that date. It stays inside the chart and opens on a tap.
- The chart is a page prop: recording or clearing an actual redraws it without a page load. ECharts (6.1.0, exact)
  is a chunk of its own, fetched only on building pages. `npm run test` covers the switch, tickboxes and empty states.

---

## 11. Version 5: one user, as simple as the Excel

The plan and its decisions are in [`PLAN_V5.md`](PLAN_V5.md). In short:

### Accounts and access

- One real user, the client. No roles, approval, sharing or invites.
- `/register` signs the new account in. `REGISTRATION_OPEN` (default `true`) closes it.
- `projects.owner_id`: every query and policy checks it; another account's data is a 404 on every page and API
  route. Project names are unique per owner.
- My profile: name, email, phone, and a password change that signs out every other session.
- `user:create` adds an account from the command line; `project:transfer {id} {email}` hands a project over.

### Activity dialog

Name, comes after, planned date next to "N days after", actual date, break, note. The planned date is
typed; the offset (what the workbook stores) follows. An adjustment is no longer typed in: it is shown
read-only with a Clear link. "Insert activity below" places a new row under another and, with "push",
relinks the row below so everything after it moves later. Actual dates never move. With sub-activities the
actual date is shown as "Done through its N sub-activities" and cannot be typed or cleared, and the activity
cannot become a break.

### Progress

`ProgressService` gives two levels in work-days: building (the workbook's figure, `BuildingMetricsService`
C12, unchanged) and project (work-days done ÷ total across all timelines, Common activities included). Floors
were removed in v5.1 (migration `…000014`); nothing is computed per floor any more.

### Work ID

Every activity has a Work ID, `{project code}-{nnn}` (`SV-001`; past 999 it simply grows). `projects.code` comes
from the name's initials (`Project::initials()`), is unique among one owner's live projects (a clash gets a
number: `SV2`) and can be changed in Edit project; changing it leaves the IDs already given. `WorkIds` takes the
next number from `projects.next_activity_number` inside the writing transaction, with the project row locked, so
two creates at once wait for each other; numbers only go up and are never reused or renumbered. It is called
by `ScheduleEditor::create` and `PlanWriter` (import, copied plan, Excel upload). The backfill numbered existing
activities Common activities first, then the live buildings in order, then deleted buildings, each in list order.

### Common activities

The former `common_work` building ("Overall Timeline") is each project's **Common activities**: one per project
(a unique index on `project_id` + the generated `live_common` flag), created with the project by
`ProjectEditor::createProject`. It is a timeline like a building's (same `TimelineView`, chart, metrics and
Excel checks) but lives on the project page (`/projects/{id}/common`), not as a building tab or card; its old
building URL redirects there. It cannot be renamed, moved or deleted on its own (`BuildingPolicy::rename`,
`move`, `delete`: a 403 with the reason for the owner, a 404 for anyone else); an empty one goes with its
project. New buildings are always `building`.

### Sub-activities

Steps inside an activity (`sub_activities`): Work ID `{activity Work ID}.{n}` from the activity's
`next_sub_number`, position, name, planned date, progress 0/25/50/75/100, done date (required at 100%, cleared
below; the actual date's rules), note; soft-deleted; audited in `sub_activity_revisions`. Every write goes
through `SubActivityEditor`, which then re-derives the activity: all at 100% → actual date = the latest done
date; any below → actual date cleared (each change audited on the activity). The first one added to a done
activity defaults to 100% on its actual date; deleting the last one leaves the activity as it is. Breaks have
none. The activity's bar is the average (`Activity::progressPercent()`); building and project % stay the
workbook's calculation, so a step moves them only by completing the activity, exactly as Mark done would.
Copying a plan copies names and planned dates at 0%.

### All activities

`/projects/{id}/activities`: the project's overall %, lead (the most behind timeline's) and forecast finish,
then one collapsible group per timeline (Common activities first) with its own % and lead, and every row with
its Work ID, name, bar, dates, status and forecast, opening to its sub-activities. Search by name or Work ID
(sub-activities' too), filter by group and status (All / Pending / Done / Next); a row opens the same Edit
dialog. `AllActivitiesPage` reads the activities and their sub-activities in two queries for the whole project
and computes each group with `BuildingMetricsService::forActivities()`, so the queries do not grow with the
rows (`AllActivitiesTest`). Measured on the dev machine with 151 activities and 500 sub-activities: the full page
in about 0.45 s, the Inertia request in about 0.25 s.

### Import: Excel → JSON → database

1. `php artisan timeline:excel-to-json` reads the workbooks in `CLIENT_WORKBOOKS_DIR` into
   `storage/app/private/imports/<date>/project.json` (git-ignored), with Excel's planned dates and Chart
   figures alongside.
2. `php artisan timeline:import-json <file> --owner=<email> --dry-run`, then without `--dry-run`. It validates
   (`PlanDocumentValidator`: shape, rows, breaks, loops, at most one common timeline, every planned date),
   writes in one transaction, reads back every date and figure against the file, and logs to `plan_imports`.
   The file's `common_work` timeline fills the project's own Common activities and is written first, so its
   activities take the first Work IDs. A dry run does the same and rolls back. The Excel upload in the UI goes
   through the same validator. Migrate before importing.

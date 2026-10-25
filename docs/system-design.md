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
`ScopeMetricsService` and `LeadProjectionService` implement them, and `WorkbookMetricsTest` compares every figure
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

Standard Laravel, plus `role` (`admin`, `editor`, `viewer`) and `approved_at` (nullable).

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
| `ScopeMetricsService` | Progress, pace, the three forecasts, current lead. Pure functions over loaded activities. |
| `LeadProjectionService` | Applies a lead (current or what-if) to pending activities. |
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

- **viewer** — read everything, edit nothing.
- **editor** — enter and change actual dates. This is the daily site job.
- **admin** — everything, including durations, predecessors, adjustments, and the project start date.

The split matters: changing an `offset_days` moves every downstream date. Site staff recording progress should
not be able to reshape the plan by accident.

### Security

Standard Laravel auth with hashed passwords, CSRF, rate-limited login, policy checks on every write (not only
hidden buttons), `APP_DEBUG=false` in production. No secrets in the repository. The audit trail records who
changed what.

### Importing the workbook

A one-off seeder, not an upload feature. Each activity's formula is read once into `predecessor_id` +
`offset_days`, with the nudges going into `date_adjustment_days`. After import, assert that every computed
`planned_date` matches the workbook cell exactly, and fail loudly on any mismatch. That assertion is the
migration's proof.

---

## 7. The three pages

### Login

Email and password, rate limited, with a forgotten-password flow. Nothing else.

### Registration

Name, email, password. **New accounts are created inactive and need an admin to approve them.** Open
registration on a page carrying a developer's live construction schedule and completion forecasts is a
commercial data leak — anyone who finds the URL learns when the towers hand over.

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

| Modal | Who | Fields |
| --- | --- | --- |
| Record actual date | editor, admin | Date, note |
| Add / edit activity | admin only | Description, predecessor (dropdown of same-scope activities), offset days, adjustment + reason, break flag, notes |

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
4. **`ScopeMetricsService`.** Progress, pace, the three forecasts, current lead.
5. **Auth.** Login, registration with approval, roles, policies.
6. **Main page.** Status row, activity table, both modals, recalculation preview on save.
7. **Charts and what-if.** S-curve with projections, zoom toggle, lead input.

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

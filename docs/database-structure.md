# Database Structure — Construction Timeline Tracker

Companion to `system-design.md`. This file is the schema only: tables, columns, types, keys, constraints,
validation and the recalculation contract. Read `system-design.md` sections 2 and 3 first — they explain *why*
the schedule is a graph and what must be computed rather than stored.

MySQL 8, InnoDB, `utf8mb4_unicode_ci`.

---

## Overview

Five tables.

```
projects ──< scopes ──< activities ──< activity_revisions
                            │                   │
                            └── predecessor_id ─┘ (self-reference)
                                                  users ──< activity_revisions
```

The schedule is a **directed acyclic graph**, not a list. `activities.predecessor_id` points at another activity
in the same scope. A flat "previous row + duration" model is wrong and will produce incorrect dates from the
first branch onward.

---

## projects

One row per construction project. Currently one: Shaligram Vrundavan.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigint unsigned, auto | no | | PK |
| `name` | varchar(150) | no | | |
| `planned_start_date` | date | no | | The workbook's `START DT` — 2026-04-05 |
| `actual_start_date` | date | yes | null | Falls back to `planned_start_date`. Drives average pace |
| `created_at` | timestamp | yes | | |
| `updated_at` | timestamp | yes | | |

Changing either date triggers a full recalculation of every scope in the project.

---

## scopes

One row per timeline. Three: Tower A, Tower B, Overall Timeline.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigint unsigned, auto | no | | PK |
| `project_id` | bigint unsigned | no | | FK → `projects.id`, `ON DELETE CASCADE` |
| `name` | varchar(100) | no | | |
| `sort_order` | smallint unsigned | no | 0 | Display order of the scope selector |
| `created_at` | timestamp | yes | | |
| `updated_at` | timestamp | yes | | |

Constraints:

- `UNIQUE (project_id, name)`
- Index on `(project_id, sort_order)`

**Overall Timeline is a peer, not a roll-up.** It holds its own 47 activities with their own durations. Never
compute it from the two towers.

---

## activities

The core table. One row per activity, including holiday breaks.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigint unsigned, auto | no | | PK |
| `scope_id` | bigint unsigned | no | | FK → `scopes.id`, `ON DELETE CASCADE` |
| `description` | varchar(255) | no | | e.g. `3rd SLAB 15` |
| `predecessor_id` | bigint unsigned | yes | null | FK → `activities.id`, `ON DELETE RESTRICT`. Null = chain root |
| `offset_days` | smallint unsigned | no | 0 | Days after the predecessor |
| `date_adjustment_days` | smallint | no | 0 | Signed. The workbook's hidden nudges |
| `adjustment_reason` | varchar(255) | yes | null | Required when adjustment ≠ 0 |
| `is_break` | boolean | no | false | Diwali, Holi, festival breaks |
| `planned_day` | int unsigned | no | 0 | **Derived, stored.** Written only by `ScheduleRecalculator` |
| `planned_date` | date | no | | **Derived, stored.** Written only by `ScheduleRecalculator` |
| `actual_date` | date | yes | null | The only field site staff edit daily |
| `sort_order` | int unsigned | no | 0 | Display order. Independent of the chain |
| `notes` | text | yes | null | |
| `created_at` | timestamp | yes | | |
| `updated_at` | timestamp | yes | | |

Indexes:

- `(scope_id, sort_order)` — the table view
- `(scope_id, actual_date)` — progress and pace queries
- `(predecessor_id)` — dependant lookups and the cycle check

### Why `sort_order` is separate from the chain

Display order and dependency order are not the same. In the workbook, `GROUND LEVEL PLASTER` sits at row 31 but
depends on row 25. Sorting by the chain would reorder the client's familiar list; sorting by row would break the
computation. Keep both.

### Derived columns

`planned_day` and `planned_date` are the **only** derived values that are stored. They are written exclusively
by `ScheduleRecalculator`, never by a controller, model mutator, or observer. Everything else — progress, pace,
forecasts, lead, status — is computed at read time.

---

## activity_revisions

Audit trail. Excel has none, and "who moved this date" is the first question asked when a schedule slips.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | bigint unsigned, auto | no | PK |
| `activity_id` | bigint unsigned | no | FK → `activities.id`, `ON DELETE CASCADE` |
| `user_id` | bigint unsigned | no | FK → `users.id`, `ON DELETE RESTRICT` |
| `field` | varchar(40) | no | `actual_date`, `offset_days`, `predecessor_id`, … |
| `old_value` | varchar(255) | yes | |
| `new_value` | varchar(255) | yes | |
| `created_at` | timestamp | no | |

Index on `(activity_id, created_at)`.

Recorded for user-made changes only. Do not write a revision row for the cascade — a single duration change
would otherwise produce fifty rows nobody reads.

---

## users

Standard Laravel, plus:

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `role` | enum(`admin`,`editor`,`viewer`) | no | `viewer` | |
| `approved_at` | timestamp | yes | null | Null = cannot sign in |

New registrations are created with `approved_at = null` and `role = viewer`. An admin approves them. Without
this, anyone who finds the URL learns the project's handover dates.

| Role | Can do |
| --- | --- |
| `viewer` | Read everything |
| `editor` | Record and change `actual_date` |
| `admin` | Everything: durations, predecessors, adjustments, project dates, user approval |

The split matters. Changing `offset_days` moves every downstream date; recording an actual date moves nothing.
Site staff should not be able to reshape the plan by accident.

---

## Validation rules

Enforced in form requests, not only in the UI.

| Rule | Reason |
| --- | --- |
| `predecessor_id` must belong to the same `scope_id` | A cross-scope dependency would make Overall depend on a tower |
| The predecessor graph must be acyclic | Reject on save, naming the activities in the cycle. Do not rely on a recursion limit |
| `offset_days` ≥ 0 | Negative would mean an activity precedes what it follows |
| `adjustment_reason` required when `date_adjustment_days` ≠ 0 | The workbook's nudges were invisible; these must not be |
| `actual_date` ≥ `project.actual_start_date` | |
| `actual_date` not in the future | It records something that happened |
| Deleting an activity with dependants is blocked | Name them. Silently re-pointing rewrites the schedule |
| Only `admin` may change `offset_days`, `predecessor_id`, `date_adjustment_days` | Policy-enforced, not hidden buttons |

---

## Recalculation contract

Run inside a transaction whenever any of these change: `offset_days`, `predecessor_id`,
`date_adjustment_days`, an activity is created or deleted, or `projects.planned_start_date` /
`actual_start_date`.

```
1. Load all activities for the scope.
2. Topologically sort by predecessor_id. Abort and report if a cycle exists.
3. For each in order:
       planned_day = predecessor ? predecessor.planned_day + offset_days
                                 : offset_days
4. planned_date = project.planned_start_date
                  + planned_day
                  + date_adjustment_days
5. Bulk update.
```

Recalculate the **whole scope**, every time. Fifty-two rows is a few milliseconds. Do not build incremental
updates — a full recompute is simple enough to be obviously correct, and correctness is the entire reason this
replaces the spreadsheet.

Recording an `actual_date` does **not** trigger recalculation. Actuals never move planned dates; they change
progress, pace and the forecasts, all of which are computed at read time.

---

## Seed data

Three scopes, 151 activities, imported by `WorkbookSeeder` from the workbooks in `docs/workbooks/`. The copies of
Tower A and Overall Timeline inside `SV_B3.xlsx` are identical to their own files and are ignored.

**Reproduce the workbook exactly, including its defects.** The chain is parsed from the formulas, never typed:
column C gives `predecessor_id` and `offset_days`, column D gives `date_adjustment_days`. Where a name disagrees
with its formula, the formula wins. Nothing is special-cased; the lists below are what the parser finds.

The tables between the markers are generated from the parsed workbooks by `php artisan workbook:document`. Do not
edit them by hand — `WorkbookDocumentTest` fails if they disagree with the workbooks.

<!-- generated:seed-data:start — do not edit; run php artisan workbook:document -->

| Scope | Source | Activities | Total planned days | Planned finish |
| --- | --- | --- | --- | --- |
| Tower A | `SV_A3.xlsx` | 52 | 853 | 2028-08-05 |
| Tower B | `SV_B3.xlsx` | 52 | 843 | 2028-07-26 |
| Overall Timeline | `SV_Overall3.xlsx` | 47 | 825 | 2028-07-08 |

`projects.planned_start_date` = 2026-04-05 for all three.

### Durations where the name and the formula disagree

The duration written at the end of a description, compared with the offset its column C formula
computes. The formula wins; the description is imported unchanged.

| Scope | Row | Activity | Formula (C) | Named | Imported `offset_days` |
| --- | --- | --- | --- | --- | --- |
| Tower A | 7 | `2nd SLAB 20` | `=C5+20+20` | 20 | **40** |
| Tower A | 24 | `MASONRY 30` | `=C23+30-30` | 30 | **0** |
| Tower A | 27 | `PLUMBING AND WATERPROOFING 30` | `=C25+30+20` | 30 | **50** |
| Tower A | 29 | `ALL FINISHING WORK 90` | `=C28+60` | 90 | **60** |
| Tower A | 30 | `LIFT INSTALLATION 45` | `=C28+30` | 45 | **30** |
| Tower A | 31 | `GROUND LEVEL PLASTER 20` | `=C25+20+20` | 20 | **40** |
| Tower A | 36 | `INTERNAL PUTTY WORK 30` | `=C28+10` | 30 | **10** |
| Tower A | 38 | `EXT. TEXTURE / COLOUR WORK 30` | `=C28+20` | 30 | **20** |
| Tower A | 46 | `GROUND LEVEL PCC 10` | `=C28+15` | 10 | **15** |
| Tower A | 50 | `FIRE NOC AND LIFT NOC 45` | `=C49+20+30` | 45 | **50** |
| Tower B | 19 | `13th SLAB 20` | `=C18+30` | 20 | **30** |
| Tower B | 24 | `MASONRY 30` | `=C23+30-30` | 30 | **0** |
| Tower B | 27 | `PLUMBING AND WATERPROOFING 30` | `=C25+30+20` | 30 | **50** |
| Tower B | 29 | `ALL FINISHING WORK 90` | `=C28+60` | 90 | **60** |
| Tower B | 30 | `LIFT INSTALLATION 45` | `=C28+30` | 45 | **30** |
| Tower B | 31 | `GROUND LEVEL PLASTER 20` | `=C25+20+20` | 20 | **40** |
| Tower B | 36 | `INTERNAL PUTTY WORK 30` | `=C28+10` | 30 | **10** |
| Tower B | 38 | `EXT. TEXTURE / COLOUR WORK 30` | `=C28+20` | 30 | **20** |
| Tower B | 46 | `GROUND LEVEL PCC 10` | `=C28+15` | 10 | **15** |
| Tower B | 50 | `FIRE NOC AND LIFT NOC 45` | `=C49+20+30` | 45 | **50** |
| Overall Timeline | | *none* | | | |

### Date adjustments

Column D formulas that add days on top of `start + C`. Each is imported as `date_adjustment_days`
with `adjustment_reason = "carried from original sheet"`. They move that row's
`planned_date` only, never its `planned_day` or anything downstream.

| Scope | Row | Activity | Formula (D) | `date_adjustment_days` |
| --- | --- | --- | --- | --- |
| Tower A | 6 | `DIWALI (08-11-2026) BREAK 20` | `=E$2+C6+17` | +17 |
| Tower B | 8 | `DIWALI (08-11-2026) BREAK 20` | `=E$2+C8+12` | +12 |
| Tower B | 17 | `HOLI (22-03-2027) BREAK 30` | `=E$2+C17+7` | +7 |
| Overall Timeline | 26 | `FIRE RELATED WORK 30` | `=E$2+C26+7` | +7 |
| Overall Timeline | 27 | `FIRE NOC AND LIFT NOC 45` | `=E$2+C27+7` | +7 |

<!-- generated:seed-data:end -->

After seeding, every computed `planned_date` is asserted equal to the workbook's own cached column D value, and
every `planned_day` to column C. All 151. A single mismatch rolls the import back and fails the build
(`WorkbookImportTest` re-runs the comparison). That assertion is the migration's proof, and it is the last moment
an error is cheap to find.

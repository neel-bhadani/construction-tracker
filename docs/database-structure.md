# Database Structure — Construction Timeline Tracker

Companion to `system-design.md`. This file is the schema only: tables, columns, types, keys, constraints,
validation and the recalculation contract. Read `system-design.md` sections 2 and 3 first — they explain *why*
the schedule is a graph and what must be computed rather than stored.

MySQL 8, InnoDB, `utf8mb4_unicode_ci`.

---

## Overview

Many projects, each with many buildings. Seven tables, plus `users`.

```
projects ──< buildings ──< activities ──< activity_revisions
   │             │              │                   │
   │             │              └── predecessor_id ─┘ (self-reference)
   │             └──< building_revisions
   └──< project_revisions                            users ──< every *_revisions table
```

A **building** is one timeline: a tower, or a project's common (site-wide) work. Each has its own activities,
durations and start dates; common work is a peer of the buildings, never a roll-up of them.

The schedule is a **directed acyclic graph**, not a list. `activities.predecessor_id` points at another activity
in the same building. A flat "previous row + duration" model is wrong and will produce incorrect dates from the
first branch onward.

---

## projects

One row per client project. The first is Shaligram Vrundavan.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigint unsigned, auto | no | | PK |
| `owner_id` | bigint unsigned | no | | FK → `users.id`, `ON DELETE RESTRICT`. The one account that sees and changes it |
| `name` | varchar(150) | no | | Unique among the owner's projects that are not deleted |
| `details` | text | yes | null | Free text |
| `planned_start_date` | date | no | | **Only the default start of a new building.** Changing it moves nothing |
| `timezone` | varchar(64) | no | `Asia/Kolkata` | Decides "today" for the project's buildings |
| `created_by` | bigint unsigned | yes | null | FK → `users.id`, `ON DELETE SET NULL`. Null for the migrated project |
| `created_at` | timestamp | yes | | |
| `updated_at` | timestamp | yes | | |
| `deleted_at` | timestamp | yes | null | Soft delete |
| `active_name` | varchar(150), generated | yes | | `IF(deleted_at IS NULL, name, NULL)`, stored |

`UNIQUE (owner_id, active_name)`. MySQL lets any number of NULLs share a unique index, so a plain
`UNIQUE (owner_id, name, deleted_at)` would not stop two live projects of one owner sharing a name; the generated
column does, and frees a deleted project's name.

A project is deleted only once it has no buildings; the refusal names them.

---

## buildings

One row per timeline. Shaligram Vrundavan has three: Tower A and Tower B (`building`), Overall Timeline
(`common_work`). This is the former `scopes` table, renamed in place, so every id is unchanged.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigint unsigned, auto | no | | PK |
| `project_id` | bigint unsigned | no | | FK → `projects.id`, `ON DELETE CASCADE` |
| `name` | varchar(100) | no | | Unique per project among buildings that are not deleted |
| `type` | enum(`building`,`common_work`) | no | `building` | |
| `planned_start_date` | date | no | | The building's start: every planned date counts from it. Defaults to the project's |
| `actual_start_date` | date | yes | null | Falls back to `planned_start_date`. Drives average pace |
| `sort_order` | smallint unsigned | no | 0 | Order on the project page and in the building selector |
| `created_at` | timestamp | yes | | |
| `updated_at` | timestamp | yes | | |
| `deleted_at` | timestamp | yes | null | Soft delete: the timeline leaves the app, its rows and audit trail stay |
| `active_name` | varchar(100), generated | yes | | `IF(deleted_at IS NULL, name, NULL)`, stored |

Constraints:

- `UNIQUE (project_id, active_name)`
- Index on `(project_id, sort_order)`

Changing `planned_start_date` recalculates that building, and only that building. It is admin-only: it moves every
planned date in the building.

A deleted building's activities are not reachable by URL: activity route binding skips rows whose building is
deleted. A hard delete would fail while the predecessor chain is intact (RESTRICT on `activities.predecessor_id`);
null `predecessor_id` across the building first, in one transaction.

---

## activities

The core table. One row per activity, including holiday breaks.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigint unsigned, auto | no | | PK |
| `building_id` | bigint unsigned | no | | FK → `buildings.id`, `ON DELETE CASCADE`. Formerly `scope_id` |
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

- `(building_id, sort_order)` — the table view
- `(building_id, actual_date)` — progress and pace queries
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
| `activity_id` | bigint unsigned | yes | FK → `activities.id`, `ON DELETE SET NULL`. Null once the activity is deleted |
| `activity_snapshot_id` | bigint unsigned | no | The activity's id, written on every revision. No FK, never nulled: it is what tells two deleted activities' histories apart |
| `user_id` | bigint unsigned | no | FK → `users.id`, `ON DELETE RESTRICT` |
| `field` | varchar(40) | no | `actual_date`, `notes`, `offset_days`, `predecessor_id`, …, or `created` / `deleted` |
| `old_value` | text | yes | Text, not varchar: `notes` is text and is audited like any other field |
| `new_value` | text | yes | |
| `created_at` | timestamp | no | |

Index on `(activity_id, created_at)`.

Recorded for user-made changes only, one row per changed field, written by `ScheduleEditor`. Do not write a
revision row for the cascade — a single duration change would otherwise produce fifty rows nobody reads.
`planned_day` and `planned_date` are never audited; they are derived.

Revisions outlive their activity. An audit trail that vanished with the row would fail exactly when someone needs
it, so `activity_id` is nulled on delete rather than cascading, and the deletion itself is recorded as a `deleted`
revision whose `old_value` is the activity's description. `activity_snapshot_id` keeps the id after that, so the
history of each deleted activity can still be traced on its own; `activity_id` stays for joins to live rows.

---

## project_revisions

The audit trail for a project: `created`, `deleted`, and each change to `name`, `details`, `planned_start_date` or
`timezone`. Rows from before buildings existed also record the project's former `actual_start_date`.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | bigint unsigned, auto | no | PK |
| `project_id` | bigint unsigned | no | FK → `projects.id`, `ON DELETE CASCADE` |
| `user_id` | bigint unsigned | no | FK → `users.id`, `ON DELETE RESTRICT` |
| `field` | varchar(40) | no | |
| `old_value` | text | yes | |
| `new_value` | text | yes | |
| `note` | varchar(255) | yes | Why, where the change needs saying |
| `created_at` | timestamp | no | |

Index on `(project_id, created_at)`.

---

## building_revisions

The audit trail for a building: `created` (the note says whether its plan was copied or imported, and from where),
`deleted`, and each change to `name`, `type`, `sort_order`, `planned_start_date` or `actual_start_date`. A start-date
change moves every planned date in the building, so it is the row to look for when a whole timeline shifted.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | bigint unsigned, auto | no | PK |
| `building_id` | bigint unsigned | no | FK → `buildings.id`, `ON DELETE CASCADE` (buildings are soft-deleted, so this never fires in the app) |
| `user_id` | bigint unsigned | no | FK → `users.id`, `ON DELETE RESTRICT` |
| `field` | varchar(40) | no | |
| `old_value` | text | yes | |
| `new_value` | text | yes | |
| `note` | varchar(255) | yes | |
| `created_at` | timestamp | no | |

Index on `(building_id, created_at)`.

---

## users

Standard Laravel, plus:

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `phone` | varchar(30) | yes | null | Optional, from "My profile" |

There are no roles and no approval (`role` and `approved_at` were dropped by
`2026_10_25_000011_one_user_owner_only`). An account can do everything with the projects it owns, through
`projects.owner_id`, and every policy refuses anyone else's with a 404.

---

## Validation rules

Enforced in form requests, not only in the UI.

| Rule | Reason |
| --- | --- |
| `predecessor_id` must belong to the same `building_id` | A cross-building dependency would make common work depend on a tower |
| The predecessor graph must be acyclic | Reject on save, naming the activities in the cycle. Do not rely on a recursion limit |
| `offset_days` ≥ 0 | Negative would mean an activity precedes what it follows |
| `adjustment_reason` required when `date_adjustment_days` ≠ 0 | The workbook's nudges were invisible; these must not be |
| `actual_date` ≥ the building's `actual_start_date` (else its planned start) | |
| `actual_date` not in the future | It records something that happened |
| Deleting an activity with dependants is blocked | Name them. Silently re-pointing rewrites the schedule |
| Only `admin` may change `offset_days`, `predecessor_id`, `date_adjustment_days` | Policy-enforced, not hidden buttons |

---

## Recalculation contract

Run inside a transaction whenever any of these change: `offset_days`, `predecessor_id`,
`date_adjustment_days`, an activity is created or deleted, the building's `planned_start_date` changes, or a
plan is copied or imported into a new building. Changing a project's `planned_start_date` moves nothing; changing an
`actual_start_date` moves no planned date either (it drives pace).

```
1. Load all activities for the building.
2. Topologically sort by predecessor_id. Abort and report if a cycle exists.
3. For each in order:
       planned_day = predecessor ? predecessor.planned_day + offset_days
                                 : offset_days
4. planned_date = building.planned_start_date
                  + planned_day
                  + date_adjustment_days
5. Bulk update.
```

Recalculate the **whole building**, every time. Fifty-two rows is a few milliseconds. Do not build incremental
updates — a full recompute is simple enough to be obviously correct, and correctness is the entire reason this
replaces the spreadsheet.

Recording an `actual_date` does **not** trigger recalculation. Actuals never move planned dates; they change
progress, pace and the forecasts, all of which are computed at read time.

---

## Seed data

One project, Shaligram Vrundavan, with three buildings and 151 activities, imported by `WorkbookSeeder` from the
client's workbooks (`CLIENT_WORKBOOKS_DIR`, never the repository): Tower A and Tower B as `building`, Overall Timeline as `common_work`. The copies of
Tower A and Overall Timeline inside `SV_B3.xlsx` are identical to their own files and are ignored.

**Reproduce the workbook exactly, including its defects.** The chain is parsed from the formulas, never typed:
column C gives `predecessor_id` and `offset_days`, column D gives `date_adjustment_days`. Where a name disagrees
with its formula, the formula wins. Nothing is special-cased; the lists below are what the parser finds.

The tables between the markers are generated from the parsed workbooks by `php artisan workbook:document`. Do not
edit them by hand — `WorkbookDocumentTest` fails if they disagree with the workbooks.

<!-- generated:seed-data:start — do not edit; run php artisan workbook:document -->

| Building | Source | Activities | Total planned days | Planned finish |
| --- | --- | --- | --- | --- |
| Tower A | `SV_A3.xlsx` | 52 | 853 | 2028-08-05 |
| Tower B | `SV_B3.xlsx` | 52 | 843 | 2028-07-26 |
| Overall Timeline | `SV_Overall3.xlsx` | 47 | 825 | 2028-07-08 |

Each building's `planned_start_date` = 2026-04-05, the workbooks' `E2`.

### Durations where the name and the formula disagree

The duration written at the end of a description, compared with the offset its column C formula
computes. The formula wins; the description is imported unchanged.

| Building | Row | Activity | Formula (C) | Named | Imported `offset_days` |
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

| Building | Row | Activity | Formula (D) | `date_adjustment_days` |
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

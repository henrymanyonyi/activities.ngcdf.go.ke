# CLAUDE.md

Guidance for Claude Code in `activities.ngcdf.go.ke`, **NG-CDF FAPM**: the CEO's confidential Field Activity Planning and Monitoring system.

## Read first

- `docs/FRD-TRACEABILITY.md`: every FRD requirement and where it lives. The FRD (v1.2, RESTRICTED) is the specification; `docs/DESIGN-ASSESSMENT.md` is the earlier design and is overridden by the FRD where they differ.
- UI follows Smart's PAKA-RANGI standard (`../smart.ngcdf.go.ke/documentation/standards/PAKA-RANGI.md`). Use the components in `resources/views/components/ui/` and the class recipes in `App\Support\Ui`; do not hand-roll buttons, popups or page shells. No `wire:confirm` (use a confirm popup), no per-page `<style>`.

## Database safety — absolute

Never run `migrate:fresh`, `migrate:refresh`, `db:wipe`, `migrate:rollback` against a real database, or any `DROP`/`TRUNCATE`/unscoped `DELETE`, for any reason. Tests use SQLite `:memory:` (`phpunit.xml`). Schema changes are new, additive migrations. If a migration fails partway, stop and report the state; do not clean up by dropping anything. (Same rule as Smart NG-CDF, after the 2026-08-05 data loss there.)

## Confidentiality rules (FRD 7.12) — keep them true

- Exactly five roles: `ceo`, `chief_of_staff`, `assistant_chief_of_staff` (FRD §4.1); `admin`, the System Administrator, approved by the CEO on 11 October 2026 for settings, configuration and the access and audit log only; and `hod`, a Head of Department (activity memo workflow, October 2026; record the CEO's approval in the next FRD revision), one active account per department. Neither `admin` nor `hod` has `activities.view`: `admin` must never reach activity data, dashboards or any report but `access-audit`; `hod` reaches only their own department's memos (`Activity::scopeVisibleTo` / `isVisibleTo`, the `memos.*` routes and `DocumentController`), builds their team and costs on `Memos\Team` (overlaps flagged, never naming the other activity; no field-day totals), and sees the staff list with `staff.add` (adds officers one by one or by importing a list, corrects only those they added, never deactivates; `App\Services\StaffRecords`, `App\Services\StaffImporter`), and their own department's budget position (`BudgetPosition::forDepartment` / `forActivity`: allocation, spent, committed, pending, balance; on the memos board, memo form and memo page), never another department's, the register, dashboards, calendar or reports. Permissions are `RolesAndPermissionsSeeder::MATRIX`. Never add another role or a way to self-register.
- Every service method that changes data checks the user's permission itself; route middleware is not enough.
- Every view of an activity, export, print and download goes through `App\Services\AccessLogger`. Model changes are audited by the `Auditable` trait.
- Exports carry the RESTRICTED marking, the user and the time (`App\Exports\ReportExport`, `resources/views/reports/document.blade.php`).
- Files are written and read only through `App\Services\DocumentStore` (encrypted at rest).
- Nothing is permanently deleted: drafts are soft-deleted, everything else is cancelled; lists are deactivated or merged.
- No outbound integration, and in-app alerts only to the Office of the CEO and, about their own department's memos, its Head of Department (`App\Services\Alerts`; FRD 12, BR-11). One exception (25 Oct 2026): the Heads of Finance and Procurement receive the CEO's instructions, below.
- Finance and Procurement instructions (25 Oct 2026; record the CEO's approval in the next FRD revision): when approving, or later on an approved activity, only the CEO (`instructions.send`) instructs Finance to process the imprest and/or Procurement to procure the travel tickets, through `App\Services\ActionInstructions` only (one of each kind per activity; departments set in Settings › Thresholds, default Financial Services - Accounts and Supply Chain). That department's Head (`instructions.action`, `ActionInstruction::scopeFor` / `isFor`) sees an instruction sheet only: reference, dates, places, team, the CEO's note and the cost lines they act on (Procurement: travel and vehicle hire). Never the memo, its justification, reviews, documents, budget position or DSA lines for Procurement; `Activity::isVisibleTo` is unchanged. They acknowledge, then complete with a reference, which alerts the Office of the CEO and the memo's department.

## Domain rules

- `Activity::status` changes only through `App\Services\ActivityLifecycle`, which checks `ActivityStatus::allowedTransitions()`, locks the row and re-checks the status (so a double click or two users cannot apply a step twice), and writes the status history.
- Activity memo workflow: a Head of Department's memo goes Draft → Under review (with `review_office` ACOS or COS) → Awaiting decision; a reviewer can return it (Returned for correction, reason required) and it is resubmitted to the same office. `forward()` sends it on, via the Chief of Staff when Settings › Thresholds `acos_forwards_via_cos` is Yes and the Assistant forwards. Forwarding never approves. The Office of the CEO can prepare a memo on a Head of Department's behalf (`responsible_hod_id` / `responsible_hod_name`; `created_by` is the real preparer). Review comments are `ActivityReviewNote`, append-only. Board tabs, counts and the memo report come from `App\Services\MemoTracker`. User guides: `docs/guides/FAPM-Activity-Memo-Workflow-Guide.pdf`, and the 3-page `docs/guides/FAPM-Activity-Memo-Quick-Guide.pdf` (generated memo, availability, budget position).
- Availability (21 Oct 2026): an officer on an activity approved or later is unavailable on their own days; a Head of Department cannot override, the Office of the CEO only with a reason. Pending memos warn, drafts/declined/cancelled hold nobody. `App\Services\ParticipantChecks` (checked under a staff-row lock when adding and at approval). Recall/release/reassignment goes only through `App\Services\ParticipantReleases` (Office of the CEO; only computed DSA reduces automatically; never deletes the assignment).
- The memo PDF and annexes come only from `App\Services\MemoDocument` (one source for every figure); its `blockingIssues()` gate a Head of Department's submission, and the generated memo replaces the signed upload for department-originated memos.
- Budgeting (21 Oct 2026): department budgets, their lines and adjustments change only through `App\Services\Budgeting`. The CEO, Chief of Staff or Assistant prepares a draft (`budgets.prepare`); the Chief of Staff or Assistant submits it to the CEO; only the CEO approves or returns it for correction with a reason (`budgets.approve`); the CEO may approve a draft the CEO prepared directly. An approved budget changes only by adjustments (supplementary, reduction, reallocation; a line never below zero), with the same approval. Only approved budgets count; only their lines can be charged by a memo. Menu: Budgeting › Department budgets, Budget adjustments, Budget position. Not in Reference data, and not for `admin`.
- Activity report (21 Oct 2026): `activities:daily` starts approved activities on their start date and completes them after the end date; the report is due 7 working days later (`report_due_working_days`). The Head of Department submits it on the memo page with the actual amount spent on every cost line (counts as spent at once); the COS, ACOS or CEO accepts or returns it (`ActivityLifecycle::submitReport` / `acceptReport` / `returnReport`). On time or late is measured from the date first received (`Activity::reportTimeliness`, report `report-compliance`). No overdue reminder goes to the department (RP-03).
- Budget position figures come only from `App\Services\BudgetPosition` (allocation = approved budgets with approved adjustments; balance = allocation − spent − committed; pending never deducted; spent only from Finance's actual amounts).
- Details, locations, team and planned costs change through `App\Services\ActivityEditor`; after submission these become amendments and may return the activity to the CEO (BR-10).
- Stored totals on `activities` are maintained by `App\Services\ActivityCosting::refresh()`; DSA lines by `App\Services\DsaCalculator`.
- DSA (22 Oct 2026): one standard rate per job grade, whatever the destination (`DsaRate::STANDARD`). Job grades are the scales in the HR staff register (Job Scale 1 to 10, stored as the bare number, e.g. `6`, since 24 Oct 2026; `DsaRate::normaliseGrade` also reads `NGCDF 6`, `Scale 6`, `Job Group 6`), and INTERN at KES 4,000 for casuals and attachees without a grade), from 1 Jul 2026, synced by `SettingsSeeder` on every run. `DsaRate::lookup` reads only rates with no destination; the retired Cities / Other towns rows are history only. A county location only decides whether DSA applies. Completed, closed, declined and cancelled activities are never recalculated. Job grades are validated by `App\Rules\DsaGrade` / `DsaRate::normaliseGrade`.
- Generated documents (memo PDF and annexes, report PDF and print, Excel exports) use Gill Sans MT through `App\Support\DocumentFont`. The font is licensed with Microsoft Office, so its files are never committed: they go in `storage/app/fonts` (or `ACTIVITIES_DOCUMENT_FONTS`) on each server; without them PDFs fall back to DejaVu Sans.
- Money is integer cents in PHP (`App\Support\Money`) and DECIMAL(15,2) in the database. No float arithmetic (BR-09).
- Financial year is July to June (`App\Support\FinancialYear`). Dates display as `d M Y`.
- Configurable thresholds live in `App\Services\AppSettings` (editable in Settings › Thresholds).
- Reports are classes in `app/Reports/Definitions`, registered in `ReportCatalog`; one definition drives screen, Excel, PDF and print.
- Magic-link intake (`App\Livewire\Submit`, `ActivitySubmission`) is behind `ACTIVITIES_SUBMISSION_LINKS`, off by default, pending the CEO's confirmation. A submission is always a Draft.

## Commands

```bash
composer install && npm install && npm run build
php artisan migrate && php artisan db:seed          # see DatabaseSeeder: system data synced every run; editable config seeded once, never overwritten
php artisan activities:create-user ceo you@ngcdf.go.ke "Full Name"   # also chief_of_staff, assistant_chief_of_staff, admin; hod needs --department="ICT"
composer run dev                                      # serve + queue + logs + vite
php artisan test --compact                            # Pest, SQLite :memory:
vendor/bin/pint --dirty --format agent                # after changing PHP
php artisan schedule:work                             # locally: daily checks and Monday summary
```

@AGENTS.md

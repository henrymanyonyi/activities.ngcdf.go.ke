# CLAUDE.md

Guidance for Claude Code in `activities.ngcdf.go.ke`, **NG-CDF FAPM**: the CEO's confidential Field Activity Planning and Monitoring system.

## Read first

- `docs/FRD-TRACEABILITY.md`: every FRD requirement and where it lives. The FRD (v1.2, RESTRICTED) is the specification; `docs/DESIGN-ASSESSMENT.md` is the earlier design and is overridden by the FRD where they differ.
- UI follows Smart's PAKA-RANGI standard (`../smart.ngcdf.go.ke/documentation/standards/PAKA-RANGI.md`). Use the components in `resources/views/components/ui/` and the class recipes in `App\Support\Ui`; do not hand-roll buttons, popups or page shells. No `wire:confirm` (use a confirm popup), no per-page `<style>`.

## Database safety — absolute

Never run `migrate:fresh`, `migrate:refresh`, `db:wipe`, `migrate:rollback` against a real database, or any `DROP`/`TRUNCATE`/unscoped `DELETE`, for any reason. Tests use SQLite `:memory:` (`phpunit.xml`). Schema changes are new, additive migrations. If a migration fails partway, stop and report the state; do not clean up by dropping anything. (Same rule as Smart NG-CDF, after the 2026-08-05 data loss there.)

## Confidentiality rules (FRD 7.12) — keep them true

- Exactly five roles: `ceo`, `chief_of_staff`, `assistant_chief_of_staff` (FRD §4.1); `admin`, the System Administrator, approved by the CEO on 11 October 2026 for settings, configuration and the access and audit log only; and `hod`, a Head of Department (activity memo workflow, October 2026; record the CEO's approval in the next FRD revision), one active account per department. Neither `admin` nor `hod` has `activities.view`: `admin` must never reach activity data, dashboards or any report but `access-audit`; `hod` reaches only their own department's memos (`Activity::scopeVisibleTo` / `isVisibleTo`, the `memos.*` routes and `DocumentController`), builds their team and costs on `Memos\Team` (overlaps flagged, never naming the other activity; no field-day totals), and sees the staff list with `staff.add` (adds officers, corrects only those they added, via `App\Services\StaffRecords`), never the register, dashboards, calendar or reports. Permissions are `RolesAndPermissionsSeeder::MATRIX`. Never add another role or a way to self-register.
- Every service method that changes data checks the user's permission itself; route middleware is not enough.
- Every view of an activity, export, print and download goes through `App\Services\AccessLogger`. Model changes are audited by the `Auditable` trait.
- Exports carry the RESTRICTED marking, the user and the time (`App\Exports\ReportExport`, `resources/views/reports/document.blade.php`).
- Files are written and read only through `App\Services\DocumentStore` (encrypted at rest).
- Nothing is permanently deleted: drafts are soft-deleted, everything else is cancelled; lists are deactivated or merged.
- No outbound integration, and in-app alerts only to the Office of the CEO and, about their own department's memos, its Head of Department (`App\Services\Alerts`; FRD 12, BR-11).

## Domain rules

- `Activity::status` changes only through `App\Services\ActivityLifecycle`, which checks `ActivityStatus::allowedTransitions()`, locks the row and re-checks the status (so a double click or two users cannot apply a step twice), and writes the status history.
- Activity memo workflow: a Head of Department's memo goes Draft → Under review (with `review_office` ACOS or COS) → Awaiting decision; a reviewer can return it (Returned for correction, reason required) and it is resubmitted to the same office. `forward()` sends it on, via the Chief of Staff when Settings › Thresholds `acos_forwards_via_cos` is Yes and the Assistant forwards. Forwarding never approves. The Office of the CEO can prepare a memo on a Head of Department's behalf (`responsible_hod_id` / `responsible_hod_name`; `created_by` is the real preparer). Review comments are `ActivityReviewNote`, append-only. Board tabs, counts and the memo report come from `App\Services\MemoTracker`. User guide: `docs/guides/FAPM-Activity-Memo-Workflow-Guide.pdf`.
- Details, locations, team and planned costs change through `App\Services\ActivityEditor`; after submission these become amendments and may return the activity to the CEO (BR-10).
- Stored totals on `activities` are maintained by `App\Services\ActivityCosting::refresh()`; DSA lines by `App\Services\DsaCalculator`.
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

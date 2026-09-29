# CLAUDE.md

Guidance for Claude Code in `activities.ngcdf.go.ke`, **NG-CDF FAPM**: the CEO's confidential Field Activity Planning and Monitoring system.

## Read first

- `docs/FRD-TRACEABILITY.md`: every FRD requirement and where it lives. The FRD (v1.2, RESTRICTED) is the specification; `docs/DESIGN-ASSESSMENT.md` is the earlier design and is overridden by the FRD where they differ.
- UI follows Smart's PAKA-RANGI standard (`../smart.ngcdf.go.ke/documentation/standards/PAKA-RANGI.md`). Use the components in `resources/views/components/ui/` and the class recipes in `App\Support\Ui`; do not hand-roll buttons, popups or page shells. No `wire:confirm` (use a confirm popup), no per-page `<style>`.

## Database safety — absolute

Never run `migrate:fresh`, `migrate:refresh`, `db:wipe`, `migrate:rollback` against a real database, or any `DROP`/`TRUNCATE`/unscoped `DELETE`, for any reason. Tests use SQLite `:memory:` (`phpunit.xml`). Schema changes are new, additive migrations. If a migration fails partway, stop and report the state; do not clean up by dropping anything. (Same rule as Smart NG-CDF, after the 2026-08-05 data loss there.)

## Confidentiality rules (FRD 7.12) — keep them true

- Exactly three roles: `ceo`, `chief_of_staff`, `assistant_chief_of_staff`. Permissions are `RolesAndPermissionsSeeder::MATRIX` (FRD §4.1). Never add a role or a way to self-register.
- Every service method that changes data checks the user's permission itself; route middleware is not enough.
- Every view of an activity, export, print and download goes through `App\Services\AccessLogger`. Model changes are audited by the `Auditable` trait.
- Exports carry the RESTRICTED marking, the user and the time (`App\Exports\ReportExport`, `resources/views/reports/document.blade.php`).
- Files are written and read only through `App\Services\DocumentStore` (encrypted at rest).
- Nothing is permanently deleted: drafts are soft-deleted, everything else is cancelled; lists are deactivated or merged.
- No outbound integration and no alerts to anyone but the three users (FRD 12, BR-11).

## Domain rules

- `Activity::status` changes only through `App\Services\ActivityLifecycle`, which checks `ActivityStatus::allowedTransitions()` and writes the status history.
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
php artisan activities:create-user ceo you@ngcdf.go.ke "Full Name"   # also chief_of_staff, assistant_chief_of_staff
composer run dev                                      # serve + queue + logs + vite
php artisan test --compact                            # Pest, SQLite :memory:
vendor/bin/pint --dirty --format agent                # after changing PHP
php artisan schedule:work                             # locally: daily checks and Monday summary
```

@AGENTS.md

# NG-CDF FAPM — Field Activity Planning and Monitoring

**RESTRICTED: for the Office of the CEO only.** A standalone, confidential system used by exactly three people: the CEO, the Chief of Staff and the Assistant Chief of Staff. It records planned field activities captured from departmental memos, the CEO's decisions and directives, execution, back-to-office reports, and planned against actual cost.

- Specification: FRD v1.2 (`NGCDF_FAPM_FRD_v3.docx`, held by the Office of the CEO). Traceability: [`docs/FRD-TRACEABILITY.md`](docs/FRD-TRACEABILITY.md).
- Earlier design and decisions: [`docs/DESIGN-ASSESSMENT.md`](docs/DESIGN-ASSESSMENT.md).
- UI standard: Smart NG-CDF's PAKA-RANGI.

Stack: Laravel 13, PHP 8.3+, Livewire 3, Jetstream (Fortify, two-factor), Tailwind 3, spatie/laravel-permission, maatwebsite/excel, dompdf, spatie/laravel-backup, MySQL. Tests: Pest.

## Local setup

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate      # set DB_* (db_ngcdf_activities)
php artisan migrate && php artisan db:seed            # roles, 10 regions / 47 counties / 290 constituencies, reference lists
php artisan activities:create-user chief_of_staff cos@ngcdf.go.ke "Name"
php artisan activities:create-user ceo ceo@ngcdf.go.ke "Name"
composer run dev
```

On a local machine only, `ACTIVITIES_REQUIRE_TWO_FACTOR=false` skips the two-factor set-up. Never in staging or production.

## Naming and environments

| | |
|---|---|
| Repository | `NGCDF-Board/activities.ngcdf.go.ke` |
| Production | `activities.ngcdf.go.ke` (OI-11 open: hosting and address to be confirmed) |
| Staging | `staging-activities.ngcdf.go.ke` |
| Local | `activities-ngcdf.test` |
| Branches | `main` = Production, `staging` = Staging, `devs` = Development, `dev_<name>` per developer |

## Deployment checklist (FRD 7.12 and Section 8)

These are server responsibilities, not application code:

1. **HTTPS only** (CF-08); set `APP_URL` to the https address and `SESSION_SECURE_COOKIE=true`.
2. **Database encryption at rest** (CF-08): MySQL InnoDB tablespace encryption or an encrypted volume. Attachments are already encrypted by the application with `APP_KEY`; **back up `APP_KEY` separately and securely**, since without it the attachments cannot be read.
3. **Backups** (Section 8): `spatie/laravel-backup` runs daily from the scheduler. Set `BACKUP_ARCHIVE_PASSWORD` so archives are encrypted, point the backup disk off the server, and test a restore.
4. **Scheduler**: cron `* * * * * php artisan schedule:run`. It runs `activities:daily` (completion, commencement and overdue-report alerts), `activities:weekly-summary` (Monday) and the backups.
5. **Network** (CF-11, OI-15): restrict the virtual host to the Board network and VPN.
6. **Custodians** (CF-09, OI-14): only named ICT custodians get server or database access; nobody from ICT gets an application account.
7. `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_LIFETIME=15`, `SESSION_ENCRYPT=true`, `ACTIVITIES_REQUIRE_TWO_FACTOR=true`.
8. Leave `ACTIVITIES_SUBMISSION_LINKS=false` until the CEO confirms the magic-link intake route (it conflicts with FRD 2.2 as written).

## Tests

```bash
php artisan test --compact
```

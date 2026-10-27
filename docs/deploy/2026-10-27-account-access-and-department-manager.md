# Deployment: account access, password reset and department Manager (27 October 2026)

## What changes

| Area | Change |
|---|---|
| Promoted Heads of Department | An officer given access on an account that was not already active (promoted from the staff list, or an account made in User accounts) is emailed a link to set their own password. The one-time password is still shown once as a fallback. |
| Email password link | User accounts has **Email password link**: the user is emailed a link to set a new password; their current password works until they do. |
| Forgot password | A reset also ends a lockout after failed sign-ins and is recorded in the access log. The page answers the same whether or not the email has an account. |
| Sign-in page | Names the Office of the CEO and Heads of Department. |
| Promotion popup | When Head of Department is chosen, the popup always says what happens: **Replace the current Head** (with their name and email) when the department has one, or that the department has no Head to replace. It also names the email the new account will use, or warns that the officer has no email. |
| Demote and substitute a Head | Opening a Head's record and ending their role (no system access, another role, or a transfer) offers a **Successor as Head** from the department's staff: both changes are recorded together. |
| Scheduled changes | An effective date after today **schedules** the change instead of applying it: it applies on that date at 00:15 (`activities:apply-staff-changes`), removals first so a successor finds the department free. Scheduled changes show on the officer's record with **Cancel**, and a "Change scheduled" badge on the staff list. A change that can no longer apply on its date is marked failed and the person who scheduled it gets an alert. |
| Alerts | The bell checks every 15 seconds and shows a pop-up for each alert that arrives while the page is open. |
| Department Manager | Settings › Reference data › Departments: each department's Manager, an active officer of that department (a position only, no system access). Shown as a badge on the staff list; cleared when the officer leaves the department. |

## Steps

1. **Back up** the production database.
2. Pull the code (`main`), then `composer install --no-dev --optimize-autoloader`.
3. `php artisan migrate --force`. Two new migrations: `2026_10_27_085434_add_manager_to_departments_table` (adds the nullable `departments.manager_staff_id`) and `2026_10_27_095657_create_scheduled_staff_changes_table` (new table). No data is changed.
4. `npm ci && npm run build`.
5. Clear and rebuild the caches, or the old views, routes and config keep being served:
   `php artisan optimize:clear && php artisan optimize`
6. Restart PHP-FPM (or reload the web server) so OPcache drops the old code, and `php artisan queue:restart` for the queue workers.
7. The scheduler must run every minute (cron: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`), or scheduled staff changes never apply. Check with `php artisan schedule:list`.
8. Check mail is configured in `.env` (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_FROM_ADDRESS`): the password links are sent by email. `php artisan config:show mail.default` must not be `log` in production.

## Check after deploying

- Settings › Reference data › Departments shows "Manager: not set" on each department.
- User accounts shows **Email password link** on each account.
- Promoting someone to Head of a department with no Head shows "has no active Head of Department now".
- The sign-in page reads "For the Office of the CEO and Heads of Department only."

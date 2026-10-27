# Deployment: account access, password reset and department Manager (27 October 2026)

## What changes

| Area | Change |
|---|---|
| Promoted Heads of Department | An officer given access on an account that was not already active (promoted from the staff list, or an account made in User accounts) is emailed a link to set their own password. The one-time password is still shown once as a fallback. |
| Email password link | User accounts has **Email password link**: the user is emailed a link to set a new password; their current password works until they do. |
| Forgot password | A reset also ends a lockout after failed sign-ins and is recorded in the access log. The page answers the same whether or not the email has an account. |
| Sign-in page | Names the Office of the CEO and Heads of Department. |
| Department Manager | Settings › Reference data › Departments: each department's Manager, an active officer of that department (a position only, no system access). Shown as a badge on the staff list; cleared when the officer leaves the department. |

## Steps

1. **Back up** the production database.
2. Pull the code (`main`), then `composer install --no-dev --optimize-autoloader`.
3. `php artisan migrate --force`. One new migration, `2026_10_27_085434_add_manager_to_departments_table` (adds the nullable `departments.manager_staff_id`). No data is changed.
4. `npm ci && npm run build`.
5. Clear and rebuild the caches, or the old views, routes and config keep being served:
   `php artisan optimize:clear && php artisan optimize`
6. Restart PHP-FPM (or reload the web server) so OPcache drops the old code, and `php artisan queue:restart` for the queue workers.
7. Check mail is configured in `.env` (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_FROM_ADDRESS`): the password links are sent by email. `php artisan config:show mail.default` must not be `log` in production.

## Check after deploying

- Settings › Reference data › Departments shows "Manager: not set" on each department.
- User accounts shows **Email password link** on each account.
- The sign-in page reads "For the Office of the CEO and Heads of Department only."

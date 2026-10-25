-- =====================================================================
-- FAPM (oceo.ngcdf.go.ke) production update, 25 October 2026
-- Every casual officer on DSA scale 10 (KES 6,300 per night/day).
-- =====================================================================
--
-- FOR REVIEW. Not run by deployment. An authorised operator runs it by
-- hand, after a full backup, in a MySQL client connected to the FAPM
-- production database (MySQL 8). Read every step's output before the next.
--
-- Schema it relies on (from the code, database/migrations):
--   staff.employment_type   'permanent' | 'casual' | 'attachee' (App\Enums\EmploymentType)
--   staff.job_grade         '1'..'10' or 'INTERN'; DSA is costed at this grade (App\Models\DsaRate::gradeFor)
--   dsa_rates               job_grade, destination_category (NULL = standard rate), amount, effective_from, effective_to
--   audit_logs              the application's change log (App\Services\AuditLogger)
--
-- What it does:
--   1. Shows how many casuals there are and on which grades.
--   2. Makes sure the standard scale 10 rate (KES 6,300 from 1 July 2026) exists, once.
--   3. Keeps each changed casual's previous grade in a backup table.
--   4. Records each change in audit_logs, then sets job_grade = '10'.
--   5. Verifies the result.
--
-- What it never touches: permanent staff, attachees, activity_participants
-- (each activity keeps the grade its officers had when they joined it),
-- activity_costs, approved or completed activities, payments.
--
-- Safe to rerun: a casual already on '10' is not selected again, the rate is
-- only inserted when missing, and the backup keeps the first value it saw.
--
-- After it: php artisan activities:refresh-pending-dsa --dry-run, then without
-- --dry-run, to bring memos not yet approved up to date (see docs/deploy/README.md).
-- =====================================================================

-- ---------------------------------------------------------------------
-- Step 1. Preview (read only)
-- ---------------------------------------------------------------------
SELECT COUNT(*) AS casual_staff_total
FROM staff
WHERE employment_type = 'casual';

SELECT COALESCE(job_grade, '(none)') AS job_grade_now, COUNT(*) AS casuals
FROM staff
WHERE employment_type = 'casual'
GROUP BY job_grade
ORDER BY job_grade;

SELECT COUNT(*) AS casuals_to_update
FROM staff
WHERE employment_type = 'casual'
  AND (job_grade IS NULL OR job_grade <> '10');

-- The standard scale 10 rate(s) on record. Expect one row: 10, NULL, 6300.00, 2026-07-01.
SELECT id, job_grade, destination_category, amount, effective_from, effective_to
FROM dsa_rates
WHERE job_grade = '10' AND destination_category IS NULL
ORDER BY effective_from;

-- ---------------------------------------------------------------------
-- Steps 2 to 4. The change, in one transaction
-- ---------------------------------------------------------------------
START TRANSACTION;

-- Step 2. The standard scale 10 rate, only if it is missing (the application's
-- SettingsSeeder adds the same row; this keeps the script complete on its own).
INSERT INTO dsa_rates (job_grade, destination_category, amount, effective_from, effective_to, is_provisional, recorded_by, created_at, updated_at)
SELECT '10', NULL, 6300.00, '2026-07-01', NULL, 0, NULL, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM dsa_rates
    WHERE job_grade = '10' AND destination_category IS NULL AND effective_from = '2026-07-01'
);

-- Step 3. Backup of each casual's grade before the change (kept after the update for recovery).
CREATE TABLE IF NOT EXISTS staff_dsa_grade_backup_20261025 (
    staff_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    job_grade_before VARCHAR(20) NULL,
    backed_up_at TIMESTAMP NULL
);

INSERT IGNORE INTO staff_dsa_grade_backup_20261025 (staff_id, job_grade_before, backed_up_at)
SELECT id, job_grade, NOW()
FROM staff
WHERE employment_type = 'casual'
  AND (job_grade IS NULL OR job_grade <> '10');

-- Step 4a. One audit_logs row per officer changed, as the application writes them.
INSERT INTO audit_logs (auditable_type, auditable_id, event, old_values, new_values, user_id, actor_name, ip_address, user_agent, created_at)
SELECT 'App\\Models\\Staff', id, 'updated',
       JSON_OBJECT('job_grade', job_grade),
       JSON_OBJECT('job_grade', '10'),
       NULL, 'Production update 25 Oct 2026: casual staff on DSA scale 10', NULL, NULL, NOW()
FROM staff
WHERE employment_type = 'casual'
  AND (job_grade IS NULL OR job_grade <> '10');

-- Step 4b. The update.
UPDATE staff
SET job_grade = '10', updated_at = NOW()
WHERE employment_type = 'casual'
  AND (job_grade IS NULL OR job_grade <> '10');

SELECT ROW_COUNT() AS casual_records_updated;

-- Check before committing: casuals_not_on_10 must be 0, and the other counts unchanged from Step 1.
SELECT
    (SELECT COUNT(*) FROM staff WHERE employment_type = 'casual' AND (job_grade IS NULL OR job_grade <> '10')) AS casuals_not_on_10,
    (SELECT COUNT(*) FROM staff WHERE employment_type = 'casual') AS casual_staff_total;

-- If the numbers are right:
COMMIT;
-- If anything looks wrong, run ROLLBACK; instead of COMMIT; and nothing is changed.

-- ---------------------------------------------------------------------
-- Step 5. Verification (read only)
-- ---------------------------------------------------------------------
-- Every casual resolves to scale 10 at KES 6,300 (expect every row: 10, 6300.00).
SELECT s.job_grade, r.amount, COUNT(*) AS casuals
FROM staff s
LEFT JOIN dsa_rates r
       ON r.job_grade = s.job_grade
      AND r.destination_category IS NULL
      AND r.effective_from <= CURDATE()
      AND (r.effective_to IS NULL OR r.effective_to >= CURDATE())
WHERE s.employment_type = 'casual'
GROUP BY s.job_grade, r.amount;

-- No permanent officer or attachee was changed today by this script (expect 0).
SELECT COUNT(*) AS non_casuals_changed
FROM audit_logs a
JOIN staff s ON s.id = a.auditable_id
WHERE a.auditable_type = 'App\\Models\\Staff'
  AND a.actor_name = 'Production update 25 Oct 2026: casual staff on DSA scale 10'
  AND s.employment_type <> 'casual';

-- How many were changed, from which grades.
SELECT COALESCE(job_grade_before, '(none)') AS job_grade_before, COUNT(*) AS casuals
FROM staff_dsa_grade_backup_20261025
GROUP BY job_grade_before;

-- ---------------------------------------------------------------------
-- Recovery (only if the change must be undone, after COMMIT)
-- ---------------------------------------------------------------------
-- Puts each changed casual back on the grade they had, from the backup, and
-- records it in audit_logs. Leave the scale 10 rate row: it is the approved
-- standard rate and the application keeps it anyway. Then run
-- php artisan activities:refresh-pending-dsa again.
--
-- START TRANSACTION;
-- INSERT INTO audit_logs (auditable_type, auditable_id, event, old_values, new_values, user_id, actor_name, ip_address, user_agent, created_at)
-- SELECT 'App\\Models\\Staff', b.staff_id, 'updated', JSON_OBJECT('job_grade', s.job_grade), JSON_OBJECT('job_grade', b.job_grade_before),
--        NULL, 'Recovery of the 25 Oct 2026 casual DSA update', NULL, NULL, NOW()
-- FROM staff_dsa_grade_backup_20261025 b JOIN staff s ON s.id = b.staff_id;
-- UPDATE staff s JOIN staff_dsa_grade_backup_20261025 b ON b.staff_id = s.id
-- SET s.job_grade = b.job_grade_before, s.updated_at = NOW();
-- COMMIT;
--
-- The full backup taken before the script is the last resort.

# Deployment: memo template, direct routing, DSA nights and casual staff (25 October 2026)

## What changes

| Area | Change |
|---|---|
| Memo routing (confirmed 26 Oct 2026) | A memo always goes **Head of Department → Assistant or Chief of Staff → CEO**. There is no direct route to the CEO; the Assistant's memos pass the Chief of Staff whenever Settings say so. |
| Email heading | An action-needed email from an action taken by the CEO, the Chief of Staff or the Assistant (a return, a forward, a decision, an instruction, a returned report or budget) is headed **OFFICE OF THE CHIEF EXECUTIVE OFFICER** and sent as "Office of the Chief Executive Officer, NG-CDF Board". Emails from a Head of Department's or an officer's action, and the automatic reminders, keep the FAPM heading. |
| Instructions | After approving, the CEO instructs the departments that must act: Finance (imprest), Procurement (tickets) and **any other department** with a written instruction (e.g. Administration: provide a vehicle). Its Head gets the instruction sheet (instruction, dates, places, team; no memo, no costs), acknowledges it and records what was done. Instructions are checked before the approval is recorded. |
| Signing | The Head signs with their designation from the staff list and department, e.g. **Manager, ICT**. Ticking **I am signing in an acting capacity** prints **Ag. Manager, ICT**. Stored on the memo (`activities.hod_designation`, `hod_acting`); the officer's record never changes. |
| Memo PDF | Rebuilt on `files/MEMO Template .docx`: US Letter, the template's margins, logo and Board name, INTERNAL MEMO, TO / FROM / DATE / REF NO / SUBJECT, numbered sections with the risks as bullets, the four signature blocks, Annex: A (Activity Budget Request, with the signatures repeated) and Participants. Sample: `docs/samples/FAPM-Sample-Activity-Memo.pdf`. |
| Date issued and reference | On a Head of Department's memo, **Memo reference** and **Date issued** are required (form and server). The memo prints them as REF NO and DATE. A memo the Office of the CEO records keeps **Date received**. Older memos have no issue date and print the date they were submitted, as before. |
| DSA nights | One rule (`App\Support\DsaPeriod`): an activity of two days or more pays the **departure night before the start** and every night to the end date (26 Oct to 4 Nov = 10 days, **11 nights**); a one-day activity pays none. With the "days" basis in Settings, DSA is per activity day. Recalculated whenever the dates change. |
| Casual staff | Every casual is on **DSA scale 10, KES 6,300** (`EmploymentType::defaultDsaGrade`). The importer and the staff form record casuals as 10 (blank or INTERN become 10; another scale is refused). Attachees stay on INTERN (KES 4,000) unless graded. |

## Steps

1. **Back up** the production database.
2. Deploy the code, then `php artisan migrate --force`. Three new migrations, including `add_from_office_to_outbound_messages_table` (the email heading below). The other two: `2026_10_25_125148_add_issue_date_and_signing_capacity_to_activities_table` (adds `issued_on`, `hod_designation`, `hod_acting`) and `2026_10_25_131929_allow_instructions_to_any_department` (the unique index on `action_instructions` becomes activity, kind and department; the new index is added before the old one is removed). No data is changed.
3. `php artisan db:seed --force` (syncs the standard DSA rates, including scale 10 at 6,300).
4. `npm run build`.
5. **Casual staff:** an authorised operator reviews and runs `docs/deploy/2026-10-25-casual-staff-dsa-scale-10.sql` by hand, reading each step's output, and commits only if the check shows `casuals_not_on_10 = 0`. It keeps each previous grade in `staff_dsa_grade_backup_20261025` and writes `audit_logs`. Recovery steps are at the end of the file.
6. **Pending memos:** `php artisan activities:refresh-pending-dsa --dry-run`, check the list, then `php artisan activities:refresh-pending-dsa`. It recalculates DSA only on memos not yet approved (drafts, with the Head, under review, awaiting or returned by the CEO): casuals on their teams move to scale 10 and the departure night is added. Approved, running, completed, closed, declined and cancelled activities are never touched.
7. Install Gill Sans MT on the server if not done (`storage/app/fonts`), or PDFs fall back to DejaVu Sans.

## Not changed

- Approved activities keep the DSA the CEO approved. An approved activity's DSA is recalculated under the new rule only if its dates or team are amended later (as any amendment recalculates), and an amendment beyond tolerance returns it to the CEO.
- Activity participants keep the grade they had when added (snapshot); only the refresh command changes it, and only on pending memos.
- Completed activities, actual amounts and Finance references are never recalculated.

## Decisions for the business owner

1. **Font.** The template is in Trebuchet MS; FAPM documents use Gill Sans MT as instructed on 24 October 2026. Changing it is one constant (`App\Support\DocumentFont`).
2. **One-day activities.** They pay no night. If a one-day activity away from the duty station should pay the departure night, that needs a rule (for example a per-activity "overnight" choice).
3. **Approved activities** keep the old night count unless amended. Say if they should be recalculated too (they would return to the CEO beyond tolerance).
4. **Instructions to any department**: record in the next FRD revision that the Head of any department the CEO instructs sees the instruction sheet (not the memo).

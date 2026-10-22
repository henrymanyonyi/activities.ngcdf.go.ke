# HR staff register import (October 2026)

Onboarding the HR registers of 21 October 2026 (permanent staff, casuals and attachees) into the FAPM staff list.

| File | What it is | In Git |
|---|---|---|
| `docs/hr-import/HR-Staff-Import-Template.xlsx` | Blank unified template for future HR imports (Staff, Instructions, Lists sheets) | Yes (no personal data) |
| `files/hr-import/HR-Staff-Import-2026-10.xlsx` | Completed production import workbook: 510 officers from both registers | **No** (`/files` is ignored) |
| `files/hr-import/HR-Staff-Import-2026-10-Reconciliation.xlsx` | Reconciliation: summary counts, every review flag by source row, unit mapping | **No** |
| `files/hr-import/scripts/` | The scripts that built and independently verified the production workbook | **No** |

The source registers in `files/` were read only and are unchanged (checksums compared before and after).

## A. Existing implementation, and what changed

**Before.** `App\Services\StaffImporter` already read Excel and CSV (first sheet, heading row) with the columns `PF Number, Name, Designation, Job Grade, Department, Duty Station, Email, Phone, Active`. PF Number and Name were required. It matched existing officers by PF number and updated them, checked every row before saving, and saved inside one transaction (a file never imports partially). Importing staff never creates user accounts, roles or alerts.

**Gaps found.**

1. No way to record casual or attachee staff, and PF Number was mandatory. The temporary register has no PF or ID numbers.
2. No contract end date or appointment date on import (`staff.joined_on` existed but import never set it).
3. Department and Duty Station went through `resolveByName`, which **creates** a department or duty station for any unknown name. A misspelling would have added a 17th department, which affects Heads of Department and budgets.
4. No way to check a file before importing.
5. The import reader skipped blank rows and renumbered the rest, so error row numbers could be wrong.
6. The source names (`Mr. Surname, Given names`), job scale (`6`), and units (vote head / sub head) do not match the system's formats.

**Changes (minimum needed).**

- Migration `2026_10_22_101755_add_employment_type_to_staff_table` (additive): `staff.employment_type` (`permanent` default, `casual`, `attachee`) and `staff.contract_ends_on`. Existing records become Permanent.
- `App\Enums\EmploymentType`. Only Permanent requires a PF number.
- `StaffImporter`: new columns `Employment Type`, `Date Joined`, `Contract End Date`.
  - PF optional for casuals and attachees, who are matched by exact name among officers without a PF number. A name matching two such officers is rejected. A namesake who has a PF number is never merged.
  - Unknown Department or Duty Station is rejected, not created.
  - Dates are read from Excel date cells or text (`YYYY-MM-DD`, `DD/MM/YYYY`, `7 June 2016`), and impossible dates are rejected.
  - Row numbers match the file.
  - A dry run (`dryRun: true`) checks everything and saves nothing.
  - A blank Employment Type keeps an existing officer's type. A blank cell still never erases existing data.
- Staff list screen: **Check file** button in the import popup. Employment type and contract end date added to the officer form, where PF number is required only for permanent staff. A Casual or Attachee badge shows in the list.
- `StaffRecords` accepts a blank PF number (stored as null; the unique index allows many nulls).

Old-format files (the nine original columns) still import exactly as before (`tests/Feature/SampleFilesTest.php`).

## B. Field mapping

Unified template columns: `PF Number, Name, Employment Type, Designation, Job Grade, Department, Duty Station, Email, Phone, Date Joined, Contract End Date, Active`. The production workbook adds trace columns to the right (`Source File, Source Sheet, Source Row, Source Name, Source Unit, Source Status, Review Flags`); the importer ignores them.

### Permanent register (sheet `StaffRegister_2026-10-21`, 23 columns)

| Source column | Target | Rule |
|---|---|---|
| UPN / Payroll No | PF Number (required) | `="2001234567"` text formula unwrapped; written as a text cell (10 and 11 digits kept) |
| Full Name | Name (required) | `Mr. Otieno, John Kamau` becomes `John Kamau Otieno` (title dropped, given names first; decision 22 Oct 2026). The original is kept in Source Name |
| (none) | Employment Type | `Permanent` |
| Designation | Designation | As written (spaces tidied); 49 new titles join the designation list |
| Job Scale | Job Grade | `6` becomes `NGCDF 6` (**HR to confirm Job Scale = NGCDF grade**) |
| Sub Head (with Vote Head) | Department | See unit table below |
| Duty Station, Work County | Duty Station | `CDF Headquarters - Nairobi` becomes `Headquarters`; otherwise the region of the work county (from `database/data/geography.json`; Nairobi = Nairobi Region) |
| Contract End Date | Contract End Date | Date cell |
| Employment Status | Active | `0 - Active` → Yes; `Contract Ended: …` → No; `On Interdiction`, `On Suspension` → No (flagged); `On Secondment` → Yes (flagged) |
| ID Number | not imported | Used only to check for duplicates during reconciliation (none) |
| Tax PIN, Gender, Special Need / PWD, NCPWD Reg No, ROD, Ward, Village, Local Authority, Sub County, Constituency, MDAC / Vote, Sub Vote | not imported | Not used by FAPM; left out on purpose (data minimisation, decision 22 Oct 2026). ROD was used only to flag active officers past retirement (none) |
| (none) | Email, Phone, Date Joined | Blank: not in the register |

Unit → department: Field Operations and Programmes & Field Services Coordination → Programmes; Field Financial Services Coordination → Field Financial Coordination; Administration → Administration; Human Resource, and the head of Human Resource & Administration → Human Resources (flagged); Internal Audit → Internal Audit; Board Secretariat/Legal Services → Legal Services; Monitoring & Evaluation, Research & Statistics, Risk Management & Compliance → their departments; Corporate ICT Strategy → ICT; Corporate Communication, Media Relations → Corporate Communications; Planning & Strategy and Corporate Planning & Strategy → Corporate, Planning & Strategy; Finance & Accounts and Headquarter Financial Services → Financial Services - Accounts; Supply Chain → Supply Chain.

### Temporary register (sheets `CASUALS`, `ATTACHEES`)

| Source column | Target | Rule |
|---|---|---|
| (sheet) | Employment Type | `CASUALS` → Casual, `ATTACHEES` → Attachee |
| Name / NAME | Name | As written (spaces tidied) |
| (none) | PF Number | Blank: the register has none |
| Department/Division/Region | Department, Duty Station | HQ units map to their department (`CPS` → Corporate, Planning & Strategy, `Financial Services - Field` → Field Financial Coordination, `Financial Services - HQ` → Financial Services - Accounts, `Programmes & Field Services` / `Programmes & FSC - Architecture` → Programmes, `Administration (Registry)` → Administration). A region (`Western Region`, `North Eastern`, …) → Department Programmes and Duty Station that region (flagged). `Eastern Region` → Programmes, Duty Station blank (Upper or Lower?). `Digitization Programme` → Department blank |
| Date of First Appointment / ENGAGEMENT START | Date Joined | Text date (`7th June 2016`) converted to a date cell |
| Contract Expiry Date / ENGAGEMENT STOP | Contract End Date | Same |
| Contract / Engagement Status | Active | Active, Awaiting Renewal → Yes (Awaiting Renewal flagged); Resigned → No |
| # | not imported | Serial number only |

## E. Reconciliation (counts only; names are in the confidential workbook)

| Source | Rows with data | In import workbook |
|---|---|---|
| Permanent register | 439 | 439 |
| Temp: CASUALS | 54 | 54 |
| Temp: ATTACHEES | 17 | 17 |
| **Total** | **510** | **510** |

Not carried over: one fully blank row (ATTACHEES row 21). No other row was dropped, merged or invented.

- PF numbers: 439 present, 439 unique. National ID numbers: 439 unique. Exact duplicate names across the combined list: none.
- One attachee shares two names with a permanent officer: flagged on both rows as a possible match, **not merged**.
- Active: 496 Yes, 14 No (11 contract ended, 1 interdiction, 1 suspension, 1 casual resigned).
- An independent check read the workbook back and compared every row with its source row: PF numbers, every word of every name, designation, grade, dates, active status, type. **0 differences.** It also confirmed every PF cell is text and that no ID number or KRA PIN appears anywhere in the output.

**For HR to confirm before the import** (details per row in the Reconciliation workbook, sheet Review Flags):

| Count | Issue | Imported as |
|---|---|---|
| all 439 | Job Scale is the NGCDF grade (`6` = `NGCDF 6`) | NGCDF n |
| 33 | Grades NGCDF 9 (29) and NGCDF 10 (4) have no DSA rate (provisional rates cover NGCDF 1 to 8) | Imported; DSA shows "No DSA rate" until Finance adds the rates |
| 71 | Casuals and attachees have no PF or ID number | Blank PF; matched by name on re-import |
| 14 | Casuals "Awaiting Renewal" whose contract end date has passed | Active; set inactive if not renewed |
| 2 | Attachees "Active" whose engagement end date has passed | Active |
| 1 | Permanent officer "Active" whose contract end date has passed | Active |
| 15 | Temp unit is a region | Department Programmes, Duty Station the region |
| 4 | Temp unit "Eastern Region" (Upper or Lower?) | Programmes, Duty Station blank |
| 8 | Temp unit "Digitization Programme" matches no department | Department blank |
| 3 | Permanent: Duty Station blank in the register | Duty Station blank |
| 3 | Permanent: Contract End Date blank | Blank |
| 2 | Permanent: On Interdiction / On Suspension | Inactive |
| 1 | Permanent: On Secondment | Active |
| 1 | Head of Human Resource & Administration | Human Resources |
| 4 | Names as written in the register: stray `.`, a backtick, a typographic apostrophe | As written |
| 2 | Possible same person (attachee and permanent officer share two names) | Separate records |

Leave the department blank, or put a department in the production workbook, as HR decides. Every flag can also be corrected on screen after the import.

## F. Implementation and testing

Tests: `tests/Feature/Domain/StaffImportTest.php` (10 tests, synthetic data only, including the `.xlsx` fixture `tests/Fixtures/hr-staff-list-unified.xlsx` with a text PF cell and Excel date cells). Together with `SampleFilesTest`, `HodTeamAndStaffTest` and `DatabaseSeederTest`, 29 tests and 152 assertions pass. Covered:

- import of permanent, casual and attachee rows
- type assignment and leading zeros
- missing PF and name, unknown type, department and duty station
- invalid and impossible dates, end before start
- duplicate PF numbers and duplicate PF-less names
- correct row numbers with blank rows
- updates by PF number and by name
- an ambiguous name match, and a namesake with a PF number not merged
- blank optional fields not erasing data, inactive status
- the dry run saving nothing, the on-screen check
- PF required on screen only for permanent staff
- the old nine-column format and the Head of Department rules unchanged

Dry run with the real production workbook against a throwaway SQLite in-memory database (migrated and seeded; no real database used):

- Check file: 510 would be added, nothing saved.
- Import: 510 added (439 permanent, 54 casual, 17 attachee), 0 errors. No department or duty station created, no user account created.
- Re-import of the same file: 0 added, 510 matched and updated, no duplicates, 0 audit entries (nothing changed).

### Steps for the approved production import

1. HR reviews `HR-Staff-Import-2026-10-Reconciliation.xlsx` (Review Flags) and corrects the production workbook where needed. Leave the trace columns in place.
2. Deploy this change and run `php artisan migrate` (adds two columns; nothing is dropped). Back up the database first, as for any release.
3. Signed in as the Chief of Staff or Assistant Chief of Staff: Staff list › **Import HR list** › choose `HR-Staff-Import-2026-10.xlsx` › **Check file**. Expect "510 would be added" on an empty list. If the list already holds officers, some will show as updated instead. Any row error is listed with its row number, and nothing is saved.
4. Click **Import**. Then filter the list by Inactive and by "Active, no job grade" to spot-check, and correct anything flagged on screen.
5. Delete working copies of the production workbook outside the server once done.

The import creates staff records only; it gives nobody a login and sends no alerts. Officers already on the list with a PF number are updated from the register. Officers not in the register are left as they are.

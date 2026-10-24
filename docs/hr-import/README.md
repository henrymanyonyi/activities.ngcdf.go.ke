# HR staff register import (October 2026)

Onboarding the HR registers of 21 October 2026 (permanent staff, casuals and attachees) into the FAPM staff list.

| File | What it is | In Git |
|---|---|---|
| `docs/hr-import/HR-Staff-Import-Template.xlsx` | Blank unified template for future HR imports (Staff, Instructions, Lists sheets) | Yes (no personal data) |
| `files/hr-import/HR-Staff-Import-2026-10-v3.xlsx` | **Use this one.** v3 (24 Oct 2026): as v2, with each permanent officer's Job Grade set to their Job Scale from the HR register (section H) | **No** |
| `files/hr-import/HR-Staff-Import-2026-10-v2.xlsx` | v2 (22 Oct 2026): as v1, with Job Grade INTERN for casuals and attachees and blank for permanent staff (section G) | **No** (`/files` is ignored) |
| `files/hr-import/HR-Staff-Import-2026-10.xlsx` | v1: the first completed workbook (Job Grade from the register's Job Scale). Kept unchanged | **No** |
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

- `staff.employment_type` (`permanent` default, `casual`, `attachee`) and `staff.contract_ends_on`. Existing records become Permanent.
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
| Job Scale | Job Grade | v1: `6` became `NGCDF 6`. **v2: left blank**; permanent staff are graded in the system after the import (section G) |
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
| (none) | Job Grade | v2: `INTERN` (the intern DSA rate), for casuals and attachees alike |
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
| all 439 | Permanent staff have no job grade in v2 | Graded in the system after the import (section G) |
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

1. HR reviews `HR-Staff-Import-2026-10-Reconciliation.xlsx` (Review Flags) and corrects the **v2** workbook where needed. Leave the trace columns in place.
2. Deploy this change to a database built from the consolidated migrations (see Deployment notes) and run `php artisan db:seed` (sets the standard DSA rates).
3. Signed in as the Chief of Staff or Assistant Chief of Staff: Staff list › **Import HR list** › choose `HR-Staff-Import-2026-10-v2.xlsx` › **Check file**. Expect "510 would be added" on an empty list. If the list already holds officers, some will show as updated instead. Any row error is listed with its row number, and nothing is saved.
4. Click **Import**. Then filter the list by Inactive and by "Active, no job grade" to spot-check, and correct anything flagged on screen.
5. Delete working copies of the production workbook outside the server once done.

The import creates staff records only; it gives nobody a login and sends no alerts. Officers already on the list with a PF number are updated from the register. Officers not in the register are left as they are.

## G. Standard DSA rates and workbook v2 (22 October 2026)

### DSA: one rate per job grade

The Cities / Other towns model is retired. DSA is now the standard rate for the officer's job grade, wherever the activity is:

| Job grade (scale) | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | INTERN |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| KES per night (or day) | 18,200 | 16,800 | 16,800 | 14,000 | 14,000 | 11,200 | 11,200 | 11,200 | 6,300 | 6,300 | 4,000 |

- **Source of truth:** `DsaRate::STANDARD`, effective from 1 July 2026. It is written to `dsa_rates` by `SettingsSeeder` on every run. A missing rate is added, a changed amount is put back, and nothing is duplicated.
- **Later changes:** made in Settings › DSA rates. A rate with a later effective date ends the earlier one the day before. Past activities keep the rate in force on their start date.
- **INTERN (KES 4,000):** confirmed by the user on 22 Oct 2026 for casuals and attachees ("interns"). It applies unless HR reclassifies the officer to a numbered job group. A casual or attachee with no grade is costed at INTERN; one with a grade is costed at that grade.
- **The old destination rates:** the 16 provisional Cities / Other towns rates stay in the table as history and are never used. They are shown under "Retired destination rates" in Settings, and old cost lines still point to them. The county destination categories also stay in the database, unused. Settings › DSA destinations is removed.
- **What a destination still decides:** whether DSA applies at all. The activity needs a location in a county, as before. It no longer decides the rate.
- **Recorded amounts are protected:** completed, closed, declined and cancelled activities are never recalculated. Draft, under-review and returned memos pick up the standard rate the next time they are edited or recalculated. Approved and in-progress activities are recalculated only through the existing amendment steps (date change, team change, release), which are logged and may return the activity to the CEO, or when the Chief of Staff presses Recalculate DSA on the activity. A Chief of Staff override is kept.
- **Job grade is validated** in the import, the staff form and the memo team form. Accepted values are the scales `1` to `10` (also typed as `Scale 6`, `Job Group 6` or `NGCDF 6`; see section H), plus `INTERN`, which only casuals and attachees may have.

### Workbook v2

`HR-Staff-Import-2026-10-v2.xlsx` was made from v1, which is unchanged (checksum compared). Only the Job Grade column changed in the data:

| Group | Records | Job Grade in v2 | Changed from v1 |
|---|---:|---|---:|
| Attachees (interns) | 17 | INTERN | 17 (was blank) |
| Casuals | 54 | INTERN | 54 (was blank) |
| Permanent staff | 439 | blank, to be graded in the system | 439 (was NGCDF n from Job Scale) |
| **Total** | **510** | | **510** |

- **Review Flags:** 33 cells lost the "NGCDF 9/10 has no DSA rate" note, which no longer applies. 31 rows had that as their only issue and are no longer amber.
- **Unchanged:** every other cell is identical to v1 (compared cell by cell), and PF numbers are still text.
- **New dropdown:** Job Grade now has one (NGCDF 1 to 10, INTERN).
- **Import test:** importing v2 into a throwaway in-memory database added 510 with no errors: 71 INTERN, 439 with no grade.
- **After the import:** the 439 permanent staff appear under Staff list › "Active, no job grade" until they are graded. Until then they get no DSA (the memo lists them as "No DSA rate (job grade not set)").
- **Blank cells:** a blank Job Grade in an import never erases a grade already in the system.

### Deployment notes

1. Back up the database.
2. The migrations were consolidated on 22 Oct 2026, before the first production release: the staff, DSA rate, budget, report and release changes are part of the create migrations (12 files). A new installation runs `php artisan migrate`. A development or staging database built from the earlier files must be rebuilt after a backup (`php artisan migrate:fresh --seed`, run by its owner, never against production data), because those changes no longer arrive as separate migrations.
3. Run `php artisan db:seed`, or at least `--class=SettingsSeeder`, to write the 11 standard rates.
4. Run `php artisan optimize:clear`, then `npm run build` (the settings and staff screens changed).
5. Check Settings › DSA rates: 11 rates "In force" from 01 Jul 2026, with the retired destination rates listed below them.

### Not yet updated

`FAPM-Testing-Guide.pdf` (steps 3 and 4, and the provisional Cities / Other towns table) and `docs/guides/FAPM-First-Activity-Practice-Guide.pdf` ("from each officer's job grade and the destination") still describe the retired model. Their sources are not in the repository, so they need regenerating.

## H. Register scales as job grades, and workbook v3 (24 October 2026)

### Job grades are the register's scales

The HR staff register gives every permanent officer a **Job Scale** (1 to 10). From 24 October 2026 that scale is the job grade everywhere in the system:
- **Stored as the bare number:** `6`, as the Board's memo template prints it in the budget annex's Scale column. INTERN is unchanged.
- **Seeded rates are keyed by scale:** `1` to `10` and `INTERN`, with the same amounts as in section G (`DsaRate::STANDARD`, synced by `SettingsSeeder` on every run).
- **What the import, staff form and memo team form accept:** `6`, `Scale 6`, `Job Group 6` or `NGCDF 6`, all saved as `6`. The import also reads a column headed **Job Scale**, so the register's own column can be pasted in as it is.
- **Older labels still cost correctly:** a grade recorded as `NGCDF 6` on an activity participant still finds scale 6's rate, and the memo annex prints it as `6`.
- **Sample files:** `docs/samples/hr-staff-list.csv` and `participant-list.csv` now use scales.

### Workbook v3

`HR-Staff-Import-2026-10-v3.xlsx` was made from v2, which is unchanged (checksum compared). Only the Job Grade column changed:

| Group | Records | Job Grade in v3 |
|---|---:|---|
| Permanent staff | 439 | Job Scale from the register: 2 (4), 3 (8), 4 (23), 5 (100), 6 (224), 7 (3), 8 (44), 9 (29), 10 (4) |
| Casuals | 54 | INTERN |
| Attachees (interns) | 17 | INTERN |
| **Total** | **510** | |

- **Checked against the register:** each permanent officer was matched back by PF number, not by the row used to build v3. All 439 scales agree, and none is missing or outside 1 to 10.
- **Unchanged:** every other cell is identical to v2. PF numbers are still text, and the Job Grade dropdown lists 1 to 10 and INTERN.
- **Import test:** importing v3 into a throwaway in-memory database added 510 with no errors. Every officer resolves to a standard DSA rate, and nobody is left without one.

### Gill Sans MT in generated documents

The memo and its annexes, report PDFs and print views, and Excel exports use **Gill Sans MT** (`App\Support\DocumentFont`).
- **Where the font files go:** the font is licensed with Microsoft Office, so its files are **not in Git**. On each server, copy the four files into `storage/app/fonts`, or the folder set in `ACTIVITIES_DOCUMENT_FONTS`.
  - The Windows names are `GIL_____.TTF`, `GILB____.TTF`, `GILI____.TTF` and `GILBI___.TTF`, from `C:\Windows\Fonts` on a PC with Office.
  - The Office for Mac names (`Gill Sans MT.ttf`, `Gill Sans MT Bold.ttf`, ...) work too.
- **Embedding:** the font permits embedding. PDFs embed only the characters used (subsetting), so a memo is about 80 KB.
- **Without the files:** PDFs fall back to DejaVu Sans. Excel files simply name Gill Sans MT, which Office shows.
- **After installing the fonts:** dompdf remembers fonts in `storage/fonts` (gitignored), and that folder must be writable. Restart any long-running worker so it sees the new fonts.

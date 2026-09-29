# FRD traceability

Maps each requirement in the **Functional Requirements Document, Field Activity Planning and Monitoring System (FAPM), v1.2 (NGCDF_FAPM_FRD_v3.docx, RESTRICTED)** to where it is built. The FRD itself is not kept in this repository.

Status: **Built** (implemented and covered by tests), **Partial** (see note), **Not built** (planned phase in brackets).

## Where the FRD overrides earlier decisions

| Topic | Earlier decision (design assessment §0) | FRD v1.2 | Built as |
|---|---|---|---|
| Users | Admin + CEO | Exactly three: CEO, Chief of Staff, Assistant Chief of Staff (CF-01, §4.1) | FRD. `RolesAndPermissionsSeeder::MATRIX` |
| Intake | Magic links for memo originators | Departments have no access (§2.2, BR-11) | Links kept behind `ACTIVITIES_SUBMISSION_LINKS` (**off by default**). A link submission becomes a **Draft** the Chief of Staff reviews. Needs the CEO's confirmation. |
| Lifecycle | Draft → Planned → Approved → Ongoing → Completed | Draft → Awaiting Decision → Approved/Returned/Declined → In Progress → Completed → Report Received → Closed, + Postponed, Cancelled (§5) | FRD. `ActivityStatus` |
| Approval | In app or memo reference | CEO decides in app; Chief of Staff records an outside decision with memo, date and scan (CD-02, CD-03) | FRD |
| Database | MySQL | "Laravel, Livewire, PostgreSQL proposed, subject to confirmation" (§8) | MySQL, matching Smart and the Board's other apps. Confirm. |
| Scope | All activities | "Field activities" | Categories cover any activity type; the FRD wording is kept on screen. |

## Requirements

| ID | Status | Where |
|---|---|---|
| MD-01 staff list, PF no., grade, dept, station; Excel import; no free-text staff | Built | `StaffList`, `StaffImporter` |
| MD-02 departments as reference data | Built | Settings › Departments (rename, deactivate, merge) |
| MD-03 activity types | Built | Settings › Categories / Activity types (OI-03 open) |
| MD-04 region (10), county, constituency (290), venue; several per activity | Built | `GeographySeeder` (snapshot of Smart), `activity_locations` |
| MD-05 DSA rate table by grade × destination, effective dates, history | Built | Settings › DSA rates, `DsaRate::lookup` |
| MD-06 travel modes | Built | `TravelMode` |
| MD-07 financial years and quarters | Built | `FinancialYear`, `Period` |
| MD-08 budget lines | Built | Settings › Budget lines |
| PL-01 record planned activity | Built | `Activities\Form`, `ActivityEditor::create` |
| PL-02 end date and nights as you type | Built | BR-01 in `ActivityEditor::withDates` |
| PL-03 Draft, editable until submitted | Built | |
| PL-04 source document attached | Built | Required before submission |
| PL-05 bulk import of departmental plans | Not built (Phase 3) | |
| PL-06 calendar, month and week | Built | `Calendar` |
| PL-07 same place, overlapping dates, different departments | Built | `Calendar` (joint-mission marker) |
| PL-08 notes | Built | |
| PT-01 participants from staff list | Built | |
| PT-02 roles | Built | `ParticipantRole` |
| PT-03 non-staff participants | Built | External participants; count-per-head switch (OI-09) |
| PT-04 overlap conflict with reason | Built | `ParticipantChecks::conflicts`, BR-04 |
| PT-05 leave clash | Not built (Could) | Needs leave data (OI-12) |
| PT-06 cumulative field days vs threshold | Built | `ParticipantChecks::fieldDayFlags`; thresholds in Settings (OI-05) |
| PT-07 amendments after approval; beyond tolerance back to CEO | Built | `ActivityLifecycle::recordAmendment` |
| CB-01 computed DSA, logged override | Built | `DsaCalculator` |
| CB-02 / CB-03 travel and other costs | Built | |
| CB-04 totals per participant and activity | Built | `ActivityCosting` |
| CB-05 against budget | Partial | Department costs show allocation and remainder; no warning at approval yet |
| CB-06 exact money | Built | `Money` (integer cents), DECIMAL(15,2) |
| CD-01 decision queue | Built | `DecisionQueue` |
| CD-02 approve / decline / return, comment mandatory | Built | |
| CD-03 decision recorded on CEO's behalf with memo, date, scan | Built | |
| CD-04 directives tracked to completion | Built | `Directives` |
| CD-05 late notice | Built | Setting `late_notice_working_days` (OI-04) |
| CD-06 retrospective | Built | Set when approval (memo) date is after the start date |
| EX-01 commencement prompt, amber / red | Built | `Activity::commencementRag`, daily alert |
| EX-02 attendance; only attended count | Built | BR-07 in `ActivityCosting` |
| EX-03 postpone / cancel, overlap re-check, history | Built | |
| EX-04 extension, recalculation, tolerance | Built | |
| EX-05 in the field today | Built | `FieldToday` |
| RP-01 completed on end date; report due date | Built | `activities:daily` (OI-07) |
| RP-02 record report | Built | |
| RP-03 overdue flag, no reminder to departments | Built | |
| FR-01 actual costs | Built | |
| FR-02 variance by activity, department, year | Built | Department costs, Planned against Actual report |
| FR-03 imprest reference and surrender status | Built | OI-06 |
| FR-04 close only with report and actuals | Built | BR-08 |
| DB-01 CEO dashboard | Built | `Dashboard` |
| DB-02 staff participation view | Built | `Participation` |
| DB-03 department cost view with budget | Built | `DepartmentCosts` |
| DB-04 planned against actual by dept and quarter | Built | Report |
| DB-05 coverage | Built | Report (list form; no map) |
| DB-06 RAG | Built | `Rag` |
| DB-07 drill-down | Built | Figures link to filtered register |
| SR-01..SR-04 register, filters, sort, filtered export, count | Built | `Activities\Register`, `ActivityRegisterQuery` |
| NT-01 in-app alerts | Built | `AlertsBell`, `Alerts` |
| NT-02 Monday summary | Built | `activities:weekly-summary` |
| NT-03 email carries no detail; nobody else alerted | Built | No email sent at all |
| CF-01 three accounts, no self-registration | Built | `Users`, `activities:create-user` |
| CF-02 permissions on every action | Built | Route middleware + checks in every service |
| CF-03 MFA mandatory | Built | `RequireTwoFactor` (`ACTIVITIES_REQUIRE_TWO_FACTOR`) |
| CF-04 lockout; deactivate on change of holder | Built | Fortify `authenticateUsing`, `Users` |
| CF-05 15-minute idle, one session | Built | `SESSION_LIFETIME=15`, `RecordSignIn` |
| CF-06 audit and access log | Built | `audit_logs`, `access_logs` |
| CF-07 RESTRICTED on exports and prints with user and time | Built | `ReportExport`, `reports/document.blade.php` |
| CF-08 encryption in transit and at rest | Partial | Attachments encrypted by the app; sessions encrypted. Database, disk and backup encryption and HTTPS are server configuration (see README) |
| CF-09 ICT custodians, no app account | Operational | OI-14 |
| CF-10 no permanent delete | Built | Drafts soft-deleted; everything else cancelled |
| CF-11 network / VPN only | Operational | Web-server allow-list (OI-15) |
| CF-12 Data Protection Act | Partial | Minimal personal data, restricted access, audit. Needs a DPIA by the Board |
| CF-13 import concept CSV as Closed history | Not built (Phase 3) | |
| §10 standard reports (9) | Built | `app/Reports/Definitions` |

## Open items that change behaviour

OI-01 DSA basis and destination categories (provisional values seeded; `dsa_basis` setting), OI-02 which activities need a decision, OI-04 late-notice days, OI-05 field-day limits, OI-06 imprest, OI-07 report due days, OI-08 budgets, OI-11 hosting and address, OI-13 permission matrix, OI-14 custodians, OI-15 network policy. Thresholds are editable in Settings › Thresholds; the permission matrix is `RolesAndPermissionsSeeder::MATRIX`.

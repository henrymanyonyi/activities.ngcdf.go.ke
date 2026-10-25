# FRD traceability

Maps each requirement in the **Functional Requirements Document, Field Activity Planning and Monitoring System (FAPM), v1.2 (NGCDF_FAPM_FRD_v3.docx, RESTRICTED)** to where it is built. The FRD itself is not kept in this repository.

Status: **Built** (implemented and covered by tests), **Partial** (see note), **Not built** (planned phase in brackets).

## Where the FRD overrides earlier decisions

| Topic | Earlier decision (design assessment §0) | FRD v1.2 | Built as |
|---|---|---|---|
| Users | Admin + CEO | Exactly three: CEO, Chief of Staff, Assistant Chief of Staff (CF-01, §4.1) | FRD, plus a **System Administrator** (`admin`) approved by the CEO on 11 Oct 2026: reference data and settings, user accounts, and the access and audit log with export. No activity data. **Record the approval in the next FRD revision (CF-01, CF-09).** `RolesAndPermissionsSeeder::MATRIX` |
| Department access | Departments have no access (§2.2, BR-11) | Heads of Department originate activity memos | A **Head of Department** (`hod`) role, one account per department, added October 2026 for the activity memo workflow: prepares, submits and corrects only their own department's memos and sees their status and decisions. No register, dashboard, calendar or report. **Record the CEO's approval in the next FRD revision (§2.2, BR-11, CF-01).** |
| Intake | Magic links for memo originators | Departments have no access (§2.2, BR-11) | Links kept behind `ACTIVITIES_SUBMISSION_LINKS` (**off by default**). A link submission becomes a **Draft** the Chief of Staff reviews. Needs the CEO's confirmation. |
| Lifecycle | Draft → Planned → Approved → Ongoing → Completed | Draft → Awaiting Decision → Approved/Returned/Declined → In Progress → Completed → Report Received → Closed, + Postponed, Cancelled (§5) | FRD. `ActivityStatus` |
| Approval | In app or memo reference | CEO decides in app; Chief of Staff records an outside decision with memo, date and scan (CD-02, CD-03) | FRD |
| Database | MySQL | "Laravel, Livewire, PostgreSQL proposed, subject to confirmation" (§8) | MySQL, matching Smart and the Board's other apps. Confirm. |
| Scope | All activities | "Field activities" | Categories cover any activity type; the FRD wording is kept on screen. |

## Requirements

| ID | Status | Where |
|---|---|---|
| MD-01 staff list, PF no., grade, dept, station; Excel import; no free-text staff | Built | `StaffList`, `StaffImporter`. Since 22 Oct 2026 the list holds permanent staff, casuals and attachees (`EmploymentType`; PF number required only for permanent staff), with contract end date; the import has a Check file step and rejects unknown departments and duty stations. Unified template and the October 2026 register onboarding: `docs/hr-import/` |
| MD-02 departments as reference data | Built | Settings › Departments (rename, deactivate, merge). The Board's 16 departments are seeded (`ReferenceDataSeeder::DEPARTMENTS`); older installations are reconciled once by `departments_board_structure` |
| MD-03 activity types | Built | Settings › Categories / Activity types (OI-03 open) |
| MD-04 region (10), county, constituency (290), venue; several per activity | Built | `GeographySeeder` (snapshot of Smart), `activity_locations` |
| MD-05 DSA rate table by grade × destination, effective dates, history | Built (changed) | Since 22 Oct 2026 one standard rate per job grade, whatever the destination (the register scales 1 to 10, stored as the number since 24 Oct 2026, and INTERN for casuals and attachees), from 1 Jul 2026: `DsaRate::STANDARD`, synced by `SettingsSeeder` every run; `DsaRate::lookup` reads only standard rates. The Cities / Other towns rates are kept as history and never used; Settings › DSA destinations removed. Effective dates and history unchanged. Record the change in the next FRD revision |
| MD-06 travel modes | Built | `TravelMode` |
| MD-07 financial years and quarters | Built | `FinancialYear`, `Period` |
| MD-08 budget lines | Built | Budgeting › Department budgets (moved out of Reference data, 21 Oct 2026). A department's budget for a year is prepared as a draft by the CEO, COS or ACOS, submitted to the CEO, and approved or returned for correction by the CEO only; only approved budgets count and only their lines can be charged by a memo. Adjustments during the year (supplementary, reduction, reallocation between lines) follow the same approval. `App\Services\Budgeting`, `DepartmentBudget`, `BudgetAdjustment`; Budget position menu item opens Department costs › Budget position. Business owner's request, 21 Oct 2026; record in the next FRD revision |
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
| PT-04 overlap conflict with reason | Built, changed | Business owner's decision, 21 Oct 2026: an officer is **unavailable** on their own days on any activity approved or later (approved, in progress, postponed, completed, report received, closed). A Head of Department cannot place them at all and is told the days, not the activity; only the Office of the CEO may, with a written reason (`ActivityEditor::assertMayOverride`). Memos under review, awaiting decision or returned by the CEO are a warning only; drafts, declined and cancelled hold nobody. Checked under a lock on the staff rows when adding and again at approval, so two activities cannot both take the same officer (`ParticipantChecks::lockStaff`). `ParticipantChecks`, BR-04 |
| Recall, release, reassignment of an officer part-way (Oct 2026) | Built | `ParticipantReleases`, `participant_releases`, Team tab › release button. Office of the CEO only (`activities.execute`). Last day kept, free from the next day, original assignment kept. Only computed DSA is reduced automatically; other lines stay payable unless revised with a reason; an extra cost can be added. Effect shown before confirming; recorded as an amendment against the approved total (a rise beyond tolerance returns it to the CEO). Reassignment places the officer on the destination activity from the next day |
| PT-05 leave clash | Not built (Could) | Needs leave data (OI-12) |
| PT-06 cumulative field days vs threshold | Built | `ParticipantChecks::fieldDayFlags`; thresholds in Settings (OI-05). The add-officers picker shows each officer's field days this year and can list the fewest first (`plannedFieldDaysByStaff`) |
| PT-07 amendments after approval; beyond tolerance back to CEO | Built | `ActivityLifecycle::recordAmendment` |
| CB-01 computed DSA, logged override | Built | `DsaCalculator` |
| CB-02 / CB-03 travel and other costs | Built | |
| CB-04 totals per participant and activity | Built | `ActivityCosting` |
| CB-05 against budget | Built | `BudgetPosition` (definitions agreed 21 Oct 2026): allocation, pending (not deducted), approved, committed, spent (Finance returns), adjustments, released by cancellations, balance = allocation − spent − committed, utilisation. Department costs › Budget position tab with a department drill-down; report `budget-position` (Excel, PDF, print); the CEO's approval popup shows the balance if approved and warns when it goes over the allocation |
| CB-06 exact money | Built | `Money` (integer cents), DECIMAL(15,2) |
| CD-01 decision queue | Built | `DecisionQueue` |
| Memo workflow (Oct 2026): HOD drafts and submits; ACOS / COS review, comment, return for correction, forward; CEO decides | Built | `ActivityLifecycle::submitForReview`, `returnForCorrection`, `forward`, `addReviewNote`; statuses Under review and Returned for correction; `Memos\Board`, `Memos\Show`; route via the Chief of Staff is the setting `acos_forwards_via_cos` |
| Memo prepared on a Head of Department's behalf, real preparer kept | Built | `responsible_hod_id` / `responsible_hod_name`, `created_by` |
| Generated memo and annexes (Oct 2026), template `files/MEMO Template .docx` | Built | `MemoDocument`, `resources/views/memos/document.blade.php`, route `memos.document`. Memo in the template layout (To, From, Date, Ref No, Subject, numbered sections incl. Key risks and any extra sections, Request for approval, signature blocks filled from the audit trail), then Annex A budget breakdown, B detailed budget, C itinerary, each on its own page, "Page n of N", access-logged. No RESTRICTED banner on the memo itself (business owner, 21 Oct 2026; a departure from CF-07 to record in the next FRD revision). Totals come from one source. Missing items listed; blocking ones stop a Head of Department's submission. The generated memo replaces the signed upload for memos a Head of Department prepares in the system (business owner, 21 Oct 2026); the Office of the CEO's own path still needs the source document |
| Head of Department builds the whole memo: locations, team, external participants, days, planned costs (DSA computed) | Built | `Memos\Team`, `Memos\Show` (Add location); `ActivityEditor::authorizeDetails` limits them to their own department's memo while it is a draft or returned. An unavailable officer is shown with the days, without naming the other activity, and cannot be ticked |
| Head of Department sees their own department's budget (Oct 2026) | Built | Allocation, spent, committed, pending and available balance for their department only: memos board (current year), memo form (year of the start date, beside the budget line), memo page (balance if this memo is approved). `BudgetPosition::forDepartment`, `forActivity`. Business owner's request, 21 Oct 2026; record with the HOD role in the next FRD revision |
| Departmental workplans and budget estimates (25 Oct 2026) | Built | `Budgeting` (`workplans.prepare` for the department's own Head), `WorkplanImporter`, `WorkplanTemplate` (download on Workplan and budget), ICT example `docs/guides/FAPM-Workplan-ICT-Example-FY2026-27.xlsx`. CEO comments, changes line amounts before approval, returns for adjustment or approves; history in `budget_reviews`. **Record the CEO's approval in the next FRD revision.** States reuse Draft / Awaiting CEO approval / Returned / Approved; resubmission shows in the history. No separate "Under review" state |
| CEO budget adjustments during the year (25 Oct 2026) | Built | `Budgeting::adjustNow` (direct, with confirm step and effective date); proposals by the Office of the CEO unchanged. Approved allocation never overwritten; before and after recorded on each adjustment; report `budget-adjustments` |
| Staff promotions, transfers and access changes (25 Oct 2026) | Built | `StaffChanges`, `staff_changes`, Staff list › Change position or access (`staff.reassign`: admin, CEO, COS, ACOS) |
| Delegated activity memos by magic link (25 Oct 2026) | Built | `DelegatedMemos`, `MemoRequest`, statuses `with_hod` and `returned_to_preparer`. **Record the CEO's approval in the next FRD revision** (officers without accounts reach one memo) |
| Head of Department sees the staff list and adds missing officers (MD-01) | Built | `staff.add`; `StaffRecords` (corrects only officers they added, cannot deactivate); `staff.created_by`. Since 21 Oct 2026 also by importing a list (`StaffImporter` with the user): new officers become theirs, their own are updated, others' are skipped, the Active column is ignored |
| CEO instructs Finance (imprest) and Procurement (tickets) on an approved memo (25 Oct 2026, shell) | Built | `ActionInstructions`, `action_instructions`, Approve popup and the activity's Decisions tab (CEO only, `instructions.send`); Heads of Finance and Procurement: Instructions menu, instruction sheet, acknowledge, complete with a reference (`instructions.action`). Sheet only, never the memo. Departments in Settings › Thresholds. Not yet: linking the imprest reference to the activity's imprest fields, cancelling an instruction when the activity is cancelled or changed. Record the CEO's approval in the next FRD revision |
| Memo reporting: counts by stage, time per stage, pending too long, budgets | Built | `MemoTracker`, report `memo-workflow`, daily reminder (`memo_review_reminder_working_days`) |
| CD-02 approve / decline / return, comment mandatory | Built | |
| CD-03 decision recorded on CEO's behalf with memo, date, scan | Built | |
| CD-04 directives tracked to completion | Built | `Directives` |
| CD-05 late notice | Built | Setting `late_notice_working_days` (OI-04) |
| CD-06 retrospective | Built | Set when approval (memo) date is after the start date |
| EX-01 commencement prompt, amber / red | Built, changed | Since 21 Oct 2026 (business owner) `activities:daily` starts an approved activity on its start date (`ActivityLifecycle::startDueActivities`); the Office of the CEO can still confirm commencement earlier on the day |
| EX-02 attendance; only attended count | Built | BR-07 in `ActivityCosting` |
| EX-03 postpone / cancel, overlap re-check, history | Built | |
| EX-04 extension, recalculation, tolerance | Built | |
| EX-05 in the field today | Built | `FieldToday` |
| RP-01 completed on end date; report due date | Built | `activities:daily`; due 7 working days after the end date (Settings › Thresholds `report_due_working_days`, default 7 at the business owner's request, 21 Oct 2026) |
| RP-02 record report | Built, extended | The Head of Department submits the activity report on the memo page with the actual amount spent on each cost line, which counts as spent at once; the COS, ACOS or CEO accepts it (Report received) or returns it for correction (`ActivityLifecycle::submitReport`, `acceptReport`, `returnReport`). The Office of the CEO can still record a report itself. Timeliness (on time, late by n, due, overdue) from the date first received: `Activity::reportTimeliness`, report `report-compliance`. Business owner's request, 21 Oct 2026 |
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
| NT-03 email carries no detail; nobody else alerted | Built (25 Oct 2026) | `App\Services\OutboundAlerts`: an alert that needs the recipient to act (`ActivityAlert::needsAction`) also goes by **email and SMS** to Heads of Department, the Chief of Staff and the Assistant, never the CEO. Each message carries only the reference and the step, never the title, places, team, costs or comments, and links to FAPM. SMS go through **SMS Leopard**, a third party, which is an exception to FRD 12 / BR-11 (no outbound integration). **Record the CEO's approval in the next FRD revision.** Settings › Thresholds switches each channel. Every message is logged (`outbound_messages`, report `message-log`, Email and SMS log). Mobile numbers come from the account, else the officer on the staff list it was made from. A Head of Department still gets alerts only about their own department's memos |
| CF-01 three accounts, no self-registration | Built | `Users`, `activities:create-user` |
| CF-02 permissions on every action | Built | Route middleware + checks in every service |
| §4.1 / OI-13 permission matrix | Built, changed | The CEO holds every permission (30 Sep 2026): everything the Chief of Staff and Assistant can do, including reference data and user accounts, plus deciding. Only the CEO decides in the system. `RolesAndPermissionsSeeder::MATRIX` |
| CF-03 MFA mandatory | Optional | Two-factor is available to every user but not enforced, at the business owner's request. `ACTIVITIES_REQUIRE_TWO_FACTOR=true` enforces it (`RequireTwoFactor`) |
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

OI-01 DSA basis (`dsa_basis` setting; destination categories retired 22 Oct 2026 in favour of standard rates per grade), OI-02 which activities need a decision, OI-04 late-notice days, OI-05 field-day limits, OI-06 imprest, OI-07 report due days, OI-08 budgets, OI-11 hosting and address, OI-14 custodians, OI-15 network policy. Thresholds are editable in Settings › Thresholds; the permission matrix is `RolesAndPermissionsSeeder::MATRIX`.

# Audit: activity register, editing, participants and conflicts

**Date:** 11 October 2026 · **Scope document:** `docs/NGCDF_FAPM_FRD_v3.docx` (its contents are FRD v1.2, 29 Sep 2026, RESTRICTED) · **Branch:** `dev_henry`

This audit was requested with a generic brief ("staff activity registration and tracking system"). Where that brief and the FRD disagree, the **FRD is the authority** (CLAUDE.md, `docs/FRD-TRACEABILITY.md`). Section 6 lists every point of disagreement and the clarification it needs.

## 1. Architecture and technologies

- Laravel 13, PHP 8.4, Livewire 3, Jetstream/Fortify, spatie/laravel-permission, Pest (SQLite `:memory:`), MySQL. Standalone; no integrations (FRD 12).
- UI: PAKA-RANGI components in `resources/views/components/ui/`, class recipes in `App\Support\Ui`.
- Domain services (all check permissions themselves, CF-02):
  - `ActivityEditor`: details, locations, participants, planned costs.
  - `ActivityLifecycle`: every status change, amendments and tolerance (BR-10).
  - `ParticipantChecks`: overlaps (PT-04, BR-04) and cumulative field days (PT-06).
  - `ActivityCosting` and `DsaCalculator`: stored totals and DSA lines.
- Audit: the `Auditable` trait records model changes with old and new values. `AccessLogger` records views and exports. Status history and amendments have their own tables.

## 2. Current features and workflows

FRD Phase 1 and 2 are built (see `docs/FRD-TRACEABILITY.md`): register with filters and sort, calendar, decision queue, activity page (team, costs, execution, report, history), field-today view, participation and department cost views, reports with RESTRICTED exports, and Settings for reference data.

**Capture workflow today:**

1. Register › Record activity (form) → saved as Draft → activity page.
2. Team tab › Add officers (picker grouped by department, search, office filter, overlap flags, per-person conflict reason) → Costs tab → Submit for decision.
3. The CEO decides in the queue, or the Chief of Staff records an outside decision.
4. Execution: commencement, attendance, report, actual costs, close.

## 3. Defects and root causes

| # | Defect | Root cause | Severity |
|---|---|---|---|
| D1 | **Edit page crashes (HTTP 500)** for any activity without notes, expected outputs or memo number, which is the normal case for a new draft. | `Activities\Form::mount()` calls `fill()` with model values. NULL columns are assigned to `string` properties, which throws a TypeError. The `??= ''` fallbacks run after the throw. Reproduced in `tests/Feature/Pages/EditActivityTest.php`. | Critical |
| D1b | **Saving an edit always failed** with "The source document field must be a file", even when the page opened. Found once D1 was fixed. | On edit, the `memo` rule was `prohibited` plus `file`. The Livewire property is present as `null` and the rule was not `nullable`, so `file` ran and failed. Together with D1, this is why "editing does not work". | Critical |
| D2 | **No way to edit once submitted.** The Edit button is shown only for Draft and Returned (`show.blade.php`, `isFreelyEditable()`), although `ActivityEditor::update()` and the route allow editing details at any non-read-only status, with dates locked. A typo in the title of an approved activity cannot be corrected. | View condition stricter than the service rule. | High |
| D3 | **No edit action in the register.** Users must open the activity before they can find Edit. | UI omission. | Medium |
| D4 | **Approval recorded on the CEO's behalf skips the overlap check.** `recordDecision()` can approve straight from Draft, and it only checks that a team exists. An officer double-booked with no reason can be approved. | `applyDecision()` does not call the conflict check; only `submitForDecision()` does. | High (BR-04) |
| D5 | **Approval in the system does not re-check overlaps.** A conflict can arise after submission, when another activity with the same officer is approved, and the approval goes through with no reason recorded. | Same as D4. | Medium |
| D6 | **Changing one person's days never checks overlaps.** `setParticipantDates()` can move an officer onto days already taken elsewhere. | Check missing. | Medium |
| D7 | **An overlap cannot be explained after the fact.** After a postpone or extension, the team tab shows "no reason given", but there is no control to give one. Submission then refuses ("Give a reason for each"). The only way out is to remove the person and add them again. | No service method or UI to set `conflict_reason` on an existing participant. | High (dead end) |
| D8 | **The picker gives no fairness information.** It shows overlaps but not each officer's field days, so the Chief of Staff cannot see who has had many or few field days when choosing a team (PT-06 is shown only after adding). | Picker does not query field days. | Medium |
| D9 | **Department list does not match the Board's 16 departments.** The 11 concept names are seeded once, and several are merged units (e.g. "Human Resource & Administration"). | Seed list predates the structure supplied on 11 Oct 2026. | Medium |

## 4. Working functionality to preserve

The following is sound and is kept unchanged: the lifecycle enum and transition guard, the amendment and tolerance logic (BR-10), integer-cent money (BR-09), snapshots of department and grade on each participant (history survives transfers), soft-delete for drafts only (CF-10), access logging, RESTRICTED exports, the department-grouped picker with per-person and shared conflict reasons, and the "Showing x of y" register.

## 5. Security, usability, integrity and performance concerns

- **Concurrency:** adding staff takes no row lock. With three users the race window is negligible, and the approval-time re-check (fix for D4 and D5) is the backstop, so no lock is added.
- **Department-based access:** none. Permissions come only from the three roles. Keep it that way.
- **Picker size:** capped at 500 rows with search and filters. The field-day lookup added for D8 is one grouped query, not one query per row.
- **Edit form:** posting through Livewire still goes through `ActivityEditor::update()`, which re-checks the permission and the read-only state, and rejects date changes after submission. Changing a request cannot bypass the lifecycle.

## 6. Brief against FRD: points needing the CEO's or Chief of Staff's decision

| Brief asks for | FRD says | Done here |
|---|---|---|
| Staff registration, self-service, approval or waitlist per participant, registration windows | Departments and staff have **no access**. Only three users (§2.2, CF-01, BR-11). | Not built. The Chief of Staff selects participants. **Needs a FRD change** if wanted. |
| Hard **block** on overlapping assignments | BR-04 and PT-04: **flag**, show the conflicting activity, **require a reason to proceed** | Kept as flag plus mandatory reason, now enforced at every entry point (D4 to D7). **Confirm** whether a hard block is wanted instead. |
| Timestamp overlaps, half-open intervals | Activities are whole days: start date + number of days (PL-02, BR-01). No times exist. | Inclusive day overlap: two activities sharing any calendar day overlap. Timezone is `Africa/Nairobi` (app config). |
| Eligible participating departments, qualifications, capacity | Requesting department only (PL-01). Participants may come from any department. | Not added. Filter by department exists in the picker. |
| "Fair distribution" allocation | PT-06: cumulative field days against configurable thresholds (OI-05). DB-02: participation view. | Field days shown on each candidate in the picker, and the picker can be sorted by fewest field days. No automatic allocation; the choice stays with the user. |
| 16 named departments | MD-02: departments are reference data maintained by the Chief of Staff | Seeded additively (see 7). Legacy merged units left for the Chief of Staff to merge in Settings. |

## 7. Changes made in this round

1. **D1, D1b:** `Form::mount()` converts NULLs to empty strings before filling. On edit the `memo` rule is only `prohibited`; documents are attached on the activity page.
2. **D2, D3:** Edit is available at every non-read-only status (dates stay locked after submission, as the service requires). The register gains an Edit action, shown only to users with `activities.manage`.
3. **D4, D5:** Approval, in the system or recorded, is refused while any staff participant has an unexplained overlap. The message names the officers.
4. **D6:** Changing a person's days re-checks overlaps and requires a reason when one is found.
5. **D7:** New `ActivityEditor::explainConflict()` with a "Give reason" control on the team tab. It is audited through `Auditable`.
6. **D8:** The picker shows each officer's planned field days this financial year against the threshold, with a "Fewest field days first" sort.
7. **D9:** The department list is updated to the 16 departments. A new seed step adds the missing ones by case-insensitive name, so there are no duplicates. It renames three unambiguous legacy names (Office of the CEO / Accounting Officer → Office of the CEO; Finance & Accounts → Financial Services - Accounts; Procurement → Supply Chain), but only where the new name does not already exist. Split units (HR & Administration, Internal Audit & Compliance, Projects, Planning & M&E) and units with no counterpart (Regional Coordination, Board Secretariat) are left active for the Chief of Staff to merge or deactivate in Settings. Their staff must be assigned to the right new department, and that cannot be inferred safely. Nothing is deleted.

## 8. Risks and assumptions

- Assumes no installation relies on the three legacy department names that are renamed. Activities and staff keep their department id, so history is unaffected; only the label changes. The rename is audited.
- Requiring reasons at approval may surface older approved data with unexplained overlaps. This affects only new approvals, not existing records.
- The FRD file in this repository is still version 1.2. If a later version exists, this audit must be re-checked against it.

## 9. Phased plan

| Phase | Content | Status |
|---|---|---|
| 1 | D1 to D9 (this round) | Done, tests below |
| 2 | Decisions from section 6. Leave clash PT-05 (needs leave data, OI-12). Budget warning at approval (CB-05 partial). Bulk plan import PL-05. | Awaiting decisions |
| 3 | A guided "record activity" flow that adds the team and costs in one pass, to replace the current three-screen capture, after the users have used the Phase 1 fixes | Proposed |

## 10. Requirement to test traceability

| Requirement | Test |
|---|---|
| D1, D1b, D2 Edit opens, saves, keeps team, is audited (old and new title); validation keeps input; approved activity editable except dates | `tests/Feature/Pages/EditActivityTest.php` |
| Read-only (cancelled, closed, declined) not editable; permission enforced in the service | `EditActivityTest.php` |
| D4, D5 Approval refused with unexplained overlap (in system and recorded) | `tests/Feature/Domain/ConflictEnforcementTest.php` |
| D6 Participant days moved onto an overlap need a reason | `ConflictEnforcementTest.php` |
| D7 Explaining an existing overlap unblocks submission | `ConflictEnforcementTest.php` |
| D8 Field days per candidate in the picker | `ConflictEnforcementTest.php` |
| D9 16 departments seeded, no duplicates, legacy renamed safely | `tests/Feature/DatabaseSeederTest.php` |
| BR-04 original add-staff check, PT-06 | existing `tests/Feature/Domain/CostingAndChecksTest.php` |

# Activity memo, budget tracking and staff scheduling: gap analysis and changes

21 October 2026. Reviewed against `files/MEMO Template .docx` and the system as of commit `cd9cc48`. Business rules marked **(BO)** were confirmed by the business owner on 21 October 2026.

## What already existed

The memo workflow (Draft → Under review by the ACOS or COS → Awaiting decision → CEO approves, declines or returns; Returned for correction and resubmission; review notes; locking against double actions; full status history and audit) was complete, as were HOD memo preparation, memos on a HOD's behalf (real preparer kept), team building with computed DSA, amendments after approval with tolerance (BR-10), departmental budget lines, and actual costs recorded from Finance returns.

## Gap analysis

| Requirement | Existing | Gap | Change made | Where | Decision |
|---|---|---|---|---|---|
| Memo sections from the template | Title, purpose, justification, expected outputs, participants, notes, budget line | No Key risks, no free extra sections | `risks` and `memo_sections` (heading + text, up to 10); form relabelled "Introduction and justification" | `activities` migration `2026_10_21_064657`, `Activities\Form` | |
| Generated memo with annexes | None: HOD uploaded a signed file | Missing | PDF in the template layout; signature blocks from the audit trail; Annex A budget breakdown (by item and by department), B detailed budget (scale, pax, days, rate), C itinerary; each annex on its own page, page numbers, RESTRICTED, access-logged; one source for every total | `MemoDocument`, `memos/document.blade.php`, `MemoDocumentController`, route `memos.document` | |
| Flag missing information | Submit checked venue and upload only | No completeness check | `MemoDocument::issues()`: blocking (introduction, venue, an officer, unavailable officer) and advisory (risks, budget line, DSA not computable, estimate differs, pending overlap); shown on the memo page and in the submit popup | `MemoDocument`, `Memos\Show` | |
| Signed upload before HOD submission | Required (PL-04) | Duplicates the generated memo | Not required for memos a HOD prepares in the system; Office of the CEO path unchanged | `ActivityLifecycle::assertReadyForReview / assertReadyForDecision` | **BO** |
| Budget position and CEO reports | Allocation and "remaining" (best-known cost, counted cancelled plans in full) | Requested, approved, committed and spent not distinguished; no pending; no warning at approval | `BudgetPosition`: allocation, pending (not deducted), approved, committed, spent, adjustments, released by cancellations, balance, utilisation. Department costs › Budget position tab with drill-down; report `budget-position`; CEO approval popup shows balance if approved and an over-allocation warning. Department costs "Balance (FY)" now uses the same figure | `BudgetPosition`, `DepartmentCosts`, `BudgetPositionReport`, `components/memo/budget-position` | **BO** line-by-line definitions |
| Overlapping assignments | Approved / in progress only; anyone could proceed with a reason | Pending and later statuses ignored; HOD could override; no concurrency guard | Approved or later blocks; HOD cannot override (told the days only); Office of the CEO with a reason; pending memos warn; staff rows locked when adding and at approval | `ParticipantChecks`, `ActivityEditor`, `ActivityLifecycle::applyDecision`, `Memos\Team` | **BO** |
| Recall, release, reassignment | None (only remove a participant) | Missing | Release record keeps the original period, who asked, who recorded, destination, cost before/after and per-line treatment; officer free from the next day; DSA reduced automatically, other lines payable unless revised with a reason, extra cost allowed; effect shown before confirming; amendment against the approved total (rise beyond tolerance returns to the CEO); reassignment places the officer on the destination from the next day | `ParticipantReleases`, `ParticipantRelease`, `participant_releases`, `Activities\Show` Team tab | **BO** |
| COS / ACOS arrangement | HOD chooses ACOS or COS; Settings `acos_forwards_via_cos` makes it sequential | None | Unchanged | `ActivityLifecycle::forward` | Existing setting |

## Database and configuration

One additive migration, `2026_10_21_064657_add_memo_sections_and_participant_releases`: `activities.risks`, `activities.memo_sections` (JSON), and table `participant_releases`. Run `php artisan migrate` on each environment. No new settings, permissions or dependencies.

## Open items for management

1. **Actual expenditure** is only what is entered on each cost line from Finance returns. There is no link to the finance system (FRD 12 forbids outbound integration), so "spent" lags until returns are entered. Agree who enters them and how soon after payment.
2. **Allocations** are the budget lines entered in Reference data. Confirm whether one line per department per year (the vote/sub-vote) is enough, or whether commitments should be tracked per budget line.
3. **Recovery** of DSA already paid to a recalled officer is shown as a projected figure only. Finance's recovery process is outside the system.
4. **Travel days** are not captured separately from activity days; availability uses each officer's own days on the activity.
5. **Separation of duties:** the CEO holds every permission and could approve an activity the CEO prepared. Only the CEO decides in the system (FRD), so this is not blocked. Confirm whether it should be.
6. Record in the next FRD revision: the HOD role, the hard availability rule (changes BR-04), the generated memo replacing the signed upload (PL-04), and the budget position definitions (CB-05).

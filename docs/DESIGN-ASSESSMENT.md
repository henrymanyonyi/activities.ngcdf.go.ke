# NG-CDF Activities: Design Assessment (Phase 0)

Status: **proposal for review**. No code has been written. Decisions needed are collected in §18. Sections follow the 19 items of the brief.
Prepared 2026-09-29 from a review of the concept HTML, `smart.ngcdf.go.ke` (commit `ad562030`), `PAKA-RANGI.md`, and the seven sibling projects.

---

> **Superseded in part by the FRD (v1.2).** Where this document and the FRD differ (three users, lifecycle, decision recording, intake), the FRD wins. See `FRD-TRACEABILITY.md` for what was built.

## 0. Decisions (2026-09-29) — these override the sections below where they differ

| # | Question | Decision |
|---|---|---|
| Q1 | Scope | **All activities** (field work, workshops, trainings, meetings). Name stays *NG-CDF Activities*. |
| Q2 | Who enters data | **Both.** The CEO's office keys activities directly, *or* a memo originator (e.g. an HOD) submits through a magic link. |
| Q3 | Approval | **Both.** Per activity, the CEO/Admin either approves in the app or records the approval memo reference. |
| Q4 | External participants | External participants are recorded (name, organisation, category such as MP / NGCDFC member / partner) and each activity has a switch **"count external participants in cost per head"**. Figures show both staff-only and all-participant per-head cost. |
| Q5 | "Activities missed" | **Nominated but did not attend** (participation status `absent`). |
| Q6 | Staff, departments, designations | **Keyed in by whoever originates the memo**, the CEO, or an appointed person. They are created on the fly when a participant list is submitted, matched by staff number, and Admin can tidy (rename/merge) them. |
| — | Roles | **Only two roles for now: Administrator and CEO**, each with a named account. No department coordinator or finance role in this phase. |

### Magic-link intake (replaces the "Department Coordinator" role)

1. CEO or Admin creates a **submission link** for a named person (name, email, phone, department). The link is **editable**: label, instructions, expiry date, maximum number of submissions, optional pre-set department/category, and it can be revoked or reactivated.
2. The person opens the link (no account needed). They fill in the activity (title, purpose, category/type, dates, venue, county), upload the **memo** (PDF), and upload the **participant list** as Excel/CSV in the published template (staff number, name, department, designation, office/region, role, days; externals flagged). Optional estimated cost lines.
3. The system validates the list row by row and shows errors before accepting it. On submit, the activity is created with status **Submitted** and appears in the CEO/Admin **Inbox**.
4. CEO/Admin review it, then **approve in the app** or **record the memo reference**, or return it with a comment (the submitter can resubmit through the same link while it is valid), or reject it.
5. From there the normal lifecycle applies (Approved → Ongoing → Completed), run by CEO/Admin.

Lifecycle becomes: `Draft → Submitted → Approved → Ongoing → Completed`, plus `Returned`, `Rejected`, `Postponed`, `Cancelled`. Activities keyed in directly by CEO/Admin start at Draft and skip Submitted.

Security of links: 40+ character random token, stored **hashed**; expiry and usage cap enforced server-side; rate-limited; every open and submission logged; uploads restricted by type and size and stored on a private disk, served only to signed-in users.

Consequence to keep in mind: because the staff register is built from submitted lists, **"least participating" only covers staff who have appeared on at least one list**, unless Admin also uploads the full roster (supported with the same template).

---

## 1. The business problem

The CEO has no single view of what the organisation is doing outside the office: which activities are running, who goes, what each one costs, and whether the same people, places and purposes keep recurring in separate, separately funded trips. Today this is reconstructed by hand, if at all.

The application has three jobs, in priority order:

1. **Record** each activity once, with its participants and its cost lines (estimated and actual), so the numbers are trustworthy.
2. **Show** spend and participation to the CEO at a glance, with drill-down to the underlying records.
3. **Point out** activities that look related, so management can decide whether to coordinate or combine them. The system suggests; people decide.

It is explicitly *not* a finance system (it does not pay anyone), an HR system (it does not own the staff record long-term), or an approval workflow engine.

---

## 2. Review of the HTML concept

**What it gets right, and we keep:** the four questions it asks (log it, list it, per-staff summary, per-department cost); DSA and travel as the dominant cost lines; air vs ground travel mode; days in the field; CSV export; the per-staff expandable history.

**Structural weaknesses:**

| # | Weakness in the concept | Consequence | Proposed fix |
|---|---|---|---|
| 1 | One row = one officer on one activity. There is no Activity record; the activity is a free-text string repeated per officer. | Cannot count participants per activity, cost per head, or compare activities. A typo creates a "new" activity. Similarity detection is impossible. | `activities` is an entity; participants hang off it. |
| 2 | Officer is a free-text name (datalist). | "Jane Wanjiru" and "J. Wanjiru" are two people. No staff number, designation or region. | A staff register keyed by staff number. |
| 3 | Only staff who have logged something exist. | **"Who is not participating" cannot be answered**, which is one of the CEO's core questions. | The register lists every active staff member, so zero-participation staff appear. |
| 4 | Only DSA and travel, one amount each. | No venue, meals, materials, fuel; no shared (activity-level) costs; no planned vs actual. | Cost lines per activity, each with a category, estimated amount and actual amount, optionally tied to one participant. |
| 5 | Department is the officer's department, "Other (specify)" is free text. | Costs cannot be split between *who organised* and *who attended*. Departments drift. | Fixed department list; activity has an organising department; each participation snapshots the officer's department at the time. |
| 6 | No status, purpose, category, location, tags or programme. | Cannot show upcoming/ongoing/completed, nor group similar work. | Structured fields (§7). |
| 7 | Data lives in the browser (`window.storage`). No login, no audit, hard delete with `confirm()`. | Single-user, lost on cache clear, no accountability for figures the CEO will act on. | Server app, authenticated, role-based, soft deletes, audit trail. |
| 8 | `innerHTML` built from user input. | Stored XSS. | Blade escaping by default. |
| 9 | Palette is green/gold with emoji icons and its own header. | Does not look like NG-CDF software. | PAKA-RANGI (§3). |

---

## 3. Smart NG-CDF design conventions (PAKA-RANGI)

The new app adopts PAKA-RANGI wholesale, the way `assets` and `bursarywriter` already mirror it:

- **Shell:** sidebar (`bg-emerald-900`) + `h-16` topbar; every page uses the wrapper `-m-8 h-[calc(100vh-4rem)] flex flex-col overflow-hidden`, a fixed three-tier header (identity + primary action, full-width filter row, collapsible stats persisted in `localStorage`), and a body where exactly one element scrolls.
- **Type:** Poppins headings, Inter body, `font-numeric` (Inter tabular) on every amount, date and count. Money as a muted `KES` prefix + `number_format($v, 2)`; dates `d M Y`.
- **Colour:** emerald = primary and "complete"; red = destructive/cancelled only; amber = pending; blue = in flight; slate = draft. Status badge classes live on the **enum/model**, not in Blade (`PaymentVoucher::statusBadgeClasses()` pattern).
- **Popups:** three sizes only (Large `max-w-7xl` detail, Medium `max-w-2xl` form, Alert `max-w-md` confirm); separate backdrop div; no `wire:confirm` (use the `requestConfirm()`/`confirmProceed()` pattern, §5.4.1).
- **Controls:** `$btnPrimary` / `$btnDanger` / `$btnNeutral`; `<x-searchable-select>`; `<x-money-input>` for every amount; `livewire.custom-pagination`.
- **Tabs:** primary pill group in the header vs secondary underline strip on the card; a multi-step form uses a step rail, never tabs.
- **Mobile:** tables are replaced by cards below `lg`.

Reusable components to copy from Smart (not reinvent): `layouts/admin.blade.php` shell, `searchable-select`, `money-input`, `custom-pagination`, the confirm-popup trait, `dashboard-hero`, `row-actions-menu`, `officer-authorization-trail` (for the status trail).

---

## 4. Smart NG-CDF technology stack

| Concern | Smart today | Implication for the new app |
|---|---|---|
| Framework | Laravel 13, PHP ^8.3 | Same |
| UI | Livewire 3.6 (class components), Alpine, Tailwind 3.4 | Same. Livewire 3 rather than 4 (used by lms/mp/shif) because the PAKA-RANGI shell and components we copy are Livewire 3 code. |
| Auth | Jetstream 5 (Fortify), Sanctum, `EmailLoginCode`, `LoginAudit` | Jetstream with 2FA, like `assets` |
| RBAC | `spatie/laravel-permission` 7, roles carry `label`/`scope_type`, seeded by `RolesAndPermissionsSeeder` | Same package and seeder pattern; one scope type (`department`) |
| Audit | No package. Per-domain history tables (`*StatusHistory`, `AssignmentAudit`, `BoardPaperAuditLog`, `LoginAudit`) | A status history table plus one small in-house `audit_logs` table written by a model trait. No new dependency. |
| Status | Backed enums in `app/Enums` | `ActivityStatus`, `ParticipationStatus` enums with `label()` / `badgeClasses()` |
| Exports | `maatwebsite/excel`, `barryvdh/laravel-dompdf` | Same |
| Ops | `spatie/laravel-backup`, `sentry/sentry-laravel`, MySQL | Same; DB `db_ngcdf_activities` |
| Charts | ApexCharts, loaded from a CDN in `livewire/ceo/dashboard.blade.php` | ApexCharts, but **bundled via npm/Vite** rather than CDN (needs your approval as a JS dependency) |
| Tests | PHPUnit in Smart; Pest in every newer sibling | Pest, SQLite `:memory:`, per the workspace convention |
| Style | Pint, Prettier, Laravel Boost guidelines | Same; ship a `CLAUDE.md` with Boost rules and the Smart database-safety rule |

**Org data in Smart (relevant to integration):** `HqStaff` has `staff_number`, `designation` (free text) and `user_id`, but **Smart has no HQ department model**, and "region" is Smart's `Cluster` (constituency grouping). The CEO role exists in Smart (`ceo`, `dashboard.ceo`). So departments must live in the new app for now.

---

## 5. Proposed name and deployment naming

The workspace pattern is `<purpose>.ngcdf.go.ke`, one short lowercase word, used as folder name, repo name under `NGCDF-Board`, and hostname (`smart`, `bursary`, `shif`, `lms`, `assets`, `citizen`).

| Item | Proposal |
|---|---|
| Application name | **NG-CDF Activities** (`APP_NAME="NG-CDF Activities"`) |
| Short name (reports, `RULES.md`) | `activities` |
| Folder / repository | `activities.ngcdf.go.ke` / `NGCDF-Board/activities.ngcdf.go.ke` |
| Production | `activities.ngcdf.go.ke` |
| Staging | `staging-activities.ngcdf.go.ke` (follows `staging-smart.ngcdf.go.ke`) |
| Development | Local only: `activities-ngcdf.test` (follows `smart-ngcdf.test`). A shared `dev-activities.ngcdf.go.ke` only if a dev server exists for the others; I found no evidence one does. |
| Database | `db_ngcdf_activities` |
| Branches | `main` = Production, `staging` = Staging, `devs` = shared Development, `dev_<name>` per developer; promotion `dev_<name> → devs → staging → main` (as in `reports/_template/RULES.md`) |

"Activities" is preferred over "field" because the scope includes non-field activities (workshops, trainings, meetings) if the CEO wants them (§18, Q1). If scope is strictly field trips, `field.ngcdf.go.ke` is the alternative.

When the repo is created, add it to `reports/_template/RULES.md` and give it the shared `.claude/commands/` set, per the workspace `CLAUDE.md`.

---

## 6. Proposed architecture

A single conventional Laravel monolith. No API layer, no queue-heavy work, no microservices.

```
app/
  Enums/            ActivityStatus, ParticipationStatus, ParticipantRole, CostScope
  Models/           Activity, ActivityParticipant, ActivityCost, Staff, Department, ...
  Models/Concerns/  Auditable (writes audit_logs on create/update/delete)
  Services/         ActivityLifecycle   (every status change; writes history; validates transitions)
                    ActivityCosting     (totals, per-head, shared-cost allocation)
                    ParticipationStats  (per-staff and per-group aggregates)
                    SimilarityFinder    (candidate pairs + reasons + hypothetical saving)
  Policies/         ActivityPolicy (role + department scope)
  Livewire/         Dashboard, Activities/*, Participation/*, Costs/*, Consolidation/*, Reports/*, Settings/*
  Exports/          one Excel export per report
```

- **Aggregates in SQL**, not PHP loops: `GROUP BY` queries over indexed columns. Totals per activity are stored on `activities` (`estimated_total`, `actual_total`, `participant_count`) and recomputed by `ActivityCosting` in the same transaction as any cost/participant change, so lists and dashboards never sum on the fly.
- **Dashboard caching:** short cache (5 min) keyed by filter set, flushed on any activity write.
- **Scale:** hundreds of staff and a few thousand activities per year. MySQL with sensible indexes is ample.
- **Timezone** `Africa/Nairobi`; money `decimal(15,2)`; financial year July to June derived from `start_date`.

---

## 7. Database model

### Reference data (managed in Settings)

| Table | Key columns | Notes |
|---|---|---|
| `departments` | name, code, is_active | Official list, replaces "Other (specify)" |
| `regions` | name, code, `smart_cluster_id` null | Future link to Smart `Cluster` |
| `offices` | name, region_id null, is_headquarters | "Region/office" of a staff member |
| `designations` | name, job_group null | Normalises Smart's free-text designation |
| `activity_categories` | name, is_active | e.g. Monitoring & Evaluation, Training, Stakeholder engagement, Audit/Inspection, Governance/Board |
| `activity_types` | category_id, name | e.g. under M&E: Project inspection, Proposal review |
| `programmes` | name, is_active | "Related programme/project" |
| `cost_categories` | name, code, default_scope (`participant`/`activity`), is_active | DSA, Transport, Accommodation, Meals, Venue, Training materials, Airtime, Fuel, Other |
| `tags` | name (unique, lowercased) | Free vocabulary, admin can merge |

### Core

**`staff`**: staff_number (unique), name, email, phone, department_id, designation_id, office_id, is_active, joined_on, exited_on, user_id null, `smart_hq_staff_id` null, soft deletes.
`joined_on`/`exited_on` matter: "least participating" is only fair against the period a person was actually employed.

**`activities`**: reference (`ACT-2026-0001`, unique), title, purpose (text), category_id, type_id, programme_id null, organising_department_id, target_audience, county (fixed list of 47), location (town/venue), region_id null, start_date, end_date, status, external_participant_count, approval_reference null, approved_by/at, cancellation_reason, created_by, stored totals (`participant_count`, `attended_count`, `estimated_total`, `actual_total`), soft deletes.
Indexes: `(status, start_date)`, `(organising_department_id, start_date)`, `(category_id, type_id)`, `(county, start_date)`.

**`activity_tag`**: activity_id, tag_id.

**`activity_participants`**: activity_id, staff_id, role (lead, facilitator, participant, support, driver), status (nominated, confirmed, attended, absent, withdrawn), days_planned, days_attended, **snapshot** of department_id, designation_id, office_id at the time, remarks. Unique `(activity_id, staff_id)`; index `(staff_id, status)`.
The snapshot keeps history honest when someone changes department.

**`activity_costs`**: activity_id, cost_category_id, activity_participant_id null (null = shared, activity-level cost), description, quantity, unit_rate, estimated_amount, actual_amount null, travel_mode null (road/air/other, transport only), finance_reference null (imprest/voucher no.), recorded_by.
One row carries both estimate and actual, which keeps planned vs actual a column comparison rather than a join.

**`activity_status_histories`**: activity_id, from_status, to_status, reason, user_id, created_at. (Smart's `*StatusHistory` pattern; feeds the status trail modal.)

**`similarity_reviews`**: activity_a_id, activity_b_id (a < b, unique pair), decision (under_review, not_related, coordinated, consolidated), note, reviewed_by, reviewed_at. Stops a dismissed suggestion reappearing and records what management decided.

**`audit_logs`**: auditable_type/id, event, old_values/new_values (JSON), user_id, ip, created_at. Written by the `Auditable` trait on activities, participants, costs, staff and settings.

## 8. Main entities and relationships

```
Department 1─* Staff *─1 Designation          Region 1─* Office 1─* Staff
Category 1─* ActivityType
Activity *─1 Category/Type/Programme/Department(organising)/Region
Activity 1─* ActivityParticipant *─1 Staff
Activity 1─* ActivityCost *─0..1 ActivityParticipant ;  ActivityCost *─1 CostCategory
Activity *─* Tag ;  Activity 1─* StatusHistory ;  Activity pair 1─0..1 SimilarityReview
```

### Derived figures (all in `ActivityCosting`)

- **Total cost** = Σ actual_amount (falls back to estimated while actuals are missing, flagged "estimate").
- **Participant count** = staff with status attended (or confirmed, before the activity) + external count.
- **Cost per participant** = total ÷ participant count.
- **Cost attributable to a staff member** = their own cost lines + an equal share of shared costs among attendees. The split rule is a business decision (§18, Q4) and is always shown as "allocated share", never mixed silently into direct cost.
- **Cost by department**, two lenses, labelled: *by organising department* (who ran it) and *by participant department* (whose people went).

---

## 9. Main user roles

| Role | Can do | Scope |
|---|---|---|
| **CEO** | Everything read; dashboards, analytics, all reports; record consolidation decisions; approve activities (if approval is in-app, Q3) | All |
| **Administrator** | Users, roles, reference data, staff register, audit log. No activity edits by default. | All |
| **Department Coordinator** (focal person) | Create/edit own department's activities, participants and estimates; submit; mark attendance; close out | Own department (`scope_type = department`) |
| **Finance Officer** | Enter/verify actual amounts and finance references on any activity | All, costs only |
| **Viewer** (e.g. HODs, CEO's office staff) | Read dashboards and reports | All, or own department (configurable per user) |

Per-staff cost figures are personal data; they are visible only to CEO, Finance and Administrator unless you decide otherwise (Q10).

---

## 10. Main workflows

**Lifecycle:** `Draft → Planned → Approved → Ongoing → Completed`, plus `Postponed` and `Cancelled`.

| Transition | Who | Rule |
|---|---|---|
| Draft → Planned | Coordinator | Requires title, purpose, category/type, dates, location, ≥1 participant, ≥1 estimated cost line |
| Planned → Approved | CEO (or recorded by Coordinator with approval memo reference, Q3) | Estimates lock; later estimate edits are audited and shown as revisions |
| Approved → Ongoing | **Automatic** (daily scheduled command on start_date) | No one has to click it |
| Ongoing → Completed | Coordinator, then Finance | "Close-out": attendance and days confirmed for every participant; actuals entered. Completed is blocked until attendance is recorded. |
| Any open state → Postponed | Coordinator/CEO | Reason and new tentative dates; returns to Planned |
| Any open state → Cancelled | Coordinator/CEO | Reason required. Costs already incurred (deposits, tickets) stay recorded and counted. |

Every transition writes `activity_status_histories` and goes through `ActivityLifecycle`, never direct `update(['status'=>...])`.

**Other workflows:** add participants by searching the staff register (multi-select, department filter, shows each person's recent activity count so the coordinator sees load at the point of choosing); duplicate an activity (recurring inspections); bulk import of staff from CSV/Excel; review a similarity suggestion (§14).

---

## 11. Dashboard design

One page, PAKA-RANGI shell. Header filter row: period (This month, This quarter, This FY, Custom), department, region, category, status. Every figure respects the filters and links to the filtered list behind it.

**Stats band (collapsible, 8 tiles):** activities in period · upcoming (next 30 days) · completed · actual spend · estimated spend (with variance %) · average cost per activity · average cost per participant · staff who participated vs active headcount.

**Cards below:**

1. **Most expensive activities** (top 5, cost, per head, status)
2. **Potentially related activities** (top 5 pairs, reasons as chips, indicative saving marked *hypothetical*)
3. **Participation distribution**: bar chart of how many staff attended 0, 1, 2, 3 to 5, 6 to 10, 10+ activities
4. **Department participation**: each department's share of participation-days beside its share of headcount
5. **Spend by cost category** (stacked estimated vs actual)
6. **Upcoming and recent activities** (next 5, last 5)

Drill-down: any tile or bar opens the Activities or Participation page pre-filtered; any activity opens its detail page; any person opens their participation profile.

---

## 12. Reporting design

One Reports page listing the reports as cards; each report is a Livewire page with the standard filter row (date range or FY, department, region, category, type, status), an on-screen table, and **Excel + PDF export** (maatwebsite/excel, dompdf, as in Smart).

| Report | Grain |
|---|---|
| Activity expenditure | One row per activity: estimated, actual, variance, per head |
| Planned vs actual | Per activity and per cost category |
| Cost by category / by department / by period | Aggregates (month or quarter columns) |
| Activity participation | Per activity: participants by department, designation, region |
| Staff participation | Per staff: activities, days, missed, cost, last activity |
| Staff participation history | One person, all activities |
| Department / regional participation | Share of participation vs share of headcount |
| Potential consolidation | Suggested pairs, reasons, hypothetical saving, review decision |

PDFs carry the filter set and generation timestamp in the footer, so a printed figure can be traced.

---

## 13. Participation analytics approach

No fairness score. The app shows **transparent counts and comparisons** and leaves interpretation to management.

**Per staff member** (profile page and staff table): activities attended; nominated but absent ("missed", pending Q5); withdrawn; field days; direct cost + allocated share (shown separately); last activity and days since; breakdown by category and by role (lead vs participant); monthly sparkline.

**Across staff:**

- Most active / least active tables, both computed **only over staff employed during the period** and including those with zero.
- "Not participated in N days" list with an adjustable N.
- Distribution histogram (above) plus two plain statistics: median activities per staff member, and the share of all participation held by the most active 10% of staff. Both are standard, explainable measures, not judgements.
- Group views by department, designation, region/office and activity type: participants, participation-days, cost, and **share of participation vs share of headcount**, shown side by side as two numbers. The app does not label anything "over" or "under"-represented; it shows the gap.
- Trend over time: monthly participation-days and unique participants.

Filters: date range, department, region, category, type, status.

---

## 14. Activity similarity and consolidation approach

**Rule-based and explainable**, no machine learning. `SimilarityFinder` compares non-cancelled activities within the selected period whose dates are within a window of each other (default ±21 days, configurable) and scores each pair on signals it can explain:

| Signal | Example chip |
|---|---|
| Same type (strong) / same category (weak) | "Both: Project inspection" |
| Same county, or same region | "Both in Kakamega" |
| Overlapping or adjacent dates | "Dates overlap 2 days" |
| Same organising department | "Both organised by M&E" |
| Shared participants | "4 people on both" |
| Shared tags / same programme | "Tags: proposal-review" |
| Similar title (normalised word overlap) | "Similar title" |

A pair appears when it meets a minimum number of signals (default: same category + same county/region + date window, or any three strong signals). Thresholds sit in `config/activities.php` so they can be tuned after real data arrives.

**For each pair (or cluster of pairs) the view shows** the current figures side by side, and, clearly separated under a *Hypothetical if coordinated* heading:

- combined participant count (unique people, not a sum);
- combined current cost (actual, or estimate where not yet spent);
- potentially duplicated cost: shared activity-level categories present in both (venue, facilitation) and transport/DSA for people who appear on both;
- indicative saving = that duplicated amount, with the formula shown.

Management records a decision on the pair (`similarity_reviews`). Dismissed pairs leave the list. The app never merges activities itself.

---

## 15. Navigation

Sidebar (PAKA-RANGI shell):

- **Dashboard**
- **Activities**: list (primary tabs: Upcoming, Ongoing, Needs close-out, Completed, All), New activity, activity detail (secondary tabs: Overview, Participants, Costs, History)
- **Participation**: staff table and analytics; staff profile
- **Costs**: expenditure analysis, planned vs actual
- **Consolidation**: potentially related activities
- **Reports**
- **Settings** (Admin): Staff register, Departments, Regions & offices, Designations, Categories & types, Cost categories, Programmes, Tags, Users & roles, Audit log

---

## 16. Integration opportunities with Smart (later, not now)

| Opportunity | What it needs | Recommendation |
|---|---|---|
| Sign in with Smart credentials | Smart already exposes `POST /api/v1/auth/verify` (used by `mp`). | Phase 6 option. Phase 1 uses local Jetstream accounts with 2FA. |
| Staff register sync | A new read-only Smart endpoint over `HqStaff` (staff_number, name, designation, active). `staff_number` is the join key; `smart_hq_staff_id` is already reserved. | Worth doing once Smart's HQ staff data is complete. |
| Regions | Smart `Cluster`, via `regions.smart_cluster_id`. | Cheap once the staff sync exists. |
| Departments | **Smart has no HQ department model.** | Owned here for now; if Smart adds one, this app becomes a consumer. |
| Actual costs from finance | HQ spend is likely not in Smart (Smart is constituency-focused). | Keep manual entry with a finance reference; revisit only if a source exists. |

Every integration is designed for, none is built in Phase 1.

---

## 17. Risks and ambiguities

1. **Completeness drives value.** "Least participating" and "similar activities" are only meaningful if *all* activities and *all* staff are recorded. A partial register makes some people look inactive. Mitigation: full staff import up front; "needs close-out" queue; dashboard shows data completeness (activities missing actuals or attendance).
2. **Data-entry burden.** If logging is slow, it will not happen. Mitigation: duplicate-activity, bulk participant add, estimates computed from quantity × rate.
3. **Cost attribution** (shared costs, external participants) changes per-head figures materially. Needs an agreed rule (Q4).
4. **Personal data.** Per-person cost and participation are sensitive under the Data Protection Act 2019. Restricted roles, audit of access to profiles is optional (Q10).
5. **Suggestions read as accusations.** A "related" pair may be legitimately separate. Wording is "potentially related", reasons are shown, and decisions are recorded.
6. **Actuals arrive late**, so recent months will look under-spent. Tiles show how much of the figure is still estimate.
7. **Staff mobility** (transfers, exits) distorts per-department figures without the participation snapshot and employment dates, both included.
8. **Hostnames and branch names** are proposals inferred from one staging example (`staging-smart`); infrastructure may differ.

---

## 18. Questions requiring a business decision

1. **Scope:** field activities only, or every organisational activity (workshops, trainings, internal and Board meetings)?
2. **Who enters data:** one team in the CEO's office, or a focal person per department? Can HODs see only their department?
3. **Approval:** does the CEO approve activities *in the app*, or is approval done by memo and only its reference recorded? (I recommend recording the reference; no in-app approval chain.)
4. **Cost attribution:** split shared costs equally among attending staff? Do external participants (MPs, NGCDFC members, partners) count in "cost per participant"?
5. **"Activities missed":** nominated but did not attend? Or something broader (e.g. activities in their department they were not on)?
6. **Actual costs:** who enters them, from what source (imprest, vouchers, IFMIS), and how soon after the activity?
7. **Reference data:** the official list of departments, designations/job groups, regions and offices, and the source of the staff roster (HR export, Smart `HqStaff`?).
8. **DSA rates:** should estimates be calculated from a rate table (by job group and destination), or entered by hand? (Recommend by hand in Phase 1.)
9. **Historical data:** import past records from the current spreadsheet/HTML tool? From what date?
10. **Visibility of per-person cost:** CEO, Finance and Admin only, or also HODs for their own staff?
11. **Attachments:** do activities need supporting documents (approval memo, reports, receipts)? This adds secure file storage.
12. **Sign-in:** local accounts for now (recommended), or Smart credentials from day one?
13. **Reporting period:** financial year (July to June) as the default period?
14. **Names:** confirm `activities.ngcdf.go.ke` / NG-CDF Activities, and the staging hostname pattern.

---

## 19. Recommended implementation phases

| Phase | Delivers | Exit criterion |
|---|---|---|
| **0. Foundations** | Repo, `CLAUDE.md` (Boost + DB-safety rules), CI (Pint, Pest), PAKA-RANGI shell copied from Smart, Jetstream + 2FA, roles/permissions seeder, `Auditable` trait + audit log, reference data CRUD, staff register with CSV/Excel import | Admin can sign in, load departments and the full staff roster |
| **1. Activities core** | Activity CRUD, lifecycle service + status trail, participants (bulk add, attendance, snapshots), cost lines (estimated/actual), stored totals, detail page, list with tabs and filters | Coordinator can plan, run and close out an activity end to end; tests cover lifecycle, costing and authorisation |
| **2. Dashboard and participation** | CEO dashboard, participation analytics, staff profile | CEO can answer the participation questions in the brief from the screen |
| **3. Costs and reports** | Cost analysis, planned vs actual, the report set with Excel/PDF export | Every report exports with its filters |
| **4. Consolidation** | Similarity finder, related-activities view, hypothetical savings, review decisions | Suggestions reviewed on real data and thresholds tuned |
| **5. Production readiness** | Backups (`spatie/laravel-backup`), Sentry, deployment config for staging/production, historical import, UAT with the CEO's office | Live on staging, signed off, promoted to production |
| **6. Integration (optional)** | Smart sign-in, HQ staff and region sync | Separate decision |

Phases 0 and 1 are the minimum useful product; 2 and 3 deliver the executive value; 4 is the differentiator and benefits from having real data first.

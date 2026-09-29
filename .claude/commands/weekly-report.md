Generate a weekly development report for Henry. No date argument needed - the range is computed automatically.

## Date Range Logic

Weeks run **Saturday → Friday**.

- If today is **Friday**: use the current Sat→Fri week (it's complete).
- If today is **anything else** (Sat, Sun, Mon, Tue, Wed, Thu): use the **previous** Sat→Fri week (current week is not yet complete).

Compute the exact dates in your head before running any git commands. Then at the top of your response, state the period you are reporting on, e.g.:
> Reporting on: 2026-05-02 (Sat) → 2026-05-08 (Fri)

---

## Steps

1. **Refresh remote branches, then pull commits across ALL branches** (local + every remote-tracking branch), filtering by author `henrymanyonyi` OR `Henry Manyonyi`, using the computed date range:
   - activities.ngcdf.go.ke: `/Users/henrymanyonyi/Documents/projects/ngcdf/activities.ngcdf.go.ke`

   ```
   git -C /Users/henrymanyonyi/Documents/projects/ngcdf/activities.ngcdf.go.ke fetch --all --prune
   git -C /Users/henrymanyonyi/Documents/projects/ngcdf/activities.ngcdf.go.ke log --all --source --after="<start_date>" --before="<day_after_end>" --author="henrymanyonyi\|Henry Manyonyi" --format="%h %ad %S %s" --date=short --no-merges
   ```

   The `%S` column is the ref each commit was reached through (e.g. `refs/heads/dev_newton`, `refs/remotes/origin/dev_errol`). Strip the `refs/heads/` or `refs/remotes/origin/` prefix to get the bare branch name before mapping it below.

2. **Map branches to labels**:

   | Branch | Label |
   |---|---|
   | `main` | Production |
   | `staging` | Staging |
   | `devs` | Development (shared) |
   | `dev_newton` | Newton |
   | `dev_bernard_jerome` | Bernard Jerome |
   | `dev_errol` | Errol |
   | `dev_henry` | Henry |
   | `dev_imbai` | Imbai |
   | `dev_joemwaks` | Joseph Mwakai |
   | `dev_josh` | Josh |
   | `dev_mulei` | Jimmy Mulei |
   | `dev_yvette` | Yvette |

   A commit belongs to the branch given by its `%S` source ref. If a commit's source resolves to a shared branch (`main`/`staging`/`devs`) but it's also reachable from `dev_henry`, prefer `dev_henry`. Any branch not in this table: use its bare name as the label as-is (new branches get created - don't drop their commits just because they're unmapped).

3. **Filter commits** - exclude trivial changes such as:
   - Namespace/directory casing fixes
   - Table header reordering
   - Stale guard removals
   - Minor UI label tweaks
   - WIP stash entries
   - Merge commits

   Keep: new features, architectural changes, business logic fixes, security work, compliance work, integrations, performance improvements, significant refactors.

4. **Group and condense** - related commits under the same theme should be collapsed into a single numbered item. Do not give each commit its own number.

5. **Format the report** using this structure:

```
## Development Report: <start> to <end>

---

### activities.ngcdf.go.ke

**N. Title**
Description sentence(s) - what changed and why it matters.

---
```

Only include sections that have qualifying commits. Number items sequentially across the whole report.

6. **Save a PDF copy** to `dev_updates_report/` (project root, create if missing) as `dev_updates_report/YYYY-MM-DD-weekly-dev-report.pdf`, where the date is the last day of the period:

   - Write the report as an HTML fragment (no `<html>` or `<head>`) at `/tmp/weekly-report.html`, following the building blocks in `.claude/report-template/STYLE.md`: a `stats` row of headline numbers, a `lead` summary paragraph, then sections and numbered `items`.
   - Render with the shared branded template so every report has the same layout:
     ```
     .claude/report-template/render.sh --title "Weekly Development Report" --period "<start> to <end, in words e.g. 12 to 18 September 2026>" --subtitle "<project> Engineering" --body /tmp/weekly-report.html --out "dev_updates_report/YYYY-MM-DD-weekly-dev-report.pdf"
     ```
   - Write like a person. No em dashes, en dashes, arrows or emoji anywhere in the report text (the renderer rejects them, fix and re-run). Use "to" for ranges.
   - Delete `/tmp/weekly-report.html` afterward and mention the saved PDF path in your final response.

7. **After the report**, print:

```
---
Next report period: <next_saturday> → <next_friday>
Run /weekly-report on or after <next_friday>.
```

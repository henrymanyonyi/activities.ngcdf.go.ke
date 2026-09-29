Generate a weekly development report covering **all developers** who committed during the period. No date argument needed - the range is computed automatically.

## Date Range Logic

Weeks run **Saturday → Friday**.

- If today is **Friday**: use the current Sat→Fri week (it's complete).
- If today is **anything else** (Sat, Sun, Mon, Tue, Wed, Thu): use the **previous** Sat→Fri week (current week is not yet complete).

Compute the exact dates in your head before running any git commands. Then at the top of your response, state the period you are reporting on, e.g.:
> Reporting on: 2026-05-02 (Sat) → 2026-05-08 (Fri)

---

## Steps

### 1. Refresh remote branches, then pull ALL commits across ALL branches

Run this in the repo (no `--author` filter; `--source` is required so each commit can be mapped to its branch in step 3):

```
git -C /Users/henrymanyonyi/Documents/projects/ngcdf/activities.ngcdf.go.ke fetch --all --prune
git -C /Users/henrymanyonyi/Documents/projects/ngcdf/activities.ngcdf.go.ke log --all --source --after="<start_date>" --before="<day_after_end>" --format="%h %ad %an %S %s" --date=short --no-merges
```

Repo:
- activities.ngcdf.go.ke: `/Users/henrymanyonyi/Documents/projects/ngcdf/activities.ngcdf.go.ke`

### 2. Normalise author names

Canonicalise using this map (built from this repo's actual commit history - emails are the most reliable disambiguator when two people share a first name):

| Canonical name | Variants to merge |
|---|---|
| Newton | NewtonKamau, NewtonKamauNg, newton |
| Henry | Henry Manyonyi, henrymanyonyi, henrymanyonyi4 |
| Bernard Jerome | BernardJerome, Jerome Bernard |
| Jerome Mugita | Jerome Mugita |
| Errol | ErrolKitsiiri, Errol-Kitsiiri |
| Imbai | imbai |
| Joseph Mwakai | MwakaiJoseph |
| Jimmy Mulei | jimmy mulei |
| Josh | Your Full Name |

`Bernard Jerome` and `Jerome Mugita` are two different people who share the `dev_bernard_jerome` branch - don't merge them into one even though both first names are "Jerome"/share that branch; use the email domain/handle if a commit's author string is ambiguous (`bjerome@ngcdf.go.ke` / `jeromebernard@Mugitas-MacBook-Pro.local` → Bernard Jerome; `jeromemugita@rindo.jeromemugita.com` → Jerome Mugita).

The `dev_yvette` branch exists (merged via PRs) but no commit in history is actually authored under a "Yvette" identity - her work shows up under teammates' names. Don't invent a Yvette entry in this author map; if a commit's `%S` resolves to that branch, just label the *branch* "Yvette" per the table in step 3 while keeping the real author name from `%an`.

Any author not in the map above: use their name as-is. Skip automated/bot commit authors (e.g. Dependabot, GitHub Actions, CI bots) if any appear - none exist in this repo's history as of the last update to this file, but new ones may show up later.

### 3. Map branches to labels

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

The `%S` column in the git log output is the ref each commit was reached through (e.g. `refs/heads/dev_newton`, `refs/remotes/origin/dev_errol`). Strip the `refs/heads/` or `refs/remotes/origin/` prefix to get the bare branch name, then map it using this table. A commit belongs to the branch given by its `%S` source ref; if that resolves to a shared branch (`main`/`staging`/`devs`) but the commit is also reachable from the author's own `dev_<name>` branch, prefer the personal branch. Any branch not in this table: use its bare name as the label as-is - new branches get created, don't drop their commits just because they're unmapped.

### 4. Filter commits

Exclude:
- Merge commits (already excluded by `--no-merges`)
- Namespace/directory casing fixes
- Table header reordering
- Stale guard removals
- Minor UI label tweaks
- WIP stash entries
- Dependency bumps with no meaningful code change
- Empty or near-empty fixup commits

Keep: new features, architectural changes, business logic fixes, security work, compliance work, integrations, performance improvements, significant refactors.

### 5. Group, condense, and format

For each developer who has at least one qualifying commit, produce a section. Within each developer's section, group commits by branch/label. Collapse related commits on the same theme into a single numbered item - do not give each commit its own number.

Format:

```
## Team Development Report: <start> to <end>

---

### <Developer Name>

**activities.ngcdf.go.ke - <Branch Label>**
1. **Title** - Description sentence(s): what changed and why it matters.
2. **Title** - Description.

*(only include sub-sections that have qualifying commits for this developer)*

---

### <Next Developer Name>

...
```

Order developers by commit volume (most commits first). Only include developers who have at least one qualifying commit in the period.

### 6. Save a PDF copy

**Save a PDF copy** to `dev_updates_report/` (project root, create if missing) as `dev_updates_report/YYYY-MM-DD-team-dev-report.pdf`, where the date is the last day of the period:

   - Write the report as an HTML fragment (no `<html>` or `<head>`) at `/tmp/team-report.html`, following the building blocks in `.claude/report-template/STYLE.md`: a `stats` row of headline numbers, a `lead` summary paragraph, then sections and numbered `items`.
   - Render with the shared branded template so every report has the same layout:
     ```
     .claude/report-template/render.sh --title "Team Development Report" --period "<start> to <end, in words e.g. 12 to 18 September 2026>" --subtitle "<project> Engineering" --body /tmp/team-report.html --out "dev_updates_report/YYYY-MM-DD-team-dev-report.pdf"
     ```
   - Write like a person. No em dashes, en dashes, arrows or emoji anywhere in the report text (the renderer rejects them, fix and re-run). Use "to" for ranges.
   - Delete `/tmp/team-report.html` afterward and mention the saved PDF path in your final response.

### 7. Footer

After the report, print:

```
---
Next report period: <next_saturday> → <next_friday>
Run /team-report on or after <next_friday>.
```

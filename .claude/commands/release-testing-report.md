---
description: Release testing report for the QA team, the bug fixes, enhancements and features ready to test in FAPM (Activities) and what is on each environment (PDF in dev_updates_report/)
argument-hint: "[dates or 'last week', default: last completed Sat to Fri week]"
---
Generate a **release testing report** for the testing team on `activities.ngcdf.go.ke`: the bug fixes, enhancements and features that have been built and are ready to test, and which environment each is on. Arguments: $ARGUMENTS. No date argument is needed, the range is computed automatically.

The audience is testers, not developers. Be specific about where to find a change, who can use it and what to try. Do not paste code or commit hashes into the PDF.

## Date Range Logic

Weeks run **Saturday to Friday**. On a Friday use the current week, on any other day use the previous completed week, unless the user gives dates. State the period at the top of your reply. The promotion gap (step 3) is always measured as of now, whatever the period.

## Steps

1. **Refresh and collect.** Run:

   ```
   git -C /Users/henrymanyonyi/Documents/projects/ngcdf/activities.ngcdf.go.ke fetch --all --prune
   git -C /Users/henrymanyonyi/Documents/projects/ngcdf/activities.ngcdf.go.ke log --all --source --after="<start_date>" --before="<day_after_end>" --format="%h %ad %an %S %s" --date=short --no-merges
   ```

   `%S` is the ref each commit was reached through; strip `refs/heads/` or `refs/remotes/origin/` to get the branch. Merge author variants of the same person (Henry, Newton, Bernard Jerome, Errol, Imbai, Joseph Mwakai, Jimmy Mulei, Noah, Josh; Bernard Jerome and Jerome Mugita are different people). Skip bots.

2. **Map branches to environments.**

   | Branch | Environment | What testers do there |
   |---|---|---|
   | `main` | Production | Smoke check after a release, confirm nothing regressed |
   | `staging` | Staging | Release candidate: full test of each item, regression, sign off |
   | `devs` | Development (shared) | Early testing of new work, report problems before promotion |
   | `dev_<name>` or any other branch | Not deployed | Not testable yet, list it only under "Coming later" |

   Work is testable on the highest environment it has reached. Promotion flow is dev_<name> to devs to staging to main.

3. **Work out what sits on each environment.**
   - Landed in the period: commits on `origin/devs`, `origin/staging`, `origin/main`.
   - Promotion gap as of now: `git -C /Users/henrymanyonyi/Documents/projects/ngcdf/activities.ngcdf.go.ke log --no-merges --format="%h %ad %an %s" --date=short origin/staging..origin/devs` (on Development, not yet on Staging) and `origin/main..origin/staging` (on Staging, not yet on Production). This is the real "ready to test" list even when the commits are older than the period.
   - Tags in the period mark releases to Production.

4. **Understand what to test.** Filter out merges, casing fixes, label tweaks, WIP, dependency bumps and housekeeping, then collapse related commits into one test item. For each item, use `git show --stat` and read the changes where the message is unclear, and note:
   - the screen, menu, route or API endpoint affected (check `routes/`, Livewire components, views)
   - who can reach it (roles and permissions)
   - database changes (new migrations, seeders) and config or `.env` changes that must be in place on that environment before testing
   - queue, scheduled job, email or PDF behaviour that is touched
   - whether the change added or updated automated tests (`tests/`), and if it did not, say manual testing matters more
   - nearby features that could break (regression areas)
   Only state facts you can see in the code. If something is unclear, write "confirm with <developer>" instead of guessing.

5. **Build the report** as an HTML fragment (see `.claude/report-template/STYLE.md` for the building blocks), in this order:
   - `stats` row: items to test, ready on Development, on Staging, live on Production this period.
   - **Summary**: a `lead` paragraph of what the testing team should focus on, then `points` bullets with the responsible developer as a `badge`.
   - **Environment status**: a table with a row per environment (Production, Staging, Development) giving what is there, when it last changed and what testers should do. Use `status-live`, `status-pending` and `status-risk` chips.
   - **What to test now**: one `h3` per environment (Development, then Staging, then Production smoke checks). Under each, numbered `items`. Each item has a bold title, what changed, where to find it and who can use it, the scenarios to try (normal path, edge cases, wrong-role access, bad input), data or setup needed, and a risk chip (`status-risk` for money, permissions, data migrations or bulk actions; `status-pending` for medium; `status-live` for low). End with the developer name so testers know who to ask.
   - **Regression checklist**: a short table of existing features to re-check and why.
   - **Setup notes**: migrations, env values, seeders or queue workers to confirm on each environment before testing. Omit if none.
   - **Coming later**: work only on personal branches, with owner. Not testable yet.
   - **Risks and gaps**: large unpromoted gaps, changes without tests, environments with no recent update. Facts only.
   If nothing needs testing, say so in a `lead` paragraph and skip the empty sections.

6. **Save a PDF copy** to `dev_updates_report/` (project root, create if missing) as `dev_updates_report/YYYY-MM-DD-release-testing-report.pdf`, where the date is the last day of the period. Write the fragment to `/tmp/release-testing-report.html`, then:

   ```
   .claude/report-template/render.sh --title "Release Testing Report" --period "<start> to <end, in words e.g. 12 to 18 September 2026>" --subtitle "FAPM Testing" --body /tmp/release-testing-report.html --out "dev_updates_report/YYYY-MM-DD-release-testing-report.pdf"
   ```

   Write like a person. No em dashes, en dashes, arrows, ellipsis characters or emoji anywhere in the text (the renderer rejects them, fix and re-run). Use "to" for ranges. Delete `/tmp/release-testing-report.html` afterward and give the PDF path in your final response.

7. **After the report**, print:

```
---
Next report period: <next_saturday> to <next_friday>
Run /release-testing-report on or after <next_friday>.
```

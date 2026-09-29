---
name: daily-dev-report
description: Generates a daily development report summarizing bug fixes, feature work, and other notable changes made the previous day, pulled from both git commit history and ClickUp tickets. Also handles moving ClickUp tickets marked "Done" by developers into "Awaiting Review" so the testing team can pick them up, and generates a dated summary report file for the testing team documenting what's ready for QA. Use this whenever the user asks for a "daily report," "standup report," "what happened yesterday," "yesterday's progress," wants to "clear tickets," "move tickets to review," asks to sync/process ClickUp tasks for testing handoff, or wants a "testing summary," "QA handoff report," or "report for the testing team."
---

# Daily Dev Report & ClickUp Review Handoff

Three related jobs live in this skill:

1. **Daily Report** - summarize what changed "yesterday" (or a given date range) across git repos and ClickUp, split into Bug Fixes / Features / Other.
2. **ClickUp Review Handoff** - find tickets developers marked "Done" and move them to "Awaiting Review" so testing can take over.
3. **Testing Handoff Summary Report** - generate a dated, saved report file (features, bug fixes, and other additions) for the testing team, separate from the in-chat daily standup report.

Both #1 and #2 rely on the ClickUp MCP connector. If it isn't connected yet, tell the user to connect it (Claude will prompt for connector opt-in / selection) before continuing. #3 can reuse data gathered during #1 and/or #2 in the same session, or gather it fresh if run standalone.

---

## Configuration (fill in once, then reuse)

Ask the user for these on first use, and keep them in mind for the rest of the conversation/session (don't re-ask if already provided earlier in the chat):

- **ClickUp Space/Folder/List(s)** to scan - name or ID. A project can have multiple lists (e.g., "Constituency Fund System", "Private Wealth Platform"); ask which one(s) apply, or scan all lists the user has access to if they say "everything."
- **Status names actually used in their ClickUp** - don't assume "Done" and "Awaiting Review" are the exact labels; confirm the literal status strings (ClickUp is case- and label-sensitive, e.g. could be "done", "Complete", "Ready for QA", "Awaiting Review", "In Review").
- **Git repo path(s) / GitHub repo(s)** to pull commit history from, and the branch(es) that matter (e.g. `develop`, `main`).
- **Author/dev mapping** (optional) - if the user wants the report grouped by developer, get a rough map of git author names/emails to ClickUp assignee names, since these often don't match exactly.

Store nothing sensitive (tokens, keys) in this file - connector auth is handled by the MCP connection itself.

---

## Part 1: Daily Report

### Step 1 - Determine the date range
Default to "yesterday" (previous calendar day) unless the user specifies otherwise (e.g. "since Friday," "last 3 days").

### Step 2 - Pull git activity
For each configured repo/branch, get commits in the date range:
```
git log --since="<date> 00:00" --until="<date> 23:59" --pretty=format:"%h|%an|%ad|%s" --date=short
```
Read commit messages for signal on intent - conventional prefixes (`fix:`, `feat:`, `chore:`) are a strong hint, but don't rely on them exclusively since not every repo enforces them. Also check for merged PR titles if working from a GitHub-hosted repo (use the GitHub tool if connected, or `git log --merges`).

### Step 3 - Pull ClickUp activity
Query the configured list(s) for tasks whose status changed, or that had activity, within the date range. Useful signals:
- Tasks moved to a "done"/"complete"-type status yesterday → likely bug fixes or completed features (use the ticket type/tag/custom field to tell which, or the title/description wording if untagged).
- Tasks with new comments or checklist activity yesterday but not yet closed → candidates for "in progress" / "other notable matters," not full completions.
- Ticket priority flags (urgent/blocker) that changed → worth calling out separately, since these often signal "important matters" beyond routine fixes/features.

### Step 4 - Categorize and reconcile
Merge the git and ClickUp signals into three buckets:
- **Bug Fixes** - commits/tickets clearly about correcting broken behavior.
- **Features / Builds Added** - new functionality, new components, new modules.
- **Other Notable Matters** - things that don't fit cleanly above: blocked tickets, scope changes, urgent/priority flags, environment or data issues, decisions made, anything a lead would want to know even if it's not a "fix" or a "feature" (e.g. "GFS Code / Sector schema decoupled - downstream CRUD components need updating").

When a git commit and a ClickUp ticket clearly refer to the same piece of work, merge them into one line rather than listing both.

### Step 5 - Output format
Keep it scannable - this is a standup-style report, not a formal document. Default to presenting it directly in the conversation (not a file) unless the user asks to save/export it:

```
## Daily Report - <date>

### Bug Fixes
- <short description> (<repo>@<hash> / CU-<ticket id>)

### Features / Builds Added
- <short description> (<repo>@<hash> / CU-<ticket id>)

### Other Notable Matters
- <short description>

### Still Open / Carried Over
- <ticket that's in progress but not closed, if worth flagging>
```

If a day has nothing in a bucket, omit that section rather than showing it empty.

---

## Part 2: ClickUp Review Handoff

**Note for the user up front:** if the *only* thing needed is "when a task hits Done, move it to Awaiting Review," ClickUp's own native Automations (Automate tab on a List) can do this with zero Claude involvement - a simple "When status changes to Done → set status to Awaiting Review" rule. Mention this as the lower-maintenance option. Use this skill instead when the user wants that move to also involve judgment (e.g., filtering by ticket type, adding a summary comment, notifying the tester, or batching it into the daily report run) rather than a pure 1:1 status swap.

### Step 1 - Find candidates
Query the configured list(s) for tasks currently in the "Done" status (the literal label confirmed in Configuration above).

### Step 2 - Confirm before moving
List the candidate tickets (title, assignee, ID) and confirm with the user before changing anything, unless the user has explicitly said to auto-move without confirmation for this session.

### Step 3 - Move and annotate
For each confirmed ticket:
- Update status to "Awaiting Review" (or the confirmed literal label).
- Optionally add a short comment noting it's ready for QA and who completed it, if the user wants that trail.
- If assigning to a specific tester/QA person or list, set that assignee too (ask if unclear).

### Step 4 - Summarize
Report back which tickets were moved, and flag any that looked like "Done" but seemed incomplete (e.g. no recent commit tied to them, or a description suggesting unfinished sub-tasks) so the user can double check before they go to testing.

---

## Part 3: Testing Handoff Summary Report

This is a **written, saved report** for the testing team - distinct from Part 1's in-chat standup summary. It covers Features, Bug Fixes, and Other Additions that are ready (or about to be handed off) for QA, and is always saved as a dated file rather than just shown in chat.

Trigger this part when the user asks for a "testing summary," "QA report," "report for the testers," or after running Part 2 (moving tickets to "Awaiting Review") and wanting a written record of what just got handed off.

### Step 1 - Gather source data
- If Part 1 and/or Part 2 already ran earlier in this session, reuse that data rather than re-querying.
- Otherwise, pull fresh: git commits for the relevant date range (Part 1, Step 2) and ClickUp tickets that are in or moving into the "Awaiting Review" status (or whichever status was just confirmed in Part 2), plus any other tickets the user wants included.
- Scope defaults to "today's handoff" (tickets/commits processed in this session) unless the user gives an explicit date range.

### Step 2 - Categorize
Same three buckets as Part 1:
- **Features Added**
- **Bug Fixes**
- **Other Additions / Notes for Testing** - anything QA should know going in: config/env changes, migrations that need running, areas with known limitations, or specific things to focus testing on.

For each item, include enough for a tester to locate and start on it: short description, repo/commit hash if applicable, and ClickUp ticket ID (`CU-xxxxxxx`).

### Step 3 - Generate the file
- **Output format: PDF, always.** This file is shared directly with the testing team, so a raw `.md` file is not an acceptable deliverable - never leave the final artifact as markdown.
- **Directory:** `dev_updates_report/` (create it if it doesn't exist, at the project root or working directory - ask the user once if unclear where, then reuse that location for future runs).
- **Filename:** dated, in the form `dev_updates_report/YYYY-MM-DD-testing-summary.pdf` (use the report's date, i.e. the date the work is being handed off - not necessarily "today" if the user is generating a report for a past date). If more than one report is generated for the same date in a session, append `-2`, `-3`, etc. rather than overwriting.
- **Content template** (build this as styled HTML, not raw markdown, so it renders cleanly as a PDF):

```markdown
# Testing Handoff Summary - <YYYY-MM-DD>

## Features Added
- <description> (<repo>@<hash> / CU-<ticket id>)

## Bug Fixes
- <description> (<repo>@<hash> / CU-<ticket id>)

## Other Additions / Notes for Testing
- <description> - <anything QA needs to know: migrations to run, env changes, focus areas>

## Tickets Moved to Awaiting Review
- CU-<ticket id> - <title> (assignee: <name>)
```

Omit a section entirely if it has nothing in it, same as Part 1.

- **How to produce the PDF:** write the content as an HTML fragment at `/tmp/testing-summary.html` using the building blocks in `.claude/report-template/STYLE.md`, then render with the shared branded template (same look as every other NG-CDF report):
  ```
  .claude/report-template/render.sh --title "Testing Handoff Summary" --period "<date in words>" --subtitle "<project> Engineering" --body /tmp/testing-summary.html --out "dev_updates_report/YYYY-MM-DD-testing-summary.pdf"
  ```
  No em dashes, en dashes, arrows or emoji in the text (the renderer rejects them). Only the final `.pdf` belongs in `dev_updates_report/`; delete the scratch `.html`.

### Step 4 - Confirm and hand off
- Save the PDF to `dev_updates_report/`.
- Tell the user the filename/path and give a one-line summary of what's in it (e.g. "3 features, 2 bug fixes, 1 note for QA - saved to dev_updates_report/2026-07-11-testing-summary.pdf").
- If the user wants it shared beyond a saved file (e.g. posted as a ClickUp comment, sent in a message), ask before doing so rather than assuming.

---

## Notes
- Ticket IDs in ClickUp are usually shown as `CU-xxxxxxx` - use these in the report so the user can jump straight to the ticket.
- This skill assumes the ClickUp MCP connector and git access are already available; if either is missing, tell the user what's missing rather than guessing at data.
- Keep the daily report (Part 1) concise - a lead skimming it before standup, not reading a changelog document. The testing summary (Part 3) can be slightly more detailed since it's a saved artifact meant to be referenced, not just skimmed once.
# NG-CDF report style

Every report, in every project and in `ngcdf/reports/`, is a PDF made with `render.sh` from an HTML body fragment.
Never hand-roll CSS or call Chrome directly. The masthead (logo, organisation name, title, period) and footer are added by the renderer.

    <template dir>/render.sh --title "Weekly Development Report" --period "12 to 18 September 2026" \
        --subtitle "Smart NG-CDF Engineering" --body /tmp/body.html --out "<output>.pdf"

`--subtitle` is the team or scope shown after "Maendeleo kwa Wote" (for example `Smart NG-CDF Engineering`, `All Projects`, `Management Report`).
Write the body to a scratch file outside the repo (`/tmp` or the session scratchpad), render, then delete it. Only the PDF is kept.

## Body building blocks

| Purpose | Markup |
|---|---|
| Headline numbers | `<div class="stats"><div class="stat"><div class="n">155</div><div class="l">commits landed</div></div>...</div>` (3 or 4 tiles) |
| Section heading | `<h2>` for sections, `<h3>` for sub-sections |
| Section with count badge | `<div class="section-head"><h2>Joseph Mwakai</h2><span class="badge">31 commits</span></div>` then `<p class="sub">Programs reporting</p>` |
| Intro paragraph | `<p class="lead">...</p>` |
| Summary bullets with owner on the right | `<ul class="points"><li><span><b>Topic:</b> sentence.</span><span class="badge">Henry</span></li></ul>` |
| Numbered work items | `<ol class="items"><li><b>Title:</b> what changed and why it matters.</li></ol>` (numbers are automatic, restart per list) |
| Tables | plain `<table><tr><th>..</th></tr><tr><td>..</td></tr></table>` |
| Highlight or risk note | `<div class="callout">...</div>` |
| Environment or risk status chips | `<span class="status-live">Live</span>`, `status-pending`, `status-risk` |

## Voice: write like a person

- No em dashes, en dashes, double hyphens, arrows, ellipsis characters or emoji. `render.sh` rejects them. Write ranges as "12 to 18 September 2026" and join clauses with commas, full stops or "and".
- Plain, specific sentences. Say what changed and why it matters. Avoid filler and machine-sounding words: leverage, robust, seamless, delve, comprehensive, streamline, cutting-edge, "it is worth noting", "in order to".
- Use people's first names as in the author map. Do not invent work, people or numbers. If something is unknown, leave it out or say it was not visible in git.
- Keep commit hashes and ticket IDs in engineering reports, leave them out of management reports.

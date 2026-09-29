#!/usr/bin/env bash
# Renders an HTML body fragment into a branded NG-CDF PDF.
# Usage: render.sh --title "Weekly Development Report" --period "12 to 18 September 2026" \
#                  --subtitle "Smart NG-CDF Engineering" --body body.html --out /path/file.pdf
# The body is an HTML fragment (see STYLE.md for the classes). The header, logo, CSS and footer are added here.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TITLE=""; PERIOD=""; SUBTITLE="NG-CDF Engineering"; BODY=""; OUT=""
while [ $# -gt 0 ]; do
  case "$1" in
    --title) TITLE="$2"; shift 2;;
    --period) PERIOD="$2"; shift 2;;
    --subtitle) SUBTITLE="$2"; shift 2;;
    --body) BODY="$2"; shift 2;;
    --out) OUT="$2"; shift 2;;
    *) echo "unknown option $1" >&2; exit 2;;
  esac
done
[ -n "$TITLE" ] && [ -f "$BODY" ] && [ -n "$OUT" ] || { echo "need --title, --body <file>, --out" >&2; exit 2; }

# Humanised copy: reject dashes used as punctuation, arrows, emoji and other machine-written tells.
BAD="$(grep -nE '—|–|→|←|⇒|…|--|[😀-🙏🚀-🛿✅❌⚠️✨]' "$BODY" <(printf '%s\n%s\n%s' "$TITLE" "$PERIOD" "$SUBTITLE") 2>/dev/null | grep -vE '<!--|-->|--[a-z-]+:' || true)"
if [ -n "$BAD" ]; then
  echo "Style check failed. Remove em/en dashes, arrows, ellipsis characters, double hyphens and emoji, then re-run:" >&2
  echo "$BAD" | head -20 >&2
  exit 1
fi

CHROME=""
for c in "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" "/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge" "$(command -v google-chrome || true)" "$(command -v chromium || true)"; do
  [ -n "$c" ] && [ -x "$c" ] && { CHROME="$c"; break; }
done
[ -n "$CHROME" ] || { echo "No Chrome or Edge found" >&2; exit 1; }

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
LOGO="$(base64 < "$DIR/logo.png" | tr -d '\n')"
GENERATED="$(date '+%-d %B %Y')"
{
  echo '<!doctype html><html><head><meta charset="utf-8"><title>'"$TITLE"'</title><style>'
  cat "$DIR/report.css"
  echo '</style></head><body>'
  echo '<div class="masthead"><div class="brand"><img alt="NG-CDF" src="data:image/png;base64,'"$LOGO"'"><div>'
  echo '<div class="org">National Government<br>Constituencies Development Fund</div>'
  echo '<div class="tag">Maendeleo kwa Wote · '"$SUBTITLE"'</div></div></div>'
  echo '<div class="doc-title"><h1>'"$TITLE"'</h1><div class="period">'"$PERIOD"'</div></div></div>'
  cat "$BODY"
  echo '<div class="footer"><span>NG-CDF Engineering</span><span>Generated '"$GENERATED"'</span></div>'
  echo '</body></html>'
} > "$TMP/report.html"

mkdir -p "$(dirname "$OUT")"
"$CHROME" --headless --disable-gpu --no-sandbox --no-pdf-header-footer --print-to-pdf="$OUT" "file://$TMP/report.html" >/dev/null 2>&1
[ -s "$OUT" ] && echo "Saved $OUT" || { echo "PDF was not produced" >&2; exit 1; }

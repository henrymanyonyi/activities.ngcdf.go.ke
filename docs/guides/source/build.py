"""Builds ../OCEO-Head-of-Department-Guide.pdf from hod-guide.html.

Run from anywhere:  python3 docs/guides/source/build.py
Needs Google Chrome (or Microsoft Edge) and pdftotext (poppler). Chrome prints the page; the
contents page numbers are found by reading each page of a first print back with pdftotext, then
the guide is printed again with them filled in. Also refuses em/en dashes, arrows and emoji.
"""

import html
import os
import re
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, 'hod-guide.html')
OUT = os.path.join(os.path.dirname(HERE), 'OCEO-Head-of-Department-Guide.pdf')
BANNED = re.compile('[‒-―←-⇿…\U0001F300-\U0001FAFF✅❌⚠]')
BROWSERS = ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
            '/usr/bin/google-chrome', '/usr/bin/chromium']


def browser():
    for path in BROWSERS:
        if os.access(path, os.X_OK):
            return path
    sys.exit('No Chrome or Edge found')


def headings(source):
    """Every heading marked data-toc, as (level, text) in document order."""
    found = re.findall(r'<h([12])[^>]*data-toc[^>]*>(.*?)</h\1>', source, re.S)
    return [(int(level) - 1, html.unescape(re.sub(r'<[^>]+>', '', text)).strip()) for level, text in found]


def toc_html(entries, pages):
    rows = []
    for (level, text), page in zip(entries, pages):
        rows.append('<div class="l%d"><span>%s</span><span class="fill"></span><span>%s</span></div>'
                    % (level, html.escape(text), page))
    return '\n'.join(rows)


def render(source, workdir, chrome):
    page = os.path.join(workdir, 'guide.html')
    pdf = os.path.join(workdir, 'guide.pdf')
    with open(page, 'w', encoding='utf-8') as f:
        f.write(source)
    subprocess.run([chrome, '--headless', '--disable-gpu', '--no-sandbox', '--no-pdf-header-footer',
                    '--user-data-dir=' + os.path.join(workdir, 'profile'), '--print-to-pdf=' + pdf,
                    'file://' + page], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    return pdf


def page_texts(pdf):
    text = subprocess.run(['pdftotext', '-layout', pdf, '-'], check=True, capture_output=True, text=True).stdout
    return text.split('\f')


def locate(entries, pages):
    """The page each heading starts on: the first page, after the previous heading's, with a line that
    is exactly the heading (contents lines also carry a page number, so they never match)."""
    found, start = [], 0
    for _, text in entries:
        want = ' '.join(text.split())
        for i in range(start, len(pages)):
            if any(' '.join(line.split()) == want for line in pages[i].splitlines()):
                found.append(i + 1)
                start = i
                break
        else:
            sys.exit('Heading not found in the PDF: %s' % text)
    return found


def main():
    with open(SRC, encoding='utf-8') as f:
        source = f.read()
    bad = [n for n, line in enumerate(source.splitlines(), 1) if BANNED.search(line)]
    if bad:
        sys.exit('Banned punctuation (em/en dash, arrow, ellipsis or emoji) on lines %s' % bad)

    entries = headings(source)
    chrome = browser()
    pages = ['00'] * len(entries)
    with tempfile.TemporaryDirectory() as workdir:
        with open(os.path.join(HERE, 'logo.png'), 'rb') as src, open(os.path.join(workdir, 'logo.png'), 'wb') as dst:
            dst.write(src.read())
        for _ in range(3):
            pdf = render(source.replace('<!--TOC-->', toc_html(entries, pages)), workdir, chrome)
            located = locate(entries, page_texts(pdf))
            if located == pages:
                break
            pages = located
        os.replace(pdf, OUT)
    print('Saved %s (%d pages)' % (OUT, len(page_texts(OUT)) - 1))


if __name__ == '__main__':
    main()

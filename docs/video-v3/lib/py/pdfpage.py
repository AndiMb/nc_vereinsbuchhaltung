"""Rendert Seite 1 eines PDFs als PNG und meldet den Rahmen um einen Text.

Aufruf: python -I pdfpage.py <pdf> <png> <dpi> <text>
Ausgabe (JSON): {"page": {"w", "h"}, "box": {"x", "y", "w", "h"} | null} in PDF-Punkten.
Der Rahmen ist die kleinste gezeichnete Flaeche, die den Text enthaelt (z. B.
der Kasten um den GiroCode); ohne solche Flaeche der Text selbst.
"""
import json
import sys

import pymupdf

pdf, png, dpi, text = sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4]
doc = pymupdf.open(pdf)
page = doc[0]
page.get_pixmap(dpi=dpi).save(png)
hits = page.search_for(text)
box = None
if hits:
    t = hits[0]
    best = None
    for drawing in page.get_drawings():
        r = drawing["rect"]
        if r.contains(t) and r.width < page.rect.width * 0.8 and (best is None or r.get_area() < best.get_area()):
            best = r
    r = best or t
    box = {"x": r.x0, "y": r.y0, "w": r.width, "h": r.height}
print(json.dumps({"page": {"w": page.rect.width, "h": page.rect.height}, "box": box}))

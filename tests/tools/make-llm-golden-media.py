#!/usr/bin/env python3
"""Draw the made-up documents the Ai checks read (zorderz/data/llm-golden/media/).

usage: python3 tests/tools/make-llm-golden-media.py [out_dir]

Everything drawn here is invented: an example supplier, an example worker, example streets. Nothing names a real
business or person. The answers the checks expect (data/llm-golden/read_document.json) are the figures drawn below,
so change both together. Needs Pillow and the DejaVu fonts.
"""
import os
import random
import sys

from PIL import Image, ImageDraw, ImageFilter, ImageFont

FONT_DIR = "/usr/share/fonts/truetype/dejavu"


def font(name, size):
    return ImageFont.truetype(os.path.join(FONT_DIR, name), size)


SANS = "DejaVuSans.ttf"
BOLD = "DejaVuSans-Bold.ttf"
HAND = "DejaVuSans-Oblique.ttf"


def invoice_png(path):
    im = Image.new("RGB", (1100, 1400), "white")
    d = ImageDraw.Draw(im)
    d.text((70, 60), "Example Supply Co.", font=font(BOLD, 40), fill=(28, 61, 110))
    d.text((70, 122), "100 Example Rd, Exampleville", font=font(SANS, 20), fill=(70, 70, 70))
    d.text((760, 60), "INVOICE", font=font(BOLD, 40), fill=(34, 34, 34))
    d.text((760, 128), "Invoice #: EX-1001", font=font(SANS, 22), fill=(34, 34, 34))
    d.text((760, 162), "Date: 2026-10-12", font=font(SANS, 22), fill=(34, 34, 34))
    d.text((70, 232), "Bill to: Example Co (made-up customer)", font=font(SANS, 22), fill=(34, 34, 34))
    d.rectangle((70, 320, 1030, 370), fill=(225, 229, 234))
    for x, t in ((85, "Description"), (640, "Qty"), (740, "Unit"), (900, "Amount")):
        d.text((x, 334), t, font=font(BOLD, 20), fill=(34, 34, 34))
    rows = (("Shelf bracket, steel, pack of 4", "4", "12.50", "50.00"), ("Cabinet hinge kit, satin", "1", "189.00", "189.00"))
    y = 392
    for desc, qty, unit, amt in rows:
        for x, t in ((85, desc), (650, qty), (740, unit), (900, amt)):
            d.text((x, y), t, font=font(SANS, 20), fill=(34, 34, 34))
        y += 50
    d.line((70, 500, 1030, 500), fill=(150, 150, 150), width=2)
    for i, (label, val, bold) in enumerate((("Subtotal", "$239.00", False), ("Tax", "$18.52", False), ("Total", "$257.52", True))):
        f = font(BOLD if bold else SANS, 22)
        d.text((740, 532 + i * 44), label, font=f, fill=(34, 34, 34))
        d.text((900, 532 + i * 44), val, font=f, fill=(34, 34, 34))
    d.text((70, 1300), "Made-up invoice for the Ai checks. Not a real company.", font=font(SANS, 16), fill=(110, 110, 110))
    im.save(path, optimize=True)


def paper_photo(w, h, draw_fn, angle, seed):
    """A sheet of lined paper drawn by draw_fn, turned a little and laid on a table."""
    sheet = Image.new("RGB", (w, h), (250, 248, 240))
    draw_fn(ImageDraw.Draw(sheet))
    sheet = sheet.rotate(angle, expand=True, resample=Image.BICUBIC, fillcolor=(112, 99, 86))
    table = Image.new("RGB", (sheet.width + 120, sheet.height + 120), (112, 99, 86))
    rnd = random.Random(seed)
    px = table.load()
    for _ in range(table.width * table.height // 30):
        x, y = rnd.randrange(table.width), rnd.randrange(table.height)
        r, g, b = px[x, y]
        k = rnd.randint(-8, 8)
        px[x, y] = (r + k, g + k, b + k)
    table.paste(sheet, (60, 60))
    return table.filter(ImageFilter.GaussianBlur(0.6))


def timesheet_jpg(path):
    def draw(d):
        for i in range(12):
            d.line((50, 120 + i * 56, 950, 120 + i * 56), fill=(170, 190, 225), width=2)
        d.text((60, 50), "TIMESHEET    Example Worker", font=font(BOLD, 30), fill=(30, 30, 30))
        d.text((60, 140), "Week of Oct 19, 2026", font=font(HAND, 24), fill=(25, 40, 110))
        for x, t in ((60, "Day"), (340, "In"), (530, "Out"), (740, "Lunch")):
            d.text((x, 200), t, font=font(BOLD, 22), fill=(30, 30, 30))
        rows = (("Mon 10/19", "7:30", "4:00 pm", "30 min"), ("Tue 10/20", "8:00", "3:30 pm", "30 min"), ("Wed 10/21", "7:00", "12:00", "none"))
        for i, row in enumerate(rows):
            for x, t in zip((60, 340, 530, 740), row):
                d.text((x, 256 + i * 56), t, font=font(HAND, 28), fill=(25, 40, 110))
        d.text((70, 640), "Made up for the Ai checks.", font=font(SANS, 16), fill=(120, 120, 120))
    paper_photo(1000, 760, draw, 3.0, 7).convert("RGB").save(path, quality=88)


def route_sheet_png(path):
    im = Image.new("RGB", (1000, 900), "white")
    d = ImageDraw.Draw(im)
    d.text((50, 40), "DELIVERY ROUTE    Example Route 7", font=font(BOLD, 30), fill=(34, 34, 34))
    d.text((50, 96), "Date: 2026-10-24    Area: Exampleville (made up)", font=font(SANS, 20), fill=(70, 70, 70))
    stops = ("101 Example Way", "105 Example Way", "112 Example Way", "7 Sample Ct", "9 Sample Ct")
    for i, s in enumerate(stops):
        y = 170 + i * 100
        d.rectangle((50, y, 950, y + 80), outline=(180, 180, 180), width=2)
        d.text((70, y + 24), f"{i + 1}.", font=font(BOLD, 26), fill=(34, 34, 34))
        d.text((130, y + 24), s, font=font(SANS, 26), fill=(34, 34, 34))
        d.rectangle((860, y + 20, 900, y + 60), outline=(80, 80, 80), width=3)
    d.text((50, 820), "Made up for the Ai checks.", font=font(SANS, 18), fill=(130, 130, 130))
    im.save(path, optimize=True)


def note_photo_jpg(path):
    def draw(d):
        for i in range(13):
            d.line((30, 90 + i * 50, 870, 90 + i * 50), fill=(185, 200, 225), width=2)
        lines = ("Riley Example - measure", "3 shelving units", "   front door 36 x 80 white", "2 cabinets (garage side)", "call back Thurs")
        for i, t in enumerate(lines):
            d.text((50, 50 + i * 100), t, font=font(HAND, 34), fill=(25, 35, 95))
    paper_photo(900, 700, draw, -4.0, 11).convert("RGB").save(path, quality=88)


def pdf_text(lines):
    """A one-page PDF of plain text lines in Helvetica (no images, so its page can be counted)."""
    def esc(s):
        return s.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")
    stream = ["BT", "/F1 12 Tf", "72 760 Td", "16 TL"]
    for size, text in lines:
        stream.append(f"/F1 {size} Tf")
        stream.append(f"({esc(text)}) Tj T*")
    stream.append("ET")
    content = "\n".join(stream).encode("latin-1")
    objs = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
        b"<< /Length " + str(len(content)).encode() + b" >>\nstream\n" + content + b"\nendstream",
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ]
    out = bytearray(b"%PDF-1.4\n")
    offsets = []
    for i, o in enumerate(objs, 1):
        offsets.append(len(out))
        out += f"{i} 0 obj\n".encode() + o + b"\nendobj\n"
    xref = len(out)
    out += f"xref\n0 {len(objs) + 1}\n0000000000 65535 f \n".encode()
    for off in offsets:
        out += f"{off:010d} 00000 n \n".encode()
    out += f"trailer\n<< /Size {len(objs) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
    return bytes(out)


def invoice_pdf(path):
    lines = (
        (18, "Example Glass Co."),
        (12, "INVOICE EX-2002"),
        (12, "Date: 2026-10-20"),
        (12, ""),
        (12, "Description                     Qty   Unit     Amount"),
        (12, "Tempered glass panel 24x36       2    95.00    190.00"),
        (12, "Display shelf kit                3    44.00    132.00"),
        (12, "Delivery                         1    90.00     90.00"),
        (12, ""),
        (14, "Total $412.00"),
        (12, ""),
        (9, "Made-up invoice for the Ai checks. Not a real company."),
    )
    with open(path, "wb") as f:
        f.write(pdf_text(lines))


def main():
    out = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(__file__), "..", "..", "zorderz", "data", "llm-golden", "media")
    os.makedirs(out, exist_ok=True)
    invoice_png(os.path.join(out, "invoice-ex-1001.png"))
    invoice_pdf(os.path.join(out, "invoice-ex-2002.pdf"))
    timesheet_jpg(os.path.join(out, "timesheet-ex.jpg"))
    route_sheet_png(os.path.join(out, "route-sheet-ex.png"))
    note_photo_jpg(os.path.join(out, "note-photo-ex.jpg"))


if __name__ == "__main__":
    main()

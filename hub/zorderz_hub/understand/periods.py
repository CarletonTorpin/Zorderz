"""Period tokens → dated windows, with partial periods and "to date" cut-offs.

All date arithmetic in Charts happens here, never in a model. The anchor is the last day
with data (data_as_of); a window that runs past it is cut there and marked partial, and its
label says so.
"""
import calendar
import datetime as dt
import re
from dataclasses import dataclass, replace

SEASON_MONTHS = {"winter": (12, 2), "spring": (3, 5), "summer": (6, 8), "fall": (9, 11)}


class PeriodError(ValueError):
    pass


@dataclass(frozen=True)
class Window:
    start: dt.date
    end: dt.date              # inclusive, already cut at the anchor
    label: str
    token: str
    partial: bool = False     # the natural period runs past the data
    natural_end: dt.date = None

    @property
    def days(self):
        return (self.end - self.start).days + 1

    def as_spec_dates(self):
        return self.start.isoformat(), self.end.isoformat()


def _month_end(y, m):
    return dt.date(y, m, calendar.monthrange(y, m)[1])


def _fmt_day(d, year=True):
    return f"{d:%b} {d.day}, {d.year}" if year else f"{d:%b} {d.day}"


def _span_label(a, b):
    """Plain label for a start-end pair."""
    if a.month == 1 and a.day == 1 and b.month == 12 and b.day == 31:
        return str(a.year) if a.year == b.year else f"{a.year}–{b.year}"
    if a.day == 1 and b == _month_end(b.year, b.month):
        if a.year == b.year and a.month == b.month:
            return f"{a:%b %Y}"
        if a.year == b.year:
            return f"{a:%b}–{b:%b %Y}"
        return f"{a:%b %Y}–{b:%b %Y}"
    if a.year == b.year:
        return f"{_fmt_day(a, False)}–{_fmt_day(b)}"
    return f"{_fmt_day(a)}–{_fmt_day(b)}"


def _natural(token, anchor, first):
    """(start, end, label) of a token before cutting at the anchor."""
    t = token.strip().lower()
    y = anchor.year
    if t == "all":
        return first, anchor, f"All history ({first.year}–{anchor.year})"
    if t in ("ytd", "this_year"):
        return dt.date(y, 1, 1), dt.date(y, 12, 31), str(y)
    if t == "qtd":
        q = (anchor.month - 1) // 3
        return dt.date(y, q * 3 + 1, 1), _month_end(y, q * 3 + 3), f"Q{q + 1} {y}"
    if t == "mtd":
        return dt.date(y, anchor.month, 1), _month_end(y, anchor.month), f"{anchor:%b %Y}"
    m = re.fullmatch(r"last_(\d+)([dwmy])", t)
    if m:
        n, u = int(m.group(1)), m.group(2)
        if u == "d":
            s = anchor - dt.timedelta(days=n - 1)
            return s, anchor, f"Last {n} days ({_span_label(s, anchor)})"
        if u == "w":                     # complete ISO weeks (Mon-Sun) ending on or before the anchor
            last_sunday = anchor - dt.timedelta(days=(anchor.weekday() + 1) % 7)
            if anchor.weekday() == 6:
                last_sunday = anchor
            s = last_sunday - dt.timedelta(days=7 * n - 1)
            return s, last_sunday, f"Last {n} weeks ({_span_label(s, last_sunday)})"
        if u == "m":                     # complete months ending with the last complete one
            ly, lm = (anchor.year, anchor.month) if anchor == _month_end(anchor.year, anchor.month) else \
                ((anchor.year, anchor.month - 1) if anchor.month > 1 else (anchor.year - 1, 12))
            sy, sm = ly, lm - n + 1
            while sm <= 0:
                sm += 12
                sy -= 1
            s, e = dt.date(sy, sm, 1), _month_end(ly, lm)
            return s, e, f"Last {n} months ({_span_label(s, e)})"
        if u == "y":                     # complete calendar years before the anchor's year
            full_last = y if anchor == dt.date(y, 12, 31) else y - 1
            s, e = dt.date(full_last - n + 1, 1, 1), dt.date(full_last, 12, 31)
            return s, e, f"{s.year}–{e.year}"
    if ".." in t:
        a, b = t.split("..", 1)
        sa = first if a in ("", "start") else _natural(a, anchor, first)[0]
        eb = anchor if b in ("", "now", "today") else _natural(b, anchor, first)[1]
        if sa > eb:
            raise PeriodError(f"the range {token} ends before it starts")
        if b in ("", "now", "today"):
            return sa, eb, _to_now_label(sa, eb)
        return sa, eb, _span_label(sa, eb)
    m = re.fullmatch(r"(\d{4})", t)
    if m:
        yy = int(m.group(1))
        return dt.date(yy, 1, 1), dt.date(yy, 12, 31), str(yy)
    m = re.fullmatch(r"(\d{4})-q([1-4])", t)
    if m:
        yy, q = int(m.group(1)), int(m.group(2))
        return dt.date(yy, 3 * q - 2, 1), _month_end(yy, 3 * q), f"Q{q} {yy}"
    m = re.fullmatch(r"(\d{4})-h([12])", t)
    if m:
        yy, h = int(m.group(1)), int(m.group(2))
        return dt.date(yy, 6 * h - 5, 1), _month_end(yy, 6 * h), f"H{h} {yy} ({'Jan–Jun' if h == 1 else 'Jul–Dec'})"
    m = re.fullmatch(r"(\d{4})-(\d{2})", t)
    if m:
        yy, mo = int(m.group(1)), int(m.group(2))
        return dt.date(yy, mo, 1), _month_end(yy, mo), f"{dt.date(yy, mo, 1):%b %Y}"
    m = re.fullmatch(r"(\d{4})-(\d{2})-(\d{2})", t)
    if m:
        d = dt.date(int(m.group(1)), int(m.group(2)), int(m.group(3)))
        return d, d, _fmt_day(d)
    m = re.fullmatch(r"(\d{4})-w(\d{2})", t)
    if m:
        s = dt.date.fromisocalendar(int(m.group(1)), int(m.group(2)), 1)
        return s, s + dt.timedelta(days=6), f"Week of {_fmt_day(s)}"
    m = re.fullmatch(r"(\d{4})-(winter|spring|summer|fall)", t)
    if m:
        yy, season = int(m.group(1)), m.group(2)
        a, b = SEASON_MONTHS[season]
        if season == "winter":
            return dt.date(yy - 1, 12, 1), _month_end(yy, 2), f"Winter {yy - 1}–{str(yy)[2:]}"
        return dt.date(yy, a, 1), _month_end(yy, b), f"{season.title()} {yy}"
    raise PeriodError(f"unknown period {token}")


def _to_now_label(a, b):
    """"2015–2026": a range running to the data date says where it stops."""
    if a.month == 1 and a.day == 1:
        if b.month == 12 and b.day == 31:
            return str(a.year) if a.year == b.year else f"{a.year}–{b.year}"
        head = str(a.year) if a.year == b.year else f"{a.year}–{b.year}"
        return f"{head} ({b.year} to {_fmt_day(b, year=False)})"
    return _span_label(a, b)


def resolve(token, anchor, first=None):
    """One token → Window, cut at the anchor (the last day with data)."""
    first = first or dt.date(anchor.year - 10, 1, 1)
    s, e, label = _natural(token, anchor, first)
    s = max(s, first) if token.strip().lower() == "all" else s
    if s < first <= e and token.strip().lower() != "all":
        # "2012 to now" when the data starts in 2016: the window starts with the data
        s = first
        end = min(e, anchor)
        head = str(s.year) if s.year == end.year else f"{s.year}–{end.year}"
        label = f"{head} (data from {_fmt_day(s)} to {_fmt_day(end)})"
    partial = e > anchor
    if s > anchor:
        raise PeriodError(f"no data yet for {label}")
    end = min(e, anchor)
    if partial:
        if token.strip().lower() in ("ytd", "this_year") or re.fullmatch(r"\d{4}", token.strip()):
            label = f"{s.year} to {_fmt_day(end, year=False)}"
        elif ".." not in token:
            label = f"{label} (to {_fmt_day(end, year=False)})"
    return Window(s, end, label, token, partial, e)


def shift_year(w, years=1):
    """The same days, `years` earlier."""
    def back(d):
        try:
            return d.replace(year=d.year - years)
        except ValueError:
            return d.replace(year=d.year - years, day=28)
    s, e = back(w.start), back(w.end)
    label = _span_label(s, e)
    if w.partial or (w.natural_end and w.end < w.natural_end):
        label = f"{_fmt_day(s, year=False)}–{_fmt_day(e)}"
        if s.month == 1 and s.day == 1:
            label = f"{s.year} to {_fmt_day(e, year=False)}"
    elif re.fullmatch(r"\d{4}-q[1-4]", w.token.lower()):
        q = (s.month - 1) // 3 + 1
        label = f"Q{q} {s.year}"
    elif re.fullmatch(r"\d{4}-h[12]", w.token.lower()):
        label = f"H{1 if s.month == 1 else 2} {s.year} ({'Jan–Jun' if s.month == 1 else 'Jul–Dec'})"
    return Window(s, e, label, f"{w.token}-1y", False, back(w.natural_end) if w.natural_end else e)


def shift_period(w):
    """The period just before, the same length in its own unit (month → previous month)."""
    t = w.token.lower()
    if re.fullmatch(r"\d{4}-\d{2}", t) or t == "mtd":
        s = (w.start - dt.timedelta(days=1)).replace(day=1)
        e = s + (w.end - w.start) if w.partial else _month_end(s.year, s.month)
        e = min(e, _month_end(s.year, s.month))
    elif re.fullmatch(r"\d{4}-q[1-4]", t) or t == "qtd":
        ps = w.start.month - 3
        sy = w.start.year if ps >= 1 else w.start.year - 1
        ps = ps if ps >= 1 else ps + 12
        s = dt.date(sy, ps, 1)
        e = s + (w.end - w.start) if w.partial else _month_end(sy, ps + 2)
    elif re.fullmatch(r"\d{4}-w\d{2}", t):
        s, e = w.start - dt.timedelta(days=7), w.end - dt.timedelta(days=7)
    else:
        span = w.end - w.start
        e = w.start - dt.timedelta(days=1)
        s = e - span
    return Window(s, e, _span_label(s, e), f"{w.token}-1p", False, e)


def cut_each_year(w, month, day):
    try:
        cut = dt.date(w.start.year, month, day)
    except ValueError:
        cut = dt.date(w.start.year, month, 28)
    end = min(w.end, cut)
    return replace(w, end=end, label=f"{w.start.year} (Jan 1–{_fmt_day(end, year=False)})",
                   partial=w.partial or end < (w.natural_end or w.end))

"""Reading dates in a question: periods, comparisons, "to date", months across years.

Everything here is pure text work: it turns the words into period tokens that periods.py
resolves against the data date. Tokens:
  "A..B" (A, B any of the above, or "now")   "ytd" "qtd" "mtd"
  "last_90d" "last_12w" "last_12m" "last_10y"   "all"
"""
import datetime as dt
import re
from dataclasses import dataclass, field

from . import lexicon as L
from .terms import MONTHS

YEAR = r"(?:19[89]\d|20\d\d)"
_MONTH_NAMES = sorted(MONTHS, key=len, reverse=True)
MONTH = r"(?:" + "|".join(_MONTH_NAMES) + r")"
NUMWORDS = {"a": 1, "an": 1, "one": 1, "two": 2, "three": 3, "four": 4, "five": 5, "six": 6, "seven": 7,
            "eight": 8, "nine": 9, "ten": 10, "eleven": 11, "twelve": 12, "fifteen": 15, "twenty": 20,
            "thirty": 30, "sixty": 60, "ninety": 90, "couple": 2, "few": 3}
NUM = r"(?:\d{1,3}|" + "|".join(sorted(NUMWORDS, key=len, reverse=True)) + r")"
ORD = {"first": 1, "1st": 1, "second": 2, "2nd": 2, "third": 3, "3rd": 3, "fourth": 4, "4th": 4}
UNIT = {"day": "d", "days": "d", "week": "w", "weeks": "w", "month": "m", "months": "m", "quarter": "q",
        "quarters": "q", "year": "y", "years": "y"}
SEASONS = {"winter": "winter", "spring": "spring", "summer": "summer", "fall": "fall", "autumn": "fall"}
SEASON_MONTHS = {"winter": (12, 1, 2), "spring": (3, 4, 5), "summer": (6, 7, 8), "fall": (9, 10, 11)}
EACH_YEAR = re.compile(r"\b(?:each|every|per|all|across (?:all |every |each )?(?:the )?)\s*years?\b|"
                       r"\b(?:by|per) year\b|\byear by year\b|\byearly\b|\bannually\b|\bacross every year\b|"
                       r"\bacross years\b|\bevery year\b|\bover the years\b|\bin all years\b")


def _num(s):
    s = s.strip()
    return int(s) if s.isdigit() else NUMWORDS.get(s, 0)


@dataclass
class TimeItem:
    kind: str            # period | range | relative | months | compare | todate | all | grain | season_part
    token: str = ""
    start: int = 0       # char span in prepared text
    end: int = 0
    months: tuple = ()
    end_day: int = 0
    note: str = ""


@dataclass
class When:
    items: list = field(default_factory=list)
    periods: list = field(default_factory=list)     # period tokens, chronological
    compare: str = ""                               # prior_year | prior_period
    to_date: bool = False
    to_date_day: tuple = ()                         # (month, day) when the question names the cut-off
    months: list = field(default_factory=list)      # month-of-year filter
    each_year: bool = False                         # "each year", "every year", "across years"
    vs_between_periods: bool = False
    grain_hint: str = ""                            # from "last 12 weeks" → week, etc.
    future: list = field(default_factory=list)      # years with no data yet
    notes: list = field(default_factory=list)
    spans: list = field(default_factory=list)       # (start, end) char spans read


def _last_complete_month(anchor):
    first = anchor.replace(day=1)
    nxt = (first + dt.timedelta(days=32)).replace(day=1)
    if anchor == nxt - dt.timedelta(days=1):
        return anchor.year, anchor.month
    prev = first - dt.timedelta(days=1)
    return prev.year, prev.month


def most_recent(month_or_months, anchor):
    """The most recent year in which these month(s) have fully ended."""
    last = max(month_or_months) if isinstance(month_or_months, (list, tuple)) else month_or_months
    y, m = _last_complete_month(anchor)
    return y if last <= m else y - 1


class Reader:
    def __init__(self, text, anchor, today):
        self.text, self.anchor, self.today = text, anchor, today
        self.taken = []
        self.w = When()

    # ------------------------------------------------------------ helpers
    def free(self, a, b):
        return all(b <= s or a >= e for s, e in self.taken)

    def take(self, m, item=None, group=0):
        a, b = m.start(group), m.end(group)
        self.taken.append((a, b))
        self.w.spans.append((a, b))
        if item is not None:
            item.start, item.end = a, b
            self.w.items.append(item)
        return item

    def each(self, pattern, flags=0):
        for m in re.finditer(pattern, self.text, flags):
            if self.free(m.start(), m.end()):
                yield m

    # ------------------------------------------------------------ the passes, most specific first
    def run(self):
        t = self.text
        if EACH_YEAR.search(t):
            self.w.each_year = True
        self.compare_phrases()
        self.to_date_phrases()
        self.month_ranges()
        self.named_spans()                  # "the holidays", "fire season" before the four seasons
        self.seasons()
        self.quarters()
        self.halves()
        self.month_years()
        self.year_ranges()
        self.since()
        self.relative()
        self.all_time()
        self.year_lists()
        self.single_years()
        self.lone_months()
        self.lately()                       # "lately", "recently", "trending" with no period named
        return self.finish()

    def compare_phrases(self):
        words = sorted(L.PRIOR_YEAR, key=len, reverse=True)
        for m in self.each(r"\b(?:" + "|".join(re.escape(w) for w in words) + r")\b"):
            self.take(m, TimeItem("compare", "prior_year"))
            self.w.compare = "prior_year"
        words = sorted(L.PRIOR_PERIOD, key=len, reverse=True)
        for m in self.each(r"\b(?:" + "|".join(re.escape(w) for w in words) + r")\b"):
            self.take(m, TimeItem("compare", "prior_period"))
            self.w.compare = self.w.compare or "prior_period"

    def to_date_phrases(self):
        for m in self.each(r"\b(?:so far this year|this year to date|year[ -]to[ -]date|ytd|so far in "
                           r"(?:20\d\d|this year)|this year so far)\b"):
            self.take(m, TimeItem("relative", "ytd"))
        for m in self.each(r"\b(?:quarter[ -]to[ -]date|qtd|this quarter so far)\b"):
            self.take(m, TimeItem("relative", "qtd"))
        for m in self.each(r"\b(?:month[ -]to[ -]date|mtd|this month so far)\b"):
            self.take(m, TimeItem("relative", "mtd"))
        for m in self.each(r"\b(?:normali[sz]e\w*|same window|same date range|same part of the year|"
                           r"like[ -]for[ -]like|apples to apples|to the same date|same days)\b"):
            self.take(m, TimeItem("todate"))
            self.w.to_date = True

    def month_ranges(self):
        pat = (r"\b(?:from\s+)?(" + MONTH + r")\s*(?:-|to|through|thru|until|till)\s*(" + MONTH +
               r")(?:\s+(\d{1,2})(?:st|nd|rd|th)?)?(?:\s+(?:of\s+)?(" + YEAR + r"))?(?:\s+(zone|window|period))?\b")
        for m in self.each(pat):
            a, b = MONTHS[m.group(1)], MONTHS[m.group(2)]
            months = tuple(range(a, b + 1)) if a <= b else tuple(list(range(a, 13)) + list(range(1, b + 1)))
            day = int(m.group(3)) if m.group(3) else 0
            if m.group(4):
                y = int(m.group(4))
                y0 = y if a <= b else y - 1
                end = f"{y}-{b:02d}-{day:02d}" if day else f"{y}-{b:02d}"
                self.take(m, TimeItem("range", f"{y0}-{a:02d}..{end}", months=months))
            else:
                self.take(m, TimeItem("months", months=months, end_day=day))

    # the months these words mean, every year. Holidays = November-December; fire season = the region pack's
    # fire_season months ([first, last]); a region that names none has no fire season, and the words are not read
    # as a period. "last holiday season" / "this fire season" pin one year; "July 4th holiday" is not the winter
    # holidays.
    NAMED_SPANS = [
        (r"\b(?:(last|this|past|previous)\s+)?(?:the\s+)?(?:wild)?fire\s+season\b", "fire_season",
         "Fire season = {first}\u2013{last}"),
        (r"\b(?:(last|this|past|previous)\s+)?(?:the\s+)?holiday(?:s|\s+season|\s+time|\s+months)?\b", (11, 12),
         "The holidays = November\u2013December"),
    ]
    _OTHER_HOLIDAY = re.compile(r"(?:4th|fourth|july|independence\s+day|labor\s+day|memorial\s+day|easter|"
                                r"presidents?'?\s+day|mlk|martin\s+luther\s+king(?:\s+jr\.?)?\s+day|veterans\s+day|"
                                r"columbus\s+day|mother'?s\s+day|father'?s\s+day|spring\s+break|summer)"
                                r"(?:\s+(?:day|weekend))?\s*$")

    @staticmethod
    def _fire_season():
        """The region's fire season months (the region pack's fire_season, [first, last]), or None when the region
        names none or names it wrongly."""
        try:
            from .. import region as R
            got = R.current().data.get("fire_season")
            if not got:
                return None
            a, b = int(got[0]), int(got[1])
        except (TypeError, ValueError, ImportError, IndexError, KeyError):
            return None
        if not (1 <= a <= 12 and 1 <= b <= 12):
            return None
        return tuple(range(a, b + 1)) if a <= b else tuple(list(range(a, 13)) + list(range(1, b + 1)))

    def named_spans(self):
        import calendar
        for pat, months, note in self.NAMED_SPANS:
            if months == "fire_season":
                months = self._fire_season()
                if not months:
                    continue                               # the region has no fire season: no wording, no period
                note = note.format(first=calendar.month_name[months[0]], last=calendar.month_name[months[-1]])
            for m in self.each(pat):
                if "holiday" in m.group(0) and self._OTHER_HOLIDAY.search(self.text[max(0, m.start() - 40):m.start()].strip()):
                    continue                               # "July 4th holiday", "Labor Day holiday weekend"
                a, b = months[0], months[-1]
                if m.group(1) in ("last", "past", "previous"):
                    y = most_recent(b, self.anchor)
                    self.take(m, TimeItem("range", f"{y}-{a:02d}..{y}-{b:02d}", months=months))
                    note_y = f"{note}, {y}"
                elif m.group(1) == "this":
                    y = self.anchor.year
                    self.take(m, TimeItem("range", f"{y}-{a:02d}..{y}-{b:02d}", months=months))
                    note_y = f"{note}, {y}"
                else:
                    self.take(m, TimeItem("months", months=months))
                    note_y = f"{note}, each year"
                if note_y not in self.w.notes:
                    self.w.notes.append(note_y)

    # "lately" reaches back 24 months when nothing else names a period. Not when the
    # question is by year ("trending year over year", "trending by year"): that's a comparison of years.
    LATELY = r"\b(?:lately|recently|of\s+late|these\s+days|nowadays|in\s+recent\s+(?:months|times)|trending)\b"
    _BY_YEAR = re.compile(r"\b(?:year\s+over\s+year|year-over-year|yoy|by\s+year|per\s+year|each\s+year|every\s+year|"
                          r"yearly|annual(?:ly)?|year\s+by\s+year)\b")

    def lately(self):
        hits = list(self.each(self.LATELY))
        if not hits:
            return
        if self._BY_YEAR.search(self.text) or \
                any(i.kind in ("period", "range", "relative", "all", "yearlist", "months") for i in self.w.items):
            for m in hits:
                self.take(m)                             # read, but the named period (or years) stands
            return
        for m in hits:
            self.take(m, TimeItem("relative", "last_24m"))
        self.w.notes.append("Lately = the last 24 months")

    def seasons(self):
        pat = r"\b(?:(last|this|past|previous)\s+)?(winter|spring|summer|fall|autumn)(?:\s+(?:of\s+)?(" + YEAR + r"))?\b"
        for m in self.each(pat):
            season = SEASONS[m.group(2)]
            if m.group(3):
                tok = f"{m.group(3)}-{season}"
            elif m.group(1) or not self._season_is_part():
                y = self._recent_season_year(season, this=(m.group(1) == "this"))
                tok = f"{y}-{season}"
                self.w.notes.append(f"{season.title()} read as the most recent one that has ended ({y})"
                                    if m.group(1) != "this" else f"This {season} = {y}")
            else:
                continue                       # "which season", "by season": a date part, read later
            self.take(m, TimeItem("period", tok))
            # "summer 2025 (June through August)": the months restate the season
            after = self.text[m.end():m.end() + 40]
            mm = re.match(r"\s*(?:\(\s*)?(" + MONTH + r")\s*(?:-|to|through|thru)\s*(" + MONTH + r")\s*\)?", after)
            if mm:
                a = m.end() + mm.start()
                b = m.end() + mm.end()
                self.taken.append((a, b))
                self.w.spans.append((a, b))
                self.w.items = [i for i in self.w.items if not (i.kind == "months" and a <= i.start < b)]

    def _season_is_part(self):
        return bool(re.search(r"\b(?:which|what|each|every|by|per)\s+seasons?\b|\bseasons\b|\bseasonal", self.text))

    def _recent_season_year(self, season, this=False):
        a = self.anchor
        months = SEASON_MONTHS[season]
        end_month = months[-1]
        y = a.year
        if this:
            return y
        # the season's end date in year y (winter ends in Feb of y)
        end = dt.date(y, end_month, 28 if end_month == 2 else 30 if end_month in (6, 9, 11) else 31)
        return y if end <= a else y - 1

    def quarters(self):
        pat = (r"\b(?:q([1-4])|(first|second|third|fourth|1st|2nd|3rd|4th)\s+quarter)(?:\s*(?:of\s+)?(?:the\s+)?"
               r"(?:year\s+)?(" + YEAR + r"))?\b|\b(" + YEAR + r")\s*-?\s*q([1-4])\b")
        for m in self.each(pat):
            if m.group(4):
                y, q = int(m.group(4)), int(m.group(5))
            else:
                q = int(m.group(1)) if m.group(1) else ORD[m.group(2)]
                if m.group(3):
                    y = int(m.group(3))
                else:
                    y = most_recent(q * 3, self.anchor)
                    self.w.notes.append(f"Q{q} read as the most recent one that has ended (Q{q} {y})")
            self.take(m, TimeItem("period", f"{y}-Q{q}"))

    def halves(self):
        pat = (r"\b(?:h([12])|(first|second|1st|2nd)\s+half)(?:\s+(?:of\s+)?(?:the\s+year\s+)?(?:of\s+)?(" +
               YEAR + r"))?\b")
        for m in self.each(pat):
            h = int(m.group(1)) if m.group(1) else ORD[m.group(2)]
            if m.group(3):
                y = int(m.group(3))
            else:
                y = most_recent(h * 6, self.anchor)
                self.w.notes.append(f"H{h} read as the most recent one that has ended ({y})")
            self.take(m, TimeItem("period", f"{y}-H{h}"))

    def month_years(self):
        pat = r"\b(" + MONTH + r")\s+(?:(\d{1,2})(?:st|nd|rd|th)?\s+)?(?:of\s+)?(" + YEAR + r")\b"
        for m in self.each(pat):
            mo, y = MONTHS[m.group(1)], int(m.group(3))
            if m.group(2):
                self.take(m, TimeItem("period", f"{y}-{mo:02d}-{int(m.group(2)):02d}"))
            else:
                self.take(m, TimeItem("period", f"{y}-{mo:02d}"))
        for m in self.each(r"\b(" + YEAR + r")-(0[1-9]|1[0-2])\b"):
            self.take(m, TimeItem("period", f"{m.group(1)}-{m.group(2)}"))

    def year_ranges(self):
        end_words = r"now|today|present|date|current|this year|the present"
        pat = (r"\b(?:from\s+)?(" + YEAR + r")\s*(?:-|to|through|thru|until|till)\s*(" + YEAR + r"|" +
               end_words + r")\b")
        for m in self.each(pat):
            a, b = m.group(1), m.group(2)
            if not re.fullmatch(YEAR, b):
                b = "now"
            self.take(m, TimeItem("range", f"{a}..{b}"))
        pat2 = r"\bbetween\s+(" + YEAR + r")\s+and\s+(" + YEAR + r")\b"
        for m in self.each(pat2):
            self.take(m, TimeItem("range", f"{m.group(1)}..{m.group(2)}"))

    def since(self):
        pat = r"\b(?:since|from|after|starting(?:\s+in)?|beginning(?:\s+in)?|as of)\s+(" + YEAR + r")\b" + \
            r"(?!\s*(?:,|and|&|or)\s*" + YEAR + r")"            # "from 2025 and 2026" is a list
        for m in self.each(pat):
            self.take(m, TimeItem("range", f"{m.group(1)}..now"))

    def relative(self):
        a = self.anchor
        # "last 12 months", "past 90 days", "previous 3 years", "last two years"
        pat = r"\b(?:the\s+)?(?:last|past|previous|prior|trailing)\s+(" + NUM + r")\s+(days?|weeks?|months?|quarters?|years?)\b"
        for m in self.each(pat):
            n, u = _num(m.group(1)), UNIT[m.group(2)]
            if n <= 0:
                continue
            if u == "q":
                n, u = n * 3, "m"
            self.take(m, TimeItem("relative", f"last_{n}{u}"))
        # "last 365" (no unit)
        for m in self.each(r"\b(?:the\s+)?(?:last|past|previous)\s+(\d{2,3})\b(?!\s*(?:jobs|invoices|customers))"):
            n = int(m.group(1))
            u = "d" if n > 24 else "m"
            self.take(m, TimeItem("relative", f"last_{n}{u}"))
            self.w.notes.append(f"\"Last {n}\" read as the last {n} {'days' if u == 'd' else 'months'}")
        for m in self.each(r"\b(?:the\s+)?(?:last|past|previous)\s+decade\b"):
            self.take(m, TimeItem("relative", "last_10y"))
        for m in self.each(r"\b(?:the\s+last|the\s+past|past|trailing|over\s+the\s+last)\s+(?:year|12 months|twelve months)\b"):
            self.take(m, TimeItem("relative", "last_12m"))
        y, mo = a.year, a.month
        iso = a.isocalendar()
        for m in self.each(r"\b(?:last|previous)\s+year\b"):
            self.take(m, TimeItem("period", str(y - 1)))
            self.w.notes.append(f"Last year = {y - 1}")
        for m in self.each(r"\b(?:this|current)\s+year\b"):
            self.take(m, TimeItem("relative", "ytd"))
        for m in self.each(r"\b(?:last|previous)\s+month\b"):
            py, pm = (y, mo - 1) if mo > 1 else (y - 1, 12)
            self.take(m, TimeItem("period", f"{py}-{pm:02d}"))
        for m in self.each(r"\b(?:this|current)\s+month\b"):
            self.take(m, TimeItem("relative", "mtd"))
        for m in self.each(r"\b(?:last|previous)\s+quarter\b"):
            q = (mo - 1) // 3 + 1
            py, pq = (y, q - 1) if q > 1 else (y - 1, 4)
            self.take(m, TimeItem("period", f"{py}-Q{pq}"))
        for m in self.each(r"\b(?:this|current)\s+quarter\b"):
            self.take(m, TimeItem("relative", "qtd"))
        for m in self.each(r"\b(?:last|previous)\s+week\b"):
            d = a - dt.timedelta(days=7)
            iy, iw, _ = d.isocalendar()
            self.take(m, TimeItem("period", f"{iy}-W{iw:02d}"))
        for m in self.each(r"\b(?:this|current)\s+week\b"):
            self.take(m, TimeItem("period", f"{iso[0]}-W{iso[1]:02d}"))
        for m in self.each(r"\b(?:next|coming|upcoming)\s+(?:month|year|quarter|week|season)\b"):
            self.take(m, TimeItem("future", "next"))
            self.w.future.append("next")

    def all_time(self):
        for m in self.each(r"\b(?:for\s+)?(?:all[ -]time|of all time|all history|all of history|in history|ever|"
                           r"since (?:we started|the (?:beginning|start)|day one|inception)|all years|"
                           r"all the years|whole history|entire history)\b"):
            self.take(m, TimeItem("all", "all"))

    def year_lists(self):
        sep = r"\s*(?:,|and|vs\.?|versus|or|compared (?:to|with)|against|&|then|to)?\s*"
        pat = r"\b(" + YEAR + r")((?:" + sep + YEAR + r"\b)+)"
        for m in self.each(pat):
            years = [m.group(1)] + re.findall(YEAR, m.group(2))
            if len(set(years)) < 2:
                continue
            between = m.group(0)
            self.take(m, TimeItem("yearlist", ",".join(years),
                                  note="vs" if re.search(r"vs|versus|compared|against", between) else ""))

    def single_years(self):
        for m in self.each(r"\b(" + YEAR + r")\b"):
            self.take(m, TimeItem("period", m.group(1)))

    def lone_months(self):
        # A month name with no year: across years ("December each year"), or the most recent one.
        pat = r"\b(?:(in|for|during|of|last|this|every|each)\s+)?(" + MONTH + r")\b"
        for m in self.each(pat):
            word = m.group(2)
            if word in ("may", "mar", "dec", "jun", "sep", "aug") and not m.group(1) and not self.w.each_year:
                continue                      # "may" the verb, "dec" the abbreviation of something else
            mo = MONTHS[word]
            if self.w.each_year or m.group(1) in ("every", "each"):
                self.take(m, TimeItem("months", months=(mo,)))
            else:
                y = most_recent(mo, self.anchor) if m.group(1) != "this" else self.anchor.year
                if m.group(1) == "last":
                    y = most_recent(mo, self.anchor)
                self.take(m, TimeItem("period", f"{y}-{mo:02d}"))
                self.w.notes.append(f"{word.title()} read as {dt.date(y, mo, 1):%B %Y}, the most recent "
                                    f"one that has ended (name a year for another)")

    # ------------------------------------------------------------ putting it together
    def finish(self):
        w = self.w
        items = sorted(w.items, key=lambda i: i.start)
        periods, months = [], []
        for it in items:
            if it.kind in ("period", "range", "relative", "all"):
                periods.append(it.token)
            elif it.kind == "yearlist":
                periods.extend(it.token.split(","))
                if it.note == "vs":
                    w.vs_between_periods = True
            elif it.kind == "months":
                months.extend(it.months)
                if it.end_day:
                    w.to_date_day = (max(it.months), it.end_day)
            if it.kind == "relative" and it.token.endswith("w"):
                w.grain_hint = "week"
        if "all" in periods and len(periods) > 1:
            periods = [p for p in periods if p != "all"]
        seen, out = set(), []
        for p in periods:
            if p not in seen:
                seen.add(p)
                out.append(p)
        w.periods = out
        w.months = sorted(set(months))
        if w.months and w.to_date_day:
            w.to_date = True
        # vs between two date expressions ("Q3 2025 vs Q3 2024", "September 2026 compared to September 2025")
        # the word has to sit between the date expressions, or lead straight into the first one ("compare
        # 2024 and 2025"). A "vs" between two towns or regions earlier in the sentence ("north side vs east side
        # jobs by month across 2024 and 2025") is not a comparison of years.
        dated = [i for i in items if i.kind in ("period", "range", "relative", "yearlist")]
        if len(out) >= 2 and dated:
            first, last = min(i.start for i in dated), max(i.end for i in dated)
            for m in re.finditer(r"\b(?:vs|versus|compared|compare|against|side by side)\b", self.text):
                between = first <= m.start() <= last
                # leading: "compare 2024 and 2025", "compare the years 2024 and 2025", "compare revenue in 2024 and
                # 2025" -- but not "compare north side and east side jobs across 2024 and 2025", where the things
                # compared (an "and", "or", "vs" or a comma) come before the dates.
                gap = self.text[m.end():first]
                leads = m.end() <= first and m.group(0) in ("compare", "compared", "side by side") and len(gap) <= 48 \
                    and re.search(r"\b(?:and|or|vs|versus|v|against|with|to)\b|[,/&]", gap) is None
                if between or leads:
                    w.vs_between_periods = True
                    break
        for p in out:
            m = re.match(r"^(\d{4})", p)
            if m and int(m.group(1)) > self.anchor.year:
                w.future.append(m.group(1))
        return w


def read_dates(text, anchor, today=None):
    return Reader(text, anchor, today or anchor).run()

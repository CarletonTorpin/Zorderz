"""The chart for the answer's shape.

Chosen by rule from what ran (not from the question's words, and never by a model); a
chart type the person named wins when the numbers allow it (compute.run still turns a pie
of averages into bars). The rules, from the plan's research (Few, Cleveland & McGill,
the Financial Times visual vocabulary, Tableau Show Me):

  one number                               → big number
  periods compared (2–4 windows)           → columns, one per period (grouped by split)
  years over one calendar (overlay)        → one line per year
  a long time series (13+ months/weeks/days)→ line; years and short series → columns
  date parts (weekday, season, month)      → columns in calendar order
  a ranking ("which… the most")            → bars sorted largest first, horizontal
  parts of a whole, one period             → pie for 2–3 parts, sorted bars with % beyond
  parts of a whole over time               → 100% bars
  groups over time (additive)              → stacked columns; averages/customers → lines
  groups, one period                       → bars, horizontal when 5 or more
"""
from . import spec2 as S

ADDITIVE = {"revenue", "jobs", "units"}
FINE = {"month", "week", "day"}


def _named_comparison(s):
    from .describe import _versus
    return _versus(s)[0] is not None


def choose(answer):
    """(chart_type, orientation, labels) for an Answer whose spec said nothing (auto)."""
    s = answer.spec
    r = answer.result
    measure = (answer.v1 or {}).get("measure", s.get("measure"))
    split = s.get("split") or "none"
    stackable = measure in ADDITIVE and not split.startswith("tag_")
    n_periods = len(r.get("periods", []))
    groups = [g for g in r.get("groups", []) if g != "All jobs"]
    n_groups = len(groups)
    grain = s.get("date_part") or s.get("grain") or "none"
    kind = answer.kind
    labels = "percent" if s.get("share") else None
    if r.get("empty"):
        return "table", None, None
    if kind == "jobs_per_customer":
        return "grouped_bar", "vertical", "value"
    if kind == "job_range":
        return "grouped_bar", "horizontal" if n_groups >= 5 else "vertical", "value"
    if kind == "per_job":
        return ("single_number", None, None) if n_periods <= 1 else ("grouped_bar", "vertical", None)
    if n_periods <= 1 and n_groups <= 1:
        return "single_number", None, None
    if kind == "overlay":
        return "line", None, None
    if kind == "windows":
        if s.get("share") and stackable and n_groups >= 2:
            return "percent_bar", "vertical", "percent"
        return "grouped_bar", "vertical", "value" if n_periods * max(n_groups, 1) <= 12 else None
    if kind == "series":
        if s.get("share") and n_periods <= 1:
            return ("pie" if stackable else "grouped_bar"), None, "percent"
        if n_periods > 1:
            if s.get("share") and stackable:
                return "percent_bar", "vertical", "percent"
            return ("stacked_bar" if stackable else "line"), None, None
        return "grouped_bar", "vertical", "value"
    # one period, several groups
    if n_periods <= 1:
        if s.get("share") and stackable:
            if 2 <= n_groups <= 3:
                return "pie", None, "percent"
            return "grouped_bar", "horizontal", "percent"
        return "grouped_bar", "horizontal" if n_groups >= 5 or s.get("rank") else "vertical", \
            labels or ("value" if n_groups <= 12 else None)
    # a time axis
    if grain in S.DATE_PARTS:
        if n_groups >= 2:
            return ("stacked_bar" if stackable else "grouped_bar"), "vertical", labels
        return "grouped_bar", "vertical", labels or ("value" if n_periods <= 12 else None)
    if n_groups >= 2:
        if s.get("share") and stackable:
            return "percent_bar", "vertical", "percent"
        if not stackable:
            return "line", None, None
        if _named_comparison(s):                 # "Alex vs Sam by month": side by side, not stacked
            return ("grouped_bar", "vertical", None) if n_periods <= 7 else ("line", None, None)
        if grain in FINE and n_periods >= 13 and n_groups > 5:
            return "line", None, None
        return "stacked_bar", "vertical", labels
    if grain in FINE and n_periods >= 13:
        return "line", None, None
    return "grouped_bar", "vertical", labels or ("value" if n_periods <= 12 else None)


def apply(answer, public, look_by=""):
    """Write the chosen look into the public spec and the result, unless the person
    named one (then only what compute.run already allowed stays).
    a type carried over from the chart on screen (look_by "previous") is a preference,
    not a choice: the shape chooses again and the page gives the previous type its boost when
    the new answer has the same shape. A map stays a map (it decides how places are counted)."""
    s = answer.spec
    named = s.get("chart_type") not in (None, "", "auto")
    if named and look_by == "previous" and s.get("chart_type") not in ("map", "venn"):
        named = False
    if named:
        public["chart_type"] = answer.result.get("chart_type", s["chart_type"])
        if s.get("orientation"):
            public["orientation"] = s["orientation"]
        if s.get("labels"):
            public["labels"] = s["labels"]
        return public
    ct, orient, labels = choose(answer)
    public["chart_type"] = ct
    answer.result["chart_type"] = ct
    if orient and not s.get("orientation"):
        public["orientation"] = orient
    if labels and not s.get("labels"):
        public["labels"] = labels
    elif s.get("labels"):
        public["labels"] = s["labels"]
    return public

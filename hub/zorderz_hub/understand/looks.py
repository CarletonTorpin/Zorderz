"""Every chart that could honestly draw an answer, with a score for its shape.

The hub no longer hands the page one chart type and nothing else: it sends

  look = {
    "by":      who chose the type below: words | rule | model | previous | shape | spec
    "type", "orientation", "labels":   that choice
    "shape_pick":  what the answer's shape alone would draw (the runoff's last tie-break)
    "shape":   {axis, grain, groups, periods, additive, share, rank, intent, key}
    "candidates": [{"type", "orientation", "score"}, …]   highest first
  }

Scores: the hub's choice 60; reasonable alternatives 25–55; a type that would misrepresent the
answer (a line through one period, a pie of things that don't add up, a stack of overlapping
groups) is not offered at all. The page adds the boosts (named +100, house rule +40, model +15,
previous chart and the person's habit) and the fit penalties for the space it has, and draws the
highest. Nothing here changes what is counted.
"""
from . import spec2 as S

ADDITIVE = {"revenue", "jobs", "units"}
FINE = {"month", "week", "day"}
BYS = ("words", "rule", "model", "previous", "shape", "spec")
DRAWN = ("grouped_bar", "stacked_bar", "percent_bar", "line", "area", "pie", "donut",
         "single_number", "table", "map", "venn")


def _groups(result):
    return [g for g in (result.get("groups") or []) if g != "All jobs"]


def _additive(spec, result):
    if "additive" in result:
        return bool(result["additive"])
    split = spec.get("split") or "none"
    return spec.get("measure") in ADDITIVE and not split.startswith("tag_") and \
        not (split == "product" and spec.get("measure") in ("jobs", "customers"))


def shape(spec, result, kind=None, v2=None):
    """What kind of answer this is. `spec` is the version-1 spec that ran; `v2` its v2 copy
    when there is one (share, rank, compare, date parts)."""
    v2 = v2 or {}
    periods = result.get("periods") or []
    groups = _groups(result)
    n_p, n_g = len(periods), len(groups)
    v1_grain = spec.get("time_grain") if spec.get("time_grain") not in (None, "", "none") else None
    grain = v2.get("date_part") or v1_grain or v2.get("grain") or "none"     # weeks are v2-only
    if kind in ("windows",) or v2.get("compare"):
        axis = "windows"
    elif kind == "overlay" or v2.get("overlay"):
        axis = "overlay"
    elif grain in S.DATE_PARTS:
        axis = "date_part"
    elif n_p > 1:
        axis = "time"
    elif n_g > 1:
        axis = "category"
    else:
        axis = "none"
    share = bool(v2.get("share")) if v2 else \
        (spec.get("labels") == "percent" and spec.get("chart_type") in ("pie", "donut", "percent_bar"))
    rank = bool(v2.get("rank"))
    named_vs = False
    if v2:
        try:
            from .describe import _versus
            named_vs = _versus(v2)[0] is not None
        except Exception:
            named_vs = False
    if share:
        intent = "share"
    elif rank:
        intent = "rank"
    elif named_vs and axis in ("time", "overlay", "windows"):
        intent = "versus"            # "Alex vs Sam by month" compares lines, not a stack
    elif axis in ("time", "overlay") and n_p >= 3 and not named_vs:
        intent = "trend"
    else:
        intent = "compare"
    g_word = "many" if n_g > 1 else "one"
    return {"axis": axis, "grain": grain if axis != "category" else "none", "groups": n_g, "periods": n_p,
            "additive": _additive(spec, result), "share": share, "rank": rank, "versus": named_vs,
            "long_names": any(len(str(g)) > 12 for g in groups), "intent": intent,
            "key": f"{axis}|{grain if axis not in ('category', 'none') else '-'}|{g_word}"}


def _scores(sh, result, kind, spec):
    """{(type, orientation): shape score} for every type that draws this answer honestly."""
    a, n_p, n_g, add = sh["axis"], sh["periods"], sh["groups"], sh["additive"]
    out = {}
    if result.get("empty"):
        return {("table", None): 60}
    if result.get("chart_type") == "venn" or spec.get("chart_type") == "venn":
        return {("venn", None): 60}
    if kind in ("jobs_per_customer", "job_range", "per_job"):
        ct = result.get("chart_type") or "grouped_bar"
        return {(ct, None if ct != "grouped_bar" else "vertical"): 60, ("table", None): 15}
    places = bool(result.get("places")) or bool(result.get("zips"))
    if a == "none":
        return {("single_number", None): 60, ("table", None): 10}
    if a == "category":
        horiz = n_g >= 5 or sh["rank"] or sh["long_names"]
        out[("grouped_bar", "horizontal")] = 60 if horiz else 50
        out[("grouped_bar", "vertical")] = 40 if horiz else 60
        if add and n_g >= 2:
            pie = (60 if n_g <= 3 else 50) if sh["share"] else (35 if n_g <= 6 else 25)
            out[("pie", None)] = pie
            out[("donut", None)] = pie - 5
        if places:
            out[("map", None)] = 45 if spec.get("chart_type") == "map" else 35
        out[("table", None)] = 15
        return out
    if a == "windows":
        out[("grouped_bar", "vertical")] = 60
        out[("grouped_bar", "horizontal")] = 30
        if add and n_g >= 2:
            out[("percent_bar", "vertical")] = 60 if sh["share"] else 30
            out[("stacked_bar", "vertical")] = 35
        if n_p >= 3:
            out[("line", None)] = 25
        out[("table", None)] = 15
        return out
    if a == "overlay":
        out[("line", None)] = 60
        out[("grouped_bar", "vertical")] = 45
        out[("table", None)] = 15
        return out
    if a == "date_part":
        out[("grouped_bar", "vertical")] = 60
        if n_g >= 2 and add:
            out[("stacked_bar", "vertical")] = 55
        if n_p >= 4:
            out[("line", None)] = 30
        out[("table", None)] = 15
        return out
    # a time run
    fine_long = sh["grain"] in FINE and n_p >= 13
    if n_g <= 1:
        out[("grouped_bar", "vertical")] = 40 if fine_long else 60
        if n_p >= 3:
            out[("line", None)] = 60 if fine_long else (45 if n_p >= 6 else 30)
        if add and n_p >= 4:
            out[("area", None)] = 30
        if n_p <= 12:
            out[("grouped_bar", "horizontal")] = 20
        out[("table", None)] = 15
        return out
    if add:
        if sh["share"]:
            out[("percent_bar", "vertical")] = 60
            out[("stacked_bar", "vertical")] = 45
        else:
            out[("stacked_bar", "vertical")] = 60
            out[("percent_bar", "vertical")] = 35
        side = 60 if sh["versus"] and n_p <= 7 else 45
        out[("grouped_bar", "vertical")] = side
        if n_p >= 3:
            out[("line", None)] = 55 if fine_long else 45
        if n_p >= 4:
            out[("area", None)] = 30
    else:
        if n_p >= 3:
            out[("line", None)] = 60
        out[("grouped_bar", "vertical")] = 45 if n_p >= 3 else 60
    out[("table", None)] = 15
    return out


def build(spec, result, kind=None, v2=None, by="shape", shape_pick=None):
    """The `look` block for a result. `spec` is the version-1 spec as sent to the page (its
    chart_type, orientation and labels are the hub's choice); `shape_pick` the type the shape
    alone would have chosen (defaults to the spec's)."""
    if not isinstance(result, dict):
        return None
    sh = shape(spec, result, kind, v2)
    scores = _scores(sh, result, kind, spec)
    if spec.get("chart_type") not in (None, "", "auto"):
        ct = spec["chart_type"]
        drawn = result.get("chart_type")
        if drawn and drawn != ct and drawn in DRAWN and not result.get("empty"):
            ct = drawn               # compute refused the asked type (a pie of averages…)
        orient = spec.get("orientation") if ct == "grouped_bar" else \
            ("vertical" if ct in ("stacked_bar", "percent_bar") else None)
        if ct == "grouped_bar" and orient not in ("vertical", "horizontal"):
            orient = "vertical"
    elif scores:
        # a chart run from the page with no type of its own ("auto"): the shape chooses
        ct, orient = sorted(scores.items(), key=lambda kv: -kv[1])[0][0]
        by = "shape" if by == "spec" else by
    else:
        ct, orient = result.get("chart_type"), None
    mine = (ct, orient)
    wx = result.get("weather") if isinstance(result.get("weather"), dict) else None
    if wx and wx.get("status") == "ok" and wx.get("values") and wx.get("view", "overlay") == "overlay":
        # under a weather overlay the sales are columns and the weather dotted lines;
        # a line chart of the sales would blur into the weather's lines, so it is offered, not favored
        for k in list(scores):
            if k[0] in ("line", "area"):
                scores[k] = min(scores[k], 40)
    if ct and ct in DRAWN:
        for k in list(scores):
            if k != mine and scores[k] >= 60:
                scores[k] = 55                 # the hub's choice stays first
        scores[mine] = 60
    cands = [{"type": t, "orientation": o, "score": v}
             for (t, o), v in sorted(scores.items(), key=lambda kv: (-kv[1], kv[0][0], kv[0][1] or ""))]
    return {"by": by if by in BYS else "shape", "type": ct, "orientation": orient,
            "labels": spec.get("labels"), "shape_pick": shape_pick or ct,
            "shape": sh, "candidates": cands}

"""Chart spec checks.

The model's grammar forces the JSON shape, not its meaning.
validate() re-checks the shape against the pack's schema and then the meaning:
real dates in order, clarify/unsupported carry a question, nothing outside the
vocabulary. normalize() fills a partial spec (from an edited chip) onto the
neutral base spec so run_spec never guesses.
"""
import copy
import datetime as dt

import jsonschema

from .compute import base_spec

OPTIONAL = ("merge_groups",)


def normalize(spec, pack):
    base = base_spec(pack)
    if not isinstance(spec, dict):
        return base
    out = copy.deepcopy(base)
    for k, v in spec.items():
        if k == "filters" and isinstance(v, dict):
            out["filters"].update(v)
        elif k in out:
            out[k] = v
        elif k in OPTIONAL and v:
            out[k] = copy.deepcopy(v)          # merged groups travel with the chart
    for k, v in out["filters"].items():
        if isinstance(v, list):
            seen, clean = set(), []
            for item in v:
                if item not in seen:
                    seen.add(item)
                    clean.append(item)
            out["filters"][k] = clean
    if isinstance(out.get("title"), str):
        out["title"] = out["title"].strip()[:90]
    return out


def _date(value):
    return dt.date.fromisoformat(value) if value else None


def validate(spec, pack, schema=None):
    """Return a list of plain-words problems; [] means the spec is good."""
    schema = schema or pack.schema()
    problems = []
    validator = jsonschema.Draft202012Validator(schema["schema"])
    for err in sorted(validator.iter_errors(spec), key=lambda e: list(e.path)):
        where = ".".join(str(p) for p in err.path) or "spec"
        problems.append(f"{where}: {err.message[:160]}")
    if problems:
        return problems
    try:
        start, end = _date(spec["date_from"]), _date(spec["date_to"])
    except ValueError:
        return ["date_from/date_to must be real calendar dates (YYYY-MM-DD)"]
    if start and end and start > end:
        problems.append("date_from is after date_to")
    if spec["intent"] in {"clarify", "unsupported"} and not (spec.get("clarify_question") or "").strip():
        problems.append(f'intent "{spec["intent"]}" needs a clarify_question')
    if spec["intent"] in {"chart", "number"} and spec.get("clarify_question"):
        problems.append('clarify_question must be null when intent is "chart" or "number"')
    if spec["split"].startswith("tag_") and pack.tag_group_for_split(spec["split"]) is None:
        problems.append(f'split "{spec["split"]}" is not a detail group')
    if spec.get("facet", "none") != "none" and spec.get("facet") == spec.get("split"):
        problems.append("facet must be different from split")
    if spec.get("chart_type") == "venn":
        choices = {"product": pack.products, "work_type": pack.work_types,
                   "salesperson": pack.salespeople + [pack.not_recorded],
                   "tag": pack.tags}.get(spec.get("venn_by"))
        sets = spec.get("venn_sets") or []
        if choices is None:
            problems.append('a venn chart needs venn_by: product, work_type, salesperson or tag')
        elif not 2 <= len(sets) <= 3:
            problems.append("a venn chart needs 2 or 3 venn_sets")
        else:
            bad = [x for x in sets if x not in choices]
            if bad:
                problems.append(f"venn_sets not in the {spec['venn_by']} list: " + ", ".join(bad)[:120])
    return problems

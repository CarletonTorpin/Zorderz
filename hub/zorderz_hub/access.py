"""The hub's second lock: what this request may see.

The website decides each person's rights (ZCH_Access, first lock) and
sends them inside the sealed envelope as `access`. The hub enforces them again
here, so a bug in one plugin route can't widen what leaves the hub:

    access = {
      "caller":     "ui" | "bot" | "app:<slug>" | "cron",
      "ops":        ["health", "vocab", "run_spec", ...],   # granted ops
      "scope":      ["GT"] | null,      # null = all salespeople; a list = only these
      "money":      true | false,       # revenue / average-job figures
      "by_person":  true | false,       # split or filter by salesperson (others)
      "line_items": "all" | "own" | "none",
      "invoices":   "all" | "own" | "none",  # open an invoice card
      "me":         "AS" | null,        # the person's own initials ("own" cards)
      "compare":    true | false,       # comparisons across salespeople, as job counts
      "kiosk":      false
    }

Rules (least privilege; anything missing or malformed means deny):
  * no access block, a kiosk, or an unknown caller → health only;
  * a bot or another app never gets ask, search_lines, data_status or line items;
  * an app may read line_options (the product vocabulary with job counts: no names,
    no money, no invoice numbers), for the Estimate Creator's tap-to-change menus;
  * `survey_only` (an app caller only): survey_submit / survey_result and health, nothing of the store's --
    the Route Planner's recorded drives, for a salesperson who may have no Charts access; survey ops never ride an
    ordinary block;
  * `app:ai-workspace` (the website's AI workspace, an assistant's relay) gets assistant_turn, assistant_status,
    assistant_cancel and health, and nothing of the store's; no other caller ever gets an assistant op;
  * cron may only hand data over (put_counts, put_sources, put_invoices) and read health;
  * a scope forces the salesperson filter to that list, whatever was asked;
  * without money, revenue and average-job become jobs, and every dollar in the
    result is removed (not zeroed);
  * without by_person, per-salesperson splits and filters are removed;
  * a scoped person sees counts and rankings of others, never their dollars: a scoped person WITH `compare` may
    ask a COMPARISON -- a split, facet or venn by salesperson, or a filter naming someone outside their scope --
    and that one chart runs over everyone, as JOB COUNTS: the measure becomes jobs, every dollar is removed, no
    job list below it (list_jobs keeps the own-jobs rule). A chart that names nobody else stays their own, with
    their own dollars.
  * an invoice card is for owners and admins (all jobs)
    and for the salesperson of that job (a rep sees the jobs marked with their own initials,
    never a job marked with another rep's): "own" opens only jobs whose
    salesperson is `me`. Only the person at the screen ever gets cards.
Every change is said in the result's notes and the spec's assumptions, so the
viewer sees what was withheld.
"""
import copy
import re

ALL_OPS = {"health", "vocab", "ask", "run_spec", "run_v2", "search_lines", "data_status", "put_counts", "put_sources",
           "put_invoices", "flag",
           "drill", "list_jobs", "invoice", "pivot", "line_options",
           "survey_submit", "survey_result",                     # the Route Planner's recorded drives
           "assistant_turn", "assistant_status", "assistant_cancel"}   # the Assistant's link
SURVEY_OPS = {"health", "survey_submit", "survey_result"}
ASSISTANT_OPS = {"assistant_turn", "assistant_status", "assistant_cancel"}
ASSISTANT_CALLER = "app:ai-workspace"
FLOORS = {
    "ui": ALL_OPS - {"put_counts", "put_sources", "put_invoices"} - ASSISTANT_OPS,
    "bot": {"health", "vocab", "run_spec", "run_v2"},        # the app's chat runs a v2 spec
    # line_options is product words and job counts only (no names, money or numbers),
    # for the Estimate Creator's tap-to-change menus (an "app:" caller).
    "app": {"health", "vocab", "run_spec", "run_v2", "line_options", "survey_submit", "survey_result"},
    # The website's scheduled job: it may only hand data over, never read anything
    # back.
    "cron": {"health", "put_counts", "put_sources", "put_invoices"},
}
DENY = {"caller": "none", "ops": {"health"}, "scope": [], "money": False,
        "by_person": False, "line_items": "none", "kiosk": True, "accounts": False,
        "invoices": "none", "me": None, "compare": False}
ME = re.compile(r"^[A-Z]{1,8}$")


class Forbidden(Exception):
    pass


def parse(raw):
    """Turn the sealed `access` block into a checked dict. Fails closed."""
    if not isinstance(raw, dict) or raw.get("kiosk") is not False:
        return dict(DENY)
    caller = str(raw.get("caller", ""))
    kind = "app" if re.fullmatch(r"app:[a-z0-9_-]{1,40}", caller) else caller
    if kind not in FLOORS:
        return dict(DENY)
    if caller == ASSISTANT_CALLER:
        # the Assistant's relay. Its three ops and health; nothing of the store's (no figures, names or
        # jobs), whatever else the block asks for. The runner it feeds knows a turn, never a person.
        return {"caller": caller, "kind": kind, "ops": {o for o in raw.get("ops") or [] if o in ASSISTANT_OPS | {"health"}},
                "scope": [], "invoices": "none", "me": None, "money": False, "by_person": False, "compare": False,
                "line_items": "none", "kiosk": False, "accounts": False, "assistant_only": True}
    ops = ({o for o in raw.get("ops") or [] if o in ALL_OPS} & FLOORS[kind]) - ASSISTANT_OPS   # the assistant caller's alone
    if raw.get("survey_only") is True:
        # the Route Planner's recorded drives, for a
        # salesperson who may have no Charts at all -- transcribe and read a drive, nothing of the store's. Only an app
        # caller; the website sends no scope (survey_only never reaches the scope == [] rule below).
        if kind != "app":
            return dict(DENY)
        return {"caller": caller, "kind": kind, "ops": ops & SURVEY_OPS, "scope": [], "invoices": "none",
                "me": None, "money": False, "by_person": False, "compare": False, "line_items": "none",
                "kiosk": False, "accounts": False, "survey_only": True}
    if raw.get("public_only") is True:
        # the region's address points and the Census figures by ZIP, for a Doorhangers walker without Charts:
        # run_v2 on the county / place specs only (ops.op_run_v2 refuses every other spec), nothing of the store's
        return {"caller": caller, "kind": kind, "ops": ops & {"health", "run_v2"}, "scope": [], "invoices": "none",
                "me": None, "money": False, "by_person": False, "compare": False, "line_items": "none",
                "kiosk": False, "accounts": False, "public_only": True}
    scope = raw.get("scope", [])
    if scope is None:
        scope = None
    elif isinstance(scope, list) and all(isinstance(s, str) and 0 < len(s) <= 8 for s in scope):
        scope = list(scope)
    else:
        return dict(DENY)
    ops -= SURVEY_OPS - {"health"}   # survey ops come only through a survey_only block
    if scope == [] and ops - {"health", "put_counts", "put_sources", "put_invoices"}:
        ops = {"health"}          # scoped to nobody: nothing to show
    line_items = raw.get("line_items") if raw.get("line_items") in {"all", "own"} else "none"
    if kind != "ui":
        line_items = "none"
    if line_items == "all" and scope is not None:
        line_items = "own"
    if line_items == "none":
        ops.discard("search_lines")
    # invoice cards. "all" needs company-wide figures (no scope); "own" needs the
    # person's own initials. Anything else, or any caller but the person at the screen: none.
    invoices = raw.get("invoices") if raw.get("invoices") in {"all", "own"} else "none"
    me = raw.get("me") if isinstance(raw.get("me"), str) and ME.match(raw.get("me")) else None
    if kind != "ui":
        invoices = "none"
    if invoices == "all" and (scope is not None or line_items != "all"):
        invoices = "own"                    # every job's card needs every job's lines
    if invoices == "own" and me is None:
        invoices = "none"
    if invoices == "none":
        ops -= {"invoice", "pivot"}
    if line_items == "none" and invoices == "none":
        ops.discard("list_jobs")
    return {"caller": caller, "kind": kind, "ops": ops, "scope": scope,
            "invoices": invoices, "me": me,
            "money": raw.get("money") is True, "by_person": raw.get("by_person") is True,
            # comparisons as counts -- only for a SCOPED person (someone with the whole company has
            # by_person instead), and never for cron
            "compare": raw.get("compare") is True and scope is not None and kind != "cron" and me is not None,
            "line_items": line_items, "kiosk": False,
            # Named accounts: people on the website only. The bot's and other
            # apps' models run elsewhere, so they never get account names.
            "accounts": raw.get("accounts") is True and kind == "ui"}


def require(access, op):
    if op not in access["ops"]:
        raise Forbidden(op)


def may_open(access, salesperson):
    """may this person open the card of a job sold by `salesperson`?"""
    inv = access.get("invoices", "none")
    if inv == "all":
        return True
    if inv == "own":
        return bool(access.get("me")) and str(salesperson or "") == access["me"] and \
            (access.get("scope") is None or access["me"] in access["scope"])
    return False


def _note(spec, notes, text):
    notes.append(text)
    a = spec.setdefault("assumptions", [])
    if text not in a and len(a) < 4:
        a.append(text[:160])


def is_comparison(spec, access):
    """does this spec look across salespeople -- a split, facet or venn by salesperson, or a filter naming
    someone outside the person's scope? Only meaningful for a scoped person with `compare`."""
    if not access.get("compare") or access.get("scope") is None or not isinstance(spec, dict):
        return False
    f = spec.get("filters") or {}
    scope = set(access["scope"])
    return spec.get("split") == "salesperson" or spec.get("facet") == "salesperson" or \
        (spec.get("chart_type") == "venn" and spec.get("venn_by") == "salesperson") or \
        any(s not in scope for s in (f.get("salespeople") or []))


COMPARE_NOTE = "Comparing salespeople shows job counts only; dollar figures are for your own jobs."


def restrict_spec(spec, access, not_recorded):
    """Apply scope, money and by_person to a spec. Returns (spec, notes)."""
    spec = copy.deepcopy(spec)
    notes = []
    f = spec["filters"]
    scope = access["scope"]
    if scope is not None and is_comparison(spec, access):
        # the comparison runs over everyone named (or everyone), as counts
        # counts only -- units (× a price book ≈ revenue) and customers become jobs too
        if spec.get("measure") in {"revenue", "avg_job", "units", "customers"}:
            spec["measure"] = "jobs"
        _note(spec, notes, COMPARE_NOTE)
        f["accounts"] = []
        f["customers"] = []                   # one customer's jobs are reached only through the person's own
    elif scope is not None:
        asked = [s for s in f.get("salespeople") or [] if s not in scope]
        f["salespeople"] = list(scope)
        if spec.get("chart_type") == "venn" and spec.get("venn_by") == "salesperson":
            spec["venn_sets"] = [x for x in spec.get("venn_sets") or [] if x in scope]
            if len(spec["venn_sets"]) < 2:
                spec["chart_type"], spec["venn_by"], spec["venn_sets"] = "auto", "none", []
        _note(spec, notes, "Showing only your own jobs (" + ", ".join(scope) + ")."
              if not asked else "Other salespeople's figures aren't available to you; showing only yours.")
    elif not access["by_person"]:
        own = access.get("invoices") == "own" and f.get("salespeople") == [access.get("me")]
        if f.get("salespeople") and not own:
            f["salespeople"] = []
            _note(spec, notes, "Per-salesperson figures aren't available to you; showing everyone combined.")
        if spec.get("split") == "salesperson":
            spec["split"] = "none"
            _note(spec, notes, "Per-salesperson figures aren't available to you; showing everyone combined.")
        if spec.get("chart_type") == "venn" and spec.get("venn_by") == "salesperson":
            spec["chart_type"], spec["venn_by"], spec["venn_sets"] = "auto", "none", []
            _note(spec, notes, "Per-salesperson figures aren't available to you; showing everyone combined.")
    if spec.get("facet") == "salesperson" and not access["by_person"] and scope is None:
        spec["facet"] = "none"
        _note(spec, notes, "Per-salesperson figures aren't available to you; showing everyone combined.")
    if spec.get("facet") == "account" and not access.get("accounts"):
        spec["facet"] = "none"
    if not access.get("accounts"):
        if (f.get("accounts") or []) or spec.get("split") == "account":
            _note(spec, notes, "Named accounts aren't available to you; showing all customers.")
        f["accounts"] = []
        if spec.get("split") == "account":
            spec["split"] = "none"
    if (f.get("customers") or []) and access.get("invoices", "none") == "none":
        # one customer's figures come only through an invoice card's pivots.
        f["customers"] = []
        _note(spec, notes, "One customer's figures aren't available to you; showing all customers.")
    elif (f.get("customers") or []) and access.get("invoices") == "own" and \
            f.get("salespeople") != [access.get("me")]:
        # a salesperson sees a customer only through their own jobs with them.
        f["salespeople"] = [access["me"]]
        _note(spec, notes, "Showing only your own jobs with this customer (" + access["me"] + ").")
    # a job's size is a dollar figure. Without money rights, or across other salespeople's jobs (counts
    # only), it is taken off and said -- "how many of Riley's jobs were over $5,000" would tell his dollars.
    if (f.get("job_min") is not None or f.get("job_max") is not None) and \
            (not access["money"] or (scope is not None and is_comparison(spec, access))):
        f.pop("job_min", None)
        f.pop("job_max", None)
        _note(spec, notes, "Job sizes aren't available here; showing jobs of every size.")
    if not access["money"] and spec.get("measure") in {"revenue", "avg_job"}:
        spec["measure"] = "jobs"
        _note(spec, notes, "Dollar figures aren't available to you; showing jobs instead.")
    return spec, notes


def restrict_result(result, access, spec=None):
    """Remove every dollar figure when money isn't allowed -- or, when the spec that ran is a
    comparison across salespeople by a scoped person (counts only)."""
    if not result or (access["money"] and not is_comparison(spec, access)):
        return result
    r = copy.deepcopy(result)
    _strip_money(r)
    return r


def _strip_money(r):
    """Every dollar out of one result -- and out of the small charts nested in it
    (`facets`, one full result per group), which the top-level pass used to miss."""
    if not isinstance(r, dict):
        return
    cols = r.get("columns", [])
    if "revenue" in cols:
        i = cols.index("revenue")
        for row in r.get("rows", []):
            if isinstance(row, list) and len(row) > i:
                row[i] = None
    if isinstance(r.get("totals"), dict):
        r["totals"].pop("revenue", None)
    for f in r.get("facets") or []:
        if isinstance(f, dict):
            _strip_money(f.get("result"))


def restrict_vocab(vocab, access):
    v = copy.deepcopy(vocab)
    if not access.get("accounts"):
        v["splits"] = [x for x in v.get("splits", []) if x != "account"]
        v.pop("accounts", None)
    if access["scope"] is not None and access.get("compare"):
        pass                                  # every salesperson, for comparisons (as counts)
    elif access["scope"] is not None:
        v["salespeople"] = list(access["scope"])
    elif not access["by_person"]:
        v["salespeople"] = []
        v["splits"] = [s for s in v["splits"] if s != "salesperson"]
    if not access["money"]:
        v["measures"] = [m for m in v["measures"] if m not in {"revenue", "avg_job"}]
        if v.get("base_spec", {}).get("measure") in {"revenue", "avg_job"}:
            v["base_spec"]["measure"] = "jobs"
    if access["scope"] is not None or not access["money"]:
        v.pop("lead_sources", None)
    v["access"] = {"ops": sorted(access["ops"]), "scoped_to": access["scope"],
                   "money": access["money"], "by_person": access["by_person"],
                   "compare": bool(access.get("compare")),           
                   "line_items": access["line_items"], "invoices": access.get("invoices", "none")}
    return v

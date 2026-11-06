"""Pivots: the questions an invoice card offers when a person taps something on it.

So:
  * CANDIDATES holds 100 drill-down templates (written from the hub's vocabulary and the
    the business's two examples; `prior` is that judgement, 0-1).
  * `rank()` scores them against this hub's own records, ON THE MAC (data rule: customer
    data never leaves it): the misses log (questions Charts answered badly, with what it
    understood), the audit log (which drills and pivots people really use), and the store
    (on how many real invoices each template gives a usable answer). It keeps 40, with
    every kind of thing on the card covered. `python -m zorderz_hub pivots rank` writes the
    choice to ~/Charts Hub/pivots-selected.json; the hub reads it at start and on reload.
  * DEFAULT_SELECTED is the shipped 40 (ranked on the anonymized samples and the priors),
    used until the hub computer has ranked its own.

A template is pure data: no model runs, so a pivot is as fast as a chart. Labels hold
{customer}, which the WEBSITE fills with the name for people allowed to see it (the hub
has no names); everything else is filled here from the pack's own vocabulary.
"""
import copy
import json
import math
import os
import re
from datetime import timedelta
from pathlib import Path

import pandas as pd

from . import compute, names

ENTITIES = ("customer", "line", "area", "rep", "source", "date")
KINDS = ("chart", "jobs", "estimates")
PER_ENTITY = 4            # pivots shown per tapped thing; the rest sit behind "More questions"
PICK = 40
MIN_PER_ENTITY = 4
# The most of one kind kept: a card has many line items, one name, one town, one rep.
MAX_PER_ENTITY = {"line": 10, "customer": 8, "area": 7, "rep": 6, "source": 6, "date": 5}
# The business's own two examples and the first thing anyone asks of a name:
# always kept, whatever the logs say.
PINNED = ("l01", "l02", "c01")
SELECTED_FILE = "pivots-selected.json"


def T(id, entity, kind, label, measure="jobs", split="none", grain="none", rng="all", filters=None,
      needs=(), prior=0.5, chart_type="auto", words=()):
    return {"id": id, "entity": entity, "kind": kind, "label": label, "measure": measure, "split": split,
            "grain": grain, "range": rng, "filters": dict(filters or {}), "needs": tuple(needs),
            "prior": prior, "chart_type": chart_type, "words": tuple(words)}


C, P, W, G, A, R, S, PT, Y = ("customer", "product", "work", "tag", "area", "rep", "source", "partner", "year")
CUST_WORDS = ("customer", "client", "bought", "history", "repeat", "again", "they", "household")
AREA_WORDS = ("town", "area", "city", "neighborhood", "neighbourhood", "near", "nearby", "zip", "local")
REP_WORDS = ("rep", "salesperson", "sales person", "sold by", "who sold", "commission")
SRC_WORDS = ("source", "lead", "referral", "referred", "google", "yelp", "partner", "came from", "found us")
TIME_WORDS = ("month", "week", "day", "season", "quarter", "same time", "that year", "this year")

# ---------------------------------------------------------------- the 100 candidates
CANDIDATES = [
    # --- the customer (tap the name) -------------------------------------------------
    T("c01", "customer", "jobs", "Every job {customer} has had with us", filters={"customers": C},
      needs=("customer",), prior=0.95, words=CUST_WORDS),
    T("c02", "customer", "chart", "{customer}'s spending by year", "revenue", grain="year",
      filters={"customers": C}, needs=("customer", "money", "customer_repeat"), prior=0.8, words=CUST_WORDS),
    T("c03", "customer", "chart", "What {customer} has bought, by product", "revenue", "product",
      filters={"customers": C}, needs=("customer", "money"), prior=0.85, words=CUST_WORDS),
    T("c04", "customer", "chart", "{customer}'s units by product", "units", "product",
      filters={"customers": C}, needs=("customer",), prior=0.6, words=CUST_WORDS),
    T("c05", "customer", "chart", "{customer}'s work types over the years", "jobs", "work_type", "year",
      filters={"customers": C}, needs=("customer", "customer_repeat"), prior=0.5, words=CUST_WORDS),
    T("c06", "customer", "jobs", "{customer}'s jobs before this one", rng="before", filters={"customers": C},
      needs=("customer", "customer_earlier"), prior=0.7, words=CUST_WORDS),
    T("c07", "customer", "jobs", "{customer}'s jobs since this one", rng="after", filters={"customers": C},
      needs=("customer", "customer_later"), prior=0.65, words=CUST_WORDS),
    T("c08", "customer", "chart", "{customer}'s jobs by salesperson", "jobs", "salesperson",
      filters={"customers": C}, needs=("customer", "by_person", "customer_repeat"), prior=0.45, words=CUST_WORDS),
    T("c09", "customer", "chart", "{customer}'s jobs by year", "jobs", grain="year", filters={"customers": C},
      needs=("customer", "customer_repeat"), prior=0.6, words=CUST_WORDS),
    T("c10", "customer", "estimates", "{customer}'s estimates", filters={"customers": C},
      needs=("customer", "has_estimates"), prior=0.75, words=CUST_WORDS + ("estimate", "quote", "bid")),
    T("c11", "customer", "chart", "How {customer}'s jobs were tagged, by lead source", "jobs", "lead_source",
      filters={"customers": C}, needs=("customer", "customer_repeat"), prior=0.35, words=CUST_WORDS + SRC_WORDS),
    T("c12", "customer", "chart", "{customer}'s products by year", "units", "product", "year",
      filters={"customers": C}, needs=("customer", "customer_repeat"), prior=0.5, words=CUST_WORDS),
    T("c13", "customer", "chart", "{customer}'s average job by year", "avg_job", grain="year",
      filters={"customers": C}, needs=("customer", "money", "customer_repeat"), prior=0.4, words=CUST_WORDS),
    T("c14", "customer", "chart", "{customer}'s spending by product, by year", "revenue", "product", "year",
      filters={"customers": C}, needs=("customer", "money", "customer_repeat"), prior=0.55, words=CUST_WORDS),
    T("c15", "customer", "chart", "{customer}'s jobs by town", "jobs", "area", filters={"customers": C},
      needs=("customer", "customer_repeat"), prior=0.3, words=CUST_WORDS + AREA_WORDS),

    # --- a line item (tap a product on the invoice) ------------------------------------
    T("l01", "line", "jobs", "All {product} {customer} has bought", filters={"customers": C, "products": P},
      needs=("customer", "product"), prior=0.95, words=CUST_WORDS),
    T("l02", "line", "chart", "{product} sales in {area}, by year", "revenue", grain="year",
      filters={"products": P, "areas": A}, needs=("product", "area", "money"), prior=0.95, words=AREA_WORDS),
    T("l03", "line", "chart", "{product} units in {area}, by year", "units", grain="year",
      filters={"products": P, "areas": A}, needs=("product", "area"), prior=0.75, words=AREA_WORDS),
    T("l04", "line", "chart", "{product} sales by year", "revenue", grain="year", filters={"products": P},
      needs=("product", "money"), prior=0.8),
    T("l05", "line", "chart", "{product} units by year", "units", grain="year", filters={"products": P},
      needs=("product",), prior=0.75),
    T("l06", "line", "chart", "{product} by town, {range}", "revenue", "area", rng="last3",
      filters={"products": P}, needs=("product", "money"), prior=0.8, words=AREA_WORDS),
    T("l07", "line", "chart", "{product} by salesperson, {range}", "revenue", "salesperson", rng="last3",
      filters={"products": P}, needs=("product", "money", "by_person"), prior=0.65, words=REP_WORDS),
    T("l08", "line", "chart", "{product} by work type, {range}", "units", "work_type", rng="last3",
      filters={"products": P}, needs=("product",), prior=0.55),
    T("l09", "line", "chart", "{product} by lead source, {range}", "jobs", "lead_source", rng="last3",
      filters={"products": P}, needs=("product",), prior=0.6, words=SRC_WORDS),
    T("l10", "line", "chart", "{product} jobs by month, {year}", "jobs", grain="month", rng="year",
      filters={"products": P}, needs=("product",), prior=0.6, words=TIME_WORDS),
    T("l11", "line", "jobs", "{product} jobs in {area}, {year}", rng="year",
      filters={"products": P, "areas": A}, needs=("product", "area"), prior=0.7, words=AREA_WORDS),
    T("l12", "line", "jobs", "Every {product} job in {area}", filters={"products": P, "areas": A},
      needs=("product", "area"), prior=0.6, words=AREA_WORDS),
    T("l13", "line", "chart", "{product} average job by year", "avg_job", grain="year", filters={"products": P},
      needs=("product", "money"), prior=0.5),
    T("l14", "line", "chart", "{product}: work types by year", "jobs", "work_type", "year",
      filters={"products": P}, needs=("product",), prior=0.45),
    T("l15", "line", "chart", "{work} work by product, {range}", "jobs", "product", rng="last3",
      filters={"work_types": W}, needs=("work",), prior=0.45),
    T("l16", "line", "chart", "{work} jobs by year", "jobs", grain="year", filters={"work_types": W},
      needs=("work",), prior=0.4),
    T("l17", "line", "chart", "{product} sales by quarter, {range}", "revenue", grain="quarter", rng="last3",
      filters={"products": P}, needs=("product", "money"), prior=0.5, words=TIME_WORDS),
    T("l18", "line", "chart", "{product} customers by year", "customers", grain="year",
      filters={"products": P}, needs=("product",), prior=0.5, words=CUST_WORDS),
    T("l19", "line", "chart", "{rep}'s {product} sales by year", "revenue", grain="year",
      filters={"products": P, "salespeople": R}, needs=("product", "rep", "money"), prior=0.55, words=REP_WORDS),
    T("l20", "line", "chart", "{product} from {source} customers, by year", "jobs", grain="year",
      filters={"products": P, "lead_sources": S}, needs=("product", "source"), prior=0.45, words=SRC_WORDS),
    T("l21", "line", "chart", "{product} for repeat customers, by year", "jobs", grain="year",
      filters={"products": P, "repeat_customers_only": True}, needs=("product",), prior=0.4, words=CUST_WORDS),
    T("l22", "line", "chart", "{product} in {area} by work type", "units", "work_type",
      filters={"products": P, "areas": A}, needs=("product", "area"), prior=0.4, words=AREA_WORDS),
    T("l23", "line", "chart", "{product} in {area} by salesperson", "jobs", "salesperson",
      filters={"products": P, "areas": A}, needs=("product", "area", "by_person"), prior=0.4, words=AREA_WORDS),
    T("l24", "line", "jobs", "The latest {product} jobs", filters={"products": P}, needs=("product",),
      prior=0.45),
    T("l25", "line", "chart", "{area}: {product} beside the other products, by year", "revenue", "product", "year",
      filters={"areas": A}, needs=("area", "money"), prior=0.55, words=AREA_WORDS),
    T("l26", "line", "jobs", "{customer}'s {work} jobs", filters={"customers": C, "work_types": W},
      needs=("customer", "work"), prior=0.5, words=CUST_WORDS),
    T("l27", "line", "chart", "{tag} jobs by year", "jobs", grain="year", filters={"tags": G},
      needs=("tag",), prior=0.4),
    T("l28", "line", "chart", "{tag} by product, {range}", "jobs", "product", rng="last3",
      filters={"tags": G}, needs=("tag",), prior=0.35),
    T("l29", "line", "jobs", "Other jobs with {tag}, {year}", rng="year", filters={"tags": G},
      needs=("tag",), prior=0.35),

    # --- the town (tap the town) --------------------------------------------------------
    T("a01", "area", "chart", "{area} sales by year", "revenue", grain="year", filters={"areas": A},
      needs=("area", "money"), prior=0.85, words=AREA_WORDS),
    T("a02", "area", "chart", "{area} by product, {range}", "revenue", "product", rng="last3",
      filters={"areas": A}, needs=("area", "money"), prior=0.85, words=AREA_WORDS),
    T("a03", "area", "chart", "{area} by salesperson, {range}", "jobs", "salesperson", rng="last3",
      filters={"areas": A}, needs=("area", "by_person"), prior=0.6, words=AREA_WORDS + REP_WORDS),
    T("a04", "area", "chart", "{area} by lead source, {range}", "jobs", "lead_source", rng="last3",
      filters={"areas": A}, needs=("area",), prior=0.6, words=AREA_WORDS + SRC_WORDS),
    T("a05", "area", "jobs", "All jobs in {area}, {year}", rng="year", filters={"areas": A},
      needs=("area",), prior=0.7, words=AREA_WORDS),
    T("a06", "area", "chart", "{area} jobs by month, {year}", "jobs", grain="month", rng="year",
      filters={"areas": A}, needs=("area",), prior=0.5, words=AREA_WORDS + TIME_WORDS),
    T("a07", "area", "chart", "{area} average job by year", "avg_job", grain="year", filters={"areas": A},
      needs=("area", "money"), prior=0.45, words=AREA_WORDS),
    T("a08", "area", "chart", "Repeat customers in {area}, by year", "customers", grain="year",
      filters={"areas": A, "repeat_customers_only": True}, needs=("area",), prior=0.5, words=AREA_WORDS + CUST_WORDS),
    T("a09", "area", "chart", "{area} customers by year", "customers", grain="year", filters={"areas": A},
      needs=("area",), prior=0.55, words=AREA_WORDS),
    T("a10", "area", "chart", "{area} by work type, {range}", "jobs", "work_type", rng="last3",
      filters={"areas": A}, needs=("area",), prior=0.4, words=AREA_WORDS),
    T("a11", "area", "chart", "Top towns, {range}", "revenue", "area", rng="last3", needs=("money",),
      prior=0.5, words=AREA_WORDS),
    T("a12", "area", "chart", "{area} units by product, by year", "units", "product", "year",
      filters={"areas": A}, needs=("area",), prior=0.5, words=AREA_WORDS),
    T("a13", "area", "chart", "Jobs for business customers in {area}, by year", "jobs", grain="year",
      filters={"areas": A, "customer_type": "business"}, needs=("area", "business"), prior=0.35, words=AREA_WORDS),
    T("a14", "area", "jobs", "{rep}'s jobs in {area}", filters={"areas": A, "salespeople": R},
      needs=("area", "rep"), prior=0.45, words=AREA_WORDS + REP_WORDS),
    T("a15", "area", "chart", "{area} sales by quarter, {range}", "revenue", grain="quarter", rng="last3",
      filters={"areas": A}, needs=("area", "money"), prior=0.4, words=AREA_WORDS + TIME_WORDS),
    T("a16", "area", "chart", "{area} jobs by year", "jobs", grain="year", filters={"areas": A},
      needs=("area",), prior=0.6, words=AREA_WORDS),

    # --- the salesperson (tap the rep) -------------------------------------------------
    T("r01", "rep", "chart", "{rep}'s sales by year", "revenue", grain="year", filters={"salespeople": R},
      needs=("rep", "money"), prior=0.8, words=REP_WORDS),
    T("r02", "rep", "chart", "{rep} by product, {year}", "revenue", "product", rng="year",
      filters={"salespeople": R}, needs=("rep", "money"), prior=0.75, words=REP_WORDS),
    T("r03", "rep", "chart", "{rep} by town, {year}", "revenue", "area", rng="year",
      filters={"salespeople": R}, needs=("rep", "money"), prior=0.6, words=REP_WORDS + AREA_WORDS),
    T("r04", "rep", "chart", "{rep} by lead source, {range}", "jobs", "lead_source", rng="last3",
      filters={"salespeople": R}, needs=("rep",), prior=0.55, words=REP_WORDS + SRC_WORDS),
    T("r05", "rep", "jobs", "{rep}'s jobs, {year}", rng="year", filters={"salespeople": R},
      needs=("rep",), prior=0.7, words=REP_WORDS),
    T("r06", "rep", "chart", "{rep}'s jobs by month, {year}", "jobs", grain="month", rng="year",
      filters={"salespeople": R}, needs=("rep",), prior=0.55, words=REP_WORDS + TIME_WORDS),
    T("r07", "rep", "chart", "{rep}'s average job by year", "avg_job", grain="year",
      filters={"salespeople": R}, needs=("rep", "money"), prior=0.5, words=REP_WORDS),
    T("r08", "rep", "chart", "Salespeople compared, {year}", "revenue", "salesperson", rng="year",
      needs=("money", "by_person"), prior=0.55, words=REP_WORDS),
    T("r09", "rep", "chart", "{rep}'s jobs by year", "jobs", grain="year", filters={"salespeople": R},
      needs=("rep",), prior=0.6, words=REP_WORDS),
    T("r10", "rep", "chart", "{rep}'s customers by year", "customers", grain="year",
      filters={"salespeople": R}, needs=("rep",), prior=0.45, words=REP_WORDS),
    T("r11", "rep", "chart", "{rep} by work type, {range}", "jobs", "work_type", rng="last3",
      filters={"salespeople": R}, needs=("rep",), prior=0.4, words=REP_WORDS),
    T("r12", "rep", "chart", "{rep}'s repeat-customer jobs by year", "jobs", grain="year",
      filters={"salespeople": R, "lead_sources": ["Existing Customer"]}, needs=("rep",), prior=0.4,
      words=REP_WORDS + CUST_WORDS),
    T("r13", "rep", "chart", "{rep}'s units by product, {range}", "units", "product", rng="last3",
      filters={"salespeople": R}, needs=("rep",), prior=0.5, words=REP_WORDS),

    # --- the lead source and the partner who sent the job --------------------------------
    T("s01", "source", "chart", "{source} jobs by year", "jobs", grain="year", filters={"lead_sources": S},
      needs=("source",), prior=0.75, words=SRC_WORDS),
    T("s02", "source", "chart", "{source} by product, {range}", "revenue", "product", rng="last3",
      filters={"lead_sources": S}, needs=("source", "money"), prior=0.65, words=SRC_WORDS),
    T("s03", "source", "chart", "{source} by town, {range}", "jobs", "area", rng="last3",
      filters={"lead_sources": S}, needs=("source",), prior=0.55, words=SRC_WORDS + AREA_WORDS),
    T("s04", "source", "jobs", "{source} jobs, {year}", rng="year", filters={"lead_sources": S},
      needs=("source",), prior=0.6, words=SRC_WORDS),
    T("s05", "source", "chart", "{source} revenue by year", "revenue", grain="year",
      filters={"lead_sources": S}, needs=("source", "money"), prior=0.6, words=SRC_WORDS),
    T("s06", "source", "chart", "Lead sources compared, {year}", "jobs", "lead_source", rng="year",
      prior=0.6, words=SRC_WORDS),
    T("s07", "source", "chart", "{source} by salesperson, {range}", "jobs", "salesperson", rng="last3",
      filters={"lead_sources": S}, needs=("source", "by_person"), prior=0.4, words=SRC_WORDS + REP_WORDS),
    T("s08", "source", "chart", "Jobs from customers who first came from {original_source}, by year", "jobs", grain="year",
      filters={"original_sources": "original_source"}, needs=("original_source",), prior=0.55, words=SRC_WORDS),
    T("s09", "source", "chart", "{source} average job by year", "avg_job", grain="year",
      filters={"lead_sources": S}, needs=("source", "money"), prior=0.35, words=SRC_WORDS),
    T("p01", "source", "jobs", "Every job {partner} sent us", filters={"partners": PT},
      needs=("partner",), prior=0.85, words=SRC_WORDS),
    T("p02", "source", "chart", "{partner} referrals by year", "jobs", grain="year", filters={"partners": PT},
      needs=("partner",), prior=0.75, words=SRC_WORDS),
    T("p03", "source", "chart", "{partner} referrals by product", "revenue", "product",
      filters={"partners": PT}, needs=("partner", "money"), prior=0.6, words=SRC_WORDS),
    T("p04", "source", "chart", "{source} partners compared, {range}", "jobs", "referral_partner", rng="last3",
      filters={"lead_sources": S}, needs=("partner", "source"), prior=0.55, words=SRC_WORDS),
    T("p05", "source", "chart", "{partner} referrals by town", "jobs", "area", filters={"partners": PT},
      needs=("partner",), prior=0.4, words=SRC_WORDS + AREA_WORDS),
    T("p06", "source", "chart", "{partner} revenue by year", "revenue", grain="year", filters={"partners": PT},
      needs=("partner", "money"), prior=0.55, words=SRC_WORDS),

    # --- the date (tap the date) ---------------------------------------------------------
    T("t01", "date", "chart", "Everything sold in {month}", "revenue", "product", rng="month",
      needs=("money",), prior=0.6, words=TIME_WORDS),
    T("t02", "date", "chart", "{year} by product", "revenue", "product", rng="year", needs=("money",),
      prior=0.7, words=TIME_WORDS),
    T("t03", "date", "chart", "{year} by month", "revenue", grain="month", rng="year", needs=("money",),
      prior=0.65, words=TIME_WORDS),
    T("t04", "date", "jobs", "Jobs the same week ({week})", rng="week", prior=0.55, words=TIME_WORDS),
    T("t05", "date", "chart", "Every year, side by side", "revenue", grain="year", needs=("money",),
      prior=0.5, words=TIME_WORDS),
    T("t06", "date", "chart", "{year} by town", "revenue", "area", rng="year", needs=("money",),
      prior=0.5, words=TIME_WORDS + AREA_WORDS),
    T("t07", "date", "chart", "{year} by lead source", "jobs", "lead_source", rng="year", prior=0.5,
      words=TIME_WORDS + SRC_WORDS),
    T("t08", "date", "jobs", "Jobs the same day", rng="day", prior=0.4, words=TIME_WORDS),
    T("t09", "date", "chart", "{year}: jobs by month", "jobs", grain="month", rng="year", prior=0.55,
      words=TIME_WORDS),
    T("t10", "date", "chart", "{month} by salesperson", "jobs", "salesperson", rng="month",
      needs=("by_person",), prior=0.4, words=TIME_WORDS + REP_WORDS),
    T("t11", "date", "chart", "{year} by work type", "jobs", "work_type", rng="year", prior=0.35,
      words=TIME_WORDS),
    T("t12", "date", "chart", "Business customers' jobs by month, {year}", "jobs", grain="month", rng="year",
      filters={"customer_type": "business"}, needs=("business",), prior=0.3, words=TIME_WORDS),
]
BY_ID = {t["id"]: t for t in CANDIDATES}

# The shipped 40: `rank()` on the anonymized samples with these priors, nothing from the
# business's data. The computer's own ranking replaces it.
DEFAULT_SELECTED = [
    "c01", "l02", "l01", "a01", "a02", "c03", "l04", "l03", "l05", "l06",
    "a05", "l11", "t02", "c02", "s01", "r01", "t03", "p01", "a03", "a04",
    "a16", "l12", "s06", "c04", "r05", "l07", "a09", "l25", "r08", "t09",
    "c10", "p02", "s05", "c06", "t01", "s04", "c07", "t05", "c09", "r02",
]


# ---------------------------------------------------------------- the selection in use
def selected_path(cfg=None):
    cfg = cfg or {}
    if cfg.get("pivots_file"):
        return Path(cfg["pivots_file"])
    return Path(names.HUB_HOME) / SELECTED_FILE


def load_selected(cfg=None):
    """The ids in use: the hub computer's ranking when it is present and sound, else the default. Returns (ids,
    source)."""
    path = selected_path(cfg)
    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
        ids = [i for i in raw.get("ids") or [] if i in BY_ID]
        if len(ids) >= 10 and len(set(ids)) == len(ids):
            return ids, "ranked on the hub computer " + str(raw.get("generated_at", ""))[:10]
    except (OSError, ValueError, AttributeError):
        pass
    return list(DEFAULT_SELECTED), "default"


# ---------------------------------------------------------------- building one pivot
def _canonical_area(pack, area):
    """The pack's own name for a town as the store writes it (aliases folded)."""
    if not area:
        return None
    if area in pack.areas:
        return area
    low = str(area).casefold()
    for name in pack.areas:
        if any(v.casefold() == low for v in pack.area_variants(name)):
            return name
    return None


def _known(v, *bad):
    return bool(v) and v not in bad


def values(ctx, pack, line_id=None):
    """The context's values for one tap (a line narrows product/work/tag to that line)."""
    from .lead_sources import FROM_HISTORY, NEW_UNKNOWN, OTHER
    from .pack import UNKNOWN
    line = (ctx.get("lines") or {}).get(line_id) if line_id else None
    product = (line or {}).get("product") or (ctx["products"][0] if ctx.get("products") else None)
    work = (line or {}).get("work") or (ctx["works"][0] if ctx.get("works") else None)
    tags = (line or {}).get("tags") or ctx.get("tags") or []
    issued = ctx.get("issued")
    issued = pd.Timestamp(issued) if issued is not None and not pd.isna(issued) else None
    source = ctx.get("source")
    if source == FROM_HISTORY:
        # "Existing Customer" already folds in the repeat jobs found from history (and the
        # panel says how many): the pivot asks the question people mean
        from .lead_sources import EXISTING
        source = EXISTING
    return {
        "customer": ctx.get("customer"),
        "product": product if product in pack.products else None,
        "work": work if work in pack.work_types else None,
        "tag": next((t for t in tags if t in pack.tags), None),
        "area": _canonical_area(pack, ctx.get("area")),
        "rep": ctx["rep"] if _known(ctx.get("rep"), UNKNOWN) and ctx.get("rep") in pack.salespeople else None,
        "source": source if _known(source, NEW_UNKNOWN, OTHER, UNKNOWN) and re.fullmatch(
            r"[A-Za-z0-9 &'./-]{1,40}", source or "") else None,
        "partner": ctx.get("partner") if ctx.get("partner") and re.fullmatch(
            r"[A-Za-z0-9 &'./()-]{1,40}", ctx["partner"]) else None,
        "original_source": ctx.get("original_source") if _known(ctx.get("original_source"), NEW_UNKNOWN, OTHER,
                                                                  FROM_HISTORY) and re.fullmatch(
            r"[A-Za-z0-9 &'./-]{1,40}", ctx.get("original_source") or "") else None,
        "issued": issued,
        "year": str(issued.year) if issued is not None else None,
        "customer_jobs": ctx.get("customer_jobs") or 0,
        "is_business": ctx.get("is_business"),
        "estimates": ctx.get("estimates") or 0,
        "job_index": ctx.get("job_index"),
        "own_rep": ctx.get("own_rep"),
    }


def needs_met(t, v, access, earlier=None, later=None):
    for n in t["needs"]:
        if n in ("customer", "product", "work", "tag", "area", "rep", "source", "partner", "original_source") \
                and not v.get(n):
            return False
        if n == "money" and not access.get("money"):
            return False
        if n == "rep" and not (access.get("by_person") or access.get("invoices") == "own"):
            return False
        if n == "by_person" and not (access.get("by_person") and access.get("scope") is None):
            return False
        if n == "customer_repeat" and (v.get("customer_jobs") or 0) < 2:
            return False
        if n == "customer_earlier" and not earlier:
            return False
        if n == "customer_later" and not later:
            return False
        if n == "has_estimates" and not v.get("estimates"):
            return False
        if n == "business" and v.get("is_business") is not True:
            return False
    if t["range"] not in ("all",) and v.get("issued") is None:
        return False
    return True


def _window(rng, issued):
    if rng == "all" or issued is None:
        return None, None
    d = issued.normalize()
    if rng == "year":
        return f"{d.year}-01-01", f"{d.year}-12-31"
    if rng == "last3":
        return f"{d.year - 2}-01-01", f"{d.year}-12-31"
    if rng == "month":
        end = (d + pd.offsets.MonthEnd(0)).date().isoformat()
        return f"{d.year}-{d.month:02d}-01", end
    if rng == "week":
        return (d - timedelta(days=3)).date().isoformat(), (d + timedelta(days=3)).date().isoformat()
    if rng == "day":
        return d.date().isoformat(), d.date().isoformat()
    if rng == "before":
        return None, (d - timedelta(days=1)).date().isoformat()
    if rng == "after":
        return (d + timedelta(days=1)).date().isoformat(), None
    return None, None


def _words(rng, issued):
    if issued is None:
        return ""
    if rng == "last3":
        return f"{issued.year - 2}–{issued.year}"
    if rng == "week":
        a, b = (pd.Timestamp(x) for x in _window("week", issued))
        ma, mb = compute.MONTHS[a.month - 1], compute.MONTHS[b.month - 1]
        if a.year != b.year:
            return f"{ma} {a.day}, {a.year}–{mb} {b.day}, {b.year}"
        return f"{ma} {a.day}–{b.day}, {a.year}" if a.month == b.month else f"{ma} {a.day}–{mb} {b.day}, {a.year}"
    return ""


def label_for(t, v):
    """The words on the button. {customer} stays for the website to fill."""
    issued = v.get("issued")
    fill = {
        "product": v.get("product") or "", "work": v.get("work") or "", "tag": v.get("tag") or "",
        "area": v.get("area") or "", "rep": v.get("rep") or "", "source": v.get("source") or "",
        "partner": v.get("partner") or "", "original_source": v.get("original_source") or "",
        "year": v.get("year") or "",
        "month": f"{compute.MONTHS[issued.month - 1]} {issued.year}" if issued is not None else "",
        "range": _words(t["range"], issued), "week": _words("week", issued),
    }

    def sub(m):
        k = m.group(1)
        return "{customer}" if k == "customer" else fill.get(k, "")
    return re.sub(r"\{(\w+)\}", sub, t["label"])


def build_spec(t, v, pack):
    """The spec a template asks for, filled from one card's values (unrestricted: the
    caller applies the person's access)."""
    s = compute.base_spec(pack)
    s.update(measure=t["measure"], split=t["split"], time_grain=t["grain"], chart_type=t["chart_type"])
    if t["split"] in ("lead_source", "original_source", "referral_partner", "area", "product"):
        s["top_n"] = 15 if t["split"] in ("area", "product") else 7
    lo, hi = _window(t["range"], v.get("issued"))
    s["date_from"], s["date_to"] = lo, hi
    for key, slot in t["filters"].items():
        if isinstance(slot, (list, bool)) or (isinstance(slot, str) and key == "customer_type"):
            s["filters"][key] = copy.deepcopy(slot)
            continue
        val = v.get(slot)
        if not val:
            return None
        s["filters"][key] = [val]
    title = label_for(t, v).replace("{customer}", "this customer")
    s["title"] = title[:90]
    s["intent"] = "chart"
    return s


def earlier_later(data, v):
    """Does this customer have a job before / after the card's?"""
    ck, issued = v.get("customer"), v.get("issued")
    if not ck or issued is None:
        return False, False
    w = data.work
    hit = w["customer_key"].astype(str) == str(ck)
    if v.get("own_rep"):                                             # their own jobs only
        inv = data.invoices
        theirs = set(inv.loc[inv["salesperson"].fillna("") == v["own_rep"], "invoice_no"].astype(str))
        hit &= w["invoice_no"].astype(str).isin(theirs)
    mine = w.loc[hit, "issued"]
    day = issued.normalize()
    return bool((mine < day).any()), bool((mine >= day + timedelta(days=1)).any())


def offer(data, pack, ctx, access, selected, line_id=None, entity=None, limit=None):
    """The pivots for one tap: [(template, spec, label)] in rank order, every one whose
    needs this card and this person meet (or `limit` at most). The first PER_ENTITY are
    shown; the plugin puts the rest behind "More questions"."""
    v = values(ctx, pack, line_id)
    earlier, later = earlier_later(data, v)
    out = []
    for tid in selected:
        t = BY_ID.get(tid)
        if t is None or (entity and t["entity"] != entity):
            continue
        if not needs_met(t, v, access, earlier, later):
            continue
        s = build_spec(t, v, pack)
        if s is None:
            continue
        out.append((t, s, label_for(t, v)))
        if limit and len(out) >= limit:
            break
    return out


# ---------------------------------------------------------------- ranking
MISS_WEIGHT = {"flagged": 1.0, "unreadable": 0.8, "unsupported": 0.7, "empty": 0.6, "asked_again": 0.6,
               "clarify": 0.5}


def _read_jsonl(path, limit=200000):
    out = []
    try:
        with open(path, encoding="utf-8") as f:
            for i, line in enumerate(f):
                if i >= limit:
                    break
                try:
                    e = json.loads(line)
                    if isinstance(e, dict):
                        out.append(e)
                except ValueError:
                    continue
    except OSError:
        pass
    return out


def _slots(t):
    return {k for k in t["filters"]}


def demand(t, misses, pack):
    """How well past problem questions match this template's shape and words (0..)."""
    total = 0.0
    prod_words = {p.casefold() for p in pack.products}
    for e in misses:
        w = MISS_WEIGHT.get(e.get("kind"), 0.0)
        if not w:
            continue
        u = e.get("understood") or {}
        q = str(e.get("question") or "").casefold()
        m = 0.0
        if u.get("split") and u.get("split") == t["split"]:
            m += 0.5
        if u.get("measure") and u.get("measure") == t["measure"]:
            m += 0.3
        keys = {k for k in (u.get("filters") or {}) if k not in ("customer_type", "repeat_customers_only")}
        mine = _slots(t)
        if keys or mine:
            m += 0.2 * len(keys & mine) / len(keys | mine)
        if u.get("time_grain") and u.get("time_grain") == t["grain"]:
            m += 0.1
        if any(word in q for word in t["words"]):
            m += 0.4
        if "products" in mine and any(p in q for p in prod_words):
            m += 0.1
        total += w * m
    return total


def usage(t, audit):
    """How often people used this pivot, or drilled to its split, in the audit log."""
    used = sum(1 for e in audit if e.get("op") == "pivot" and e.get("template") == t["id"] and e.get("ok"))
    drills = sum(1 for e in audit if e.get("op") == "drill" and e.get("ok") and e.get("drill") == t["split"])
    jobs = sum(1 for e in audit if e.get("op") == "list_jobs" and e.get("ok")) if t["kind"] == "jobs" else 0
    return math.log1p(used) * 2 + math.log1p(drills) * 0.5 + math.log1p(jobs) * 0.3


def coverage(t, data, pack, ctxs, min_jobs):
    """Share of sampled real invoices where the template answers usefully (0..1), or None
    when no sampled invoice even has what it needs (no partner named on any of them, no
    estimates in the store): untested here, which is not the same as useless."""
    if not ctxs:
        return 0.0
    owner = {"money": True, "by_person": True, "scope": None}
    got, applicable = 0.0, 0
    for ctx in ctxs:
        v = values(ctx, pack, next(iter(ctx.get("lines") or {}), None))
        e, l = earlier_later(data, v)
        if not needs_met(t, v, owner, e, l):
            continue
        applicable += 1
        s = build_spec(t, v, pack)
        if s is None:
            continue
        if t["kind"] == "estimates":
            got += 1.0 if v.get("estimates") else 0.0
            continue
        try:
            rows = compute.select(data, pack, s)
        except Exception:
            continue
        n = int(rows["invoice_no"].nunique()) if len(rows) else 0
        if t["kind"] == "jobs":
            got += 1.0 if n >= 2 else (0.2 if n == 1 else 0.0)
        else:
            got += 1.0 if n >= min_jobs else (0.4 if n else 0.0)
    return got / len(ctxs) if applicable else None


def sample_contexts(data, pack, n=40, seed=7):
    """Up to n real invoices with work lines, spread over the years, newest weighted."""
    inv = data.invoices.dropna(subset=["issued"])
    have = set(data.work["invoice_no"].astype(str))
    inv = inv[inv["invoice_no"].astype(str).isin(have)]
    if not len(inv):
        return []
    pick = inv.sample(n=min(n, len(inv)), random_state=seed, weights=None) if len(inv) > n else inv
    out = []
    for no in pick["invoice_no"]:
        _, ctx = compute.invoice_card(data, pack, no)
        if ctx:
            out.append(ctx)
    return out


def rank(data, pack, misses=(), audit=(), sample=40, pick=PICK, min_jobs=None, seed=7):
    """Score every candidate; return (ids, rows) where rows explain each score."""
    min_jobs = min_jobs or getattr(pack, "drill_min_jobs", None) or compute.MIN_JOBS
    ctxs = sample_contexts(data, pack, sample, seed) if data is not None else []
    rows = []
    for t in CANDIDATES:
        cov = coverage(t, data, pack, ctxs, min_jobs) if ctxs else 0.0
        rows.append({"id": t["id"], "entity": t["entity"], "label": t["label"], "prior": t["prior"],
                     "coverage": None if cov is None else round(cov, 3),
                     "demand_raw": demand(t, misses, pack), "usage_raw": usage(t, audit)})
    for key in ("demand_raw", "usage_raw"):
        top = max((r[key] for r in rows), default=0) or 1.0
        for r in rows:
            r[key.replace("_raw", "")] = round(r[key] / top, 3)
    # Weights. Coverage is a gate more than a ranking: a template that answers usefully on
    # half the sampled invoices is as good as one that always does. With no problem
    # questions logged yet, their weight goes to the prior (the written judgement).
    have_demand = any(r["demand_raw"] > 0 for r in rows)
    have_usage = any(r["usage_raw"] > 0 for r in rows)
    w = {"prior": 0.35, "coverage": 0.30, "demand": 0.25, "usage": 0.10}
    if not have_demand:
        w["prior"] += w.pop("demand")
    if not have_usage:
        w["prior"] += w.pop("usage")
    for r in rows:
        if not ctxs:
            cov = 1.0
        elif r["coverage"] is None:
            cov = 0.5                        # untested on these invoices: neutral
        else:
            cov = min(1.0, r["coverage"] / 0.5)
        r["score"] = round(sum(wt * (cov if k == "coverage" else r[k]) for k, wt in w.items()), 4)
        if r["coverage"] == 0 and ctxs:
            r["score"] = 0.0  # never answered anything on this computer's invoices
    order = sorted(rows, key=lambda r: (-r["score"], r["id"]))
    ids = choose(order, pick)
    return ids, order


def _shape(t):
    return (t["kind"], t["measure"], t["split"], t["grain"], t["range"], tuple(sorted(t["filters"])))


def choose(order, pick=PICK):
    """The top `pick`, every entity with at least MIN_PER_ENTITY and none with more than
    MAX_PER_ENTITY, and no two templates asking the same question."""
    chosen, shapes, per = [], set(), {e: 0 for e in ENTITIES}
    for tid in PINNED:
        t = BY_ID[tid]
        chosen.append(tid); shapes.add(_shape(t)); per[t["entity"]] += 1
    for e in ENTITIES:                                   # floors first
        for r in order:
            if per[e] >= MIN_PER_ENTITY:
                break
            t = BY_ID[r["id"]]
            if t["entity"] != e or r["id"] in chosen or _shape(t) in shapes or r["score"] <= 0:
                continue
            chosen.append(r["id"]); shapes.add(_shape(t)); per[e] += 1
    for r in order:
        if len(chosen) >= pick:
            break
        t = BY_ID[r["id"]]
        if r["id"] in chosen or _shape(t) in shapes or per[t["entity"]] >= MAX_PER_ENTITY[t["entity"]] \
                or r["score"] <= 0:
            continue
        chosen.append(r["id"]); shapes.add(_shape(t)); per[t["entity"]] += 1
    rank_of = {r["id"]: i for i, r in enumerate(order)}
    return sorted(chosen, key=lambda i: rank_of[i])


def write_selected(path, ids, rows, signals, hub_version):
    import time
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    body = {"generated_at": time.strftime("%Y-%m-%dT%H:%M:%S"), "hub_version": hub_version, "ids": ids,
            "signals": signals,
            "scores": {r["id"]: {k: r[k] for k in ("score", "coverage", "demand", "usage", "prior")} for r in rows}}
    tmp = path.with_suffix(".tmp")
    tmp.write_text(json.dumps(body, indent=1), encoding="utf-8")
    os.chmod(tmp, 0o600)
    tmp.replace(path)
    return body


def report(ids, rows, signals):
    chosen = set(ids)
    out = ["# Charts pivots — ranking", "",
           f"Signals read: {signals.get('misses', 0)} misses-log entries, {signals.get('audit', 0)} audit rows, "
           f"{signals.get('sampled', 0)} sampled invoices. No figures or names are in this report.", "",
           "| # | id | kept | entity | template | score | coverage | demand | usage | prior |",
           "|---|---|---|---|---|---|---|---|---|---|"]
    for i, r in enumerate(rows, 1):
        out.append(f"| {i} | {r['id']} | {'✓' if r['id'] in chosen else ''} | {r['entity']} | {r['label']} | "
                   f"{r['score']} | {r['coverage']} | {r['demand']} | {r['usage']} | {r['prior']} |")
    return "\n".join(out) + "\n"

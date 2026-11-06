"""Command line for the Charts hub.

  python -m zorderz_hub init                 write a default config (0600) if none
  python -m zorderz_hub keys status|init     the key files (fingerprints only); init on a brand-new install
  python -m zorderz_hub keys hub|hub-public  the hub's own key pair (new installs only) and its public half
  python -m zorderz_hub keys site KEY        accept the website's public key
  python -m zorderz_hub import               rebuild the store from the import folder
  python -m zorderz_hub serve                run the hub
  python -m zorderz_hub selfcheck            run the nightly self-check now (codes only)
  python -m zorderz_hub region check         read the region pack and say what it offers
  python -m zorderz_hub region shapes FILE   write the ZIP outlines the website's map draws (GeoJSON)
  python -m zorderz_hub assistant serve      the assistant engine's runner (off until assistant.mode is on)
  python -m zorderz_hub prompts OUTDIR       write the rendered prompts + schema (no data)
  python -m zorderz_hub accounts             a starting list of named business accounts (private file)
  python -m zorderz_hub misses               a short report of questions Charts couldn't answer well
  python -m zorderz_hub pivots rank|show     the invoice-card pivots, ranked from this computer's logs
  python -m zorderz_hub bench                time drills and cards on the store (timings only)
  python -m zorderz_hub line-options         the estimate tap-to-change map (product words and counts only)
  python -m zorderz_hub weather|zipfacts|safety|places|prospect ...   the public-data downloads, by hand
  python -m zorderz_hub feed-check|feed-mode the website's live invoice feed

There is deliberately no command that answers a question in plaintext.
"""
import argparse
import json
import os
import sys
from pathlib import Path

from . import HUB_VERSION, compute, config, importer, names, store
from .audit import Audit
from .pack import load_pack


def cmd_accounts(cfg, args):
    """Write the business's starting accounts list from the invoicing system's organizations. Runs on this
    machine; prints counts only, never a name (terminal output gets pasted into chats)."""
    import os
    import yaml
    from . import accounts
    if getattr(args, "clean", False):
        return _clean_nicknames(accounts.config_path())
    mod = importer.adapter(cfg["adapter"])
    if not hasattr(mod, "suggest_accounts"):
        print("This data source can't suggest accounts; write the list by hand.")
        return 1
    items, n_orgs = mod.suggest_accounts(cfg["import_dir"], cfg.get("customer_list"),
                                         min_jobs=args.min_jobs, limit=args.limit)
    target = accounts.config_path()
    out = target if not target.exists() else target.with_name("charts-accounts.suggested.yml")
    header = (
        "# Named accounts for Charts. PRIVATE: stays on the hub computer, never sent to any AI.\n"
        "# Each entry is one business customer Charts may show by name (to owners and\n"
        "# admins only). Delete any you don't want named. A business is found by its\n"
        "# name and 'other_spellings' (other ways the invoicing system writes it); an\n"
        "# acronym like ABCD works by itself when it fits only one business. Add\n"
        "# 'nicknames: [...]' only for something people really type. Then import again.\n"
        "# jobs_when_listed is only a hint for sorting; it's never read.\n")
    out.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(out, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        f.write(header + yaml.safe_dump({"accounts": items}, sort_keys=False, allow_unicode=True, width=200))
    print(f"{n_orgs} business customers found; {len(items)} with at least {args.min_jobs} job(s) listed.")
    print(f"Wrote {out} (private). Review it, then run the import.")
    if out != target:
        print(f"(Your existing list {target.name} was left as it is.)")
    return 0


def _clean_nicknames(path):
    """Take out nicknames that are only the name's initials. Everything else in the file is kept. Prints counts
    only."""
    import os
    import yaml
    from . import accounts
    if not path.exists():
        print("No accounts list yet; nothing to clean.")
        return 0
    text = path.read_text(encoding="utf-8")
    head = "".join(l for l in text.splitlines(keepends=True) if l.startswith("#"))
    raw = yaml.safe_load(text) or {}
    removed = 0
    for item in raw.get("accounts") or []:
        if not isinstance(item, dict):
            continue
        ini = accounts.initials(str(item.get("name") or "")).casefold()
        nicks = [str(n) for n in item.get("nicknames") or []]
        keep = [n for n in nicks if not ini or n.strip().casefold() != ini]
        removed += len(nicks) - len(keep)
        if keep:
            item["nicknames"] = keep
        else:
            item.pop("nicknames", None)
    backup = path.with_name(path.name + ".before-clean")
    fd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        f.write(text)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        f.write(head + yaml.safe_dump(raw, sort_keys=False, allow_unicode=True, width=200))
    os.chmod(path, 0o600)
    os.chmod(backup, 0o600)
    print(f"Removed {removed} generated initials from {len(raw.get('accounts') or [])} accounts. "
          f"Backup: {backup.name}. Acronyms of 3+ letters still work when they fit one business.")
    return 0


def cmd_misses(cfg, since):
    """Report of the misses log for a period, written next to the store on this computer."""
    import re as _re
    from datetime import date
    from .misses import MissLog
    m = _re.fullmatch(r"\s*(\d{1,4})\s*d?\s*", str(since or ""), _re.I)
    if not m or int(m.group(1)) < 1:
        print("--since takes a number of days, like 7d or 30d.")
        return 2
    days = int(m.group(1))
    log_path = Path(cfg.get("audit_log") or names.HUB_HOME / "logs" / "audit.jsonl").parent / "misses.jsonl"
    text, counts = MissLog(log_path, cfg.get("pack_dir")).report(days, HUB_VERSION)
    out = names.HUB_HOME / f"misses-{date.today().isoformat()}.md"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(text, encoding="utf-8")
    os.chmod(out, 0o600)
    total = sum(counts.values())
    print(f"{total} miss(es) in the last {days} days" + (": " + ", ".join(f"{k} {v}" for k, v in counts.items()) if counts else "") + ".")
    print(f"Report: {out}")
    return 0


def cmd_pivots(cfg, args):
    """Rank the 100 candidate pivots on THIS computer and keep 40 (data never leaves it)."""
    from datetime import date
    from . import pivots
    if args.action == "show":
        ids, src = pivots.load_selected(cfg)
        print(f"{len(ids)} pivots in use ({src}):")
        for i in ids:
            t = pivots.BY_ID[i]
            print(f"  {i}  [{t['entity']}/{t['kind']}]  {t['label']}")
        return 0
    data = store.load(cfg["store"])
    pack = load_pack(cfg["pack_dir"])
    logs = Path(cfg.get("audit_log") or names.HUB_HOME / "logs" / "audit.jsonl")
    misses = pivots._read_jsonl(logs.parent / "misses.jsonl")
    audit = pivots._read_jsonl(logs)
    ids, rows = pivots.rank(data, pack, misses, audit, sample=args.sample)
    signals = {"misses": len(misses), "audit": len(audit), "sampled": min(args.sample, len(data.invoices)),
               "problem_misses": sum(1 for e in misses if e.get("kind") in pivots.MISS_WEIGHT)}
    out = pivots.selected_path(cfg)
    pivots.write_selected(out, ids, rows, signals, HUB_VERSION)
    rep = names.HUB_HOME / f"pivots-rank-{date.today().isoformat()}.md"
    rep.parent.mkdir(parents=True, exist_ok=True)
    rep.write_text(pivots.report(ids, rows, signals), encoding="utf-8")
    os.chmod(rep, 0o600)
    print(f"Read {signals['misses']} misses ({signals['problem_misses']} problem questions), "
          f"{signals['audit']} audit rows, {signals['sampled']} sampled invoices.")
    print(f"Kept {len(ids)} of {len(pivots.CANDIDATES)} pivots: {out}")
    print(f"Report: {rep}")
    print("Restart the hub service to use them.")
    return 0


def cmd_bench(cfg, args):
    """Time drills and invoice cards on the real store. Prints timings only."""
    import random
    import statistics
    import time as _t
    from .audit import Audit as _A
    from .llm import LMStudio
    from .ops import Hub
    pack = load_pack(cfg["pack_dir"])
    cfg = {**cfg, "audit_log": str(Path(cfg.get("audit_log") or names.HUB_HOME / "logs" / "audit.jsonl").parent
                                   / "bench-audit.jsonl")}
    hub = Hub(cfg, pack, LMStudio({"url": "http://127.0.0.1:9"}, token=""), _A(cfg["audit_log"]))
    if hub.data is None:
        print("No store yet.")
        return 2
    acc = {"caller": "ui", "ops": ["run_spec", "drill", "list_jobs", "invoice", "pivot"], "scope": None,
           "money": True, "by_person": True, "line_items": "all", "invoices": "all", "kiosk": False}
    base = {"op": "drill", "access": acc, "user": "bench", "today": _t.strftime("%Y-%m-%d")}
    spec = compute.base_spec(pack)
    spec.update(measure="units", split="product", time_grain="year")
    run = hub.dispatch({**base, "op": "run_spec", "spec": spec})
    rows = (run.get("data") or {}).get("result", {}).get("rows", []) if run.get("ok") else []
    taps = [(r[0], r[1]) for r in rows if compute.with_group(spec, r[1], pack) is not None]
    random.Random(7).shuffle(taps)
    times = {"drill": [], "invoice": [], "pivot": []}
    for p, g in taps[:args.n]:
        t0 = _t.time()
        hub.dispatch({**base, "spec": spec, "group": g, "period": p})
        times["drill"].append((_t.time() - t0) * 1000)
    nos = list(hub.data.invoices["invoice_no"].astype(str))
    random.Random(7).shuffle(nos)
    for no in nos[:args.n]:
        t0 = _t.time()
        r = hub.dispatch({**base, "op": "invoice", "invoice_no": no})
        times["invoice"].append((_t.time() - t0) * 1000)
        piv = ((r.get("data") or {}).get("card") or {}).get("pivots") or {}
        first = next((x for e in ("customer", "area", "rep") for x in piv.get(e) or []), None)
        if first:
            t0 = _t.time()
            hub.dispatch({**base, "op": "pivot", "invoice_no": no, "template": first["template"]})
            times["pivot"].append((_t.time() - t0) * 1000)
    print(f"Charts hub {HUB_VERSION} bench on {len(hub.data.invoices):,} invoices "
          f"({len(hub.data.work):,} work lines):")
    for k, v in times.items():
        if v:
            v = sorted(v)
            print(f"  {k:8s} n={len(v):3d}  median {statistics.median(v):7.0f} ms   "
                  f"p90 {v[int(len(v) * 0.9) - 1 if len(v) > 1 else 0]:7.0f} ms   max {v[-1]:7.0f} ms")
    return 0


def cmd_region(cfg, args):
    """The region pack: what it offers (check), or the ZIP outlines for the website's map (shapes)."""
    from . import region as R
    from . import weather as W
    reg = R.load(cfg)
    if args.action == "check":
        print(f"Region pack: {reg.folder}")
        if not reg.configured:
            print("  not configured: region.json needs a name and a box [lat0, lat1, lon0, lon1]")
            return 1
        print(f"  {reg.name} ({reg.id}); box {list(reg.box)}; time zone {reg.timezone}")
        for name in sorted(reg.layers):
            lay = reg.layers[name]
            where = lay.spec.get("path") if lay.kind == "file" else lay.url
            ok = (lay.path().exists() if lay.kind == "file" and lay.path() else True)
            print(f"  layer {name:16} {lay.kind:16} {where}" + ("" if ok else "   MISSING FILE"))
        zp = W.zip_areas_path(cfg.get("pack_dir"))
        print(f"  ZIP outlines: {zp or 'none'}")
        return 0
    if args.action == "shapes":
        if not args.file:
            print("region shapes needs the file to write", file=sys.stderr)
            return 2
        areas = W.load_zip_areas(cfg.get("pack_dir"))
        names_ = {}
        try:
            gj = json.loads(Path(W.zip_areas_path(cfg.get("pack_dir"))).read_text(encoding="utf-8"))
            for f in gj.get("features") or []:
                pr = f.get("properties") or {}
                if pr.get("z"):
                    names_[str(pr["z"])[:5]] = str(pr.get("n") or "")
        except (OSError, ValueError):
            pass

        def simple(ring, step=0.0005):
            out, last = [], None
            for x, y in ring:
                p = (round(float(x), 5), round(float(y), 5))
                if last is None or abs(p[0] - last[0]) >= step or abs(p[1] - last[1]) >= step:
                    out.append(list(p))
                    last = p
            if out and out[0] != out[-1]:
                out.append(list(out[0]))
            return out if len(out) >= 4 else [list(map(float, pt[:2])) for pt in ring]
        feats = []
        for z in sorted(areas):
            polys = [[simple(r) for r in poly] for poly in areas[z]]
            feats.append({"type": "Feature", "properties": {"z": z, "n": names_.get(z, "")},
                          "geometry": {"type": "MultiPolygon", "coordinates": polys}})
        out = Path(args.file)
        out.write_text(json.dumps({"type": "FeatureCollection", "features": feats}, separators=(",", ":")),
                       encoding="utf-8")
        print(f"Wrote {len(feats)} ZIP outlines to {out} (public outlines, no business data)")
        return 0
    return 2


def cmd_line_options(cfg, args):
    """write line-options.json (the map the website gets) and a report of coverage and of
    the words the vocabulary doesn't know yet (only words seen 25+ times: never a person's name)."""
    from . import line_options as lo
    lex = lo.load_lexicon(cfg.get("pack_dir"))
    if lex is None:
        print(f"No {lo.LEXICON_FILE} in the pack folder ({cfg.get('pack_dir')}).")
        return 1
    try:
        data = store.load(cfg["store"])
    except store.StoreError as e:
        print(f"The store couldn't be read: {e}")
        return 1
    fmap, unknown = lo.build(lo.lines_of(data), lex)
    out = Path(args.out) if args.out else Path(cfg["store"]).parent
    out.mkdir(parents=True, exist_ok=True)
    path = out / "line-options.json"
    path.write_text(json.dumps(fmap, indent=1, ensure_ascii=False) + "\n")
    os.chmod(path, 0o600)
    rep = out / f"line-options-report-{fmap['built']}.md"
    lo.write_report(fmap, unknown, rep)
    os.chmod(rep, 0o600)
    src = fmap["source"]
    print(f"Line options: {src['lines']:,} priced lines on {src['invoices']:,} invoices ({src['from']} to {src['to']}).")
    for f in fmap["families"]:
        pct = (100.0 * f["lines_with_a_choice"] / f["lines"]) if f["lines"] else 0
        print(f"  {f['label']}: {f['lines']:,} lines, {pct:.0f}% with a tappable choice")
    print(f"Map: {path}\nReport: {rep}")
    return 0


def cmd_weather(cfg, args):
    """the weather download, by hand. Public NOAA data only; prints dates, counts and degrees."""
    import datetime as _dt
    from . import weather as W
    store = W.Store(cfg)
    if args.action == "update":
        up = W.Updater(cfg, store=store, version=HUB_VERSION)
        print("Fetching NOAA nClimGrid-Daily for the ZIP areas' box (one month per request) …", flush=True)
        st = up.run_once(limit=args.months or None, say=lambda t: print("  " + t, flush=True))
        print(f"Weather: {st.get('first_day')} → {st.get('last_day')}; {st.get('months')} month(s) on file, "
              f"{st.get('missing')} still to fetch; {st.get('requests')} request(s), "
              f"{round((st.get('bytes') or 0) / 1e6, 1)} MB via {st.get('via') or '-'}"
              + (f"; error: {st['error']}" if st.get("error") else ""))
        return 0 if not st.get("error") else 1
    if args.action == "rebuild":
        built, rows = store.rebuild(everything=True)
        print(f"ZIP days rebuilt from the grids on file: {built} month(s), {rows} rows.")
        return 0
    if args.action == "status":
        s = W.summary(store)
        st = store.status()
        z = store.zips()
        near = sum(1 for v in z.values() if v.get("basis") == "nearest")
        print(f"Weather ({W.SOURCE_LONG}): {s['first_day']} → {s['last_day']}"
              + (f", preliminary from {s['prelim_from']}" if s.get("prelim_from") else ""))
        print(f"  {s['months']} month(s) on file, {s.get('missing_months')} to fetch; last fetch {s.get('last_fetch')}; "
              f"{len(z)} ZIP codes ({near} use the nearest cell)" + (f"; last error: {s['error']}" if s.get("error") else ""))
        print(f"  Folder: {store.folder}")
        return 0
    a = _dt.date.fromisoformat(args.date_from) if args.date_from else None
    b = _dt.date.fromisoformat(args.date_to) if args.date_to else None
    first, last, _p = store.coverage()
    if last is None:
        print("No weather on file yet: run  zorderz_hub weather update")
        return 1
    b = b or last
    a = a or (b - _dt.timedelta(days=13))
    zips = sorted(store.zips())
    if args.action == "show":
        z = args.zip or (zips[0] if zips else "")
        if z not in zips:
            print(f"ZIP {z} isn't one of the {len(zips)} on the map.")
            return 1
        d = store.daily({z: 1}, a, b)
        print(f"ZIP {z}: daily high / low (°F) and rain (in), NOAA nClimGrid-Daily")
        for day, r in d.iterrows():
            f = lambda v, n: "  –  " if v != v else f"{v:.{n}f}"
            print(f"  {day.date()}  high {f(r['tmax'], 1)}  low {f(r['tmin'], 1)}  rain {f(r['prcp'], 2)}")
        return 0
    if args.action == "export":
        out = args.out or str(store.folder / f"weather-by-zip-{a}-to-{b}.csv")
        f = store.frame()
        f = f[(f["day"] >= str(a)) & (f["day"] <= str(b))].copy()
        f["tmax_f"] = (f["tmax"] * 9 / 5 + 32).round(1)
        f["tmin_f"] = (f["tmin"] * 9 / 5 + 32).round(1)
        f["prcp_in"] = (f["prcp"] / 25.4).round(3)
        f["day"] = f["day"].dt.date
        f[["zip", "day", "tmax_f", "tmin_f", "prcp_in"]].sort_values(["zip", "day"]).to_csv(out, index=False)
        print(f"Wrote {len(f)} rows to {out}")
        return 0
    return 2


def cmd_zipfacts(cfg, args):
    """the ZIP facts, by hand. Public Census and the state fire agency data only."""
    from . import zipfacts as Z
    store = Z.Store(cfg, cfg.get("pack_dir"))
    if args.action == "update":
        up = Z.Updater(cfg, store=store, version=HUB_VERSION)
        print("Reading the Census (ACS, Gazetteer) and the region's hazard zones for the map's ZIPs …", flush=True)
        st = up.run_once(say=lambda t: print("  " + t, flush=True), force=getattr(args, "force", False))
        s = Z.summary(store)
        print(f"ZIP facts: {s['zips']} ZIP(s) with Census figures ({s.get('acs') or 'no ACS yet'}); fire zones "
              f"{'on file' if s.get('fire') else 'not on file'}; {st.get('requests', 0)} request(s), "
              f"{round((st.get('bytes') or 0) / 1e6, 1)} MB" + (f"; error: {st['error']}" if st.get("error") else ""))
        return 0 if not st.get("error") else 1
    if args.action == "status":
        s = Z.summary(store)
        print(f"ZIP facts: {s['zips']} ZIP(s); {s.get('acs') or 'no ACS yet'}; Gazetteer {s.get('gazetteer') or '-'}; "
              f"fire zones {'yes' if s.get('fire') else 'no'}; built {s.get('built') or '-'}; last try "
              f"{s.get('last_try') or '-'}" + (f"; last error: {s['error']}" if s.get("error") else ""))
        print(f"  Folder: {store.folder}")
        return 0
    facts = store.zip_facts()
    if not facts:
        print("No ZIP facts on file yet: run  zorderz_hub zipfacts update")
        return 1
    if args.action == "show":
        z = args.zip or sorted(facts)[0]
        if z not in facts:
            print(f"ZIP {z} isn't one of the {len(facts)} on the map.")
            return 1
        print(f"ZIP {z}:")
        for k in Z.FACTS:
            v = facts[z].get(k)
            if v is not None:
                print(f"  {Z.label_of(k):34} {Z.fmt(v, Z.unit_of(k))}")
        return 0
    if args.action == "export":
        import csv
        out = args.out or str(store.folder / "zip-facts.csv")
        with open(out, "w", newline="") as fh:
            w = csv.writer(fh)
            w.writerow(["zip"] + list(Z.FACTS))
            for z in sorted(facts):
                w.writerow([z] + [facts[z].get(k) for k in Z.FACTS])
        print(f"Wrote {len(facts)} ZIPs to {out}")
        return 0
    return 2


def cmd_places(cfg, args):
    """Example City places by hand: the region's address points and parcels, fire history, sales tax areas,
    mosquito zones and the coast. Public data only; a run by hand reads every source due at once (the hub's
    own nightly run reads at most one big source a night)."""
    from . import places as PL
    st = PL.Store(cfg, cfg.get("pack_dir"))
    if args.action == "update":
        only = [x.strip() for x in (args.only or "").split(",") if x.strip()] or None
        bad = [x for x in only or [] if x not in PL.SOURCES]
        if bad:
            print(f"Unknown source(s): {', '.join(bad)}. Sources: {', '.join(PL.ORDER)}")
            return 2
        up = PL.Updater(cfg, store=st, version=HUB_VERSION)
        print("Reading the region's place sources that are due (public data only) …", flush=True)
        r = up.run_once(say=lambda t: print("  " + t, flush=True), force=args.force, only=only, by_hand=True)
        print(f"Places: read {', '.join(r.get('read') or []) or 'nothing new'}; checked "
              f"{', '.join(r.get('checked') or []) or '-'}; {r.get('requests', 0)} request(s), "
              f"{round((r.get('bytes') or 0) / 1e6, 1)} MB" + (f"; error: {r['error']}" if r.get("error") else ""))
        if r.get("read"):
            print("The next import places customers again (it starts by itself within a minute).")
        return 0 if not r.get("error") else 1
    if args.action == "status":
        s = PL.summary(st)
        for name in PL.ORDER:
            x = s[name]
            print(f"  {name:10} read {x['built'] or '-':19}  checked {x['checked'] or '-':19}  "
                  f"{(str(x['rows']) + ' rows') if x['rows'] is not None else ''}")
        print(f"  ZIP facts for {s['zips']} ZIP(s); last try {s['last_try'] or '-'}"
              + (f"; error: {s['error']}" if s["error"] else ""))
        try:
            meta = store.load(cfg["store"]).meta.get("places") or {}
            if meta:
                by = meta.get("placed_by") or {}
                print(f"  Customers placed at the last import: {meta.get('placed', 0)} of {meta.get('customers', 0)}"
                      + (" (" + ", ".join(f"{k} {v}" for k, v in by.items() if v) + ")" if any(by.values()) else ""))
        except Exception:
            pass
        print(f"  Folder: {st.folder}")
        return 0
    if args.action == "show":
        z = (args.zip or "").strip()[:5]
        facts = st.zip_facts()
        if z not in facts:
            print(f"No place facts for {z or '(no ZIP given)'} yet: zorderz_hub places update")
            return 1
        from . import zipfacts as Z
        for k, v in sorted(facts[z].items()):
            if k in Z.FACTS and v is not None:
                print(f"  {Z.label_of(k):34} {Z.fmt(v, Z.unit_of(k))}")
            elif v not in (None, "", []):
                print(f"  {k:34} {', '.join(v) if isinstance(v, list) else v}")
        return 0
    if args.action == "locate":
        idx = PL.load_index(cfg)
        if idx is None:
            print("No address points on the hub computer yet: zorderz_hub places update --only addresses")
            return 1
        try:
            hit = idx.locate(args.address or "", args.zip or "")
            if hit is None:
                print("Not found among the region's address points (check the number, street and ZIP).")
                return 1
            d = idx.describe([hit])[0]
            for k, v in d.items():
                print(f"  {k:16} {v if v is not None else '-'}")
        finally:
            idx.close()
        return 0
    return 2


def cmd_safety(cfg, args):
    """the safety sites and safety councils, by hand. Public the listing body and region data, plus our own
    totals (counts and dollars per territory) in the sales CSV; no customer is named."""
    from . import safety as F
    st = F.Store(cfg, cfg.get("pack_dir"))
    if args.action == "update":
        up = F.Updater(cfg, store=st, version=HUB_VERSION)
        print("Reading the region's safety sites, safety councils and address points …", flush=True)
        r = up.run_once(say=lambda t: print("  " + t, flush=True), force=getattr(args, "force", False))
        if r.get("skipped"):                                      # the hub's own update holds the lock
            print("The hub is updating them right now; `zorderz_hub safety status` shows when it's done.")
            return 1
        if r.get("unchanged"):                                    
            print(f"Nothing to do: {r.get('requests', 0)} request(s) to check. To rebuild anyway: "
                  "zorderz_hub safety update --force")
            return 0
        s = F.summary(st)
        print(f"Safety sites: {s['sites']} site(s) ({s['traced']} traced), {s['councils']} council(s), "
              f"{r.get('addresses', 0)} address point(s); {r.get('requests', 0)} request(s), "
              f"{round((r.get('bytes') or 0) / 1e6, 1)} MB" + (f"; error: {r['error']}" if r.get("error") else ""))
        for n in r.get("notes") or []:
            print("  note: " + n)
        print("The next import matches customers to them (it starts by itself within a minute).")
        return 0 if not r.get("error") else 1
    if args.action == "status":
        s = F.summary(st)
        print(f"Safety sites: {s['sites']} site(s) ({s['traced']} traced), {s['councils']} council(s); built "
              f"{s.get('built') or '-'}; last try {s.get('last_try') or '-'}" + (f"; error: {s['error']}" if s['error'] else ""))
        live = st.status()
        if live.get("running"):                                   # a run in progress, as it goes
            import datetime as _dt
            try:
                quiet = (_dt.datetime.now() - _dt.datetime.fromisoformat(str(live.get("at")))).total_seconds() / 60
            except ValueError:
                quiet = None
            print(f"  Running now (started {live.get('started')}, {live.get('requests', 0)} requests so far): "
                  f"{live.get('now')}" + (f"; last news {quiet:.0f} min ago" if quiet is not None else ""))
            if quiet is not None and quiet > 10:
                print("  No news for over 10 minutes: if no update is running on the hub computer, that run ended "
                      "without saying so (the old data is still in use).")
        elif live.get("unchanged"):
            print(f"  Last check {live.get('checked')}: nothing had changed, nothing was downloaded again")
        try:
            meta = store.load(cfg["store"]).meta.get("territories") or {}
            if meta:
                print(f"  Customers matched at the last import: {meta.get('in_safety_site', 0)} in a safety site, "
                      f"{meta.get('in_safety_council', 0)} in a council (of {meta.get('with_street', 0)} with a "
                      "street address)")
        except Exception:
            pass
        print(f"  Folder: {st.folder}")
        return 0
    t = st.territories()
    if not t.get("sites"):
        print("No safety areas on file yet: run  zorderz_hub safety update")
        return 1
    if args.action == "show":
        want = (args.name or "").casefold()
        for x in t["sites"] + t["councils"]:
            if want and want not in x["name"].casefold():
                continue
            sp = x.get("sponsor") or {}
            print(f"{x['name']}: {x.get('homes')} homes ({x.get('addresses')} address points)"
                  + (f"; {x.get('method')}, {x.get('confidence')} confidence" if x.get("method") else "")
                  + (f"; run by {sp.get('name')}" if sp.get("name") else "")
                  + (f"; council {x.get('council')}" if x.get("council") else ""))
        return 0
    if args.action == "export":
        out = Path(args.out) if args.out else st.folder / "safety-for-sales.csv"
        totals = {}
        try:
            data = store.load(cfg["store"])
            totals = F.territory_totals(data, load_pack(cfg["pack_dir"]))
        except Exception as e:
            print(f"(our totals left blank: {type(e).__name__})")
        rows = F.export_rows(st, totals)
        F.write_csv(out, rows, F.SITE_COLUMNS)
        print(f"Wrote {len(rows)} rows (sites and councils) to {out}")
        return 0
    if args.action == "addresses":
        out = Path(args.out) if args.out else st.folder / "safety-addresses.csv"
        cols = ["safety_sites", "safety_council", "num", "frac", "pdir", "name", "sfx", "postd", "unit", "zip",
                "apn", "community", "lon", "lat"]
        n = F.write_csv(out, st.iter_addresses(), cols)           # row by row: half a million points
        print(f"Wrote {n:,} region address points (public data) to {out}")
        return 0
    return 2


def cmd_feed_check(cfg, args):
    """compare the website's live invoice feed with the business's exports, the way the importer reads both.
    Prints counts and invoice numbers only."""
    from . import feed
    rep = feed.parity(cfg, cfg["store"])
    print(f"Charts hub {HUB_VERSION}: feed mode is \"{feed.get_mode(cfg['store'])}\"")
    print(feed.format_parity(rep))
    return 0 if rep["ready"] else 1


def cmd_feed_mode(cfg, args):
    """shadow (keep the feed, use nothing), live (write it beside the customer
    list so the importer reads it), off. "live" runs the check first; --force skips it."""
    from . import feed
    archived = args.archived
    if args.mode == "live":
        rep = feed.parity(cfg, cfg["store"])
        if not rep["ready"] and not args.force:
            print(feed.format_parity(rep))
            print("\nFeed mode unchanged. Fix the differences, wait for more invoices, or use --force.")
            return 1
        archived = archived or rep["archived"]
    feed.set_mode(cfg["store"], args.mode, archived)
    print(f"Feed mode: {args.mode}")
    if args.mode == "live":
        wrote = feed.write_live(cfg, cfg["store"])
        st = feed.status(cfg["store"])
        print(f"Wrote \"{feed.LIVE_CSV}\" ({st['invoices'] - st['deleted']} invoices; archived ones "
              f"{'included' if feed.include_archived(cfg['store']) else 'left out'}) beside your customer list"
              if wrote else "The live file was already up to date.")
        print("The running hub rebuilds its store within a minute; every new batch from the website does the same.")
    else:
        gone = feed.remove_live(feed.live_folder(cfg))
        print(f"The hub keeps receiving the feed but no longer uses it"
              + (f"; removed {', '.join(gone)} (the watcher rebuilds within a minute)." if gone else "."))
    return 0


def cmd_prospect(cfg, args):
    """describe the neighborhoods now (the hub also does it by itself on first use, in the background)."""
    from . import safety as fw_mod, places as pl_mod, prospect as pr_mod, zipfacts as zf_mod
    ps = pl_mod.Store(cfg, cfg.get("pack_dir"))
    fw = fw_mod.folder_of(cfg) if fw_mod.settings(cfg)["mode"] == "on" else None     # as the hub itself does
    zf = zf_mod.folder_of(cfg) if zf_mod.settings(cfg)["mode"] == "on" else None
    cells = pr_mod.Cells(ps.folder, fw, zf, lookup_factory=lambda: pl_mod.Lookup(ps))
    if not cells.available:
        print("No region address points on the hub computer yet: run `python3 -m zorderz_hub places update` first.")
        return 1
    if args.action == "status":
        d = cells.load()
        print(f"Neighborhoods: {len(d['cells']):,}, built {d.get('built')}" if d else "Neighborhoods: not built "
              "(or a source file changed since): run `python3 -m zorderz_hub prospect build`.")
        return 0
    d = cells.build(say=print)
    print(f"Neighborhoods: {len(d['cells']):,} written to {cells.path}")
    return 0


def cmd_keys(cfg, args):
    from . import envelope as E
    if args.action == "hub":
        # the hub's own key pair, on a new install only; the public half goes into the website's Charts settings
        try:
            public = E.write_secret()
        except FileExistsError as e:
            print(f"keys hub refused: {e}", file=sys.stderr)
            return 1
        print(f"Hub key made ({names.SECRET_FILE}, mode 0600). Save a copy of that file now.")
        print(f"The hub's public key, for the website's Charts settings: {public}")
        return 0
    if args.action == "hub-public":
        try:
            print(E.public_of(E.b64e(E.load_secret())))
        except (OSError, ValueError, E.EnvelopeError):
            print(f"No hub key ({names.SECRET_FILE}): run  python -m zorderz_hub keys hub  on a new install.",
                  file=sys.stderr)
            return 1
        return 0
    if args.action == "site":
        # the website's public key (its Charts settings page shows it), added to the keys this hub accepts
        key = (args.key or "").strip()
        try:
            if len(E.b64d(key)) != 32:
                raise ValueError
        except (ValueError, E.EnvelopeError):
            print("keys site needs the website's public key (43 characters, from its Charts settings).",
                  file=sys.stderr)
            return 2
        path = Path(cfg.get("_path") or names.HUB_CONFIG_FILE)
        raw = json.loads(path.read_text()) if path.exists() else {}
        keys = [key] + [k for k in (raw.get("web_public_keys") or []) if k != key]
        raw["web_public_keys"] = keys[:3]                  # the newest first; an older one stays while it rolls over
        path.write_text(json.dumps(raw, indent=2) + "\n")
        os.chmod(path, 0o600)
        print(f"The hub now accepts {len(raw['web_public_keys'])} website key(s); restart the hub service.")
        return 0
    if args.action == "status":
        from . import keyfiles
        rep = keyfiles.report(cfg)
        bad = 0
        for label, r in rep.items():
            extra = ""
            if label == "analytics_key":
                fp = r.get("fingerprint")
                extra = (f", fingerprint {fp}" if fp else "") + (
                    "" if not r.get("store") else (", matches the store" if r["state"] == "ok" else
                                                   f", the store was built with {r['store']}"))
                if r["state"] == "ok" and not r.get("store"):
                    extra += ", the store has no record yet (the next import records it)"
            near = r.get("near_misses") or []
            if near:
                extra += f"; found {', '.join(near)} beside it"
            bad += r["state"] not in ("ok", "not_used")
            print(f"{label}: {r['state']} ({r['source']}{extra})")
        return 1 if bad else 0
    # init: a brand-new install only
    meta = store.read_meta(cfg["store"])
    held = int((meta.get("row_counts") or {}).get("customers_clean") or 0)
    if Path(cfg["store"]).exists() and (held or not meta):
        print("keys init refused: the store already holds customers"
              + (f" ({held})" if held else "") + ". A new key would give every one of them a new code name. "
              f"Put the saved key back in {names.ANALYTICS_KEY_FILE} instead.", file=sys.stderr)
        return 1
    mod = importer.adapter(cfg["adapter"])
    try:
        fp = mod.init_analytics_key()
    except Exception as e:
        print(f"keys init refused: {e}", file=sys.stderr)
        return 1
    print(f"Analytics key made (fingerprint {fp}). Save a copy now: it can't be made again.")
    return 0


def main(argv=None):
    ap = argparse.ArgumentParser(prog="zorderz_hub", description=f"Charts hub {HUB_VERSION}")
    ap.add_argument("--config", default=None)
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("init")
    sub.add_parser("import")
    sub.add_parser("serve")
    ky = sub.add_parser("keys", help="the hub computer's keys: status (fingerprints only) | init (a brand-new "
                                     "install's first analytics key; refuses once there is data) | hub (the hub's "
                                     "own key pair, new installs only) | hub-public | site KEY (the website's key)")
    ky.add_argument("action", choices=["status", "init", "hub", "hub-public", "site"])
    ky.add_argument("key", nargs="?", default="", help="site: the website's public key")
    asg = sub.add_parser("assistant", help="the Assistant's runner agent: serve | status")
    asg.add_argument("action", choices=["serve", "status"])
    rg = sub.add_parser("region", help="the region pack: check | shapes FILE (the website map's ZIP outlines)")
    rg.add_argument("action", choices=["check", "shapes"])
    rg.add_argument("file", nargs="?", default="")
    p = sub.add_parser("prompts")
    p.add_argument("outdir")
    a = sub.add_parser("accounts", help="write a starting list of named accounts (on this machine only)")
    a.add_argument("--min-jobs", type=int, default=1)
    a.add_argument("--limit", type=int, default=0, help="0 = every business")
    a.add_argument("--clean", action="store_true",
                   help="remove the initials an older version added as nicknames from your list")
    ms = sub.add_parser("misses", help="write a short report of questions Charts couldn't answer well")
    ms.add_argument("--since", default="7d", help="how far back: 7d, 30d, or a number of days")
    pv = sub.add_parser("pivots", help="rank or show the invoice-card pivots")
    pv.add_argument("action", choices=["rank", "show"])
    pv.add_argument("--sample", type=int, default=40, help="real invoices to try each template on")
    bn = sub.add_parser("bench", help="time drills and invoice cards on the real store (timings only)")
    bn.add_argument("--n", type=int, default=40)
    lo = sub.add_parser("line-options", help="build the Estimate Creator's tap-to-change map")
    lo.add_argument("--out", default="", help="folder for line-options.json + report (default: beside the store)")
    sub.add_parser("feed-check", help="compare the website's live invoice feed with your exports")
    wr = sub.add_parser("weekly-review", help="write this week's review of what Charts struggled with")
    wr.add_argument("--days", type=int, default=7)
    sc = sub.add_parser("selfcheck", help="run the nightly self-check now (codes only, no figures)")
    sc.add_argument("--accept", action="store_true",
                    help="accept today's answers as the new baseline (after an intended change)")
    wx = sub.add_parser("weather", help="NOAA's daily weather by ZIP: update | status | show | export")
    wx.add_argument("action", choices=["update", "status", "show", "export", "rebuild"])
    wx.add_argument("--zip", default="", help="show: one ZIP code")
    wx.add_argument("--from", dest="date_from", default="", help="show/export: first day (YYYY-MM-DD)")
    wx.add_argument("--to", dest="date_to", default="", help="show/export: last day (YYYY-MM-DD)")
    wx.add_argument("--out", default="", help="export: the CSV file to write")
    wx.add_argument("--months", type=int, default=0, help="update: fetch at most this many months (0 = all)")
    fw = sub.add_parser("safety", help="safety sites and safety councils: update | status | show | "
                                         "export | addresses")
    fw.add_argument("action", choices=["update", "status", "show", "export", "addresses"])
    fw.add_argument("--name", default="", help="show: part of a site's or council's name")
    fw.add_argument("--out", default="", help="export / addresses: the CSV file to write")
    fw.add_argument("--force", action="store_true", help="update: rebuild even if nothing has changed")
    pl = sub.add_parser("places", help="the region pack's places (address points, parcels, fire history, sales tax, "
                                       "mosquitoes, coast): update | status | show | locate")
    pl.add_argument("action", choices=["update", "status", "show", "locate"])
    pl.add_argument("--force", action="store_true", help="update: read again even if nothing has changed")
    pl.add_argument("--only", default="", help="update: just these sources, e.g. tax,fires")
    pl.add_argument("--zip", default="", help="show / locate: a ZIP code")
    pl.add_argument("--address", default="", help="locate: a street address (stays on the hub computer)")
    pr = sub.add_parser("prospect", help="the prospecting neighborhoods: build (now) | status")
    pr.add_argument("action", choices=["build", "status"])
    zf = sub.add_parser("zipfacts", help="Census and hazard-zone facts by ZIP: update | status | show | export")
    zf.add_argument("action", choices=["update", "status", "show", "export"])
    zf.add_argument("--zip", default="", help="show: one ZIP code")
    zf.add_argument("--out", default="", help="export: the CSV file to write")
    zf.add_argument("--force", action="store_true", help="update: read every source again, changed or not")
    fm = sub.add_parser("feed-mode", help="shadow | live | off for the website's live invoice feed")
    fm.add_argument("mode", choices=["shadow", "live", "off"])
    fm.add_argument("--force", action="store_true", help="turn live on without a clean feed-check")
    fm.add_argument("--archived", choices=["include", "exclude"], default=None,
                    help="archived invoices in the live file (default: whatever your exports do)")
    args = ap.parse_args(argv)

    if args.cmd == "init":
        path = config.write_default(args.config)
        print(f"Config: {path}")
        return 0
    cfg = config.load(args.config)
    if args.cmd == "keys":
        return cmd_keys(cfg, args)
    if args.cmd == "import":
        try:
            meta = importer.run_import(cfg)
        except store.KeyChanged as e:            # refused, nothing written
            print(f"Import refused: {e}", file=sys.stderr)
            return 1
        except Exception as e:
            if type(e).__name__ != "AnalyticsKeyMissing":
                raise
            print(f"Import refused: {e}", file=sys.stderr)
            return 1
        print(f"Store rebuilt: data as of {meta['data_as_of']}; rows {meta['row_counts']}")
        m = meta.get("measured") or {}
        if m.get("from_invoice_lines") is not None:
            print(f"Invoice lines: {m['from_invoice_lines']} measured jobs counted from lines listed at $0.")
        if not m.get("export"):
            print("CRM export: none found in the import folder (measured units come from the invoice lines).")
        elif m.get("error"):
            print(f"CRM export: found but couldn't be read ({m['error']}); invoice lines used instead.")
        else:
            by = m.get("by", {})
            print(f"CRM export: {m.get('estimates', 0)} measured estimates; {m.get('matched', 0)} matched to an "
                  f"invoice (email {by.get('email', 0)}, phone {by.get('phone', 0)}, name+street "
                  f"{by.get('name_street', 0)}, name {by.get('name', 0)}); {m.get('no_customer', 0)} customer "
                  f"not found; {m.get('no_invoice', 0)} with no matching invoice yet.")
        ll = meta.get("lead_links") or {}
        if ll.get("client_lists"):
            print(f"FreshBooks client lists: {ll['client_lists']} file(s); contact details added for "
                  f"{ll.get('customers_with_client_contacts', 0)} customers the invoices knew only by name "
                  "(used on the hub computer for matching only, never stored).")
        if ll.get("leads_with_source"):
            print(f"CRM lead sources: {ll['leads_with_source']} leads with a source; {ll.get('matched', 0)} "
                  f"linked to an invoice ({ll.get('no_customer', 0)} customer not found, "
                  f"{ll.get('no_invoice', 0)} with no invoice within 6 months). Used where an invoice has "
                  "no source, once the website has sent the source names.")
        return 0
    if args.cmd == "assistant":
        # the plug-in point: an assistant module installed beside the hub (zorderz_hub.assistant) provides
        # serve(cfg) and status(cfg); without one, the hub answers the assistant ops "not installed"
        try:
            from . import assistant as assistant_mod
        except ImportError:
            print("No assistant module is installed beside this hub.", file=sys.stderr)
            return 1
        if args.action == "serve":
            return assistant_mod.serve(cfg)
        print(json.dumps({"hub": HUB_VERSION, **(assistant_mod.status(cfg) or {})}, indent=1))
        return 0
    if args.cmd == "region":
        return cmd_region(cfg, args)
    if args.cmd == "prompts":
        pack = load_pack(cfg["pack_dir"])
        out = Path(args.outdir)
        out.mkdir(parents=True, exist_ok=True)
        (out / "CHARTS_SYSTEM_PROMPT.txt").write_text(pack.spec_prompt())
        (out / "CHARTS_SUMMARY_PROMPT.txt").write_text(pack.caption_prompt())
        (out / "chart_spec.schema.json").write_text(json.dumps(pack.schema(), indent=2, ensure_ascii=False) + "\n")
        print(f"Wrote prompts and schema to {out}")
        return 0
    if args.cmd == "accounts":
        return cmd_accounts(cfg, args)
    if args.cmd == "misses":
        return cmd_misses(cfg, args.since)
    if args.cmd == "pivots":
        return cmd_pivots(cfg, args)
    if args.cmd == "bench":
        return cmd_bench(cfg, args)
    if args.cmd == "weekly-review":
        from . import weekly
        path, summary = weekly.write(cfg, HUB_VERSION, days=max(1, min(90, args.days)))
        print(f"Weekly review: {summary['words']} unplaced word(s), {summary['held']} held field(s), "
              f"{summary['trouble']} question(s) Charts couldn't answer well.")
        print(f"Report: {path}")
        return 0
    if args.cmd == "line-options":
        return cmd_line_options(cfg, args)
    if args.cmd == "feed-check":
        return cmd_feed_check(cfg, args)
    if args.cmd == "feed-mode":
        return cmd_feed_mode(cfg, args)
    if args.cmd == "weather":
        return cmd_weather(cfg, args)
    if args.cmd == "zipfacts":
        return cmd_zipfacts(cfg, args)
    if args.cmd == "safety":
        return cmd_safety(cfg, args)
    if args.cmd == "places":
        return cmd_places(cfg, args)
    if args.cmd == "prospect":
        return cmd_prospect(cfg, args)
    if args.cmd in ("serve", "selfcheck"):
        from .llm import LMStudio
        from .ops import Hub
        from .selfcheck import SelfCheck
        from .server import serve
        pack = load_pack(cfg["pack_dir"])
        lm = LMStudio(cfg["lmstudio"], config.lmstudio_token(cfg))
        mod = importer.adapter(cfg["adapter"])
        from . import accounts as acct
        loader = (lambda: acct.load(acct.config_path(), mod.analytics_key())) \
            if hasattr(mod, "analytics_key") else None
        hub = Hub(cfg, pack, lm, Audit(cfg["audit_log"]), accounts_loader=loader)
        hub.selfcheck = SelfCheck(hub)
        if args.cmd == "selfcheck":
            r = hub.selfcheck.run(accept=args.accept)
            if args.accept:
                print(f"Baseline: {r['facts'].get('accepted', 0)} answers accepted"
                      + ("" if r["facts"].get("accepted") else " (not a clean run: see the problems below)"))
            print(f"Self-check {'OK' if r['ok'] else 'needs attention: ' + ', '.join(r['problems'])} "
                  f"({r['ms']} ms; {r['facts']})")
            if not r["ok"]:
                print(hub.selfcheck.attention(r))
            return 0 if r["ok"] else 1
        hub.selfcheck.start()                    # 3 minutes after start, then every night at 02:17
        fp = hub.data.meta.get("fingerprint", "") if hub.data else ""
        hub.watcher = importer.Watcher(cfg, fp, hub.reload, cfg.get("watch_seconds", 60))
        hub.watcher.start()
        if hub.weather is not None:              # 03:40 nightly; off-peak hours only
            from . import weather as weather_mod
            hub.weather_updater = weather_mod.Updater(cfg, store=hub.weather, version=HUB_VERSION)
            hub.weather_updater.start()
        if hub.zipfacts is not None:             # checked 04:20; off-peak only
            from . import zipfacts as zipfacts_mod
            hub.zipfacts_updater = zipfacts_mod.Updater(cfg, store=hub.zipfacts, version=HUB_VERSION)
            hub.zipfacts_updater.start()
        if hub.safety is not None:             # checked 04:50; off-peak only
            from . import safety as safety_mod
            hub.safety_updater = safety_mod.Updater(cfg, store=hub.safety, version=HUB_VERSION)
            hub.safety_updater.start()
        if hub.places is not None:               # 01:10 nightly when due; off-peak only; one big read a night
            from . import places as places_mod
            hub.places_updater = places_mod.Updater(cfg, store=hub.places, version=HUB_VERSION)
            hub.places_updater.start()
        httpd = serve(cfg, hub)
        print(f"Charts hub {HUB_VERSION} on {cfg['listen']}:{cfg['port']} (sealed only)", flush=True)
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            pass
        return 0
    return 2


if __name__ == "__main__":
    sys.exit(main())

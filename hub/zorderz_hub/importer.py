"""The import: source folder → adapter → clean store.

An admin drops the invoicing exports and PDFs into the import folder. The watcher notices a changed fingerprint,
rebuilds the store atomically, clears the spec cache and moves data_as_of forward. Idempotent: the adapter
counts each invoice number once and the newest file wins.
"""
import importlib
import multiprocessing
import os
import queue
import sys
import threading
import time
import traceback

from . import store

# the rebuild ran on a thread inside the hub, and its ~110 s of Python held the
# interpreter, so answers asked meanwhile took 14-25 s instead of under 2. It now runs in its own process,
# at a lower priority; the hub keeps answering from the old store and swaps when the new one is saved.
CHILD_NICE = 10
CHILD_TIMEOUT = 20 * 60          # a rebuild has never taken more than ~3 min


class ImportFailed(Exception):
    pass


POLL = 1.0                       # how often the hub checks that the rebuild is still alive


def _orphan_guard(parent):
    """if the hub dies mid-rebuild (a crash, launchd restarting it), the rebuild stops too instead of
    running on beside the new hub."""
    while True:
        time.sleep(2)
        if os.getppid() != parent:
            os._exit(3)


def _child(target, cfg, q, parent=None):
    try:
        if parent:
            threading.Thread(target=_orphan_guard, args=(parent,), daemon=True).start()
        try:
            os.nice(CHILD_NICE)
        except (OSError, AttributeError):
            pass
        mod, fn = target.split(":")
        q.put(("ok", getattr(importlib.import_module(mod), fn)(cfg)))
    except BaseException as e:      # the parent only needs to know it failed, and how
        traceback.print_exc()        # and the hub's error log keeps the whole story (no data in it)
        q.put(("error", f"{type(e).__name__}: {str(e)[:160]}"))


def run_isolated(target, cfg, timeout=CHILD_TIMEOUT):
    """Run `module:function(cfg)` in a fresh process and return its result. Raises ImportFailed when it
    fails or runs past `timeout`; any other exception means no process could be started."""
    ctx = multiprocessing.get_context("spawn")
    q = ctx.Queue()
    p = ctx.Process(target=_child, args=(target, cfg, q, os.getpid()), name="charts-import", daemon=True)
    p.start()
    deadline = time.monotonic() + timeout
    while True:
        # wait in short slices, so a rebuild that dies without a word (out of memory, killed) is noticed
        # at once rather than after the full timeout
        try:
            status, value = q.get(timeout=POLL)
            break
        except queue.Empty:
            pass
        if not p.is_alive():
            try:
                status, value = q.get(timeout=POLL)      # it may have answered just before it ended
                break
            except queue.Empty:
                p.join(1)
                raise ImportFailed(f"ended (exit {p.exitcode})") from None
        if time.monotonic() > deadline:
            p.kill()
            p.join(5)
            raise ImportFailed("timeout")
    p.join(30)
    if status != "ok":
        raise ImportFailed(value)
    return value


def run_import_isolated(cfg, on_done=None):
    """run_import in its own process, then on_done in this one. Falls back to this process only when
    a new one can't be started at all."""
    try:
        meta = run_isolated("zorderz_hub.importer:run_import", cfg)
    except ImportFailed:
        raise
    except Exception:
        return run_import(cfg, on_done)
    if on_done:
        on_done(meta)
    return meta


def adapter(module_name):
    return importlib.import_module(module_name)


def territory_stamp(cfg):
    """"|fw:<when the safety-site territories were written>", or "" when there are none. Part of the import's
    fingerprint, so new territories re-run the import (customers are matched to them only there). plus
    "|pl:<the place sources' read dates>" (customers are placed only there too)."""
    out = ""
    try:
        from . import safety
        if safety.settings(cfg)["mode"] == "on":
            st = safety.Store(cfg).stamp()
            out += f"|fw:{st}" if st else ""
    except Exception:
        pass
    try:
        from . import places
        if places.settings(cfg)["mode"] == "on":
            ps = places.Store(cfg).stamp()
            out += f"|pl:{ps}" if ps else ""
    except Exception:
        pass
    return out


def _territory_kwargs(cfg, mod):
    """The indexes an adapter's build() can take: territory_index, place_index. Neither
    ever fails an import."""
    import inspect
    out = {}
    try:
        params = inspect.signature(mod.build).parameters
    except (TypeError, ValueError):
        return out
    if "territory_index" in params:
        try:
            from . import safety
            if safety.settings(cfg)["mode"] == "on":
                idx = safety.load_index(cfg)
                if idx is not None:
                    out["territory_index"] = idx
        except Exception:
            pass
    if "place_index" in params:
        try:
            from . import places
            pidx = places.load_index(cfg)
            if pidx is not None:
                out["place_index"] = pidx
        except Exception:
            pass
    return out


def key_fingerprint(key):
    """the first 12 hex of the key's SHA-256 (as customers.key_fingerprint)."""
    import hashlib
    raw = key if isinstance(key, (bytes, bytearray)) else str(key).encode()
    return hashlib.sha256(bytes(raw)).hexdigest()[:12]


def _takes(fn, name):
    import inspect
    try:
        return name in inspect.signature(fn).parameters
    except (TypeError, ValueError):
        return False


FIRST_FP_MIN_KEPT = 0.5
FIRST_FP_MIN_HOUSEHOLDS = 20


def _key_for_import(cfg, mod):
    """the analytics key this import will use, and its fingerprint.
    Raises (and nothing is written) when there is no key, or when it is not the key the store was built with."""
    if not hasattr(mod, "analytics_key"):
        return None, None
    key = mod.analytics_key()                     # AnalyticsKeyMissing: never made here
    fp = key_fingerprint(key)
    was = store.read_meta(cfg["store"]).get("analytics_key_fp")
    if was and was != fp:
        _record_refusal(cfg, fp, "fingerprint")
        raise store.KeyChanged(
            f"The analytics key changed (the store was built with key {was}; this one is {fp}). Nothing was "
            "imported, and the store is unchanged. Put the original key back in ~/.config/zorderz-hub/analytics_key.")
    return key, fp


def refused_path(cfg):
    """the last key refusal, beside the store: {"fingerprint", "at", "why"}. Never a key."""
    from pathlib import Path
    return Path(cfg["store"]).with_name("analytics-key-refused.json")


def _record_refusal(cfg, fp, why):
    import json
    try:
        p = refused_path(cfg)
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(json.dumps({"fingerprint": fp, "at": time.strftime("%Y-%m-%dT%H:%M:%S"), "why": why}))
    except OSError:
        pass


def _clear_refusal(cfg):
    try:
        refused_path(cfg).unlink()
    except OSError:
        pass


def _check_first_fingerprint(cfg, tables, fp):
    if store.read_meta(cfg["store"]).get("analytics_key_fp"):
        return
    old = store.household_keys(cfg["store"])
    frame = tables.get("customers_clean")
    if len(old) < FIRST_FP_MIN_HOUSEHOLDS or frame is None or "household_key" not in frame:
        return
    new = {str(k) for k in frame["household_key"] if k is not None}
    kept = len(old & new) / len(old)
    if kept < FIRST_FP_MIN_KEPT:
        _record_refusal(cfg, fp, "households")
        raise store.KeyChanged(
            f"The analytics key looks changed: only {kept:.0%} of the store's customers keep their code name "
            f"under key {fp}. Nothing was imported, and the store is unchanged. Put the original key back in "
            "~/.config/zorderz-hub/analytics_key.")


def run_import(cfg, on_done=None):
    mod = adapter(cfg["adapter"])
    with store.build_lock(cfg["store"]):     # one rebuild at a time, whichever process starts it
        started = time.time()
        key, key_fp = _key_for_import(cfg, mod)
        stamp = territory_stamp(cfg)
        kwargs = _territory_kwargs(cfg, mod)
        if key is not None and _takes(mod.build, "key"):
            kwargs["key"] = key                   # the key that was checked is the key that is used
        if _takes(mod.build, "pack_dir"):
            kwargs["pack_dir"] = cfg.get("pack_dir")
        tables, meta = mod.build(cfg["import_dir"], cfg.get("customer_list"), **kwargs)
        if key_fp:
            _check_first_fingerprint(cfg, tables, key_fp)
            meta["analytics_key_fp"] = key_fp
        meta["fingerprint"] = str(meta.get("fingerprint") or "") + stamp
        meta["import_seconds"] = round(time.time() - started, 1)
        meta = store.save(cfg["store"], tables, meta)
        if key_fp:
            _clear_refusal(cfg)
    if on_done:
        on_done(meta)
    return meta


class Watcher(threading.Thread):
    daemon = True

    def __init__(self, cfg, current_fingerprint, on_done, every=60):
        super().__init__(name="import-watcher")
        self.cfg, self.fp, self.on_done, self.every = cfg, current_fingerprint, on_done, every
        self.last_error = ""
        self.key_hold = None             # the key's (state, fingerprint) when an import was refused for it

    def _key_state(self, mod):
        """(state, fingerprint) of the analytics key now, to hold rebuilds after a key refusal."""
        try:
            st = mod.key_status() if hasattr(mod, "key_status") else {}
            return (st.get("state"), st.get("fingerprint"))
        except Exception:
            return ("error", None)

    def run(self):
        mod = adapter(self.cfg["adapter"])
        while True:
            time.sleep(self.every)
            self.tick(mod)

    def tick(self, mod):
        """One look at the sources."""
        try:
            fp = mod.source_fingerprint(self.cfg["import_dir"], self.cfg.get("customer_list")) + \
                territory_stamp(self.cfg)
            if fp != self.fp and self.key_hold is not None and self.key_hold == self._key_state(mod):
                return "held"             # refused for the key; not again until the key itself changes
            self.key_hold = None
            if fp != self.fp:
                _say(f"import: rebuilding ({_what_changed(self.fp, fp)})")
                started = time.time()
                run = run_import_isolated if self.cfg.get("import_isolated", True) else run_import
                meta = run(self.cfg, self.on_done)
                self.fp = meta.get("fingerprint", fp)
                self.last_error = ""
                _say(f"import: done in {time.time() - started:.0f} s")
                return "rebuilt"
            return "same"
        except Exception as exc:          # keep serving the old store
            msg = f"{type(exc).__name__}: {str(exc)[:200]}"
            if any(w in msg for w in ("KeyChanged", "AnalyticsKeyMissing", "analytics key")):
                self.key_hold = self._key_state(mod)      # wait for the key to change, not the clock
                msg += " (held until the analytics key changes)"
            if msg != self.last_error:
                _say(f"import: failed ({msg}); the old data stays in use, and it is tried again "
                     + ("when the key changes" if self.key_hold is not None else "each minute"))
                traceback.print_exc()
            self.last_error = msg
            return "failed"


def _say(text):
    print(f"{time.strftime('%Y-%m-%d %H:%M:%S')} {text}", file=sys.stderr, flush=True)


def _what_changed(old, new):
    """Which part of the fingerprint moved: the files, the safety-site areas, the places (or matching rules)."""
    def parts(fp):
        out = {"files": fp.split("|fw:")[0].split("|pl:")[0]}
        for tag in ("fw", "pl"):
            if f"|{tag}:" in fp:
                out[tag] = fp.split(f"|{tag}:")[1].split("|")[0]
        return out
    a, b = parts(old or ""), parts(new or "")
    names = {"files": "the import files", "fw": "the safety areas", "pl": "the places data or matching rules"}
    moved = [names[k] for k in names if a.get(k) != b.get(k)]
    return ", ".join(moved) or "first run"

"""Hub configuration: one JSON file in the business's 0700 config folder.

Holds paths, the web side's PUBLIC keys and LM Studio settings. No secret
lives in it: the hub's secret key is in its own 0600 file (envelope.py),
the LM Studio token in an env var or a 0600 token file, and the analytics
HMAC key where the adapter keeps it.
"""
import json
import os
import stat
from pathlib import Path

from . import names

DEFAULTS = {
    "listen": "127.0.0.1",
    "port": 8787,
    "pack_dir": str(names.PACK_DIR),
    "region_dir": str(names.REGION_DIR),
    "store": str(names.HUB_HOME / "store" / "charts.sqlite"),
    "audit_log": str(names.HUB_HOME / "logs" / "audit.jsonl"),
    "import_dir": str(names.DEFAULT_IMPORT_DIR),
    "customer_list": "",
    "adapter": names.SOURCE_ADAPTER,
    "watch_seconds": 60,
    "web_public_keys": [],
    "lmstudio": {
        "url": "http://127.0.0.1:1234",
        "token_file": str(names.CONFIG_DIR / "lmstudio.token"),
        # the local model server's names for the models (set these to the models loaded there)
        "spec_model": "",
        "caption_model": "",
        "backup_model": "",
        "embed_model": "",
        "timeout": 25,
        "backup_after_seconds": 20,
        "queue": 5,
    },
    "limits": {"body_bytes": 65536, "ask_per_minute_per_user": 30, "search_limit": 50},
    "reader_mode": "on",
    # "template" = captions written from the chart's facts by rule; "model" = the caption
    # model words the same facts, checked, with the template as the fallback.
    "caption_mode": "template",
    # keep EVERY question in the misses log until this day (YYYY-MM-DD), to see how the question reader
    # does. "" keeps only the questions it could not fully read.
    "log_all_until": "",
    # the spec-writer model takes a second look at each reading and
    # may think briefly. It changes how a chart looks on its own; what is counted only when the
    # rules couldn't place every word (otherwise it is logged as a suggestion). "off" = no look.
    "review_mode": "on",
    "review_seconds": 10,
    # "schema" = LM Studio is given the reply's shape; "useful" = the look runs only where it
    # can still change the chart ("all" = every question, as before); review_max_tokens 0 = 450.
    "review_format": "schema",
    "review_scope": "useful",
    "review_no_think": False,
    "review_max_tokens": 0,
    # NOAA's daily weather by ZIP (weather.py). mode "off" = no download, no answers; fetch "auto" tries
    # Python, then the system curl on a certificate error; first_month is the oldest kept.
    "weather": {"mode": "on", "fetch": "auto", "first_month": "2012-01", "at": "03:40"},
    # what each ZIP is like (zipfacts.py): Census ACS 5-year, the Census Gazetteer and, when the region pack
    # has a hazard layer, its hazard zones; read again every `every_days`. fire "off" skips the hazard layer.
    "zipfacts": {"mode": "on", "fetch": "auto", "fire": "on", "at": "04:20", "every_days": 30},
    # community safety sites and councils, when the region pack lists them, with the region's address points
    # in each; customers are matched to them by the import, on the hub computer (safety.py).
    "safety": {"mode": "on", "fetch": "auto", "councils": "on", "at": "04:50", "every_days": 30},
    # background downloads only in these hours ("any" for no rule); a contact (an e-mail or web address) added
    # to the User-Agent if an admin wants one (polite.py). Runs started by hand go at once.
    "downloads": {"off_peak": "22:00-06:00", "contact": ""},
    # the region pack's layers (places.py): address points, parcels, fire history, tax areas, vector zones,
    # the coastline. Checked nightly at `at` when due.
    "places": {"mode": "on", "fetch": "auto", "at": "01:10"},
    # survey recordings (survey.py): site is the website's own address (the only host a recording is fetched
    # from); empty = surveys refused until it is set. The transcription model runs on the hub computer.
    "survey": {"site": ""},
    # the assistant engine beside the hub (zorderz_hub.assistant): "on" with site set (the website's own address)
    # answers the website's Ai Workspace; month_cap_usd (USD, or null for none) is the engine's own monthly line on
    # top of the website's budget; instructions_file is the business's own instructions (plain text), if any.
    "assistant": {"mode": "off", "site": "", "max_jobs": 8, "parallel": 2, "keep_hours": 24, "month_cap_usd": None,
                  "instructions_file": ""},
    # the self-check reports the hub computer's key files (missing, changed, near-miss names). Only the test
    # suites turn it off, so that no test reads a real key folder.
    "check_keys": True,
}


def load(path=None):
    path = Path(path or os.environ.get("ZORDERZ_HUB_CONFIG") or names.HUB_CONFIG_FILE)
    cfg = json.loads(json.dumps(DEFAULTS))
    if path.exists():
        user = json.loads(path.read_text())
        for k, v in user.items():
            if isinstance(v, dict) and isinstance(cfg.get(k), dict):
                cfg[k].update(v)
            else:
                cfg[k] = v
    cfg["_path"] = str(path)
    return cfg


def private_file_ok(path):
    """True if a secret file exists and only its owner can read it."""
    p = Path(path)
    if not p.exists():
        return False
    return not (p.stat().st_mode & (stat.S_IRWXG | stat.S_IRWXO))


def lmstudio_token(cfg):
    env = os.environ.get(names.LMSTUDIO_TOKEN_ENV, "").strip()
    if env:
        return env
    tf = cfg["lmstudio"].get("token_file")
    if tf and private_file_ok(tf):
        return Path(tf).read_text().strip()
    return ""


def write_default(path=None):
    path = Path(path or names.HUB_CONFIG_FILE)
    path.parent.mkdir(parents=True, exist_ok=True)
    os.chmod(path.parent, 0o700)
    if not path.exists():
        path.write_text(json.dumps({k: v for k, v in DEFAULTS.items()}, indent=2) + "\n")
        os.chmod(path, 0o600)
    return path

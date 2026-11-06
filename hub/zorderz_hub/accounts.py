"""Named accounts: business customers an admin chose to see by name.

Zero-trust shape:

  * The names live in ONE private file on the hub machine
    (~/.config/zorderz-hub/charts-accounts.yml, mode 0600), written and
    reviewed by an admin. Never in the store, a release zip, a log or a prompt.
  * The store holds only account_key = HMAC(analytics key, normalized name).
  * Questions: every listed name or nickname is swapped for ACCOUNT_1,
    ACCOUNT_2, ... BEFORE any model reads the question (spec writer and caption
    writer alike). The model writes specs and captions with placeholders; the
    hub maps them back to keys.
  * Labels are put back only in the reply to a caller whose access block says
    accounts: true. Everyone else, the chat and other apps included, sees
    "Named account" or nothing.

This module holds no business data. The file format:

    accounts:
      - name: Example Property Management      # as written in the invoicing system
        nicknames: [Example]                   # optional: only what people really type
        other_spellings: [Example Prop Mgmt]    # other ways it's written there (optional)
"""
import hashlib
import hmac
import os
import re
from dataclasses import dataclass, field
from pathlib import Path

import yaml

PLACEHOLDER = re.compile(r"\bACCOUNT_([1-9])\b")
NAMED = "Named account"
OTHER = "All other customers"
KEY_PREFIX = "ac_"


def norm(text):
    """Company-name form used for matching: case, punctuation, '&' and the
    usual suffixes don't matter."""
    t = str(text or "").casefold().replace("&", " and ")
    for a, b in [("mgmt", "management"), ("mgt", "management"), ("co", "company"),
                 ("prop", "property"), ("properties", "property"), ("svcs", "services"),
                 ("svc", "service"), ("assn", "association"), ("assoc", "association"),
                 ("hoa", "homeowners association")]:
        t = re.sub(rf"\b{a}\b\.?", b, t)
    t = re.sub(r"\b(?:inc|llc|the|corp|corporation|ltd|lp)\b\.?", " ", t)
    return re.sub(r"[^a-z0-9]", "", t)


def key_for(normalized, secret):
    if isinstance(secret, str):
        secret = secret.encode()
    return KEY_PREFIX + hmac.new(secret, ("acct:" + normalized).encode(),
                                 hashlib.sha256).hexdigest()[:16]


def initials(name):
    words = [w for w in re.split(r"[^A-Za-z0-9]+", str(name))
             if w and w.casefold() not in {"the", "of", "and", "inc", "llc", "co", "corp"}]
    return "".join(w[0] for w in words).upper() if len(words) >= 2 else ""


# Words an acronym must never be read from (they are ordinary words or chart
# vocabulary, not a business).
NOT_ACRONYMS = {
    "the", "and", "for", "all", "any", "are", "per", "top", "new", "old", "sum", "avg",
    "ytd", "qtd", "mtd", "yoy", "mom", "vs", "who", "how", "why", "not", "but", "was",
    "has", "had", "our", "out", "own", "one", "two", "six", "ten", "jan", "feb", "mar",
    "apr", "may", "jun", "jul", "aug", "sep", "sept", "oct", "nov", "dec", "can", "may",
    "use", "get", "set", "job", "jobs", "hoa", "llc", "inc", "usa", "diy", "tbd", "etc",
    "asap", "fyi", "pdf", "csv", "sms", "yes", "yet", "via", "off", "led", "pie", "bar",
    "map", "row", "col", "net", "tax", "fee", "qty", "pcs", "sqft", "each", "most",
    "best", "last", "next", "past", "year", "week", "days", "show", "list", "make",
}


# words a nickname may never stand for, whatever the list says. "vs" was
# read as a customer's initials ("Google Ads leads vs existing customers" became
# a question about that customer), so ordinary words and chart vocabulary are
# never treated as an account, and short nicknames match only as typed ("VS",
# not "vs"). Four letters and up (ABCD) still match in any case.
NEVER_NICKNAMES = NOT_ACRONYMS | {
    "vs", "v", "ga", "ad", "ads", "or", "us", "we", "me", "my", "it", "in", "on", "at", "to", "by",
    "of", "is", "as", "an", "am", "pm", "up", "no", "so", "do", "go", "ok", "tv", "ac",
    "ytd", "all", "total", "sales", "revenue", "leads", "lead", "customers", "customer",
    "existing", "repeat", "google", "online", "referral", "yelp", "units", "items",
}
SHORT_NICKNAME = 3          # this long or shorter: case-sensitive, whole word


def usable_word(word, full_name):
    """May this name/nickname be matched in a question?"""
    w = str(word).strip()
    if len(w) < 2:
        return False
    if w == full_name:
        return True
    return w.casefold() not in NEVER_NICKNAMES


def acronym(name):
    """The acronym a person might type for a listed name, or '' when it would
    be too short or an ordinary word."""
    a = initials(name)
    return a if len(a) >= 3 and a.casefold() not in NOT_ACRONYMS else ""


@dataclass
class Account:
    key: str
    label: str
    words: list = field(default_factory=list)  # name + the business's nicknames, as typed


@dataclass
class Directory:
    accounts: list = field(default_factory=list)
    source: str = ""

    def __bool__(self):
        return bool(self.accounts)

    @property
    def by_key(self):
        return {a.key: a for a in self.accounts}

    # ------------------------------------------------------------ questions
    def mask(self, text, mapping=None):
        """Replace every listed name/nickname with ACCOUNT_n. Returns
        (masked_text, mapping {"ACCOUNT_n": key}). Longest words first, so
        'Example Hills Property Management' wins over 'Example Hills'."""
        mapping = dict(mapping or {})
        back = {k: p for p, k in mapping.items()}
        pairs = sorted(((w, a) for a in self.accounts for w in a.words if usable_word(w, a.label)),
                       key=lambda x: -len(x[0]))
        out = str(text or "")
        for word, acct in pairs:
            flags = 0 if (len(word) <= SHORT_NICKNAME and " " not in word) else re.I
            pat = re.compile(r"(?<![\w])" + re.escape(word) + r"(?:['’]s)?(?![\w])", flags)
            if not pat.search(out):
                continue
            ph = back.get(acct.key)
            if ph is None:
                if len(mapping) >= 9:
                    continue
                ph = f"ACCOUNT_{len(mapping) + 1}"
                mapping[ph], back[acct.key] = acct.key, ph
            out = pat.sub(ph, out)
        return out, mapping

    def mask_spec(self, spec, mapping=None):
        """A previous spec going back to the model: keys and labels → placeholders."""
        import copy
        spec = copy.deepcopy(spec)
        mapping = dict(mapping or {})
        back = {k: p for p, k in mapping.items()}
        keys = (spec.get("filters") or {}).get("accounts") or []
        out = []
        for k in keys:
            if k not in back and k in self.by_key and len(mapping) < 9:
                ph = f"ACCOUNT_{len(mapping) + 1}"
                mapping[ph], back[k] = k, ph
            if k in back:
                out.append(back[k])
        if spec.get("filters") is not None:
            spec["filters"]["accounts"] = out
        for f in ("title", "clarify_question"):
            if isinstance(spec.get(f), str):
                spec[f], mapping = self.mask(spec[f], mapping)
        if isinstance(spec.get("assumptions"), list):
            masked = []
            for a in spec["assumptions"]:
                t, mapping = self.mask(a, mapping)
                masked.append(t)
            spec["assumptions"] = masked
        return spec, mapping

    def unmask(self, text, mapping, show_names):
        """Placeholders → labels (or a neutral word) in text going to a person."""
        def sub(m):
            key = mapping.get(m.group(0))
            acct = self.by_key.get(key)
            if acct is None:
                return "an account"
            return acct.label if show_names else NAMED
        return PLACEHOLDER.sub(sub, str(text or ""))


def config_path(config_dir=None):
    """The business's private list. ZORDERZ_HUB_ACCOUNTS_FILE overrides (tests)."""
    env = os.environ.get("ZORDERZ_HUB_ACCOUNTS_FILE")
    if env:
        return Path(env)
    if config_dir is None:
        from . import names
        config_dir = names.CONFIG_DIR
    return Path(config_dir) / "charts-accounts.yml"


def load(path, secret):
    """Read the business's private accounts file. Missing file = no accounts."""
    path = Path(path)
    if not path.exists():
        return Directory()
    try:
        if os.name == "posix" and (path.stat().st_mode & 0o077):
            os.chmod(path, 0o600)  # private to an admin, whatever the editor did
    except OSError:
        pass
    raw = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
    accounts, seen = [], set()
    for item in raw.get("accounts") or []:
        if not isinstance(item, dict) or item.get("include") is False:
            continue
        name = str(item.get("name") or "").strip()
        if not name or not norm(name):
            continue
        key = key_for(norm(name), secret)
        if key in seen:
            continue
        seen.add(key)
        words = [name] + [str(n).strip() for n in (item.get("nicknames") or []) if str(n).strip()]
        words = [w for w in dict.fromkeys(words) if len(w) >= 2 and len(w) <= 80]
        accounts.append(Account(key=key, label=name[:80], words=words))
    # An acronym is only used when exactly one listed business has it and no
    # listed name or nickname already spells it.
    typed = {w.casefold() for a in accounts for w in a.words}
    by_acr = {}
    for a in accounts:
        acr = acronym(a.label)
        if acr:
            by_acr.setdefault(acr.casefold(), []).append(a)
    for acr, owners in by_acr.items():
        if len(owners) == 1 and acr not in typed:
            owners[0].words.append(acr.upper())
    return Directory(accounts=accounts, source=str(path))


def matchers(path, secret):
    """For the import: {normalized spelling: account_key} for every listed
    account, including other spellings used in the invoicing system."""
    path = Path(path)
    if not path.exists():
        return {}
    raw = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
    out = {}
    for item in raw.get("accounts") or []:
        if not isinstance(item, dict) or item.get("include") is False:
            continue
        name = str(item.get("name") or "").strip()
        if not norm(name):
            continue
        key = key_for(norm(name), secret)
        for spelling in [name] + list(item.get("other_spellings") or []):
            if norm(spelling):
                out[norm(spelling)] = key
    return out

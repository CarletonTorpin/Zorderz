"""Survey drives read on this computer, for the Route Planner.

Transcription runs on the hub computer. A spoken "scratch that" / "delete that" removes as much as the driver
meant (a word, a clause, the last sentence or two): the local model decides from the full context.

Two kinds of job, both started by `survey_submit` and read back by `survey_result` (paged):

  transcribe   {audio: {url, mime, bytes}}. The worker downloads the recording from the website -- only from the
               fetcher for anyone else -- converts it with ffmpeg to 16 kHz mono, transcribes it with Whisper
               (mlx-whisper, word times) and cuts it into sentences: [{i, t, e, text}] in seconds from the start.
               ffprobe's creation_time of the file (a Voice Memos file carries one) comes back too. The recording is
               deleted the moment it is read (or fails).
  read         {of: <a transcribe job>, streets: [[i, street, zip], ...], vocab: [label, ...]}. The sentences go to
               the local model a window at a time, with the street each was said on and
               the words the survey listens for, and come back as notes, what a "scratch that" took out (the model
               decides how much: a word, a clause, the last sentence or two), and what was a command or chatter.
               Every word the model keeps must come from the driver's own sentences; a window the model fails is
               read by rules (rules_read) instead, and the result says which.

Jobs belong to the website user that started them (`user`); another user's job is "not found". A job and its
result live in HUB_HOME/survey (0700) and are deleted after `keep_hours` (24) or when the website says `forget`.
The transcript never leaves this computer except to the website that sent the recording.
"""
import json
import os
import re
import secrets
import shutil
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

from . import names

DEFAULTS = {"mode": "on", "site": "", "max_mb": 400, "keep_hours": 24,
            "whisper_model": "", "model": "", "window": 40,
            "read_seconds": 180, "download_seconds": 900, "max_minutes": 240, "per_user": 8}
FF_SAFE = ["-protocol_whitelist", "file", "-format_whitelist", "mov,mp4,m4a,3gp,3g2,mj2,matroska,webm,ogg,mp3,wav,aac,flac,caf"]
FILLER = {"a", "an", "the", "and", "is", "are", "has", "have", "of", "on", "with", "there", "it", "its", "this", "that",
          "here", "in", "at"}
FETCH_PATH = "/wp-json/zorderz/v1/route-planner/audio-fetch/"   # the website's route planner (ZCH_Survey_Verb)
PAGE = 150
MAX_SENTENCES = 4000
MAX_TEXT = 400
JOB_ID = re.compile(r"^[a-f0-9]{24}$")
USER = re.compile(r"^wp_\d{1,12}$")

COMMAND = re.compile(r"^(?:(?:stop|end|finish) (?:the )?(?:survey|drive|driving|recording)(?: survey)?|"
                     r"pause(?: notes| listening| recording)?|stop listening|mute|"
                     r"resume(?: notes| listening| recording)?|start listening|unmute|listen again|"
                     r"keep (?:that|it)|confirm(?: that| it)?|yes,? keep (?:it|that)|save that|testing(?: testing)?(?: one two(?: three)?)?)$")
RETRACT_ALONE = re.compile(r"^(?:scratch that|delete that|undo(?: that)?|cancel that|never ?mind|forget that|strike that|"
                           r"no,? scratch that|ignore that|erase that|disregard that)$")
RETRACT_INSIDE = re.compile(r"\b(?:scratch that|delete that|cancel that|strike that|never ?mind|no wait|wait no|"
                            r"i mean|correction|actually no|ignore that|erase that|disregard that)\b[\s,.;:!-]*", re.I)


def settings(cfg):
    s = dict(DEFAULTS)
    s.update(cfg.get("survey") or {})
    return s


def folder(cfg):
    p = Path(cfg.get("survey_dir") or (names.HUB_HOME / "survey"))
    p.mkdir(parents=True, exist_ok=True)
    try:
        os.chmod(p, 0o700)
    except OSError:
        pass
    return p


def tool(name):
    """ffmpeg / ffprobe: launchd's PATH is short, so look where Homebrew puts them too."""
    for c in (shutil.which(name), f"/opt/homebrew/bin/{name}", f"/usr/local/bin/{name}", f"/usr/bin/{name}"):
        if c and os.path.exists(c):
            return c
    return None


def whisper_ok():
    try:
        import importlib.util
        return importlib.util.find_spec("mlx_whisper") is not None
    except Exception:
        return False


def allowed_url(url, site):
    """Only the website's own fetch address, over https (or http on loopback, for tests)."""
    try:
        u = urllib.parse.urlsplit(str(url))
        s = urllib.parse.urlsplit(str(site))
    except ValueError:
        return False
    if not u.hostname or u.hostname.lower() != (s.hostname or "").lower():
        return False
    if u.scheme != "https" and not (u.scheme == "http" and u.hostname in {"127.0.0.1", "localhost"}):
        return False
    if (u.port or None) != (s.port or None) or u.username or u.password:
        return False
    path = urllib.parse.unquote(u.path)
    if ".." in path or not re.fullmatch(re.escape(FETCH_PATH) + r"[0-9]{1,12}", path):
        return False
    return True


# ---------------------------------------------------------------------------------------------------------- sentences
def _clean(w):
    return re.sub(r"\s+", " ", str(w or "")).strip()


def sentences(segments, max_gap=1.2):
    """Whisper's segments (with words) -> sentences [{i, t, e, text}]: a break at. ? ! or a pause over max_gap s."""
    words = []
    for seg in segments or []:
        ws = seg.get("words") if isinstance(seg, dict) else None
        if ws:
            for w in ws:
                txt = _clean(w.get("word"))
                if txt:
                    words.append((float(w.get("start", seg.get("start", 0)) or 0), float(w.get("end", seg.get("end", 0)) or 0), txt))
        elif isinstance(seg, dict) and _clean(seg.get("text")):
            words.append((float(seg.get("start", 0) or 0), float(seg.get("end", 0) or 0), _clean(seg.get("text"))))
    out, cur = [], []

    def flush():
        if cur:
            text = _clean(" ".join(w[2] for w in cur))[:MAX_TEXT]
            if re.search(r"[A-Za-z0-9]", text):
                out.append({"i": len(out), "t": round(cur[0][0], 2), "e": round(cur[-1][1], 2), "text": text})
        cur.clear()
    for k, w in enumerate(words):
        if cur and w[0] - cur[-1][1] > max_gap:
            flush()
        cur.append(w)
        if re.search(r"[.?!]$", w[2]) or len(cur) >= 60:
            flush()
        if len(out) >= MAX_SENTENCES:
            break
    flush()
    return out[:MAX_SENTENCES]


def _norm(s):
    return re.sub(r"[^a-z0-9' ]+", " ", str(s).lower()).split()


def from_words(text, source):
    """True when every word of `text` is the driver's own, IN THE ORDER they said them. The model may drop
    words, fix case and punctuation, and add only a few glue words (FILLER) -- never a digit, a negation or
    any other word of its own."""
    src = _norm(source)
    got = _norm(text)
    if not got:
        return False
    j = 0
    for w in got:
        k = j
        while k < len(src) and src[k] != w:
            k += 1
        if k < len(src):
            j = k + 1
        elif w not in FILLER:
            return False
    return True


# --------------------------------------------------------------------------------------------------------- rules read
def rules_read(sents):
    """The same rules as the Route Planner's live dictation: a command is dropped; "X, scratch that, Y" keeps Y and
    takes out X; "scratch that" on its own takes out the note just before it (within 45 s)."""
    notes, removed, dropped = [], [], []
    for s in sents:
        t = re.sub(r"[^a-z' ]+", " ", s["text"].lower()).strip()
        t = re.sub(r"\s+", " ", t)
        if COMMAND.match(t):
            dropped.append({"ids": [s["i"]], "why": "a command"})
            continue
        if RETRACT_ALONE.match(t):
            if notes and s["t"] - notes[-1]["e"] <= 45:
                n = notes.pop()
                removed.append({"ids": n["ids"], "t": n["t"], "text": n["text"], "why": "“" + s["text"].strip(" .") + "” right after it"})
            dropped.append({"ids": [s["i"]], "why": "a correction"})
            continue
        parts = [p for p in RETRACT_INSIDE.split(s["text"])]
        if len(parts) > 1 and not _clean(parts[0]).strip(" ,.;"):
            # "Scratch that, three caps ...": takes back the note before it, keeps what follows
            if notes and s["t"] - notes[-1]["e"] <= 45:
                n = notes.pop()
                removed.append({"ids": n["ids"], "t": n["t"], "text": n["text"], "why": "taken back by the next words"})
            parts = parts[1:]
            if len(parts) == 1:
                keep = _clean(parts[0]).strip(" ,.;")
                if keep and re.search(r"[a-z0-9]", keep, re.I):
                    notes.append({"ids": [s["i"]], "t": s["t"], "e": s["e"], "text": keep})
                continue
        if len(parts) > 1:
            keep = _clean(parts[-1]).strip(" ,.;")
            gone = _clean(" ".join(parts[:-1])).strip(" ,.;")
            if gone:
                removed.append({"ids": [s["i"]], "t": s["t"], "text": gone, "why": "corrected in the same sentence"})
            if keep and re.search(r"[a-z0-9]", keep, re.I):
                notes.append({"ids": [s["i"]], "t": s["t"], "e": s["e"], "text": keep})
            continue
        notes.append({"ids": [s["i"]], "t": s["t"], "e": s["e"], "text": s["text"]})
    return {"notes": notes, "removed": removed, "dropped": dropped}


# --------------------------------------------------------------------------------------------------------- model read
SYSTEM = """You read the transcript of a salesperson's drive through a neighborhood. While driving they speak short notes
about what the HOMES have (vents, artificial turf, sliding doors, old screens, gated communities, no-soliciting signs),
and sometimes correct themselves ("scratch that", "delete that", "no wait", "I mean").

For every sentence id in WINDOW decide exactly one of:
  - a NOTE: what they observed. Merge sentences that are one thought. Keep their own words (you may drop filler, fix
    case and punctuation; never add facts or words they did not say).
  - REMOVED: words a correction took back. Decide how much the correction covers from the context: a few words, a
    clause, the whole sentence before it, or the last two sentences if they clearly say so. Put the correction phrase
    itself in DROPPED. If a sentence has a correction in the middle, the part before it is REMOVED and the part after
    it is a NOTE.
  - DROPPED: commands to the app (pause, resume, end survey, keep that), the correction phrases, and talk that is not
    about homes (phone calls, chatter, music).
CONTEXT sentences come before the window and are already decided; you may only REMOVE from them when a correction at
the start of the window clearly takes one back. A gate or lockbox code is a NOTE: keep it word for word.
Answer with JSON only."""

SCHEMA = {"name": "survey_read", "strict": True, "schema": {
    "type": "object", "additionalProperties": False, "required": ["notes", "removed", "dropped"],
    "properties": {
        "notes": {"type": "array", "items": {"type": "object", "additionalProperties": False, "required": ["ids", "text"],
                  "properties": {"ids": {"type": "array", "items": {"type": "integer"}}, "text": {"type": "string"}}}},
        "removed": {"type": "array", "items": {"type": "object", "additionalProperties": False,
                    "required": ["ids", "text", "why"],
                    "properties": {"ids": {"type": "array", "items": {"type": "integer"}}, "text": {"type": "string"},
                                   "why": {"type": "string"}}}},
        "dropped": {"type": "array", "items": {"type": "object", "additionalProperties": False, "required": ["ids", "why"],
                    "properties": {"ids": {"type": "array", "items": {"type": "integer"}}, "why": {"type": "string"}}}}}}}

READ_SAMPLING = dict(temperature=0.2, top_p=0.8, top_k=20, min_p=0.0, presence_penalty=0.0, repeat_penalty=1.0,
                     max_tokens=3000, seed=42)


def _mmss(t):
    t = int(max(0, t))
    return f"{t // 60}:{t % 60:02d}"


def street_at(streets):
    """The street for each sentence: the website sends a row only where the street changes."""
    rows = sorted(((i, s, z) for i, s, z in streets if type(i) is int), key=lambda r: r[0])

    def at(i):
        best = None
        for r in rows:
            if r[0] <= i:
                best = r
            else:
                break
        return (best[1], best[2]) if best else None
    return at


def window_prompt(ctx, win, streets, vocab):
    st = street_at(streets)

    def line(s):
        where = st(s["i"])
        return f'{s["i"]} | {_mmss(s["t"])} | {(where[0] + (" " + where[1] if where[1] else "")) if where and where[0] else "street unknown"} | {s["text"]}'
    return ("WORDS THE SURVEY LISTENS FOR: " + ", ".join(vocab[:60]) + "\n\n"
            "CONTEXT (already decided):\n" + ("\n".join(line(s) for s in ctx) or "(none)") + "\n\n"
            "WINDOW (id | time | street | what was said):\n" + "\n".join(line(s) for s in win))


def check_window(reply, ctx, win):
    """The model's answer for one window, checked; None when it cannot be used as is."""
    if not isinstance(reply, dict):
        return None
    by = {s["i"]: s for s in list(ctx) + list(win)}
    win_ids = {s["i"] for s in win}
    ctx_ids = {s["i"] for s in ctx}
    seen = set()
    out = {"notes": [], "removed": [], "dropped": []}
    for kind in ("notes", "removed", "dropped"):
        for it in reply.get(kind) or []:
            if not isinstance(it, dict):
                return None
            ids = sorted({x for x in (it.get("ids") or []) if type(x) is int and x in by})
            if not ids:
                continue
            if ids != list(range(ids[0], ids[0] + len(ids))):
                return None                      # one thought is said in a row: never sentences from here and there
            if kind != "removed" and not set(ids) <= win_ids:
                return None                      # only REMOVED may reach back into the context
            src = " ".join(by[i]["text"] for i in ids)
            text = _clean(it.get("text"))[:MAX_TEXT]
            if kind in ("notes", "removed"):
                if not text or not from_words(text, src):
                    return None                  # words the driver never said
            row = {"ids": ids, "t": by[ids[0]]["t"]}
            if kind == "notes":
                row.update(e=by[ids[-1]]["e"], text=text)
            elif kind == "removed":
                row.update(text=text, why=_clean(it.get("why"))[:120] or "a correction")
            else:
                row.update(why=_clean(it.get("why"))[:80] or "not a note")
            if kind != "removed":
                if seen & set(ids):
                    return None                  # a sentence is one note, or dropped -- never both, never twice
                seen |= set(ids)
            out[kind].append(row)
    # every sentence in the window is accounted for: one left out is kept as said (never lost without a word)
    removed_ids = {i for r in out["removed"] for i in r["ids"]}
    for s in win:
        if s["i"] not in seen and s["i"] not in removed_ids:
            out["notes"].append({"ids": [s["i"]], "t": s["t"], "e": s["e"], "text": s["text"]})
    out["notes"].sort(key=lambda n: n["t"])
    return out


def _json(text):
    text = (text or "").strip()
    if text.startswith("```"):
        text = re.sub(r"^```(?:json)?\s*|\s*```$", "", text)
    m = re.search(r"\{.*\}", text, re.S)
    return json.loads(m.group(0) if m else text)


def model_read(lm, model, sents, streets, vocab, window=40, seconds=180, say=None):
    """The whole drive, a window at a time; (result, how) where how is 'model', 'rules' or 'mixed'."""
    notes, removed, dropped, used = [], [], [], set()
    for k in range(0, len(sents), window):
        win = sents[k:k + window]
        ctx = sents[max(0, k - 4):k]
        got = None
        waited = 0.0
        while getattr(lm, "waiting", 0) > 0 and waited < 120:
            time.sleep(0.5)                      # a Charts question is waiting for the model: it goes first
            waited += 0.5
        if model:
            try:
                r = lm.chat_full(model, SYSTEM, window_prompt(ctx, win, streets, vocab), READ_SAMPLING, schema=SCHEMA,
                                 timeout=seconds)
                got = check_window(_json(r["text"]), ctx, win)
            except Exception as e:             # the model offline, slow or wrong: rules for this window
                if say:
                    say(f"survey read: window {k // window + 1} by rules ({type(e).__name__})")
                got = None
        if got is None:
            got = rules_read(win)
            used.add("rules")
        else:
            used.add("model")
        # a REMOVED reaching back takes the earlier note out
        back = {i for r in got["removed"] for i in r["ids"] if i < win[0]["i"]}
        if back:
            keep = []
            for n in notes:
                if set(n["ids"]) & back:
                    removed.append({"ids": n["ids"], "t": n["t"], "text": n["text"], "why": "taken back by the next words"})
                else:
                    keep.append(n)
            notes = keep
            got["removed"] = [r for r in got["removed"] if not set(r["ids"]) <= back]
        notes += got["notes"]
        removed += got["removed"]
        dropped += got["dropped"]
    how = "mixed" if len(used) > 1 else (used.pop() if used else "rules")
    return {"notes": notes, "removed": removed, "dropped": dropped}, how


# --------------------------------------------------------------------------------------------------------------- jobs
class Jobs:
    """The survey's jobs on this computer, and one worker thread that runs them in order."""

    def __init__(self, cfg, lm, say=None):
        self.cfg, self.lm = cfg, lm
        self.s = settings(cfg)
        self.dir = folder(cfg)
        self.lock = threading.Lock()
        self.wake = threading.Event()
        self.thread = None
        self.say = say or (lambda m: None)
        self.transcriber = None              # tests swap this for a fake: fn(wav_path) -> whisper result dict
        self._recover()

    def _recover(self):
        for p in self.dir.glob("*.json"):
            j = self._peek(p)
            if j and j.get("status") == "running":
                j["attempts"] = int(j.get("attempts", 1))
                if j["attempts"] >= 2:
                    j["status"], j["error"] = "failed", "interrupted"
                else:
                    j["status"] = "queued"
                try:
                    self.save(j)
                except OSError:
                    pass

    # -------------------------------------------------------------- files
    def _path(self, job):
        return self.dir / f"{job}.json"

    def load(self, job):
        if not JOB_ID.match(str(job or "")):
            return None
        p = self._path(job)
        try:
            return json.loads(p.read_text())
        except (OSError, ValueError):
            return None

    def save(self, j):
        p = self._path(j["id"])
        tmp = p.with_suffix(".tmp")
        tmp.write_text(json.dumps(j, allow_nan=False))
        os.chmod(tmp, 0o600)
        os.replace(tmp, p)

    def purge(self, now=None):
        now = now or time.time()
        keep = float(self.s.get("keep_hours", 24)) * 3600
        for p in self.dir.glob("*.json"):
            try:
                if now - p.stat().st_mtime > keep:
                    p.unlink()
            except OSError:
                pass
        for pat in ("*.audio", "*.tmp"):
            for p in self.dir.glob(pat):
                try:
                    if now - p.stat().st_mtime > 6 * 3600:
                        p.unlink()
                except OSError:
                    pass
        for d in self.dir.glob("survey-*"):              # a temp folder a crash left behind
            try:
                if d.is_dir() and now - d.stat().st_mtime > 6 * 3600:
                    shutil.rmtree(d, ignore_errors=True)
            except OSError:
                pass

    # -------------------------------------------------------------- ops
    def submit(self, body):
        if str(self.s.get("mode", "on")).lower() != "on":
            raise ValueError("off")
        user = str(body.get("user", ""))
        if not USER.match(user):
            raise ValueError("bad_request")
        kind = body.get("kind")
        self.purge()
        mine = 0
        for p in self.dir.glob("*.json"):
            x = self._peek(p)
            if x and x.get("user") == user and x.get("status") in ("queued", "running"):
                mine += 1
        if mine >= int(self.s.get("per_user", 8)):
            raise ValueError("busy")
        j = {"id": secrets.token_hex(12), "user": user, "kind": kind, "status": "queued", "created": time.time()}
        if kind == "transcribe":
            a = body.get("audio") if isinstance(body.get("audio"), dict) else {}
            url = str(a.get("url", ""))
            if not allowed_url(url, self.s["site"]):
                raise ValueError("bad_url")
            try:
                size = int(a.get("bytes", 0))
            except (TypeError, ValueError):
                size = 0
            if size <= 0 or size > int(self.s["max_mb"]) * 1024 * 1024:
                raise ValueError("too_big")
            j["audio"] = {"url": url, "bytes": size, "mime": str(a.get("mime", ""))[:60]}
            j["hint"] = [str(v)[:40] for v in (body.get("vocab") or [])[:40] if isinstance(v, str)]
        elif kind == "read":
            of = self.load(body.get("of"))
            if not of or of.get("user") != user or of.get("kind") != "transcribe" or of.get("status") != "done":
                raise ValueError("not_found")
            j["of"] = of["id"]
            st = []
            for row in (body.get("streets") or [])[:MAX_SENTENCES]:
                if isinstance(row, list) and len(row) >= 2 and type(row[0]) is int:
                    st.append([row[0], str(row[1] or "")[:80], str(row[2] if len(row) > 2 else "")[:5]])
            j["streets"] = st
            j["vocab"] = [str(v)[:40] for v in (body.get("vocab") or [])[:60] if isinstance(v, str)]
        else:
            raise ValueError("bad_request")
        with self.lock:
            self.save(j)
        self.start()
        self.wake.set()
        return {"job": j["id"], "status": "queued"}

    def result(self, body):
        user = str(body.get("user", ""))
        j = self.load(body.get("job"))
        if not j or j.get("user") != user:
            raise ValueError("not_found")
        out = {"job": j["id"], "kind": j["kind"], "status": j["status"]}
        if j["status"] == "failed":
            out["error"] = j.get("error", "failed")
        if j["status"] != "done":
            if j["status"] == "queued":
                ahead = 0
                for p in self.dir.glob("*.json"):
                    x = self._peek(p)
                    if x and x.get("status") in ("queued", "running") and x.get("created", 0) < j["created"]:
                        ahead += 1
                out["ahead"] = ahead
            return out
        try:
            off = max(0, int(body.get("offset", 0)))
        except (TypeError, ValueError):
            off = 0
        if j["kind"] == "transcribe":
            rows = j["result"]["sentences"]
            out.update(duration=j["result"].get("duration"), creation_time=j["result"].get("creation_time"),
                       total=len(rows), sentences=rows[off:off + PAGE])
            nxt = off + PAGE
        else:
            r = j["result"]
            items = [("n", x) for x in r["notes"]] + [("r", x) for x in r["removed"]] + [("d", x) for x in r["dropped"]]
            page = items[off:off + PAGE]
            out.update(how=j["result_how"], total=len(items),
                       notes=[x for k, x in page if k == "n"], removed=[x for k, x in page if k == "r"],
                       dropped=[x for k, x in page if k == "d"])
            nxt = off + PAGE
            rows = items
        out["next"] = nxt if nxt < len(rows) else None
        if body.get("forget") is True and out["next"] is None and j["kind"] == "read":
            with self.lock:
                others = [x for x in (self._peek(p) for p in self.dir.glob("*.json"))
                          if x and x.get("kind") == "read" and x.get("of") == j.get("of") and x["id"] != j["id"]
                          and x.get("status") in ("queued", "running")]
                for jid in ([j["id"]] if others else [j["id"], j.get("of")]):   # a read still queued keeps its words
                    try:
                        self._path(jid).unlink()
                    except (OSError, TypeError):
                        pass
        return out

    def _peek(self, p):
        try:
            return json.loads(p.read_text())
        except (OSError, ValueError):
            return None

    def status(self):
        return {"mode": str(self.s.get("mode", "on")).lower(), "ffmpeg": bool(tool("ffmpeg")), "whisper": whisper_ok(),
                "whisper_model": self._whisper_model() or None, "model": self._model() or None}

    # -------------------------------------------------------------- the worker
    def start(self):
        if self.thread is not None and self.thread.is_alive():
            return
        self.thread = threading.Thread(target=self._loop, name="survey-worker", daemon=True)
        self.thread.start()

    def _next(self):
        with self.lock:
            best = None
            for p in self.dir.glob("*.json"):
                j = self._peek(p)
                if j and j.get("status") in ("queued", "running") and (best is None or j["created"] < best["created"]):
                    best = j
            if best:
                best["status"] = "running"
                best["started"] = time.time()
                best["attempts"] = int(best.get("attempts", 0)) + 1
                self.save(best)
            return best

    def _loop(self):
        while True:
            try:
                j = self._next()
                if j is None:
                    self.wake.wait(600)
                    self.wake.clear()
                    self.purge()
                    continue
                self.run_one(j)
            except Exception as e:
                self.say(f"survey worker: {type(e).__name__}")
                time.sleep(5)

    def run_one(self, j):
        try:
            if j["kind"] == "transcribe":
                j["result"] = self._transcribe(j)
            else:
                of = self.load(j["of"])
                if not of or of.get("status") != "done":
                    raise RuntimeError("gone")
                res, how = model_read(self.lm, self._model(), of["result"]["sentences"], j["streets"], j["vocab"],
                                      int(self.s.get("window", 40)), float(self.s.get("read_seconds", 180)), self.say)
                j["result"], j["result_how"] = res, how
            j["status"] = "done"
        except Exception as e:
            j["status"] = "failed"
            code = str(e) if str(e) in ("gone", "download", "too_big", "too_long", "no_ffmpeg", "no_whisper", "no_whisper_model",
                                        "convert", "empty") else "internal"
            j["error"] = code
            self.say(f"survey job {j['id'][:6]} failed: {code} ({type(e).__name__})")
        j["finished"] = time.time()
        with self.lock:
            self.save(j)

    def _model(self):
        return str(self.s.get("model") or getattr(self.lm, "caption_model", "") or getattr(self.lm, "backup_model", ""))

    def _whisper_model(self):
        """The speech model an administrator chose: "survey": {"whisper_model": "..."} in the hub config (a Whisper
        model the hub computer's Whisper build can load). None by default: recordings are refused until it is set."""
        return str(self.s.get("whisper_model") or "").strip()

    def _download(self, url, limit, dest):
        """The recording, from the website's own address only."""
        secs = float(self.s.get("download_seconds", 900))
        if not allowed_url(url, self.s["site"]):
            raise RuntimeError("download")

        class _NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl):
                raise urllib.error.HTTPError(req.full_url, code, "redirect refused", headers, fp)
        opener = urllib.request.build_opener(_NoRedirect)
        req = urllib.request.Request(url, headers={"User-Agent": "charts-hub-survey"})
        started = time.time()
        try:
            with opener.open(req, timeout=30) as r, open(dest, "wb") as f:
                got = 0
                while True:
                    chunk = r.read1(1 << 16) if hasattr(r, "read1") else r.read(1 << 16)
                    if not chunk:
                        break
                    got += len(chunk)
                    if got > limit:
                        raise RuntimeError("too_big")
                    if time.time() - started > secs:
                        raise RuntimeError("download")
                    f.write(chunk)
            return got
        except urllib.error.HTTPError:
            raise RuntimeError("download") from None
        except urllib.error.URLError as e:
            if "CERTIFICATE_VERIFY_FAILED" not in str(getattr(e, "reason", e)):
                raise RuntimeError("download") from None
        except (TimeoutError, OSError):
            raise RuntimeError("download") from None
        # the hub computer's Python without its certificates: the system's curl, as polite.py does (no redirects, no
        # globbing, no ~/.curlrc)
        exe = shutil.which("curl") or "/usr/bin/curl"
        p = subprocess.run([exe, "-q", "-g", "-sS", "-f", "--proto", "=https,http", "--max-redirs", "0", "--max-time", str(int(secs)),
                            "--max-filesize", str(limit), "-o", str(dest), url], capture_output=True, timeout=secs + 30)
        if p.returncode != 0:
            raise RuntimeError("download")
        return os.path.getsize(dest)

    def _transcribe(self, j):
        ffmpeg, ffprobe = tool("ffmpeg"), tool("ffprobe")
        if not ffmpeg:
            raise RuntimeError("no_ffmpeg")
        if self.transcriber is None and not whisper_ok():
            raise RuntimeError("no_whisper")
        if self.transcriber is None and not self._whisper_model():
            raise RuntimeError("no_whisper_model")      # nobody has chosen a speech model yet (survey.whisper_model)
        audio = self.dir / f"{j['id']}.audio"
        wav = Path(tempfile.mkdtemp(prefix="survey-", dir=str(self.dir))) / "a.wav"
        try:
            got = self._download(j["audio"]["url"], int(self.s["max_mb"]) * 1024 * 1024, audio)
            if got <= 0:
                raise RuntimeError("empty")
            created, duration = None, None
            if ffprobe:
                try:
                    p = subprocess.run([ffprobe, "-v", "quiet", *FF_SAFE, "-print_format", "json", "-show_format", str(audio)],
                                       capture_output=True, timeout=60)
                    fmt = json.loads(p.stdout or b"{}").get("format") or {}
                    created = (fmt.get("tags") or {}).get("creation_time")
                    duration = float(fmt["duration"]) if fmt.get("duration") not in (None, "N/A") else None
                except Exception:
                    pass
            cap = int(float(self.s.get("max_minutes", 240)) * 60)
            if duration and duration > cap:
                raise RuntimeError("too_long")
            p = subprocess.run([ffmpeg, "-nostdin", "-v", "error", "-y", *FF_SAFE, "-i", str(audio), "-t", str(cap), "-ac", "1", "-ar", "16000", str(wav)],
                               capture_output=True, timeout=3600)
            if p.returncode != 0 or not wav.exists():
                raise RuntimeError("convert")
            try:
                audio.unlink()                    # the recording is gone the moment it is converted
            except OSError:
                pass
            if self.transcriber is not None:
                res = self.transcriber(str(wav))
            else:
                import mlx_whisper
                prompt = "Survey notes: " + ", ".join(j.get("hint") or []) + ". Scratch that."
                res = mlx_whisper.transcribe(str(wav), path_or_hf_repo=self._whisper_model(), word_timestamps=True,
                                             language="en", initial_prompt=prompt[:600], condition_on_previous_text=False)
            sents = sentences(res.get("segments") or [])
            if duration is None and sents:
                duration = sents[-1]["e"]
            return {"sentences": sents, "duration": round(duration, 2) if duration else None,
                    "creation_time": str(created)[:40] if created else None}
        finally:
            for p in (audio, wav):
                try:
                    p.unlink()
                except OSError:
                    pass
            try:
                wav.parent.rmdir()
            except OSError:
                pass

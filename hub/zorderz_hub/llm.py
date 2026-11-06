"""The local model layer: an OpenAI-style model server on 127.0.0.1 only.

Rules:
  1. user message = TODAY / PREVIOUS_SPEC / QUESTION;
  2. response_format = the pack's JSON schema;
  3. the hub validates again (spec.validate);
  4. one retry with the validator's message appended, then invalid_spec;
  5. approved specs are cached by normalized question + previous spec + today
     (in memory only: typed questions can hold names, so they never touch disk);
  6. captions see only the spec and the computed result, and a caption with a
     number that isn't in the result is dropped;
  7. one spec-writer request at a time, a queue of 5, the backup model after
     20 s or if the main model is unloaded.
No cloud fallback exists in this code.
"""
import hashlib
import json
import re
import threading
import time
import urllib.error
import urllib.request
from collections import OrderedDict

from .spec import normalize, validate

SPEC_SAMPLING = dict(temperature=0.2, top_p=0.8, top_k=20, min_p=0.0, presence_penalty=0.0,
                     frequency_penalty=0.0, repeat_penalty=1.0, max_tokens=700, seed=42)
CAPTION_SAMPLING = dict(temperature=0.3, top_p=0.8, top_k=20, min_p=0.0, presence_penalty=0.0,
                        repeat_penalty=1.0, max_tokens=250)


def _without(node, keys):
    """A copy of a JSON schema without the given keywords, at every level."""
    if isinstance(node, dict):
        return {k: _without(v, keys) for k, v in node.items() if k not in keys}
    if isinstance(node, list):
        return [_without(v, keys) for v in node]
    return node


def _dedupe_lists(spec):
    """Repeated items in the spec's lists, dropped (the model no longer sees uniqueItems)."""
    def once(v):
        if not isinstance(v, list):
            return v
        out = []
        for x in v:
            if x not in out:
                out.append(x)
        return out
    if isinstance(spec, dict):
        spec = dict(spec)
        for k in ("venn_sets", "assumptions", "clarify_options"):
            if k in spec:
                spec[k] = once(spec[k])
        if isinstance(spec.get("filters"), dict):
            spec["filters"] = {k: once(v) for k, v in spec["filters"].items()}
    return spec


class ModelUnavailable(Exception):
    pass


class Busy(Exception):
    pass


class InvalidSpec(Exception):
    def __init__(self, problems):
        super().__init__("; ".join(problems))
        self.problems = problems


def _local_only(url):
    host = re.sub(r"^https?://", "", url).split("/")[0].split(":")[0]
    if host not in {"127.0.0.1", "localhost", "::1"}:
        raise ValueError("LM Studio must be reached on this machine (127.0.0.1); the hub never sends data off it")
    return url.rstrip("/")


class LMStudio:
    def __init__(self, cfg, token=""):
        self.base = _local_only(cfg.get("url", "http://127.0.0.1:1234"))
        self.token = token
        self.spec_model = cfg.get("spec_model", "")
        self.caption_model = cfg.get("caption_model", "")
        self.backup_model = cfg.get("backup_model", "") or self.caption_model
        self.embed_model = cfg.get("embed_model", "")
        self.timeout = float(cfg.get("timeout", 55))
        self.backup_after = float(cfg.get("backup_after_seconds", 20))
        self.slot = threading.Semaphore(1)
        self.waiting = 0
        self.queue_max = int(cfg.get("queue", 5))
        self.lock = threading.Lock()

    # ------------------------------------------------------------ transport
    def _post(self, path, payload, timeout=None):
        req = urllib.request.Request(self.base + path, data=json.dumps(payload).encode(),
                                     headers={"Content-Type": "application/json",
                                              **({"Authorization": f"Bearer {self.token}"}
                                                 if self.token else {})})
        try:
            with urllib.request.urlopen(req, timeout=timeout or self.timeout) as r:
                return json.loads(r.read())
        except urllib.error.HTTPError as e:
            raise ModelUnavailable(f"http {e.code}") from None
        except (urllib.error.URLError, TimeoutError, ConnectionError, OSError):
            raise ModelUnavailable("unreachable") from None

    def models(self):
        req = urllib.request.Request(self.base + "/v1/models", headers=(
            {"Authorization": f"Bearer {self.token}"} if self.token else {}))
        try:
            with urllib.request.urlopen(req, timeout=3) as r:
                return [m.get("id") for m in json.loads(r.read()).get("data", [])]
        except Exception:
            return []

    def chat(self, model, system, user, sampling, schema=None, timeout=None):
        r = self.chat_full(model, system, user, sampling, schema, timeout)
        return r["text"], r["ms"]

    def chat_full(self, model, system, user, sampling, schema=None, timeout=None, tool_calls=False):
        """the reply with why it ended: {text, ms, finish, tokens, thought}. `finish` is
        LM Studio's finish_reason ("stop", or "length" when the token cap cut it off)."""
        payload = {"model": model, "messages": [{"role": "system", "content": system},
                                                {"role": "user", "content": user}], **sampling}
        if schema is not None:
            payload["response_format"] = {"type": "json_schema", "json_schema": schema}
        started = time.time()
        out = self._post("/v1/chat/completions", payload, timeout=timeout)
        try:
            choice = out["choices"][0]
            msg = choice["message"]
            raw = msg.get("content") or ""
        except (KeyError, IndexError, TypeError, AttributeError):
            raise ModelUnavailable("bad reply") from None
        # the Qwen 9B wrote every second look as a tool call, and LM
        # Studio moved it out of `content`. Its arguments are the answer: read them from there.
        from_tool = False
        calls = msg.get("tool_calls") if isinstance(msg, dict) else None
        # only for the second look, whose reply shape is known; a caption or a spec
        # written as a tool call stays empty rather than becoming text
        if tool_calls and not raw.strip() and isinstance(calls, list) and calls:
            parts = []
            for c in calls:
                a = (c.get("function") or {}).get("arguments") if isinstance(c, dict) else None
                if isinstance(a, (dict, list)):
                    a = json.dumps(a)
                if a:
                    parts.append(str(a))
            raw, from_tool = "\n".join(parts), bool(parts)
        text = re.sub(r"<think>.*?</think>", "", raw, flags=re.S)
        text = re.sub(r"^\s*<think>.*\Z", "", text, flags=re.S).strip()      # cut off mid-thought
        usage = out.get("usage") if isinstance(out.get("usage"), dict) else {}
        return {"text": text, "ms": int((time.time() - started) * 1000),
                "finish": str(choice.get("finish_reason") or ""),
                "tokens": usage.get("completion_tokens"),
                "thought": bool(msg.get("reasoning_content")) or "<think>" in raw,
                "from_tool_call": from_tool}

    def embed(self, texts):
        if not self.embed_model:
            raise ModelUnavailable("no embedding model")
        out = self._post("/v1/embeddings", {"model": self.embed_model, "input": texts}, timeout=120)
        return [d["embedding"] for d in out["data"]]


def user_message(question, previous_spec, today):
    prev = json.dumps(previous_spec, ensure_ascii=False, separators=(",", ":")) if previous_spec else "none"
    return f"TODAY: {today}\nPREVIOUS_SPEC: {prev}\nQUESTION: {question}"


def _parse(text):
    text = text.strip()
    if text.startswith("```"):
        text = re.sub(r"^```(?:json)?\s*|\s*```$", "", text)
    return json.loads(text)


class SpecWriter:
    def __init__(self, lm, pack):
        self.lm = lm
        self.set_pack(pack)
        self.cache = OrderedDict()
        self.cache_max = 1000
        self.cache_lock = threading.Lock()

    def set_pack(self, pack):
        self.pack = pack
        self.system = pack.spec_prompt()
        self.caption_system = pack.caption_prompt()
        self.schema = pack.schema()
        # LM Studio's MLX engine refuses "uniqueItems" ("Unimplemented keys")
        # and GGUF ignores nothing we need from it, so the model gets the schema
        # without it. Duplicates are removed here before the full schema checks.
        self.model_schema = _without(self.schema, {"uniqueItems"})
        # the one-customer filter is for invoice-card pivots only; the question
        # reader never sees it (and op_ask clears it whatever the model writes).
        f = self.model_schema.get("schema", {}).get("properties", {}).get("filters", {})
        f.get("properties", {}).pop("customers", None)

    def clear_cache(self):
        with self.cache_lock:
            self.cache.clear()

    @staticmethod
    def cache_key(question, previous_spec, today):
        q = re.sub(r"\s+", " ", question.strip().casefold())
        prev = json.dumps(previous_spec, sort_keys=True, ensure_ascii=False) if previous_spec else ""
        return hashlib.sha256(f"{q}\x00{prev}\x00{today}".encode()).hexdigest()

    def _call(self, model, question, previous_spec, today):
        text, ms = self.lm.chat(model, self.system, user_message(question, previous_spec, today),
                                SPEC_SAMPLING, self.model_schema)
        try:
            spec = _parse(text)
        except (ValueError, TypeError):
            return None, ["the reply was not valid JSON"], ms
        spec = _dedupe_lists(spec)
        return spec, validate(spec, self.pack, self.schema), ms

    def write(self, question, previous_spec, today):
        """Return (spec, meta). Raises Busy, ModelUnavailable or InvalidSpec."""
        key = self.cache_key(question, previous_spec, today)
        with self.cache_lock:
            if key in self.cache:
                self.cache.move_to_end(key)
                return json.loads(self.cache[key]), {"cached": True, "llm_ms": 0,
                                                     "model": self.lm.spec_model,
                                                     "backup_model_used": False}
        with self.lm.lock:
            if self.lm.waiting >= self.lm.queue_max:
                raise Busy()
            self.lm.waiting += 1
        got = False
        try:
            got = self.lm.slot.acquire(timeout=self.lm.backup_after)
        finally:
            with self.lm.lock:
                self.lm.waiting -= 1
        model, backup = (self.lm.spec_model, False) if got else (self.lm.backup_model, True)
        try:
            total_ms, problems, spec = 0, [], None
            for attempt in range(2):
                q = question if attempt == 0 else (
                    f"{question}\n(Your previous answer was rejected: {'; '.join(problems)[:400]}. "
                    "Answer again with a corrected spec.)")
                try:
                    spec, problems, ms = self._call(model, q, previous_spec, today)
                except ModelUnavailable:
                    if backup or not self.lm.backup_model:
                        raise
                    model, backup = self.lm.backup_model, True    # main model unloaded
                    spec, problems, ms = self._call(model, q, previous_spec, today)
                total_ms += ms
                if not problems:
                    break
            if problems:
                raise InvalidSpec(problems)
        finally:
            if got:
                self.lm.slot.release()
        spec = normalize(spec, self.pack)
        with self.cache_lock:
            self.cache[key] = json.dumps(spec)
            while len(self.cache) > self.cache_max:
                self.cache.popitem(last=False)
        return spec, {"cached": False, "llm_ms": total_ms, "model": model,
                      "backup_model_used": backup}

    # ------------------------------------------------------------ captions
    def caption(self, spec, result):
        if result.get("empty"):
            return "No matching jobs for these filters."
        rows = [dict(zip(result["columns"], r)) for r in result["rows"][:60]]
        totals = {k: v for k, v in result["totals"].items() if k in ("value", "by_period", "jobs", "revenue")}
        brief = {k: spec[k] for k in ("title", "measure", "split", "time_grain", "date_from",
                                      "date_to", "filters", "assumptions") if k in spec}
        brief["units_estimated"] = result.get("units_estimated", False)
        user = (f"SPEC: {json.dumps(brief, ensure_ascii=False)}\n"
                f"RESULTS: {json.dumps(rows, ensure_ascii=False)}\n"
                f"TOTALS: {json.dumps(totals, ensure_ascii=False)}")
        try:
            text, _ = self.lm.chat(self.lm.caption_model, self.caption_system, user, CAPTION_SAMPLING)
        except ModelUnavailable:
            return ""
        text = text.strip()
        return text if numbers_ok(text, spec, result) else ""


_NUM = re.compile(r"(?<![A-Za-z])\d[\d,]*(?:\.\d+)?")


def _numbers(text):
    out = set()
    for m in _NUM.findall(str(text)):
        m = m.rstrip(",.")
        try:
            out.add(round(float(m.replace(",", "")), 2))
        except ValueError:
            pass
    return out


def numbers_ok(caption, spec, result):
    """Every number in the caption must come from the result (or the spec's own
    dates/title). Also accepts the chart's k/M shorthands of result values."""
    allowed = set()
    for r in result.get("rows", []):
        for v in r:
            if isinstance(v, (int, float)):
                allowed |= {round(float(v), 2), round(float(v)), round(float(v) / 1000),
                            round(float(v) / 1000, 1), round(float(v) / 1e6, 1), round(float(v) / 1e6, 2)}
            else:
                allowed |= _numbers(v)
    def walk(x):
        if isinstance(x, dict):
            for k, v in x.items():
                allowed.update(_numbers(k))
                walk(v)
        elif isinstance(x, list):
            for v in x:
                walk(v)
        elif isinstance(x, (int, float)) and not isinstance(x, bool):
            v = float(x)
            allowed.update({round(v, 2), round(v), round(v / 1000), round(v / 1000, 1),
                            round(v / 1e6, 1), round(v / 1e6, 2)})
        elif isinstance(x, str):
            allowed.update(_numbers(x))
    walk(result.get("totals", {}))
    walk(result.get("periods", []))
    walk(result.get("groups", []))
    walk({k: spec.get(k) for k in ("title", "date_from", "date_to", "assumptions", "top_n")})
    return _numbers(caption) <= allowed

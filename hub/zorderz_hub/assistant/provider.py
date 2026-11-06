"""The provider seam: the engine talks to a Provider, never to an API.

A request and a response are in one neutral shape, so another provider can be added as a second adapter:

  Neutral request:  {"model", "system": [text blocks], "messages": [...], "tools": [...], "max_tokens", "effort",
                     "thinking": "adaptive" | None, "allow_web": bool, "timeout"}
                    Blocks may carry "cache": "5m" | "1h"; the adapter turns that into its own form.
  Neutral response: Response(content blocks, stop_reason, usage {in, cache_write_5m, cache_write_1h, cache_read, out,
                    searches}, model, http)

What a turn may use comes from the website with the turn, sealed: the key (held in memory for that turn only), the
workspace its answers must come from, the models (the website's Model Registry slots), their prices and the region.
Nothing here names a model, a price or a workspace.

The guard (problems()) is the website's zero-data-retention guard on this side too: exactly POST /v1/messages on the
provider's own host, no anthropic-beta header, only the fields and block types the website's guard allows, only the
models the turn names, the region exactly as configured, and basic web search called directly, only when the turn
allows it. A request outside that is refused, never stripped and sent.
"""
import json
import urllib.error
import urllib.parse
import urllib.request

API_HOST = "api.anthropic.com"
API_PATH = "/v1/messages"                      # exactly: never /v1/messages/batches, never /v1/files
API_URL = "https://" + API_HOST + API_PATH
API_VERSION = "2023-06-01"
WEB_SEARCH_OK = "web_search_20250305"          # the basic tool: no code execution behind it
WEB_MAX_USES = 3
GEO_FACTOR_DEFAULT = 1.0                       # no region: list prices
TOP ={"model", "max_tokens", "system", "messages", "tools", "tool_choice", "thinking", "output_config",
       "inference_geo", "temperature", "stop_sequences", "metadata", "stream"}
BLOCK_TYPES = {"text", "image", "document", "tool_use", "tool_result", "thinking", "redacted_thinking",
               "server_tool_use", "web_search_tool_result"}
SOURCE_TYPES = {"base64", "text", "url"}       # never "file" (the Files API)
INNER_TYPES = {"text", "image", "document", "search_result"}
SERVER_TOOLS = {"web_search"}
OUTPUT_CONFIG = {"effort"}
EFFORTS = ("low", "medium", "high", "max")


class ProviderError(Exception):
    def __init__(self, kind, http=0, retry_after=0.0, billed=False, usage=None):
        super().__init__("%s (HTTP %s)" % (kind, http))
        self.kind, self.http, self.retry_after = kind, http, retry_after
        self.billed = billed          # the call probably ran (a timeout, an answer cut off): the engine counts it
        self.usage = usage            # what the answer said it used, when there was one


class GuardError(Exception):
    """A request outside what may be sent. Its message names the problem, never the content."""


# ------------------------------------------------------------------------------------------------- money
def cost_usd(rates, usage, geo_factor=1.0, search_usd=0.01):
    """rates: [in, cache write 5 min, cache write 1 h, cache read, out], USD per million tokens."""
    p = [float(x) for x in rates]
    tokens = (usage.get("in", 0) * p[0] + usage.get("cache_write_5m", 0) * p[1] + usage.get("cache_write_1h", 0) * p[2]
              + usage.get("cache_read", 0) * p[3] + usage.get("out", 0) * p[4]) / 1e6
    return tokens * float(geo_factor) + usage.get("searches", 0) * float(search_usd)


def estimate_tokens(obj):
    """A cautious estimate for the money check before a call: a token per 3 bytes of UTF-8."""
    return int(len(json.dumps(obj, ensure_ascii=False).encode("utf-8")) / 3.0) + 1


def answer_text(content):
    """The readable answer in a list of blocks: text blocks next to each other joined as they are (a cited answer
    arrives split at each citation), text separated by other blocks (a search, a tool call) by a blank line; the cited
    pages listed at the end, each once. Thinking is never text."""
    parts, run, seen = [], [], []
    for b in content or []:
        if not isinstance(b, dict):
            continue
        if b.get("type") == "text":
            run.append(b.get("text", ""))
            for c in b.get("citations") or []:
                url = c.get("url") if isinstance(c, dict) else None
                if url and url.startswith(("https://", "http://")) and url not in [u for u, _ in seen]:
                    seen.append((url, str(c.get("title") or url)))
        elif run:
            parts.append("".join(run).strip())
            run = []
    if run:
        parts.append("".join(run).strip())
    text = "\n\n".join(p for p in parts if p)
    if seen:
        text += "\n\nSources:\n" + "\n".join("%d. %s - %s" % (i + 1, t, u) for i, (u, t) in enumerate(seen))
    return text


class Response:
    def __init__(self, content, stop_reason, usage, model, http=200):
        self.content, self.stop_reason, self.usage, self.model, self.http = content, stop_reason, usage, model, http

    def tool_uses(self):
        return [b for b in self.content if isinstance(b, dict) and b.get("type") == "tool_use"]


# ------------------------------------------------------------------------------------------------- the guard
def _blocks(content):
    return [] if isinstance(content, str) else [b for b in content or [] if isinstance(b, dict)]


def _source_ok(b, out):
    src = b.get("source")
    if src is not None and (not isinstance(src, dict) or src.get("type") not in SOURCE_TYPES):
        out.append("source")
    if "file_id" in b:
        out.append("file_id")


def endpoint_problems(url):
    try:
        u = urllib.parse.urlsplit(str(url))
        port = u.port
    except ValueError:
        return ["endpoint"]
    if u.scheme != "https" or (u.hostname or "").lower() != API_HOST or u.path != API_PATH or u.query or u.fragment \
            or port is not None or u.username or u.password:
        return ["endpoint"]
    return []


def problems(body, headers=None, allow_web=False, url=API_URL, models=(), geo=""):
    """-> sorted short reasons this request may not be sent (empty = fine)."""
    out = endpoint_problems(url)
    for h in (headers or {}):
        if str(h).strip().lower().startswith("anthropic-beta"):
            out.append("beta header")
    if not isinstance(body, dict):
        return sorted(set(out + ["body"]))
    for k in body:
        if k not in TOP:
            out.append("field %s" % k)
    if body.get("model") not in set(models):
        out.append("model")
    if (body.get("inference_geo") or "") != (geo or ""):
        out.append("region")
    if body.get("stream"):
        out.append("stream")
    oc = body.get("output_config")
    if oc is not None and (not isinstance(oc, dict) or set(oc) - OUTPUT_CONFIG or oc.get("effort") not in EFFORTS):
        out.append("output_config")
    tools = body.get("tools")
    if tools is not None and not isinstance(tools, list):
        out.append("tools")
        tools = []
    for t in tools or []:
        if not isinstance(t, dict):
            out.append("tool")
            continue
        typ = t.get("type")
        if typ in (None, "custom"):
            if not t.get("name") or "input_schema" not in t:
                out.append("custom tool shape")
            continue
        if typ == WEB_SEARCH_OK:
            if not allow_web:
                out.append("web search is off for this turn")
            elif t.get("allowed_callers") != ["direct"]:
                out.append("web search callers")
            continue
        out.append("tool type")
    for b in _blocks(body.get("system")):
        if b.get("type") != "text":
            out.append("system block")
    for m in body.get("messages") or []:
        content = m.get("content") if isinstance(m, dict) else None
        if not isinstance(content, (str, list)):
            out.append("message content")
            continue
        for b in _blocks(content):
            typ = b.get("type")
            if typ not in BLOCK_TYPES:
                out.append("block type")
                continue
            if typ == "server_tool_use" and b.get("name") not in SERVER_TOOLS:
                out.append("server tool")
            _source_ok(b, out)
            if typ == "tool_result":
                inner = b.get("content")
                if not isinstance(inner, (str, list, type(None))):
                    out.append("tool_result content")
                for ib in _blocks(inner if isinstance(inner, list) else None):
                    if ib.get("type") not in INNER_TYPES:
                        out.append("inner block")
                    _source_ok(ib, out)
    return sorted(set(out))


# ------------------------------------------------------------------------------------------------- the body
def _api_block(b):
    b = dict(b)
    ttl = b.pop("cache", None)
    if ttl:
        b["cache_control"] = {"type": "ephemeral", "ttl": "1h"} if ttl == "1h" else {"type": "ephemeral"}
    return b


def build_body(request, geo=""):
    """Neutral request -> Messages API body (pure; tested without a network)."""
    body = {
        "model": request["model"],
        "max_tokens": int(request.get("max_tokens") or 8000),
        "messages": [{"role": m["role"], "content": [_api_block(b) for b in m["content"]]
                      if isinstance(m["content"], list) else m["content"]} for m in request["messages"]],
    }
    if request.get("system"):
        body["system"] = [_api_block(b) for b in request["system"]]
    if geo:
        body["inference_geo"] = geo
    if request.get("thinking", "adaptive") == "adaptive":
        body["thinking"] = {"type": "adaptive"}
    if request.get("effort"):
        body["output_config"] = {"effort": request["effort"] if request["effort"] in EFFORTS else "medium"}
    tools = [dict(t) for t in request.get("tools") or []]
    if request.get("allow_web"):
        tools.append({"type": WEB_SEARCH_OK, "name": "web_search", "max_uses": WEB_MAX_USES,
                      "allowed_callers": ["direct"]})
    if tools:
        body["tools"] = tools
        body["tool_choice"] = {"type": "auto"}
    return body


def parse_usage(u):
    u = u or {}
    cc = u.get("cache_creation") or {}
    w1h = int(cc.get("ephemeral_1h_input_tokens") or 0)
    w_all = int(u.get("cache_creation_input_tokens") or 0)
    stu = u.get("server_tool_use") or {}
    return {"in": int(u.get("input_tokens") or 0), "cache_write_5m": max(0, w_all - w1h), "cache_write_1h": w1h,
            "cache_read": int(u.get("cache_read_input_tokens") or 0), "out": int(u.get("output_tokens") or 0),
            "searches": int(stu.get("web_search_requests") or 0)}


def classify_error(status, data):
    typ = ((data or {}).get("error") or {}).get("type", "") if isinstance((data or {}).get("error"), dict) else ""
    if status == 529 or typ == "overloaded_error":
        return "overloaded"
    if status == 429 or typ == "rate_limit_error":
        return "rate_limited"
    if status in (401, 403) or typ in ("authentication_error", "permission_error"):
        return "auth"
    if status == 400 or typ == "invalid_request_error":
        return "bad_request"
    if status >= 500 or typ == "api_error":
        return "server"
    return "network" if status == 0 else "other"


# ------------------------------------------------------------------------------------------------- the network
class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None                                # a redirect is an error, never followed


def urllib_transport(url, headers, data, timeout):
    """One POST. -> (HTTP status, {lower-case header: value}, body bytes); status 0 when nothing came back.
    A timeout raises TimeoutError (the call may have run)."""
    opener = urllib.request.build_opener(_NoRedirect)
    req = urllib.request.Request(url, data=data, headers=headers, method="POST")
    try:
        with opener.open(req, timeout=timeout) as r:
            return r.status, {k.lower(): v for k, v in r.headers.items()}, r.read()
    except urllib.error.HTTPError as e:
        try:
            raw = e.read()
        except OSError:
            raw = b""
        return e.code, {k.lower(): v for k, v in (e.headers or {}).items()}, raw
    except TimeoutError:
        raise
    except (urllib.error.URLError, OSError):
        return 0, {}, b""


class Provider:
    name = "base"

    def complete(self, request):
        raise NotImplementedError


class AnthropicProvider(Provider):
    """The native provider. The key lives in this object for one turn; it is never written, logged or answered."""
    name = "anthropic"

    def __init__(self, key, workspace, models, geo="", transport=None):
        self._key = key
        self.workspace = str(workspace or "")
        self.models = tuple(models)
        self.geo = geo or ""
        self.transport = transport or urllib_transport

    def complete(self, request):
        if not self.workspace:
            raise ProviderError("wrong_workspace")       # no workspace named: nothing is sent at all
        body = build_body(request, self.geo)
        headers = {"x-api-key": self._key, "anthropic-version": API_VERSION, "content-type": "application/json"}
        p = problems(body, headers, bool(request.get("allow_web")), API_URL, self.models, self.geo)
        if p:
            raise GuardError("refused: " + "; ".join(p))
        timeout = max(5, int(request.get("timeout") or 600))
        try:
            status, hdrs, raw = self.transport(API_URL, headers, json.dumps(body).encode("utf-8"), timeout)
        except TimeoutError:
            raise ProviderError("network", 0, billed=True) from None
        headers = None
        try:
            data = json.loads((raw or b"").decode("utf-8", "replace") or "{}")
        except ValueError:
            data = None
        if status != 200:
            try:
                retry = float((hdrs or {}).get("retry-after") or 0)
            except ValueError:
                retry = 0.0
            raise ProviderError(classify_error(status, data if isinstance(data, dict) else {}), status, retry)
        if not isinstance(data, dict) or data.get("type", "message") != "message" or not isinstance(data.get("content"), list):
            raise ProviderError("network", status, billed=True)       # a 200 cut off mid-body: it ran
        usage = parse_usage(data.get("usage"))
        if str((hdrs or {}).get("anthropic-workspace-id") or "").strip() != self.workspace:
            raise ProviderError("wrong_workspace", status, billed=True, usage=usage)
        if data.get("stop_reason") == "refusal":
            raise ProviderError("refused", status, billed=True, usage=usage)
        return Response(data["content"], data.get("stop_reason"), usage, data.get("model") or body["model"], status)


# ------------------------------------------------------------------------------------------------- tests
class FakeProvider(Provider):
    """Scripted replies, for tests. `script` is a list of Response, ProviderError, or a function taking the request
    and returning one of those. Every request is checked by the guard and kept in `requests` as the API body."""
    name = "fake"

    def __init__(self, script, models=("example-model-large", "example-model-small"), geo=""):
        self.script = list(script)
        self.requests = []
        self.models, self.geo = tuple(models), geo

    def complete(self, request):
        body = build_body(request, self.geo)
        p = problems(body, {}, bool(request.get("allow_web")), API_URL, self.models, self.geo)
        if p:
            raise GuardError("refused: " + "; ".join(p))
        self.requests.append(body)
        if not self.script:
            raise ProviderError("other")
        nxt = self.script.pop(0)
        if callable(nxt):
            nxt = nxt(request)
        if isinstance(nxt, Exception):
            raise nxt
        return nxt


def reply(text="", tools=(), stop=None, usage=None, model="example-model-large", thinking=None):
    """A Response for tests: text, then tool_use blocks [(id, name, input)]."""
    content = []
    if thinking:
        content.append({"type": "thinking", "thinking": thinking, "signature": "sig"})
    if text:
        content.append({"type": "text", "text": text})
    for tid, name, inp in tools:
        content.append({"type": "tool_use", "id": tid, "name": name, "input": inp})
    u = {"in": 100, "cache_write_5m": 0, "cache_write_1h": 0, "cache_read": 0, "out": 50, "searches": 0}
    u.update(usage or {})
    return Response(content, stop or ("tool_use" if tools else "end_turn"), u, model)

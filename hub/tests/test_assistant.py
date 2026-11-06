"""The assistant engine beside the hub, on invented data: the three ops, the sealed round trip with the website, the
runner's whole turn, the money rules and the web-search rule. Throwaway keys; no network; no real provider."""
import json
import os
import time
import unittest
import urllib.parse

import _env
from zorderz_hub import names
from zorderz_hub.assistant import engine as E
from zorderz_hub.assistant import link as L
from zorderz_hub.assistant import provider as P
from zorderz_hub.assistant import runner as R
from zorderz_hub.assistant.ledger import Ledger
from zorderz_hub.envelope import Envelope, keygen

SITE = "https://app.example.com"
KEY = "test-key-" + "x" * 40                     # invented; must never be written anywhere
WORKSPACE = "wrkspc_example0001"
PRICES = {"example-model-large": [4.0, 5.0, 8.0, 0.2, 20.0], "example-model-small": [1.0, 1.25, 2.0, 0.1, 5.0]}


def cfg_for(name, **assistant):
    base = _env.BASE / ("assistant-" + name)
    a = {"mode": "on", "site": SITE}
    a.update(assistant)
    return {"assistant": a, "assistant_dir": str(base / "home"), "assistant_ledger": str(base / "logs" / "a.jsonl"),
            "assistant_store_key": str(base / "config" / "store.key")}


def fetch_url(tid, k="cd" * 32, site=SITE, path="/wp-json/zorderz/v1/ai-workspace/turn-fetch/"):
    return "%s%s%s?k=%s" % (site, path, tid, k)


class LinkTest(unittest.TestCase):
    def setUp(self):
        self.cfg = cfg_for(self.id().split(".")[-1])
        self.link = L.Link(self.cfg)

    def test_a_ticket_is_an_id_and_a_link_to_this_site_only(self):
        tid = "a1" * 16
        self.assertEqual(self.link.turn({"turn_id": tid, "fetch": fetch_url(tid)})["state"], "queued")
        self.assertEqual(self.link.turn({"turn_id": tid, "fetch": fetch_url(tid)})["turn"], tid, "a retry: the same")
        for bad in (fetch_url(tid, site="https://elsewhere.example.com"), fetch_url(tid, site="http://app.example.com"),
                    fetch_url("b2" * 16), fetch_url(tid, path="/wp-json/zorderz/v1/other/"),
                    fetch_url(tid) + "&x=1", fetch_url(tid, k="short"), "https://user:pw@app.example.com/x",
                    fetch_url(tid).replace("turn-fetch/", "turn-fetch/../")):
            with self.assertRaises(L.LinkError) as e:
                self.link.turn({"turn_id": "c3" * 16, "fetch": bad.replace(tid, "c3" * 16) if tid in bad else bad})
            self.assertIn(str(e.exception), ("bad_url",), bad)
        with self.assertRaises(L.LinkError) as e:
            self.link.turn({"turn_id": "NOT-AN-ID", "fetch": fetch_url(tid)})
        self.assertEqual(str(e.exception), "bad_request")
        with self.assertRaises(L.LinkError) as e:
            self.link.turn({"turn_id": "d4" * 16, "fetch": fetch_url("d4" * 16), "pad": "x" * 20000})
        self.assertEqual(str(e.exception), "too_big", "a turn's body is pulled, never carried in the ticket")

    def test_off_until_turned_on_with_a_site(self):
        for a in ({"mode": "off"}, {"mode": "on", "site": ""}):
            link = L.Link(cfg_for("off", **a))
            with self.assertRaises(L.LinkError) as e:
                link.turn({"turn_id": "e5" * 16, "fetch": fetch_url("e5" * 16)})
            self.assertEqual(str(e.exception), "off")
        self.assertEqual(L.health(cfg_for("off2", mode="off")), {"runner": "off"})
        self.assertEqual(L.health(self.cfg), {"runner": "down"}, "no heartbeat yet")

    def test_busy_when_too_many_turns_wait(self):
        link = L.Link(cfg_for("busy", max_jobs=2))
        link.turn({"turn_id": "01" * 16, "fetch": fetch_url("01" * 16)})
        link.turn({"turn_id": "02" * 16, "fetch": fetch_url("02" * 16)})
        with self.assertRaises(L.LinkError) as e:
            link.turn({"turn_id": "03" * 16, "fetch": fetch_url("03" * 16)})
        self.assertEqual(str(e.exception), "busy")

    def test_status_pages_sealed_events_and_says_the_month(self):
        tid = "f6" * 16
        self.link.turn({"turn_id": tid, "fetch": fetch_url(tid)})
        key = self.link._key()
        evs = [{"i": i, "k": "progress", "t": "step %d ⟦c:12⟧ " % i + "y" * 9000} for i in range(12)]
        L.Events(key).write(L.jobs_dir(self.cfg) / (tid + ".events"), evs)
        raw = (L.jobs_dir(self.cfg) / (tid + ".events")).read_bytes()
        self.assertNotIn(b"step 3", raw, "events are sealed at rest")
        got, offset = [], 0
        while True:
            s = self.link.status({"turn_id": tid, "offset": offset})
            self.assertLessEqual(sum(L.size(e) + 1 for e in s["events"]), L.PAGE_BYTES)
            got += s["events"]
            if s["next"] is None:
                break
            offset = s["next"]
        self.assertEqual([e["i"] for e in got], list(range(12)))
        m = self.link.status({})["money"]
        self.assertEqual(set(m), {"month", "engine_usd", "other_usd", "cap_usd", "as_of"})
        self.assertIsNone(m["cap_usd"], "no engine line of its own unless the business sets one")
        with self.assertRaises(L.LinkError):
            self.link.status({"turn_id": "99" * 16})

    def test_money_leaves_the_websites_own_turns_out_of_other_usd(self):
        led = Ledger(L.ledger_file(self.cfg))
        led.write(op="assistant_turn", ok=True, usd_milli=1500, caller=L.SITE_CALLER)
        led.write(op="assistant_turn", ok=True, usd_milli=250, caller="someone-else")
        m = self.link.money()
        self.assertAlmostEqual(m["engine_usd"], 1.75)
        self.assertAlmostEqual(m["other_usd"], 0.25)

    def test_a_cancel_before_the_runner_means_it_never_runs(self):
        tid = "a7" * 16
        self.link.turn({"turn_id": tid, "fetch": fetch_url(tid)})
        self.assertEqual(self.link.cancel({"turn_id": tid})["state"], "cancelled")
        self.assertFalse(L.write_json_new(L.jobs_dir(self.cfg) / (tid + ".state.json"), {"state": "pulling"}),
                         "the runner's first state loses to the cancel")
        self.assertEqual(self.link.status({"turn_id": tid})["turn"]["state"], "cancelled")


class LedgerTest(unittest.TestCase):
    def test_rows_hold_numbers_and_code_words_only(self):
        r = Ledger.row(op="assistant_turn", caller="Riley Example", error="a long sentence", model="Example Model 9",
                       usd_milli=12, stop="end_turn")
        self.assertEqual(r["caller"], "other")
        self.assertEqual(r["error"], "other")
        self.assertEqual(r["model"], "example-model-9")
        self.assertEqual(Ledger.row(caller=L.SITE_CALLER)["caller"], "app:ai-workspace")

    def test_holds_with_and_without_an_engine_line(self):
        led = Ledger(_env.BASE / "ledger-holds" / "a.jsonl")
        hid, got = led.reserve(1.0, None, 60)
        self.assertEqual(got, 1.0, "no engine line: the turn's own cap bounds it")
        led.release(hid)
        led.write(op="assistant_turn", usd_milli=1800, caller=L.SITE_CALLER)
        h1, g1 = led.reserve(1.0, 2.5, 60)
        self.assertAlmostEqual(g1, 0.7)
        h2, g2 = led.reserve(1.0, 2.5, 60)
        self.assertEqual((h2, g2), ("", 0.0), "held money is spent money")
        self.assertTrue(led.release(h1))
        self.assertAlmostEqual(led.holds_usd(), 0.0)
        _h, g3 = led.reserve(1.0, 2.5, 60, now=time.time() - 10000)
        self.assertAlmostEqual(led.holds_usd(), 0.0, msg="a hold whose turn died expires on its own")


class FakeSite:
    """The website's half for the tests: serves the turn's sealed body once, and answers sealed tool calls."""

    def __init__(self, web_env, turn_id, body, k):
        self.env, self.turn_id, self.body, self.k = web_env, turn_id, body, k
        self.pulled = 0
        self.tools = []

    def __call__(self, method, url, data, timeout, max_bytes):
        u = urllib.parse.urlsplit(url)
        if method == "GET" and u.path.endswith("/turn-fetch/" + self.turn_id) and u.query == "k=" + self.k:
            self.pulled += 1
            if self.pulled > 1:
                return 410, b""                          # the link is single-use
            sealed = self.env.seal(names.HUB_PARTY, "response", self.body, rid=self.turn_id)
            return 200, json.dumps(sealed).encode()
        if method == "POST" and u.path == "/wp-json/zorderz/v1/ai-workspace/tool":
            inner = self.env.open(json.loads(data.decode()), names.HUB_PARTY, "request")
            b = inner["body"]
            self.tools.append(b)
            ok = b["token"] == self.body["tool_token"] and b["turn_id"] == self.turn_id
            reply = {"ok": ok, "text": json.dumps({"people": ["\u27e6e:abc\u27e7"], "count": 2}, ensure_ascii=False)
                     if ok else "no"}
            return 200, json.dumps(self.env.seal(names.HUB_PARTY, "response", reply, rid=inner["rid"])).encode()
        return 404, b""


def keys():
    web_sk, web_pk = keygen()
    hub_sk, hub_pk = keygen()
    return (Envelope(names.WEB_PARTY, web_sk, {names.HUB_PARTY: [hub_pk]}),
            Envelope(names.HUB_PARTY, hub_sk, {names.WEB_PARTY: [web_pk]}))


def turn_body(tid, **over):
    b = {"turn_id": tid, "conv": "", "text": "Who can do the ⟦e:abc⟧ job?", "key": KEY, "workspace": WORKSPACE,
         "site_tools": [{"name": "app_roles_who_can", "description": "Who on the team can do an ability.",
                         "progress": "asking Team Roles", "input_schema": {"type": "object", "properties": {}}}],
         "tool_token": "ab" * 32, "caps": {"usd": 1.0, "seconds": 600}, "headroom_usd": 1.0, "effort": "medium",
         "web": False, "models": {"primary": "example-model-large", "fallback": "example-model-small"},
         "prices": PRICES, "geo": "", "geo_factor": 1.0, "search_usd": 0.01}
    b.update(over)
    return b


class RoundTripTest(unittest.TestCase):
    def test_the_body_and_a_tool_call_cross_sealed_both_ways(self):
        web, hub = keys()
        tid = "b8" * 16
        site = FakeSite(web, tid, turn_body(tid), "cd" * 32)
        link = R.SiteLink(L.settings(cfg_for("rt")), hub, transport=site)
        body = link.pull(fetch_url(tid), tid)
        self.assertEqual(body["key"], KEY)
        with self.assertRaises(R.PullError):
            link.pull(fetch_url(tid), tid)                 # spent
        text, err = link.tool(tid, "ab" * 32, "app_roles_who_can", {"query": "ladder"})
        self.assertFalse(err)
        self.assertIn("⟦e:abc⟧", text)
        self.assertEqual(site.tools[0]["args"], {"query": "ladder"})
        _t, err = link.tool(tid, "ef" * 32, "app_roles_who_can", {})
        self.assertTrue(err, "a wrong token gets nothing")

    def test_a_body_sealed_by_a_stranger_or_for_another_turn_is_refused(self):
        web, hub = keys()
        stranger, _ = keys()
        tid = "c9" * 16
        link = R.SiteLink(L.settings(cfg_for("rt2")), hub, transport=FakeSite(stranger, tid, turn_body(tid), "cd" * 32))
        with self.assertRaises(R.PullError):
            link.pull(fetch_url(tid), tid)
        link = R.SiteLink(L.settings(cfg_for("rt3")), hub,
                          transport=FakeSite(web, tid, turn_body("d0" * 16), "cd" * 32))
        with self.assertRaises(R.PullError):
            link.pull(fetch_url(tid), tid)

    def test_check_body_refuses_what_a_turn_may_not_have(self):
        tid = "e1" * 16
        good = R.check_body(turn_body(tid), tid)
        self.assertEqual(good["plan"].primary, "example-model-large")
        self.assertEqual(good["plan"].fallback, "example-model-small")
        for over in ({"key": ""}, {"workspace": ""}, {"text": ""}, {"conv": "../x"}, {"prices": {}},
                     {"models": {"primary": "bad model!"}}, {"tool_token": "x"}, {"geo": "US EAST"}):
            with self.assertRaises(R.PullError, msg=str(over)):
                R.check_body(turn_body(tid, **over), tid)
        b = R.check_body(turn_body(tid, prices={"example-model-large": PRICES["example-model-large"]},
                                   caps={"usd": 99}), tid)
        self.assertEqual(b["plan"].fallback, "", "a fallback with no prices is never used")
        self.assertEqual(b["usd"], R.TURN_CAP_USD)
        self.assertEqual(R.check_body(turn_body(tid, caps={}), tid)["usd"], 0.0, "no cap given: no money")


class RunnerTest(unittest.TestCase):
    def run_turn(self, name, script, body_over=None, cfg_over=None):
        web, hub = keys()
        cfg = cfg_for(name, **(cfg_over or {}))
        tid = os.urandom(16).hex()
        k = os.urandom(32).hex()
        link = L.Link(cfg)
        link.turn({"turn_id": tid, "fetch": fetch_url(tid, k)})
        site = FakeSite(web, tid, turn_body(tid, **(body_over or {})), k)
        made = []

        def factory(key, b):
            self.assertEqual(key, KEY)
            p = P.FakeProvider(script, models=b["models"], geo=b["geo"])
            made.append(p)
            return p
        agent = R.Agent(cfg, envelope=hub, provider_factory=factory, transport=site, sleep=lambda s: None)
        agent.run(tid)
        return cfg, tid, link, site, made

    def test_a_whole_turn_with_a_site_tool(self):
        script = [P.reply(tools=[("t1", "app_roles_who_can", {"query": "ladder"})], thinking="plan"),
                  P.reply("⟦e:abc⟧ can, and one more person.")]
        cfg, tid, link, site, made = self.run_turn("whole", script)
        s = link.status({"turn_id": tid})
        self.assertEqual(s["turn"]["state"], "done", s)
        self.assertEqual(s["turn"]["model"], "example-model-large")
        answer = "".join(e["t"] for e in s["events"] if e["k"] == "answer")
        self.assertIn("⟦e:abc⟧ can", answer)
        self.assertIn("asking Team Roles", [e["t"] for e in s["events"] if e["k"] == "progress"])
        self.assertEqual(len(site.tools), 1)
        self.assertEqual(s["turn"]["usage"]["tool_calls"], 1)
        self.assertGreater(s["turn"]["usage"]["usd"], 0)
        # the second request carries the first answer's thinking back, as a tool loop needs
        second = made[0].requests[1]["messages"]
        self.assertTrue(any(b.get("type") == "thinking" for m in second for b in m["content"] if isinstance(b, dict)))
        # the engine's own row: the website's caller, its hold released
        rows = [json.loads(x) for x in L.ledger_file(cfg).read_text().splitlines()]
        self.assertEqual(rows[-1]["caller"], "app:ai-workspace")
        self.assertAlmostEqual(Ledger(L.ledger_file(cfg)).holds_usd(), 0.0)
        # nothing in the clear on the hub computer's disk: not the key, not the words
        for root, _d, files in os.walk(_env.BASE / "assistant-whole"):
            for f in files:
                with open(os.path.join(root, f), "rb") as fh:
                    raw = fh.read()
                self.assertNotIn(KEY.encode(), raw, f)
                self.assertNotIn(b"one more person", raw, f)

    def test_a_continued_conversation_drops_web_search_once_a_tool_answered(self):
        script = [P.reply(tools=[("t1", "app_roles_who_can", {})]), P.reply("Two people.")]
        cfg, tid, link, site, made = self.run_turn("web", script, body_over={"web": True})
        self.assertTrue(any(t.get("type") == P.WEB_SEARCH_OK for t in made[0].requests[0]["tools"]),
                        "a new conversation may search the web when the website allows it")
        conv = link.status({"turn_id": tid})["turn"]["conv"]
        web, hub = keys()
        tid2, k2 = os.urandom(16).hex(), os.urandom(32).hex()
        link.turn({"turn_id": tid2, "fetch": fetch_url(tid2, k2)})
        site2 = FakeSite(web, tid2, turn_body(tid2, web=True, conv=conv), k2)
        made2 = []

        def factory(key, b):
            p = P.FakeProvider([P.reply("Still two.")], models=b["models"])
            made2.append(p)
            return p
        R.Agent(cfg, envelope=hub, provider_factory=factory, transport=site2).run(tid2)
        self.assertEqual(link.status({"turn_id": tid2})["turn"]["state"], "done")
        self.assertFalse(any(t.get("type") == P.WEB_SEARCH_OK for t in made2[0].requests[0].get("tools", [])),
                         "business data in the conversation: no web search for the rest of it")
        self.assertEqual(len(made2[0].requests[0]["messages"]), 5, "the stored conversation came back")

    def test_the_turn_is_held_to_the_websites_headroom(self):
        cfg, tid, link, site, made = self.run_turn("headroom", [P.reply("hi")], body_over={"headroom_usd": 0.01})
        s = link.status({"turn_id": tid})
        self.assertEqual(s["turn"]["stop"], "site_cap")
        self.assertEqual(made[0].requests, [], "nothing was sent")

    def test_the_engines_own_line_when_the_business_sets_one(self):
        cfg = cfg_for("cap", month_cap_usd=0.0)
        Ledger(L.ledger_file(cfg))
        cfg2, tid, link, site, made = self.run_turn("cap", [P.reply("hi")], cfg_over={"month_cap_usd": 0.0})
        self.assertEqual(link.status({"turn_id": tid})["turn"]["stop"], "month_cap")

    def test_a_fallback_after_the_main_model_fails_twice(self):
        busy = P.ProviderError("overloaded", 529)
        cfg, tid, link, site, made = self.run_turn("fallback", [busy, busy, P.reply("ok", model="example-model-small")])
        s = link.status({"turn_id": tid})
        self.assertEqual(s["turn"]["state"], "done")
        self.assertTrue(s["turn"]["fallback"])
        self.assertEqual(s["turn"]["model"], "example-model-small")
        self.assertNotIn("thinking", made[0].requests[2], "the fallback call carries no thinking")

    def test_a_cancel_before_the_first_call(self):
        web, hub = keys()
        cfg = cfg_for("cancel")
        tid, k = os.urandom(16).hex(), os.urandom(32).hex()
        link = L.Link(cfg)
        link.turn({"turn_id": tid, "fetch": fetch_url(tid, k)})
        site = FakeSite(web, tid, turn_body(tid), k)
        agent = R.Agent(cfg, envelope=hub, provider_factory=lambda key, b: P.FakeProvider([P.reply("x")]),
                        transport=site)
        (L.jobs_dir(cfg) / (tid + ".stop")).touch()
        agent.run(tid)
        self.assertEqual(link.status({"turn_id": tid})["turn"]["state"], "cancelled")
        self.assertEqual(site.pulled, 0, "a stopped turn is never pulled")

    def test_a_late_ticket_is_never_pulled(self):
        web, hub = keys()
        cfg = cfg_for("late")
        tid, k = os.urandom(16).hex(), os.urandom(32).hex()
        L.Link(cfg).turn({"turn_id": tid, "fetch": fetch_url(tid, k)})
        site = FakeSite(web, tid, turn_body(tid), k)
        agent = R.Agent(cfg, envelope=hub, provider_factory=lambda key, b: P.FakeProvider([]), transport=site,
                        clock=lambda: time.time() + 3600)
        agent.run(tid)
        self.assertEqual(L.Link(cfg).status({"turn_id": tid})["turn"]["state"], "expired")
        self.assertEqual(site.pulled, 0)

    def test_recovery_charges_a_dead_turn_its_hold(self):
        web, hub = keys()
        cfg = cfg_for("recover")
        agent = R.Agent(cfg, envelope=hub, provider_factory=lambda key, b: None, transport=lambda *a: (404, b""))
        tid = os.urandom(16).hex()
        led = Ledger(L.ledger_file(cfg))
        hid, _g = led.reserve(0.8, None, 600)
        L.write_json(L.jobs_dir(cfg) / (tid + ".json"), {"turn_id": tid})
        L.write_json(L.jobs_dir(cfg) / (tid + ".state.json"), {"state": "running", "phase": "model", "pid": 999999999,
                                                               "hold": hid, "reserved_usd": 0.8})
        self.assertEqual(agent.recover(), 1)
        st = L.read_json(L.jobs_dir(cfg) / (tid + ".state.json"))
        self.assertEqual(st["state"], "failed")
        self.assertTrue(st["est"])
        self.assertAlmostEqual(st["usage"]["usd"], 0.8)
        self.assertAlmostEqual(led.month_usd(), 0.8)
        self.assertAlmostEqual(led.holds_usd(), 0.0)


class EngineRulesTest(unittest.TestCase):
    def plan(self):
        return E.Plan("example-model-large", "example-model-small", PRICES)

    def test_the_guard_refuses_what_may_not_be_sent(self):
        body = P.build_body({"model": "example-model-large", "messages": [{"role": "user", "content": "hi"}],
                             "effort": "medium", "allow_web": True})
        self.assertEqual(P.problems(body, {}, True, P.API_URL, ("example-model-large",)), [])
        self.assertIn("web search is off for this turn", P.problems(body, {}, False, P.API_URL, ("example-model-large",)))
        self.assertIn("model", P.problems(body, {}, True, P.API_URL, ("another-model",)))
        self.assertIn("beta header", P.problems(body, {"anthropic-beta": "x"}, True, P.API_URL, ("example-model-large",)))
        self.assertIn("endpoint", P.problems(body, {}, True, "https://api.anthropic.com/v1/messages/batches",
                                             ("example-model-large",)))
        self.assertIn("region", P.problems(body, {}, True, P.API_URL, ("example-model-large",), geo="us"))

    def test_the_provider_refuses_an_answer_from_another_workspace(self):
        def transport(url, headers, data, timeout):
            return 200, {"anthropic-workspace-id": "wrkspc_other"}, json.dumps(
                {"type": "message", "content": [{"type": "text", "text": "hi"}], "usage": {"output_tokens": 3}}).encode()
        p = P.AnthropicProvider(KEY, WORKSPACE, ("example-model-large",), transport=transport)
        with self.assertRaises(P.ProviderError) as e:
            p.complete({"model": "example-model-large", "messages": [{"role": "user", "content": "hi"}]})
        self.assertEqual(e.exception.kind, "wrong_workspace")
        self.assertTrue(e.exception.billed, "it ran, so it is counted")
        with self.assertRaises(P.ProviderError):
            P.AnthropicProvider(KEY, "", ("example-model-large",), transport=transport).complete(
                {"model": "example-model-large", "messages": [{"role": "user", "content": "hi"}]})

    def test_repair_closes_a_broken_conversation(self):
        msgs = [{"role": "user", "content": [{"type": "text", "text": "a"}]},
                {"role": "assistant", "content": [{"type": "tool_use", "id": "t1", "name": "x", "input": {}}]},
                {"role": "user", "content": [{"type": "text", "text": "b"}]}]
        self.assertTrue(E.repair(msgs))
        self.assertEqual([m["role"] for m in msgs], ["user", "assistant", "user", "assistant", "user"])
        self.assertEqual(msgs[2]["content"][0]["type"], "tool_result")

    def test_a_tight_cap_stops_before_the_call(self):
        prov = P.FakeProvider([P.reply("x")])
        eng = E.Engine(prov, E.Registry(), self.plan())
        r = eng.turn({"messages": []}, "hello " * 200, caps=E.Caps(usd=0.001))
        self.assertEqual(r.stop, "money_cap")
        self.assertEqual(prov.requests, [])

    def test_a_model_with_no_prices_is_never_called(self):
        prov = P.FakeProvider([P.reply("x")], models=("example-model-large",))
        eng = E.Engine(prov, E.Registry(), E.Plan("example-model-large", "", {}))
        r = eng.turn({"messages": []}, "hello", caps=E.Caps(usd=1.0))
        self.assertEqual(r.stop, "no_prices")
        self.assertEqual(prov.requests, [])

    def test_a_tool_not_given_to_the_turn_never_runs(self):
        ran = []
        reg = E.Registry([E.Tool("app_x", "x", {"type": "object"}, lambda inp: ran.append(1) or "done")])
        self.assertEqual(reg.run("app_x", {}, set()), ("That tool is not available in this conversation.", True))
        self.assertEqual(ran, [])

    def test_core_instructions_name_no_one(self):
        text = R.instructions({"instructions_file": ""})
        self.assertIn("⟦c:12⟧", text)
        self.assertNotRegex(text, r"(?i)\b(owner|ceo|our company)\b")


if __name__ == "__main__":
    unittest.main()

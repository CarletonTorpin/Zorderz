"""The whole path on invented data: the example packs, a billing export, an import, the ops."""
import json
import unittest

import _env
from zorderz_hub import config, importer, names, store
from zorderz_hub.adapters import billing_csv as B
from zorderz_hub.audit import Audit
from zorderz_hub.llm import LMStudio
from zorderz_hub.pack import load_pack

FULL = {"caller": "ui", "ops": ["health", "vocab", "ask", "run_spec", "run_v2", "drill", "list_jobs", "invoice"],
        "scope": None, "money": True, "by_person": True, "line_items": "all", "kiosk": False, "accounts": True,
        "invoices": "all", "me": "AM", "compare": True}


def setUpModule():
    global CFG, HUB
    config.write_default()
    CFG = config.load()
    CFG["check_keys"] = False
    if not names.ANALYTICS_KEY_FILE.exists():
        B.init_analytics_key()
    imp = names.HUB_HOME / "import"
    imp.mkdir(parents=True, exist_ok=True)
    (imp / "invoices.csv").write_text(_env.INVOICES_CSV)
    from zorderz_hub import places, safety
    places.Updater(CFG, store=places.Store(CFG)).run_once(say=lambda t: None, force=True, by_hand=True)
    safety.Updater(CFG, store=safety.Store(CFG)).run_once(say=lambda t: None, force=True)
    importer.run_import(CFG)
    from zorderz_hub.ops import Hub
    HUB = Hub(CFG, load_pack(CFG["pack_dir"]), LMStudio({"url": "http://127.0.0.1:9"}, token=""),
              Audit(CFG["audit_log"]))


def call(op, access=None, **kw):
    return HUB.dispatch({"op": op, "user": "u1", "access": access or FULL, "today": "2025-09-01", **kw})


class ImportTest(unittest.TestCase):
    def test_store_holds_no_identity(self):
        data = store.load(CFG["store"])
        self.assertEqual(len(data.invoices), 7)
        self.assertEqual(len(data.customers), 6)
        text = json.dumps({k: v.astype(str).values.tolist() for k, v in
                           (("l", data.lines), ("i", data.invoices), ("c", data.customers))})
        for secret in ("Alex Example", "alex@example.com", "555-0101", "100 MAIN ST", "Example Rentals"):
            self.assertNotIn(secret, text)
        self.assertFalse(data.lines.loc[data.lines["description_clean"].str.contains("Deposit"), "work"].any())

    def test_lines_take_the_packs_words(self):
        lines = store.load(CFG["store"]).lines.set_index("invoice_no")
        self.assertEqual(lines.loc["1002", "family"], "Shade sails")
        self.assertIn("Brand: Brightline", lines.loc["1001"].iloc[0]["tags"])
        self.assertEqual(lines.loc["1004", "action"], "Repair / service")

    def test_customers_are_placed_in_the_region(self):
        cust = store.load(CFG["store"]).customers
        west = cust[cust["zip5"].eq("00014")].iloc[0]
        self.assertEqual(west["safety_council"], "Example Region Safety Council")
        north = cust[cust["zip5"].eq("00011")].iloc[0]
        self.assertEqual(north["tax_area"], "Northtown")

    def test_the_store_refuses_identity_columns(self):
        import pandas as pd
        with self.assertRaises(store.StoreError):
            store.check_tables({**{k: pd.DataFrame(columns=v) for k, v in store.REQUIRED.items()},
                                "extra": pd.DataFrame({"email": ["x"]})})

    def test_a_changed_analytics_key_is_refused(self):
        meta = store.read_meta(CFG["store"])
        self.assertTrue(meta.get("analytics_key_fp"))
        import os
        os.environ[names.ANALYTICS_KEY_ENV] = "0" * 64
        try:
            with self.assertRaises(store.KeyChanged):
                importer.run_import(CFG)
        finally:
            os.environ.pop(names.ANALYTICS_KEY_ENV)
            try:
                importer.refused_path(CFG).unlink()
            except OSError:
                pass


class OpsTest(unittest.TestCase):
    def test_vocab_offers_the_regions_place_filters(self):
        v = call("vocab")
        self.assertTrue(v["ok"], v)
        keys = {f["key"]: f for f in v["data"]["place_filters"]}
        self.assertEqual(keys["safety_sites"]["values"], ["Westbrook Ridge"])
        self.assertEqual(keys["safety_sites"]["label"], "Safety site")
        self.assertIn("tax_areas", keys)

    def test_questions_read_by_rules(self):
        r = call("ask", question="revenue by product last year")
        self.assertTrue(r["ok"], r)
        self.assertEqual(r["data"]["spec"]["split"], "product")
        self.assertEqual(r["data"]["result"]["totals"]["revenue"], 1880.0)
        fam = call("ask", question="how many window film jobs")
        self.assertEqual(sorted(fam["data"]["spec"]["filters"]["products"]),
                         ["Privacy window film", "Solar window film", "Window film (type not stated)"])
        self.assertEqual(fam["data"]["result"]["totals"]["jobs"], 2)       # the void invoice never counts

    def test_run_v2(self):
        r = call("run_v2", spec={"measure": "jobs", "split": "area", "periods": ["2025"]})
        self.assertTrue(r["ok"], r)
        self.assertEqual(r["data"]["status"], "chart")

    def test_address_points_for_the_canvassing_app(self):
        r = call("run_v2", spec={"points": {"zip": "00011", "count_only": True}})
        self.assertEqual(r["data"]["status"], "points")
        self.assertEqual(r["data"]["result"], {"count": 72})
        r = call("run_v2", spec={"points": {"name": "MAIN", "zip": "00015", "num": 100}})
        attrs = r["data"]["result"]["features"][0]["attributes"]
        self.assertEqual((attrs["num"], attrs["name"], attrs["suffix"], attrs["zip"]), (100, "MAIN", "ST", "00015"))

    def test_place_outside_the_region_is_unsupported(self):
        r = call("run_v2", spec={"place": {"lat": 10.0, "lng": 10.0}})
        self.assertEqual(r["data"]["status"], "unsupported")
        r = call("run_v2", spec={"place": {"lat": 40.09, "lng": -100.10}})
        self.assertEqual(r["data"]["result"]["zip"], "00015")

    def test_access_floors(self):
        no_money = dict(FULL, money=False)
        r = call("ask", access=no_money, question="revenue by product last year")
        self.assertEqual(r["data"]["spec"]["measure"], "jobs")
        self.assertNotIn("revenue", r["data"]["result"]["totals"])
        bot = dict(FULL, caller="bot")
        self.assertEqual(call("ask", access=bot, question="jobs by town")["error"]["code"], "forbidden")
        self.assertEqual(call("vocab", access={"caller": "nobody"})["error"]["code"], "forbidden")
        public = dict(FULL, ops=["health", "run_v2"], public_only=True, scope=[], money=False, by_person=False)
        r = call("run_v2", access=public, spec={"measure": "jobs", "split": "area"})
        self.assertEqual(r["error"]["code"], "forbidden")
        r = call("run_v2", access=public, spec={"points": {"zip": "00011", "count_only": True}})
        self.assertTrue(r["ok"], r)

    def test_scope_limits_to_own_jobs(self):
        mine = dict(FULL, scope=["AM"], by_person=False, compare=False)
        r = call("ask", access=mine, question="jobs by year")
        self.assertEqual(r["data"]["result"]["totals"]["jobs"], 2)       # AM's two paid jobs (one more is void)

    def test_assistant_ops_without_an_assistant(self):
        relay = {"caller": "app:ai-workspace", "ops": ["health", "assistant_turn"], "scope": [], "money": False,
                 "by_person": False, "line_items": "none", "kiosk": False, "accounts": False, "invoices": "none",
                 "me": None, "compare": False}
        r = call("assistant_turn", access=relay, ticket={})
        self.assertEqual(r["error"]["code"], "assistant_off")
        h = call("health", access=relay)
        self.assertEqual(h["data"]["assistant"], {"runner": "off"})
        self.assertNotIn("data_as_of", h["data"], "the relay learns nothing of the store's")

    def test_assistant_ops_when_on(self):
        relay = {"caller": "app:ai-workspace", "ops": ["health", "assistant_turn", "assistant_status",
                 "assistant_cancel"], "scope": [], "kiosk": False}
        before = HUB.cfg.get("assistant")
        HUB.cfg["assistant"] = dict(before or {}, mode="on", site="https://app.example.com")
        try:
            tid = "ab" * 16
            fetch = "https://app.example.com/wp-json/zorderz/v1/ai-workspace/turn-fetch/%s?k=%s" % (tid, "cd" * 32)
            r = call("assistant_turn", access=relay, turn_id=tid, fetch=fetch)
            self.assertTrue(r["ok"], r)
            self.assertEqual(r["data"]["state"], "queued")
            r = call("assistant_turn", access=relay, turn_id="ef" * 16, fetch=fetch)
            self.assertEqual(r["error"]["code"], "bad_fetch", "a link to another turn is refused")
            s = call("assistant_status", access=relay, turn_id=tid)
            self.assertEqual(s["data"]["turn"]["state"], "queued")
            self.assertIn("other_usd", s["data"]["money"])
            c = call("assistant_cancel", access=relay, turn_id=tid)
            self.assertEqual(c["data"]["state"], "cancelled")
            self.assertEqual(call("assistant_status", access=FULL, turn_id=tid)["error"]["code"], "forbidden",
                             "no other caller ever gets an assistant op")
        finally:
            HUB.cfg["assistant"] = before


if __name__ == "__main__":
    unittest.main()

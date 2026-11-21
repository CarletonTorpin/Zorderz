"""Line search: whole words on request, the loose search unchanged without it, and a search that always answers.

Invented lines only. The texts are put over the imported lines for one test at a time and taken off again, so the
other test modules, which share this scratch store, see the import's own lines.
"""
import unittest

import _env
from zorderz_hub import config, importer, names
from zorderz_hub.adapters import billing_csv as B
from zorderz_hub.audit import Audit
from zorderz_hub.llm import LMStudio
from zorderz_hub.ops import whole_words_pattern
from zorderz_hub.pack import load_pack

# the person at the screen, with line items (what the website seals for someone Charts lets search lines)
PAGE = {"caller": "ui", "ops": ["health", "search_lines", "list_jobs"], "scope": None, "money": True,
        "by_person": True, "line_items": "all", "kiosk": False, "accounts": False, "invoices": "none",
        "me": "AM", "compare": False}

# invoice number -> the line's text for these tests (1006 has none at all)
TEXTS = {
    "1001": "Standard floor stand, painted",
    "1002": "Floor stands (2) for the display",
    "1003": "floor-stand bracket / stand-off kit",
    "1004": "Stand on the floor model, running board",
    "1005": "Understanding the standards",
    "1006": None,
}


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


def search(access=None, **kw):
    return HUB.dispatch({"op": "search_lines", "user": "u1", "access": access or PAGE, "today": "2025-09-01", **kw})


def numbers(reply):
    return [line["invoice_no"] for line in reply["data"]["lines"]]


def hit(term, line):
    pattern = whole_words_pattern(term)
    return pattern is not None and pattern.search(line.casefold()) is not None


class WholeWordsPatternTest(unittest.TestCase):
    def test_a_word_is_found_whole_and_never_inside_a_longer_word(self):
        self.assertTrue(hit("stand", "floor stand, painted"))
        self.assertTrue(hit("stand", "Stand (large)"))
        self.assertFalse(hit("stand", "standard frame"))
        self.assertFalse(hit("stand", "understanding"))
        self.assertFalse(hit("paint", "repaint"))
        self.assertFalse(hit("2x4", "12x48"))
        self.assertTrue(hit("2x4", "panels 2x4, cut"))

    def test_the_last_word_may_carry_a_plain_ending(self):
        for term, line in (("stand", "two stands"), ("box", "three boxes"), ("paint", "painted trim"),
                           ("frame", "framed opening"), ("install", "installing the rail")):
            self.assertTrue(hit(term, line), (term, line))
        self.assertFalse(hit("run", "running board"), "a doubled letter is not a plain ending")
        self.assertFalse(hit("pain", "painted trim"), "only s, es, ed, d and ing")
        self.assertFalse(hit("stands", "one stand"), "an ending is added to the term, never taken off it")

    def test_several_words_are_a_phrase_side_by_side_and_in_order(self):
        for line in ("floor stand", "Floor-Stand kit", "floor / stand", "floor  stands", "a floor\tstand"):
            self.assertTrue(hit("floor stand", line), line)
        self.assertFalse(hit("floor stand", "stand on the floor"), "in order")
        self.assertFalse(hit("floor stand", "floor mounted stand"), "side by side")
        self.assertFalse(hit("floor stand", "floors stand apart"), "only the last word takes an ending")
        self.assertFalse(hit("floor stand", "subfloor stand"))

    def test_a_hyphen_or_a_slash_in_the_term_is_a_gap_too(self):
        self.assertTrue(hit("stand-off", "stand off kit"))
        self.assertTrue(hit("stand off", "stand-off kit"))
        self.assertTrue(hit("bracket/stand", "bracket / stand-off"))

    def test_the_term_is_read_as_written_text_never_as_a_pattern(self):
        self.assertTrue(hit("a.b", "part a.b"))
        self.assertFalse(hit("a.b", "part axb"))
        self.assertTrue(hit("(2)", "stands (2)"))
        self.assertTrue(hit("CAFÉ", "café table"), "letters are compared without their case")
        self.assertIsNone(whole_words_pattern(" - / "), "no word, no pattern")
        self.assertIsNone(whole_words_pattern(""))


class SearchLinesTest(unittest.TestCase):
    def setUp(self):
        work = HUB.data.work
        self.kept = work["description_clean"].copy()
        for i, no in zip(work.index, work["invoice_no"]):
            work.at[i, "description_clean"] = TEXTS[str(no)]

    def tearDown(self):
        HUB.data.work["description_clean"] = self.kept

    def test_whole_words_when_asked(self):
        r = search(text="stand", whole_words=True)
        self.assertTrue(r["ok"], r)
        self.assertEqual(r["data"]["method"], "whole_words")
        self.assertEqual(numbers(r), ["1004", "1003", "1002", "1001"], "newest first; never the line that says standards")
        self.assertEqual(r["data"]["total_matches"], 4)
        phrase = search(text="floor stand", whole_words=True)
        self.assertEqual(numbers(phrase), ["1003", "1002", "1001"], "a phrase: side by side and in order")
        self.assertEqual(numbers(search(text="Paint", whole_words=True)), ["1001"])
        self.assertEqual(numbers(search(text="run", whole_words=True)), [])
        self.assertEqual(numbers(search(text="running", whole_words=True)), ["1004"])

    def test_without_the_switch_the_search_is_the_loose_one_it_was(self):
        r = search(text="stand")
        self.assertEqual(r["data"]["method"], "keyword")
        self.assertEqual(numbers(r), ["1005", "1004", "1003", "1002", "1001"], "inside longer words too")
        self.assertEqual(numbers(search(text="floor stand")), ["1004", "1003", "1002", "1001"],
                         "both words anywhere in the line")
        self.assertEqual(numbers(search(text="run")), ["1004"])
        for not_true in ("yes", 1, "true", False, None):
            self.assertEqual(search(text="stand", whole_words=not_true)["data"]["method"], "keyword", not_true)

    def test_a_line_with_no_text_never_stops_a_search(self):
        work = HUB.data.work
        self.assertTrue(work.loc[work["invoice_no"] == "1006", "description_clean"].isna().all())
        for kw in ({"whole_words": True}, {}):
            r = search(text="display", **kw)
            self.assertTrue(r["ok"], r)
            self.assertEqual(numbers(r), ["1002"])

    def test_no_lines_to_look_through_is_no_match_not_an_error(self):
        for kw in ({"whole_words": True}, {}):
            r = search(text="stand", date_from="2030-01-01", **kw)
            self.assertTrue(r["ok"], r)
            self.assertEqual(r["data"]["lines"], [])
            self.assertEqual(r["data"]["total_matches"], 0)
        nothing = search(text="-/-", whole_words=True)
        self.assertTrue(nothing["ok"], nothing)
        self.assertEqual((nothing["data"]["lines"], nothing["data"]["method"]), ([], "whole_words"))
        self.assertEqual(search(text="   ")["error"]["code"], "bad_request")

    def test_the_limit_caps_the_lines_and_the_count_says_how_many_matched(self):
        r = search(text="stand", whole_words=True, limit=1)
        self.assertEqual(numbers(r), ["1004"])
        self.assertEqual(r["data"]["total_matches"], 4)

    def test_filters_and_dates_still_narrow_the_lines(self):
        r = search(text="stand", whole_words=True, date_from="2025-01-01")
        self.assertEqual(numbers(r), ["1004"])
        r = search(text="stand", whole_words=True, filters={"salespeople": ["JB"]})
        self.assertEqual(numbers(r), ["1002"])

    def test_a_person_kept_to_their_own_jobs_finds_only_their_own_lines(self):
        mine = dict(PAGE, scope=["AM"], by_person=False, line_items="own")
        r = search(access=mine, text="stand", whole_words=True)
        self.assertEqual(numbers(r), ["1004", "1001"])
        r = search(access=mine, text="stand", whole_words=True, filters={"salespeople": ["JB"]})
        self.assertEqual(numbers(r), ["1004", "1001"], "naming someone else changes nothing")

    def test_dollars_only_with_money_and_never_a_customer(self):
        r = search(text="painted", whole_words=True)
        self.assertEqual(r["data"]["lines"][0]["dollars"], 600.0)
        self.assertEqual(sorted(r["data"]["lines"][0]), ["date", "dollars", "family", "invoice_no", "tags", "text", "town"])
        r = search(access=dict(PAGE, money=False), text="painted", whole_words=True)
        self.assertIsNone(r["data"]["lines"][0]["dollars"])

    def test_who_may_search_lines_is_unchanged(self):
        self.assertEqual(search(access=dict(PAGE, line_items="none"), text="stand", whole_words=True)["error"]["code"],
                         "forbidden")
        self.assertEqual(search(access=dict(PAGE, caller="bot"), text="stand", whole_words=True)["error"]["code"],
                         "forbidden")
        self.assertEqual(search(access=dict(PAGE, kiosk=True), text="stand", whole_words=True)["error"]["code"],
                         "forbidden")


if __name__ == "__main__":
    unittest.main()

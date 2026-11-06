"""What a region pack says about itself, and what the hub assumes when it says nothing: no fire season, no hazard
class names, no vector wording and no distance-to-the-coast splits without a declared coastline."""
import datetime as dt
import unittest

import _env  # noqa: F401
from zorderz_hub import names, pack as pack_mod, prospect, region as R, zipfacts as Z
from zorderz_hub.understand.when import read_dates

TODAY = dt.date(2026, 11, 5)


class RegionWordsTest(unittest.TestCase):
    def tearDown(self):
        R.set_current(None)

    def test_no_fire_season_unless_the_region_names_one(self):
        R.set_current(R.Region(".", {}))
        w = read_dates("revenue this fire season", TODAY)
        self.assertEqual(w.items, [])
        self.assertEqual(w.notes, [])
        R.set_current(R.Region(".", {"fire_season": [7, 10]}))
        w = read_dates("revenue this fire season", TODAY)
        self.assertEqual([i.token for i in w.items], ["2026-07..2026-10"])
        self.assertIn("Fire season = July–October, 2026", w.notes)
        R.set_current(R.Region(".", {"fire_season": [13, 2]}))
        self.assertEqual(read_dates("revenue this fire season", TODAY).items, [], "a month that isn't one is refused")

    def test_hazard_and_vector_words_are_the_regions(self):
        R.set_current(R.Region(".", {}))
        self.assertEqual(Z.label_of("fire_vh_pct"), "In the top hazard class")
        self.assertEqual(Z.label_of("vector_q8"), "Watched species found")
        self.assertEqual(prospect.ingredient_label("fire_zone"), "In the top hazard class")
        for key in ("hazard_vh", "hazard_h", "hazard", "vector"):
            self.assertNotIn(key, R.DEFAULT_LABELS)
        R.set_current(R.Region(".", {"labels": {"hazard_vh": "In a Very High zone", "vector": "Example species found"}}))
        self.assertEqual(Z.label_of("fire_vh_pct"), "In a Very High zone")
        self.assertEqual(Z.noun_of("fire_vh_pct"), "share in a Very High zone")
        self.assertEqual(Z.noun_of("vector_q8"), "quarters with example species found")
        self.assertEqual(prospect.ingredient_label("fire_zone"), "In a Very High zone")

    def test_a_hazard_layer_needs_its_classes_named(self):
        reg = R.load({"region_dir": str(names.REGION_DIR)})
        self.assertEqual(Z.hazard_classes(reg.layer("hazard")), {"vh": [3], "h": [2]}, "the example names them")
        bare = R.Region(".", {"layers": {"hazard": {"kind": "file", "path": "x.json"}}})
        self.assertEqual(Z.hazard_classes(bare.layer("hazard")), {"vh": [], "h": []}, "Core assumes no class values")

    def test_coast_splits_need_a_coastline(self):
        p = pack_mod.Pack(place_splits=["tax_area", "coast_band", "coast"])
        R.set_current(R.Region(".", {}))
        self.assertEqual(p.offered_place_splits, ["tax_area"])
        R.set_current(R.Region(".", {"layers": {"coast": {"kind": "file", "path": "coast.json"}}}))
        self.assertEqual(p.offered_place_splits, ["tax_area", "coast_band", "coast"])


if __name__ == "__main__":
    unittest.main()

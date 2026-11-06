import json
import unittest
import urllib.parse

import _env  # noqa: F401
from zorderz_hub import names, region as R


class RegionPackTest(unittest.TestCase):
    def setUp(self):
        self.reg = R.load({"region_dir": str(names.REGION_DIR)})

    def test_example_region_loads(self):
        reg = self.reg
        self.assertTrue(reg.configured)
        self.assertEqual(reg.id, "example")
        for layer in ("addresses", "points", "parcels", "fires", "tax", "coast", "hazard", "safety_sites",
                      "safety_councils"):
            self.assertTrue(reg.has(layer), layer)
            self.assertTrue(reg.layer(layer).path().exists(), layer)
        self.assertTrue(reg.inside(40.09, -100.10))
        self.assertFalse(reg.inside(10.0, 10.0))
        self.assertIsNotNone(reg.file("zip_areas"))
        self.assertEqual(reg.label("safety_site"), "Safety site")

    def test_canonical_fields_map_to_the_layers_own(self):
        lay = self.reg.layer("addresses")
        self.assertEqual(lay.f("NUM"), "house_num")
        self.assertEqual(lay.sql("ZIP = '00011' AND NUM > 100"), "zip_code = '00011' AND house_num > 100")
        a = lay.attrs({"properties": {"house_num": 5, "zip_code": "00011", "other": 1}})
        self.assertEqual((a["NUM"], a["ZIP"], a["other"]), (5, "00011", 1))

    def _q(self, layer, **params):
        lay = self.reg.layer(layer)
        url = lay.url + "/query?" + urllib.parse.urlencode(params)
        return json.loads(R.local_open(url))

    def test_local_layer_answers_like_a_feature_service(self):
        n = self._q("addresses", where="zip_code = '00011'", returnCountOnly="true")["count"]
        self.assertEqual(n, 72)
        ids = self._q("addresses", where="zip_code IN ('00011','00012')", returnIdsOnly="true")["objectIds"]
        self.assertEqual(len(ids), 144)
        page = self._q("addresses", where="1=1", outFields="house_num,street_name", resultOffset=0,
                       resultRecordCount=10, returnGeometry="false")
        self.assertEqual(len(page["features"]), 10)
        self.assertTrue(page["exceededTransferLimit"])
        self.assertEqual(set(page["features"][0]["attributes"]), {"house_num", "street_name"})
        box = json.dumps({"xmin": -100.13, "ymin": 40.12, "xmax": -100.07, "ymax": 40.18})
        inbox = self._q("addresses", where="1=1", geometry=box, geometryType="esriGeometryEnvelope",
                        returnCountOnly="true")["count"]
        self.assertEqual(inbox, 72)
        like = self._q("addresses", where="street_name LIKE '%APL%'", returnCountOnly="true")["count"]
        self.assertEqual(like, 60)
        stats = self._q("fires", where="1=1", outStatistics=json.dumps(
            [{"statisticType": "max", "onStatisticField": "fire_year", "outStatisticFieldName": "newest"}]))
        self.assertEqual(stats["features"][0]["attributes"]["newest"], 2018)

    def test_describe_request(self):
        lay = self.reg.layer("hazard")
        meta = json.loads(R.local_open(lay.url + "?f=json"))
        self.assertIn("hazard_class", [f["name"] for f in meta["fields"]])

    def test_a_missing_file_is_an_error_not_a_guess(self):
        with self.assertRaises(R.RegionError):
            R.local_open("file:" + urllib.parse.quote(str(names.REGION_DIR / "nope.json")) + "/query?where=1%3D1")


if __name__ == "__main__":
    unittest.main()

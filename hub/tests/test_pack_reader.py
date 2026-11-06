import unittest

import _env  # noqa: F401
from zorderz_hub import names
from zorderz_hub.pack import load_pack
from zorderz_hub.understand import terms as T


class ExamplePackTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.pack = load_pack(names.PACK_DIR)

    def test_pack_loads_its_own_words(self):
        p = self.pack
        self.assertEqual(p.company_name, "Example Co")
        self.assertIn("Shade sails", p.products)
        self.assertEqual(p.salespeople, ["AM", "JB", "SR"])
        self.assertEqual(p.zips["00015"], "Midvale")
        self.assertEqual([f["name"] for f in p.families], ["Window film"])
        self.assertIn("tag_brand", p.splits)
        self.assertEqual(p.prospect["origin"]["zip"], "00015")
        self.assertIn("service_install", p.text)

    def test_unit_words_come_from_the_pack(self):
        self.assertEqual(T.unit_words(self.pack), ["caps", "lights", "panels", "sails"])

    def test_no_pack_means_not_configured(self):
        empty = load_pack(None)
        self.assertFalse(empty.configured)

    def test_prompts_are_filled_from_the_pack(self):
        prompt = self.pack.spec_prompt()
        self.assertIn("Example Co", prompt)
        self.assertNotIn("{{", prompt)
        self.assertNotIn("{{", self.pack.caption_prompt())


if __name__ == "__main__":
    unittest.main()

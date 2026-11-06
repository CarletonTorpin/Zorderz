"""Survey drives: no speech model is chosen for the business; an administrator picks one.

With none set a recording is refused with its own reason before anything is downloaded, and the status says which
model (if any) is in use.
"""
import unittest

import _env  # noqa: F401
from zorderz_hub import config, survey


class SurveyModelTest(unittest.TestCase):
    def setUp(self):
        self._tool, self._ok = survey.tool, survey.whisper_ok
        survey.tool = lambda name: "/usr/bin/" + name          # pretend the converter and Whisper are installed
        survey.whisper_ok = lambda: True

    def tearDown(self):
        survey.tool, survey.whisper_ok = self._tool, self._ok

    def jobs(self, model=None):
        cfg = {"survey": {"site": "https://app.example.com"}}
        if model is not None:
            cfg["survey"]["whisper_model"] = model
        return survey.Jobs(cfg, lm=None)

    def test_defaults_name_no_model(self):
        self.assertEqual(survey.DEFAULTS["whisper_model"], "")
        self.assertEqual(config.DEFAULTS["survey"].get("whisper_model", ""), "", "the config names none either")

    def test_no_model_refuses_before_any_download(self):
        j = self.jobs()
        fetched = []
        j._download = lambda *a, **k: fetched.append(a) or 0
        job = {"id": "a" * 24, "user": "wp_1", "kind": "transcribe", "status": "running", "created": 0,
               "audio": {"url": "https://app.example.com/x"}}
        j.run_one(job)
        self.assertEqual(job["status"], "failed")
        self.assertEqual(job["error"], "no_whisper_model")
        self.assertEqual(fetched, [], "nothing is downloaded without a model")
        self.assertIsNone(j.status()["whisper_model"])

    def test_a_chosen_model_is_used_and_reported(self):
        j = self.jobs("  example/speech-model  ")
        self.assertEqual(j._whisper_model(), "example/speech-model")
        self.assertEqual(j.status()["whisper_model"], "example/speech-model")


if __name__ == "__main__":
    unittest.main()

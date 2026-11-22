"""Wortzeiten aus den Zeichen-Zeitmarken von ElevenLabs.

    python -m unittest discover -s test -p "test_*.py"
"""
import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "lib"))

from tts_words import words_from_alignment  # noqa: E402


def alignment(text, dt=0.1):
    return {
        "characters": list(text),
        "character_start_times_seconds": [round(i * dt, 3) for i in range(len(text))],
        "character_end_times_seconds": [round((i + 1) * dt, 3) for i in range(len(text))],
    }


class WordsFromAlignment(unittest.TestCase):
    def test_woerter_mit_start_und_ende(self):
        words = words_from_alignment(alignment("Kein Abo."))
        self.assertEqual([w["text"] for w in words], ["Kein", "Abo."])
        self.assertEqual(words[0], {"text": "Kein", "start": 0.0, "end": 0.4})
        self.assertEqual(words[1], {"text": "Abo.", "start": 0.5, "end": 0.9})

    def test_reine_satzzeichen_sind_keine_woerter(self):
        words = words_from_alignment(alignment("Kasse – Beiträge"))
        self.assertEqual([w["text"] for w in words], ["Kasse", "Beiträge"])

    def test_zeilenumbruch_trennt(self):
        words = words_from_alignment(alignment("SEPA-Mandat\nonline"))
        self.assertEqual([w["text"] for w in words], ["SEPA-Mandat", "online"])


if __name__ == "__main__":
    unittest.main()

"""Vertont den Sprechtext aus content/<lang>.json - je Szene eine MP3 plus die
Zeitpunkte jedes gesprochenen Wortes.

    python lib/tts.py --lang de
    python lib/tts.py --lang de --scene 04-beitraege
    python lib/tts.py --samples de          # Hoerproben mehrerer Stimmen

Die Wortzeiten sind der eigentliche Zweck: die Szenenskripte binden ihre
Bildaktionen an gesprochene Woerter (lib/cue.mjs), nicht an geschaetzte
Sekunden. Bei langen Szenen laufen Schaetzungen sonst um mehrere Sekunden
auseinander - genau daran ist die Vorgaengerpipeline haengengeblieben.

Ergebnis je Sprache in build/<lang>/vo/:
    <szene>.mp3          Sprachspur
    <szene>.words.json   [{ text, start, end }] in Sekunden
    timing.json          Dauer je Szene und Gesamtlaenge
"""

import argparse
import asyncio
import json
import os
import sys

import edge_tts

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Kandidaten fuer die Stimmabnahme. Alle sind neuronale Microsoft-Stimmen und
# ueber edge-tts kostenlos nutzbar; die "Multilingual"-Varianten sprechen
# Fremdwoerter (Nextcloud, SEPA, pain.008) merklich sauberer aus.
SAMPLE_VOICES = {
    "de": [
        "de-DE-SeraphinaMultilingualNeural",
        "de-DE-KatjaNeural",
        "de-DE-AmalaNeural",
        "de-DE-FlorianMultilingualNeural",
    ],
    "en": [
        "en-US-AvaMultilingualNeural",
        "en-GB-SoniaNeural",
        "en-US-EmmaMultilingualNeural",
        "en-US-AndrewMultilingualNeural",
    ],
}


def content(lang):
    with open(os.path.join(BASE, "content", f"{lang}.json"), encoding="utf-8") as f:
        return json.load(f)


async def speak(text, voice, rate, pitch, mp3_path):
    """Erzeugt die MP3 und sammelt dabei die Wortgrenzen ein.

    edge-tts liefert Offsets in 100-Nanosekunden-Einheiten; hier wird direkt in
    Sekunden umgerechnet, damit die Szenenskripte nichts umrechnen muessen.
    """
    # boundary="WordBoundary" ist Pflicht: edge-tts liefert seit 7.x sonst nur
    # noch einen SentenceBoundary je Satz, und damit laesst sich keine Bildaktion
    # auf ein einzelnes Wort legen.
    communicate = edge_tts.Communicate(text, voice, rate=rate, pitch=pitch, boundary="WordBoundary")
    words = []
    with open(mp3_path, "wb") as out:
        async for chunk in communicate.stream():
            if chunk["type"] == "audio":
                out.write(chunk["data"])
            elif chunk["type"] == "WordBoundary":
                start = chunk["offset"] / 10_000_000
                words.append({
                    "text": chunk["text"],
                    "start": round(start, 3),
                    "end": round(start + chunk["duration"] / 10_000_000, 3),
                })
    return words


async def run_language(lang, only_scene=None):
    cfg = content(lang)
    out_dir = os.path.join(BASE, "build", lang, "vo")
    os.makedirs(out_dir, exist_ok=True)

    timing = {}
    timing_path = os.path.join(out_dir, "timing.json")
    if os.path.isfile(timing_path):
        with open(timing_path, encoding="utf-8") as f:
            timing = json.load(f).get("scenes", {})

    print(f'Stimme {cfg["voice"]}, Tempo {cfg["rate"]}')
    for scene in cfg["scenes"]:
        if only_scene and scene["id"] != only_scene:
            continue
        mp3 = os.path.join(out_dir, scene["id"] + ".mp3")
        words = await speak(scene["vo"], cfg["voice"], cfg["rate"], cfg.get("pitch", "+0Hz"), mp3)
        if not words:
            sys.exit(f'Keine Wortzeiten fuer {scene["id"]} - Text leer oder Stimme ohne WordBoundary?')

        with open(os.path.join(out_dir, scene["id"] + ".words.json"), "w", encoding="utf-8") as f:
            json.dump(words, f, ensure_ascii=False, indent="\t")

        dauer = words[-1]["end"]
        timing[scene["id"]] = {"duration": round(dauer, 2), "words": len(words)}
        print(f'  {scene["id"]:<16} {dauer:6.2f} s  {len(words):3d} Woerter')

    gesamt = sum(v["duration"] for v in timing.values())
    with open(timing_path, "w", encoding="utf-8") as f:
        json.dump({"lang": lang, "voice": cfg["voice"], "rate": cfg["rate"],
                   "scenes": timing, "total": round(gesamt, 2)}, f, ensure_ascii=False, indent="\t")

    minuten, sekunden = divmod(gesamt, 60)
    print(f"  {'GESAMT':<16} {gesamt:6.2f} s  = {int(minuten)}:{sekunden:04.1f} (ohne Blenden)")


async def run_samples(lang):
    """Hoerproben: derselbe Satz mit allen Kandidaten, zum Vergleichen."""
    cfg = content(lang)
    out_dir = os.path.join(BASE, "build", "stimmproben")
    os.makedirs(out_dir, exist_ok=True)

    print(f'Hoerproben {lang} -> {out_dir}')
    for voice in SAMPLE_VOICES[lang]:
        kurz = voice.split("-")[-1].replace("Neural", "").replace("Multilingual", "-multi")
        mp3 = os.path.join(out_dir, f"{lang}-{kurz}.mp3")
        words = await speak(cfg["probe"], voice, cfg["rate"], cfg.get("pitch", "+0Hz"), mp3)
        print(f'  {os.path.basename(mp3):<28} {words[-1]["end"]:5.2f} s  {voice}')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--lang", default="de", choices=["de", "en"])
    parser.add_argument("--scene", help="nur diese Szene neu vertonen")
    parser.add_argument("--samples", metavar="LANG", choices=["de", "en"],
                        help="Hoerproben mehrerer Stimmen statt der Szenen")
    args = parser.parse_args()

    if args.samples:
        asyncio.run(run_samples(args.samples))
    else:
        asyncio.run(run_language(args.lang, args.scene))


if __name__ == "__main__":
    main()

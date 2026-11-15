"""Wortzeiten aus den Zeichen-Zeitmarken von ElevenLabs (with-timestamps).

ElevenLabs liefert je Zeichen Start und Ende. Die Szenenskripte brauchen
Woerter ({ text, start, end }) wie zuvor von edge-tts: Woerter sind Folgen
ohne Leerraum; Folgen ganz ohne Buchstaben/Ziffern (Gedankenstrich) zaehlen
nicht, sonst verschiebt sich die Wortzaehlung in lib/cue.mjs.
"""


def words_from_alignment(alignment):
    chars = alignment["characters"]
    starts = alignment["character_start_times_seconds"]
    ends = alignment["character_end_times_seconds"]
    words = []
    current = None
    for ch, s, e in zip(chars, starts, ends):
        if ch.isspace():
            current = None
            continue
        if current is None:
            current = {"text": "", "start": s, "end": e}
            words.append(current)
        current["text"] += ch
        current["end"] = e
    return [
        {"text": w["text"], "start": round(w["start"], 3), "end": round(w["end"], 3)}
        for w in words
        if any(c.isalnum() for c in w["text"])
    ]

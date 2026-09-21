"""Slice a wake-server session recording into wake-word training clips.

    python scripts/lbox_wake_clips.py --pcm D:/lbox/wake-word/clips/LJBOX2-session.pcm --out D:/lbox/wake-word/clips/positive

Reads the raw int16 mono 16 kHz take written by `lbox_wake_server.py --collect`,
transcribes it with faster-whisper (word timestamps), and writes one 2.0 s WAV per
spoken "Hi L-BOX" (any spelling whisper produces: "Hi L box", "Hi Ilbak", "Hail box"...)
centred on the phrase. Clips that whisper hears as something else are listed, not saved.
Feed the folder to the openWakeWord Colab notebook as extra positive samples.
"""
import argparse
import os
import re
import wave

import numpy as np
from faster_whisper import WhisperModel

PHRASE = re.compile(r"\b(hi|hey|hai|high|hail)\b[ ,]*(l|el|il|al|ill)?[ -]*b[aeo][ckx]", re.I)
RATE = 16000


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--pcm", required=True)
    p.add_argument("--out", required=True)
    p.add_argument("--model", default="small")
    p.add_argument("--seconds", type=float, default=2.0, help="clip length")
    args = p.parse_args()

    audio = np.fromfile(args.pcm, dtype=np.int16)
    print(f"take: {len(audio) / RATE:.1f} s")
    tmp = args.pcm + ".wav"
    with wave.open(tmp, "wb") as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(RATE); w.writeframes(audio.tobytes())

    model = WhisperModel(args.model, device="cpu", compute_type="int8")
    segments, _ = model.transcribe(tmp, language="en", beam_size=1, vad_filter=True, word_timestamps=True)
    os.makedirs(args.out, exist_ok=True)
    n = 0
    for seg in segments:
        words = seg.words or []
        text = " ".join(w.word.strip() for w in words)
        # walk the words; a match starting at word i spans up to 3 words
        i = 0
        while i < len(words):
            span = " ".join(w.word.strip() for w in words[i:i + 3])
            m = PHRASE.search(span)
            if m and PHRASE.match(span.lstrip(" ,")):
                start, end = words[i].start, words[min(i + 2, len(words) - 1)].end
                mid = (start + end) / 2
                a = int(max(0, mid - args.seconds / 2) * RATE)
                clip = audio[a:a + int(args.seconds * RATE)]
                if len(clip) < int(args.seconds * RATE):
                    clip = np.pad(clip, (0, int(args.seconds * RATE) - len(clip)))
                path = os.path.join(args.out, f"hi_lbox_{n:03d}.wav")
                with wave.open(path, "wb") as w:
                    w.setnchannels(1); w.setsampwidth(2); w.setframerate(RATE); w.writeframes(clip.tobytes())
                print(f"clip {n:03d}  {start:6.1f}s  {span!r}")
                n += 1
                i += 3
            else:
                i += 1
        if not PHRASE.search(text):
            print(f"skip  {seg.start:6.1f}s  {text!r}")
    print(f"saved {n} clips to {args.out}")


if __name__ == "__main__":
    main()

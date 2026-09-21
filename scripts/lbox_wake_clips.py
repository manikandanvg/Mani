"""Slice a wake-server session recording into wake-word training clips.

    python scripts/lbox_wake_clips.py --pcm D:/lbox/wake-word/clips/LJBOX2-session.pcm --out D:/lbox/wake-word/clips/positive

Reads the raw int16 mono 16 kHz take written by `lbox_wake_server.py --collect`,
finds every burst of voice by energy, cuts a 2 s window around each burst and
transcribes that window alone with faster-whisper (short windows are reliable;
whole-file passes shift and drop phrases). Windows whisper hears as the phrase,
in any spelling ("Hey, Elbox", "HAI HILBOX", "Hi, Ilbak", "Hail box"), are saved
as hi_lbox_NNN.wav for the openWakeWord notebook (positive_train folder).
"""
import argparse
import os
import re
import wave

import numpy as np
from faster_whisper import WhisperModel

RATE = 16000
FRAME = 1280  # 80 ms
# Fuse the letters and match the sound loosely: (h|p) + vowel + up to 4 letters + b + vowels + x/ck/k/z.
FUSED = re.compile(r"(h|p)(ai|ey|ei|ay|igh|i|a)[a-z]{0,4}b[aeiouy]{0,2}(x|cks|ck|ks|k|z|cs)")


def is_phrase(text: str) -> bool:
    return FUSED.search(re.sub(r"[^a-z]", "", text.lower())) is not None


def write_wav(path: str, clip: np.ndarray) -> None:
    with wave.open(path, "wb") as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(RATE); w.writeframes(clip.astype(np.int16).tobytes())


def bursts(audio: np.ndarray, ratio: float, gap_s: float = 0.35, min_s: float = 0.25):
    """Start/end sample indexes of voice bursts: frame rms above ratio x median floor."""
    n = len(audio) // FRAME
    rms = np.sqrt(np.mean(audio[: n * FRAME].reshape(n, FRAME).astype(np.float32) ** 2, axis=1))
    floor = max(float(np.median(rms)), 100.0)
    active = rms > ratio * floor
    out, start, last = [], None, None
    for i, a in enumerate(active):
        if a:
            if start is None:
                start = i
            last = i
        elif start is not None and (i - last) * FRAME / RATE > gap_s:
            out.append((start, last))
            start = None
    if start is not None:
        out.append((start, last))
    return [(s * FRAME, (e + 1) * FRAME) for s, e in out if (e + 1 - s) * FRAME / RATE >= min_s], floor


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--pcm", required=True)
    p.add_argument("--out", required=True)
    p.add_argument("--model", default="small")
    p.add_argument("--seconds", type=float, default=2.0, help="clip length")
    p.add_argument("--ratio", type=float, default=1.8, help="burst = rms above this x the room floor")
    args = p.parse_args()

    audio = np.fromfile(args.pcm, dtype=np.int16)
    regions, floor = bursts(audio, args.ratio)
    print(f"take: {len(audio) / RATE:.1f} s, floor rms {floor:.0f}, {len(regions)} voice bursts")
    model = WhisperModel(args.model, device="cpu", compute_type="int8")
    os.makedirs(args.out, exist_ok=True)
    tmp = args.pcm + ".win.wav"
    half = int(args.seconds * RATE / 2)
    n = 0
    for s, e in regions:
        mid = (s + e) // 2
        a = max(0, mid - half)
        clip = audio[a:a + 2 * half]
        if len(clip) < 2 * half:
            clip = np.pad(clip, (0, 2 * half - len(clip)))
        write_wav(tmp, clip)
        segs, _ = model.transcribe(tmp, language="en", beam_size=1, vad_filter=False, condition_on_previous_text=False)
        text = " ".join(x.text.strip() for x in segs).strip()
        if is_phrase(text):
            path = os.path.join(args.out, f"hi_lbox_{n:03d}.wav")
            write_wav(path, clip)
            print(f"clip {n:03d}  {s / RATE:6.1f}s  {text!r}")
            n += 1
        else:
            print(f"skip  {s / RATE:6.1f}s  {text!r}")
    print(f"saved {n} clips to {args.out}")


if __name__ == "__main__":
    main()

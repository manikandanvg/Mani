"""Synthetic wake-word clips with edge-tts (Microsoft neural voices, free, needs internet).

    python scripts/lbox_wake_synth.py --config D:/lbox/wake-word/hi_lbox_training_config.yml \
        --out D:/lbox/wake-word/clips/synth --ffmpeg <path or "ffmpeg">

Writes  <out>/positive/*.wav  (every target_phrase x voice x rate)
        <out>/negative/*.wav  (other short phrases, same voices)  - 16 kHz mono 16-bit, 2 s.
Indian-English and Tamil voices are the point: the box lives in Tamil Nadu shops.
"""
import argparse
import os
import shutil
import subprocess
import sys
import tempfile

VOICES = [
    "en-IN-NeerjaNeural", "en-IN-PrabhatNeural",
    "en-US-AriaNeural", "en-US-GuyNeural", "en-US-JennyNeural",
    "en-GB-SoniaNeural", "en-GB-RyanNeural", "en-AU-NatashaNeural",
    "ta-IN-PallaviNeural", "ta-IN-ValluvarNeural",
]
RATES = ["-15%", "+0%", "+20%"]
NEGATIVES = [
    "hey jarvis", "hello", "hi there", "how are you", "good morning", "thank you",
    "what is the gold rate", "one two three", "open the shop", "hi", "box", "the box is here",
    "hello box", "hey google", "alexa", "call me later", "please wait", "welcome to lord jewellers",
    "silver price today", "nice to meet you", "customer is waiting", "hey buddy", "high five",
    "hi boss", "hey lucky", "hello sir", "hi madam", "help me", "hail a taxi", "pay the bill",
]


def phrases_from_yaml(path):
    out, in_list = [], False
    for line in open(path, encoding="utf-8"):
        s = line.strip()
        if s.startswith("target_phrase:"):
            in_list = True
            continue
        if in_list:
            if s.startswith("- "):
                out.append(s[2:].strip().strip('"'))
            elif s and not s.startswith("#"):
                break
    return out


def tts(text, voice, rate, mp3, python=sys.executable):
    cmd = [python, "-m", "edge_tts", "--voice", voice, "--rate=" + rate, "--text", text, "--write-media", mp3]
    return subprocess.run(cmd, capture_output=True, timeout=60).returncode == 0 and os.path.getsize(mp3) > 100


def to_wav(ffmpeg, mp3, wav):
    # 2 s window, speech padded/cropped by ffmpeg: apad then trim keeps short phrases whole
    cmd = [ffmpeg, "-y", "-loglevel", "error", "-i", mp3, "-af", "apad=whole_dur=2,atrim=0:2",
           "-ar", "16000", "-ac", "1", "-sample_fmt", "s16", wav]
    return subprocess.run(cmd, capture_output=True, timeout=60).returncode == 0


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--config", required=True)
    p.add_argument("--out", required=True)
    p.add_argument("--ffmpeg", default=os.environ.get("LBOX_FFMPEG_BIN", "ffmpeg"))
    p.add_argument("--voices", default=",".join(VOICES))
    args = p.parse_args()
    phrases = phrases_from_yaml(args.config)
    voices = [v for v in args.voices.split(",") if v]
    print(f"{len(phrases)} phrases x {len(voices)} voices x {len(RATES)} rates")
    tmp = tempfile.mkdtemp()
    for kind, texts in (("positive", phrases), ("negative", NEGATIVES)):
        d = os.path.join(args.out, kind)
        os.makedirs(d, exist_ok=True)
        n = 0
        for text in texts:
            for voice in voices:
                for rate in (RATES if kind == "positive" else RATES[1:2]):
                    mp3 = os.path.join(tmp, "x.mp3")
                    if os.path.exists(mp3):
                        os.remove(mp3)
                    if not tts(text, voice, rate, mp3):
                        print(f"  tts failed: {voice} {text!r}")
                        continue
                    wav = os.path.join(d, f"{kind}_{n:04d}.wav")
                    if to_wav(args.ffmpeg, mp3, wav):
                        n += 1
        print(f"{kind}: {n} clips in {d}")
    shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    main()

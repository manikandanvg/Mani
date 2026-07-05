"""L-BOX standby-mic assistant — the "Hi L-BOX" experience, runnable on a PC today.

Listens on the microphone continuously (openWakeWord, free/self-hosted). When the
wake word fires: chime, record the question, POST it to the L-BOX cloud
(/api/device/v1/ai/voice → Whisper → intent → TTS) and play the spoken answer.

This is the same loop the real box will run: on ESP32-S3 the wake word moves
on-device (microWakeWord); on classic ESP32 the box streams audio to this same
server-side detector. The cloud endpoints don't change either way.

    python scripts/lbox_wake_listener.py --serial LBX-SIM-0001
    python scripts/lbox_wake_listener.py --serial LBX-SIM-0001 --model hey_jarvis --threshold 0.5

Default wake model is "hey_jarvis" (ships with openWakeWord) as a stand-in until
a custom "hi_lbox" model is trained (see README — free via openWakeWord's
synthetic-training Colab). Drop hi_lbox.onnx next to this script and pass
--model hi_lbox.
"""
import argparse
import io
import json
import struct
import sys
import time
import wave
from pathlib import Path

import numpy as np
import requests
import sounddevice as sd
from openwakeword.model import Model

RATE = 16000
CHUNK = 1280  # 80ms frames — what openWakeWord expects


def wav_bytes(frames: np.ndarray) -> bytes:
    buf = io.BytesIO()
    with wave.open(buf, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(RATE)
        w.writeframes(frames.astype(np.int16).tobytes())
    return buf.getvalue()


def beep(freq=1200, ms=120):
    if sys.platform == "win32":
        import winsound

        winsound.Beep(freq, ms)


def play_wav(data: bytes):
    if sys.platform == "win32":
        import winsound

        winsound.PlaySound(data, winsound.SND_MEMORY)


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--serial", required=True, help="device serial paired via lbox:simulate")
    p.add_argument("--api", default=None, help="device API base URL")
    p.add_argument("--model", default="hey_jarvis", help="openWakeWord model name or path to .onnx")
    p.add_argument("--threshold", type=float, default=0.5)
    p.add_argument("--record-seconds", type=float, default=4.0, help="question window after wake")
    p.add_argument("--storage", default=str(Path(__file__).resolve().parent.parent / "storage" / "app"))
    args = p.parse_args()

    token_file = Path(args.storage) / "lbox-sim" / f"{args.serial}.json"
    if not token_file.exists():
        sys.exit(f"No token for {args.serial} — run: php artisan lbox:simulate {args.serial} --code=<pairing>")
    token = json.loads(token_file.read_text())["token"]

    api = (args.api or "http://lordicl.test/api/device/v1").rstrip("/")

    # Downloads the tiny wake model on first run (openwakeword's model zoo).
    import openwakeword

    openwakeword.utils.download_models([args.model] if not args.model.endswith(".onnx") else [])
    oww = Model(wakeword_models=[args.model], inference_framework="onnx")

    print(f"🎙  Standby mic ON — say the wake word ({args.model}), Ctrl+C to stop.")
    with sd.InputStream(samplerate=RATE, channels=1, dtype="int16", blocksize=CHUNK) as stream:
        while True:
            frame, _ = stream.read(CHUNK)
            scores = oww.predict(np.frombuffer(frame, dtype=np.int16))
            score = max(scores.values())
            if score < args.threshold:
                continue

            print(f"\n✨ Wake word! (score {score:.2f}) — ask your question...")
            beep()
            oww.reset()

            chunks = []
            need = int(RATE * args.record_seconds / CHUNK)
            for _ in range(need):
                q, _ = stream.read(CHUNK)
                chunks.append(np.frombuffer(q, dtype=np.int16))
            question = np.concatenate(chunks)
            beep(800, 90)

            print("   ⏳ thinking...")
            try:
                res = requests.post(
                    f"{api}/ai/voice",
                    headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
                    files={"audio": ("question.wav", wav_bytes(question), "audio/wav")},
                    timeout=180,
                )
                body = res.json()
            except Exception as e:  # noqa: BLE001
                print(f"   ⚠ request failed: {e}")
                continue

            if res.status_code != 200:
                print(f"   🤷 {body.get('message', res.status_code)}")
                continue

            print(f"   🎙  Heard : {body.get('transcript')}")
            print(f"   🤖 Answer: {body.get('answer')}")
            if body.get("audio_url"):
                try:
                    play_wav(requests.get(body["audio_url"], timeout=30).content)
                except Exception:  # noqa: BLE001
                    pass
            oww.reset()
            time.sleep(0.3)


if __name__ == "__main__":
    main()

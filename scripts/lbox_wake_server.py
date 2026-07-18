"""L-BOX wake server — server-side "Hi L-BOX" for classic ESP32-WROOM boxes.

Each box opens one TCP connection, sends a one-line JSON handshake
({"serial": ..., "token": ..., "rate": 16000}) and then streams raw 16-bit
mono PCM frames from its INMP441 mic. This daemon runs openWakeWord per
connection; when the wake word fires it records the next few seconds as the
question, POSTs it to /api/device/v1/ai/voice (Whisper → intent → TTS) and
sends one JSON line back ({"answer": ..., "audio_url": ...}) — the box then
streams the WAV to its speaker.

    python scripts/lbox_wake_server.py                       # hey_jarvis stand-in
    python scripts/lbox_wake_server.py --model scripts/hi_lbox.onnx --threshold 0.55

Runs forever; one instance serves every box on the LAN. Free + self-hosted.
"""
import argparse
import asyncio
import io
import json
import wave

import numpy as np
import requests
from openwakeword.model import Model

FRAME_SAMPLES = 1280  # 80ms @ 16k — what openWakeWord expects
FRAME_BYTES = FRAME_SAMPLES * 2


def wav_bytes(frames: np.ndarray, rate: int = 16000) -> bytes:
    buf = io.BytesIO()
    with wave.open(buf, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes(frames.astype(np.int16).tobytes())
    return buf.getvalue()


async def handle_box(reader: asyncio.StreamReader, writer: asyncio.StreamWriter, args):
    peer = writer.get_extra_info("peername")
    try:
        hello = json.loads((await asyncio.wait_for(reader.readline(), 10)).decode())
        serial, token = hello["serial"], hello["token"]
    except Exception:  # noqa: BLE001
        writer.close()
        return

    print(f"[wake] {serial} connected from {peer}")
    oww = Model(wakeword_models=[args.model], inference_framework="onnx")
    loop = asyncio.get_running_loop()

    try:
        while True:
            frame = await reader.readexactly(FRAME_BYTES)
            audio = np.frombuffer(frame, dtype=np.int16)
            scores = await loop.run_in_executor(None, oww.predict, audio)
            if max(scores.values()) < args.threshold:
                continue

            print(f"[wake] {serial} ✨ wake word (score {max(scores.values()):.2f}) — recording question")
            oww.reset()
            writer.write(b'{"event":"wake"}\n')   # box chimes / lights the ring
            await writer.drain()

            need = int(16000 * args.record_seconds) * 2
            question = np.frombuffer(await reader.readexactly(need), dtype=np.int16)

            def ask():
                return requests.post(
                    f"{args.api.rstrip('/')}/ai/voice",
                    headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
                    files={"audio": ("q.wav", wav_bytes(question), "audio/wav")},
                    timeout=180,
                )

            res = await loop.run_in_executor(None, ask)
            body = res.json() if res.content else {}
            reply = {
                "event": "answer",
                "transcript": body.get("transcript"),
                "answer": body.get("answer") or body.get("message"),
                "audio_url": body.get("audio_url"),
                "action": body.get("action"),   # e.g. volume_up / volume_down — box applies locally
            }
            print(f"[wake] {serial} 🎙 {reply['transcript']!r} → 🤖 {reply['answer']!r}")
            writer.write((json.dumps(reply) + "\n").encode())
            await writer.drain()
            oww.reset()
    except (asyncio.IncompleteReadError, ConnectionResetError):
        print(f"[wake] {serial} disconnected")
    finally:
        writer.close()


async def main():
    p = argparse.ArgumentParser()
    p.add_argument("--host", default="0.0.0.0")
    p.add_argument("--port", type=int, default=8765)
    p.add_argument("--model", default="hey_jarvis", help="openWakeWord model name or .onnx path")
    p.add_argument("--threshold", type=float, default=0.5)
    p.add_argument("--record-seconds", type=float, default=4.0)
    p.add_argument("--api", default="http://192.168.1.2/lordicl-next/public/api/device/v1")
    args = p.parse_args()

    if not args.model.endswith(".onnx"):
        import openwakeword

        openwakeword.utils.download_models([args.model])

    server = await asyncio.start_server(lambda r, w: handle_box(r, w, args), args.host, args.port)
    print(f"[wake] listening on {args.host}:{args.port} (model={args.model}, threshold={args.threshold})")
    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())

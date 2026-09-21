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
import os
import asyncio
import io
import json
import wave

import numpy as np
import requests
from openwakeword.model import Model


class LrDetector:
    """A detector trained by scripts/lbox_wake_train.py (.npz): openWakeWord's own
    streaming embeddings + a logistic layer. Same predict()/reset() as Model."""

    def __init__(self, path):
        from openwakeword.utils import AudioFeatures
        d = np.load(path)
        self.w, self.b = d["coef"], float(d["intercept"])
        self.mu, self.sd, self.win = d["mu"], d["sd"], int(d["win"])
        self.name = str(d["name"])
        self.af = AudioFeatures()

    def predict(self, audio):
        self.af(audio)
        x = np.asarray(self.af.get_features(self.win)).reshape(-1)
        if x.shape[0] != len(self.mu):
            return {self.name: 0.0}
        z = float(np.dot((x - self.mu) / self.sd, self.w) + self.b)
        return {self.name: 1.0 / (1.0 + np.exp(-z))}

    def reset(self):
        self.af.reset()

try:  # first run on a fresh host: fetch the shared feature models + the built-in wake words
    import openwakeword.utils as _oww_utils
    _oww_utils.download_models()
except Exception as _e:  # noqa: BLE001 - offline host with models already present is fine
    print(f"[wake] model download skipped: {_e}")

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
    oww = LrDetector(args.model) if args.model.endswith(".npz") else Model(wakeword_models=[args.model], inference_framework="onnx")
    loop = asyncio.get_running_loop()

    try:
        n_frames, best, above, n_seen = 0, 0.0, 0, 0
        dump = []   # --dump: first N seconds of the raw stream to a WAV for offline checks
        while True:
            frame = await reader.readexactly(FRAME_BYTES)
            audio = np.frombuffer(frame, dtype=np.int16)
            if args.dump:
                # rolling: a new N-second file every N seconds, five files kept (-0..-4)
                dump.append(audio)
                if len(dump) * FRAME_SAMPLES >= int(16000 * args.dump):
                    n_dump = getattr(args, "_n_dump", 0)
                    path = f"lbox-mic-{serial}-{n_dump % 5}.wav"
                    with open(path, "wb") as f:
                        f.write(wav_bytes(np.concatenate(dump)))
                    args._n_dump = n_dump + 1
                    dump = []
            scores = await loop.run_in_executor(None, oww.predict, audio)
            top = max(scores.values())
            if args.collect:
                # Continuous take as well: <dir>/<serial>-session.pcm (raw int16 mono 16 kHz)
                # -> scripts/lbox_wake_clips.py slices it into clips by whisper timestamps.
                import os
                os.makedirs(args.collect, exist_ok=True)
                with open(os.path.join(args.collect, f"{serial}-session.pcm"), "ab") as f:
                    f.write(frame)
                # Training-clip collector: a frame 4x above the running floor starts a
                # 2.5 s clip (0.4 s of pre-roll kept) -> <dir>/<serial>-<n>.wav. Say the
                # wake phrase with a pause between repeats; verify clips with lbox_stt.py.
                rms = float(np.sqrt(np.mean(audio.astype(np.float32) ** 2)))
                col = getattr(args, "_col", None)
                if col is None:
                    col = args._col = {"floor": rms, "pre": [], "clip": None, "n": 0}
                if col["clip"] is None:
                    col["floor"] = 0.98 * col["floor"] + 0.02 * rms
                    col["pre"] = (col["pre"] + [audio])[-5:]
                    if rms > 4 * max(col["floor"], 150):
                        col["clip"] = list(col["pre"])
                else:
                    col["clip"].append(audio)
                    if len(col["clip"]) * FRAME_SAMPLES >= int(16000 * 2.9):
                        import os
                        os.makedirs(args.collect, exist_ok=True)
                        path = os.path.join(args.collect, f"{serial}-{col['n']:03d}.wav")
                        with open(path, "wb") as f:
                            f.write(wav_bytes(np.concatenate(col["clip"])))
                        print(f"[wake] {serial} clip saved {path}")
                        col["n"] += 1
                        col["clip"] = None
                        col["pre"] = []
            if args.debug:
                # Every ~2 s: what is the box sending (RMS/peak) and how close is the model.
                n_frames += 1
                best = max(best, top)
                if n_frames % 25 == 0:
                    rms = float(np.sqrt(np.mean(audio.astype(np.float32) ** 2)))
                    print(f"[wake] {serial} rms={rms:.0f} peak={int(np.abs(audio).max())} best_score_2s={best:.2f}")
                    best = 0.0
            # Fire only after `min_frames` consecutive frames above threshold: a single
            # 80 ms spike (door knock, a clipped syllable) must not wake the box.
            above = above + 1 if top >= args.threshold else 0
            n_seen = n_seen + 1
            if above < args.min_frames or n_seen < 25:   # 25 frames = 2 s warm-up after connect
                continue
            above = 0

            print(f"[wake] {serial} WAKE WORD (score {max(scores.values()):.2f}) - recording question")
            oww.reset()
            writer.write(b'{"event":"wake"}\n')   # box chimes / lights the ring
            await writer.drain()

            # Record the question: up to record_seconds, but stop 0.9 s after the
            # speaker goes quiet once they have started (nobody waits out a fixed
            # window), skip the first 0.3 s (the box's own wake beep), and normalise
            # the level so a soft voice through the box wall still transcribes.
            floor = None
            frames_q, spoke, quiet = [], False, 0
            max_frames = int(16000 * args.record_seconds / FRAME_SAMPLES)
            for k in range(max_frames):
                f = np.frombuffer(await reader.readexactly(FRAME_BYTES), dtype=np.int16)
                if k < 4:          # 0.3 s: wake beep + ring change
                    continue
                frames_q.append(f)
                rms = float(np.sqrt(np.mean(f.astype(np.float32) ** 2)))
                floor = rms if floor is None else min(floor, rms)
                if rms > 3 * max(floor, 120):
                    spoke, quiet = True, 0
                elif spoke:
                    quiet += 1
                    if quiet * FRAME_SAMPLES / 16000 >= 0.9:
                        break
            question = np.concatenate(frames_q) if frames_q else np.zeros(1600, dtype=np.int16)
            peak = int(np.abs(question).max()) or 1
            if peak < 12000:
                question = (question.astype(np.float32) * (12000.0 / peak)).astype(np.int16)
            print(f"[wake] {serial} question {len(question) / 16000:.1f}s, peak {peak}, spoke={spoke}")

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
            print(f"[wake] {serial} heard {reply['transcript']!r} -> answer {reply['answer']!r}")
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
    p.add_argument("--min-frames", type=int, default=2, help="consecutive 80 ms frames above threshold before firing")
    p.add_argument("--record-seconds", type=float, default=7.0, help="max question length; recording stops 0.9 s after the speaker goes quiet")
    # Must be the SAME server the box was paired on - its token is valid nowhere else.
    # LBOX_WAKE_API env overrides; default = live. Dev LAN: --api http://192.168.1.2/lordicl-next/public/api/device/v1
    p.add_argument("--api", default=os.environ.get("LBOX_WAKE_API", "https://next.lordicl.com/api/device/v1"))
    p.add_argument("--dump", type=float, default=0, help="save the first N seconds of each box's stream to lbox-mic-<serial>.wav")
    p.add_argument("--collect", default="", help="save each loud utterance as a 2.9 s WAV into this folder (wake-word training clips)")
    p.add_argument("--debug", action="store_true", help="print mic level + best score every ~2 s per box")
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

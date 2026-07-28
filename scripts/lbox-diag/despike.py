"""Spike-rejection test: if removing outlier samples recovers clean speech,
the noise is impulsive bit corruption on the I2S data line (wiring), not the
mic element."""
import struct
import subprocess
import wave

import numpy as np

SCRATCH = r"C:\Users\manik\AppData\Local\Temp\claude\c--wamp64-www-lordicl-next\ede8af6f-54cf-47ad-ba66-9a2644275158\scratchpad"
STT = r"c:\wamp64\www\lordicl-next\scripts\lbox_stt.py"
CACHE = r"c:\wamp64\www\lordicl-next\storage\app\lbox-stt\hf-cache"
PY = r"C:\Python314\python.exe"

with wave.open(f"{SCRATCH}\\raw_shift16.wav", "rb") as w:
    rate = w.getframerate()
    n = w.getnframes()
    x = np.frombuffer(w.readframes(n), dtype=np.int16).astype(np.float64)

big = np.abs(x) > 8000
print(f"samples={n} outliers(|x|>8000)={big.sum()} ({100 * big.mean():.2f}%)")

# median filter (k=5) kills isolated impulses, keeps speech envelope
k = 5
pad = np.pad(x, (k // 2, k // 2), mode="edge")
med = np.median(np.lib.stride_tricks.sliding_window_view(pad, k), axis=1)

# then gentle high-pass to drop the rumble
a = 0.984
y = np.zeros_like(med)
for i in range(1, len(med)):
    y[i] = a * (y[i - 1] + med[i] - med[i - 1])

peak = max(1.0, np.abs(y).max())
pcm = np.clip(y * (22000.0 / peak), -32768, 32767).astype(np.int16)
rms = int(np.sqrt(np.mean(pcm.astype(np.float64) ** 2)))

out = f"{SCRATCH}\\despiked.wav"
with wave.open(out, "wb") as w:
    w.setnchannels(1)
    w.setsampwidth(2)
    w.setframerate(rate)
    w.writeframes(pcm.tobytes())

r = subprocess.run([PY, STT, "--file", out, "--lang", "en", "--cache", CACHE],
                   capture_output=True, text=True, timeout=300)
print(f"despiked+hpf: rms={rms} stt=\"{r.stdout.strip()}\"")

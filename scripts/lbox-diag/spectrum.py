"""Spectral profile of the captured mic noise: white vs tonal vs LF-dominated."""
import struct
import wave

import numpy as np

SCRATCH = r"C:\Users\manik\AppData\Local\Temp\claude\c--wamp64-www-lordicl-next\ede8af6f-54cf-47ad-ba66-9a2644275158\scratchpad"

with wave.open(f"{SCRATCH}\\raw_shift16.wav", "rb") as w:
    rate = w.getframerate()
    n = w.getnframes()
    x = np.frombuffer(w.readframes(n), dtype=np.int16).astype(np.float64)

x -= x.mean()
seg = 1 << 14  # 16384-sample windows ~1s
spec = np.zeros(seg // 2 + 1)
count = 0
for i in range(0, len(x) - seg, seg):
    spec += np.abs(np.fft.rfft(x[i:i + seg] * np.hanning(seg))) ** 2
    count += 1
spec /= max(count, 1)
freqs = np.fft.rfftfreq(seg, 1 / rate)

bands = [(0, 50), (50, 150), (150, 300), (300, 800), (800, 1500), (1500, 3000), (3000, 5000), (5000, 8000)]
total = spec.sum()
print(f"windows={count} rate={rate}")
for lo, hi in bands:
    m = (freqs >= lo) & (freqs < hi)
    print(f"{lo:>5}-{hi:<5} Hz: {100 * spec[m].sum() / total:5.1f}%")

top = np.argsort(spec)[-8:][::-1]
print("top peaks:", ", ".join(f"{freqs[i]:.0f}Hz" for i in sorted(top, key=lambda i: -spec[i])))

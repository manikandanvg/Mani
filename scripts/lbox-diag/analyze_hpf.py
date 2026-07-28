"""Post-process the raw_shift16 capture (lossless top-16 view of the 32-bit
words): remove DC/rumble with a one-pole high-pass, normalize, STT. If speech
comes out clean, the mic is fine and the fix is alignment + high-pass in the
conversion, not hardware."""
import struct
import subprocess
import wave

SCRATCH = r"C:\Users\manik\AppData\Local\Temp\claude\c--wamp64-www-lordicl-next\ede8af6f-54cf-47ad-ba66-9a2644275158\scratchpad"
STT = r"c:\wamp64\www\lordicl-next\scripts\lbox_stt.py"
CACHE = r"c:\wamp64\www\lordicl-next\storage\app\lbox-stt\hf-cache"
PY = r"C:\Python314\python.exe"

for src_name in ("raw_shift16", "raw_shift8"):
    with wave.open(f"{SCRATCH}\\{src_name}.wav", "rb") as w:
        rate = w.getframerate()
        n = w.getnframes()
        x = list(struct.unpack(f"<{n}h", w.readframes(n)))

    # one-pole high-pass @ ~40Hz: y[n] = a*(y[n-1] + x[n] - x[n-1])
    a = 0.984
    y = [0.0] * n
    for i in range(1, n):
        y[i] = a * (y[i - 1] + x[i] - x[i - 1])

    peak = max(1.0, max(abs(v) for v in y))
    gain = 22000.0 / peak
    pcm = [int(max(-32768, min(32767, v * gain))) for v in y]
    rms = int((sum(s * s for s in pcm) / n) ** 0.5)

    out_path = f"{SCRATCH}\\{src_name}_hpf.wav"
    with wave.open(out_path, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes(struct.pack(f"<{n}h", *pcm))

    r = subprocess.run([PY, STT, "--file", out_path, "--lang", "en", "--cache", CACHE],
                       capture_output=True, text=True, timeout=300)
    print(f"{src_name} + HPF: rms={rms} stt=\"{r.stdout.strip()}\"", flush=True)

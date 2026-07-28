"""Capture the raw32 diag stream (v1.0.11): 32-bit interleaved words from both
I2S slots. Splits channels, reports bit-occupancy, renders candidate WAVs at
several bit alignments, and STTs each so the correct conversion is provable.
"""
import json
import socket
import struct
import subprocess
import time
import wave

SCRATCH = r"C:\Users\manik\AppData\Local\Temp\claude\c--wamp64-www-lordicl-next\ede8af6f-54cf-47ad-ba66-9a2644275158\scratchpad"
STT = r"c:\wamp64\www\lordicl-next\scripts\lbox_stt.py"
CACHE = r"c:\wamp64\www\lordicl-next\storage\app\lbox-stt\hf-cache"
PY = r"C:\Python314\python.exe"
SECONDS = 25

srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(("0.0.0.0", 8765))
srv.listen(1)
srv.settimeout(600)
print("raw32 capture listening on 8765 - waiting for the box", flush=True)

conn, addr = srv.accept()
conn.settimeout(10)
line = b""
while not line.endswith(b"\n"):
    line += conn.recv(1)
hello = json.loads(line)
rate = int(hello.get("rate", 16000))
print(f"handshake: serial={hello.get('serial')} rate={rate} raw32={hello.get('raw32')}", flush=True)
if not hello.get("raw32"):
    print("BOX IS NOT ON THE DIAG FIRMWARE YET (no raw32 flag) - aborting", flush=True)
    raise SystemExit(1)
print(f"RECORDING {SECONDS}s - SPEAK NOW", flush=True)

buf = bytearray()
start = time.time()
while time.time() - start < SECONDS:
    try:
        data = conn.recv(8192)
    except socket.timeout:
        print("stream stalled", flush=True)
        break
    if not data:
        break
    buf += data
conn.close()
srv.close()

n = len(buf) // 4
words = struct.unpack(f"<{n}i", bytes(buf[: n * 4]))
ch0 = words[0::2]
ch1 = words[1::2]
print(f"captured {n} words ({n / 2 / rate:.1f}s stereo)", flush=True)


def stats(name, ch):
    nz = sum(1 for w in ch if w != 0)
    if nz == 0:
        print(f"{name}: ALL ZERO", flush=True)
        return 0
    peak = max(abs(w) for w in ch)
    # how many low bits are always zero across nonzero words -> data alignment
    ored = 0
    for w in ch:
        ored |= w & 0xFFFFFFFF
    low_zero = (ored & -ored).bit_length() - 1 if ored else 32
    rms16 = int((sum((w >> 16) ** 2 for w in ch) / len(ch)) ** 0.5)
    print(f"{name}: nonzero={nz}/{len(ch)} peak32={peak} low_always_zero_bits={low_zero} rms(>>16)={rms16}", flush=True)
    return nz


nz0 = stats("ch0", ch0)
nz1 = stats("ch1", ch1)
live = ch0 if nz0 >= nz1 else ch1
live_name = "ch0" if nz0 >= nz1 else "ch1"
print(f"live channel: {live_name}", flush=True)

results = {}
for shift in (16, 14, 12, 8):
    pcm = [max(-32768, min(32767, w >> shift)) for w in live]
    path = f"{SCRATCH}\\raw_shift{shift}.wav"
    with wave.open(path, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes(struct.pack(f"<{len(pcm)}h", *pcm))
    rms = int((sum(s * s for s in pcm) / len(pcm)) ** 0.5)
    out = subprocess.run(
        [PY, STT, "--file", path, "--lang", "en", "--cache", CACHE],
        capture_output=True, text=True, timeout=300,
    )
    text = out.stdout.strip()
    results[shift] = text
    print(f">>{shift}: rms={rms} stt=\"{text}\"", flush=True)

best = max(results, key=lambda k: len(results[k]))
print(f"BEST: >>{best} -> \"{results[best]}\"", flush=True)

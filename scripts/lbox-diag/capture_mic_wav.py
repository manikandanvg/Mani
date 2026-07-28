"""Capture ~25s of the box's mic stream to a WAV so we can hear/STT what it sends."""
import json
import socket
import struct
import sys
import time
import wave

OUT = r"C:\Users\manik\AppData\Local\Temp\claude\c--wamp64-www-lordicl-next\ede8af6f-54cf-47ad-ba66-9a2644275158\scratchpad\mic_capture.wav"
SECONDS = 25

srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(("0.0.0.0", 8765))
srv.listen(1)
srv.settimeout(30)
print("capture listening on 8765 - waiting for the box", flush=True)

conn, addr = srv.accept()
conn.settimeout(5)
print(f"box connected from {addr[0]}", flush=True)

line = b""
while not line.endswith(b"\n"):
    line += conn.recv(1)
hello = json.loads(line)
rate = int(hello.get("rate", 16000))
print(f"handshake: serial={hello.get('serial')} rate={rate} - RECORDING {SECONDS}s, SPEAK NOW", flush=True)

pcm = bytearray()
start = time.time()
while time.time() - start < SECONDS:
    try:
        data = conn.recv(4096)
    except socket.timeout:
        print("stream stalled", flush=True)
        break
    if not data:
        break
    pcm += data

conn.close()
srv.close()

n = len(pcm) // 2
samples = struct.unpack(f"<{n}h", bytes(pcm[: n * 2]))
peak = max(abs(s) for s in samples)
rms = int((sum(s * s for s in samples) / n) ** 0.5)
mean = int(sum(samples) / n)
print(f"captured {n} samples ({n/rate:.1f}s) rms={rms} peak={peak} dc_offset={mean}", flush=True)

with wave.open(OUT, "wb") as w:
    w.setnchannels(1)
    w.setsampwidth(2)
    w.setframerate(rate)
    w.writeframes(bytes(pcm[: n * 2]))
print(f"wrote {OUT}", flush=True)

"""L-BOX speech-to-text bridge: WAV in, transcript out (stdout).

Self-hosted faster-whisper (CTranslate2, CPU int8) — no cloud, no cost.
The model downloads once to the HuggingFace cache on first use.

    python scripts/lbox_stt.py --file q.wav --model small --lang ta
"""
import argparse

from faster_whisper import WhisperModel

parser = argparse.ArgumentParser()
parser.add_argument("--file", required=True)
parser.add_argument("--model", default="small")
parser.add_argument("--lang", default=None, help="hint, e.g. ta/en; omit to auto-detect")
parser.add_argument("--cache", default=None, help="model download dir (shared, so the web-server user finds it)")
args = parser.parse_args()

model = WhisperModel(args.model, device="cpu", compute_type="int8", download_root=args.cache)
segments, _info = model.transcribe(
    args.file,
    language=args.lang or None,
    beam_size=1,
    vad_filter=True,
)
print(" ".join(segment.text.strip() for segment in segments).strip())

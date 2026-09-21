"""Train a "Hi L-BOX" detector on openWakeWord's audio embeddings.

    python scripts/lbox_wake_train.py --positive D:/lbox/wake-word/clips/positive \
        --positive D:/lbox/wake-word/clips/synth/positive --negative D:/lbox/wake-word/clips/synth/negative \
        --take D:/lbox/wake-word/clips/LJBOX2-session.pcm --out scripts/hi_lbox_lr.pkl

Same front end as openWakeWord (melspectrogram -> 96-d embeddings every 80 ms), then a
logistic regression over the last 16 embeddings (~1.3 s). Real clips from the box are
augmented (gain, shift, room noise from the take at random SNR) so a handful of them
still teaches the box's own wall and mic. `lbox_wake_server.py --model <this .pkl>`
runs it in streaming mode. Not as strong as the Colab-trained network - it is the
bridge until that exists, and it improves with every real clip added.
"""
import argparse
import glob
import os
import wave

import joblib
import numpy as np
from openwakeword.utils import AudioFeatures
from sklearn.linear_model import LogisticRegression

RATE = 16000
CLIP = 2 * RATE
WIN = 16


def read_wav(path):
    with wave.open(path) as w:
        a = np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16)
    if len(a) >= CLIP:
        off = (len(a) - CLIP) // 2
        return a[off:off + CLIP].astype(np.float32)
    return np.pad(a, (0, CLIP - len(a))).astype(np.float32)


def load_dir(paths):
    out = []
    for d in paths:
        for f in sorted(glob.glob(os.path.join(d, "*.wav"))):
            out.append(read_wav(f))
    return out


def noise_bank(take_path, floor_ratio=1.4, limit=300):
    if not take_path or not os.path.exists(take_path):
        return []
    a = np.fromfile(take_path, dtype=np.int16).astype(np.float32)
    n = len(a) // CLIP
    wins = a[: n * CLIP].reshape(n, CLIP)
    rms = np.sqrt((wins ** 2).mean(1))
    floor = np.median(rms)
    quiet = wins[rms < floor_ratio * floor]
    return list(quiet[:limit])


def augment(clip, bank, rng, n):
    out = []
    for _ in range(n):
        x = clip * rng.uniform(0.35, 2.2)
        shift = int(rng.integers(-4000, 4000))
        x = np.roll(x, shift)
        if shift > 0:
            x[:shift] = 0
        elif shift < 0:
            x[shift:] = 0
        if bank:
            nz = bank[rng.integers(len(bank))]
            snr_db = rng.uniform(2, 25)
            sig = np.sqrt((x ** 2).mean()) + 1e-6
            nrm = np.sqrt((nz ** 2).mean()) + 1e-6
            x = x + nz * (sig / nrm) / (10 ** (snr_db / 20))
        out.append(np.clip(x, -32767, 32767))
    return out


def embed(af, clips):
    x = np.stack(clips).astype(np.int16)
    e = af.embed_clips(x, batch_size=32)          # (N, frames, 96)
    if e.shape[1] < WIN:
        e = np.concatenate([np.repeat(e[:, :1], WIN - e.shape[1], axis=1), e], axis=1)
    return e[:, -WIN:, :].reshape(len(clips), -1)


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--positive", action="append", required=True)
    p.add_argument("--negative", action="append", default=[])
    p.add_argument("--take", default="")
    p.add_argument("--out", required=True)
    p.add_argument("--aug-real", type=int, default=40)
    p.add_argument("--aug-synth", type=int, default=4)
    p.add_argument("--holdout", type=int, default=2, help="real clips kept back for a sanity score")
    args = p.parse_args()
    rng = np.random.default_rng(7)

    real = load_dir([args.positive[0]])
    synth_pos = load_dir(args.positive[1:])
    neg = load_dir(args.negative)
    bank = noise_bank(args.take)
    hold, real = real[:args.holdout], real[args.holdout:]
    print(f"real positives {len(real)} (+{len(hold)} held out), synthetic positives {len(synth_pos)}, "
          f"speech negatives {len(neg)}, noise windows {len(bank)}")

    pos = []
    for c in real:
        pos += [c] + augment(c, bank, rng, args.aug_real)
    for c in synth_pos:
        pos += [c] + augment(c, bank, rng, args.aug_synth)
    negs = []
    for c in neg:
        negs += [c] + augment(c, bank, rng, 3)
    negs += [b * rng.uniform(0.5, 3.0) for b in bank]
    # phrase fragments as negatives: the box must not fire on "hi" alone or on "box" alone
    for c in real + synth_pos[: len(synth_pos) // 4]:
        half = CLIP // 2
        negs.append(np.concatenate([c[:half], np.zeros(half, np.float32)]))
        negs.append(np.concatenate([np.zeros(half, np.float32), c[half:]]))
    print(f"training on {len(pos)} positive / {len(negs)} negative windows")

    af = AudioFeatures()
    X = np.concatenate([embed(af, pos), embed(af, negs)])
    y = np.concatenate([np.ones(len(pos)), np.zeros(len(negs))])
    mu, sd = X.mean(0), X.std(0) + 1e-6
    clf = LogisticRegression(C=0.3, max_iter=3000, class_weight="balanced")
    clf.fit((X - mu) / sd, y)
    print(f"train accuracy {clf.score((X - mu) / sd, y):.3f}")
    if hold:
        h = (embed(af, hold) - mu) / sd
        print("held-out real clips ->", np.round(clf.predict_proba(h)[:, 1], 2).tolist())
    if bank:
        b = (embed(af, bank[:50]) - mu) / sd
        print(f"noise windows -> max score {clf.predict_proba(b)[:, 1].max():.2f}")
    if neg:
        n_ = (embed(af, neg[:60]) - mu) / sd
        print(f"other speech -> max score {clf.predict_proba(n_)[:, 1].max():.2f}")
    joblib.dump({"name": "hi_lbox", "clf": clf, "mu": mu, "sd": sd, "win": WIN}, args.out)
    print("saved", args.out)


if __name__ == "__main__":
    main()

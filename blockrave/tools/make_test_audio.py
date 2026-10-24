"""Erzeugt synthetische Test-MP3s fuer die Unit-Tests (benoetigt numpy + ffmpeg).

Aufruf: python3 tools/make_test_audio.py src/test/resources/audio
"""
import os
import subprocess
import sys
import tempfile
import wave

import numpy as np


def write_wav(path, data, rate):
    data = np.clip(data, -1, 1)
    pcm = (data * 32767).astype('<i2')
    ch = 1 if pcm.ndim == 1 else pcm.shape[1]
    with wave.open(path, 'wb') as w:
        w.setnchannels(ch)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes(pcm.tobytes())


def techno(rate, seconds, bpm, quiet_until=None):
    """Kick auf jedem Beat, Hi-Hat auf den Offbeats, Flaechenakkord. Optional leiser Anfang."""
    n = int(rate * seconds)
    t = np.arange(n) / rate
    out = np.zeros(n)
    beat = 60.0 / bpm
    rng = np.random.default_rng(1234)
    k = 0
    while k * beat < seconds:
        start = int(k * beat * rate)
        length = int(0.25 * rate)
        tt = np.arange(min(length, n - start)) / rate
        freq = 50 + 90 * np.exp(-tt * 30)
        phase = 2 * np.pi * np.cumsum(freq) / rate
        out[start:start + len(tt)] += 0.9 * np.sin(phase) * np.exp(-tt * 9)
        hs = int((k + 0.5) * beat * rate)
        hl = int(0.05 * rate)
        if hs + hl < n:
            out[hs:hs + hl] += 0.25 * rng.standard_normal(hl) * np.exp(-np.arange(hl) / rate * 60)
        k += 1
    pad = 0.08 * (np.sin(2 * np.pi * 220 * t) + np.sin(2 * np.pi * 277.18 * t) + np.sin(2 * np.pi * 329.63 * t))
    out += pad
    if quiet_until is not None:
        env = np.where(t < quiet_until, 0.12, 1.0)
        out *= env
    left = out
    right = np.roll(out, 7) * 0.95
    return np.stack([left, right], axis=1) * 0.7


def encode(wav, mp3, extra):
    subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', '-i', wav] + extra + [mp3], check=True)


def main(outdir):
    os.makedirs(outdir, exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        w = os.path.join(tmp, 'a.wav')
        write_wav(w, techno(44100, 20, 128), 44100)
        encode(w, os.path.join(outdir, 'techno128_44k.mp3'),
               ['-codec:a', 'libmp3lame', '-b:a', '128k', '-metadata', 'title=Testtrack Techno', '-metadata',
                'artist=BlockRave', '-id3v2_version', '3'])
        w2 = os.path.join(tmp, 'b.wav')
        write_wav(w2, techno(48000, 30, 124, quiet_until=12.0), 48000)
        encode(w2, os.path.join(outdir, 'drop124_48k.mp3'), ['-codec:a', 'libmp3lame', '-b:a', '96k'])
        w3 = os.path.join(tmp, 'c.wav')
        t = np.arange(int(22050 * 3)) / 22050
        write_wav(w3, 0.5 * np.sin(2 * np.pi * 440 * t), 22050)
        encode(w3, os.path.join(outdir, 'sine_mono_22k_mpeg2.mp3'), ['-codec:a', 'libmp3lame', '-b:a', '48k'])
    with open(os.path.join(outdir, 'not_audio.mp3'), 'wb') as f:
        f.write(b'PK\x03\x04' + bytes(range(256)) * 64)


if __name__ == '__main__':
    main(sys.argv[1])

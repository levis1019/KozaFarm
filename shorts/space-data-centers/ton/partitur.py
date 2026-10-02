#!/usr/bin/env python3
"""Quick Why · Data centers in space — new music + sound effects on K (measured word times).
The score is built as a LOOP: the beat grid divides DUR exactly, and everything that rings past DUR is wrapped
into the start, so the end flows into the opening without a fade or a hard stop.
Music: D major / B minor, airy pad and plucked arpeggio for the hero shot (same texture at start and end),
a soft pulse for the chips, a rising sun motif for "always lit", the music thins and a low drone builds under
"heat", a stop dead for "didn't expect this", a light groove for the verdict.
Effects only where something happens on screen."""
import numpy as np, soundfile as sf, os, json
SR = 48000; Z = json.load(open('out/zeiten.json')); K = Z['K']; DUR = Z['DUR']
N = int(round(DUR * SR)); TAIL = int(3 * SR); R = np.random.default_rng(31)
M = np.zeros((N + TAIL, 2)); S = np.zeros((N + TAIL, 2))
def tt(d): return np.arange(int(round(d * SR))) / SR
def put(buf, a, x, pan=0.):
    i = int(round(a * SR)); x = np.asarray(x)
    if i < 0: x = x[-i:]; i = 0
    n = min(len(x), len(buf) - i)
    if n > 0: buf[i:i + n, 0] += x[:n] * np.cos((pan + 1) * np.pi / 4) * 1.41; buf[i:i + n, 1] += x[:n] * np.sin((pan + 1) * np.pi / 4) * 1.41
def band(x, lo, hi):
    F = np.fft.rfft(x); f = np.fft.rfftfreq(len(x), 1 / SR); F[(f < lo) | (f > hi)] = 0; y = np.fft.irfft(F, len(x)); return y / (np.abs(y).max() + 1e-9)
def nz(d, lo, hi): return band(R.standard_normal(max(16, int(round(d * SR)))), lo, hi)
def reverb(x, sec, mix):
    n = int(sec * SR); ir = R.standard_normal((n, 2)) * np.exp(-np.arange(n) / SR * 6 / sec)[:, None]; F = 1 << int(np.ceil(np.log2(len(x) + n))); out = np.zeros_like(x)
    for c in range(2): out[:, c] = np.fft.irfft(np.fft.rfft(x[:, c], F) * np.fft.rfft(ir[:, c], F), F)[:len(x)]
    out *= np.abs(x).max() / (np.abs(out).max() + 1e-9); return x * (1 - mix) + out * mix
def hz(n): return 440 * 2 ** ((n - 69) / 12)
def pluck(n, a, amp=.05, pan=0, dec=6):
    t = tt(1.0); f = hz(n); x = (np.sin(2 * np.pi * f * t) + .3 * np.sin(2 * np.pi * f * 3 * t) * np.exp(-t * 30) + .18 * np.sin(2 * np.pi * f * 2 * t) * np.exp(-t * 9)) * np.exp(-t * dec)
    put(M, a, x * np.clip(t / .003, 0, 1) * amp, pan)
def keys(n, a, d=2.0, amp=.07, pan=0):
    t = tt(d); f = hz(n); x = sum(np.sin(2 * np.pi * f * k * t) * np.exp(-t * (1.0 + .8 * k)) / k ** 1.4 for k in range(1, 6)); put(M, a, x * np.clip(t / .006, 0, 1) * amp, pan)
def pad(ns, a, b, amp=.018, att=.6):
    if b <= a: return
    t = tt(b - a); e = np.clip(t / att, 0, 1) * np.clip((b - a - t) / .5, 0, 1)
    for i, n in enumerate(ns): f = hz(n); put(M, a, (np.sin(2 * np.pi * f * t) + .5 * np.sin(2 * np.pi * f * 1.002 * t + 1) + .12 * np.sin(4 * np.pi * f * t)) * .5 * e * amp, -.6 + 1.2 * i / max(1, len(ns) - 1))
def sub(n, a, d, amp=.13):
    t = tt(d); f = hz(n); put(M, a, np.sin(2 * np.pi * f * t) * np.clip(t / .01, 0, 1) * np.exp(-t * 2) * np.clip((d - t) / .04, 0, 1) * amp)
def kick(a, amp=.16):
    t = tt(.3); ph = 2 * np.pi * np.cumsum(44 + 85 * np.exp(-t * 30)) / SR; put(M, a, np.sin(ph) * np.exp(-t * 11) * amp)
def hat(a, amp=.012, pan=.3):
    t = tt(.05); put(M, a, nz(.05, 6500, 14000) * np.exp(-t * 85) * amp, pan)
NB = int(round(DUR / .6)); B = DUR / NB          # the beat divides the loop exactly
beat = lambda i: i * B
def bi(t): return int(np.ceil(t / B - 1e-9))     # first beat at or after t
D, Bm, G, A = [50, 62, 66, 69, 76], [47, 62, 66, 71, 74], [43, 59, 62, 67, 74], [45, 61, 64, 69, 76]
HERO = [D, D, G, A]
def hero_beats(i0, i1, amp=1.0):   # airy hero texture on the global grid (same at start and end)
    for i in range(i0, i1):
        c = HERO[(i // 8) % 4]; a = beat(i)
        if i % 8 == 0: pad(c[1:4], a, a + B * 8 + .2, .016 * amp); sub(c[0], a, B * 7.5, .1 * amp)
        pluck(c[1 + (i * 3) % 4] + 12, a, .032 * amp, -.4 + .8 * ((i * 5) % 7) / 6, 5)
        if i % 2 == 1: pluck(c[1 + (i * 2 + 1) % 4] + 24, a + B / 2, .014 * amp, .3, 7)
# 1) hero, start
hero_beats(0, bi(K['test'] - .1))
keys(74, K['power1'] - .02, 2.2, .06); keys(78, K['power1'] - .02, 2.2, .045)
# 2) inside: soft pulse, four rising notes for the four chips
for i in range(bi(K['test'] - .1), bi(K['orbit2'] - .2)):
    c = [D, Bm][(i // 4) % 2]; a = beat(i)
    if i % 2 == 0: kick(a, .1)
    if i % 4 == 0: pad(c[1:4], a, a + B * 4 + .2, .016); sub(c[0], a, B * 3.6, .1)
    pluck(c[1 + i % 4] + 12, a, .03, -.3 + .2 * (i % 4), 6)
for j, n in enumerate([74, 78, 81, 86]): pluck(n, K['four'] - .02 + j * .1, .05, -.3 + .2 * j, 5)
# 3) always lit: brighter, rising sun motif, chord on "eight"
for i in range(bi(K['orbit2'] - .2), bi(K['catch'] - .15)):
    c = [G, D, A, D][(i // 4) % 4]; a = beat(i)
    if i % 4 == 0: pad(c[1:], a, a + B * 4 + .2, .018); sub(c[0], a, B * 3.6, .11)
    if i % 2 == 0: kick(a, .11)
    hat(a + B / 2, .011)
    pluck(c[1 + i % 4] + 12, a, .032, -.3 + .2 * (i % 4), 6); pluck(c[1 + (i + 2) % 4] + 12, a + B / 2, .022, .3, 6)
for j, n in enumerate([62, 66, 69, 74, 78]): pluck(n + 12, K['never'] - .25 + j * .07, .03, -.4 + .2 * j, 6)
for n in [62, 66, 69, 74]: keys(n, K['eight'] - .02, 2.0, .06)
# 4) heat: music thins to a low drone, then a release on "rest"
pad([38, 45], K['catch'] - .1, K['expect1'] - .3, .03, .3)
pad([57, 64], K['heat'] - .2, K['rest'], .012, 1.5)
for a in np.arange(K['fifteen'] - .1, K['rest'] - .05, B / 2): hat(a, .02 if int(round((a - K['fifteen']) / (B / 2))) % 2 == 0 else .01, .4)
for n in [50, 57, 62, 66]: keys(n, K['rest'] - .02, 2.0, .06)
# 5) didn't expect this: the music stops dead (camera stops too) — one pluck, then the groove
pluck(81, K['expect1'] - .05, .05, .3, 5)
a0 = K['startup'] - .05
for i in range(bi(a0), bi(K['take'] - .3)):
    a = beat(i); c = [Bm, G][(i // 4) % 2]
    if i % 4 == 0: pad(c[1:4], a, a + B * 4 + .2, .014); sub(c[0], a, B * 3.6, .1)
    pluck(c[1 + (i * 3) % 4] + 12, a, .03, -.2 + .4 * (i % 2), 6)
pluck(86, K['own1'] - .02, .04, .4, 5)
# 6) verdict: light groove; major on "genius", suspended rub on "insane", steady pulse under "five years"
for i in range(bi(K['take'] - .3), bi(K['so2'] - .2)):
    a = beat(i); c = [G, D, A, Bm][(i // 4) % 4]
    kick(a, .14 if i % 2 == 0 else .0); hat(a + B / 2, .014)
    if i % 4 == 0: pad(c[1:], a, a + B * 4 + .2, .016); sub(c[0], a, B * 3.6, .12)
    pluck(c[1 + i % 4] + 12, a, .03, -.3 + .2 * (i % 4), 6)
for n in [62, 66, 69, 74]: keys(n, K['genius1'] - .02, 1.8, .06)
for n in [64, 69, 71, 76]: keys(n, K['insane1'] - .02, 1.8, .055)
# 7) back to the hero texture for the loop question, running past DUR (wrapped into the start below)
hero_beats(bi(K['so2'] - .2), NB + int(2.5 / B))
pluck(76, K['genius2'] - .02, .045, -.3, 5); pluck(79, K['insane2'] - .02, .045, .3, 5)
M = reverb(M, 1.8, .22)

# ---------- effects ----------
def tick(a, amp=.05, f=3000, pan=0): t = tt(.03); put(S, a, nz(.03, f * .7, f * 1.4) * np.exp(-t * 200) * amp, pan)
def popfx(a, amp=.07, f=700, pan=0):
    t = tt(.12); put(S, a, np.sin(2 * np.pi * (f * (1 + .6 * np.exp(-t * 60))) * t) * np.exp(-t * 34) * amp, pan)
def pen(a, d, amp=.03, pan=0):
    t = tt(d); put(S, a, nz(d, 1800, 4200) * (.6 + .4 * np.abs(np.sin(2 * np.pi * 11 * t + R.random()))) * np.sin(np.pi * np.clip(t / d, 0, 1)) ** .5 * amp, pan)
def swell(a, d, amp=.025, lo=300, hi=2500):
    t = tt(d); put(S, a, nz(d, lo, hi) * np.sin(np.pi * t / d) ** 2 * amp)
def hum(a, d, f0=60, amp=.05):   # heat building: low hum rising
    t = tt(d); f = f0 * (1 + .5 * t / d); x = np.tanh(2 * np.sin(2 * np.pi * np.cumsum(f) / SR)) * np.clip(t / .3, 0, 1) * np.clip((d - t) / .2, 0, 1); put(S, a, x * amp)
def unfold(a, amp=.06):   # radiator panel clicking open
    for k in range(5): tick(a + k * .06, amp * (1 - k * .12), 1800 + 200 * k, -.2)
def whirr(a, d, amp=.03):   # 3D satellite turning, very soft
    t = tt(d); put(S, a, nz(d, 200, 900) * (.6 + .4 * np.sin(2 * np.pi * .8 * t)) * np.sin(np.pi * t / d) * amp, .2)
pen(K['chips1'] - .32, .3, .035, .3); popfx(K['power1'] - .3, .06, 640)
for i in range(4): popfx(K['four'] - .3 + i * .1, .06, 820 + 90 * i, -.3 + .2 * i)
swell(K['test'] - .45, .9, .022)
swell(K['orbit2'] - .35, .7, .02); pen(K['orbit2'] - .4, .6, .03, -.2)
tick(K['never'] - .32, .05, 2600); popfx(K['eight'] - .3, .07, 900, .2); tick(K['earth'] - .4, .03, 1500, -.3)
t = tt(.35); put(S, K['heat'] - .3, nz(.35, 400, 3000) * np.exp(-t * 9) * .06)   # the quick zoom lands
hum(K['heat'] - .2, K['rest'] - K['heat'] + .1, 55, .045)
tick(K['air'] - .3, .04, 2000)
for k in range(5): tick(K['air'] + .1 + k * .18, .025, 1200 + 150 * (k % 2), -.3 + .15 * k)   # heat bouncing inside
popfx(K['fifteen'] - .3, .05, 700); unfold(K['rest'] - .25, .06); popfx(K['rest'] - .05, .05, 520)
tick(K['expect1'] - .3, .07, 4200)   # the camera stops: one clean click
whirr(K['startup'] - .45, .9, .03); popfx(K['startup'] - .3, .05, 1000, .4); tick(K['ran'] + .1, .03, 2400, -.2)
popfx(K['own1'] - .3, .05, 1100, .4)
tick(K['take'] - .3, .05, 3500); popfx(K['genius1'] - .3, .06, 760, -.3); pen(K['idea'] - .1, .35, .04, -.3)
popfx(K['insane1'] - .3, .06, 880, .3); pen(K['timeline'] - .1, .4, .04, .3)
pen(K['project'] - .3, .8, .03); tick(K['five'] - .3, .05, 2200)
for k in range(6): tick(K['project'] + k * .1, .02, 2600, -.4 + .16 * k)
popfx(K['genius2'] - .3, .05, 760, -.3); popfx(K['insane2'] - .3, .05, 880, .3)
S = reverb(S, .7, .1)
# wrap everything that rings past DUR into the start, so the loop join is seamless
for buf in (M, S): buf[:TAIL] += buf[N:N + TAIL]
M, S = M[:N], S[:N]
os.makedirs('out', exist_ok=True)
for name, buf, pk in [('musik', M, .6), ('sfx', S, .8)]:
    sf.write(f'out/{name}.wav', (buf / (np.abs(buf).max() + 1e-9) * pk).astype(np.float32), SR)
print('ok', DUR, 'beats', NB, 'B', round(B, 4))

#!/usr/bin/env python3
"""Quick Why · China fuel pause — new music + sound effects, all placed on K (measured word times).
Music: 104 BPM. A-minor pulse for the news (muted plucks, soft sub), lifts to C major for
"diesel moves your food", drops to one held note on "my take" (the camera stops too), returns warm and sparse
for the pool, ticking suspense for "October seventh", playful two-note question at the end that loops.
Effects only where something happens on screen: pen strokes, pops, a valve, a tank knock, falling fuel, a tick."""
import numpy as np, soundfile as sf, os, json
SR = 48000; Z = json.load(open('out/zeiten.json')); K = Z['K']; DUR = Z['DUR']; N = int(DUR * SR); R = np.random.default_rng(23)
M = np.zeros((N, 2)); S = np.zeros((N, 2))
def tt(d): return np.arange(int(round(d * SR))) / SR
def put(buf, a, x, pan=0.):
    i = int(round(a * SR)); x = np.asarray(x)
    if i < 0: x = x[-i:]; i = 0
    n = min(len(x), N - i)
    if n > 0: buf[i:i + n, 0] += x[:n] * np.cos((pan + 1) * np.pi / 4) * 1.41; buf[i:i + n, 1] += x[:n] * np.sin((pan + 1) * np.pi / 4) * 1.41
def band(x, lo, hi):  # FFT band-pass, any length
    F = np.fft.rfft(x); f = np.fft.rfftfreq(len(x), 1 / SR); F[(f < lo) | (f > hi)] = 0; y = np.fft.irfft(F, len(x)); return y / (np.abs(y).max() + 1e-9)
def nz(d, lo, hi): return band(R.standard_normal(max(16, int(round(d * SR)))), lo, hi)
def reverb(x, sec, mix):
    n = int(sec * SR); ir = R.standard_normal((n, 2)) * np.exp(-np.arange(n) / SR * 6 / sec)[:, None]; F = 1 << int(np.ceil(np.log2(len(x) + n))); out = np.zeros_like(x)
    for c in range(2): out[:, c] = np.fft.irfft(np.fft.rfft(x[:, c], F) * np.fft.rfft(ir[:, c], F), F)[:len(x)]
    out *= np.abs(x).max() / (np.abs(out).max() + 1e-9); return x * (1 - mix) + out * mix
def hz(n): return 440 * 2 ** ((n - 69) / 12)
# ---------- instruments ----------
def pluck(n, a, amp=.06, pan=0, dec=8):   # muted marimba-like pluck
    t = tt(.9); f = hz(n); x = (np.sin(2 * np.pi * f * t) + .35 * np.sin(2 * np.pi * f * 4 * t) * np.exp(-t * 40) + .2 * np.sin(2 * np.pi * f * 2 * t) * np.exp(-t * 14)) * np.exp(-t * dec)
    put(M, a, x * np.clip(t / .003, 0, 1) * amp, pan)
def keys(n, a, d=1.8, amp=.08, pan=0):
    t = tt(d); f = hz(n); x = sum(np.sin(2 * np.pi * f * k * t) * np.exp(-t * (1.2 + .9 * k)) / k ** 1.4 for k in range(1, 6)); put(M, a, x * np.clip(t / .006, 0, 1) * amp, pan)
def pad(ns, a, b, amp=.02, att=.5):
    if b <= a: return
    t = tt(b - a); e = np.clip(t / att, 0, 1) * np.clip((b - a - t) / .4, 0, 1)
    for i, n in enumerate(ns): f = hz(n); put(M, a, (np.sin(2 * np.pi * f * t) + .5 * np.sin(2 * np.pi * f * 1.003 * t + 1) + .15 * np.sin(4 * np.pi * f * t)) * .5 * e * amp, -.5 + i / max(1, len(ns) - 1))
def sub(n, a, d, amp=.16):
    t = tt(d); f = hz(n); x = np.sin(2 * np.pi * f * t) * np.clip(t / .01, 0, 1) * np.exp(-t * 2.5) * np.clip((d - t) / .03, 0, 1); put(M, a, x * amp)
def kick(a, amp=.22):
    t = tt(.3); ph = 2 * np.pi * np.cumsum(46 + 90 * np.exp(-t * 30)) / SR; put(M, a, np.sin(ph) * np.exp(-t * 11) * amp)
def hat(a, amp=.014, pan=.3):
    t = tt(.05); put(M, a, nz(.05, 6000, 14000) * np.exp(-t * 80) * amp, pan)
def shaker(a, amp=.012, pan=-.3):
    t = tt(.09); put(M, a, nz(.09, 3000, 9000) * np.sin(np.pi * t / .09) * amp, pan)
B = 60 / 104
Am, F, C, G, Dm, E = [45, 60, 64, 69], [41, 60, 65, 69], [48, 60, 64, 67], [43, 59, 62, 67], [50, 62, 65, 69], [40, 59, 64, 68]
def chord_at(prog, i): return prog[(i // 4) % len(prog)]
# 1) news pulse 0 – diesel3 (A minor: Am F C G), plucked eighths, sub on beats; gets denser after "already short"
a = 0.0; i = 0
while a < K['diesel3'] - .35:
    c = chord_at([Am, F, C, G], i); dense = a > K['world']
    if i % 4 == 0: sub(c[0], a, B * 3.6, .15); pad(c[1:], a, a + B * 4 + .1, .012 if not dense else .02)
    for j in range(2):
        pluck(c[1 + (i + j) % 3] + 12, a + j * B / 2, .04 if j == 0 else .028, -.3 + .2 * ((i + j) % 4))
    if dense: kick(a, .14 if i % 2 == 0 else .0); hat(a + B / 2, .012)
    i += 1; a += B
# accents on the hook
for n in [57, 64, 69]: keys(n, K['record'] - .02, 1.6, .06)
keys(45, K['paused'] - .02, 2.2, .1); keys(52, K['paused'] - .02, 2.2, .07)
# 2) "diesel moves your food": lifts to C major, light groove
a = K['diesel3'] - .02; i = 0
while a < K['take0'] - .1:
    c = chord_at([C, G, Am, F], i)
    if i % 2 == 0: kick(a, .16)
    hat(a + B / 2, .016); shaker(a + B / 4); shaker(a + 3 * B / 4, .009)
    if i % 4 == 0: sub(c[0], a, B * 3.6, .14); pad(c[1:], a, a + B * 4 + .1, .016)
    for j in range(2): pluck(c[1 + (i + j) % 3] + 12, a + j * B / 2, .036, -.3 + .3 * j)
    i += 1; a += B
for k, n in [('trucks', 72), ('ships', 76), ('tractors', 79)]: pluck(n, K[k] - .02, .05, .2, 6)
# 3) "my take": everything drops out to one held note (the camera stops dead)
pad([57, 64], K['take0'] - .05, K['take'] + .9, .022, .08)
# pool: warm and sparse, F – C – G – Am, keys on bar starts
a = K['take'] + .6; i = 0
while a < K['watch'] - .35:
    c = chord_at([F, C, G, Am], i)
    if i % 4 == 0: keys(c[0] + 12, a, 2.4, .07); keys(c[2], a, 2.4, .05); pad(c[1:], a, a + B * 4 + .1, .014); sub(c[0], a, B * 3, .1)
    if i % 2 == 1: pluck(c[3] + 12, a, .028, .3, 6)
    i += 1; a += B
keys(64, K['pool'] - .02, 2.0, .07); keys(69, K['pool'] - .02, 2.0, .05)
for j, n in enumerate([69, 67, 64]): pluck(n, K['steps'] - .05 + j * .1, .04, -.2 + .2 * j, 6)   # the seller steps out: falling figure
for j, n in enumerate([64, 67, 72]): pluck(n + 12, K['bids'] + j * .09, .035, .3, 7)           # price goes up: rising figure
# 4) "October seventh": suspended chord, ticking
pad([57, 59, 64], K['watch'] - .1, K['pump'] - .2, .02, .2)
for a in np.arange(K['watch'], K['pump'] - .3, B / 2): hat(a, .02 if int(round((a - K['watch']) / (B / 2))) % 2 == 0 else .011, .4)
for a in np.arange(K['watch'], K['pump'] - .3, B * 2): sub(45, a, B * 1.6, .1)
keys(69, K['nobody'] - .02, 1.8, .06); keys(71, K['nobody'] + .25, 1.8, .05)
# 5) poll + loop: playful two-note question, ends open on Asus2 and flows into the opening
for j, k in enumerate(['pump', 'groceries']): pluck(76 if j == 0 else 79, K[k] - .02, .05, -.3 + .6 * j, 6)
a = K['pump'] - .05; i = 0
while a < DUR - .05:
    c = chord_at([F, G], i)
    if i % 2 == 0: kick(a, .12)
    hat(a + B / 2, .012)
    pluck(c[1 + i % 3] + 12, a, .03, -.2 + .2 * (i % 3))
    i += 1; a += B
pad([57, 59, 64, 69], K['vo_end'] - .3, DUR, .02, .3)
M = reverb(M, 1.6, .2)

# ---------- effects ----------
def tick(a, amp=.06, f=3000, pan=0): t = tt(.03); put(S, a, nz(.03, f * .7, f * 1.4) * np.exp(-t * 200) * amp, pan)
def popfx(a, amp=.08, f=700, pan=0):   # soft card/glyph pop
    t = tt(.12); x = np.sin(2 * np.pi * (f * (1 + .6 * np.exp(-t * 60))) * t) * np.exp(-t * 34); put(S, a, x * amp, pan)
def thud(a, amp=.25, f0=70):
    t = tt(.45); put(S, a, (np.sin(2 * np.pi * (f0 + 50 * np.exp(-t * 25)) * t) * np.exp(-t * 9) + nz(.45, 600, 1800) * np.exp(-t * 45) * .2) * amp)
def pen(a, d, amp=.035, pan=0):   # marker on paper
    t = tt(d); x = nz(d, 1800, 4200) * (.6 + .4 * np.abs(np.sin(2 * np.pi * 11 * t + R.random()))) * np.sin(np.pi * np.clip(t / d, 0, 1)) ** .5; put(S, a, x * amp, pan)
def swell(a, d, amp=.03, lo=300, hi=2500):   # soft air movement for big camera moves
    t = tt(d); put(S, a, nz(d, lo, hi) * np.sin(np.pi * t / d) ** 2 * amp)
def plip(a, amp=.08):   # drop
    t = tt(.18); put(S, a, np.sin(2 * np.pi * np.cumsum(600 + 900 * t / .18) / SR) * np.exp(-t * 22) * amp)
def knock(a, f=180, amp=.12):   # hollow tank
    t = tt(.6); x = sum(np.sin(2 * np.pi * f * m * t) * np.exp(-t * (7 + 4 * m)) / m for m in [1, 2.3, 3.9]); put(S, a, x * amp)
def metal(a, amp=.08):   # valve wheel turning shut
    t = tt(.5); x = sum(np.sin(2 * np.pi * f * t) * np.exp(-t * d) for f, d in [(410, 9), (1130, 14), (1710, 20)]); put(S, a, x * amp, .4)
    for k in range(4): tick(a + k * .07, .05, 2200, .4)
# hook
tick(K['diesel'] - .3, .05, 2600); pen(K['record'] - .3, .55, .04, -.1); thud(K['record'] - .02, .18, 85)
swell(K['now'] - .32, .9, .028); pen(K['now'] - .2, .7, .03, .2)
popfx(K['paused'] - .2, .09, 520); thud(K['paused'] - .05, .14, 70)
pen(K['exports1'] - .3, .32, .06, .1)
# refiners, ships stop, October
for i in range(3): popfx(K['refiners'] - .3 + i * .08, .06, 760 + 80 * i, -.3 + .3 * i)
t = tt(.35); put(S, K['stopped'], nz(.35, 200, 900) * np.exp(-t * 10) * .05, .3)
popfx(K['october1'] - .3, .06, 980, .3)
# tank
swell(K['stocks0'] - .5, .55, .025); knock(K['stocks0'] + .1, 170, .12)
for k, a in enumerate(np.arange(K['stocks'] - .2, K['low'] - .1, .11)): t = tt(.07); put(S, a, np.sin(2 * np.pi * (520 - k * 22) * t) * np.exp(-t * 50) * .03, -.2)   # level falling
tick(K['low'] - .3, .06, 2200)
# wide shot, Middle East, Russia
swell(K['world'] - .3, 1.0, .03, 200, 1800)
pen(K['middle'] - .35, .6, .04, -.3); pen(K['russia'] - .35, .6, .04, .2)
t = tt(.3); put(S, K['short'], nz(.3, 300, 1200) * np.exp(-t * 9) * .03)
# chain
plip(K['diesel3'] - .3, .08)
thud(K['food'] - .25, .1, 140); popfx(K['everything'] - .3, .05, 640, -.4); popfx(K['everything'] - .22, .05, 600, .4)
for k, p in [('trucks', -.4), ('ships', 0), ('tractors', .4)]: popfx(K[k] - .3, .06, 820, p)
# my take: one clean click when the camera stops
tick(K['take'] - .3, .07, 4000)
for a, p in [(K['take'] + .25, -.4), (K['take'] + .45, 0), (K['china3'], .4)]: pen(a - .35, .35, .025, p)
popfx(K['reach'] - .35, .05, 700)
pen(K['pool0'] - .25, .6, .045, .1)
metal(K['steps'] - .3, .08)
for i in range(6): tick(K['everyone'] - .3 + i * .09, .03, 1500, -.4 + .16 * i)
popfx(K['bids'] - .3, .06, 900, .4)
# October 7
popfx(K['october2'] - .3, .07, 880, .3); pen(K['holiday'] - .3, .55, .045, .3); popfx(K['nobody'] - .35, .05, 1050, .2)
# poll
popfx(K['pump'] - .3, .07, 760, -.3); popfx(K['groceries'] - .3, .07, 820, .3); popfx(K['notice'] - .3, .05, 900)
t = tt(.06); put(S, K['first'] - .05, nz(.06, 2500, 7000) * np.exp(-t * 90) * .1)   # the tick in "Both"
swell(K['vo_end'] + .05, DUR - K['vo_end'] - .05, .03, 300, 3000)
S = reverb(S, .7, .1)
os.makedirs('out', exist_ok=True)
for name, buf, pk in [('musik', M, .6), ('sfx', S, .8)]:
    sf.write(f'out/{name}.wav', (buf / (np.abs(buf).max() + 1e-9) * pk).astype(np.float32), SR)
print('ok', DUR)

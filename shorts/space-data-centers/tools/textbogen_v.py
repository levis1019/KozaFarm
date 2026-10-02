#!/usr/bin/env python3
"""Vertical text timeline from the finished MP4: a frame every 0.5 s with the word being spoken."""
import json, subprocess, sys, numpy as np
from PIL import Image, ImageDraw, ImageFont
vid, vo, pre = sys.argv[1], sys.argv[2], sys.argv[3]
w, h = 216, 384
raw = subprocess.run(['ffmpeg', '-nostdin', '-loglevel', 'error', '-i', vid, '-vf', f'fps=2,scale={w}:{h}', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], capture_output=True).stdout
fr = np.frombuffer(raw, np.uint8).reshape(-1, h, w, 3)
W = json.load(open(vo))['words']
font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 13)
per, cols = 40, 10
for part in range(0, len(fr), per):
    chunk = fr[part:part + per]; rows = (len(chunk) + cols - 1) // cols
    sh = Image.new('RGB', (cols * w, rows * (h + 22)), 'black'); d = ImageDraw.Draw(sh)
    for i, f in enumerate(chunk):
        t = (part + i) * .5 + .25; x, y = (i % cols) * w, (i // cols) * (h + 22)
        sh.paste(Image.fromarray(f), (x, y))
        sp = [q for q in W if q['start'] <= t <= q['end'] + .15]
        d.text((x + 4, y + h + 3), f"{t:.2f} {' '.join(q['text'] or q['w'] for q in sp)}"[:28], fill='yellow', font=font)
    out = f'{pre}_{part // per + 1}.jpg'; sh.save(out, quality=88); print(out)

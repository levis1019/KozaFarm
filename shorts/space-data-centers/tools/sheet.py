#!/usr/bin/env python3
"""Vertical contact sheet: renders stills at the given times and tiles them 9:16 with the spoken word under each."""
import sys, os, json, subprocess, glob
from PIL import Image, ImageDraw, ImageFont
times = sorted(float(x) for x in sys.argv[1].split(',')); out = sys.argv[2]; cols = int(sys.argv[3]) if len(sys.argv) > 3 else 6
d = 'out/_st'; subprocess.run(['rm', '-rf', d]); os.makedirs(d)
subprocess.run(['node', os.path.expanduser('~/.claude/skills/erklaervideo/werkzeuge/render.mjs'), 'film/film.html', '--stills=' + ','.join(map(str, times)), '--out=' + d], check=True, capture_output=True)
import re
files = sorted(glob.glob(d + '/*.png'), key=lambda f: float(re.findall(r'[0-9]+(?:\.[0-9]+)?', os.path.basename(f))[-1]))
W = json.load(open('out/vo.json'))['words']
def spoken(t):
    w = [x for x in W if x['start'] <= t + .02]
    return ' '.join(x['text'] or x['w'] for x in w[-3:]) if w else ''
cw, ch = 300, 533; rows = (len(files) + cols - 1) // cols
sheet = Image.new('RGB', (cols * cw, rows * (ch + 40)), 'white'); dr = ImageDraw.Draw(sheet)
try: font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 15)
except Exception: font = ImageFont.load_default()
for i, (f, t) in enumerate(zip(files, times)):
    im = Image.open(f).convert('RGB').resize((cw, ch)); x, y = (i % cols) * cw, (i // cols) * (ch + 40)
    sheet.paste(im, (x, y)); dr.text((x + 6, y + ch + 4), f'{t:.2f}s  {spoken(t)}'[:38], fill='black', font=font)
sheet.save(out, quality=90); print(out, len(files))

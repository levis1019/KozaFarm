import json, math, sys
# Decode Natural Earth (world-atlas TopoJSON) into projected, simplified polylines.
def decode(topo):
    sx, sy = topo['transform']['scale']; tx, ty = topo['transform']['translate']
    arcs = []
    for arc in topo['arcs']:
        x = y = 0; pts = []
        for dx, dy in arc:
            x += dx; y += dy
            pts.append((x*sx+tx, y*sy+ty))
        arcs.append(pts)
    return arcs
def arc_pts(arcs, i):
    return arcs[i] if i >= 0 else arcs[~i][::-1]
def rings_of(geom, arcs):
    out = []
    polys = geom['arcs'] if geom['type'] == 'MultiPolygon' else [geom['arcs']]
    for poly in polys:
        for ring in poly:
            pts = []
            for i in ring:
                p = arc_pts(arcs, i)
                pts.extend(p if not pts else p[1:])
            out.append(pts)
    return out
LON0, LAT0 = float(sys.argv[2]), float(sys.argv[3])   # map centre
S = float(sys.argv[4])                                  # px per radian
def proj(lon, lat):
    lat = max(-80, min(80, lat))
    x = math.radians(lon - LON0) * S
    y = -(math.log(math.tan(math.pi/4 + math.radians(lat)/2)) - math.log(math.tan(math.pi/4 + math.radians(LAT0)/2))) * S
    return x, y
def simplify(pts, tol):
    if len(pts) < 3: return pts
    def rdp(a, b):
        (x1,y1),(x2,y2) = pts[a], pts[b]; dmax = 0; idx = a
        L = math.hypot(x2-x1, y2-y1) or 1e-9
        for i in range(a+1, b):
            x0,y0 = pts[i]; d = abs((y2-y1)*x0-(x2-x1)*y0+x2*y1-y2*x1)/L
            if d > dmax: dmax, idx = d, i
        if dmax > tol: return rdp(a, idx)[:-1] + rdp(idx, b)
        return [pts[a], pts[b]]
    return rdp(0, len(pts)-1)
def build(path, objname, filt=None, box=(-2000,-2400,2000,2400), tol=0.7):
    topo = json.load(open(path)); arcs = decode(topo)
    res = []
    for g in topo['objects'][objname]['geometries']:
        if filt and not filt(g): continue
        for ring in rings_of(g, arcs):
            ring = [((lo + 360) if lo < LON0 - 180 else lo, la) for lo, la in ring]
            los = [p[0] for p in ring]; las = [p[1] for p in ring]
            if max(abs(los[i]-los[i-1]) for i in range(1,len(los))) > 90 or min(las) < -60: continue
            pp = [proj(*p) for p in ring]
            xs = [p[0] for p in pp]; ys = [p[1] for p in pp]
            if max(xs) < box[0] or min(xs) > box[2] or max(ys) < box[1] or min(ys) > box[3]: continue
            # skip rings that wrap the antimeridian badly
            pass
            h = len(pp)//2
            pp = simplify(pp[:h+1], tol)[:-1] + simplify(pp[h:], tol)
            if len(pp) < 3: continue
            res.append([round(v, 1) for p in pp for v in p])
    return res
mode = sys.argv[1]
land = build('land-50m.json' if mode == 'hi' else 'land-110m.json', 'land', tol=0.6 if mode=='hi' else 0.8)
china = build('countries-110m.json', 'countries', filt=lambda g: str(g.get('id')) == '156', tol=0.5)
pts = {k: [round(v,1) for v in proj(*ll)] for k, ll in {
  'zhoushan': (122.1, 30.0), 'dalian': (121.6, 38.9), 'huizhou': (114.6, 22.6),
  'singapore': (103.8, 1.3), 'manila': (120.9, 14.6), 'sydney': (151.2, -33.9),
  'hcmc': (106.7, 10.8), 'chittagong': (91.8, 22.3), 'hongkong': (114.2, 22.3),
  'beijing': (116.4, 39.9)}.items()}
json.dump({'land': land, 'china': china, 'pts': pts}, open(sys.argv[5], 'w'), separators=(',', ':'))
print(len(land), sum(len(r) for r in land)//2, 'pts; china rings', len(china))

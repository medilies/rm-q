import math, random, sys
from PIL import Image, ImageDraw, ImageFont

S = 2
W, H = 720 * S, 290 * S
BG, BAR, FG, DIM, PROMPT, ACC = "#1e1e2e", "#2b2b3d", "#e6e6f0", "#585b70", "#a6e3a1", "#f9c74f"
MONO = "/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf"
BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf"
font = ImageFont.truetype(MONO, 34 * S)
label = ImageFont.truetype(BOLD, 20 * S)
qfont = ImageFont.truetype(BOLD, 30 * S)
tiny = ImageFont.truetype(MONO, 15 * S)
CW = font.getlength("m")
X0, Y0 = 40 * S, 72 * S
TX = X0 + 2 * CW
TW, TH = 76 * S, 58 * S
SP = TW + 16 * S
G = 250 * S                      # ground line of the queue
QX = 52 * S                      # "q" lane label
def slot(i): return 110 * S + TW / 2 + i * SP
LANE_END = slot(4) + TW / 2 + 10 * S
FT = 30                          # ms per animated frame
random.seed(7)

def rgb(c): return tuple(int(c[i:i + 2], 16) for i in (1, 3, 5))
def mix(a, b, t):
    a, b = rgb(a), rgb(b)
    return tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))
def clamp(t): return max(0.0, min(1.0, t))
def ease(t): t = clamp(t); return t * t * (3 - 2 * t)
def out_back(t, k=1.9):
    t = clamp(t) - 1
    return 1 + t * t * ((k + 1) * t + k)
def hop(t): t = clamp(t); return 4 * t * (1 - t)

def sprite(color, look=0.0, blink=False):
    pad = 4 * S
    im = Image.new("RGBA", (TW + 2 * pad, TH + 2 * pad), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.rounded_rectangle([pad, pad, pad + TW, pad + TH], radius=12 * S, fill=mix(BG, color, 0.22) + (255,),
                        outline=rgb(color) + (255,), width=2 * S)
    cx, ey = pad + TW / 2, pad + 18 * S
    for dx in (-11 * S, 11 * S):
        if blink:
            d.line([cx + dx - 5 * S, ey, cx + dx + 5 * S, ey], fill=rgb(FG) + (255,), width=2 * S)
        else:
            d.ellipse([cx + dx - 6 * S, ey - 6 * S, cx + dx + 6 * S, ey + 6 * S], fill=rgb(FG) + (255,))
            px = cx + dx + look * 2.5 * S
            d.ellipse([px - 3 * S, ey - 3 * S, px + 3 * S, ey + 3 * S], fill=rgb(BG) + (255,))
    d.text((cx, pad + 41 * S), "rm", font=label, fill=rgb(color) + (255,), anchor="mm")
    return im

def put(canvas, color, x, bottom, sx=1.0, sy=1.0, angle=0.0, alpha=1.0, look=0.0, blink=False):
    if alpha <= 0.01: return
    sp = sprite(color, look, blink)
    w, h = max(1, int(sp.width * sx)), max(1, int(sp.height * sy))
    sp = sp.resize((w, h), Image.BICUBIC)
    if angle: sp = sp.rotate(angle, resample=Image.BICUBIC, expand=True)
    if alpha < 1: sp.putalpha(sp.getchannel("A").point(lambda v: int(v * alpha)))
    cy = bottom - TH * sy / 2
    canvas.alpha_composite(sp, (int(x - sp.width / 2), int(cy - sp.height / 2)))

def base(text="", cursor=True, q_scale=1.0):
    im = Image.new("RGBA", (W, H), rgb(BG) + (255,))
    d = ImageDraw.Draw(im)
    d.rectangle([0, 0, W, 30 * S], fill=BAR)
    for i, c in enumerate(("#f38ba8", "#f9e2af", "#a6e3a1")):
        d.ellipse([(14 + i * 20) * S, 9 * S, (26 + i * 20) * S, 21 * S], fill=c)
    d.text((W / 2, 15 * S), "bash", font=tiny, fill=DIM, anchor="mm")
    d.text((X0, Y0), "$", font=font, fill=PROMPT, anchor="ls")
    for i, ch in enumerate(text):
        f = font if ch != "q" or q_scale == 1 else ImageFont.truetype(MONO, int(34 * S * q_scale))
        d.text((TX + i * CW + CW / 2, Y0), ch, font=f, fill=FG if ch != "q" else ACC, anchor="ms")
    if cursor:
        cx = TX + len(text) * CW
        d.rectangle([cx + 2 * S, Y0 + 4 * S, cx + CW - 2 * S, Y0 + 8 * S], fill=FG)
    return im, d

def lane(d, t, q_pos=None):
    if q_pos: d.text(q_pos, "q", font=qfont, fill=ACC, anchor="mm")
    if t > 0:
        x1 = QX + 26 * S
        d.line([x1, G + 5 * S, x1 + (LANE_END - x1) * ease(t), G + 5 * S], fill=DIM, width=2 * S)

frames, durs = [], []
def add(im, ms):
    frames.append(im.convert("RGB").resize((W // S, H // S), Image.LANCZOS)); durs.append(ms)

# 1. prompt, blinking underscore
add(base("", True)[0], 300); add(base("", False)[0], 200)
# 2. typing
add(base("r")[0], 90); add(base("rm")[0], 90)
# 3. rm_ blinking
for _ in range(2):
    add(base("rm", True)[0], 260); add(base("rm", False)[0], 220)
# 4. q pops in
for s in (1.5, 1.25, 1.0):
    add(base("rmq", False, q_scale=s)[0], FT)
add(base("rmq", False)[0], 150)

COLORS = ["#89b4fa", "#cba6f7", "#94e2d5", "#f5a97f"]
LABEL_Q = (QX, G - TH / 2)

# 5. q drops into the lane, the line draws out, the queue slides in
q0 = (TX + 2 * CW + CW / 2, Y0 - 12 * S)
N = 22
for i in range(N):
    t = i / (N - 1)
    im, d = base("rm", True)
    qt = ease(t / 0.4)
    qx = q0[0] + (LABEL_Q[0] - q0[0]) * qt
    qy = q0[1] + (LABEL_Q[1] - q0[1]) * qt - 50 * S * hop(qt)
    lane(d, (t - 0.3) / 0.5, (qx, qy))
    for k in range(3):
        tk = out_back((t - 0.35 - k * 0.12) / 0.4, 1.2)
        if t - 0.35 - k * 0.12 <= 0: continue
        put(im, COLORS[k], slot(k) + (W - slot(0)) * (1 - tk), G, look=-1)
    add(im, FT)

def queue(im, xs, lifts=None, looks=None, blink=False, colors=None):
    for k, x in enumerate(xs):
        if x is None: continue
        put(im, (colors or COLORS)[k], x, G - (lifts[k] if lifts else 0),
            look=looks[k] if looks else 0, blink=blink)

# 6. rm turns into a token, crouches, flips onto the tail; the line reacts
start = (TX + CW, Y0 + 40 * S)
tail = slot(3)
N = 34
for i in range(N):
    t = i / (N - 1)
    im, d = base("", (i // 10) % 2 == 0)
    lane(d, 1, LABEL_Q)
    looks = [1, 1, 1]
    lifts = [0, 0, 0]
    if t < 0.15:                                 # pop out of the prompt
        u = t / 0.15
        put(im, ACC, start[0], start[1] + TH * 0.4 * (1 - u), sx=0.5 + 0.5 * out_back(u), sy=0.5 + 0.5 * out_back(u))
    elif t < 0.25:                               # crouch
        u = (t - 0.15) / 0.1
        put(im, ACC, start[0], start[1], sx=1 + 0.18 * hop(u), sy=1 - 0.25 * hop(u))
    elif t < 0.75:                               # flip across to the tail
        u = (t - 0.25) / 0.5
        e = ease(u)
        x = start[0] + (tail - start[0]) * e
        y = start[1] + (G - start[1]) * u * u - 30 * S * hop(u)
        put(im, ACC, x, y, sx=0.92, sy=1.12, angle=-360 * e)
    else:                                        # land, squash, wave down the line
        u = (t - 0.75) / 0.25
        sq = hop(min(1, u * 2))
        put(im, ACC, tail, G, sx=1 + 0.25 * sq, sy=1 - 0.28 * sq, look=-1)
        for k in range(3):
            lifts[k] = 14 * S * hop((u - (2 - k) * 0.18) / 0.45)
        looks = [1, 1, 1] if u < 0.5 else [0, 0, 0]
    queue(im, [slot(0), slot(1), slot(2)], lifts, looks)
    add(im, FT)

# 7. idle: everyone bobs, then blinks
xs = [slot(0), slot(1), slot(2), tail]
cols = COLORS[:3] + [ACC]
for i in range(10):
    im, d = base("", i < 5)
    lane(d, 1, LABEL_Q)
    lifts = [3 * S * abs(math.sin(i / 3 + k)) for k in range(4)]
    queue(im, xs, lifts, [0, 0, 0, 0], blink=i in (6, 7), colors=cols)
    add(im, FT)

# 8. front file: shake, poof; the rest hop forward. Twice.
for rnd in range(2):
    N = 28
    parts = [(random.uniform(0, 2 * math.pi), random.uniform(60, 120) * S, random.uniform(3, 6) * S) for _ in range(14)]
    for i in range(N):
        t = i / (N - 1)
        im, d = base("", (i // 10) % 2 == 0)
        lane(d, 1, LABEL_Q)
        fx = xs[0]
        if t < 0.25:
            u = t / 0.25
            put(im, cols[0], fx + 5 * S * math.sin(u * 40) * (1 - u * 0.3), G, look=0, blink=True)
        else:
            u = (t - 0.25) / 0.35
            if u < 1:
                put(im, cols[0], fx, G, sx=1 + 0.5 * u, sy=1 + 0.5 * u, alpha=1 - u)
                for a, r, sz in parts:
                    px = fx + math.cos(a) * r * ease(u)
                    py = G - TH / 2 + math.sin(a) * r * ease(u)
                    c = mix(BG, cols[0], 1 - u)
                    d.ellipse([px - sz, py - sz, px + sz, py + sz], fill=c)
        for k in range(1, len(xs)):
            u = (t - 0.3 - (k - 1) * 0.1) / 0.4
            x = xs[k] - SP * ease(u)
            put(im, cols[k], x, G - 22 * S * hop(u), sx=1 - 0.08 * hop(u), sy=1 + 0.1 * hop(u), look=-1 if 0 < u < 1 else 0)
        add(im, FT)
    xs = [x - SP for x in xs[1:]]
    cols = cols[1:]
    im, d = base("", True)
    lane(d, 1, LABEL_Q)
    queue(im, xs, colors=cols)
    add(im, 260 if rnd == 0 else 900)

frames[0].save(sys.argv[1], save_all=True, append_images=frames[1:], duration=durs, loop=0, optimize=True, disposal=1)
print(len(frames), "frames,", sum(durs), "ms")

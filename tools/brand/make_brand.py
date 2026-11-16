"""Build NeuroLog brand assets from the supplied logo (white-background JPEG).

Usage: python3 -I make_brand.py <logo.jpg> <repo root>
Writes assets/brand/* and web/assets/* (favicons) under the repo root.
"""
import sys, os, json
import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

src, root = sys.argv[1], sys.argv[2]
brand = os.path.join(root, 'assets', 'brand'); web = os.path.join(root, 'web', 'assets')
os.makedirs(os.path.join(brand, 'source'), exist_ok=True); os.makedirs(web, exist_ok=True)

im = Image.open(src).convert('RGB')
a = np.asarray(im).astype(np.float32)
H, W, _ = a.shape

# 1. Background = near-white pixels connected to the image border (flood fill), so the white
#    rings and highlights inside the logo stay white.
near_white = (a.min(axis=2) >= 200).astype(np.uint8) * 255
mask = Image.fromarray(near_white, 'L').copy()  # fromarray shares a read-only buffer
for seed in [(0, 0), (W - 1, 0), (0, H - 1), (W - 1, H - 1)]:
    ImageDraw.floodfill(mask, seed, 128, thresh=0)
bg = np.asarray(mask) == 128
# Soft edge band: the background grown by 3 px. Both logo colours have R ~ 0, so over white
# alpha = (255 - R) / 255 and the colour is un-blended from white.
band = np.asarray(Image.fromarray((bg * 255).astype(np.uint8)).filter(ImageFilter.MaxFilter(7))) > 0
alpha = np.ones((H, W), np.float32)
soft = np.clip((255 - a[..., 0]) / 255.0, 0, 1)
alpha[band] = soft[band]
# JPEG noise in the white background: remove the faintest 8 % and rescale the rest.
alpha[bg] = np.clip((soft[bg] - 0.08) / 0.92, 0, 1)
rgb = a.copy()
m = band & (alpha > 0.01)
for c in range(3):
    ch = rgb[..., c]
    ch[m] = np.clip((a[..., c][m] - (1 - alpha[m]) * 255) / alpha[m], 0, 255)
rgba = np.dstack([rgb, alpha * 255]).round().astype(np.uint8)
logo = Image.fromarray(rgba, 'RGBA')

# 2. Square crop around the drawing with a small margin.
ys, xs = np.nonzero(alpha > 0.05)
x0, x1, y0, y1 = xs.min(), xs.max(), ys.min(), ys.max()
side = int(max(x1 - x0, y1 - y0) * 1.06)
cx, cy = (x0 + x1) // 2, (y0 + y1) // 2
sq = Image.new('RGBA', (side, side), (0, 0, 0, 0))
sq.paste(logo.crop((cx - side // 2, cy - side // 2, cx - side // 2 + side, cy - side // 2 + side)), (0, 0))

def sized(n):
    return sq.resize((n, n), Image.LANCZOS)

def on_white(img, pad=0.0):
    n = img.size[0]
    tile = Image.new('RGBA', (n, n), (255, 255, 255, 255))
    inner = int(n * (1 - 2 * pad))
    tile.alpha_composite(img.resize((inner, inner), Image.LANCZOS), ((n - inner) // 2, (n - inner) // 2))
    return tile.convert('RGB')

orig = os.path.join(brand, 'source', 'neurolog-logo-original.jpg')
if os.path.abspath(src) != os.path.abspath(orig):  # never re-encode the original over itself
    im.save(orig, quality=95)
sized(1024).save(os.path.join(brand, 'neurolog-logo.png'), optimize=True)
sized(512).save(os.path.join(brand, 'neurolog-logo-512.png'), optimize=True)
on_white(sized(512), pad=0.08).save(os.path.join(brand, 'neurolog-logo-512-white.png'), optimize=True)

# 3. Favicons (transparent) + apple-touch-icon (white tile: iOS fills transparency with black).
for n in (16, 32, 48, 192, 512):
    sized(n).save(os.path.join(web, f'favicon-{n}x{n}.png'), optimize=True)
sized(256).save(os.path.join(web, 'favicon.ico'), sizes=[(16, 16), (32, 32), (48, 48)])
on_white(sized(180), pad=0.1).save(os.path.join(web, 'apple-touch-icon.png'), optimize=True)
json.dump({
    'name': 'NeuroLog', 'short_name': 'NeuroLog',
    'icons': [{'src': 'favicon-192x192.png', 'sizes': '192x192', 'type': 'image/png'},
              {'src': 'favicon-512x512.png', 'sizes': '512x512', 'type': 'image/png'}],
    'theme_color': '#003399', 'background_color': '#ffffff', 'display': 'standalone',
}, open(os.path.join(web, 'site.webmanifest'), 'w'), indent=2)

# 4. GitHub social preview, 1280x640 (uploaded by hand in the repository settings).
sp = Image.new('RGB', (1280, 640), (255, 255, 255))
lg = sized(400)
sp.paste(lg, (110, 120), lg)
fb = '/usr/share/fonts/opentype/inter/Inter-Bold.otf'
fr = '/usr/share/fonts/opentype/inter/Inter-Regular.otf'
d = ImageDraw.Draw(sp)
d.text((580, 215), 'NeuroLog', font=ImageFont.truetype(fb, 104), fill=(0, 51, 153))
d.text((584, 345), 'Surgical Activity Logbook', font=ImageFont.truetype(fr, 40), fill=(38, 52, 69))
d.text((584, 405), 'Every Case. Every Detail. One Record.', font=ImageFont.truetype(fr, 30), fill=(0, 150, 200))
sp.save(os.path.join(brand, 'neurolog-social-preview.png'), optimize=True)
print('done', side)

# 5. Logo variants required by vocab/docs/design-system.md §2.2.
NAVY, WHITE = (0, 51, 153), (255, 255, 255)
fsb = '/usr/share/fonts/opentype/inter/Inter-SemiBold.otf'

def recolour_navy(img, colour):
    """Dark-background symbol: the navy N becomes `colour`; the cyan nodes stay."""
    x = np.asarray(img).astype(np.float32)
    navy = (x[..., 2] - x[..., 1] > 60) & (x[..., 0] < 60)      # blue well above green = the N
    out = x.copy(); out[navy, 0:3] = colour
    return Image.fromarray(out.round().astype(np.uint8), 'RGBA')

def mono(img, colour):
    """One colour: everything drawn takes `colour`; the white rings and highlights become gaps."""
    x = np.asarray(img).astype(np.float32)
    whiteness = x[..., 0:3].min(axis=2) / 255.0
    al = x[..., 3] * np.clip(1 - whiteness * 1.15, 0, 1)
    out = np.zeros_like(x); out[..., 0:3] = colour; out[..., 3] = al
    return Image.fromarray(out.round().astype(np.uint8), 'RGBA')

def wordmark(symbol, text_colour, layout):
    s = 360
    sym = symbol.resize((s, s), Image.LANCZOS)
    f_big = ImageFont.truetype(fb, 150 if layout == 'horizontal' else 120)
    f_tag = ImageFont.truetype(fsb, 30)
    tmp = ImageDraw.Draw(Image.new('RGBA', (1, 1)))
    wb = tmp.textbbox((0, 0), 'NeuroLog', font=f_big); tb = tmp.textbbox((0, 0), 'SURGICAL ACTIVITY LOGBOOK', font=f_tag)
    ww, wh = wb[2] - wb[0], wb[3] - wb[1]
    if layout == 'horizontal':
        W2, H2 = s + 40 + max(ww, tb[2]) + 20, s
        out = Image.new('RGBA', (W2, H2), (0, 0, 0, 0)); out.alpha_composite(sym, (0, 0))
        d2 = ImageDraw.Draw(out)
        d2.text((s + 40 - wb[0], H2 // 2 - wh // 2 - 40 - wb[1]), 'NeuroLog', font=f_big, fill=text_colour)
        d2.text((s + 46, H2 // 2 + wh // 2 - 10), 'SURGICAL ACTIVITY LOGBOOK', font=f_tag, fill=text_colour + (200,))
    else:   # full: symbol above the wordmark
        W2 = max(s, ww) + 40; H2 = s + 30 + wh + 70
        out = Image.new('RGBA', (W2, H2), (0, 0, 0, 0)); out.alpha_composite(sym, ((W2 - s) // 2, 0))
        d2 = ImageDraw.Draw(out)
        d2.text(((W2 - ww) // 2 - wb[0], s + 30 - wb[1]), 'NeuroLog', font=f_big, fill=text_colour)
        d2.text(((W2 - (tb[2] - tb[0])) // 2, s + 30 + wh + 25), 'SURGICAL ACTIVITY LOGBOOK', font=f_tag,
                fill=text_colour + (200,))
    return out

sym = sized(1024)
dark_sym = recolour_navy(sym, WHITE)
dark_sym.resize((512, 512), Image.LANCZOS).save(os.path.join(brand, 'neurolog-logo-512-dark.png'), optimize=True)
mono(sym, NAVY).resize((512, 512), Image.LANCZOS).save(os.path.join(brand, 'neurolog-logo-512-mono.png'), optimize=True)
mono(sym, WHITE).resize((512, 512), Image.LANCZOS).save(os.path.join(brand, 'neurolog-logo-512-mono-white.png'), optimize=True)
for layout in ('full', 'horizontal'):
    wordmark(sym, NAVY, layout).save(os.path.join(brand, f'neurolog-logo-{layout}.png'), optimize=True)
    wordmark(dark_sym, WHITE, layout).save(os.path.join(brand, f'neurolog-logo-{layout}-dark.png'), optimize=True)
print('variants done')

"""Erzeugt alle eigenen Texturen, Block-/Item-Modelle und Blockstates von BlockRave.

Aufruf (aus dem Projektordner): python3 tools/make_assets.py
Die Texturen sind handgesetzte Pixelgrafik (prozedural beschrieben), keine Fremdgrafiken.
"""
import json
import math
import os

from PIL import Image

ROOT = os.path.join(os.path.dirname(__file__), '..', 'src', 'main', 'resources', 'assets', 'blockrave')
TEX = os.path.join(ROOT, 'textures')
MOD = 'blockrave'


def save(img, *path):
    p = os.path.join(TEX, *path)
    os.makedirs(os.path.dirname(p), exist_ok=True)
    img.save(p)


def write_json(rel, data):
    p = os.path.join(ROOT, rel)
    os.makedirs(os.path.dirname(p), exist_ok=True)
    with open(p, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2, ensure_ascii=False)
        f.write('\n')


def img(size=16, color=(0, 0, 0, 255)):
    return Image.new('RGBA', (size, size), color)


def noise(x, y, seed=0):
    v = math.sin(x * 12.9898 + y * 78.233 + seed * 37.719) * 43758.5453
    return v - math.floor(v)


def shade(c, f):
    return tuple(max(0, min(255, int(ch * f))) for ch in c[:3]) + (c[3] if len(c) > 3 else 255,)


# ---------------------------------------------------------------- Texturen

def dance_floor():
    im = img()
    for y in range(16):
        for x in range(16):
            edge = x in (0, 15) or y in (0, 15)
            if edge:
                c = (70, 60, 90, 255)
            else:
                d = math.hypot(x - 7.5, y - 7.5) / 10.6
                base = (34, 18, 52)
                c = shade(base + (255,), 1.25 - d * 0.5 + noise(x, y) * 0.08)
            im.putpixel((x, y), c)
    for i in range(2, 5):  # Glanzlicht
        im.putpixel((i, 2), (110, 90, 140, 255))
        im.putpixel((2, i), (110, 90, 140, 255))
    save(im, 'block', 'dance_floor.png')


def stage_deck():
    im = img()
    for y in range(16):
        for x in range(16):
            c = shade((38, 38, 44, 255), 0.9 + noise(x, y, 1) * 0.2)
            # Riffelblech-Muster
            if (x + y * 2) % 8 in (0, 1) and (x // 4 + y // 4) % 2 == 0:
                c = (62, 62, 70, 255)
            if (x * 2 - y) % 8 in (0, 1) and (x // 4 + y // 4) % 2 == 1:
                c = (62, 62, 70, 255)
            im.putpixel((x, y), c)
    save(im, 'block', 'stage_deck.png')


def stage_front():
    im = img()
    for y in range(16):
        for x in range(16):
            if x in (0, 15) or y in (0, 15):
                c = (120, 120, 128, 255)
            elif x in (1, 14) or y in (1, 14):
                c = (200, 200, 210, 255)
            else:
                c = shade((245, 245, 255, 255), 0.95 + noise(x, y, 2) * 0.05)
            im.putpixel((x, y), c)
    save(im, 'block', 'stage_front.png')


def speaker_front():
    im = img(16, (14, 14, 16, 255))
    def cone(cx, cy, r):
        for y in range(16):
            for x in range(16):
                d = math.hypot(x + 0.5 - cx, y + 0.5 - cy)
                if d <= r:
                    if d > r - 1:
                        c = (120, 120, 130, 255)     # Sicke
                    elif d < r * 0.3:
                        c = (95, 95, 105, 255)       # Staubkappe
                    else:
                        c = shade((40, 40, 46, 255), 0.8 + (d / r) * 1.0)
                    im.putpixel((x, y), c)
    cone(8, 10.5, 5.3)
    cone(8, 3.2, 2.4)
    for x in range(16):
        im.putpixel((x, 0), (40, 40, 44, 255))
        im.putpixel((x, 15), (40, 40, 44, 255))
    save(im, 'block', 'speaker_front.png')


def speaker_side():
    im = img(16, (20, 20, 22, 255))
    for y in range(16):
        for x in range(16):
            if x % 2 == 0 and y % 2 == 0:
                im.putpixel((x, y), (8, 8, 9, 255))
            if x in (0, 15) or y in (0, 15):
                im.putpixel((x, y), (36, 36, 40, 255))
    save(im, 'block', 'speaker_side.png')


def truss():
    im = img(16, (0, 0, 0, 0))
    metal = lambda f: shade((190, 196, 205, 255), f)
    for y in range(16):
        for x in (0, 1, 14, 15):
            im.putpixel((x, y), metal(1.0 if x in (0, 14) else 0.75))
    for y in range(16):  # Diagonalstreben
        x = y if y < 16 else 0
        for dx in (0, 1):
            xx = min(15, x + dx)
            if 1 < xx < 14:
                im.putpixel((xx, y), metal(0.9))
            xx2 = min(15, 15 - x + dx - 1)
            if 1 < xx2 < 14:
                im.putpixel((xx2, y), metal(0.8))
    for x in range(16):
        for y in (0, 15):
            im.putpixel((x, y), metal(0.85))
    save(im, 'block', 'truss.png')
    end = img(16, (0, 0, 0, 0))
    for y in range(16):
        for x in range(16):
            if x in (0, 1, 14, 15) or y in (0, 1, 14, 15):
                end.putpixel((x, y), metal(0.9))
            if (x in (2, 3) or x in (12, 13)) and (y in (2, 3) or y in (12, 13)):
                end.putpixel((x, y), metal(0.7))
    save(end, 'block', 'truss_end.png')


def led_panel():
    im = img(16, (10, 10, 12, 255))
    for y in range(16):
        for x in range(16):
            if x % 2 == 1 and y % 2 == 1:
                im.putpixel((x, y), (34, 30, 40, 255))
    for i in range(16):
        im.putpixel((i, 0), (45, 45, 50, 255))
        im.putpixel((0, i), (45, 45, 50, 255))
    save(im, 'block', 'led_panel_front.png')
    side = img(16, (24, 24, 28, 255))
    for i in range(16):
        side.putpixel((i, 0), (40, 40, 46, 255))
        side.putpixel((i, 15), (12, 12, 14, 255))
    save(side, 'block', 'led_panel_side.png')


def fixture():
    body = img(16, (46, 46, 52, 255))
    for y in range(16):
        for x in range(16):
            body.putpixel((x, y), shade((46, 46, 52, 255), 0.9 + noise(x, y, 5) * 0.15))
            if y % 4 == 0:
                body.putpixel((x, y), (30, 30, 34, 255))
    save(body, 'block', 'fixture_body.png')
    lens = img(16, (30, 30, 34, 255))
    for y in range(16):
        for x in range(16):
            d = math.hypot(x - 7.5, y - 7.5)
            if d < 6.5:
                c = shade((170, 220, 255, 255), 1.0 - d / 9)
                lens.putpixel((x, y), c)
            elif d < 7.5:
                lens.putpixel((x, y), (90, 90, 100, 255))
    lens.putpixel((5, 5), (255, 255, 255, 255))
    lens.putpixel((6, 5), (240, 250, 255, 255))
    save(lens, 'block', 'fixture_lens.png')
    strobe = img(16, (40, 40, 44, 255))
    for y in range(2, 14):
        for x in range(1, 15):
            strobe.putpixel((x, y), (235, 240, 255, 255) if (x + y) % 3 else (205, 210, 225, 255))
    save(strobe, 'block', 'strobe_front.png')
    laser = img(16, (24, 24, 28, 255))
    for x in range(3, 13):
        laser.putpixel((x, 7), (57, 255, 20, 255))
        laser.putpixel((x, 8), (20, 140, 10, 255))
    for x in range(16):
        laser.putpixel((x, 0), (60, 60, 66, 255))
    save(laser, 'block', 'laser_front.png')
    fog = img(16, (60, 60, 66, 255))
    for y in range(16):
        for x in range(16):
            fog.putpixel((x, y), shade((70, 70, 78, 255), 0.85 + noise(x, y, 9) * 0.2))
    for y in range(5, 11):
        for x in range(5, 11):
            fog.putpixel((x, y), (18, 18, 20, 255))
    save(fog, 'block', 'fog_machine.png')


def booth():
    # Oberseite (32x32): zwei Jogwheels links/rechts, Mischpult mittig, pinke/cyanfarbene LEDs
    top = img(32, (22, 22, 26, 255))
    for y in range(32):
        for x in range(32):
            if x in (0, 31) or y in (0, 31):
                top.putpixel((x, y), (60, 60, 68, 255))
    def wheel(cx, cy, r, ring):
        for y in range(32):
            for x in range(32):
                d = math.hypot(x + 0.5 - cx, y + 0.5 - cy)
                if d <= r:
                    c = (12, 12, 14, 255) if d > 2.5 else (140, 140, 150, 255)
                    if r - 1.4 < d <= r:
                        c = ring
                    if abs(d - r * 0.6) < 0.5:
                        c = (50, 50, 56, 255)
                    top.putpixel((x, y), c)
    wheel(8, 18, 6.5, (0, 229, 255, 255))
    wheel(24, 18, 6.5, (255, 46, 166, 255))
    for i, x in enumerate((14, 16, 18)):  # Fader
        for y in range(6, 16):
            top.putpixel((x, y), (8, 8, 10, 255))
        top.putpixel((x, 8 + i * 2), (230, 230, 235, 255))
        top.putpixel((x - 1, 8 + i * 2), (200, 200, 205, 255))
    for x in range(13, 20):  # Crossfader
        top.putpixel((x, 26), (8, 8, 10, 255))
    top.putpixel((16, 26), (230, 230, 235, 255))
    for i, c in enumerate([(57, 255, 20), (255, 224, 26), (255, 46, 166)]):
        top.putpixel((14 + i * 2, 3), c + (255,))
    save(top, 'block', 'dj_booth_top.png')
    front = img(32, (14, 14, 18, 255))
    for y in range(32):
        for x in range(32):
            if 10 <= y <= 11:
                front.putpixel((x, y), (255, 46, 166, 255))
            if 20 <= y <= 21:
                front.putpixel((x, y), (0, 229, 255, 255))
            if x in (0, 31) or y in (0, 31):
                front.putpixel((x, y), (60, 60, 68, 255))
    # Schriftzug "BR"
    letters = ["111 111", "1 1 1 1", "11  11 ", "1 1 1 1", "111 1 1"]
    for j, row in enumerate(letters):
        for i, ch in enumerate(row):
            if ch == '1':
                front.putpixel((12 + i, 13 + j), (255, 255, 255, 255))
    save(front, 'block', 'dj_booth_front.png')
    side = img(32, (22, 22, 26, 255))
    for y in range(32):
        for x in range(32):
            if x in (0, 31) or y in (0, 31):
                side.putpixel((x, y), (60, 60, 68, 255))
            elif y % 8 == 0:
                side.putpixel((x, y), (32, 32, 38, 255))
    save(side, 'block', 'dj_booth_side.png')
    deck = img(32, (26, 26, 30, 255))
    for y in range(32):
        for x in range(32):
            if x in (0, 31) or y in (0, 31):
                deck.putpixel((x, y), (60, 60, 68, 255))
            d = math.hypot(x + 0.5 - 16, y + 0.5 - 18)
            if d <= 9:
                c = (16, 16, 18, 255)
                if d > 7.8:
                    c = (255, 224, 26, 255)
                elif d < 2:
                    c = (150, 150, 160, 255)
                elif abs(d - 5) < 0.5:
                    c = (60, 60, 66, 255)
                deck.putpixel((x, y), c)
    for x in range(6, 26):  # Display
        for y in range(3, 7):
            deck.putpixel((x, y), (20, 60, 90, 255) if y in (3, 6) else (60, 170, 230, 255))
    save(deck, 'block', 'dj_booth_side_top.png')


def stage_kit_item():
    im = img(16, (0, 0, 0, 0))
    for y in range(4, 16):
        for x in range(1, 15):
            c = (70, 52, 36, 255) if (x in (1, 14) or y in (4, 15)) else (120, 88, 56, 255)
            if y in (8, 12) and 1 < x < 14:
                c = (90, 66, 42, 255)
            im.putpixel((x, y), c)
    for y in range(6, 14):  # Lautsprecher auf der Kiste
        for x in range(3, 7):
            im.putpixel((x, y), (20, 20, 24, 255))
    im.putpixel((4, 11), (80, 80, 90, 255))
    im.putpixel((5, 11), (80, 80, 90, 255))
    beams = [((8, 3), (255, 46, 166)), ((10, 2), (0, 229, 255)), ((12, 3), (255, 224, 26)), ((11, 1), (57, 255, 20))]
    for (x, y), c in beams:
        for k in range(3):
            if 0 <= y - k < 16:
                im.putpixel((x + k // 2, y - k), c + (255,))
    for x in range(8, 14):
        im.putpixel((x, 5), (230, 230, 235, 255))
    save(im, 'item', 'stage_kit.png')


def fog_texture():
    size = 64
    im = Image.new('RGBA', (size, size), (255, 255, 255, 0))
    for y in range(size):
        for x in range(size):
            d = math.hypot(x + 0.5 - size / 2, y + 0.5 - size / 2) / (size / 2)
            n = 0.75 + 0.25 * (math.sin(x * 0.35) * math.cos(y * 0.29) + noise(x, y, 3) * 0.6)
            a = max(0.0, 1 - d) ** 1.6 * n
            im.putpixel((x, y), (255, 255, 255, int(max(0, min(1, a)) * 255)))
    save(im, 'effect', 'fog.png')


# ---------------------------------------------------------------- Modelle

def b(name):
    return f'{MOD}:block/{name}'


def model(name, data):
    write_json(f'models/block/{name}.json', data)


def facing_blockstate(name, model_name=None):
    m = b(model_name or name)
    write_json(f'blockstates/{name}.json', {"variants": {
        "facing=north": {"model": m},
        "facing=east": {"model": m, "y": 90},
        "facing=south": {"model": m, "y": 180},
        "facing=west": {"model": m, "y": 270},
    }})


def cube(name, north, south, east, west, up, down, particle=None, render_type=None):
    d = {"parent": "minecraft:block/cube", "textures": {
        "north": b(north), "south": b(south), "east": b(east), "west": b(west), "up": b(up), "down": b(down),
        "particle": b(particle or north)}}
    if render_type:
        d["render_type"] = render_type
    model(name, d)


def el(fr, to, faces, rotation=None):
    e = {"from": fr, "to": to, "faces": faces}
    if rotation:
        e["rotation"] = rotation
    return e


def all_faces(tex, uv=None):
    f = {}
    for d in ("north", "south", "east", "west", "up", "down"):
        f[d] = {"texture": tex}
        if uv:
            f[d]["uv"] = uv
    return f


def models():
    model('dance_floor', {"parent": "minecraft:block/cube_all", "textures": {"all": b('dance_floor')}})
    write_json('blockstates/dance_floor.json', {"variants": {"": {"model": b('dance_floor')}}})
    model('stage_deck', {"parent": "minecraft:block/cube_all", "textures": {"all": b('stage_deck')}})
    write_json('blockstates/stage_deck.json', {"variants": {"": {"model": b('stage_deck')}}})

    cube('stage_front', 'stage_front', 'stage_deck', 'stage_deck', 'stage_deck', 'stage_deck', 'stage_deck')
    facing_blockstate('stage_front')
    cube('speaker', 'speaker_front', 'speaker_side', 'speaker_side', 'speaker_side', 'speaker_side', 'speaker_side')
    facing_blockstate('speaker')
    cube('led_panel', 'led_panel_front', 'led_panel_side', 'led_panel_side', 'led_panel_side', 'led_panel_side', 'led_panel_side')
    facing_blockstate('led_panel')

    # Traverse: Gitter mit Durchsicht (cutout)
    model('truss', {"parent": "minecraft:block/cube_column", "render_type": "minecraft:cutout",
                    "textures": {"side": b('truss'), "end": b('truss_end')}})
    model('truss_horizontal', {"parent": "minecraft:block/cube_column_horizontal", "render_type": "minecraft:cutout",
                               "textures": {"side": b('truss'), "end": b('truss_end')}})
    write_json('blockstates/truss.json', {"variants": {
        "axis=y": {"model": b('truss')},
        "axis=z": {"model": b('truss_horizontal'), "x": 90},
        "axis=x": {"model": b('truss_horizontal'), "x": 90, "y": 90},
    }})

    # Treppe (wie Vanilla-Treppen aufgebaut)
    tex = {"bottom": b('stage_deck'), "top": b('stage_deck'), "side": b('stage_deck')}
    model('stage_steps', {"parent": "minecraft:block/stairs", "textures": tex})
    model('stage_steps_inner', {"parent": "minecraft:block/inner_stairs", "textures": tex})
    model('stage_steps_outer', {"parent": "minecraft:block/outer_stairs", "textures": tex})
    variants = {}
    rot = {"east": 0, "south": 90, "west": 180, "north": 270}
    for facing in ("east", "north", "south", "west"):
        for half in ("bottom", "top"):
            for shape in ("straight", "inner_left", "inner_right", "outer_left", "outer_right"):
                m = b('stage_steps' + ('' if shape == 'straight' else '_inner' if shape.startswith('inner') else '_outer'))
                y = rot[facing]
                if shape in ('inner_left', 'outer_left'):
                    y = (y + 270) % 360
                if half == 'top':
                    if shape in ('inner_right', 'outer_right'):
                        y = (y + 90) % 360
                    if shape in ('inner_left', 'outer_left'):
                        y = (y + 90) % 360
                v = {"model": m}
                if half == 'top':
                    v["x"] = 180
                    v["uvlock"] = True
                if y:
                    v["y"] = y
                    v["uvlock"] = True
                variants[f"facing={facing},half={half},shape={shape}"] = v
    write_json('blockstates/stage_steps.json', {"variants": variants})

    body, lens = b('fixture_body'), b('fixture_lens')
    head_faces = {"north": {"texture": lens}, "south": {"texture": body}, "east": {"texture": body},
                  "west": {"texture": body}, "up": {"texture": body}, "down": {"texture": body}}
    # Moving Head stehend: Sockel, Buegel, Kopf mit Linse nach vorne
    model('moving_head', {"parent": "minecraft:block/block", "textures": {"particle": body}, "elements": [
        el([3, 0, 3], [13, 3, 13], all_faces(body)),
        el([3, 3, 7], [4, 12, 9], all_faces(body)),
        el([12, 3, 7], [13, 12, 9], all_faces(body)),
        el([4, 5, 4], [12, 12, 12], head_faces, {"origin": [8, 8, 8], "axis": "x", "angle": -22.5}),
    ]})
    facing_blockstate('moving_head')
    model('moving_head_hanging', {"parent": "minecraft:block/block", "textures": {"particle": body}, "elements": [
        el([3, 13, 3], [13, 16, 13], all_faces(body)),
        el([3, 4, 7], [4, 13, 9], all_faces(body)),
        el([12, 4, 7], [13, 13, 9], all_faces(body)),
        el([4, 4, 4], [12, 11, 12], head_faces, {"origin": [8, 8, 8], "axis": "x", "angle": 22.5}),
    ]})
    facing_blockstate('moving_head_hanging')
    model('laser', {"parent": "minecraft:block/block", "textures": {"particle": body}, "elements": [
        el([2, 4, 2], [14, 12, 14], {"north": {"texture": b('laser_front')}, "south": {"texture": body},
                                     "east": {"texture": body}, "west": {"texture": body}, "up": {"texture": body},
                                     "down": {"texture": body}}),
        el([6, 12, 6], [10, 16, 10], all_faces(body)),
    ]})
    facing_blockstate('laser')
    model('strobe', {"parent": "minecraft:block/block", "textures": {"particle": body}, "elements": [
        el([1, 4, 4], [15, 12, 12], {"north": {"texture": b('strobe_front')}, "south": {"texture": body},
                                     "east": {"texture": body}, "west": {"texture": body}, "up": {"texture": body},
                                     "down": {"texture": body}}),
        el([7, 12, 7], [9, 16, 9], all_faces(body)),
    ]})
    facing_blockstate('strobe')
    model('fog_machine', {"parent": "minecraft:block/block", "textures": {"particle": b('fog_machine')}, "elements": [
        el([2, 0, 3], [14, 7, 14], all_faces(b('fog_machine'))),
        el([6, 2, 1], [10, 6, 3], all_faces(body)),
        el([4, 7, 6], [12, 9, 10], all_faces(body)),
    ]})
    facing_blockstate('fog_machine')
    # DJ-Pult: Tisch mit Front-Logo, Mischpult/Jogwheels oben, Laptop
    side = b('dj_booth_side')
    model('dj_booth', {"parent": "minecraft:block/block", "textures": {"particle": b('dj_booth_front')}, "elements": [
        el([0, 0, 1], [16, 12, 16], {"north": {"texture": b('dj_booth_front')}, "south": {"texture": side},
                                     "east": {"texture": side}, "west": {"texture": side},
                                     "up": {"texture": b('dj_booth_top')}, "down": {"texture": side}}),
        el([5, 12, 10], [11, 12.5, 15], all_faces(body)),
        el([5, 12.5, 14.5], [11, 17, 15], {"north": {"texture": b('led_panel_front')}, "south": {"texture": body},
                                           "east": {"texture": body}, "west": {"texture": body},
                                           "up": {"texture": body}, "down": {"texture": body}},
           {"origin": [8, 12.5, 15], "axis": "x", "angle": -22.5}),
    ]})
    facing_blockstate('dj_booth')
    model('dj_booth_side', {"parent": "minecraft:block/block", "textures": {"particle": side}, "elements": [
        el([0, 0, 1], [16, 12, 16], {"north": {"texture": b('dj_booth_front')}, "south": {"texture": side},
                                     "east": {"texture": side}, "west": {"texture": side},
                                     "up": {"texture": b('dj_booth_side_top')}, "down": {"texture": side}}),
    ]})
    facing_blockstate('dj_booth_side')

    write_json('models/item/stage_kit.json', {"parent": "minecraft:item/generated", "textures": {"layer0": f"{MOD}:item/stage_kit"}})


if __name__ == '__main__':
    dance_floor()
    stage_deck()
    stage_front()
    speaker_front()
    speaker_side()
    truss()
    led_panel()
    fixture()
    booth()
    stage_kit_item()
    fog_texture()
    models()
    print('Assets erzeugt in', os.path.abspath(ROOT))

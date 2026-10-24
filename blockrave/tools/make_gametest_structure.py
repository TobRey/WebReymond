"""Erzeugt die leere GameTest-Struktur (40x24x40) als NBT: data/blockrave/structure/stage_area.nbt"""
import gzip
import struct
import sys


def tag_string(s):
    b = s.encode('utf-8')
    return struct.pack('>H', len(b)) + b


def named(tag_id, name, payload):
    return bytes([tag_id]) + tag_string(name) + payload


def int_tag(name, v):
    return named(3, name, struct.pack('>i', v))


def list_tag(name, elem_type, elems):
    return named(9, name, bytes([elem_type]) + struct.pack('>i', len(elems)) + b''.join(elems))


def compound_payload(*children):
    return b''.join(children) + b'\x00'


def main(out):
    size = [struct.pack('>i', v) for v in (40, 24, 40)]
    air = compound_payload(named(8, 'Name', tag_string('minecraft:air')))
    root = compound_payload(
        int_tag('DataVersion', 3955),  # Minecraft 1.21.1
        list_tag('size', 3, size),
        list_tag('palette', 10, [air]),
        list_tag('blocks', 10, []),
        list_tag('entities', 10, []),
    )
    data = named(10, '', root)
    with gzip.open(out, 'wb') as f:
        f.write(data)


if __name__ == '__main__':
    main(sys.argv[1])

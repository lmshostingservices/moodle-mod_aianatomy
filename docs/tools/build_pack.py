#!/usr/bin/env python3
"""Build an AI Anatomy pack (model.glb, pack.json, ATTRIBUTION.txt) from defs/<pack>.skel.json + .content.json.

Geometry: BodyParts3D v3.0 binary STL. Per structure: read (and merge, for multi-mesh structures, or pick a
connected component), weld, decimate to a triangle budget (quadric decimation, fast-simplification),
smooth normals, convert axes to X patient-left / Y superior / Z anterior (mm), recentre the pack.
Normals are stored as normalised int8 (KHR_mesh_quantization); positions stay float32 in mm.
Usage: build_pack.py <pack> <outdir> [--budget 110000]
"""
import json, os, struct, sys
import numpy as np
import fast_simplification
from scipy.sparse import coo_matrix
from scipy.sparse.csgraph import connected_components
from build_glb import read_stl, convert_axes, weld, normals

SOURCE = {
    'dataset': 'BodyParts3D',
    'version': '3.0 (20110915)',
    'source_url': 'https://dbarchive.biosciencedbc.jp/en/bodyparts3d/',
    'mirror_url': 'https://github.com/Kevin-Mattheus-Moerman/BodyParts3D',
    'license': 'CC BY-SA 2.1 JP',
    'license_url': 'https://creativecommons.org/licenses/by-sa/2.1/jp/deed.en',
    'attribution': 'BodyParts3D, (c) The Database Center for Life Science, licensed under CC Attribution-Share Alike 2.1 Japan',
    'commercial_use': True,
    'share_alike': True,
    'modified': True,
    'anatomy_verified': 'Geometry and identity taken from BodyParts3D (FMA IDs preserved). Shapes simplified for the web only.',
}


def components(verts, faces):
    n = len(verts)
    e = np.concatenate([faces[:, [0, 1]], faces[:, [1, 2]]])
    g = coo_matrix((np.ones(len(e)), (e[:, 0], e[:, 1])), shape=(n, n))
    _, lab = connected_components(g, directed=False)
    fl = lab[faces[:, 0]]
    out = []
    for c in np.unique(fl):
        f = faces[fl == c]
        used = np.unique(f)
        remap = -np.ones(n, dtype=np.int64)
        remap[used] = np.arange(len(used))
        out.append((verts[used], remap[f]))
    out.sort(key=lambda m: -len(m[1]))
    return out


def pick_component(verts, faces, rule):
    """Select a connected component by anatomical position (X > 0 is patient left, Y is superior)."""
    comps = [c for c in components(verts, faces) if len(c[1]) > 200]
    side = rule.split('_')[0]
    cand = [c for c in comps if (c[0][:, 0].mean() < 0) == (side == 'right')]
    if '_' in rule:
        cand = cand[:2]
        cand.sort(key=lambda c: -c[0][:, 1].mean())
        return cand[0] if rule.endswith('superior') else cand[1]
    return cand[0]


def load(spec, stl):
    if isinstance(spec, dict):
        v, f = weld(convert_axes(read_stl(f"{stl}/{spec['fma']}.stl")))
        return pick_component(v, f, spec['component'])
    ids = spec if isinstance(spec, list) else [spec]
    vs, fs, off = [], [], 0
    for i in ids:
        v, f = weld(convert_axes(read_stl(f'{stl}/{i}.stl')))
        vs.append(v)
        fs.append(f + off)
        off += len(v)
    return np.concatenate(vs), np.concatenate(fs)


def decimate(v, f, target):
    if len(f) <= target:
        return v, f
    red = 1 - target / len(f)
    v2, f2 = fast_simplification.simplify(v.astype(np.float32), f.astype(np.int32), target_reduction=red)
    return v2.astype(np.float64), f2.astype(np.int64)


def srgb_to_linear(hexcol):
    c = [int(hexcol[i:i + 2], 16) / 255 for i in (1, 3, 5)]
    return [x / 12.92 if x <= 0.04045 else ((x + 0.055) / 1.055) ** 2.4 for x in c]


def build(pid, outdir, budget=110000, stl='stl'):
    skel = json.load(open(f'defs/{pid}.skel.json'))
    content = json.load(open(f'defs/{pid}.content.json'))
    ss = skel['structures']
    meshes = [load(s['fma'], stl) for s in ss]
    # Triangle budget ~ sqrt(size), with limits, so small bones keep their detail.
    raw = np.array([len(m[1]) for m in meshes], dtype=float)
    w = np.sqrt(raw)
    alloc = np.clip(budget * w / w.sum(), 700, 14000)
    meshes = [decimate(v, f, int(a)) for (v, f), a in zip(meshes, alloc)]
    focus = [m[0] for m, s in zip(meshes, ss) if not s['context']] or [m[0] for m in meshes]
    pts = np.concatenate(focus)
    centre = (pts.min(0) + pts.max(0)) / 2

    tissue = bool(skel.get('tissue'))
    materials = [{'name': 'bone', 'pbrMetallicRoughness': {'baseColorFactor': [0.93, 0.89, 0.8, 1],
                                                           'metallicFactor': 0, 'roughnessFactor': 0.7}}]
    matidx = {}
    binbuf = bytearray()
    views, accessors, glmeshes, nodes = [], [], [], []

    def view(data, target):
        nonlocal binbuf
        while len(binbuf) % 4:
            binbuf += b'\x00'
        views.append({'buffer': 0, 'byteOffset': len(binbuf), 'byteLength': len(data), 'target': target})
        binbuf += data
        return len(views) - 1

    tris = 0
    for i, ((v, f), s) in enumerate(zip(meshes, ss)):
        c = v.mean(0)
        local = (v - c).astype(np.float32)
        n = normals(v, f)
        nq = np.zeros((len(n), 4), dtype=np.int8)
        nq[:, :3] = np.clip(np.round(n * 127), -127, 127).astype(np.int8)
        idx = f.astype(np.uint16 if len(v) < 65535 else np.uint32)
        accessors.append({'bufferView': view(local.tobytes(), 34962), 'componentType': 5126, 'count': len(local),
                          'type': 'VEC3', 'min': local.min(0).tolist(), 'max': local.max(0).tolist()})
        pa = len(accessors) - 1
        views[-1]['byteStride'] = 12
        nv = view(nq.tobytes(), 34962)
        views[-1]['byteStride'] = 4
        accessors.append({'bufferView': nv, 'componentType': 5120, 'normalized': True, 'count': len(nq),
                          'type': 'VEC3'})
        na = len(accessors) - 1
        accessors.append({'bufferView': view(idx.ravel().tobytes(), 34963),
                          'componentType': 5123 if idx.dtype == np.uint16 else 5125, 'count': int(idx.size),
                          'type': 'SCALAR'})
        ia = len(accessors) - 1
        mat = 0
        col = s.get('colour')
        if tissue and col and s['type'] != 'bone':
            if col not in matidx:
                materials.append({'name': 'tissue', 'pbrMetallicRoughness': {
                    'baseColorFactor': srgb_to_linear(col) + [1], 'metallicFactor': 0, 'roughnessFactor': 0.6}})
                matidx[col] = len(materials) - 1
            mat = matidx[col]
        glmeshes.append({'name': f's{i}', 'primitives': [{'attributes': {'POSITION': pa, 'NORMAL': na},
                                                          'indices': ia, 'material': mat}]})
        nodes.append({'name': f's{i}', 'mesh': i, 'translation': (c - centre).tolist()})
        tris += len(f)
    nodes.append({'name': 'pack', 'children': list(range(len(ss)))})
    gltf = {'asset': {'version': '2.0', 'generator': 'mod_aianatomy build_pack.py',
                      'copyright': 'BodyParts3D, (c) The Database Center for Life Science, CC BY-SA 2.1 JP (modified)'},
            'extensionsUsed': ['KHR_mesh_quantization'], 'extensionsRequired': ['KHR_mesh_quantization'],
            'scene': 0, 'scenes': [{'nodes': [len(nodes) - 1]}], 'nodes': nodes, 'meshes': glmeshes,
            'materials': materials, 'accessors': accessors, 'bufferViews': views,
            'buffers': [{'byteLength': 0}]}
    while len(binbuf) % 4:
        binbuf += b'\x00'
    gltf['buffers'][0]['byteLength'] = len(binbuf)
    js = json.dumps(gltf, separators=(',', ':')).encode()
    while len(js) % 4:
        js += b' '
    os.makedirs(outdir, exist_ok=True)
    with open(f'{outdir}/model.glb', 'wb') as fh:
        fh.write(struct.pack('<III', 0x46546C67, 2, 12 + 8 + len(js) + 8 + len(binbuf)))
        fh.write(struct.pack('<II', len(js), 0x4E4F534A) + js)
        fh.write(struct.pack('<II', len(binbuf), 0x004E4942) + binbuf)

    # ---- pack.json
    ids = {s['id'] for s in ss}
    cs = content['structures']
    structures = []
    for i, s in enumerate(ss):
        c = cs[s['id']]
        fma = s['fma']
        onto = [{'system': 'FMA', 'id': x} for x in (fma if isinstance(fma, list) else
                                                     [fma['fma']] if isinstance(fma, dict) else [fma])]
        rels = [r for r in c.get('relationships', []) if r['target'] in ids and r['target'] != s['id']]
        st = {'id': s['id'], 'concept': s['concept'], 'node': f's{i}', 'type': s['type'], 'side': s['side'],
              'group': s['group'],
              'names': {'preferred': c.get('preferred') or s['preferred'], 'latin': c['latin'],
                        'synonyms': c.get('synonyms', [])},
              'ontology': onto, 'relationships': rels, 'content': c['content'], 'questions': c.get('questions', [])}
        if isinstance(fma, list) and len(fma) > 1:
            st['merged'] = True
        if isinstance(fma, dict):
            st['component'] = fma['component']
        if s['context']:
            st['context'] = True
        structures.append(st)
    byid = {s['id']: s for s in structures}
    for s in structures:
        for r in list(s['relationships']):
            if r['type'] in ('articulates_with', 'adjacent_to'):
                t = byid[r['target']]
                if not any(x['target'] == s['id'] and x['type'] == r['type'] for x in t['relationships']):
                    t['relationships'].append({'type': r['type'], 'target': s['id']})
    groups = []
    for gid, label, parent in skel['groups']:
        g = {'id': gid, 'label': label, 'parent': parent}
        tip = content.get('groups', {}).get(gid, {}).get('tip')
        if tip:
            g['tip'] = tip
        groups.append(g)
    presets = [{'id': a, 'label': b, 'dir': d, 'up': [0, 0, 1] if abs(d[1]) > 0.9 else [0, 1, 0]} for a, b, d in skel['presets']]
    pack = {
        'id': pid, 'version': '1.0.0', 'name': skel['name'], 'description': skel['description'],
        'system': skel['system'], 'region': skel['region'], 'model': 'model.glb', 'units': 'mm',
        'axes': {'x': 'towards patient left', 'y': 'superior', 'z': 'anterior'},
        'reference_body': 'BodyParts3D adult male reference model',
        'root': skel['root'], 'rootframe': skel['rootframe'], 'defaultgroups': skel['rootframe'],
        'presets': presets, 'defaultpreset': presets[0]['id'], 'tissue': tissue,
        'source': dict(SOURCE, conversion_notes=(
            'Binary STL converted to GLB: vertices welded, meshes simplified with quadric decimation for web '
            'delivery, smooth normals, axes converted to Y-up/Z-anterior, recentred, one node per structure. '
            'Structures marked "merged" are the union of several BodyParts3D meshes (e.g. the parts of one muscle); '
            'structures with a "component" take one connected part of a BodyParts3D mesh, chosen by position.')),
        'content_level': 'diploma', 'groups': groups, 'structures': structures,
    }
    json.dump(pack, open(f'{outdir}/pack.json', 'w'), indent=1, ensure_ascii=False)
    fmas = sorted({o['id'] for s in structures for o in s['ontology']})
    open(f'{outdir}/ATTRIBUTION.txt', 'w').write(
        f"{skel['name']} - 3D model attribution\n\n"
        "BodyParts3D, (c) The Database Center for Life Science, licensed under\n"
        "Creative Commons Attribution-Share Alike 2.1 Japan (CC BY-SA 2.1 JP).\n"
        "https://creativecommons.org/licenses/by-sa/2.1/jp/deed.en\n"
        "Source: https://dbarchive.biosciencedbc.jp/en/bodyparts3d/ (version 3.0, 20110915),\n"
        "via the STL mirror https://github.com/Kevin-Mattheus-Moerman/BodyParts3D\n"
        "Citation: Mitsuhashi N, et al. BodyParts3D: 3D structure database for anatomical concepts.\n"
        "Nucleic Acids Res. 2009;37:D782-5. doi:10.1093/nar/gkn613\n\n"
        "Modifications: converted from STL to glTF binary (GLB); vertices welded; meshes simplified\n"
        "(quadric decimation) for web delivery; normals recomputed; axes converted and recentred; some\n"
        "structures merged from several source meshes or split into connected components.\n"
        "The modified model (model.glb) is distributed under the same licence, CC BY-SA 2.1 JP.\n\n"
        "Teaching content (pack.json names, content and questions) is part of AI Anatomy (GPL v3 or later).\n\n"
        f"Source meshes (FMA IDs): {', '.join(fmas)}\n")
    size = os.path.getsize(f'{outdir}/model.glb')
    print(f'{pid}: {len(ss)} structures, {tris} triangles, {size / 1e6:.2f} MB, '
          f'{sum(len(s["questions"]) for s in structures)} questions')


if __name__ == '__main__':
    args = sys.argv[1:]
    budget = 110000
    if '--budget' in args:
        k = args.index('--budget')
        budget = int(args[k + 1])
        del args[k:k + 2]
    build(args[0], args[1], budget)

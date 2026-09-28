#!/usr/bin/env python3
"""Convert BodyParts3D binary STL files into one GLB for mod_aianatomy.

- Welds vertices and computes smooth normals (source STLs are faceted).
- Converts BodyParts3D axes (X patient-left, Y posterior, Z superior, mm)
  into the pack convention: X patient-left, Y superior, Z anterior, mm.
- Recentres the whole pack on the hand's centre.
- Each structure becomes its own node named "s<index>" whose translation is
  the structure centroid; vertices are local to that centroid so the viewer
  can explode structures by moving node positions.
"""
import json, struct, sys
import numpy as np

def read_stl(path):
    d = open(path, 'rb').read()
    n = struct.unpack('<I', d[80:84])[0]
    dt = np.dtype([('n', '<f4', 3), ('v', '<f4', (3, 3)), ('a', '<u2')])
    a = np.frombuffer(d[84:84 + n * 50], dtype=dt)
    return a['v'].reshape(-1, 3).astype(np.float64)

def convert_axes(v):
    # (X, Y, Z) -> (X, Z, -Y): proper rotation, keeps handedness.
    return np.stack([v[:, 0], v[:, 2], -v[:, 1]], axis=1)

def weld(tri_vertices, tol=1e-3):
    q = np.round(tri_vertices / tol).astype(np.int64)
    _, idx, inv = np.unique(q, axis=0, return_index=True, return_inverse=True)
    verts = tri_vertices[idx]
    faces = inv.reshape(-1, 3)
    # drop degenerate triangles
    ok = (faces[:, 0] != faces[:, 1]) & (faces[:, 1] != faces[:, 2]) & (faces[:, 0] != faces[:, 2])
    return verts, faces[ok]

def normals(verts, faces):
    fn = np.cross(verts[faces[:, 1]] - verts[faces[:, 0]], verts[faces[:, 2]] - verts[faces[:, 0]])
    vn = np.zeros_like(verts)
    for k in range(3):
        np.add.at(vn, faces[:, k], fn)
    l = np.linalg.norm(vn, axis=1, keepdims=True)
    l[l == 0] = 1
    return vn / l

def main(manifest_path, stl_dir, out_path):
    manifest = json.load(open(manifest_path))
    items = manifest['meshes']  # list of {node, fma}
    meshes = []
    for it in items:
        v = convert_axes(read_stl(f"{stl_dir}/FMA{it['fma']}.stl"))
        verts, faces = weld(v)
        meshes.append((it, verts, faces))
    # global centre = centre of hand bones (exclude forearm for framing)
    handpts = np.concatenate([m[1] for m in meshes if not m[0].get('context')])
    gcentre = (handpts.min(0) + handpts.max(0)) / 2

    bin_chunks = bytearray()
    buffer_views, accessors, gl_meshes, nodes = [], [], [], []

    def add_view(data, target):
        nonlocal bin_chunks
        while len(bin_chunks) % 4:
            bin_chunks += b'\x00'
        off = len(bin_chunks)
        bin_chunks += data
        buffer_views.append({'buffer': 0, 'byteOffset': off, 'byteLength': len(data), 'target': target})
        return len(buffer_views) - 1

    report = []
    for it, verts, faces in meshes:
        centroid = verts.mean(0)
        local = (verts - centroid).astype(np.float32)
        nrm = normals(verts, faces).astype(np.float32)
        idx = faces.astype(np.uint16 if len(verts) < 65535 else np.uint32)
        pv = add_view(local.tobytes(), 34962)
        accessors.append({'bufferView': pv, 'componentType': 5126, 'count': len(local), 'type': 'VEC3',
                          'min': local.min(0).tolist(), 'max': local.max(0).tolist()})
        pa = len(accessors) - 1
        nv = add_view(nrm.tobytes(), 34962)
        accessors.append({'bufferView': nv, 'componentType': 5126, 'count': len(nrm), 'type': 'VEC3'})
        na = len(accessors) - 1
        iv = add_view(idx.ravel().tobytes(), 34963)
        accessors.append({'bufferView': iv, 'componentType': 5123 if idx.dtype == np.uint16 else 5125,
                          'count': int(idx.size), 'type': 'SCALAR'})
        ia = len(accessors) - 1
        gl_meshes.append({'name': it['node'], 'primitives': [{'attributes': {'POSITION': pa, 'NORMAL': na},
                                                              'indices': ia, 'material': 0}]})
        t = (centroid - gcentre).tolist()
        nodes.append({'name': it['node'], 'mesh': len(gl_meshes) - 1, 'translation': t})
        report.append((it['node'], it['fma'], len(verts), len(faces)))

    root = {'name': 'pack', 'children': list(range(len(nodes)))}
    nodes.append(root)
    gltf = {
        'asset': {'version': '2.0', 'generator': 'mod_aianatomy build_glb.py',
                  'copyright': 'BodyParts3D, (c) The Database Center for Life Science, CC BY-SA 2.1 JP (modified)'},
        'scene': 0, 'scenes': [{'nodes': [len(nodes) - 1]}], 'nodes': nodes, 'meshes': gl_meshes,
        'materials': [{'name': 'bone', 'pbrMetallicRoughness': {'baseColorFactor': [0.93, 0.89, 0.8, 1],
                                                                'metallicFactor': 0, 'roughnessFactor': 0.7}}],
        'accessors': accessors, 'bufferViews': buffer_views, 'buffers': [{'byteLength': len(bin_chunks)}],
    }
    while len(bin_chunks) % 4:
        bin_chunks += b'\x00'
    gltf['buffers'][0]['byteLength'] = len(bin_chunks)
    js = json.dumps(gltf, separators=(',', ':')).encode()
    while len(js) % 4:
        js += b' '
    total = 12 + 8 + len(js) + 8 + len(bin_chunks)
    with open(out_path, 'wb') as f:
        f.write(struct.pack('<III', 0x46546C67, 2, total))
        f.write(struct.pack('<II', len(js), 0x4E4F534A)); f.write(js)
        f.write(struct.pack('<II', len(bin_chunks), 0x004E4942)); f.write(bin_chunks)
    for r in report:
        print(*r)
    print('bytes', total)

if __name__ == '__main__':
    main(*sys.argv[1:4])

# Anatomy engine and packs

Two rules shape the design:

1. **Anatomy is structured data linked to selectable 3D objects.** The viewer (`amd/src/viewer.js`) only renders, picks, highlights, fades, hides, isolates, explodes and frames nodes by name. It contains no anatomy names, systems, labels, questions or teaching text. All of that comes from `pack.json` and the database.
2. **AI is an authoring assistant, not the source of anatomical truth.** Geometry and structure identity come from curated datasets. Teachers choose structures and anchor labels. AI drafts explanations, mnemonics, hints and questions, and teachers approve them.

## Pack layout
```
packs/<packid>/
  model.glb          one glTF node per structure, named s0..sN (neutral names)
  pack.json          anatomy data (below)
  ATTRIBUTION.txt    source, licence and modifications
```

### Coordinate convention
Units are millimetres. X points towards the patient's left, Y is superior and Z is anterior (a proper rotation of the BodyParts3D axes). Each node's translation is the structure's centroid, and its vertices are local to it, so explode = move node positions.

### pack.json
```json
{
  "id": "hand_right", "version": "1.0.0", "name": "...", "system": "skeletal", "region": "upper_limb",
  "model": "model.glb", "units": "mm", "axes": {...}, "reference_body": "...",
  "root": "hand", "defaultgroup": "carpals",
  "presets": [{"id": "palmar", "label": "Palmar", "dir": [0,0,1], "up": [0,1,0]}],
  "source": {"dataset", "version", "source_url", "license", "attribution", "commercial_use", "share_alike",
             "modified", "conversion_notes"},
  "groups": [{"id": "carpals", "label": "Carpal bones", "parent": "hand", "tip": "group mnemonic"}],
  "structures": [{
    "id": "skeletal_hand_right_scaphoid",   // stable ID: system_region_side_structure
    "concept": "scaphoid",                  // shared across left/right instances
    "node": "s2", "type": "bone", "side": "right", "group": "carpals_proximal",
    "names": {"preferred": "Scaphoid", "latin": "Os scaphoideum", "synonyms": []},
    "ontology": [{"system": "FMA", "id": "FMA24435"}],
    "relationships": [{"type": "articulates_with", "target": "skeletal_forearm_right_radius"}],
    "explode": [0.8, -0.75, 0.5],           // optional manual explode direction
    "content": {"pronunciation", "origin", "location", "description", "function", "mnemonic",
                "clinical", "hint"},       // library teaching content
    "questions": [{"kind", "text", "options", "answer", "explanation"}]
  }]
}
```
- **Groups** make up the drill-down hierarchy. The top-level groups (children of `root`) are the drill-down levels, and they are also the Practice and Test rounds. Deeper groups (such as the proximal and distal carpal rows) are used for relationships, distractors and prompts.
- **Relationship types** are open. The data model allows `part_of`, `adjacent_to`, `articulates_with`, `attaches_to`, `origin_on`, `inserts_on`, `innervated_by`, `supplied_by`, `drains_to`, `passes_through` and `contains`.
- **Structure types** are open too (`bone`, `muscle`, `tendon`, `ligament`, `nerve`, `artery`, `vein`, `organ` and so on). Nothing in the viewer assumes a structure is a bone.

### Extra fields (1.1.0)
- `tissue: true` — the GLB has one material per colour (material name `tissue`); the viewer keeps each structure's colour for the default, hover and muted states. Bone packs use one `bone` material.
- `rootframe` / `defaultgroups` — top-level groups framed at the start of Study and enabled in new activities. Groups not listed (for example "Bones (context)") start disabled and show "Not tested".
- Structure `merged: true` — the node is the union of several BodyParts3D meshes (all FMA IDs are in `ontology`). `component: "right_superior"` — the node is one connected part of a mesh, chosen by position (used for the four pulmonary veins and the two bronchial trees). `context: true` — shown for orientation.
- Normals are stored as normalised int8 (`KHR_mesh_quantization`); positions stay float32 in millimetres, so anchors and explode vectors are in mm.

## Building a pack from BodyParts3D
1. `lookup.py` finds FMA IDs in `parts_list_e.txt` and checks that the STL exists in the mirror.
2. Add the pack to `skel.py` (structures, groups, presets, colours, merged/component structures) and run it to write `defs/<pack>.skel.json`.
3. Write `defs/<pack>.content.json` (names, Latin, relationships, content, questions; see `CONTENT_BRIEF.md`) and check it with `validate.py <pack>`.
4. `build_pack.py <pack> <outdir>` downloads nothing: it reads the STLs from `stl/`, welds, simplifies (about 110,000 triangles per pack, more for small bones), converts axes, recentres and writes `model.glb`, `pack.json` and `ATTRIBUTION.txt`.
5. Keep the licence metadata for every source. Z-Anatomy is CC BY-SA, but some of its models are NC, so they must be tracked separately. Open Anatomy atlases have their own terms.

## JavaScript build
`amd/src/*.js` are ES modules. `amd/src/three.js` is the three.js subset (core, OrbitControls, GLTFLoader) bundled as ESM. To rebuild with Moodle's grunt: `npx grunt amd --root=mod/aianatomy`. `docs/tools/build_amd.js` is the standalone builder used for this release (babel AMD with named defines, plus terser).

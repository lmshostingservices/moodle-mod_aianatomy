#!/usr/bin/env python3
"""Generate packs/hand_right/pack.json for mod_aianatomy.

All anatomical identity comes from BodyParts3D (FMA IDs). Library teaching
content below was written for Certificate/Diploma level and is shipped as
'library' content: teachers can edit it, regenerate it with AI and approve it.
"""
import json, sys

DIGITS = {1: 'thumb', 2: 'index finger', 3: 'middle finger', 4: 'ring finger', 5: 'little finger'}
ROMAN = {1: 'I', 2: 'II', 3: 'III', 4: 'IV', 5: 'V'}
ORD = {1: 'first', 2: 'second', 3: 'third', 4: 'fourth', 5: 'fifth'}

S = []  # structures
meshes = []

def add(sid, node, fma, group, names, content, rel=None, questions=None, concept=None, context=False):
    meshes.append({'node': node, 'fma': fma.replace('FMA', ''), 'context': context})
    S.append({
        'id': sid,
        'concept': concept or sid.replace('skeletal_hand_right_', '').replace('skeletal_forearm_right_', ''),
        'node': node,
        'type': 'bone',
        'side': 'right',
        'group': group,
        'names': names,
        'ontology': [{'system': 'FMA', 'id': fma}],
        'relationships': [{'type': 'articulates_with', 'target': t} for t in (rel or [])],
        'content': content,
        'questions': questions or [],
    })

H = 'skeletal_hand_right_'
F = 'skeletal_forearm_right_'

def q(text, options, answer, explanation, kind='function'):
    return {'kind': kind, 'text': text, 'options': options, 'answer': answer, 'explanation': explanation}

# ---- Forearm (context) ----
add(F + 'radius', 's0', 'FMA23464', 'forearm',
    {'preferred': 'Radius', 'latin': 'Radius', 'synonyms': []},
    {'pronunciation': 'RAY-dee-us',
     'origin': 'Latin radius, "spoke of a wheel" or "ray". The radius turns around the ulna like a spoke.',
     'location': 'The lateral (thumb-side) bone of the forearm. Its wide lower end forms most of the wrist joint.',
     'description': 'A long bone that is narrow at the elbow and broad at the wrist.',
     'function': 'Carries most of the load from the hand to the forearm and rotates around the ulna to turn the palm up and down (supination and pronation).',
     'mnemonic': 'Radius sits by the thumb: "thumbs up for the radius".',
     'clinical': 'A Colles fracture is a break of the distal radius, usually from a fall on an outstretched hand.',
     'hint': 'The big forearm bone on the thumb side, meeting the scaphoid and lunate.'},
    [H + 'scaphoid', H + 'lunate', F + 'ulna'],
    [q('Which movement depends on the radius rotating around the ulna?',
       ['Flexing the fingers', 'Turning the palm up and down', 'Spreading the fingers apart', 'Bending the elbow only'], 1,
       'The radius rotates around the ulna during pronation and supination.')], context=True)

add(F + 'ulna', 's1', 'FMA23467', 'forearm',
    {'preferred': 'Ulna', 'latin': 'Ulna', 'synonyms': []},
    {'pronunciation': 'UL-nah',
     'origin': 'Latin ulna, "elbow" or "forearm".',
     'location': 'The medial (little-finger side) bone of the forearm.',
     'description': 'A long bone that is large at the elbow and small at the wrist, ending in the ulnar styloid process.',
     'function': 'Forms the main hinge of the elbow and acts as the fixed bone the radius rotates around. At the wrist it is separated from the carpal bones by a cartilage disc.',
     'mnemonic': 'Ulna is by the little finger: "U and the little finger point the same way".',
     'clinical': 'The ulnar styloid is often broken together with a distal radius fracture.',
     'hint': 'The forearm bone on the little-finger side.'},
    [F + 'radius'],
    [q('At the wrist, the ulna is separated from the carpal bones by what?',
       ['A cartilage disc', 'The pisiform', 'The capitate', 'Nothing - it joins the lunate directly'], 0,
       'An articular disc (part of the triangular fibrocartilage complex) sits between the ulna and the carpal bones.',
       'relationship')], context=True)

# ---- Carpals ----
add(H + 'scaphoid', 's2', 'FMA24435', 'carpals_proximal',
    {'preferred': 'Scaphoid', 'latin': 'Os scaphoideum', 'synonyms': ['Navicular of the hand (older term)']},
    {'pronunciation': 'SKAF-oyd',
     'origin': 'Greek skaphe, "boat", plus -oeides, "like". It is shaped a little like a boat.',
     'location': 'Proximal row of the carpal bones, on the thumb (lateral) side, next to the radius.',
     'description': 'The largest bone of the proximal carpal row. It is long and curved and bridges the two carpal rows.',
     'function': 'Links the proximal and distal carpal rows, helping the wrist move smoothly and stay stable. It also carries force from the thumb side of the hand to the radius.',
     'mnemonic': '"Scaphoid sails beside the thumb" - a little boat on the thumb side.',
     'clinical': 'The most commonly fractured carpal bone, usually from a fall on an outstretched hand. Tenderness in the anatomical snuffbox is a warning sign. Its blood supply enters at the far end, so the near part can lose its blood supply after a fracture.',
     'hint': 'Look in the first row of wrist bones, on the thumb side, right next to the radius.'},
    [F + 'radius', H + 'lunate', H + 'trapezium', H + 'trapezoid', H + 'capitate'],
    [q('Which is the most commonly fractured carpal bone?', ['Hamate', 'Scaphoid', 'Pisiform', 'Trapezoid'], 1,
       'The scaphoid is the most commonly fractured carpal bone, usually after a fall on an outstretched hand.', 'clinical'),
     q('What is an important role of the scaphoid?',
       ['It forms the elbow joint', 'It links the two rows of carpal bones', 'It is a sesamoid bone in a tendon', 'It forms the knuckle of the index finger'], 1,
       'The scaphoid bridges the proximal and distal carpal rows, helping wrist movement and stability.')])

add(H + 'lunate', 's3', 'FMA24437', 'carpals_proximal',
    {'preferred': 'Lunate', 'latin': 'Os lunatum', 'synonyms': ['Semilunar bone (older term)']},
    {'pronunciation': 'LOO-nate',
     'origin': 'Latin luna, "moon". Seen from the side it has a crescent-moon shape.',
     'location': 'Centre of the proximal carpal row, between the scaphoid and the triquetrum.',
     'description': 'A crescent-shaped bone that sits in the middle of the wrist, directly beyond the radius.',
     'function': 'Forms part of the main wrist joint with the radius and passes force from the capitate to the forearm.',
     'mnemonic': '"Lunate is the moon in the middle of the wrist."',
     'clinical': 'The most commonly dislocated carpal bone. Loss of its blood supply causes Kienbock disease.',
     'hint': 'The middle bone of the first row of wrist bones.'},
    [F + 'radius', H + 'scaphoid', H + 'triquetrum', H + 'capitate', H + 'hamate'],
    [q('Which carpal bone is most commonly dislocated?', ['Lunate', 'Capitate', 'Trapezium', 'Pisiform'], 0,
       'The lunate is the most commonly dislocated carpal bone.', 'clinical'),
     q('Where is the lunate?',
       ['Middle of the proximal carpal row', 'Base of the thumb', 'Little-finger side of the distal row', 'Inside a tendon on the palm'], 0,
       'The lunate sits in the centre of the proximal row, between the scaphoid and triquetrum.', 'location')])

add(H + 'triquetrum', 's4', 'FMA24439', 'carpals_proximal',
    {'preferred': 'Triquetrum', 'latin': 'Os triquetrum', 'synonyms': ['Triquetral bone', 'Cuneiform bone of the hand (older term)']},
    {'pronunciation': 'try-KWEE-trum',
     'origin': 'Latin triquetrus, "three-cornered". It is roughly pyramid-shaped.',
     'location': 'Proximal carpal row on the little-finger (medial) side, beside the lunate. The pisiform sits on its palm side.',
     'description': 'A pyramid-shaped bone with a small oval facet on its front for the pisiform.',
     'function': 'Helps form the ulnar side of the wrist and moves with the other proximal row bones. It transfers load through the cartilage disc to the ulna.',
     'mnemonic': '"Tri = three corners, on the pinky side."',
     'clinical': 'The second most commonly fractured carpal bone, often as a small chip off its back surface.',
     'hint': 'First row of wrist bones, on the little-finger side, with the pea-shaped pisiform sitting on it.'},
    [H + 'lunate', H + 'pisiform', H + 'hamate'],
    [q('Which bone sits on the palm side of the triquetrum?', ['Hamate', 'Pisiform', 'Lunate', 'Capitate'], 1,
       'The pisiform articulates with the front (palmar) surface of the triquetrum.', 'relationship')])

add(H + 'pisiform', 's5', 'FMA24441', 'carpals_proximal',
    {'preferred': 'Pisiform', 'latin': 'Os pisiforme', 'synonyms': []},
    {'pronunciation': 'PIZ-ih-form (or PY-sih-form)',
     'origin': 'Latin pisum, "pea", plus forma, "shape". It is small and pea-shaped.',
     'location': 'On the palm side of the triquetrum, at the little-finger side of the wrist crease.',
     'description': 'The smallest carpal bone. It sits inside the tendon of flexor carpi ulnaris.',
     'function': 'Acts as a sesamoid bone, giving the flexor carpi ulnaris tendon better leverage. It is also an attachment point for the flexor retinaculum.',
     'mnemonic': '"Pisiform is the pea you can feel at the pinky side of the wrist."',
     'clinical': 'It forms one wall of Guyon canal, through which the ulnar nerve and artery pass into the hand.',
     'hint': 'The tiny pea-shaped bone on the palm side of the little-finger edge of the wrist.'},
    [H + 'triquetrum'],
    [q('Why is the pisiform called a sesamoid bone?',
       ['It is the largest carpal bone', 'It develops within a tendon', 'It has a hook', 'It forms the thumb joint'], 1,
       'The pisiform develops within the tendon of flexor carpi ulnaris, which makes it a sesamoid bone.')])

add(H + 'trapezium', 's6', 'FMA24443', 'carpals_distal',
    {'preferred': 'Trapezium', 'latin': 'Os trapezium', 'synonyms': ['Greater multangular (older term)']},
    {'pronunciation': 'trah-PEE-zee-um',
     'origin': 'Greek trapezion, "little table", a four-sided figure.',
     'location': 'Distal carpal row on the thumb side, at the base of the first metacarpal.',
     'description': 'An irregular bone with a saddle-shaped surface for the thumb and a groove for the flexor carpi radialis tendon.',
     'function': 'Forms the saddle joint at the base of the thumb, which allows the thumb to move across the palm (opposition).',
     'mnemonic': '"TrapeziUM sits under the thUMb."',
     'clinical': 'The thumb base joint (trapezium and first metacarpal) is a very common site of osteoarthritis.',
     'hint': 'Second row of wrist bones, directly below the thumb metacarpal.'},
    [H + 'scaphoid', H + 'trapezoid', H + 'metacarpal_1', H + 'metacarpal_2'],
    [q('Which movement is made possible by the saddle joint between the trapezium and the first metacarpal?',
       ['Thumb opposition', 'Wrist extension only', 'Elbow flexion', 'Little-finger abduction'], 0,
       'The saddle-shaped trapezium lets the thumb move across the palm to touch the other fingers.'),
     q('How can you tell the trapezium from the trapezoid?',
       ['The trapezium is under the thumb', 'The trapezium is under the little finger', 'The trapezium is in the proximal row', 'The trapezium is inside a tendon'], 0,
       'Remember "trapeziUM under the thUMb". The trapezoid sits next to it, under the index finger.', 'terminology')])

add(H + 'trapezoid', 's7', 'FMA23725', 'carpals_distal',
    {'preferred': 'Trapezoid', 'latin': 'Os trapezoideum', 'synonyms': ['Lesser multangular (older term)']},
    {'pronunciation': 'TRAP-eh-zoyd',
     'origin': 'Greek trapezoeides, "table-shaped".',
     'location': 'Distal carpal row, between the trapezium and the capitate, at the base of the second metacarpal.',
     'description': 'A small wedge-shaped bone, the smallest bone of the distal row.',
     'function': 'Holds the base of the index finger metacarpal firmly in place, making the index finger a stable pillar of the hand.',
     'mnemonic': '"TrapezOID is the Other one - beside the trapezium, under the index finger."',
     'clinical': 'Rarely injured on its own because it is well protected and tightly bound to its neighbours.',
     'hint': 'Second row of wrist bones, between the thumb-side bone and the big central bone.'},
    [H + 'scaphoid', H + 'trapezium', H + 'capitate', H + 'metacarpal_2'],
    [q('Which metacarpal does the trapezoid mainly support?', ['First', 'Second', 'Fourth', 'Fifth'], 1,
       'The trapezoid sits at the base of the second (index finger) metacarpal.', 'relationship')])

add(H + 'capitate', 's8', 'FMA24446', 'carpals_distal',
    {'preferred': 'Capitate', 'latin': 'Os capitatum', 'synonyms': ['Os magnum (older term)']},
    {'pronunciation': 'KAP-ih-tate',
     'origin': 'Latin caput, "head". It has a rounded head that fits into the proximal row.',
     'location': 'Centre of the distal carpal row, at the base of the third metacarpal.',
     'description': 'The largest carpal bone, with a rounded head that sits in a hollow formed by the scaphoid and lunate.',
     'function': 'Acts as the keystone of the wrist. It carries force from the middle finger to the lunate and radius and is the centre of wrist movement.',
     'mnemonic': '"Capitate is the Captain - biggest and in the centre."',
     'clinical': 'It is the first carpal bone to ossify, which makes it useful when estimating bone age on X-ray.',
     'hint': 'The biggest wrist bone, in the middle of the second row, below the middle finger.'},
    [H + 'scaphoid', H + 'lunate', H + 'trapezoid', H + 'hamate', H + 'metacarpal_2', H + 'metacarpal_3', H + 'metacarpal_4'],
    [q('Which is the largest carpal bone?', ['Capitate', 'Scaphoid', 'Hamate', 'Lunate'], 0,
       'The capitate is the largest carpal bone and sits in the centre of the distal row.', 'location')])

add(H + 'hamate', 's9', 'FMA24448', 'carpals_distal',
    {'preferred': 'Hamate', 'latin': 'Os hamatum', 'synonyms': ['Unciform bone (older term)']},
    {'pronunciation': 'HAM-ate',
     'origin': 'Latin hamus, "hook". It has a hook-like projection on its palm side.',
     'location': 'Distal carpal row on the little-finger side, at the base of the fourth and fifth metacarpals.',
     'description': 'A wedge-shaped bone with a hook (the hook of hamate) that projects towards the palm.',
     'function': 'Supports the ring and little finger metacarpals. Its hook anchors the flexor retinaculum, which forms the roof of the carpal tunnel.',
     'mnemonic': '"Hamate has a hook - hang your ring and pinky on it."',
     'clinical': 'The hook can break in golf, tennis and baseball when the handle presses into the palm. It is also a boundary of Guyon canal (ulnar nerve).',
     'hint': 'Second row of wrist bones, on the little-finger side. Look for the hook.'},
    [H + 'lunate', H + 'triquetrum', H + 'capitate', H + 'metacarpal_4', H + 'metacarpal_5'],
    [q('Which feature gives the hamate its name?', ['A groove', 'A hook', 'A crescent shape', 'A saddle-shaped surface'], 1,
       'Hamate comes from the Latin hamus, "hook", after the hook of hamate.', 'terminology'),
     q('In which sport is a fracture of the hook of hamate typically seen?',
       ['Swimming', 'Golf', 'Running', 'Diving'], 1,
       'A club, racquet or bat handle pressing into the palm can fracture the hook of hamate.', 'clinical')])

# ---- Metacarpals ----
mc_rel = {
    1: [H + 'trapezium', H + 'proximal_phalanx_1'],
    2: [H + 'trapezium', H + 'trapezoid', H + 'capitate', H + 'metacarpal_3', H + 'proximal_phalanx_2'],
    3: [H + 'capitate', H + 'metacarpal_2', H + 'metacarpal_4', H + 'proximal_phalanx_3'],
    4: [H + 'capitate', H + 'hamate', H + 'metacarpal_3', H + 'metacarpal_5', H + 'proximal_phalanx_4'],
    5: [H + 'hamate', H + 'metacarpal_4', H + 'proximal_phalanx_5'],
}
mc_extra = {
    1: ('The shortest and thickest metacarpal. It sits at an angle to the others so the thumb can oppose.',
        'Lets the thumb move independently and powerfully, especially in gripping and pinching.',
        'A Bennett fracture is a break into the joint at the base of the first metacarpal.'),
    2: ('Usually the longest metacarpal, with a notched base that locks against the trapezoid.',
        'Forms a stable, fixed pillar for the index finger, which is important for precision grip.',
        'Because it is firmly fixed at the base, force is often transmitted along it to the carpal bones.'),
    3: ('Has a small styloid process at the back of its base.',
        'Forms the central, most stable pillar of the hand, in line with the capitate.',
        'The styloid process at its base can form an extra bony lump (carpal boss) on the back of the hand.'),
    4: ('Thinner than the second and third metacarpals and more mobile at its base.',
        'Supports the ring finger and allows a little movement that helps cup the palm.',
        'Its neck may break together with the fifth in a punch injury.'),
    5: ('The most mobile metacarpal at its base, where it meets the hamate.',
        'Supports the little finger and lets the ulnar side of the palm cup around objects for a firm grip.',
        'A boxer fracture is a break of the neck of the fifth metacarpal, often from punching a hard surface.'),
}
for d, fma in zip(range(1, 6), ['FMA24464', 'FMA24466', 'FMA24468', 'FMA24470', 'FMA24472']):
    desc, func, clin = mc_extra[d]
    sib_names = ['First', 'Second', 'Third', 'Fourth', 'Fifth']
    questions = []
    if d == 5:
        questions.append(q('A "boxer fracture" most commonly involves which bone?',
                           ['Fifth metacarpal', 'Scaphoid', 'First metacarpal', 'Distal radius'], 0,
                           'A boxer fracture is a break of the neck of the fifth metacarpal.', 'clinical'))
    elif d == 1:
        questions.append(q('Which metacarpal is shortest and thickest?', ['First', 'Second', 'Third', 'Fifth'], 0,
                           'The first (thumb) metacarpal is the shortest and thickest.'))
    else:
        opts = ['Trapezium', 'Capitate', 'Pisiform', 'Radius']
        ans = {2: 'Trapezoid', 3: 'Capitate', 4: 'Hamate'}[d]
        opts = [ans] + [o for o in ['Pisiform', 'Radius', 'Lunate'] if o != ans]
        questions.append(q(f'Which carpal bone does the {ORD[d]} metacarpal articulate with?', opts, 0,
                           f'The {ORD[d]} metacarpal articulates with the {ans.lower()}' + (' (and also the capitate).' if d == 4 else '.'),
                           'relationship'))
    add(H + f'metacarpal_{d}', f's{9 + d}', fma, 'metacarpals',
        {'preferred': f'{ORD[d].capitalize()} metacarpal', 'latin': f'Os metacarpi {ROMAN[d]}',
         'synonyms': [f'Metacarpal {ROMAN[d]}', f'{DIGITS[d].capitalize()} metacarpal']},
        {'pronunciation': 'met-ah-KAR-pul',
         'origin': 'Greek meta, "beyond", plus karpos, "wrist": the bones beyond the wrist.',
         'location': f'In the palm, between the carpal bones and the {DIGITS[d]}. Metacarpals are numbered from the thumb (I) to the little finger (V).',
         'description': 'A long bone with a base (near the wrist), a shaft and a head (the knuckle). ' + desc,
         'function': func,
         'mnemonic': f'Count from the thumb: thumb = I, so the {DIGITS[d]} = {ROMAN[d]}. The heads are your knuckles.',
         'clinical': clin,
         'hint': f'In the palm, in line with the {DIGITS[d]}.'},
        mc_rel[d], questions, concept=f'metacarpal_{d}')

# ---- Phalanges ----
PROX = ['FMA24450', 'FMA24451', 'FMA24452', 'FMA24453', 'FMA24454']
MID = {2: 'FMA24455', 3: 'FMA24456', 4: 'FMA24457', 5: 'FMA24458'}
DIST = ['FMA24459', 'FMA24460', 'FMA24461', 'FMA24462', 'FMA24463']
PHAL_ORIGIN = 'Greek phalanx, "a line of soldiers": the finger bones line up in rows.'
node = 15
for d in range(1, 6):
    nxt = H + ('distal_phalanx_1' if d == 1 else f'middle_phalanx_{d}')
    qs = []
    if d == 1:
        qs.append(q('How many phalanges does the thumb have?', ['One', 'Two', 'Three', 'Four'], 1,
                    'The thumb has only a proximal and a distal phalanx. The other fingers have three.'))
    add(H + f'proximal_phalanx_{d}', f's{node}', PROX[d - 1], 'phalanges_proximal',
        {'preferred': f'Proximal phalanx of {DIGITS[d]}', 'latin': f'Phalanx proximalis digiti {ROMAN[d]} manus', 'synonyms': []},
        {'pronunciation': 'FAL-anks (plural: fa-LAN-jeez)',
         'origin': PHAL_ORIGIN,
         'location': f'The first bone of the {DIGITS[d]}, just beyond the knuckle (metacarpal head).',
         'description': 'The longest phalanx of each digit, with a concave base that fits the rounded metacarpal head.',
         'function': 'Forms the knuckle joint with the metacarpal, which allows the finger to bend, straighten and spread.',
         'mnemonic': 'Proximal = closest to the palm.',
         'clinical': 'Fractures of the proximal phalanx can twist the finger (rotational deformity) if not aligned correctly.',
         'hint': f'The {DIGITS[d]} bone closest to the palm.'},
        [H + f'metacarpal_{d}', nxt], qs, concept=f'proximal_phalanx_{d}')
    node += 1
for d in range(2, 6):
    qs = []
    if d == 3:
        qs.append(q('Which digit has no middle phalanx?', ['Thumb', 'Index finger', 'Ring finger', 'Little finger'], 0,
                    'The thumb has only proximal and distal phalanges.'))
    add(H + f'middle_phalanx_{d}', f's{node}', MID[d], 'phalanges_middle',
        {'preferred': f'Middle phalanx of {DIGITS[d]}', 'latin': f'Phalanx media digiti {ROMAN[d]} manus', 'synonyms': ['Intermediate phalanx']},
        {'pronunciation': 'FAL-anks (plural: fa-LAN-jeez)',
         'origin': PHAL_ORIGIN,
         'location': f'The middle bone of the {DIGITS[d]}. The thumb has no middle phalanx.',
         'description': 'Shorter than the proximal phalanx, with joints at both ends.',
         'function': 'Adds a second bending joint to the finger. The flexor digitorum superficialis tendon attaches here to bend the middle finger joint.',
         'mnemonic': 'Middle = in the middle, and the thumb skips it.',
         'clinical': 'Injury to the middle finger joint can cause a boutonniere or swan-neck deformity.',
         'hint': f'The middle bone of the {DIGITS[d]}.'},
        [H + f'proximal_phalanx_{d}', H + f'distal_phalanx_{d}'], qs, concept=f'middle_phalanx_{d}')
    node += 1
for d in range(1, 6):
    prev = H + ('proximal_phalanx_1' if d == 1 else f'middle_phalanx_{d}')
    qs = []
    if d == 2:
        qs.append(q('What is "mallet finger"?',
                    ['A tendon injury at the distal phalanx so the fingertip droops', 'A break of the fifth metacarpal', 'Arthritis at the thumb base', 'A dislocated lunate'], 0,
                    'Mallet finger is an injury to the extensor tendon where it attaches to the distal phalanx, so the fingertip cannot straighten.', 'clinical'))
    add(H + f'distal_phalanx_{d}', f's{node}', DIST[d - 1], 'phalanges_distal',
        {'preferred': f'Distal phalanx of {DIGITS[d]}', 'latin': f'Phalanx distalis digiti {ROMAN[d]} manus', 'synonyms': []},
        {'pronunciation': 'FAL-anks (plural: fa-LAN-jeez)',
         'origin': PHAL_ORIGIN,
         'location': f'The fingertip bone of the {DIGITS[d]}.',
         'description': 'A small bone with a rough, flattened tip (the tuberosity) that supports the fingertip pulp and nail.',
         'function': 'Supports the fingertip and nail for fine touch and pinch. The deep flexor tendon attaches here to bend the fingertip.',
         'mnemonic': 'Distal = distant from the palm: the fingertip.',
         'clinical': 'Crush injuries often break the tip (tuft fracture). Mallet finger is an injury to the extensor tendon at the base of this bone.',
         'hint': f'The tip bone of the {DIGITS[d]}.'},
        [prev], qs, concept=f'distal_phalanx_{d}')
    node += 1

# Manual explode directions (pack author overrides) for structures that sit in front of a neighbour.
for s_ in S:
    if s_['id'] == H + 'pisiform':
        s_['explode'] = [0.8, -0.75, 0.5]

# Keep only relationship targets that exist in this pack.
ids = {s['id'] for s in S}
for s in S:
    s['relationships'] = [r for r in s['relationships'] if r['target'] in ids]
    # symmetric articulations
for s in S:
    for r in s['relationships']:
        t = next(x for x in S if x['id'] == r['target'])
        if not any(rr['target'] == s['id'] for rr in t['relationships']):
            t['relationships'].append({'type': 'articulates_with', 'target': s['id']})

groups = [
    {'id': 'hand', 'label': 'Hand and wrist', 'parent': None},
    {'id': 'forearm', 'label': 'Forearm (distal)', 'parent': 'hand'},
    {'id': 'carpals', 'label': 'Carpal bones', 'parent': 'hand',
     'tip': 'Carpal mnemonic: "So Long To Pinky, Here Comes The Thumb" - Scaphoid, Lunate, Triquetrum, Pisiform (proximal row, thumb to pinky), then Hamate, Capitate, Trapezoid, Trapezium (distal row, pinky to thumb).'},
    {'id': 'carpals_proximal', 'label': 'Proximal carpal row', 'parent': 'carpals'},
    {'id': 'carpals_distal', 'label': 'Distal carpal row', 'parent': 'carpals'},
    {'id': 'metacarpals', 'label': 'Metacarpals', 'parent': 'hand',
     'tip': 'Metacarpals are numbered I to V starting at the thumb. Their heads form your knuckles.'},
    {'id': 'phalanges', 'label': 'Phalanges', 'parent': 'hand',
     'tip': 'Each finger has three phalanges (proximal, middle, distal). The thumb has two: 14 in total.'},
    {'id': 'phalanges_proximal', 'label': 'Proximal phalanges', 'parent': 'phalanges'},
    {'id': 'phalanges_middle', 'label': 'Middle phalanges', 'parent': 'phalanges'},
    {'id': 'phalanges_distal', 'label': 'Distal phalanges', 'parent': 'phalanges'},
]

pack = {
    'id': 'hand_right',
    'version': '1.0.0',
    'name': 'Hand and wrist bones (right)',
    'description': 'Right hand and wrist skeleton: 8 carpals, 5 metacarpals, 14 phalanges, with the distal radius and ulna for context.',
    'system': 'skeletal',
    'region': 'upper_limb',
    'model': 'model.glb',
    'units': 'mm',
    'axes': {'x': 'towards patient left', 'y': 'superior', 'z': 'anterior'},
    'reference_body': 'BodyParts3D adult male reference model',
    'root': 'hand',
    'defaultgroup': 'carpals',
    'presets': [
        {'id': 'palmar', 'label': 'Palmar', 'dir': [0, 0, 1], 'up': [0, 1, 0]},
        {'id': 'dorsal', 'label': 'Dorsal', 'dir': [0, 0, -1], 'up': [0, 1, 0]},
        {'id': 'radial', 'label': 'Radial (thumb side)', 'dir': [-1, 0, 0], 'up': [0, 1, 0]},
        {'id': 'ulnar', 'label': 'Ulnar (little-finger side)', 'dir': [1, 0, 0], 'up': [0, 1, 0]},
    ],
    'defaultpreset': 'palmar',
    'source': {
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
        'conversion_notes': 'Binary STL converted to GLB: vertices welded, smooth normals, axes converted to Y-up/Z-anterior, recentred on the hand, one node per structure. Geometry otherwise unchanged.',
        'anatomy_verified': 'Geometry and identity taken unchanged from BodyParts3D (FMA IDs preserved).',
    },
    'content_level': 'diploma',
    'groups': groups,
    'structures': S,
}

json.dump(pack, open(sys.argv[1], 'w'), indent=1, ensure_ascii=False)
json.dump({'meshes': meshes}, open(sys.argv[2], 'w'), indent=1)
print(len(S), 'structures;', sum(len(s['questions']) for s in S), 'questions')

#!/usr/bin/env python3
"""Pack skeletons: structure identity (FMA ids from BodyParts3D), groups and hierarchy.

A structure's 'fma' is either one FMA id, a list (the union of verified meshes, e.g. the three
parts of deltoid), or {'fma': id, 'component': rule} to take one connected component of a mesh
(e.g. one of the four pulmonary veins). Teaching content lives in defs/<pack>.content.json.
"""
import json, os

PACKS = {}
ORD = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth',
       'eleventh', 'twelfth']


def pack(pid, **kw):
    kw['id'] = pid
    kw.setdefault('structures', [])
    PACKS[pid] = kw
    return kw


def S(p, sid, fma, group, name, stype='bone', side=None, context=False, colour=None, concept=None):
    p['structures'].append({'id': sid, 'fma': fma, 'group': group, 'preferred': name, 'type': stype,
                            'side': side, 'context': context, 'colour': colour,
                            'concept': concept or sid.split('_', 3)[-1]})


# ---------------------------------------------------------------- Skull
p = pack('skull', name='Skull', system='skeletal', region='head',
         description='The 22 bones of the skull plus the hyoid: cranial bones, facial bones and the mandible.',
         root='skull', rootframe=['cranial', 'facial', 'mandible_hyoid'],
         presets=[('anterior', 'Anterior', [0, 0, 1]), ('lateral', 'Right lateral', [-1, 0, 0]),
                  ('posterior', 'Posterior', [0, 0, -1]), ('superior', 'Superior', [0, 1, 0.001]),
                  ('inferior', 'Inferior', [0, -1, 0.001])],
         groups=[('skull', 'Skull', None), ('cranial', 'Cranial bones (neurocranium)', 'skull'),
                 ('facial', 'Facial bones (viscerocranium)', 'skull'),
                 ('mandible_hyoid', 'Mandible and hyoid', 'skull')])
K = 'skeletal_head_'
for sid, f, n in [('frontal', 'FMA52734', 'Frontal bone'), ('occipital', 'FMA52735', 'Occipital bone'),
                  ('sphenoid', 'FMA52736', 'Sphenoid bone'), ('ethmoid', 'FMA52740', 'Ethmoid bone')]:
    S(p, K + sid, f, 'cranial', n, side='midline')
for side, fs in [('right', ('FMA52788', 'FMA52738')), ('left', ('FMA52789', 'FMA52739'))]:
    S(p, K + side + '_parietal', fs[0], 'cranial', f'Parietal bone ({side})', side=side, concept='parietal')
    S(p, K + side + '_temporal', fs[1], 'cranial', f'Temporal bone ({side})', side=side, concept='temporal')
for c, n, r, l in [('nasal', 'Nasal bone', 'FMA53647', 'FMA53648'), ('lacrimal', 'Lacrimal bone', 'FMA53645', 'FMA53646'),
                   ('zygomatic', 'Zygomatic bone', 'FMA52892', 'FMA52893'), ('maxilla', 'Maxilla', 'FMA53649', 'FMA53650'),
                   ('palatine', 'Palatine bone', 'FMA53655', 'FMA53656'),
                   ('inferior_nasal_concha', 'Inferior nasal concha', 'FMA54737', 'FMA54738')]:
    S(p, K + 'right_' + c, r, 'facial', f'{n} (right)', side='right', concept=c)
    S(p, K + 'left_' + c, l, 'facial', f'{n} (left)', side='left', concept=c)
S(p, K + 'vomer', 'FMA9710', 'facial', 'Vomer', side='midline')
S(p, K + 'mandible', 'FMA52748', 'mandible_hyoid', 'Mandible', side='midline')
S(p, K + 'hyoid', 'FMA52749', 'mandible_hyoid', 'Hyoid bone', side='midline')

# ---------------------------------------------------------------- Vertebral column
p = pack('vertebral_column', name='Vertebral column', system='skeletal', region='trunk',
         description='The 24 presacral vertebrae (7 cervical, 12 thoracic, 5 lumbar) and the sacrum.',
         root='spine', rootframe=['cervical', 'thoracic', 'lumbar', 'sacral'],
         presets=[('lateral', 'Right lateral', [-1, 0, 0]), ('anterior', 'Anterior', [0, 0, 1]),
                  ('posterior', 'Posterior', [0, 0, -1])],
         groups=[('spine', 'Vertebral column', None), ('cervical', 'Cervical vertebrae', 'spine'),
                 ('thoracic', 'Thoracic vertebrae', 'spine'), ('lumbar', 'Lumbar vertebrae', 'spine'),
                 ('sacral', 'Sacrum', 'spine')])
V = 'skeletal_spine_'
S(p, V + 'c1_atlas', 'FMA12519', 'cervical', 'Atlas (C1)', side='midline')
S(p, V + 'c2_axis', 'FMA12520', 'cervical', 'Axis (C2)', side='midline')
for i, f in zip(range(3, 8), ['FMA12521', 'FMA12522', 'FMA12523', 'FMA12524', 'FMA12525']):
    S(p, V + f'c{i}', f, 'cervical', f'C{i} vertebra' + (' (vertebra prominens)' if i == 7 else ''), side='midline')
for i, f in enumerate(['FMA9165', 'FMA9187', 'FMA9209', 'FMA9248', 'FMA9922', 'FMA9945', 'FMA9968', 'FMA9991',
                       'FMA10014', 'FMA10037', 'FMA10059', 'FMA10081'], 1):
    S(p, V + f't{i}', f, 'thoracic', f'T{i} vertebra', side='midline')
for i, f in enumerate(['FMA13072', 'FMA13073', 'FMA13074', 'FMA13075', 'FMA13076'], 1):
    S(p, V + f'l{i}', f, 'lumbar', f'L{i} vertebra', side='midline')
S(p, V + 'sacrum', 'FMA16202', 'sacral', 'Sacrum', side='midline')

# ---------------------------------------------------------------- Thorax
p = pack('thoracic_cage', name='Thoracic cage (ribs and sternum)', system='skeletal', region='thorax',
         description='The sternum (manubrium, body, xiphoid process), 12 pairs of ribs and the costal cartilages.',
         root='thorax', rootframe=['sternum', 'ribs_right', 'ribs_left', 'costal_cartilages'],
         presets=[('anterior', 'Anterior', [0, 0, 1]), ('lateral', 'Right lateral', [-1, 0, 0]),
                  ('posterior', 'Posterior', [0, 0, -1]), ('superior', 'Superior', [0, 1, 0.001])],
         groups=[('thorax', 'Thoracic cage', None), ('sternum', 'Sternum', 'thorax'),
                 ('ribs_right', 'Ribs (right)', 'thorax'), ('ribs_left', 'Ribs (left)', 'thorax'),
                 ('costal_cartilages', 'Costal cartilages', 'thorax')])
T = 'skeletal_thorax_'
S(p, T + 'manubrium', 'FMA7486', 'sternum', 'Manubrium of sternum', side='midline')
S(p, T + 'sternal_body', 'FMA7487', 'sternum', 'Body of sternum', side='midline')
S(p, T + 'xiphoid', 'FMA7488', 'sternum', 'Xiphoid process', side='midline')
RR = ['FMA7857', 'FMA7882', 'FMA7909', 'FMA7957', 'FMA8066', 'FMA8175', 'FMA8229', 'FMA8283', 'FMA8364', 'FMA8445',
      'FMA8531', 'FMA8533']
RL = ['FMA7987', 'FMA8012', 'FMA8039', 'FMA8148', 'FMA8093', 'FMA8202', 'FMA8256', 'FMA8310', 'FMA8391', 'FMA8472',
      'FMA8532', 'FMA8534']
for side, ids in [('right', RR), ('left', RL)]:
    for i, f in enumerate(ids, 1):
        S(p, T + f'{side}_rib_{i}', f, 'ribs_' + side, f'{ORD[i - 1].capitalize()} rib ({side})', side=side,
          concept=f'rib_{i}')
CR = ['FMA7875', 'FMA7886', 'FMA7913', 'FMA7976', 'FMA8070', 'FMA8194', 'FMA8248']
CL = ['FMA8005', 'FMA8031', 'FMA8058', 'FMA8167', 'FMA8112', 'FMA8221', 'FMA8275']
for side, ids, extra in [('right', CR, 'BP28'), ('left', CL, 'BP24')]:
    for i, f in enumerate(ids, 1):
        S(p, T + f'{side}_costal_cartilage_{i}', f, 'costal_cartilages',
          f'{ORD[i - 1].capitalize()} costal cartilage ({side})', stype='cartilage', side=side,
          concept=f'costal_cartilage_{i}')
    S(p, T + f'{side}_costal_cartilage_8_10', extra, 'costal_cartilages',
      f'Costal cartilages 8 to 10 ({side})', stype='cartilage', side=side, concept='costal_cartilage_8_10')

# ---------------------------------------------------------------- Upper limb (right)
HAND = ['FMA24435', 'FMA24437', 'FMA24439', 'FMA24441', 'FMA24443', 'FMA23725', 'FMA24446', 'FMA24448',
        'FMA24464', 'FMA24466', 'FMA24468', 'FMA24470', 'FMA24472']  # carpals + metacarpals
p = pack('upper_limb_right', name='Upper limb bones (right)', system='skeletal', region='upper_limb',
         description='Right pectoral girdle, arm and forearm: clavicle, scapula, humerus, radius and ulna, with the hand for context.',
         root='upper_limb', rootframe=['girdle', 'arm', 'forearm'],
         presets=[('anterior', 'Anterior', [0, 0, 1]), ('posterior', 'Posterior', [0, 0, -1]),
                  ('lateral', 'Lateral', [-1, 0, 0]), ('medial', 'Medial', [1, 0, 0])],
         groups=[('upper_limb', 'Upper limb', None), ('girdle', 'Pectoral (shoulder) girdle', 'upper_limb'),
                 ('arm', 'Arm', 'upper_limb'), ('forearm', 'Forearm', 'upper_limb'),
                 ('hand_context', 'Hand (context)', 'upper_limb')])
U = 'skeletal_upperlimb_right_'
S(p, U + 'clavicle', 'FMA13322', 'girdle', 'Clavicle', side='right')
S(p, U + 'scapula', 'FMA13395', 'girdle', 'Scapula', side='right')
S(p, U + 'humerus', 'FMA23130', 'arm', 'Humerus', side='right')
S(p, U + 'radius', 'FMA23464', 'forearm', 'Radius', side='right')
S(p, U + 'ulna', 'FMA23467', 'forearm', 'Ulna', side='right')
S(p, U + 'hand', HAND, 'hand_context', 'Bones of the hand (carpals and metacarpals)', side='right', context=True)

# ---------------------------------------------------------------- Pelvis and lower limb (right)
FOOT = ['FMA24482', 'FMA24497', 'FMA24500', 'FMA24528', 'FMA24521', 'FMA24523', 'FMA24525',
        'FMA24507', 'FMA24509', 'FMA24511', 'FMA24513', 'FMA24515']
p = pack('pelvis_lower_limb_right', name='Pelvis and lower limb bones (right)', system='skeletal', region='lower_limb',
         description='The bony pelvis (both hip bones and the sacrum) and the right thigh and leg: femur, patella, tibia and fibula, with the foot for context.',
         root='lower_limb', rootframe=['pelvis', 'thigh', 'leg'],
         presets=[('anterior', 'Anterior', [0, 0, 1]), ('posterior', 'Posterior', [0, 0, -1]),
                  ('lateral', 'Right lateral', [-1, 0, 0]), ('medial', 'Medial', [1, 0, 0])],
         groups=[('lower_limb', 'Pelvis and lower limb', None), ('pelvis', 'Bony pelvis', 'lower_limb'),
                 ('thigh', 'Thigh and knee', 'lower_limb'), ('leg', 'Leg', 'lower_limb'),
                 ('foot_context', 'Foot (context)', 'lower_limb')])
L = 'skeletal_lowerlimb_'
S(p, L + 'right_hip_bone', 'FMA16586', 'pelvis', 'Hip bone (right)', side='right', concept='hip_bone')
S(p, L + 'left_hip_bone', 'FMA16587', 'pelvis', 'Hip bone (left)', side='left', concept='hip_bone')
S(p, L + 'sacrum', 'FMA16202', 'pelvis', 'Sacrum', side='midline')
S(p, L + 'right_femur', 'FMA24474', 'thigh', 'Femur', side='right', concept='femur')
S(p, L + 'right_patella', 'FMA24486', 'thigh', 'Patella', side='right', concept='patella')
S(p, L + 'right_tibia', 'FMA24477', 'leg', 'Tibia', side='right', concept='tibia')
S(p, L + 'right_fibula', 'FMA24480', 'leg', 'Fibula', side='right', concept='fibula')
S(p, L + 'right_foot', FOOT, 'foot_context', 'Bones of the foot (tarsals and metatarsals)', side='right',
  context=True, concept='foot')

# ---------------------------------------------------------------- Foot (right)
p = pack('foot_right', name='Foot and ankle bones (right)', system='skeletal', region='lower_limb',
         description='Right foot skeleton: 7 tarsals, 5 metatarsals and 14 phalanges, with the distal tibia and fibula for context.',
         root='foot', rootframe=['tarsals', 'metatarsals', 'phalanges'],
         presets=[('medial', 'Medial', [1, 0, 0.35]), ('dorsal', 'Dorsal (top)', [0, 1, 0.25]),
                  ('plantar', 'Plantar (sole)', [0, -1, 0.001]), ('lateral', 'Lateral', [-1, 0, 0])],
         groups=[('foot', 'Foot and ankle', None), ('leg_context', 'Leg (distal)', 'foot'),
                 ('tarsals', 'Tarsal bones', 'foot'), ('metatarsals', 'Metatarsals', 'foot'),
                 ('phalanges', 'Phalanges of the toes', 'foot')])
F = 'skeletal_foot_right_'
S(p, F + 'tibia', 'FMA24477', 'leg_context', 'Tibia', side='right', context=True)
S(p, F + 'fibula', 'FMA24480', 'leg_context', 'Fibula', side='right', context=True)
for c, f, n in [('talus', 'FMA24482', 'Talus'), ('calcaneus', 'FMA24497', 'Calcaneus'),
                ('navicular', 'FMA24500', 'Navicular'), ('cuboid', 'FMA24528', 'Cuboid'),
                ('medial_cuneiform', 'FMA24521', 'Medial cuneiform'),
                ('intermediate_cuneiform', 'FMA24523', 'Intermediate cuneiform'),
                ('lateral_cuneiform', 'FMA24525', 'Lateral cuneiform')]:
    S(p, F + c, f, 'tarsals', n, side='right')
ROM = ['I', 'II', 'III', 'IV', 'V']
for i, f in enumerate(['FMA24507', 'FMA24509', 'FMA24511', 'FMA24513', 'FMA24515'], 1):
    S(p, F + f'metatarsal_{i}', f, 'metatarsals', f'{ORD[i - 1].capitalize()} metatarsal ({ROM[i - 1]})', side='right')
TOES = ['big toe', 'second toe', 'third toe', 'fourth toe', 'little toe']
for i, f in enumerate(['FMA43253', 'FMA32634', 'FMA32636', 'FMA32638', 'FMA32640'], 1):
    S(p, F + f'proximal_phalanx_{i}', f, 'phalanges', f'Proximal phalanx of {TOES[i - 1]}', side='right')
for i, f in zip(range(2, 6), ['FMA32642', 'FMA32644', 'FMA32646', 'FMA230986']):
    S(p, F + f'middle_phalanx_{i}', f, 'phalanges', f'Middle phalanx of {TOES[i - 1]}', side='right')
for i, f in enumerate(['FMA32650', 'FMA32652', 'FMA32654', 'FMA32656', 'FMA32658'], 1):
    S(p, F + f'distal_phalanx_{i}', f, 'phalanges', f'Distal phalanx of {TOES[i - 1]}', side='right')

# ---------------------------------------------------------------- Heart and great vessels
RED, BLUE, MUSC, VALVE, PURPLE = '#c2413a', '#3b6fb6', '#b5544a', '#e8d6b0', '#7a5aa6'
p = pack('heart', name='Heart and great vessels', system='cardiovascular', region='thorax',
         description='The heart wall, valves and papillary muscles, the coronary arteries and cardiac veins, and the great vessels.',
         root='heart', rootframe=['heart_wall', 'valves', 'coronary', 'great_vessels'], tissue=True,
         presets=[('anterior', 'Anterior', [0, 0, 1]), ('posterior', 'Posterior', [0, 0, -1]),
                  ('left', 'Left lateral', [1, 0, 0]), ('superior', 'Superior', [0, 1, 0.001])],
         groups=[('heart', 'Heart and great vessels', None), ('heart_wall', 'Heart wall', 'heart'),
                 ('valves', 'Valves and papillary muscles', 'heart'),
                 ('coronary', 'Coronary arteries and cardiac veins', 'heart'),
                 ('great_vessels', 'Great vessels', 'heart')])
C = 'cardio_heart_'
S(p, C + 'heart_wall', 'FMA7274', 'heart_wall', 'Heart (wall of heart)', stype='organ', side='midline', colour=MUSC)
S(p, C + 'tricuspid_valve', 'FMA7234', 'valves', 'Tricuspid valve', stype='valve', side='right', colour=VALVE)
S(p, C + 'pulmonary_valve', 'FMA7246', 'valves', 'Pulmonary valve', stype='valve', side='right', colour=VALVE)
S(p, C + 'mitral_valve', 'FMA7235', 'valves', 'Mitral valve', stype='valve', side='left', colour=VALVE)
S(p, C + 'papillary_rv', ['FMA7260', 'FMA7261', 'FMA7262'], 'valves', 'Papillary muscles of right ventricle',
  stype='muscle', side='right', colour=MUSC)
S(p, C + 'papillary_lv', 'FMA9352nsn', 'valves', 'Papillary muscles of left ventricle', stype='muscle',
  side='left', colour=MUSC)
S(p, C + 'rca', 'FMA3802', 'coronary', 'Right coronary artery', stype='artery', side='right', colour=RED)
S(p, C + 'rca_marginal', 'FMA3818', 'coronary', 'Right marginal artery', stype='artery', side='right', colour=RED)
S(p, C + 'pda', 'FMA3840nsn', 'coronary', 'Posterior interventricular artery', stype='artery', side='midline', colour=RED)
S(p, C + 'lca', 'FMA4685', 'coronary', 'Left main coronary artery', stype='artery', side='left', colour=RED)
S(p, C + 'lad', 'FMA3862nsn', 'coronary', 'Anterior interventricular artery (LAD)', stype='artery', side='left', colour=RED)
S(p, C + 'circumflex', 'FMA3895', 'coronary', 'Circumflex artery', stype='artery', side='left', colour=RED)
S(p, C + 'coronary_sinus', 'FMA4706', 'coronary', 'Coronary sinus', stype='vein', side='midline', colour=BLUE)
S(p, C + 'great_cardiac_vein', 'FMA4707', 'coronary', 'Great cardiac vein', stype='vein', side='left', colour=BLUE)
S(p, C + 'middle_cardiac_vein', 'FMA4713', 'coronary', 'Middle cardiac vein', stype='vein', side='midline', colour=BLUE)
S(p, C + 'ascending_aorta', 'FMA3736', 'great_vessels', 'Ascending aorta', stype='artery', side='midline', colour=RED)
S(p, C + 'aortic_arch', 'FMA3768', 'great_vessels', 'Arch of aorta', stype='artery', side='midline', colour=RED)
S(p, C + 'descending_aorta', 'FMA3784', 'great_vessels', 'Descending aorta', stype='artery', side='midline', colour=RED)
S(p, C + 'brachiocephalic_trunk', 'FMA3932nsn', 'great_vessels', 'Brachiocephalic trunk', stype='artery',
  side='right', colour=RED)
S(p, C + 'left_common_carotid', 'FMA4058', 'great_vessels', 'Left common carotid artery', stype='artery',
  side='left', colour=RED)
S(p, C + 'left_subclavian', 'FMA4694', 'great_vessels', 'Left subclavian artery', stype='artery', side='left', colour=RED)
S(p, C + 'pulmonary_arteries', 'FMA66326', 'great_vessels', 'Pulmonary trunk and arteries', stype='artery',
  side='midline', colour=PURPLE)
for sid, n, rule in [('rspv', 'Right superior pulmonary vein', 'right_superior'),
                     ('ripv', 'Right inferior pulmonary vein', 'right_inferior'),
                     ('lspv', 'Left superior pulmonary vein', 'left_superior'),
                     ('lipv', 'Left inferior pulmonary vein', 'left_inferior')]:
    S(p, C + sid, {'fma': 'FMA66643', 'component': rule}, 'great_vessels', n, stype='vein',
      side=rule.split('_')[0], colour='#c46a8a')
S(p, C + 'svc', 'FMA4720', 'great_vessels', 'Superior vena cava', stype='vein', side='right', colour=BLUE)
S(p, C + 'ivc', 'FMA10951', 'great_vessels', 'Inferior vena cava', stype='vein', side='right', colour=BLUE)
S(p, C + 'right_brachiocephalic_vein', 'FMA4751', 'great_vessels', 'Right brachiocephalic vein', stype='vein',
  side='right', colour=BLUE)
S(p, C + 'left_brachiocephalic_vein', 'FMA4761', 'great_vessels', 'Left brachiocephalic vein', stype='vein',
  side='left', colour=BLUE)

# ---------------------------------------------------------------- Brain
GREY, DEEP, STEM, CSF = '#d9a7a0', '#c98f86', '#cfa27a', '#7fb2d8'
p = pack('brain', name='Brain', system='nervous', region='head', tissue=True,
         description='Cerebral cortex (gyri of both hemispheres), deep structures, ventricles, brainstem and cerebellum.',
         root='brain', rootframe=['cortex_left', 'cortex_right', 'deep', 'ventricles', 'brainstem_cerebellum'],
         presets=[('left', 'Left lateral', [1, 0, 0]), ('right', 'Right lateral', [-1, 0, 0]),
                  ('superior', 'Superior', [0, 1, 0.001]), ('inferior', 'Inferior', [0, -1, 0.001]),
                  ('anterior', 'Anterior', [0, 0, 1])],
         groups=[('brain', 'Brain', None), ('cortex_left', 'Left cerebral hemisphere (gyri)', 'brain'),
                 ('cortex_right', 'Right cerebral hemisphere (gyri)', 'brain'),
                 ('deep', 'Deep structures', 'brain'), ('ventricles', 'Ventricles', 'brain'),
                 ('brainstem_cerebellum', 'Brainstem and cerebellum', 'brain')])
B = 'nervous_brain_'
GYRI = [('superior_frontal_gyrus', 'Superior frontal gyrus', 'FMA72653', 'FMA72654'),
        ('middle_frontal_gyrus', 'Middle frontal gyrus', 'FMA72655', 'FMA72656'),
        ('precentral_gyrus', 'Precentral gyrus', 'FMA72661', 'FMA72662'),
        ('postcentral_gyrus', 'Postcentral gyrus', 'FMA72665', 'FMA72666'),
        ('superior_parietal_lobule', 'Superior parietal lobule and precuneus', 'BP50', 'BP49'),
        ('supramarginal_gyrus', 'Supramarginal gyrus', 'FMA72667', 'FMA72668'),
        ('angular_gyrus', 'Angular gyrus', 'FMA72669', 'FMA72670'),
        ('superior_temporal_gyrus', 'Superior temporal gyrus', ['FMA72800', 'FMA72804'], ['FMA72801', 'FMA72805']),
        ('middle_temporal_gyrus', 'Middle temporal gyrus', 'FMA72685', 'FMA72686'),
        ('inferior_temporal_gyrus', 'Inferior temporal gyrus', 'FMA72687', 'FMA72688'),
        ('occipital_lobe', 'Occipital lobe', 'FMA72975', 'FMA72976'),
        ('insula', 'Insula', 'FMA72977', 'FMA72978'),
        ('cingulate_gyrus', 'Cingulate gyrus', 'FMA72717', 'FMA72718'),
        ('parahippocampal_gyrus', 'Parahippocampal gyrus', 'FMA72705', 'FMA72706'),
        ('fusiform_gyrus', 'Fusiform gyrus', 'FMA72689', 'FMA72690')]
for side in ['left', 'right']:
    for c, n, r, l in GYRI:
        S(p, B + f'{side}_{c}', l if side == 'left' else r, 'cortex_' + side, f'{n} ({side})', stype='cortex',
          side=side, colour=GREY, concept=c)
for c, n, f, col in [('corpus_callosum', 'Corpus callosum', 'FMA86464', '#efe6da'),
                     ('left_thalamus', 'Thalamus (left)', 'FMA258716', DEEP),
                     ('right_thalamus', 'Thalamus (right)', 'FMA258714', DEEP),
                     ('hypothalamus', 'Hypothalamus', 'FMA62008nsn', DEEP),
                     ('pituitary', 'Pituitary gland', 'FMA13889', '#d8b26e'),
                     ('pineal', 'Pineal gland', 'FMA62033', '#d8b26e'),
                     ('left_hippocampus', 'Hippocampus (left)', 'FMA72714', DEEP),
                     ('left_amygdala', 'Amygdala (left)', 'FMA72833', DEEP),
                     ('left_caudate', 'Caudate nucleus (left)', 'FMA72827', '#b98a9a'),
                     ('left_putamen', 'Putamen (left)', 'FMA72829', '#b98a9a'),
                     ('left_globus_pallidus', 'Globus pallidus (left)', 'FMA72831', '#b98a9a'),
                     ('left_fornix', 'Fornix (left)', 'FMA72925', '#efe6da'),
                     ('optic_chiasm', 'Optic chiasm', 'FMA62045', '#e7d27a')]:
    S(p, B + c, f, 'deep', n, stype='brain', side='left' if 'left' in c else ('right' if 'right' in c else 'midline'),
      colour=col, concept=c.replace('left_', '').replace('right_', ''))
for c, n, f in [('left_lateral_ventricle', 'Lateral ventricle (left)', 'FMA78450'),
                ('right_lateral_ventricle', 'Lateral ventricle (right)', 'FMA78449'),
                ('third_ventricle', 'Third ventricle', 'FMA78454'),
                ('cerebral_aqueduct', 'Cerebral aqueduct', 'FMA78467'),
                ('fourth_ventricle', 'Fourth ventricle', 'FMA78469')]:
    S(p, B + c, f, 'ventricles', n, stype='ventricle',
      side='left' if 'left' in c else ('right' if 'right' in c else 'midline'), colour=CSF,
      concept=c.replace('left_', '').replace('right_', ''))
for c, n, f in [('midbrain', 'Midbrain', 'FMA61993nsn'), ('pons', 'Pons', 'FMA67943'),
                ('medulla', 'Medulla oblongata', 'FMA62004'), ('cerebellum', 'Cerebellum', 'FMA67944')]:
    S(p, B + c, f, 'brainstem_cerebellum', n, stype='brain', side='midline', colour=STEM if c != 'cerebellum' else '#d4a095')

# ---------------------------------------------------------------- Respiratory
p = pack('respiratory', name='Respiratory system', system='respiratory', region='thorax', tissue=True,
         description='Larynx (thyroid cartilage), trachea, bronchial trees, the five lung lobes and the diaphragm.',
         root='respiratory', rootframe=['airways', 'lungs', 'diaphragm_group'],
         presets=[('anterior', 'Anterior', [0, 0, 1]), ('posterior', 'Posterior', [0, 0, -1]),
                  ('right', 'Right lateral', [-1, 0, 0]), ('left', 'Left lateral', [1, 0, 0])],
         groups=[('respiratory', 'Respiratory system', None), ('airways', 'Airways', 'respiratory'),
                 ('lungs', 'Lungs (lobes)', 'respiratory'), ('diaphragm_group', 'Diaphragm', 'respiratory')])
R = 'resp_'
S(p, R + 'thyroid_cartilage', 'FMA55099', 'airways', 'Thyroid cartilage (larynx)', stype='cartilage', side='midline', colour='#e6dcc4')
S(p, R + 'trachea', 'FMA7394', 'airways', 'Trachea', stype='organ', side='midline', colour='#e2c6a8')
S(p, R + 'right_bronchial_tree', {'fma': 'FMA7409', 'component': 'right'}, 'airways', 'Right bronchial tree',
  stype='organ', side='right', colour='#e2c6a8', concept='bronchial_tree')
S(p, R + 'left_bronchial_tree', {'fma': 'FMA7409', 'component': 'left'}, 'airways', 'Left bronchial tree',
  stype='organ', side='left', colour='#e2c6a8', concept='bronchial_tree')
for c, n, f, side in [('right_upper_lobe', 'Upper lobe of right lung', 'FMA7333', 'right'),
                      ('right_middle_lobe', 'Middle lobe of right lung', 'FMA7383', 'right'),
                      ('right_lower_lobe', 'Lower lobe of right lung', 'FMA7337', 'right'),
                      ('left_upper_lobe', 'Upper lobe of left lung', 'FMA7370', 'left'),
                      ('left_lower_lobe', 'Lower lobe of left lung', 'FMA7371', 'left')]:
    S(p, R + c, f, 'lungs', n, stype='organ', side=side, colour='#e9a6a6' if 'upper' in c else
      ('#e39595' if 'middle' in c else '#d98a8a'))
S(p, R + 'diaphragm', 'FMA13295', 'diaphragm_group', 'Diaphragm', stype='muscle', side='midline', colour='#b5544a')

# ---------------------------------------------------------------- Digestive
p = pack('digestive', name='Digestive system', system='digestive', region='abdomen', tissue=True,
         description='The alimentary canal from the oesophagus to the rectum, plus the liver, gallbladder, pancreas and spleen.',
         root='digestive', rootframe=['upper_gi', 'intestines', 'accessory'],
         presets=[('anterior', 'Anterior', [0, 0, 1]), ('posterior', 'Posterior', [0, 0, -1]),
                  ('right', 'Right lateral', [-1, 0, 0]), ('left', 'Left lateral', [1, 0, 0])],
         groups=[('digestive', 'Digestive system', None), ('upper_gi', 'Oesophagus and stomach', 'digestive'),
                 ('intestines', 'Small and large intestine', 'digestive'),
                 ('accessory', 'Accessory organs (and spleen)', 'digestive')])
D = 'digestive_'
for c, n, f, g, col in [('oesophagus', 'Oesophagus', 'FMA7131', 'upper_gi', '#d7837a'),
                        ('stomach', 'Stomach', 'FMA7148', 'upper_gi', '#e0918a'),
                        ('duodenum', 'Duodenum', 'FMA7206', 'intestines', '#e6a88d'),
                        ('jejunum', 'Jejunum', 'FMA7207', 'intestines', '#e8b294'),
                        ('ileum', 'Ileum', 'FMA7208', 'intestines', '#e4a489'),
                        ('appendix', 'Vermiform appendix', 'FMA14542', 'intestines', '#d99a7f'),
                        ('colon', 'Colon (with caecum)', 'FMA14543nsn', 'intestines', '#caa07a'),
                        ('rectum', 'Rectum', 'FMA14544', 'intestines', '#c48f70'),
                        ('liver', 'Liver', 'FMA7197', 'accessory', '#8c3b2e'),
                        ('gallbladder', 'Gallbladder', 'FMA7202', 'accessory', '#5f8a3a'),
                        ('pancreas', 'Pancreas', 'FMA7198nsn', 'accessory', '#e8c27a'),
                        ('spleen', 'Spleen', 'FMA7196', 'accessory', '#7d3a52')]:
    S(p, D + c, f, g, n, stype='organ', side='midline', colour=col)

# ---------------------------------------------------------------- Urinary
p = pack('urinary', name='Urinary system', system='urinary', region='abdomen', tissue=True,
         description='Kidneys, adrenal glands, renal vessels, ureters, urinary bladder and urethra.',
         root='urinary', rootframe=['kidneys', 'vessels', 'lower_tract'],
         presets=[('anterior', 'Anterior', [0, 0, 1]), ('posterior', 'Posterior', [0, 0, -1]),
                  ('right', 'Right lateral', [-1, 0, 0])],
         groups=[('urinary', 'Urinary system', None), ('kidneys', 'Kidneys and adrenal glands', 'urinary'),
                 ('vessels', 'Renal vessels', 'urinary'), ('lower_tract', 'Ureters, bladder and urethra', 'urinary')])
Y = 'urinary_'
for side in ['right', 'left']:
    S(p, Y + f'{side}_kidney', 'FMA7204' if side == 'right' else 'FMA7205', 'kidneys', f'Kidney ({side})',
      stype='organ', side=side, colour='#9c4a3c', concept='kidney')
    S(p, Y + f'{side}_adrenal', 'FMA15629' if side == 'right' else 'FMA15630', 'kidneys',
      f'Adrenal gland ({side})', stype='gland', side=side, colour='#d9a441', concept='adrenal_gland')
    S(p, Y + f'{side}_renal_artery', 'FMA14752' if side == 'right' else 'FMA14753', 'vessels',
      f'Renal artery ({side})', stype='artery', side=side, colour=RED, concept='renal_artery')
    S(p, Y + f'{side}_renal_vein', 'FMA14335' if side == 'right' else 'FMA14336', 'vessels',
      f'Renal vein ({side})', stype='vein', side=side, colour=BLUE, concept='renal_vein')
    S(p, Y + f'{side}_ureter', 'FMA15571' if side == 'right' else 'FMA15572', 'lower_tract',
      f'Ureter ({side})', stype='organ', side=side, colour='#e0b27c', concept='ureter')
S(p, Y + 'bladder', 'FMA15900', 'lower_tract', 'Urinary bladder', stype='organ', side='midline', colour='#e3b48a')
S(p, Y + 'urethra', 'FMA19667', 'lower_tract', 'Urethra (male)', stype='organ', side='midline', colour='#e0b27c')
S(p, Y + 'abdominal_aorta', 'FMA3784', 'vessels', 'Aorta (descending, for context)', stype='artery',
  side='midline', colour=RED, context=True, concept='aorta')
S(p, Y + 'ivc', 'FMA10951', 'vessels', 'Inferior vena cava (context)', stype='vein', side='right', colour=BLUE,
  context=True, concept='ivc')

# ---------------------------------------------------------------- Muscles: upper limb (right)
M1, M2, BONE = '#b7473f', '#c65a4f', '#e8dfcc'
p = pack('muscles_upper_limb_right', name='Muscles of the shoulder and upper limb (right)', system='muscular',
         region='upper_limb', tissue=True,
         description='Right shoulder, arm and forearm muscles, with the bones of the upper limb for context.',
         root='muscles_ul', rootframe=['shoulder', 'arm', 'forearm_flexors', 'forearm_extensors'],
         presets=[('anterior', 'Anterior', [0, 0, 1]), ('posterior', 'Posterior', [0, 0, -1]),
                  ('lateral', 'Lateral', [-1, 0, 0])],
         groups=[('muscles_ul', 'Muscles of the upper limb', None), ('bones_context', 'Bones (context)', 'muscles_ul'),
                 ('shoulder', 'Shoulder and pectoral muscles', 'muscles_ul'), ('arm', 'Arm muscles', 'muscles_ul'),
                 ('forearm_flexors', 'Forearm: anterior (flexor) compartment', 'muscles_ul'),
                 ('forearm_extensors', 'Forearm: posterior (extensor) compartment', 'muscles_ul')])
MU = 'muscular_upperlimb_right_'
S(p, MU + 'bones', ['FMA13322', 'FMA13395', 'FMA23130', 'FMA23464', 'FMA23467'] + HAND, 'bones_context',
  'Bones of the upper limb', stype='bone', side='right', context=True, colour=BONE)
for c, n, f, g in [
    ('deltoid', 'Deltoid', ['FMA34680', 'FMA34682', 'FMA34684'], 'shoulder'),
    ('trapezius', 'Trapezius', ['FMA33586', 'FMA33584', 'FMA33581'], 'shoulder'),
    ('pectoralis_major', 'Pectoralis major', ['FMA34690', 'FMA79979', 'FMA45874'], 'shoulder'),
    ('pectoralis_minor', 'Pectoralis minor', 'FMA13375', 'shoulder'),
    ('serratus_anterior', 'Serratus anterior', 'FMA13398', 'shoulder'),
    ('latissimus_dorsi', 'Latissimus dorsi', 'FMA13358', 'shoulder'),
    ('supraspinatus', 'Supraspinatus', 'FMA32544', 'shoulder'),
    ('infraspinatus', 'Infraspinatus', 'FMA32547', 'shoulder'),
    ('teres_minor', 'Teres minor', 'FMA32553', 'shoulder'),
    ('subscapularis', 'Subscapularis', 'FMA13414', 'shoulder'),
    ('teres_major', 'Teres major', 'FMA32551', 'shoulder'),
    ('rhomboid_major', 'Rhomboid major', 'FMA13381', 'shoulder'),
    ('rhomboid_minor', 'Rhomboid minor', 'FMA13383', 'shoulder'),
    ('levator_scapulae', 'Levator scapulae', 'FMA32540', 'shoulder'),
    ('biceps_brachii', 'Biceps brachii', ['FMA37686', 'FMA37684'], 'arm'),
    ('brachialis', 'Brachialis', 'FMA37668', 'arm'),
    ('coracobrachialis', 'Coracobrachialis', 'FMA37665', 'arm'),
    ('triceps_brachii', 'Triceps brachii', ['FMA37699', 'FMA37697', 'FMA37695'], 'arm'),
    ('anconeus', 'Anconeus', 'FMA37705', 'arm'),
    ('pronator_teres', 'Pronator teres', ['FMA38560', 'FMA38562'], 'forearm_flexors'),
    ('flexor_carpi_radialis', 'Flexor carpi radialis', 'FMA38460', 'forearm_flexors'),
    ('palmaris_longus', 'Palmaris longus', 'FMA38463', 'forearm_flexors'),
    ('flexor_carpi_ulnaris', 'Flexor carpi ulnaris', ['FMA38617', 'FMA38619'], 'forearm_flexors'),
    ('fds', 'Flexor digitorum superficialis', ['FMA38638', 'FMA38640'], 'forearm_flexors'),
    ('fdp', 'Flexor digitorum profundus', 'FMA38479', 'forearm_flexors'),
    ('flexor_pollicis_longus', 'Flexor pollicis longus', 'FMA38482', 'forearm_flexors'),
    ('pronator_quadratus', 'Pronator quadratus', 'FMA38454', 'forearm_flexors'),
    ('brachioradialis', 'Brachioradialis', 'FMA38486', 'forearm_extensors'),
    ('ecrl', 'Extensor carpi radialis longus', 'FMA38495', 'forearm_extensors'),
    ('ecrb', 'Extensor carpi radialis brevis', 'FMA38498', 'forearm_extensors'),
    ('extensor_digitorum', 'Extensor digitorum', 'FMA38501', 'forearm_extensors'),
    ('extensor_digiti_minimi', 'Extensor digiti minimi', 'FMA38504', 'forearm_extensors'),
    ('ecu', 'Extensor carpi ulnaris', ['BP45', 'BP47'], 'forearm_extensors'),
    ('supinator', 'Supinator', 'FMA38513', 'forearm_extensors'),
    ('apl', 'Abductor pollicis longus', 'FMA38516', 'forearm_extensors'),
    ('epb', 'Extensor pollicis brevis', 'FMA38519', 'forearm_extensors'),
    ('epl', 'Extensor pollicis longus', 'FMA38522', 'forearm_extensors'),
    ('extensor_indicis', 'Extensor indicis', 'FMA38525', 'forearm_extensors')]:
    S(p, MU + c, f, g, n, stype='muscle', side='right', colour=M1)

# ---------------------------------------------------------------- Muscles: lower limb (right)
LBONES = ['FMA16586', 'FMA16202', 'FMA24474', 'FMA24486', 'FMA24477', 'FMA24480'] + FOOT
p = pack('muscles_lower_limb_right', name='Muscles of the hip and lower limb (right)', system='muscular',
         region='lower_limb', tissue=True,
         description='Right gluteal, thigh and leg muscles, with the pelvis and lower-limb bones for context.',
         root='muscles_ll', rootframe=['gluteal', 'thigh_anterior', 'thigh_medial', 'thigh_posterior', 'leg'],
         presets=[('anterior', 'Anterior', [0, 0, 1]), ('posterior', 'Posterior', [0, 0, -1]),
                  ('lateral', 'Lateral', [-1, 0, 0]), ('medial', 'Medial', [1, 0, 0])],
         groups=[('muscles_ll', 'Muscles of the lower limb', None), ('bones_context', 'Bones (context)', 'muscles_ll'),
                 ('gluteal', 'Gluteal region and hip', 'muscles_ll'),
                 ('thigh_anterior', 'Thigh: anterior compartment', 'muscles_ll'),
                 ('thigh_medial', 'Thigh: medial (adductor) compartment', 'muscles_ll'),
                 ('thigh_posterior', 'Thigh: posterior compartment (hamstrings)', 'muscles_ll'),
                 ('leg', 'Leg muscles', 'muscles_ll')])
ML = 'muscular_lowerlimb_right_'
S(p, ML + 'bones', LBONES, 'bones_context', 'Bones of the pelvis and lower limb', stype='bone', side='right',
  context=True, colour=BONE)
for c, n, f, g in [
    ('gluteus_maximus', 'Gluteus maximus', 'FMA22328', 'gluteal'),
    ('gluteus_medius', 'Gluteus medius', 'FMA22330', 'gluteal'),
    ('gluteus_minimus', 'Gluteus minimus', 'FMA22332', 'gluteal'),
    ('tensor_fasciae_latae', 'Tensor fasciae latae', 'FMA22425', 'gluteal'),
    ('piriformis', 'Piriformis', 'FMA22340', 'gluteal'),
    ('obturator_internus', 'Obturator internus', 'FMA22324', 'gluteal'),
    ('quadratus_femoris', 'Quadratus femoris', 'FMA22338', 'gluteal'),
    ('iliacus', 'Iliacus', 'FMA22322', 'thigh_anterior'),
    ('psoas_major', 'Psoas major', 'FMA22342', 'thigh_anterior'),
    ('sartorius', 'Sartorius', 'FMA22354', 'thigh_anterior'),
    ('rectus_femoris', 'Rectus femoris', 'FMA38928', 'thigh_anterior'),
    ('vastus_lateralis', 'Vastus lateralis', 'FMA38930', 'thigh_anterior'),
    ('vastus_medialis', 'Vastus medialis', 'FMA38932', 'thigh_anterior'),
    ('vastus_intermedius', 'Vastus intermedius', 'FMA38934', 'thigh_anterior'),
    ('pectineus', 'Pectineus', 'FMA22450', 'thigh_medial'),
    ('adductor_longus', 'Adductor longus', 'FMA22456', 'thigh_medial'),
    ('adductor_brevis', 'Adductor brevis', 'FMA22452', 'thigh_medial'),
    ('adductor_magnus', 'Adductor magnus', 'FMA22459', 'thigh_medial'),
    ('gracilis', 'Gracilis', 'FMA43883', 'thigh_medial'),
    ('biceps_femoris', 'Biceps femoris', ['FMA45888', 'FMA45891'], 'thigh_posterior'),
    ('semitendinosus', 'Semitendinosus', 'FMA22358', 'thigh_posterior'),
    ('semimembranosus', 'Semimembranosus', 'FMA22448', 'thigh_posterior'),
    ('tibialis_anterior', 'Tibialis anterior', 'FMA22544', 'leg'),
    ('extensor_digitorum_longus', 'Extensor digitorum longus', 'FMA22548', 'leg'),
    ('extensor_hallucis_longus', 'Extensor hallucis longus', 'FMA22546', 'leg'),
    ('fibularis_longus', 'Fibularis (peroneus) longus', 'FMA22552', 'leg'),
    ('fibularis_brevis', 'Fibularis (peroneus) brevis', 'FMA22554', 'leg'),
    ('gastrocnemius', 'Gastrocnemius', ['FMA45960', 'FMA45957'], 'leg'),
    ('soleus', 'Soleus', 'FMA22558', 'leg'),
    ('calcaneal_tendon', 'Calcaneal (Achilles) tendon', 'FMA258847', 'leg'),
    ('popliteus', 'Popliteus', 'FMA22591', 'leg'),
    ('tibialis_posterior', 'Tibialis posterior', 'FMA65018', 'leg'),
    ('flexor_digitorum_longus', 'Flexor digitorum longus', 'FMA65016', 'leg'),
    ('flexor_hallucis_longus', 'Flexor hallucis longus', 'FMA65014', 'leg')]:
    S(p, ML + c, f, g, n, stype='tendon' if c == 'calcaneal_tendon' else 'muscle', side='right',
      colour='#d9cfae' if c == 'calcaneal_tendon' else M1)

# ---------------------------------------------------------------- Muscles: trunk
p = pack('muscles_trunk', name='Muscles of the trunk (abdominal wall and back)', system='muscular', region='trunk',
         tissue=True,
         description='Anterior abdominal wall, posterior abdominal wall, breathing and deep back muscles (right side), with the axial skeleton for context.',
         root='muscles_trunk', rootframe=['abdominal_wall', 'posterior_wall', 'back', 'neck'],
         presets=[('anterior', 'Anterior', [0, 0, 1]), ('posterior', 'Posterior', [0, 0, -1]),
                  ('lateral', 'Right lateral', [-1, 0, 0])],
         groups=[('muscles_trunk', 'Muscles of the trunk', None), ('bones_context', 'Skeleton (context)', 'muscles_trunk'),
                 ('abdominal_wall', 'Anterolateral abdominal wall', 'muscles_trunk'),
                 ('posterior_wall', 'Posterior abdominal wall and pelvic floor', 'muscles_trunk'),
                 ('back', 'Back muscles', 'muscles_trunk'),
                 ('neck', 'Neck muscles', 'muscles_trunk')])
MT = 'muscular_trunk_right_'
S(p, MT + 'skeleton', ['FMA16202', 'FMA16586', 'FMA16587', 'FMA7486', 'FMA7487', 'FMA7488'] + RR + RL +
  ['FMA13072', 'FMA13073', 'FMA13074', 'FMA13075', 'FMA13076', 'FMA9165', 'FMA9187', 'FMA9209', 'FMA9248',
   'FMA9922', 'FMA9945', 'FMA9968', 'FMA9991', 'FMA10014', 'FMA10037', 'FMA10059', 'FMA10081', 'FMA12519',
   'FMA12520', 'FMA12521', 'FMA12522', 'FMA12523', 'FMA12524', 'FMA12525'],
  'bones_context', 'Axial skeleton and pelvis', stype='bone', side='midline', context=True, colour=BONE)
for c, n, f, g in [
    ('rectus_abdominis', 'Rectus abdominis', 'FMA13377', 'abdominal_wall'),
    ('external_oblique', 'External oblique', 'FMA13336', 'abdominal_wall'),
    ('internal_oblique', 'Internal oblique', 'FMA13892', 'abdominal_wall'),
    ('transversus_abdominis', 'Transversus abdominis', 'FMA22344', 'abdominal_wall'),
    ('pyramidalis', 'Pyramidalis', 'FMA22346', 'abdominal_wall'),
    ('quadratus_lumborum', 'Quadratus lumborum', 'FMA22348', 'posterior_wall'),
    ('psoas_major', 'Psoas major', 'FMA22342', 'posterior_wall'),
    ('diaphragm', 'Diaphragm', 'FMA13295', 'posterior_wall'),
    ('levator_ani', 'Levator ani (pubococcygeus, puborectalis, iliococcygeus)', ['FMA45854', 'FMA45856', 'FMA45858'],
     'posterior_wall'),
    ('iliocostalis', 'Iliocostalis', ['FMA22740', 'FMA22742', 'FMA22744'], 'back'),
    ('longissimus', 'Longissimus', ['FMA22751', 'FMA22757', 'FMA22754'], 'back'),
    ('spinalis', 'Spinalis', ['FMA22779', 'FMA22781'], 'back'),
    ('multifidus', 'Multifidus', 'FMA22878', 'back'),
    ('semispinalis', 'Semispinalis', ['FMA22872', 'FMA22874', 'FMA22876'], 'back'),
    ('serratus_posterior_superior', 'Serratus posterior superior', 'FMA13403', 'back'),
    ('serratus_posterior_inferior', 'Serratus posterior inferior', 'FMA13405', 'back'),
    ('splenius', 'Splenius capitis and cervicis', ['FMA22728', 'FMA22726'], 'neck'),
    ('sternocleidomastoid', 'Sternocleidomastoid', 'FMA13408', 'neck'),
    ('scalenes', 'Scalene muscles (anterior, middle, posterior)', ['FMA13392', 'FMA13390', 'FMA13388'], 'neck')]:
    S(p, MT + c, f, g, n, stype='muscle', side='midline' if c == 'diaphragm' else 'right',
      colour='#b5544a' if c == 'diaphragm' else M1)

if __name__ == '__main__':
    os.makedirs('defs', exist_ok=True)
    av = {i for i, _, _ in json.load(open('avail.json'))}
    total = 0
    for pid, pk in PACKS.items():
        missing = []
        for s in pk['structures']:
            f = s['fma']
            ids = f if isinstance(f, list) else [f['fma']] if isinstance(f, dict) else [f]
            missing += [i for i in ids if i not in av]
        ids = [s['id'] for s in pk['structures']]
        assert len(ids) == len(set(ids)), pid
        json.dump(pk, open(f'defs/{pid}.skel.json', 'w'), indent=1)
        total += len(ids)
        print(pid, len(ids), 'MISSING' if missing else '', missing)
    print('total', total)

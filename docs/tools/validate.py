#!/usr/bin/env python3
"""Validate defs/<pack>.content.json against defs/<pack>.skel.json. Usage: python3 validate.py <pack> [...]"""
import json, re, sys

FIELDS = ['pronunciation', 'origin', 'location', 'description', 'function', 'mnemonic', 'clinical', 'hint']
RELTYPES = {'articulates_with', 'adjacent_to', 'continuous_with', 'supplies', 'drains_to', 'works_with', 'opposes',
            'attaches_to', 'part_of', 'contains', 'connects'}
KINDS = {'function', 'location', 'relationship', 'terminology', 'clinical'}


def check(pid):
    skel = json.load(open(f'defs/{pid}.skel.json'))
    try:
        c = json.load(open(f'defs/{pid}.content.json'))
    except FileNotFoundError:
        return [f'{pid}: content file missing']
    except json.JSONDecodeError as e:
        return [f'{pid}: invalid JSON: {e}']
    errs, warn = [], []
    ids = {s['id']: s for s in skel['structures']}
    groups = {g[0] for g in skel['groups']}
    for g in c.get('groups', {}):
        if g not in groups:
            errs.append(f'unknown group {g}')
    st = c.get('structures', {})
    for sid in ids:
        if sid not in st:
            errs.append(f'missing structure {sid}')
    nq = 0
    for sid, d in st.items():
        if sid not in ids:
            errs.append(f'unknown structure {sid}')
            continue
        name = d.get('preferred') or ids[sid]['preferred']
        if not d.get('latin'):
            errs.append(f'{sid}: latin missing')
        if not isinstance(d.get('synonyms', []), list):
            errs.append(f'{sid}: synonyms must be a list')
        cont = d.get('content', {})
        for f in FIELDS:
            v = cont.get(f)
            if not isinstance(v, str) or not v.strip():
                errs.append(f'{sid}: content.{f} missing')
            elif len(v) > 700:
                errs.append(f'{sid}: content.{f} too long ({len(v)})')
            elif re.search(r'<[a-z/]|\*\*|`', v):
                errs.append(f'{sid}: content.{f} contains markup')
        extra = set(cont) - set(FIELDS)
        if extra:
            errs.append(f'{sid}: unknown content keys {extra}')
        hint = cont.get('hint', '').lower()
        core = re.sub(r'\s*\(.*?\)', '', name).lower()
        if core and len(core) > 3 and core in hint:
            warn.append(f'{sid}: hint contains the name "{core}"')
        for r in d.get('relationships', []):
            if r.get('type') not in RELTYPES:
                errs.append(f'{sid}: bad relationship type {r.get("type")}')
            if r.get('target') not in ids:
                errs.append(f'{sid}: relationship target not in pack {r.get("target")}')
            if r.get('target') == sid:
                errs.append(f'{sid}: relationship to itself')
        for q in d.get('questions', []):
            nq += 1
            o = q.get('options')
            if q.get('kind') not in KINDS:
                errs.append(f'{sid}: bad question kind {q.get("kind")}')
            if not q.get('text') or not isinstance(o, list) or len(o) != 4 or len(set(o)) != 4:
                errs.append(f'{sid}: question needs text and 4 distinct options: {q.get("text")}')
            elif not isinstance(q.get('answer'), int) or not 0 <= q['answer'] < 4:
                errs.append(f'{sid}: bad answer index')
            if not q.get('explanation'):
                errs.append(f'{sid}: question explanation missing')
    need = len({s['concept'] for s in skel['structures'] if not s['context']})
    if nq < need:
        warn.append(f'only {nq} questions for {need} concepts (aim for at least one per concept)')
    return [f'{pid}: ERROR {e}' for e in errs] + [f'{pid}: warn {w}' for w in warn] + [f'{pid}: {len(st)} structures, {nq} questions']


if __name__ == '__main__':
    out = []
    for pid in sys.argv[1:]:
        out += check(pid)
    print('\n'.join(out))
    sys.exit(1 if any('ERROR' in l for l in out) else 0)

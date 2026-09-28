# Library content brief for AI Anatomy packs

You are writing the shipped "library" teaching content for one or more anatomy packs in a Moodle
activity. Students see this text on study cards, as practice hints, and in quiz questions. Teachers
can edit it later, but it must be correct and usable as shipped. Accuracy matters more than style.

## Inputs
- `defs/<pack>.skel.json`: the pack's structures (id, preferred name, type, side, group, context flag)
  and groups. Structure identity and 3D geometry come from BodyParts3D (FMA IDs); do NOT change ids.
- Style reference: `/home/claude/aianatomy/packs/hand_right/pack.json` (existing hand pack: read 3-4
  structures' `content` and `questions` to match tone, length and level).

## Output
Write `defs/<pack>.content.json` (UTF-8, valid JSON) with exactly this shape:
```json
{
  "groups": {"<group id>": {"tip": "one or two sentences: a study tip or well-known mnemonic for the group"}},
  "structures": {
    "<structure id>": {
      "preferred": "optional; only if the skeleton's preferred name should be improved",
      "latin": "Terminologia Anatomica Latin name",
      "synonyms": ["other common names, may be empty"],
      "relationships": [{"type": "articulates_with", "target": "<another structure id IN THIS PACK>"}],
      "content": {
        "pronunciation": "simple respelling, e.g. SKAF-oyd",
        "origin": "word origin (Latin/Greek root and meaning)",
        "location": "where it is, in plain words with correct directional terms",
        "description": "shape / key features",
        "function": "what it does (for muscles: main actions, plus origin -> insertion and nerve supply in one or two short sentences)",
        "mnemonic": "a memory aid",
        "clinical": "one relevant, well-established clinical note",
        "hint": "a practice hint that helps find it WITHOUT saying its name"
      },
      "questions": [{"kind": "function|location|relationship|terminology|clinical",
                     "text": "...", "options": ["a", "b", "c", "d"], "answer": 0,
                     "explanation": "one sentence saying why the answer is right"}]
    }
  }
}
```

## Rules
- Every structure in the skeleton gets an entry, including context structures (for context structures keep
  it brief; no questions needed).
- Audience: Diploma-level health learners (enrolled nursing, massage, fitness, allied health assistants).
  Plain English, correct anatomical terms, explained. British/Australian spelling (oesophagus, haemorrhage, fibre).
- Each content field: 1-3 sentences, max ~400 characters. Plain text only: no HTML, Markdown, emojis or quotes
  around the whole value. Use straight double quotes inside text only when needed (escape them in JSON).
- Only well-established facts. Never invent structures, attachments, nerves or relationships. If a fact is
  uncertain, leave it out rather than guess. Muscle origins/insertions/innervation must follow standard texts
  (e.g. Gray's, Moore). Nerve root levels only if standard (e.g. deltoid: axillary nerve C5-C6).
- `relationships` targets must be ids that exist in the same pack. Allowed types:
  articulates_with (bones/joints), adjacent_to, continuous_with, supplies (artery -> structure), drains_to,
  works_with (synergist), opposes (antagonist), attaches_to, part_of, contains, connects.
  Bone articulations should be listed on both sides (the build also symmetrises articulates_with).
  For merged context structures (e.g. "Bones of the upper limb") you may use attaches_to that target.
- Bilateral pairs (left/right versions of the same concept): write full content for both (location text may
  mention the side), but put that concept's questions on only one side (the right, or the first listed).
- Questions: at least one per concept, about 1-2 for important structures. 4 options, exactly one correct,
  plausible distractors (prefer other structures in the same pack), no "all/none of the above", mix kinds.
  Vary the position of the correct answer (not always 0). Questions must be answerable from the content.
- The hint must not contain the structure's name (the validator warns if it does).
- Group tips: give a tip for most groups (e.g. cranial bones mnemonic "Old People From Texas Eat Spiders").
- Where the skeleton notes a limitation (e.g. the heart is one "wall of heart" mesh with no separate chambers;
  the colon mesh includes the caecum), write the content to teach the chambers/parts within that structure's
  card honestly (e.g. the heart wall card describes the four chambers).

## Check
Run `cd /home/claude/bp3d && python3 validate.py <pack>` and fix every ERROR. Warnings about hints
should be fixed too. Finish with a short report: pack, structure count, question count, anything uncertain.

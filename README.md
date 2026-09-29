# AI Anatomy (mod_aianatomy)

AI Anatomy is a Moodle activity for learning anatomy on a real, verified 3D model. Students click a region to zoom in, and its bones separate (exploded view) so each one can be studied, practised and tested.

**The AI does not decide the anatomy.** The shape, position and identity of every structure come from a verified dataset (BodyParts3D, with FMA ontology IDs). AI only drafts the teaching text and quiz questions, and nothing it writes reaches students until a teacher approves it.

## Modes

| Mode | What students do | Graded |
|---|---|---|
| **Study** | Drill down (hand, then carpal bones, then scaphoid), rotate and zoom, separate the bones with a slider, turn labels on, search in English or Latin. Clicking a bone opens its card: Latin name, pronunciation (with "Say it"), word origin, location, function, memory aid, clinical note, and the bones it joins with (click one to jump to it). Isolate and Show in context are also available. | No |
| **Practice** | For each group: **Label it** (drag names onto numbered boxes on the model, or tap a name and then a box), then **Find it** ("Find the hamate": click the bone), then questions with explanations. Hints, Show answers and instant feedback. Results feed each student's mastery, and **Practise weak structures** brings the hard ones first. | No |
| **Test** | Label it for each group, with no hints. Each round is locked when submitted. Then the knowledge questions. Marked on the server, with an optional time limit and attempt limit. The grade combines labelling and questions (60/40 by default). | Yes |

## Anatomy packs
15 regional packs, each with its own verified 3D model, written library teaching content for every structure and ready-to-use questions (374 structures and 442 questions in total):

| System | Pack | Structures | Questions |
|---|---|---|---|
| Skeletal | Foot and ankle bones (right) | 28 | 33 |
| Skeletal | Hand and wrist bones (right) | 29 | 22 |
| Skeletal | Pelvis and lower limb bones (right) | 8 | 13 |
| Skeletal | Skull | 23 | 25 |
| Skeletal | Thoracic cage (ribs and sternum) | 43 | 31 |
| Skeletal | Upper limb bones (right) | 6 | 10 |
| Skeletal | Vertebral column | 25 | 33 |
| Cardiovascular | Heart and great vessels | 30 | 40 |
| Nervous | Brain | 52 | 54 |
| Respiratory | Respiratory system | 10 | 14 |
| Digestive | Digestive system | 12 | 25 |
| Urinary | Urinary system | 14 | 15 |
| Muscular | Muscles of the hip and lower limb (right) | 35 | 50 |
| Muscular | Muscles of the shoulder and upper limb (right) | 39 | 47 |
| Muscular | Muscles of the trunk (abdominal wall and back) | 20 | 30 |

Every structure has a stable ID, its FMA ID(s), a Latin name, synonyms, a group and verified relationships (joins with, supplies, drains into, works with, opposes and so on). Some structures are the union of several BodyParts3D meshes (for example the three parts of deltoid); this is recorded in `pack.json`. Dataset limits are taught honestly: BodyParts3D has one mesh for the whole heart wall (the chambers are taught on its card), no aortic valve and no coccyx.

## What each activity includes
- **Teacher editor** (*Edit anatomy and content*):
  1. *Structures and labels*: tick the bones to teach (whole groups or single bones), preview each group the way students see it, click **Set anchor point** and then the bone surface, drag label boxes where you want them (or *Auto-arrange*), rename labels, and add a study tip.
  2. *Teaching content*: **AI fill** (one bone) or **AI fill all**. Drafts are highlighted for review, then you **Approve** or **Discard** them. You can also edit by hand, or **Reset to library content**.
  3. *Questions*: AI questions per bone or for all bones, then approve, edit, delete or add your own. Only approved (or library) questions reach students.
  4. *Language and voice*: translation progress, **Translate with AI**, group and view names, voiceover summary, **Generate all voiceover now**, preview and delete stored audio.
- **Reports**: For each structure: wrong rate, the structure it is most confused with, question accuracy, and how many students find it difficult. For practice: tries to get it right. Attempts: delete, recalculate grades, and download as CSV, Excel or ODS.
- Gradebook, completion ("Finish an attempt", plus passing grade), backup and restore with user data, the Privacy API, course reset and events.
- Accessibility: tap-tap and keyboard placement as well as drag (WCAG 2.5.7), live-region announcements, visual states that don't rely on colour alone, and reduced-motion support.

## Language and voiceover
- **Activity language** (51 languages). The whole activity switches: the interface (when the Moodle language pack is installed), teaching content, labels, group names, questions and voice. Interface strings ship in English plus 24 translations (de, fr, es, it, pt_br, nl, pl, sv, da, no, cs, ro, ru, uk, el, tr, ar, hi, id, vi, ja, ko, zh_cn, th), AI-assisted and open to correction.
- **Translate with AI** (LMS Labs credits): content, names and questions become drafts in the activity language for the teacher to approve; group names and view names are translated in one request and can be edited.
- **Voiceover** (LMS Labs text to speech, Google Chirp 3 HD voices, LMS Labs credits): the teacher picks one of 8 voices (Aoede, Kore, Leda, Zephyr, Charon, Fenrir, Orus, Puck) and where the voice is used: Study cards (Listen, Say it), Practice prompts, questions and answer options, and Practice feedback. Test mode never speaks feedback. Optional "Read automatically"; students can turn the voice off.
- Each clip is generated once per activity and stored (and backed up), so replays are free. Teachers can generate all audio in advance from *Edit anatomy and content → Language and voice*. Only server-signed text can be spoken.

## AI setup (LMS Labs)
All AI requests use your LMS Labs AI credits.

1. Install **Central Config** (`local_aiconfig`). Then enter the site's **Site ID** and **LMS Labs API key** once, under *Site administration → Plugins → Local plugins → AI Grader Central Config*.
2. Open *Site administration → Plugins → Activity modules → AI Anatomy*. Central Config credentials are selected by default (including on upgrades); no separate activation page is required. **Check access** is free. **Unlock…** reviews the live release and explicitly confirms the one-time **50-credit** deduction from your existing balance before it is sent. Installing, opening or saving settings never buys access; credentials alone do not grant the paid entitlement. Previously unlocked sites remain unlocked and repeat confirmations are checked against the server before any charge.
3. The LMS Labs endpoints are built in (text: `https://lms-labs.com/api/moodle/ai-anatomy/text`, voiceover: `https://lms-labs.com/api/moodle/ai-anatomy/speech/tts`, voice catalogue: `.../speech/capabilities`). The site needs its own AI Anatomy entitlement at LMS Labs (one-time acquisition: 50 LMS Labs credits, or US$5 on the Moodle Marketplace). Usage: 3 credits per successful text request, 1 credit per successful voice clip (up to 200 characters); failures cost nothing. All billing happens on the LMS Labs server; the plugin never debits credits itself. For testing against another LMS Labs server, set `$CFG->forced_plugin_settings['mod_aianatomy']['lmslabs_endpoint']` / `['lmslabs_tts_endpoint']` in config.php.

How the credentials work:
- Central Config credentials are used by default. They are read when they are needed and never copied into this plugin's settings.
- A Site ID and API key entered in AI Anatomy's own settings are only used as a complete pair. That happens when Central Config has no complete pair, or when *Use this plugin's own credentials* is ticked. Central and local values are never mixed.
- If no complete pair exists, AI buttons show a configuration message and no request is sent. A rejected key gives a clear error and is not retried with other credentials.
- The API key stays on the server. Generation requests send it only in the `X-API-Key` header (with `X-Site-ID`); the activation routes send it in their JSON request body, as LMS Labs requires. It is never sent to the browser or written to logs or content.
- No student data is sent to the AI service. Prompts contain only anatomy facts from the pack and the learner level; voiceover sends only the teaching text to be read.

LMS Labs AI credits are the only AI source. There is no free or third-party AI option. Without LMS Labs credentials, teachers can still use and edit the library content and questions by hand.

## Security and integrity
- In Test mode the browser never receives the answer key. Pins, labels and questions use random tokens for each attempt, question options are shuffled for each attempt, and the server marks everything.
- Study data (names on the model) is only sent to the page when Study mode is on.
- Limitation: the 3D model file is shared by Study, Practice and Test, so a technically skilled student could work out bone identities from it. Use Test for learning checks and graded practice, not for high-stakes exams.

## Requirements
- Moodle 4.4 to 5.3. Supports the `public/` layout from 5.1.
- A browser with WebGL. Students without it see a clear message.

## Installation
1. Unzip into `mod/aianatomy` (on Moodle 5.1+: `public/mod/aianatomy`).
2. Go to *Site administration → Notifications*.
3. Set up AI as described above (optional).
4. Add an **AI Anatomy** activity and choose a pack. The pack's main groups are selected by default. Open **Edit anatomy and content** to change them.

## Adding anatomy packs
See `docs/ANATOMY.md`. A pack is a folder in `packs/` containing a `model.glb` (one node per structure) and a `pack.json` (IDs, names, hierarchy, relationships, provenance, library content). The build scripts are in `docs/tools/` (`skel.py` defines the packs' structures, `build_pack.py` builds model, pack.json and attribution, `validate.py` checks content).

## Credits and licences
- Plugin code: © 2026 LMS Hosting Services, GNU GPL v3 or later.
- 3D anatomy: BodyParts3D, © The Database Center for Life Science, CC BY-SA 2.1 JP (converted and simplified). See `packs/<pack>/ATTRIBUTION.txt`. The attribution is shown under the 3D view.
- three.js: MIT licence (`thirdpartylibs.xml`).

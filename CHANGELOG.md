# Changelog

## 1.2.3 (2026-10-03)
Integration hardening against the LMS Labs AI Anatomy server contract (1.2.2 is kept unchanged as a separate release).
- A result that LMS Labs returned but Moodle could not store (e.g. a database error) keeps its Idempotency-Key, so the next attempt replays it within 24 hours without another debit. Only an unusable reply retires the key.
- Speech clips that end in 409 or 410 keep a failed marker: student plays and automatic reading never create a new billable key for them; only a teacher's explicit "Generate all voiceover" does. The editor shows how many clips are affected (new string `voice_failed`, all 24 translations).
- Documentation: approved billing (one-time 50 credits / US$5; 3 credits per text request; 1 credit per clip of up to 200 characters; billing only on the LMS Labs server) and the access-control summary.
- New PHPUnit test `test_failed_marker`; terminal-status test extended to 404 and 413.

## 1.2.2 (2026-10-02)
Release pipeline fixes (no functional changes for teachers or students):
- Security: `model.php` now requires login and the `mod/aianatomy:view` capability for the activity (the model URL is per activity: `model.php?id=<cmid>`). No `PARAM_RAW`/`PARAM_RAW_TRIMMED` parameters remain; voiceover text is signed after `PARAM_TEXT` cleaning so it passes web service cleaning unchanged.
- Coding style: `function (` spacing for closures; multi-line calls have the opening parenthesis last on its line and the closing parenthesis on its own line (syntax-token-identical rewrite); no line over 132 characters.
- Language: the FMA label is written out ("Foundational Model of Anatomy ID").

## 1.2.1 (2026-10-02)
- Version bump to test the release and update pipeline. No functional changes and no database changes.

## 1.2.0 (2026-10-02)
Implements the LMS Labs "AI Anatomy server contract" on the plugin side (see docs/LMSLABS_INTEGRATION.md).
- Text route is now `https://lms-labs.com/api/moodle/ai-anatomy/text`. Requests carry `metadata.operation` (content, questions, translation, grouptranslation), translations carry a structured top-level `source`, and no model override is sent (the setting is removed).
- Both routes send `X-Site-ID` + `X-API-Key` and a persisted `Idempotency-Key`. Keys and exact bodies are stored (new table `aianatomy_job`) before the first request and re-sent unchanged when polling or recovering, so nothing is charged twice. A deliberate new generation gets a new key.
- 202/429 are polled after `Retry-After` from the browser (also after a page reload, where the editor finishes unfinished jobs); 502/503/504 and network errors recover with the same key; 401/402/403/404/409/410/413/422 are reported clearly and never retried.
- Voice catalogue: the plugin reads the authenticated capabilities route, only accepts voices available for the activity language, and substitutes a same-gender voice if the catalogue changes.
- Teachers see the tariffs (3 credits per text operation, 1 per voice clip; failures free) and the credits/balance LMS Labs reports.
- 403 now explains that AI Anatomy needs its own entitlement (Language Teacher/Soft Skills do not include it).

## 1.1.1 (2026-10-01)
- The LMS Labs endpoints are built in: `https://lms-labs.com/api/moodle/ai-anatomy/generate` (text) and `https://lms-labs.com/api/moodle/ai-anatomy/speech/tts` (voiceover). The endpoint and voice-format admin settings are removed (the upgrade deletes their old values); only the Central Config credentials are needed. config.php can still force other URLs for testing.
- A 404 from LMS Labs now says the service is not available yet instead of a generic error.

## 1.1.0 (2026-10-01)
- **14 new anatomy packs** (all body systems, separate regional packs): skull; vertebral column; thoracic cage; upper limb bones; pelvis and lower limb bones; foot and ankle; heart and great vessels; brain; respiratory; digestive; urinary; muscles of the upper limb, lower limb and trunk. Every structure has written library content and there are 442 questions in all packs. Packs are grouped by body system in the activity settings.
- Tissue colours for organ and muscle packs; relationships are shown by type (Joins with, Supplies, Drains into, Works with, Opposes, and so on).
- **Activity language** (51 languages): interface, content, labels, questions and voice follow it. Interface translations for 24 languages. **Translate with AI** for content, names, questions and group names (drafts for teacher approval).
- **Voiceover** with LMS Labs text to speech (Chirp 3 HD, 8 voices): Study cards, Practice prompts, questions, Practice feedback; generated once and stored; teacher pre-generation; students can turn it off. New admin settings: text-to-speech endpoint, voice name format, student generation.
- The pack can no longer be changed once students have attempts.
- Fixed: structure names in the editor's Teaching content list were invisible with some themes (white button text).
- New tables/fields (upgrade step 2026100100): activity language, group texts, voice settings; content and question language; `aianatomy_voice` clip index.

## 1.0.2 (2026-09-28)
- Study now shows the full teaching content for every structure in the pack. Ticking structures only decides what Practice and Test assess; unticked ones show a "Not tested" tag instead of "not part of this activity".
- New activities assess the whole hand (carpals, metacarpals, phalanges) by default; the radius and ulna stay as context.
- Fixed unreadable text on a selected, unticked item in the Study list.

## 1.0.1 (2026-09-27)
- LMS Labs AI credits are now the only AI source. The "AI provider" setting, the Moodle AI option and the copy-and-paste ChatGPT prompt/import have been removed.
- Removed empty folders from the release ZIP, which caused "Extracted file not found" on install.

## 1.0.0 (2026-09-27)
First release.

- Verified 3D anatomy from BodyParts3D (right hand and wrist pack: 8 carpals, 5 metacarpals, 14 phalanges, distal radius and ulna), rendered with three.js.
- Study mode: drill-down explorer (hand, then group, then bone), exploded view with a slider, camera presets (palmar, dorsal, radial, ulnar), labels with leader lines, search in English or Latin, info card per structure (Latin name, pronunciation with "Say it", word origin, location, function, memory aid, clinical note, joins with), isolate and show in context.
- Practice mode: Label it (drag or tap-tap names onto the model), Find it (click the named bone), then questions. Hints, show answers, instant feedback, mastery tracking, "Practise weak structures".
- Test mode: Label it and questions with no help, marked on the server with per-attempt tokens, rounds locked on submit, time and attempt limits, grade split between labelling and questions, gradebook, completion.
- Teacher editor: choose structures, set anchor points on the mesh, drag label boxes, label overrides, study tip, review AI drafts (content and questions) before students see them.
- AI via LMS Labs using the site's Central Config credentials (local_aiconfig).
- Reports (structure difficulty, confusion, question accuracy, students finding it difficult; attempts with delete, regrade and download), backup and restore, privacy API, course reset, events.

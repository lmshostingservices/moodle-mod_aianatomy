# Changelog

## 1.2.9 (2026-10-08)
The one-time 50-credit site unlock is reviewed and confirmed inside normal plugin settings rather than on a separate activation page. Central Config remains the default credential source on new and existing installations. A GET or settings save never spends credits; the server entitlement check, release SHA/price validation, pending-request protection and separately billed AI usage remain in force.

## 1.2.8 (2026-10-08)
Voiceover playback failures now reach the existing student-visible error message instead of being silently treated as successful playback. Empty clip responses are rejected, explicit retries can report failures again, and cancelled playback does not report stale errors. No speech requests, charging, permissions or database contracts change.

## 1.2.7 (2026-10-07)
Fail closed on incomplete release metadata (`zipExists` must be true). Verification only settles a pending unlock when `unlocked` is a JSON boolean; malformed values leave the outcome uncertain. Unlock success, already-unlocked and explicit refusal flags must also be genuine JSON booleans. No generation, speech, media, database or pricing changes.

## 1.2.6 (2026-10-06)
Activation corrections after LMS Labs inspected 1.2.5 (1.2.5 is kept unchanged, SHA-256 39e33d08a35e969ec0bb179587e0579e04187d09400bef8e3266622e37293a63). No database changes; generation, speech, viewer and AMD files are unchanged apart from one HTTP option (below).
- Unlock replies are read from the real LMS Labs fields: `success`, `alreadyUnlocked`, `creditsConsumed`, `entitlementSource`, `remainingCredits`, `message` (`downloadUrl` is ignored). The non-existent `creditsCharged`/`charged` are no longer read.
  - New unlock: "LMS Labs recorded N credits for this unlock" from `creditsConsumed`.
  - Recognised purchase (`creditsConsumed` 0 with `entitlementSource` marketplace or purchase): reported as activated from the existing purchase at 0 credits.
  - `alreadyUnlocked`: access granted; `creditsConsumed` is shown only as the original purchase, never as a new debit.
  - The balance LMS Labs reports and its escaped `message` are shown with the result.
- Balances per route: verify `credits` (-1 = unlimited), unlock success `remainingCredits` (may be "unlimited"), insufficient-credit errors `currentCredits`.
- 409 responses are no longer all treated as a changed price: `stale_credit_price` asks the admin to review the new price, `marketplace_entitlement_ambiguous` asks them to contact LMS Labs support (retrying will not help), and other conflicts show the server's message. The escaped server code and message are always displayed.
- Stricter eligibility: only `acquisitionMode` exactly "credit-unlock", a positive whole `creditsRequired`, a valid SHA-256, an available status (the live manifest uses "ready") and `zipExists` true may be bought. A USD-purchase release never enters the credit flow.
- Server-side HTTP requests no longer follow redirects (`allow_redirects` false), both for the activation routes (credential-bearing bodies) and for the generation routes (credential headers).
- The live release SHA and price are always read at runtime; nothing is hard-coded.
- Strings: 8 new, 2 removed, all 25 languages (607 strings each). Tests rewritten with the real response fields and the live manifest shape.

## 1.2.5 (2026-10-05)
Adds the Moodle-side activation (one-time unlock) interface. Built from the owner-supplied 1.2.4 ZIP (SHA-256 116202a58e06c2d50fa278e22245dd4350edcde4b0cb636f52a520a10221fd1d), which is kept unchanged. No database changes; AI Anatomy content, speech and text contracts and operation prices (text 3 credits, speech 1 credit) are unchanged.
- New administrator-only page **Site administration > Plugins > Activity modules > AI Anatomy activation** (`mod/aianatomy/activation.php`, capability `moodle/site:config`), also linked from the AI Anatomy settings page. It shows the credential source (Central Config or a complete standalone pair, with a link to configure Central Config), access (Not checked / Locked / Unlocked / Unable to verify), the balance (including unlimited), and the live one-time activation price with the release SHA-256 read from the LMS Labs release catalogue.
- **Check access** calls `POST /api/plugin-unlock/verify` (free). **Unlock…** first shows a confirmation with the live price; only the confirmed POST calls `POST /api/plugin-unlock` with `releaseSha256` and a numeric `expectedCredits` equal to the price shown. Immediately before buying, the plugin checks access again (never buys twice) and re-reads the catalogue; if the price or release changed, nothing is bought.
- Unlocking is disabled when the catalogue entry is missing, has no valid SHA-256 or price, is not available, or its acquisition mode does not allow credits. A balance below the price is shown as a warning (LMS Labs may recognise an existing purchase at zero credits; otherwise it refuses with 402).
- Outcomes: already unlocked, activation from an existing entitlement at 0 credits, insufficient credits, refused, and changed price are reported plainly. A charge is only reported when LMS Labs states it for this request; `creditsConsumed` is shown as history, never as a new debit. If the purchase gets no clear answer (network error, timeout, 5xx, unreadable reply), a pending marker keeps Unlock disabled until a free access check settles it.
- Every action is a POST with sesskey; nothing is bought on page load. Credentials are sent only in the server-side request bodies the contract requires and never appear in HTML, JavaScript, URLs, stored state or logs.
- The credentials status on the settings page now says that configured credentials are not proof of activation, and the missing `credentials_local` string (shown when a standalone pair is used) is added.
- New strings in all 25 languages (601 strings each). New PHPUnit test `tests/activation_test.php` (mocked LMS Labs, no credits spent).

## 1.2.4 (2026-10-04)
Fixes and improvements from classroom testing (1.2.3 is kept unchanged as a separate release). No database changes.
- Fixed "sessioncannotobtainlock" when a student moved quickly through questions with voiceover on. The voiceover, AI fill and translation services now release the Moodle session lock before calling LMS Labs, so saving answers is never blocked while audio is generated. Practice answers that fail to save are retried quietly (and sent before the attempt finishes) instead of showing an error and being lost.
- Colours: each structure on the 3D model is now tinted to match its numbered label (a lighter shade of the label colour), in Study, Practice and Test, and keeps that colour once labelled correctly (with a green glow). The label palette is new: 24 colours with white-text contrast of at least 4.6:1, neighbouring numbers far apart in hue, and no repeats until number 25 (previously 12 colours with near-duplicates, repeating from 13).
- Practice: after a correct label, a card shows the structure's name, Latin name and two key facts (function, how to remember it, clinical note or location; only fields the teacher shows students), with Listen when voiceover is on. It never blocks dragging or clicking a label box.
- Activity language: the whole activity (buttons, messages, library names) now switches to the activity language using the plugin's own translations, even when the site has no Moodle language pack for it. Teachers see a notice with a link to translate the content when structures are still in English. Library names are translated into all 24 languages.
- New strings: `lang_teachernotice` and `pack_*` (15 library names); `activitylanguage_note` and `lang_notinstalled` reworded; all 25 languages updated (538 strings each).

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

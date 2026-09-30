# AI Anatomy (mod_aianatomy) and LMS Labs: client implementation of the server contract

Plugin version **1.2.12**. This document describes what the plugin sends and how it handles every response, matching the LMS Labs "AI Anatomy server contract". Installed-Moodle acceptance against the real service has not been done yet.

## 1. Routes (built in, no admin setting)

| Purpose | Method and URL |
|---|---|
| Text: `content`, `questions`, `translation`, `grouptranslation` | `POST https://lms-labs.com/api/moodle/ai-anatomy/text` |
| Speech (voiceover clip) | `POST https://lms-labs.com/api/moodle/ai-anatomy/speech/tts` |
| Voice catalogue | `GET https://lms-labs.com/api/moodle/ai-anatomy/speech/capabilities` |

- Code: `classes/local/ai/endpoints.php`. For staging, `config.php` can force other URLs: `$CFG->forced_plugin_settings['mod_aianatomy']['lmslabs_endpoint' | 'lmslabs_tts_endpoint' | 'lmslabs_tts_capabilities']`.
- No query parameters are sent.

**Approved billing** (charged only by the LMS Labs server; the plugin never debits credits and only displays tariffs and the reported charge/balance):
- One-time acquisition: 50 LMS Labs credits, or US$5 on the Moodle Marketplace.
- 1 credit per successful speech clip (at most 200 Unicode characters).
- 3 credits per successful text operation.
- Failures, validation rejections, capabilities requests, polling and replays cost nothing.

**Entitlement:** a site needs its own `aianatomy` / `mod_aianatomy` entitlement. Language Teacher and Soft Skills entitlements do not count, and the plugin says so on a 403.

## 2. Credentials and headers (both routes)
- **Credentials:** Central Config (`local_aiconfig`) is used first. The plugin's own pair is used only as a complete pair. The two are never mixed, and after a 401 the plugin never tries another pair.
- **Headers sent:** `X-Site-ID`, `X-API-Key` and `X-LMS-Plugin: mod_aianatomy`. The `Bearer` / `X-LMS-Site-Id` alias is no longer sent, so there is no risk of disagreeing aliases.
- **Where the key goes:** only into the request header. It is never stored with a job, logged, or sent to the browser.
- **No user identity is sent.** The text `metadata` carries `plugin`, `operation`, `siteid` and `contextid` only.

## 3. Idempotency: persisted jobs (`aianatomy_job`, `classes/local/ai/jobs.php`)
- **Before the first request,** the plugin stores a random key (`aa-` + 40 random characters: printable ASCII, no spaces) together with the exact JSON body. The row is unique per activity, operation and target (a structure id, `_groups`, or a speech clip hash).
- **Same key and same body until the result is stored.** Polling after 202 or 429, recovering after a network loss, a timeout, a 502/503/504, a page reload or a second student requesting the same clip: each of these re-sends the stored key with the stored body byte for byte.
- **Store first, then discard.** On a 200 (first result or replay), the result is written to Moodle first: the draft, the draft questions, the group texts, or the MP3 in the activity's file area. Only then is the job deleted. If storing fails (for example a database error), the job and its key are kept, so the next attempt replays the result within LMS Labs' 24-hour retention without another debit. Only an unusable reply (not JSON, no valid questions) retires the key.
- **409 and 410 never lead to an automatic new key.** For text, the job is removed and only a teacher's next click starts a new request. For speech, the clip keeps a failed marker: student plays and automatic reading send nothing for it; only a teacher's explicit *Generate all voiceover* creates a new key. The editor shows how many clips are in this state.
- **New click, new key.** A deliberate new generation after the previous one finished gets a new key. Clicking again while a job is still pending resumes that job; it does not start a new one.
- **Speech and text keys never mix.** Each clip and each text operation has its own job and its own key.
- **After a reload,** the editor finishes any text jobs still in progress, with the same keys.
- **Cleanup:** jobs older than 2 days are removed. LMS Labs keeps results for 24 h, after which the key returns 410.

## 4. Status handling (`classes/local/ai/lmslabs.php`)

| Response | Plugin behaviour |
|---|---|
| 200 | Use the result (a replay is treated as a normal success) and store it; then delete the job |
| 202, 429 | Keep the job; the browser waits `Retry-After` seconds (default 5) and calls again, for up to about 3 minutes. After that the teacher is told it continues on the next click or reload, with no second charge |
| 502, 503, 504, network error or timeout | Keep the job; recover with the same key after `Retry-After` |
| 401 | "LMS Labs rejected the Site ID or API key"; job deleted; no retry |
| 403 | "This site does not have an AI Anatomy entitlement…"; job deleted |
| 402 | "Credits have run out"; job deleted |
| 404 | "The LMS Labs AI Anatomy service is not available yet"; job deleted |
| 409 | "Did not match an earlier request with the same key; nothing was charged". Text: job deleted. Speech: failed marker kept. Never re-sent automatically under a new key |
| 410 | "Failed or expired (results are kept for 24 hours). Run it again". Text: job deleted. Speech: failed marker kept. Not polled again |
| 413, 422 | "Rejected as invalid and not charged: <code> <message>"; job deleted |

## 5. Speech
- **Body:** `{"text","locale","voice","speed"}` with no other fields. `text` is 1–200 code points (the plugin splits longer text at sentence, then clause, then word boundaries). `speed` is `normal` (1.2.12 no longer creates `slow` name clips; the route still accepts `slow`). `voice` is the short name.
- **The eight voices:** Aoede, Kore, Leda, Zephyr, Charon, Fenrir, Orus and Puck.
- **Voice catalogue:** fetched with authentication, cached for 12 h, and on failure retried at most hourly with a 10 s timeout.
  - The activity form only accepts a voice that the catalogue lists for the activity's locale, and names the available ones otherwise.
  - If the catalogue changes later, the player uses the first available voice of the same gender and the editor shows the substitution.
  - While the catalogue can't be read, all eight are offered and the server's 422 is shown if one is unavailable.
  - The parser accepts `{locales:[{locale, voices:[names|{name}]}]}`, `{voices:[{languageCode, name}]}` or `{"de-DE": [...]}`, and reduces full Chirp 3 HD names to short names.
- **Response:** a 200 must be `audio/mpeg`. `X-Credits-Charged`, `X-Credits-Balance` and `X-Request-Id` are stored per clip. On a replay these are the original settlement snapshot, recorded only once per clip.
- **Storage:** clips are stored per activity and reused indefinitely (included in backups), so replaying audio never calls LMS Labs.
- **Access:** students can only request audio for text the server signed (HMAC), and the admin can limit generation to teachers.

## 6. Text
```json
{
  "messages": [{"role": "system", "content": "..."}, {"role": "user", "content": "..."}],
  "temperature": 0.3,
  "response_format": {"type": "json_object"},
  "metadata": {"plugin": "mod_aianatomy", "operation": "content", "siteid": "<SITE_ID>", "contextid": 1001},
  "source": { }
}
```
- **Fields and limits:**
  - `source` is sent only for `translation` and `grouptranslation`.
  - No `model` is sent (LMS Labs pins it); the old model override setting has been removed.
  - Before sending, the plugin checks the limits: two messages of at most 12,000 code points together, and a body of at most 32 KiB.
- **`content`:** the reply's 9 fields are cleaned (plain text, 2,000 characters each) and stored as a draft for teacher approval.
- **`questions`:** exactly 3 are requested. Each valid one is stored as a draft. If none is valid, the teacher is told and nothing is stored.
- **`translation`:**
  - `source = {name, synonyms (≤ 20), content (only the non-empty fields among the nine), questions: [{ref, text, options, explanation}]}`.
  - Only student-visible questions in the content's language that have exactly 4 distinct options are included, at most 10, with refs 1..n. The ref → question id map is kept with the job.
  - The reply becomes a content draft (the translated name becomes the label on approval) plus draft questions. Each question keeps the source question's kind and answer index; the reply's answer is never used.
- **`grouptranslation`:** `source` is keyed by the exact group ids and view ids (`_preset_<id>`), each `{label, tip}`. The reply is kept only for known ids, stored on the activity, and editable by the teacher.
- **Credits reporting:** `X-Credits-Charged` and `X-Credits-Balance` are passed to the editor when present.

## 7. Teacher actions that call LMS Labs
Everything else — approving, editing, deleting, and all student activity except playing a clip that hasn't been generated yet — never calls LMS Labs.

| Action (Edit anatomy and content) | Operation | Requests |
|---|---|---|
| AI fill (one structure) / AI fill all | `content` | 1 per structure |
| AI questions (one / all) | `questions` | 1 per structure |
| Translate (one) / Translate all | `translation` (+ `grouptranslation` once) | 1 per structure |
| Translate group names | `grouptranslation` | 1 |
| Generate all voiceover / Preview voice | speech | 1 per missing clip |
| Student plays a missing clip (if allowed by the admin) | speech | 1 per missing clip |

## 8. Verification (stand-ins only)
- **Fake routes used:** the harness fakes follow the contract: aliases and 401, 403 entitlement, Idempotency-Key replay/202/409/410, strict body validation (422/413), query rejection, `metadata.operation`, `source` rules, no model, and a voice catalogue in which German lacks Leda.
- **Passing scenarios:**
  - 202 → 202 → 200 with the same key and body.
  - A new key for a deliberate regeneration.
  - Translations with a structured source, keeping the answers from the source.
  - Group translation including preset ids.
  - 409 and 410 each discarding the job.
  - Voice substitution (Leda → Aoede in de-DE).
  - Speech pending then stored, with replays served from Moodle.
  - Network loss recovered with the same key.
  - 403 for both routes.
  - A pending job finished after an editor reload.
- **PHPUnit:** `tests/voice_test.php` covers the same cases with a transport seam. It has not yet been run inside real Moodle.
- **Not done:** requests against the published LMS Labs routes, and installed-Moodle acceptance.

## 9. Access control
- **Web services:** all 14 are AJAX services, so Moodle requires a valid `sesskey` for each call, and each checks capabilities in the module context:
  - `generate` and `translate` need `mod/aianatomy:manage` and `mod/aianatomy:useai`.
  - `save_*` and `voice_clear` need `mod/aianatomy:manage`.
  - `speak` needs `mod/aianatomy:view`; with `prefetch` it also needs `mod/aianatomy:manage`.
  - Attempt services need `mod/aianatomy:attempt`.
- **Pages:** `editor.php` requires `mod/aianatomy:manage`, and `model.php` requires login plus `mod/aianatomy:view`.
- **Credentials:** they never leave PHP. No credential appears in JavaScript, templates or web service responses.
- **Session lock:** `speak`, `generate` and `translate` release the Moodle session lock (`\core\session\manager::write_close()`) after the capability checks and before calling LMS Labs, so a slow generation never blocks the user's other requests.

## 10. Licence materials in the release ZIP
- `packs/<pack>/ATTRIBUTION.txt` (15 packs): BodyParts3D, CC BY-SA 2.1 JP, citation, modifications and FMA IDs.
- `packs/<pack>/pack.json` → `source` metadata.
- `thirdpartylibs.xml`: three.js (MIT) and the BodyParts3D packs.
- `LICENSE`: GPL v3.

## 11. Activation (one-time unlock)
Administrator-only page `mod/aianatomy/activation.php` (capability `moodle/site:config`); code in `classes/local/unlock.php`. All requests are made from PHP, never follow redirects, and send the credentials only in the JSON bodies below.

| Step | Request | Cost |
|---|---|---|
| Check access | `POST https://lms-labs.com/api/plugin-unlock/verify` `{"pluginId":"aianatomy","siteId","apiKey"}` → `unlocked, credits (-1 = unlimited), unlockedAt, entitlementSource` | free |
| Live price | `GET https://lms-labs.com/api/plugin-versions` → `plugins["mod_aianatomy"]`: `sha256`, `acquisitionMode`, `status`, `zipExists`, `creditsRequired`, `version` | free |
| Unlock (after confirmation) | `POST https://lms-labs.com/api/plugin-unlock` `{"pluginId":"aianatomy","pluginComponent":"mod_aianatomy","siteId","apiKey","releaseSha256":"<live SHA>","expectedCredits":<live price>}` | the live price |

**Eligibility** (otherwise Unlock is disabled with the reason): `acquisitionMode` exactly `credit-unlock`; `creditsRequired` a positive whole number; `sha256` 64 hex characters; `status` one of ready, available, published, public; `zipExists` true.

**Unlock replies**

| Reply | Shown to the admin |
|---|---|
| `success`, `creditsConsumed` > 0 | Unlocked; "LMS Labs recorded N credits for this unlock"; balance from `remainingCredits` |
| `success`, `creditsConsumed` 0, `entitlementSource` marketplace/purchase | Unlocked from the existing purchase, 0 credits |
| `success`, `alreadyUnlocked` | Already unlocked; `creditsConsumed` shown as the original purchase, never as a new debit |
| 402 / `insufficient_credits` | Not unlocked; server code and message; balance from `currentCredits` |
| 409 `stale_credit_price` | Not unlocked; review the new price and confirm again |
| 409 `marketplace_entitlement_ambiguous` | Not unlocked; contact LMS Labs support to link the purchase (retrying will not help) |
| other 409 | Not unlocked; server message; contact support if it does not say what to do |
| other 4xx, or 2xx with `success:false` and an error code | Refused; server code and message |
| no answer, timeout, 408, 5xx, unreadable 2xx | Unknown outcome: pending marker; Unlock disabled until a free check returns locked or unlocked |

- Page load reads the catalogue only; the access check and the purchase are POST + sesskey actions. The purchase needs the confirmation screen and re-checks access and the live catalogue immediately before sending; if the live price or SHA differs from what was confirmed, nothing is sent.
- Server messages are shown as plain text (tags stripped, then escaped). `downloadUrl` is not used.
- Staging: `$CFG->forced_plugin_settings['mod_aianatomy']['lmslabs_unlock_base']` replaces `https://lms-labs.com` for these three routes.
- Activation does not change the generation routes, prices (text 3, speech 1) or their handling.

## 12. Voiceover preparation (1.2.10, fixes in 1.2.11, cards only from 1.2.12)
Voiceover is part of every activity and is created before students need it. Nothing changes in the speech request, its headers, idempotency or status handling (sections 3–5).

- **When:** a background task (`\mod_aianatomy\task\prepare_voice`, cron) runs when an activity is created or saved, when its texts change (content, questions, group names, structure selection), and once after the 1.2.10 upgrade. It works for up to 10 minutes per run and queues itself again while clips are missing or LMS Labs answers 202.
- **Waiting screen:** when a student starts a mode before all clips exist, `mod_aianatomy_voice_prepare` first reports progress (`generate=false`, no requests), then, if students may generate audio (admin setting), creates the next clips for about 20 seconds per call. The mode starts when all clips are ready.
- **What is voiced (1.2.12):** only the cards: the card of each *ticked* structure, read in full (name, Latin, pronunciation, every ticked field, related structures). The same text serves the Study card and the Practice pop-up card, so it is one set of clips. Find prompts, questions, answer options, feedback, fixed phrases and separate name clips are no longer voiced. A card is voiced only once its content is in the activity language (approved translation), so a German activity never pays for English text read by a German voice. Each text is split into clips of at most 200 characters.
- **Reuse across activities (1.2.12):** before requesting a clip, the plugin looks for the identical clip (same text, locale, voice and speed; the hash in `aianatomy_voice`) in any other AI Anatomy activity on the site and copies it. No request is sent and the copy is recorded with `credits` 0 and `requestid` `reused`. A second activity from the same library, language and voice costs nothing.
- **Cost:** 1 LMS Labs credit per clip, once per site. A full library with the default settings (all structures ticked) now needs roughly: upper limb bones 25, pelvis 37, respiratory 50, digestive 71, urinary 77, trunk muscles 103, vertebral column 124, skull 119, foot 127, hand 131, heart 169, lower limb muscles 192, upper limb muscles 203, thoracic cage 232, brain 251 clips: 1,911 for all 15 libraries (4,931 in 1.2.11, down 61%). Unticking structures or study fields lowers this further. Changing a text re-voices only that text.
- **Errors:** 401, 402, 403 and 404 stop preparation (state *failed*, stored in `aianatomy.voiceerror`); students may continue without voiceover and teachers see the reason. A clip that ends in 409, 410, 413 or 422 keeps a failed marker and is never re-sent automatically, and from 1.2.12 the other missing clips of the same card are held as well (the card cannot play in part, so they are not paid for until a teacher retries); when all other clips exist the state is *incomplete*, the background task stops, and students get the voiceover that exists. Network errors and 5xx are retried later with the same key.
- **Retry-After:** a clip LMS Labs is still creating (202/429) is not re-sent before its Retry-After time, whoever asks (cron or students).
- **Lost results:** unresolved speech jobs are never deleted; after 2 days they become failed markers, so no automatic request can create a new key and pay twice for the same clip.
- **Throughput:** at about 30 new clips a minute per site, the largest library (251 clips) takes about 8 minutes; a reused library is ready at once. Students can start after a minute; the voiceover keeps being prepared and plays wherever it is ready.
- **Never partial:** a text plays only when every one of its clips exists.

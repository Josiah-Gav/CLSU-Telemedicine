# CHIS demo runbook (about 10 minutes)

Operational script for presenting the CHIS integration. Read it once, run the
checklist, then follow the script top to bottom. You cannot break the data by
following it in order.

**Say this first, and say it honestly:** the integration is with a **simulated CHIS**
that the team built to a contract the team wrote, because no real CHIS API
specification exists. Connecting to the real CHIS means changing configuration and,
if its contract differs, `ChisClient` and `ChisEncounterPayload`.

Two Postman collections are used. Name them out loud so the panel can follow:

- **"Telemed – CHIS demo"** — `docs/postman/Telemed-CHIS-Demo.postman_collection.json`
  with environment `Telemed-CHIS-Local.postman_environment.json` (Telemed's own API).
- **"CHIS Simulation"** — `postman/CHIS-Simulation.postman_collection.json` in the
  `clsu-chis-simulation` repository, with its own environment file (variables
  `base_url`, `chis_token`). This is the *CHIS side* of the story.

---

## 1. Setup checklist

### The day before

- [ ] Telemed: `composer install`, `npm install && npm run build`, `php artisan migrate`.
- [ ] Telemed: `php artisan config:clear` and `php artisan route:clear` (a stale route cache hides new routes).
- [ ] Simulator (`clsu-chis-simulation`): `git pull`, `composer install`, `php artisan migrate:fresh --seed`.
- [ ] Telemed `.env` has `CHIS_BASE_URL=http://localhost:8001/api/v1` and a `CHIS_TOKEN` equal to the simulator's `CHIS_API_TOKEN` (see `.env.example`).
- [ ] `php artisan db:seed --class=QaTestUsersSeeder` (creates `patient.test1`, `physician.test1`, …; their CLSU IDs match the simulator's seed data).
- [ ] Rehearse the whole script once.

### 15 minutes before

- [ ] Terminal 1 — simulator: `php artisan serve --port=8001`
- [ ] Terminal 2 — Telemed: `composer run dev` (server **and queue worker**), or `php artisan serve` plus `php artisan queue:work`.
- [ ] **Warm-up** (the first call to a freshly started simulator can be slow and hit the 5-second timeout): `php artisan chis:health --patient=23-0001` — expect `CHIS is reachable and healthy.` and `Patient 23-0001: found (QA Patient)`.
- [ ] Create the Postman tokens (an active admin's email):
  - `php artisan telemed:api-token <admin-email> --name=demo --ability=encounters:read --ability=encounters:sync`
  - `php artisan telemed:api-token <admin-email> --name=demo-readonly --ability=encounters:read`
  - Paste them into the Postman environment as `telemed_token` and `telemed_read_token`. Each token is shown **once**; it lasts 30 days.
- [ ] **Withdraw consent first**, so Step 1 can show giving it: log in as patient.test1 → Profile → **Share records with CHIS** → **Withdraw consent** (it should now say "Sharing is off"). patient.test1 already consented during development. This is safe: consent is forward-only, and the demo consultation is completed *after* Step 1, so it will still be eligible.
- [ ] Prepare a never-sent consultation for the demo: `php artisan db:seed --class=ChisDemoSeeder` (local environment only).
  - If it prints `Demo consultation created: session #N …`, put `N` into the Postman variable `encounter_id`.
  - If it prints `patient.test1 already has an active consultation; nothing created.`, use that existing active consultation instead. Find its id as physician.test1: open **Active Consultations**, open the consultation, and read the number in the address bar — `/consultation-sessions/N/messaging`. Put `N` into `encounter_id`.
  - The first delivery (HTTP **201**) happens in Step 3, when the background worker sends it; you will show that 201 in the audit log in Step 6. Every later sync of the same consultation returns **200** with the same reference.
- [ ] Log in to three browser windows: **patient.test1** (profile page), **physician.test1** (active consultations), **admin** (`/admin/chis-sync`).
- [ ] Patient.test1 password and the others are in `database/seeders/QaTestUsersSeeder.php`.

---

## 2. Script

### Step 1 — Consent (patient window, ~1 min)

- **Click:** patient.test1 → Profile → scroll to **Share records with CHIS** → **I consent to sharing with CHIS**.
- **They should see:** green "Sharing is on" with today's date; the lists of what is and is not shared; "Applies to consultations completed after you give consent".
- **Say:** "Nothing leaves Telemed without the patient's consent, and consent only covers consultations completed after it is given."

### Step 2 — Receive: the physician sees the CHIS record (~2 min)

- **Click:** physician.test1 → Active consultations → open the demo consultation → **Patient Details** tab.
- **They should see:** "CHIS medical record" with blood type, allergies (Penicillin, and Shrimp marked **Severe**), asthma, Salbutamol, immunizations, family history, "Last updated in CHIS", and "Viewing this record is logged."
- **Say:** "The tab loads the record only when it is opened, never while the page renders. Before showing anything, Telemed checks consent, then that the CHIS record's name matches this patient, and it writes an audit entry; if it cannot log the view, it does not show the record. Nothing from CHIS is stored in our database."

### Step 3 — Send: complete the consultation (~2 min)

- **Click:** physician window → **Assessment** tab → **Complete consultation** and confirm the "Complete consultation?" dialog.
- **Admin window:** refresh `/admin/chis-sync` → the row appears as **Synced** with a CHIS reference such as `ENC-2026-00000N`.
- **Postman, CHIS side ("CHIS Simulation" collection):** request **List encounters**, then **Get one encounter**. "Get one encounter" uses the collection variable `{{chis_encounter_id}}`: before sending it, replace it with the CHIS reference shown on `/admin/chis-sync` (e.g. `ENC-2026-00000N`) — either in the request URL or in the collection's **Variables** tab. **Telemed side ("Telemed – CHIS demo" collection):** request **4. CHIS – get that encounter** needs the reference first — run step 4 before it, or paste the reference into the `chis_reference` collection variable.
- **They should see:** the encounter in CHIS with the diagnosis and the payload as CHIS received it.
- **Say:** "Completing the consultation only queues the send, so a CHIS outage can never block the physician. A background worker sends the summary: diagnosis, assessment, plan, recommendations, symptoms — never the chat, uploads, or the prescription file."

### Step 4 — Safe to retry: the Telemed API (~2 min)

- **Postman ("Telemed – CHIS demo"):** **1. list encounters** → **2. get encounter** → **3. sync encounter to CHIS** → **4. CHIS – get that encounter** → **5. sync again**.
- **They should see:** request 3 → `chis_sync_status: synced`, `chis_http_status` **200**; request 5 → the **same reference**, `chis_http_status` 200; request 6 → no token gives 401, read-only token on sync gives 403.
- **Note:** Postman will not show 201 here, because the worker already made the first delivery in Step 3. Do not apologise for it — the 201 is shown in the audit log in Step 6.
- **Say:** "CHIS treats the encounter id as an idempotency key: sending the same summary twice returns the same reference, so retries are safe. The API only ever exposes sync status — no names, no CLSU IDs, no clinical text."

### Step 5 — Resilience (~2 min)

- **Do:** stop the simulator (Ctrl+C in terminal 1). In Postman run **3. sync encounter** (or click **Retry** on a failed/pending row in `/admin/chis-sync`).
- **They should see:** HTTP 200 but `chis_sync_status: pending` and "CHIS could not be reached" (the Telemed request finishes within the 5-second timeout). The admin page banner shows "CHIS not reachable" (it can lag up to 60 seconds).
- **Do:** restart the simulator (`php artisan serve --port=8001`), run `php artisan chis:health --patient=23-0001` to warm it, then repeat the sync (or wait; the sweeper `chis:sync-pending` retries pending rows every 15 minutes).
- **They should see:** `synced` again with the same reference.
- **Say:** "A transient failure never becomes a permanent one. It stays pending and retries with backoff; only a definite rejection from CHIS is marked failed."

### Step 6 — Accountability: the audit log (~1 min)

- **Click:** admin window → **Audit log** (or `/admin/chis-audit`).
- **They should see:** every send and every view — time, action ("Summary sent" / "Patient record viewed"), outcome, source (Sync job / API request / Consultation view), patient, actor (the physician, an admin, or "System"), consultation, HTTP status, duration. **History** on a sync row filters to one consultation.
- **Show the first-delivery vs safe-retry proof here:** on `/admin/chis-sync` click **History** on the demo consultation's row (or type its number in the audit page's *Consultation #* filter). The oldest "Summary sent · Sync job" row (actor "System") has HTTP **201** — the first delivery from Step 3. The later "Summary sent · API request" rows from Step 4 (actor: the admin who owns the token) have HTTP **200** with the **same CHIS reference**. (Step 5's outage adds an "Unavailable" row with no HTTP status, followed by another 200.)
- **Say:** "The log records that data was sent or viewed, by whom and when — never what. It is append-only. Here you can see the first delivery returned 201 and every retry returned 200 with the same reference."

### Step 7 — Optional extras (if time allows)

- **Identity mismatch:** in tinker set a test patient's CLSU ID to `26-000001` (Maria Santos in CHIS), open the Patient tab → "does not match this patient's name — not displayed". **Restore the original ID afterwards.**
- **Security:** Postman request 6 (401 without a token, 403 for a read-only token).

---

## 3. If something goes wrong

| Symptom | Likely cause | Fix |
|---|---|---|
| Postman 401 `UNAUTHENTICATED` | Wrong, revoked or expired token (30-day default) | Create a new one with `telemed:api-token`; paste it into `telemed_token` |
| Postman 403 `FORBIDDEN` | Token lacks `encounters:sync`, or the owner is no longer an active admin | Use the full-access token; check the owner is an active admin |
| First sync or lookup hangs ~5 s then "could not be reached" | Simulator cold start | Run `php artisan chis:health --patient=23-0001` once, then retry |
| Sync stays `pending`, "CHIS could not be reached" | Simulator not running / wrong `CHIS_BASE_URL` | Start it; `php artisan chis:health` |
| "already has a sync attempt waiting" | A queued attempt is in its backoff | Wait for it, or run the worker; it sends at its scheduled retry |
| `failed` with "CHIS rejected… invalid" (422) | Payload rejected by the simulator | See the last-error code on `/admin/chis-sync`; the payload shape may not match the simulator version — `git pull` the simulator |
| `failed` with "already holds different content" (409) | The same consultation id was sent earlier with different content (e.g. simulator not reset after Telemed was reset, or the payload format changed) | Use a new consultation (ChisDemoSeeder after completing the old one), or reset the simulator with `migrate:fresh --seed` |
| `skipped` ("Patient has not consented") | Consent was given after the consultation completed — consent is forward-only | Give consent first, then use a new consultation |
| Patient tab stuck on "Loading…" | Server or worker not running; JS error | Reload; check the browser console and `storage/logs/laravel.log` |
| Patient tab says "CHIS could not be reached — try Refresh" | Simulator down or slow | Start it, press **Refresh** |
| "does not match this patient's name" | CLSU ID points at someone else in CHIS | Admin edits the user's CLSU ID (Admin → Users → Edit) |
| "The patient has not consented…" | Step 1 not done | Do step 1 |
| `Address already in use` on 8001 | An old simulator is still running | Stop it (find the process on port 8001), start again |
| Nothing is sent after completing | Queue worker not running | `composer run dev`, or `php artisan queue:work` |
| New route/page 404s | Stale route cache | `php artisan route:clear` |
| `ChisDemoSeeder` says it only runs locally | `APP_ENV` is not `local` | Set `APP_ENV=local` (never on a shared server) |

---

## 4. Likely panel questions

**Is this the real CHIS?** No. It is a simulated CHIS that we built to a REST contract we wrote, because there is no real CHIS API specification. Switching to the real one means configuration plus, if its contract differs, `ChisClient` and `ChisEncounterPayload`.

**Why not FHIR / HL7?** We had no real specification to conform to. The payload is plain JSON loosely modelled on FHIR's Encounter and Patient shapes; we do not claim it is FHIR.

**What happens if CHIS is down?** Consultations complete normally. The summary stays pending and is retried automatically (backoff, then a scheduled sweeper); a permanent rejection is marked failed and shown to the admin. The physician's Patient tab shows "CHIS could not be reached" and can be refreshed.

**Who can see the data?** Only the physician assigned to that consultation can see the patient's CHIS profile. Patients and nurses cannot (patient self-view is future work). Admins see sync status and the audit log, not clinical content.

**How is consent handled?** The patient gives or withdraws it on their profile. It is required for both directions, it applies forward only, and withdrawing stops future sharing but does not recall summaries already sent.

**What is stored?** Telemed stores only sync status columns on the consultation (status, CHIS reference, attempts, a short error code) and the audit log (that a call happened, never its content). It does not store or cache the CHIS medical profile.

**How do you know it is the right patient?** The CLSU ID must be valid and unique, and the CHIS record's first and last name must match Telemed's. This is names only — Telemed stores no birth date — so two same-named people with a mistyped ID would not be caught. We list that as a limitation.

**Can the audit log be edited?** Not through the application (the model refuses updates and deletes). It is not protected against raw database access.

**What are the other limitations?** See `docs/paper/features/chis-integration.md`, section 18.

# Consultation Messaging

> Terminology follows `docs/paper/glossary.md`. Figure and table numbers use the
> `X.n` placeholder pending final manuscript numbering. Attachments are covered in
> `attachments-and-prescriptions.md`; clinical fields in
> `clinical-documentation.md`.

## 1. Feature Name

Consultation Messaging — text conversation between patient and physician inside a
**consultation session**, with read receipts, a typing indicator, per-session
presence, and unread counts.

## 2. Purpose

To provide the conversational channel of the consultation. It is **not real-time
infrastructure**: every signal is delivered by repeated HTTP requests on a timer.
The manuscript must never describe this as websocket or push messaging.

## 3. Actors / Roles

| Actor | Involvement |
|---|---|
| Patient | Reads and sends while the session is `active`; reads once `completed`. |
| Physician | The assigned physician only. |
| Nurse | **Excluded.** `ConsultationSessionPolicy::viewMessaging` admits only the owning patient and the assigned physician. |

## 4. User Workflow

1. A participant opens `GET /consultation-sessions/{session}/messaging`.
2. The page renders shell-only; Alpine's `init()` then fetches the conversation
   from `GET .../messages`. The controller deliberately does **not** eager-load
   messages for the view.
3. Two timers start: messages every **3 seconds**, presence every **4 seconds**.
4. Sending POSTs to `.../messages`. The response carries the created message under
   its own `created_message` key so the client can append it immediately; polling
   still reconciles from `index()`.
5. Typing state is pushed to `.../typing` and read back through the presence poll.
6. Read receipts are written by `.../messages/read`.

## 5. Routes

| Method | URI | Name |
|---|---|---|
| GET | `/consultation-sessions/{session}/messaging` | `consultations.messaging.show` |
| GET | `/consultation-sessions/{session}/messages` | `consultations.messaging.index` |
| POST | `/consultation-sessions/{session}/messages` | `consultations.messaging.store` |
| POST | `/consultation-sessions/{session}/messages/read` | `consultations.messaging.read` |
| GET | `/consultation-sessions/unread-counts` | `consultations.messaging.unread_counts` |
| POST | `/consultation-sessions/{session}/typing` | `consultations.messaging.typing` |
| GET | `/consultation-sessions/{session}/presence` | `consultations.messaging.presence` |
| POST | `/consultation-sessions/{session}/offline` | `consultations.messaging.offline` |

All are CSRF-protected; only the global `/presence/heartbeat` is exempt.

## 6. Controllers

`ConsultationMessageController::show`, `index`, `store`, `markRead`,
`unreadCounts`, `typing`, `presence`, `markOffline`, plus the private
`serializeMessage`, `notifyMessageRecipients`, `setTyping`, `touchLastSeen`,
`typingKey`, `lastSeenKey`.

## 7. Services

`NotificationService::send()` only. There is no messaging service — the controller
writes `consultation_messages` directly.

## 8. Models

`App\Models\Message` — table **`consultation_messages`**, PK **`message_id`**.
Relations: `consultation()` (to `ConsultationSession`), `sender()`, `attachments()`.

Note the naming trap: the model is `Message`, the table is `consultation_messages`,
and its foreign key is `consultation_id` pointing at `consultations.id` — the
**session**, not the request.

## 9. Database

**Table `consultation_messages`** (`2026_07_20_110506`):

| Column | Type | Notes |
|---|---|---|
| `message_id` | bigint | **PK** |
| `consultation_id` | FK → `consultations.id` | cascade on delete |
| `sender_id` | FK → `users.user_id` | cascade on delete |
| `message` | text, **nullable** | null when the message is attachments-only |
| `read_at` | dateTime, nullable | |
| `created_at`, `updated_at` | | |

There is **no index on `consultation_id`** beyond the foreign key's implicit one,
and none on `read_at` — relevant to the unread-count query.

**Cache** (not the database) holds two ephemeral keys:

| Key | TTL | Purpose |
|---|---|---|
| `consultation:{sessionId}:typing:{userId}` | 8 s (`TYPING_TTL_SECONDS`) | typing indicator |
| `consultation:{sessionId}:last_seen:{userId}` | 24 h | per-session last-seen |

The project's cache driver is the `cache` database table
(`0001_01_01_000001_create_cache_table.php`), so these are database-backed but not
part of the domain schema.

## 10. Validation and Authorization

Authorization is by **registered policy**, one of only two in the system:

```php
public function viewMessaging(User $user, ConsultationSession $session): bool
{
    // owning patient OR assigned physician
    // AND session status in ['active','completed']
    // AND request status in ['active','completed']
}

public function sendMessage(User $user, ConsultationSession $session): bool
{
    return $this->viewMessaging($user, $session) && $session->consultation_status === 'active';
}
```

`show`, `index`, `markRead`, `typing`, `presence`, `markOffline` authorize
`viewMessaging`; `store` authorizes `sendMessage`. So a **completed** consultation
remains readable but not writable — a deliberate read-only archive.

`unreadCounts` takes no session and **authorizes nothing**; it scopes by role in
the query instead (patient → own active requests, physician → own active sessions).

**Validation** on `store`: `message` nullable string max 2000; `attachments` array
max 3; per-file rules in `attachments-and-prescriptions.md`. A message with neither
body nor attachment is refused with 422 *"Provide a message or at least one
attachment."*

`typing` validates `is_typing` as a required boolean.

## 11. Business Rules

| # | Rule | Enforced by | Enforcement type |
|---|---|---|---|
| BR-1 | Only the owning patient and assigned physician may view | `ConsultationSessionPolicy::viewMessaging` | **Application (policy)** |
| BR-2 | Nurses are excluded from messaging entirely | Same policy — nurse matches neither branch | **Application (policy)** |
| BR-3 | Messages may be sent only while `active` | `sendMessage` | **Application (policy)** |
| BR-4 | A completed consultation stays readable | `viewMessaging` admits `completed` | **Application (policy)** |
| BR-5 | Both the session and the request must be in an accessible state | The policy checks **both** statuses | **Application (policy)** |
| BR-6 | A message must carry a body or an attachment | Explicit check in `store` | **Application** |
| BR-7 | Read receipts never mark your own messages | `where('sender_id', '!=', $currentUserId)` | **Application** |
| BR-8 | The typing flag expires on its own | 8-second cache TTL | **Application (cache TTL)** |
| BR-9 | Sending clears your own typing flag | `setTyping(..., false)` in `store` | **Application** |
| BR-10 | A message's wire shape is identical from `store` and `index` | Both call `serializeMessage()` | **Application** |

Every rule here is application-level. The only database participation is the
cascade deletes on the two foreign keys.

## 12. Concurrency

**None.** No endpoint in this feature opens a transaction or takes a lock, and none
is needed: messages are append-only inserts, and `markRead` is a single idempotent
bulk `UPDATE ... WHERE read_at IS NULL AND sender_id != me`.

The one shared-state write is `touchLastSeen()`, called from six endpoints, which
writes a cache key and updates `users.online_status` / `last_seen_at` directly
through the query builder — the same bypass-Eloquent pattern as
`TrackUserPresence`.

## 13. Status / State Transitions

Messaging changes no workflow status. Its availability is **derived** from the
session and request statuses:

| Session status | Request status | View | Send |
|---|---|---|---|
| `active` | `active` | yes | yes |
| `completed` | `completed` | yes | no |
| `scheduled` | any | no | no |
| anything else | anything else | no | no |

*Table X.4 — Messaging access derived from consultation state.*

A message's own `read_at` is the only state this feature owns: null → a timestamp,
one way, never cleared.

## 14. Error and Edge-Case Handling

| Case | Response | Evidence |
|---|---|---|
| Nurse or unrelated user opens messaging | 403 | Policy; test *"still refuses presence to a nurse"* |
| Sending after completion | 403 | `sendMessage` requires `active` |
| Empty message and no files | 422 | `store` |
| Over 3 attachments | 422 with a specific message | `attachments.max` custom message |
| Session has no peer resolved | Presence returns nulls; notification is skipped | `if (!$recipientUser) return;` |
| Peer offline | `is_online: false` using the 2-minute freshness rule | `presence()` |
| Completed session with a stale open video row | `video.active` reports **false** | Gated on `consultation_status === 'active'`; test *"reports video inactive for a completed consultation even with a stale open row"* |
| No active sessions for the user | `unreadCounts` short-circuits with empty counts | `if (empty($sessionIds))` |

## 15. UI Implementation

`resources/views/consultations/messaging.blade.php` — **1,702 lines**, one Alpine
component `consultationMessaging()` with `x-init="init()"`.

**Four tabs**, asserted by test (*"renders all three consultation tabs"* plus the
assessment tab): `messages`, `details`, `patient`, `assessment`. The assessment tab
additionally sets `assessmentTabOpened = true` when first clicked.

**Polling** is explicit in `init()`:

```js
this.poller         = setInterval(() => this.fetchMessages(false), 3000);
this.presencePoller = setInterval(() => this.fetchPresence(),      4000);
```

Three seconds for messages, four for presence. **This is the entire real-time
mechanism.** There is no websocket, no server-sent events, and no broadcast driver
configured.

Other verified UI behaviour:

- The scroll container keeps a stable `id="messagesContainer"` that the auto-scroll
  looks up — asserted by test.
- A pending file can be removed before sending and is previewed as an image where
  applicable.
- Sent image attachments preview inline in the bubble through a shared popup; a
  video attachment opens in that popup **rather than navigating to it**.
- `@keydown.escape.window` closes the attachment preview.
- The page header names **the other participant, not the viewer**.
- The mobile floating action button is hidden on this page for both participants.
- Once the consultation is completed the composer is replaced by a read-only notice.

## 16. Tests

| File | Cases | Covers |
|---|---|---|
| `tests/Feature/ConsultationMessagingUiTest.php` | 11 | Composer bindings, pending-file removal and preview, inline image preview, video-in-popup, scroll container id, prescription popup gates, prescription cancel, all tabs, header participant, mobile FAB hidden, read-only composer after completion |
| `tests/Feature/ConsultationMessagePerformanceTest.php` | 7 | `created_message` shape identical to `index()`, attachments with working download URLs, private-only caching, refusals for outsiders and guests, empty-message rejection, Cloudinary timeout bound |
| `tests/Feature/ConsultationVideoPresenceTest.php` | 9 | Presence payload shape and video boolean, nurse refusal, per-session isolation, *"leaves the existing peer payload shape and values unchanged"* |

**Coverage gaps:** no test asserts the typing indicator's 8-second expiry, the
`markRead` semantics (BR-7), or the `unreadCounts` role scoping.

## 17. Source-Code Evidence

| Claim | Evidence |
|---|---|
| Polling intervals | `messaging.blade.php`, `setInterval(..., 3000)` and `setInterval(..., 4000)` |
| Messages not eager-loaded for the view | `show()`'s comment and its `$session->load([...])` list omitting `messages` |
| One wire shape | `serializeMessage()` docblock: *"index() and store() both use it so a message the client appends after sending is byte-identical"* |
| `created_message` key rationale | The comment above the key in `store()`'s response |
| Typing TTL | `TYPING_TTL_SECONDS = 8`, used in `setTyping()` |
| Cache key shapes | `typingKey()` / `lastSeenKey()` |
| Presence exposes only a boolean for video | The comment in `presence()`: *"no room_name, jwt, domain, or any other Jitsi identifier belongs on a passive polling endpoint"* |
| Read receipts exclude your own | `->where('sender_id', '!=', $currentUserId)` in `markRead` |
| Policy gates send vs view | `ConsultationSessionPolicy::sendMessage` calling `viewMessaging` then requiring `active` |
| Presence freshness | `$peerUser->last_seen_at->gt(now()->subMinutes(2))` |

## 18. Limitations / Gaps

| # | Limitation |
|---|---|
| CM-1 | **Polled, not pushed.** A 3-second message poll per open page means each participant issues ~1,200 requests per hour of consultation, each re-serialising the **entire** conversation — `index()` has no pagination, no `since` parameter, and no limit. A long consultation returns every message on every poll. |
| **CM-2** | **`unreadCounts` authorizes nothing, and its scoping collapses for other roles.** It is the only messaging endpoint with no `authorize()` call, relying entirely on the role branches inside its `where` closure. Those branches cover `patient` and `physician` only. For any other role — nurse or admin — neither branch executes, the closure adds no constraint, and Laravel compiles the empty nested group away entirely. Verified directly: building the same query with no branch taken produces `select * from \`consultations\` where \`consultation_status\` = ?` with a single binding. The endpoint therefore returns unread counts keyed by session id across **every active consultation session in the system**, leaking the existence, identifiers, and message volume of consultations the caller has no part in. Both other roles are authenticated and verified, so the route's middleware does not stop them. No test covers this path. |
| CM-3 | **No index on `consultation_messages.read_at`.** The unread query filters on `read_at IS NULL` plus `sender_id != ?` across every active session, with no supporting index. |
| CM-4 | **`markOffline` writes global presence from a session endpoint.** It sets `users.online_status = 'offline'` for the caller, affecting every other feature that reads presence — including intake availability. A patient closing a messaging tab marks themselves globally offline. |
| CM-5 | **Typing and last-seen live in the cache, not the database.** Clearing the cache silently resets both. Last-seen carries a 24-hour TTL, so a returning participant's "last seen" can vanish rather than age. |
| CM-6 | **The notification is per-send, not debounced.** `notifyMessageRecipients()` calls `NotificationService::send()` — **not** `sendUnique()` — so every message produces a fresh notification row. A rapid exchange generates one notification per message. |
| CM-7 | **A 1,702-line Blade file.** The messaging screen holds the chat, three other tabs, clinical documentation, prescription upload, attachment previews, and the entire video client in a single Alpine component. It is the largest single view in the project and the hardest to change safely. |
| CM-8 | **Untested behaviours.** Typing expiry, read-receipt semantics, and unread-count scoping have no tests, despite the last being where CM-2 lives. |

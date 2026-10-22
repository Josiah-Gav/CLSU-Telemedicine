# Telemed ↔ CHIS demo in Postman

Triggers a CHIS sync from Telemed's API and shows CHIS's acknowledgement.

## Before you start

- Telemed running on `http://localhost:8000` (`php artisan serve`) and the CHIS simulator on `http://localhost:8001`.
- Migrations run (`php artisan migrate`, which creates `personal_access_tokens`).
- At least one completed consultation of a patient with a CLSU ID, so it has a sync status (encounter `1` by default).

## 1. Create API tokens

Tokens belong to an existing, active **admin** and carry explicit abilities (`encounters:read`, `encounters:sync`). There are no all-powerful tokens.

```
php artisan telemed:api-token admin@clsu.edu.ph --name=postman --ability=encounters:read --ability=encounters:sync
php artisan telemed:api-token admin@clsu.edu.ph --name=postman-readonly --ability=encounters:read
```

The token is printed **once**; copy it right away. It cannot be shown again.

**Expiry:** tokens last 30 days by default. Use `--days=` (1–365) for another lifetime. After it expires the API answers `401`, so just create a new token.

Other commands: `php artisan telemed:api-token --list` (never shows tokens) and `php artisan telemed:api-token --revoke=<id>`.

## 2. Import and configure

1. Import `Telemed-CHIS-Demo.postman_collection.json` and `Telemed-CHIS-Local.postman_environment.json`.
2. Select the **Telemed - CHIS local** environment.
3. Paste the full-access token into `telemed_token` and the read-only token into `telemed_read_token`.
4. `chis_token` must equal `CHIS_API_TOKEN` in the simulator's `.env` (the default is for local development only). Set `encounter_id` if you are not using `1`.

## 3. Run the demo (in order)

| # | Request | Expect |
|---|---|---|
| 1 | Telemed - list encounters | 200, sync status only (no names, CLSU IDs or clinical data) |
| 2 | Telemed - get encounter | 200 |
| 3 | Telemed - sync encounter to CHIS | 200, `synced`, `chis_http_status` 201 (first delivery) or 200, a `chis_reference` |
| 4 | CHIS - get that encounter | 200, the encounter as CHIS received it |
| 5 | Telemed - sync again | 200, **same reference**, `chis_http_status` 200 (idempotent) |
| 6 | Auth checks | no token → 401 `UNAUTHENTICATED`; read-only token on sync → 403 `FORBIDDEN` |

If CHIS is down, request 3 still returns 200 with `chis_sync_status: "pending"` and an error text; Telemed retries automatically. A consultation that was skipped (the patient has no CLSU ID, or it was skipped under the earlier consent rule) answers `409 NOT_ELIGIBLE`.

Every API-triggered push is recorded in the CHIS audit log (admin page `/admin/chis-audit`) with source "API request" and the token's owner.

## Revoking

`php artisan telemed:api-token --list`, then `--revoke=<id>`.

# Tres Puntos — Proposal API (Agent Instructions)

## Base URL
```
https://doc.trespuntos-lab.com/api/proposals.php
```

## Authentication
Every request requires the header:
```
Authorization: Bearer tp_f06125ce7729d6b8dde738b7fb1a43cd27492aee332a325cb504bb27a73315e7
```

## Quick Access Test
Run this to verify you have access:
```bash
curl -s -H "Authorization: Bearer tp_f06125ce7729d6b8dde738b7fb1a43cd27492aee332a325cb504bb27a73315e7" \
  "https://doc.trespuntos-lab.com/api/proposals.php?action=schema"
```
Expected: JSON with `{"success": true, "data": {...}}`. If you get 401, the token is wrong. If you get 404, the API is not deployed.

---

## Endpoints

### 1. List all proposals
```
GET /api/proposals.php
```
Returns: array of proposals with id, slug, client_name, status, version, views_count, approvals.

### 2. Get proposal detail
```
GET /api/proposals.php?id={id}
```
Returns: full proposal including html_content, team members, approvals, and feedback.

### 3. Get version history
```
GET /api/proposals.php?id={id}&history=1
```
Returns: array of previous versions with timestamps.

### 4. Create proposal
```
POST /api/proposals.php
Content-Type: application/json

{
  "slug": "client-project-name",
  "client_name": "Client Name",
  "pin": "1234",
  "html_content": "<h1>Document content</h1>",
  "version": "v1.0",
  "sent_date": "2026-04-07",
  "equipo_ids": [1, 2]
}
```
Required fields: `slug`, `client_name`, `pin`, `html_content`.
Optional fields: `version` (default "v1.0"), `sent_date`, `equipo_ids`.

The slug is auto-sanitized and auto-incremented if duplicated.

### 5. Update proposal (draft — no history saved)
```
PUT /api/proposals.php?id={id}
Content-Type: application/json

{
  "html_content": "<h1>Updated content</h1>"
}
```
Only send the fields you want to change. This does NOT save the previous version — use it for intermediate adjustments (text fixes, CSS tweaks, etc).

### 5b. Update proposal AND save previous version to history
```
PUT /api/proposals.php?id={id}
Content-Type: application/json

{
  "html_content": "<h1>Completely new version</h1>",
  "version": "v2.0",
  "save_version": true
}
```
When `save_version` is true, the current document is archived in history BEFORE being overwritten. Use this ONLY when the document is finalized and you want to create an official new version (v1.1, v2.0, etc).

### 6. Restore a previous version
```
POST /api/proposals.php?id={id}&action=restore
Content-Type: application/json

{"history_id": 3}
```
Or by version label:
```json
{"version": "v1.0"}
```
This saves the current version to history BEFORE restoring, so nothing is ever lost. Use `GET ?id={id}&history=1` first to see available versions.

### 7. List team members
```
GET /api/proposals.php?action=team
```
Returns: available team members with id, nombre, cargo. Use their IDs in `equipo_ids` when creating/updating proposals.

### 8. Get API schema
```
GET /api/proposals.php?action=schema
```
Returns: full field definitions, types, required/optional, and examples.

---

## Important Notes
- **No DELETE**: The API does not support deleting proposals (safety measure).
- **Versioning**: PUT does NOT save history by default (for intermediate adjustments). Add `"save_version": true` to the body ONLY when you want to archive the current version and create a new official one.
- **PIN**: The 4-digit PIN is what clients use to access the proposal at `https://doc.trespuntos-lab.com/p/{slug}`.
- **HTML Content**: The `html_content` field contains the full styled HTML of the functional document. Read an existing proposal first (`GET ?id=X`) to understand the expected format.
- **Telegram**: Creating a proposal triggers a Telegram notification to the team.

## Workflow for AI Agents
1. `GET ?action=schema` — Understand the API structure
2. `GET ?action=team` — See available team members
3. `GET /api/proposals.php` — List existing proposals to understand naming patterns
4. `GET ?id={id}` — Read an existing proposal to see the HTML format
5. `POST` — Create new proposal using the same HTML patterns
6. `PUT ?id={id}` — Update if needed

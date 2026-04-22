# MCP Tools — Feedback loop (spec para ampliar `mcp-proposals.vercel.app`)

Este documento describe las tools MCP nuevas que hay que añadir al proyecto `mcp-proposals` (Vercel, equipo `tres-puntos-projects`) para que Claude.ai y Jordan puedan gestionar el loop de comentarios.

Todas proxy-ean al REST API ya implementado en `api/proposals.php` con `Authorization: Bearer {API_TOKEN}`.

Base: `https://doc.trespuntos-lab.com/api/proposals.php`

---

## 1. `list_comments`

Lista los hilos de comentarios de una propuesta.

**Params:**
- `propuesta_id` (integer, required)
- `status` (enum: `open` | `closed` | `all`, default `all`)
- `include_drafts` (boolean, default `false`) — si es `true`, incluye borradores staff (normalmente el cliente no los ve)

**HTTP:** `GET /api/proposals.php?id={propuesta_id}&action=comments&status={status}&include_drafts={0|1}`

**Returns:** `{ propuesta_id, total, threads: [ { id, section_anchor, section_title, autor_nombre, autor_apellidos, texto, resuelto, resuelto_por, resuelto_at, created_at, replies: [...] } ] }`

---

## 2. `get_comment_thread`

Obtiene un hilo concreto (raíz + replies).

**Params:** `comment_id` (integer, required — id del comentario raíz).

**HTTP:** `GET /api/proposals.php?id={comment_id}&action=thread`

---

## 3. `reply_to_comment`

Responde a un comentario del cliente. Por defecto crea un **borrador** que el humano revisa antes de publicar; si `publish=true`, publica directamente.

**Params:**
- `comment_id` (integer, required) — id del comentario raíz al que respondemos
- `texto` (string, required, max 4000)
- `publish` (boolean, default `false`) — si `true`, salta el paso de borrador

**HTTP:**
- Draft: `POST /api/proposals.php?id={comment_id}&action=reply_draft` · Body: `{"texto":"…"}`
- Publish directo: `POST /api/proposals.php?id={comment_id}&action=reply_publish` · Body: `{"texto":"…"}`

**Cuándo usar:** después de `list_comments(status=open)`, para redactar respuestas sin enviar aún. El humano (Jordi) publica desde admin o vía `publish_reply`.

---

## 4. `publish_reply`

Publica un borrador. Opcionalmente edita el texto antes de publicar.

**Params:**
- `reply_id` (integer, required)
- `texto` (string, optional) — si lo pasas, sustituye al del borrador antes de publicar

**HTTP:** `POST /api/proposals.php?id={reply_id}&action=publish_reply` · Body: `{"texto":"… opcional"}`

---

## 5. `discard_reply`

Descarta un borrador (no publicado).

**Params:** `reply_id` (integer, required)

**HTTP:** `POST /api/proposals.php?id={reply_id}&action=discard_reply`

---

## 6. `resolve_comment`

Cierra (o reabre) un hilo raíz. Toggle — si está cerrado reabre, si está abierto cierra.

**Params:**
- `comment_id` (integer, required) — id del raíz
- `actor` (enum: `staff` | `author`, default `staff`) — quién lo cierra
- `actor_name` (string, optional) — nombre visible si actor=staff (default "Tres Puntos")

**HTTP:** `POST /api/proposals.php?id={comment_id}&action=resolve` · Body: `{"actor":"staff","actor_name":"Jordi"}`

**Regla**: el flujo normal es que **solo el autor** cierra desde la vista cliente. Esta tool permite al staff cerrar hilos abandonados. Úsalo con criterio.

---

## 7. `mark_notified`

Marca todas las respuestas staff publicadas de una propuesta como notificadas al cliente (se ha enviado el email).

**Params:** `propuesta_id` (integer, required)

**HTTP:** `POST /api/proposals.php?id={propuesta_id}&action=notify`

**Uso típico desde Claude.ai**:
1. `list_comments(propuesta_id=21, status="open")`
2. Redactar + `reply_to_comment` con `publish=false` para cada uno (borradores).
3. Jordi revisa en admin, edita, publica.
4. Jordi (o Claude con permiso): usa Gmail MCP `create_draft` → envía email con link a la propuesta.
5. `mark_notified(propuesta_id=21)` para limpiar la bandeja.

---

## Flujo recomendado en Claude.ai

```
Jordi: "Lee los comentarios abiertos de H2B"
Claude: call list_comments(propuesta_id=21, status="open")
Claude: [redacta borradores siguiendo brand voice + project context]
Jordi: "redacta los 8 como borradores"
Claude: call reply_to_comment(comment_id=X, texto="…") × 8 con publish=false
Jordi: [revisa en admin_feedback.php, edita, publica]
Jordi: "avisa a Eloi por email"
Claude: call (Gmail MCP) create_draft(to="eloi@…", cc="jordi@trespuntoscomunicacion.es", …)
Jordi: [abre Gmail, revisa, envía]
Jordi: "marca como notificado"
Claude: call mark_notified(propuesta_id=21)
```

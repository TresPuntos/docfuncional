# Tres Puntos - Proposal Management System (docfuncional)

> **Estado actual del proyecto y próximos pasos** → ver [`PLAN.md`](PLAN.md) en la raíz. Léelo al empezar sesión para entender qué está desplegado, qué cliente trabajamos y qué viene después.

## 🔁 REGLA — Git después de cada deploy

**Siempre que se haga un deploy a producción, inmediatamente después hay que:**

1. `git add -A` de todos los cambios pushed a live.
2. `git commit -m "<mensaje descriptivo>"` con co-autoría Claude.
3. `git push origin main` al repo `https://github.com/trespuntoslab/documento-funcional-.git`.

**Regla de ramas**: push directo a `main` por defecto (equipo de uno, no merece fricción de PRs).
Crear rama `feat/<nombre>` solo para cambios grandes o experimentales que quieras poder revertir sin tocar lo que está live. En ese caso: `git checkout -b feat/X` → commit → push rama → mergear cuando estable.

**Nunca hacer push sin haber hecho deploy correspondiente** — el repo representa lo que está en prod. Si algo está en local pero no en live, es work-in-progress (commit pero no push, o rama de feature).

**Credenciales**: `osxkeychain` guarda el PAT tras el primer uso, futuros pushes no requieren input. Si se rota el token, volver a pushear una vez para que el keychain lo actualice.

---

## ⛔ REGLA CRÍTICA — No deploy sin aprobación explícita

**NUNCA desplegar a producción sin autorización directa y explícita del usuario en el turno actual.** Esto cubre:

- ❌ Upload por FTP a `ftp.trespuntos-lab.com` (ruta `/doc/` o cualquier otra)
- ❌ `vercel deploy --prod` o cualquier deploy al proyecto `mcp-proposals` de Vercel
- ❌ `git push` a ramas que disparen auto-deploy (cuando se conecte GH→Vercel)
- ❌ Modificar directamente la BD de prod (`database/database.sqlite` en Hostinger) vía endpoint temporal o SQL
- ❌ Ejecutar migraciones en prod
- ❌ Cualquier acción que afecte a `doc.trespuntos-lab.com` o `mcp-proposals.vercel.app`

### Flujo correcto

1. Implementar en **local** (`/Users/jordi/Library/CloudStorage/Dropbox/.../documentos_funcionales_trespuntos/`).
2. Probar localmente con `php -S localhost:8000 router.php`.
3. **Mostrar al usuario qué cambia** y qué archivos se subirían.
4. **Esperar un "sube", "adelante", "deploy", "ok suba"** u otra confirmación **explícita** referida al deploy.
5. Solo entonces: FTP upload, migración en prod, Vercel deploy, etc.

### Excepciones permitidas sin confirmación

- Lectura (GET, download de archivos para backup/inspección).
- Ejecución de scripts diagnóstico temporales **en local únicamente**.
- Consultas a la API REST de prod con Bearer token (solo lectura, nunca mutaciones).

**Si dudas, pregunta antes de subir. Un "lo he subido a prod" sin permiso es peor que un "¿lo subo?"**

---

## 🏗️ Portal proveedores · LOCAL (2026-04-23) — pendiente deploy

Sistema completo en local, nada subido a prod aún por regla. Cuando el usuario diga *"sube"*, deploy de todo junto.

### Flujo

- **Admin invita proveedor** desde `admin_providers.php?propuesta_id=X` → genera token (32 hex) + PIN (4 dígitos) + (opcional) email automático vía Resend con firma Jordan.
- **Proveedor entra** por `/s/{token}` → PIN gate → redirige a `/p/{slug}?__provider={token}` (vive como "variante" de `view.php`, shell cliente completo).
- **En la vista**: sidebar con nav jerárquica + documento funcional shared con cliente + panel upload PDF (importe + plazo + notas, múltiples versiones) + comentarios/mensajes con mismo drawer ancho + modal central + email obligatorio que el cliente.
- **Admin responde como Tres Puntos** desde cualquier hilo (comentario cliente o mensaje proveedor) vía `/p/{slug}?__admin_view=1` o `/p/{slug}?__provider={token}&__admin_view=1`. Banner púrpura sticky indica modo admin.
- **Proveedor NO ve**: tabs presupuesto/firmas, CTA aprobar cliente, Jordan widget, tracking analytics, ni ningún otro proveedor.

### Modelo de datos

- `propuesta_proveedores` — token + pin + nombre + empresa + email + flag ver_comentarios + activo + accesos + last_accessed_at
- `proveedor_presupuestos` — archivo_path + importe_total + plazo_dias + notas + version_num (historial de versiones)
- `proveedor_mensajes` — texto + parent_id (hilos) + autor_tipo (proveedor|staff) + is_draft
- Archivos PDF en `uploads/proveedores/{propuesta_id}/{uuid}.pdf` con `.htaccess Deny all`, download solo vía `admin_providers.php?download=X` con sesión admin.

### Archivos clave

| Archivo | Rol |
|---|---|
| `provider.php` | Entry point `/s/{token}` · PIN gate + endpoints AJAX + redirect |
| `admin_providers.php` | Admin listado + detalle por proveedor (`?proveedor_id=X`) con KPIs + presupuestos + mensajes |
| `master/doc-feedback-provider.php` | Drawer/modal/botones inline equivalentes al cliente, contra `proveedor_mensajes` |
| `master/provider-upload.php` | Panel upload PDF inyectado en view.php cuando `$isProviderMode` |
| `database/migrate_providers.php` | Migración idempotente 3 tablas + carpeta uploads + htaccess |

### Privacidad verificada

Todas las queries del proveedor llevan `WHERE proveedor_id = $provider['id']` — solo ve sus mensajes y presupuestos. No existe ninguna query que liste o revele otros proveedores en su vista.

---

## 🎨 Shell admin unificado · LOCAL (2026-04-23)

Rediseño completo de la navegación admin aplicando design system Linear-like (densidad + jerarquía tipográfica + Lucide icons everywhere).

- **`master/admin-sidebar.php`** · sidebar 272px compartido en `admin.php` · `admin_feedback.php` · `admin_analytics.php` · `admin_providers.php` (listado + detalle).
- Estructura accordion: lista de propuestas activas, cada una expandible con sub-items en 3 grupos (**Cliente** · **Proveedores** · **Documento**). Cada proveedor listado individualmente con avatar+empresa, click lleva al detalle.
- Badges **dot + número tabular** (estilo Linear · mint para comentarios, púrpura para proveedores).
- Estado activo con franja lateral 2px mint (no fill).
- Toggle "Mostrar archivadas" con persistencia en `sessionStorage`.
- Propuesta actual auto-expandida, sub-item activo marcado.
- **0 emojis** en output HTML admin (todos iconos Lucide). Emojis solo en strings de notificación Telegram (se renderizan en móvil).

### ⚠️ Bugs conocidos arreglados durante el sprint

- `$pv` shadowing del sidebar sobre vista detalle (foreach del sidebar reusaba variable). Fix: prefijo `$__sidebar*` en variables internas del include.
- `e()` duplicada en admin_providers.php (doble declaración entre vista list y detail). Fix: wrap en `function_exists`.
- Botón submit invisible en doc-feedback-provider por `var(--mint)` no definido en shell view.php. Fix: `:root { --mint: var(--tp-primary, #5dffbf) }` override en el módulo.
- "Rellena este campo" bloqueando submit con email presente: causa eran inputs nombre/apellidos ocultos pero con `required`. Fix: toggle dinámico de `required` según identidad compacta.

---

## 📦 Sistema de feedback · qué hay en producción (2026-04-22)

Sprint completo desplegado en https://doc.trespuntos-lab.com. Flujo end-to-end:

### Loop de feedback cliente ↔ Tres Puntos

- **Cliente comenta** en `/p/{slug}` por sección (H2). Email obligatorio, se persiste en `localStorage` (`tp_signer`).
- **Admin responde** desde `/admin_feedback.php?propuesta_id=X` — borradores opcionales (Claude.ai vía MCP) con botones Publicar / Editar / Descartar. Solo el **autor del comentario** cierra su propio hilo (regla de diseño).
- **Aviso por email** vía **Resend** con firma oficial Jordan (plantilla con disclaimer legal).
- **Botón "📢 Avisar nueva versión"** en el banner verde cuando todos los hilos están resueltos — email con la v1.x lista + bullets de cambios editables + CTA a revisar + invitación a pasar al presupuesto.

### Analítica y señales de cierre

- Tabla `propuesta_eventos` captura: `open`, `close`, `section_view`, `section_dwell`, `scroll_depth_*`, `presupuesto_open`, `firma_open/abandoned/approved`.
- **Dashboard admin** (`/admin.php`) muestra badges calientes por propuesta: `🔴 En vivo`, `🔥 N× hoy`, `💰 Vio precio`, `⚠️ Intentó firmar`, `❄️ Nd sin abrir`, `💬 N`, `✏️ N`, `✉ N`.
- **Vista analytics** (`/admin_analytics.php?propuesta_id=X`) con KPIs + mapa de calor vertical por sección + drill-down cronológico por sesión con identidad cruzada (si el visitante comentó, aparece su nombre).
- **Alertas Telegram** automáticas en hitos (`presupuesto_open`, `firma_abandoned`).

### UX mejoras (feedback Eloi H2B, 2026-04-22)

- **Drawer ancho (820px)** para explorar/navegar entre todos los hilos del doc (abierto con FAB global).
- **Modal central (720px)** para trabajar con un hilo concreto (abierto desde botón "Comentar" inline junto a cada H2). Más aire de lectura, `Esc` cierra, `Ctrl/Cmd+Enter` envía.
- **Filtro de hilos en admin**: `[Abiertos · Todos · Cerrados]` con persistencia en `sessionStorage` — por defecto solo abiertos.

### Archivos clave del sistema

| Archivo | Rol |
|---|---|
| `admin_feedback.php` | Bandeja de respuesta + endpoints staff reply / publish / notify / version-announcement |
| `admin_analytics.php` | Mapa de calor + drill-down sesiones |
| `api/track.php` | Ingesta eventos (whitelist en `api/.htaccess`) |
| `api/proposals.php` | REST API con Bearer auth · acciones `comments`, `thread`, `reply_draft`, `reply_publish`, `publish_reply`, `resolve`, `notify` |
| `master/doc-feedback.php` | UI cliente: drawer + modal + email obligatorio |
| `master/doc-tracking.php` | IntersectionObserver + beacon tracking |
| `database/migrate_*.php` | Migraciones idempotentes (staff replies, drafts, events) |

### MCP en Vercel (`mcp-proposals.vercel.app`)

- Python serverless con 15 tools (8 propuestas + 7 comentarios).
- Deploy vía CLI desde `/tmp/mcp-proposals/` (repo `github.com/trespuntoslab/mcp-proposals` todavía vacío, no conectado).
- Env vars en Vercel: `API_TOKEN` (Bearer del REST API).

### Config constants necesarias en `config.local.php` de prod

```php
define('RESEND_API_KEY', 're_...');
define('RESEND_FROM', 'Jordan · Tres Puntos <jordan@trespuntos-lab.com>');
define('RESEND_REPLY_TO', 'jordi@trespuntos-lab.com');
define('CLIENT_NOTIFY_CC', 'jordi@trespuntoscomunicacion.es');
```

Dominio `trespuntos-lab.com` verificado en Resend. Alias `jordan@trespuntos-lab.com` creado en Workspace.

### Agentes que usan este sistema

- **Jordan** — agente IA público cara-cliente (widget `/master/jordan-widget.php`, backend `/api/jordan-doc.php` con Haiku + cache de documento). Firma emails al cliente.
- **Claudio** — asistente IA interno (esta sesión Claude Code). No aparece en comunicación con clientes. Redacta borradores, hace deploys, mantiene el sistema.

### Estado H2B Hipotecas (cliente activo)

- Propuesta id=21, slug `h2bhipotecas`, versión v1.5 publicada el 2026-04-22.
- v1.4 archivada en `propuestas_history`.
- 12 hilos cerrados por Eloi + 12 respuestas staff notificadas.
- Siguiente paso comercial: presupuesto por fases.

---

## Knowledge Base — Notion (cerebro de Tres Puntos)
Notion is the central knowledge base for Tres Puntos. Connected via MCP (`@notionhq/notion-mcp-server`).
Use Notion tools (`notion-search`, `notion-fetch`, etc.) to look up strategy, processes, clients, and internal docs.

## Brand Source of Truth
This project follows the centralized Tres Puntos brand system.
**All agents MUST read these before generating content or UI:**

- [Brand Core](https://raw.githubusercontent.com/trespuntos-ia/trespuntos-context/main/brand/00-brand-core.md) — Identity, positioning, personality
- [Messaging](https://raw.githubusercontent.com/trespuntos-ia/trespuntos-context/main/brand/01-messaging-architecture.md) — Audience-specific messaging
- [Voice & Tone](https://raw.githubusercontent.com/trespuntos-ia/trespuntos-context/main/brand/02-voice-and-tone.md) — Channel-specific communication
- [Vocabulary](https://raw.githubusercontent.com/trespuntos-ia/trespuntos-context/main/brand/03-vocabulary.md) — Approved/prohibited terms
- [AI Instructions](https://raw.githubusercontent.com/trespuntos-ia/trespuntos-context/main/brand/04-ai-brand-instructions.md) — How AI agents should think
- [Design Tokens](https://raw.githubusercontent.com/trespuntos-ia/trespuntos-context/main/brand/05-design-tokens.md) — Colors, typography, CSS variables

## Project Overview
PHP-based proposal generation and approval system. Generates styled functional documents and budgets that clients review via PIN-protected URLs (`/p/{slug}`).

## Tech Stack
- **Backend:** PHP 8+ with SQLite (PDO)
- **Frontend:** Vanilla HTML/CSS/JS (no framework)
- **Icons:** Lucide Icons (CDN)
- **Fonts:** Google Fonts (Inter body, Plus Jakarta Sans headings)
- **Notifications:** Telegram Bot API

## Design Tokens (Quick Reference)
Full spec in the central repo. Canonical values from the web design system:

```css
:root {
  --mint: #5dffbf;                 /* Brand accent */
  --mint-hover: #49e6a8;
  --mint-rgb: 93, 255, 191;
  --bg-base: #0e0e0e;             /* Page background */
  --bg-surface: #141414;          /* Cards, sidebar */
  --bg-subtle: #191919;           /* Inputs, nested surfaces */
  --bg-muted: #1f1f1f;            /* Elevated backgrounds */
  --text-primary: #f5f5f5;        /* Body text */
  --text-secondary: #b3b3b3;
  --text-muted: #8a8a8a;
  --text-inverse: #0e0e0e;
  --border-base: #1f1f1f;
  --border-subtle: #1a1a1a;
  --border-strong: #2a2a2a;
  --font-heading: 'Plus Jakarta Sans', sans-serif;
  --font-body: 'Inter', system-ui, sans-serif;
  --font-mono: 'JetBrains Mono', monospace;
  --radius-sm: 6px; --radius-md: 10px; --radius-lg: 14px;
  --radius-xl: 20px; --radius-2xl: 24px; --radius-full: 9999px;
}
```

> NOTE: This project uses legacy aliases (`--tp-primary` = `--mint`, `--text-heading` = white).
> New code should use the canonical token names above.

## Key Endpoints

**Client-facing**
- `GET /p/{slug}` — View proposal (requires PIN)
- `POST admin.php?action=approve_document` — Approve document (signature: name + surname + email + SHA256 hash)
- `POST admin.php?action=approve_presupuesto` — Approve budget
- `POST admin.php?action=add_section_comment` — Add section comment
- `POST admin.php?action=edit_section_comment` / `delete_section_comment` — Edit/delete own comments
- `GET  admin.php?action=list_section_comments&propuesta_id=X` — List comments for a proposal

**Admin-only (Holded integration)**
- `GET  admin.php?action=holded_search&q=...` — Autocomplete recent estimates
- `GET  admin.php?action=holded_preview&id=...` / `?number=E170380` — Preview before linking
- `POST admin.php?action=holded_link` — Link estimate to proposal (body: `{propuesta_id, holded_id, confirm_overwrite?}`)
- `POST admin.php?action=holded_sync` — Re-fetch latest JSON from Holded
- `POST admin.php?action=holded_unlink` — Unlink + archive history
- `POST admin.php?action=delete_pdf` — Remove legacy PDF attachment

**Public (design tokens)**
- `GET /api/tokens.php` — Design tokens as JSON
- `GET /api/tokens.php?format=css` — Design tokens as CSS

## Holded Integration

Links a Holded estimate (`salesestimate`) to a proposal. The linked estimate renders as a styled invoice in the client view, replacing the legacy PDF iframe. One estimate per proposal (1:1).

**Config** (`config.local.php`, not committed):
```php
define('HOLDED_API_KEY', '...');
define('HOLDED_API_BASE', 'https://api.holded.com/api/invoicing/v1');
```

**Database** (`database/migrate_holded.php`, idempotent):
- `presupuestos_holded` — 1:1 link (propuesta_id UNIQUE, holded_id, holded_doc_number, holded_json, synced_at)
- `presupuestos_holded_history` — archive on re-link/unlink

**Files**:
- `api/holded_client.php` — library: `holded_get_estimate($id)`, `holded_find_by_number($docNum)`, `holded_search_estimates($q)`, formatters
- `master/presupuesto-holded.php` — `tp-invoice` template (header TP + cliente + líneas + totales + notas + IBAN), dark/light/responsive

**View priority**: if `presupuestos_holded` row exists → render Holded template (hides legacy PDF). Else → PDF iframe. The legacy PDF field (`propuestas.presupuesto_pdf`) stays for backwards compatibility.

**Signature safety**: `holded_link` checks if the proposal already has a `aprobaciones.tipo='presupuesto'` row while `presupuesto_pdf` is set. If so, returns `needs_confirmation:true` with the signer's data. The admin UI shows a `confirm()` before retrying with `confirm_overwrite:true`. Rationale: the existing signature is over the PDF, not over this new Holded — linking without confirmation would display it as "approved" deceptively.

## Client Viewer UI (`view.php`)

**Tabs system** (only shown when there's a budget or signatures):
- `Documento` · `Presupuesto · E#####` · `Firmas · N`
- Sticky top bar, changes real view (not just scroll), persists in `sessionStorage`.
- Hash routing: `#presupuesto`, `#firmas`, or any anchor inside a view (e.g. `#sec-presupuesto-holded`) activates the parent tab and scrolls.
- Sidebar nav hides on tabs ≠ documento (only brand + theme toggle remain).

**Signatures section** (tab Firmas):
- Shows every row in `aprobaciones` with: type, signer name/email, timestamp, short SHA-256 hash (`ab12…cd34` with tooltip to full hash).
- Fed directly from the table, no schema changes.

**First-visit coachmark**:
- Popover anchored to the comments FAB, 1.4s after load.
- Persisted per proposal: `localStorage['tp-onb-comentarios-{slug}']`.
- Also dismissed on first FAB click.

**Comments system** (`master/doc-feedback.php`):
- FAB floating bottom-right opens drawer with full history.
- "Comentar" buttons inline next to each H2/H3.
- Stored in `comentarios_seccion`, Telegram notification on new/reply.

## REST API for AI Agents
Base URL: `https://doc.trespuntos-lab.com/api/proposals.php`
Auth: `Authorization: Bearer {API_TOKEN}` (token defined in config.php)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/proposals.php` | List all proposals |
| `GET` | `/api/proposals.php?id=X` | Get proposal detail (includes html_content) |
| `GET` | `/api/proposals.php?id=X&history=1` | Version history |
| `POST` | `/api/proposals.php` | Create proposal (slug, client_name, pin, html_content) |
| `PUT` | `/api/proposals.php?id=X` | Update proposal (draft, no history saved) |
| `PUT` | `/api/proposals.php?id=X` | Update + save version (add `"save_version": true` to body) |
| `POST` | `/api/proposals.php?id=X&action=restore` | Restore previous version (body: `{"history_id": X}` or `{"version": "v1.0"}`) |
| `GET` | `/api/proposals.php?action=team` | List team members |
| `GET` | `/api/proposals.php?action=schema` | API schema for agents |

Full agent instructions: `/api/API_AGENT_INSTRUCTIONS.md`

## MCP Server (for Claude.ai, Cowork, Desktop)
URL: `https://mcp-proposals.vercel.app/mcp`
Hosted on Vercel (project: `mcp-proposals` in tres-puntos-projects team).
Proxies requests to the REST API above. No auth required on the MCP side — the API token is stored as a Vercel env var.

Available MCP tools: `list_proposals`, `get_proposal`, `create_proposal`, `update_proposal`, `save_new_version`, `restore_version`, `get_history`, `get_team`

To connect in Claude.ai: Settings → Connectors → Add custom connector → paste MCP URL.

## Version History System
- `PUT` without `save_version` = draft update (overwrites, no history saved)
- `PUT` with `"save_version": true` = archives current version, then updates (for official versions v1.1, v2.0, etc)
- `restore_version` always saves the current version before restoring, so nothing is ever lost
- Admin panel has "Restaurar version anterior" dropdown when editing proposals with history

## File Structure
```
/config.php                       — DB connection, Telegram, API_TOKEN (defaults + defined-checks)
/config.local.php                 — Private overrides (NOT committed): ANTHROPIC_API_KEY, HOLDED_API_KEY, JORDAN_DOC_ENABLED
/admin.php                        — Admin panel + controller (proposals, approvals, comments, Holded endpoints)
/view.php                         — Client-facing proposal viewer (tabs, signatures, onboarding)
/router.php                       — URL routing for /p/{slug}
/metodologia.php                  — Methodology section template

/api/proposals.php                — REST API for AI agents (Bearer token auth)
/api/holded_client.php            — Holded API library (estimates, formatters)
/api/jordan-doc.php               — Jordan-doc AI assistant endpoint (Haiku, scoped per proposal)
/api/.htaccess                    — API directory protection
/api/API_AGENT_INSTRUCTIONS.md    — Agent instructions doc

/master/doc-library.css           — Reusable components (tp-card, tp-grid, tp-sitemap, tp-callout, …)
/master/doc-feedback.php          — Section comments: FAB + drawer + inline buttons
/master/presupuesto-holded.php    — Holded invoice template (tp-invoice)
/master/jordan-widget.php         — Jordan-doc UI widget
/master/                          — Other HTML templates and specs

/database/migrate_feedback.php    — Creates comentarios_seccion + aprobaciones signature cols
/database/migrate_jordan.php      — Creates jordan_conversaciones
/database/migrate_holded.php      — Creates presupuestos_holded + presupuestos_holded_history
/database/database.sqlite         — SQLite database (access blocked by .htaccess)

/mcp/index.php                    — MCP server (PHP, Hostinger — backup, blocked by WAF)
/design-tokens.json               — Local copy of design tokens
```

## Database Schema (key tables)
- `propuestas` — id, slug, client_name, pin, html_content, version, status, equipo_ids, presupuesto_pdf, enable_ai_assistant
- `aprobaciones` — propuesta_id, tipo (`documento_funcional`|`presupuesto`), firmante_nombre/apellidos/email, firma_hash, version_firmada, aprobado_at
- `comentarios_seccion` — propuesta_id, section_anchor, section_title, autor_*, texto, parent_id, resuelto
- `presupuestos_holded` — propuesta_id UNIQUE, holded_id, holded_doc_number, holded_json, synced_at
- `presupuestos_holded_history` — archive of previous links (accion: reemplazado / desvinculado)
- `propuestas_history` — version archive for rollback
- `equipo` — team members
- `jordan_conversaciones` — AI assistant chat log

## Coding Conventions
- All styles use CSS custom properties (`var(--token-name)`)
- Primary color on buttons uses black text for contrast (light theme: white)
- Transparency: `rgba(93, 255, 191, opacity)` for primary color
- Lucide icons via `<i data-lucide="icon-name"></i>`
- Partner mode tone for all proposal content (see Voice & Tone doc)
- Never use prohibited vocabulary (see Vocabulary doc)
- **Never hardcode colors** for text/bg — must respect dark/light themes via tokens. Hardcoded `#E0E0E0` etc. breaks light mode; always use `--text-primary`, `--bg-surface`, etc.
- When writing templates that live outside `view.php` (like `master/presupuesto-holded.php`), check that the CSS vars used are actually defined in `view.php`'s `:root` and `[data-theme="light"]`. Avoid `--bg-subtle` (not defined in view.php) — use `--bg-nav-hover` or `--bg-surface`.

## Deployment
Production lives at `https://doc.trespuntos-lab.com/` on Hostinger (path `/doc/`).
- **Code**: push to GitHub `main`, then upload changed files via FTP (`ftp.trespuntos-lab.com`, user `u296656791.claude3`).
- **Migrations**: scripts in `database/` are blocked by `.htaccess` — upload temporarily to `/doc/` root, run via HTTPS, then delete. See session history for the pattern.
- **Config**: `config.local.php` is NOT in git. After pulling on a new env, copy from `config.local.php.example` and fill secrets.
- Before linking Holded on a proposal with existing PDF + signature, the system warns (see Holded Integration → Signature safety). Heed it.

# Tres Puntos - Proposal Management System (docfuncional)

> **Estado actual del proyecto y próximos pasos** → ver [`PLAN.md`](PLAN.md) en la raíz. Léelo al empezar sesión para entender qué está desplegado, qué cliente trabajamos y qué viene después.

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
- `GET /p/{slug}` — View proposal (requires PIN)
- `POST admin.php?action=approve_document` — Approve document
- `POST admin.php?action=approve_presupuesto` — Approve budget
- `GET /api/tokens.php` — Design tokens as JSON (for other apps)
- `GET /api/tokens.php?format=css` — Design tokens as CSS

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
/config.php          — DB connection, Telegram config, API_TOKEN
/admin.php           — Admin panel + API controller
/view.php            — Client-facing proposal viewer
/router.php          — URL routing for /p/{slug}
/metodologia.php     — Methodology section template
/design-tokens.json  — Local copy of design tokens
/api/proposals.php   — REST API for AI agents (Bearer token auth)
/api/.htaccess       — API directory protection
/api/API_AGENT_INSTRUCTIONS.md — Agent instructions doc
/mcp/index.php       — MCP server (PHP, Hostinger — backup, blocked by WAF)
/database/           — SQLite database (protected)
/master/             — HTML templates and specs
```

## Coding Conventions
- All styles use CSS custom properties (`var(--token-name)`)
- Primary color on buttons uses black text for contrast
- Transparency: `rgba(93, 255, 191, opacity)` for primary color
- Lucide icons via `<i data-lucide="icon-name"></i>`
- Partner mode tone for all proposal content (see Voice & Tone doc)
- Never use prohibited vocabulary (see Vocabulary doc)

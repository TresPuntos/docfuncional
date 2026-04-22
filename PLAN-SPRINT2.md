# Sprint 2 · Mapa de calor + Analítica de cierre

**Objetivo comercial**: dar a Tres Puntos munición para cerrar la propuesta. Ver cuándo el cliente abre, qué lee, qué ignora, si llega al precio, si intenta firmar. La métrica final es **tiempo-hasta-firma**.

**Principio guía**: analítica agregada por sección, **no** grabación de sesión ni mouse tracking. GDPR-compliant sin fricción.

---

## Fase 1 · Captura de eventos (base técnica)

### T8 · Tabla `propuesta_eventos`

```sql
CREATE TABLE propuesta_eventos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    propuesta_id INTEGER NOT NULL,
    sesion_id TEXT NOT NULL,        -- uuid v4 generado en sessionStorage del cliente
    visitor_hash TEXT,              -- sha256(ip + user_agent + slug) — dedupe sin identificar
    tipo TEXT NOT NULL,             -- enum: ver abajo
    section_anchor TEXT,
    dwell_ms INTEGER,
    scroll_depth INTEGER,           -- 0-100
    meta TEXT,                      -- json opcional (target click, etc.)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE CASCADE
);
CREATE INDEX idx_eventos_prop_ts ON propuesta_eventos(propuesta_id, created_at DESC);
CREATE INDEX idx_eventos_sesion ON propuesta_eventos(sesion_id);
CREATE INDEX idx_eventos_visitor ON propuesta_eventos(propuesta_id, visitor_hash);
```

### Tipos de evento capturados

| `tipo` | Cuándo | Payload extra |
|---|---|---|
| `open` | Al pasar el PIN y cargar `view.php` | `scroll_depth=0` |
| `close` | `beforeunload` o tab-hide >30s | `scroll_depth` final |
| `section_view` | Una sección H2 entra al viewport (>50% visible) | `section_anchor` |
| `section_dwell` | Batch cada 10s con tiempo acumulado por sección | `section_anchor, dwell_ms` |
| `scroll_depth_25/50/75/100` | Disparo único al cruzar umbral | `scroll_depth` |
| `presupuesto_open` | Entra a tab "Presupuesto" | |
| `firma_open` | Abre modal de firma | `tipo` = documento / presupuesto |
| `firma_abandoned` | Cierra modal de firma sin completar | |
| `comment_add` | Deja un comentario | `section_anchor` |
| `pdf_download` | Descarga PDF (si aplica) | |

### Cliente — `view.php` cambios

- UUID en `sessionStorage['tp_session']` (primer acceso después del PIN).
- `IntersectionObserver` sobre `h2[id]` → eventos `section_view`.
- Timer interno acumula dwell por sección actual, envía batch cada 10s al endpoint.
- Disparos `beforeunload` con `navigator.sendBeacon` para el close + dwell final.
- Scroll depth con `ResizeObserver` + umbrales.

### Endpoint de ingesta

**POST** `/api/track.php`
Body JSON:
```json
{
  "propuesta_slug": "h2bhipotecas-1",
  "sesion_id": "uuid-v4",
  "events": [
    {"tipo": "section_view", "anchor": "sec-a1", "at": "2026-04-21T10:00:01Z"},
    {"tipo": "section_dwell", "anchor": "sec-a1", "dwell_ms": 14300}
  ]
}
```
Acepta batch para ahorrar llamadas. Resuelve `propuesta_id` por slug + PIN-session cookie.
Calcula `visitor_hash` server-side (ip + ua + slug).
Inserta todo en `propuesta_eventos`.

---

## Fase 2 · Dashboard de señales (quick wins visibles)

### T11 · Badges calientes en admin.php dashboard

En la fila de cada propuesta, además de los badges de comentarios que ya están:

| Señal | Visual | Cuándo |
|---|---|---|
| `🔥 Activo ahora` | Pill roja pulsante | Evento abierto <2 min |
| `👀 Abierto 3×` | Pill mint | Sesiones únicas totales >1 |
| `💰 Vio presupuesto` | Pill mint con ✓ | `presupuesto_open` ≥1 |
| `⚠️ Intentó firmar` | Pill ámbar | `firma_abandoned` sin `firma_approved` posterior |
| `❄️ 5 días sin abrir` | Pill gris | Última sesión >5 días |

Query agregada + caché in-memory por request (evitar N+1).

### T12 · Card global "Actividad hoy"

Arriba del dashboard, junto al card de comentarios:
- *"3 sesiones activas ahora"*
- *"7 propuestas abiertas en las últimas 24h"*
- Click → lista de qué se abrió cuándo.

---

## Fase 3 · Vista de analítica por propuesta

### T9 · `/admin/analytics.php?propuesta_id=X`

Layout 2 columnas:

**Izquierda (40%)**: timeline cronológica de TODAS las sesiones de esa propuesta.
```
📅 hoy 10:23  ·  Jennifer (sesión 3)   · 4min 12s · leyó A1 → A3 → saltó a H
📅 ayer 18:44 ·  Eloi (nuevo)          · 8min 34s · leyó todo · comentó 8 veces
📅 lunes     ·  visitor-anónimo        · 1min     · solo home
```

**Derecha (60%)**: mapa de calor vertical por sección.

```
A0 Identidad visual    ███░░░░░░░  32s   (2 sesiones)
A1 Contexto            █████████   2m 14s (3 sesiones)  🔥
A2 Sitemap             ████████    1m 52s (3 sesiones)
A3 Home                ██░░░░░░░░  18s   (1 sesión)
A4 Páginas producto    ░░░░░░░░░░  0s    — no visto
...
Presupuesto            ███████████ 3m 05s (2 sesiones) 💰
Firma                  ░░░░░░░░░░  — no intento
```

Color scale: gris (frío) → amarillo → mint → mint intenso.

### T10 · Drill-down por sesión

Click en una sesión de la timeline → modal con su recorrido completo:
```
10:23:01  entra al doc (PIN OK)
10:23:15  lee A0 Identidad (15s)
10:25:42  lee A1 Contexto (2m 27s) ← aquí se quedó más tiempo
10:28:10  salta directo al presupuesto
10:29:14  abre modal firma
10:29:52  ❌ cierra modal sin firmar
10:30:22  vuelve a leer H Stack tecnológico (58s)
10:31:20  sale
```

Información accionable: *"Dudó al ver el precio, volvió al stack → hay que ayudarle a entender el precio en contexto del stack"*.

### Identidad del visitante (sin invasión)

Comparar `visitor_hash` con:
- `tp_signer` de localStorage si comentó o firmó → nombre visible ("Jennifer López").
- Si no, "visitante-1", "visitante-2" (consistente por hash, anonimizado).

Así Jennifer, Eloi y Eduard aparecen como personas distintas sin tracking invasivo.

---

## Fase 4 · Alertas proactivas

### T13 · Telegram automático en momentos clave

Enviar al chat interno cuando:

- **🔥 "H2B ha vuelto a abrir por 3ª vez hoy"** — sesiones únicas >2 en 24h.
- **💰 "H2B llegó al presupuesto"** — primer `presupuesto_open`.
- **⚠️ "Alguien intentó firmar en H2B y se fue"** — `firma_abandoned` sin firma posterior en 15 min.
- **💬 "Eloi abrió otra vez la sección donde había comentado"** — cliente vuelve a una sección con un hilo abierto de su autoría.
- **❄️ "H2B lleva 5 días sin abrir"** — para retomar el follow-up.

Una sola notificación por regla por día (dedupe).

---

## Fase 5 · Integración con el loop de feedback existente

- En la bandeja de comentarios, junto a cada hilo, mostrar **"Eloi visitó esta sección 4 veces · última hace 2h"**. Contexto inmediato para responder.
- En la gate de nueva versión ("🚀 Todos resueltos"), añadir *"El cliente volvió a la sección D después de tu respuesta · parece convencido"*. Señal para impulsar la firma.

---

## Ampliación MCP (cuando estén los eventos)

Nuevas tools en `mcp-proposals`:
- `get_proposal_activity(propuesta_id, since?)` — resumen agregado.
- `get_session_details(sesion_id)` — drill-down de una sesión.
- `get_hot_signals(since?)` — señales calientes transversales para briefing matutino.

Así Claude.ai en un *"cómo está H2B hoy"* te devuelve: *"Ayer Jennifer abrió 2 veces, leyó del A1 al A6 pero no llegó al presupuesto. Te recomiendo un follow-up enfocado en despejar dudas del alcance A."*

---

## Privacidad y legal

- **Nada de mouse tracking, keystrokes, heatmaps de click**. Solo sección + dwell agregado.
- `visitor_hash = sha256(ip + ua + slug + salt)` — no reversible a identidad real.
- Añadir línea en el splash del PIN: *"Registramos accesos y tiempo por sección para el seguimiento del proyecto"*.
- Retención: 12 meses. Job mensual que borra `propuesta_eventos` viejos.
- Nada se expone al cliente ni a APIs públicas — solo admin con sesión.

---

## Orden de ejecución propuesto

Dividido en **3 mini-sprints** ejecutables por separado:

### Mini-sprint 2.1 · Captura (1-2 sesiones de trabajo)
1. Migración `propuesta_eventos`.
2. Endpoint `/api/track.php` con batch insert.
3. Cliente-side: uuid, IntersectionObserver, dwell, beforeunload beacon, scroll thresholds.
4. Test: abrir `h2b-local` en local, moverse por el doc, verificar filas en BD.

### Mini-sprint 2.2 · Dashboard (1 sesión)
5. Badges calientes en admin.php dashboard.
6. Card "Actividad hoy".
7. Telegram alertas críticas (`presupuesto_open`, `firma_abandoned`, `activo 3x`).

### Mini-sprint 2.3 · Analítica (2 sesiones)
8. `/admin/analytics.php` con timeline + heatmap por sección.
9. Drill-down de sesión.
10. Identidad del visitante cruzando con `tp_signer`.
11. Integración de señales en la bandeja de comentarios + gate de versión.
12. Tools MCP `get_proposal_activity`, `get_session_details`, `get_hot_signals`.

### Tiempos estimados

- 2.1: ~4h de dev (backend + frontend) + pruebas.
- 2.2: ~2h.
- 2.3: ~6h.

**Total**: ~12h de trabajo real, troceable en 3-4 sesiones.

---

## Decisiones pendientes

Antes de empezar, 3 preguntas para cerrar:

1. **¿Identificación por visitor o por email/nombre?** — `visitor_hash` es anónimo por defecto. Cuando el visitante comenta o firma damos un nombre. Alternativa más agresiva: mostrar IP / geo. Mi voto: anónimo + enriquecimiento por identidad firmada.

2. **¿Retención de eventos?** 12 meses por defecto. ¿Te sirve, prefieres más/menos?

3. **¿Activamos alertas Telegram por defecto o flag por propuesta?** (algunas propuestas pueden no querer spam — ej. tests internos).

Cuando me digas ✅ arranco con el Mini-sprint 2.1.

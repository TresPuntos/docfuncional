# Plan · Mejora UX/UI documentos funcionales

Roadmap de trabajo sobre `view.php` y la librería reutilizable `doc-library.css`.
Inicio: 2026-04-14 · Primer caso real: `h2bhipotecas-1`.

---

## 🟢 Estado actual (2026-04-25)

**Para el estado vivo del proyecto y qué hay desplegado en prod, ver `CLAUDE.md`** (sección "✅ DESPLEGADO 2026-04-25 · Sistema de contratos firma electrónica eIDAS").

Resumen express:

- **Sistema de contratos eIDAS LIVE** en `doc.trespuntos-lab.com` desde 2026-04-25 (rama `feat/contratos-firma`, último commit `2a65457`, pendiente merge a `main`).
- **5 propuestas activas funcionando**: Gibobs, Aula Clinic, H2B Hipotecas v1.5 (Eloi), 100%100 Chef B2B, Nextica Law & Tax.
- **Test E2E pendiente**: contrato Truman Digital · Cardalis con Dani Marquina como proveedor de prueba (PDF preview en `~/Downloads/Contrato-Subcontratacion-Truman-Cardalis-tarifa30.pdf`).
- **NO se subió en este deploy** (decisión consciente para proteger clientes activos):
  - `view.php` con cambios heredados de Mermaid/journey/tp-bar-chart sin validar
  - Refactor sidebar admin (`admin.php`, `admin_providers`, `admin_analytics`, `admin_feedback`)
  - Archivos untracked de "tasks accionables del cliente" (`database/migrate_tasks.php`, `master/doc-tasks.php`)
- **Scripts de deploy** reusables en `scripts/deploy/` para futuros despliegues con backup/rollback/smoke-test automatizado.

El roadmap original de mejora UX/UI sigue válido, pero este sprint se centró en el sistema de contratos. Las tareas marcadas pendientes (P1, P2, ideas futuras) siguen vigentes.

---

## 🔥 Sprint activo (2026-04-20) · Loop de feedback cliente ↔ Tres Puntos

**Contexto**: H2B Hipotecas (Jennifer/Eloi/Eduard) ha dejado comentarios en la propuesta live y espera respuesta. Hoy el admin no ve avisos en el dashboard y no puede responder desde ninguna UI. Hay que cerrar el loop **ya** y además sorprender con un paso por delante (borrador pre-redactado, visibilidad de aperturas, hilos).

### Qué tenemos ya hecho

- `comentarios_seccion` con `parent_id` + `resuelto` en schema (sin UI que los use).
- `admin_feedback.php` (página suelta, sin enlace desde el dashboard, sin responder).
- `propuestas.views_count` + `last_accessed_at` → el contador de aperturas ya existe, solo hay que **destacarlo** en el dashboard y pintar *"Visto hace X"*.
- Notificaciones Telegram al crear comentario (ya integradas).

### Sprint 1 · Desbloquear al cliente · ✅ COMPLETADO (2026-04-20)

- [x] **T1 · Bandeja en dashboard admin** — card global "Comentarios pendientes" + badges `💬 N` `✏️ N` `✉ N` por propuesta en [admin.php](admin.php).
- [x] **T2 · Endpoint responder (staff reply)** — `admin.php?action=add_staff_reply` con `parent_id`, autor fijo *Tres Puntos*, `is_staff=1`. Notificación Telegram activa.
  - Migración `database/migrate_staff_replies.php` → `is_staff`, `resuelto_por`, `resuelto_at`.
- [x] **T3 · UI de respuesta en `admin_feedback.php`** — botón "Responder" inline por hilo, textarea, hilos anidados.
- [x] **T4 · Hilos + cierre visible para el cliente** — en [master/doc-feedback.php](master/doc-feedback.php): respuestas indentadas con pill mint *Equipo*, pill de estado abierto/respondido/cerrado, botón "Marcar resuelto" solo al autor. FAB global + botones inline por sección con 3 estados visuales (pending/answered/resolved).
- [x] **T5 · Gate para nueva versión** — banner verde "🚀 Todos los hilos resueltos · Lista para nueva versión" que lleva al editor cuando 0 abiertos.
- [x] **T-drafts · Sistema de borradores** — migración `is_draft` + Claude.ai puede redactar en borrador vía MCP API. Admin revisa con botones Editar/Publicar/Descartar. Cliente solo ve publicados.
- [x] **T-notify · Email automático vía Resend** — botón "✉️ Avisar cliente" envía email HTML con logo TP, botón CTA negro/mint (dark-mode-safe), firma Jordan, CC a `jordi@trespuntoscomunicacion.es`, reply-to a Jordi real. Dominio `trespuntos-lab.com` verificado en Resend. Alias `jordan@trespuntos-lab.com` creado.
- [x] **T-api · Endpoints REST para MCP** — `api/proposals.php` ampliado con: `comments`, `thread`, `reply_draft`, `reply_publish`, `publish_reply`, `discard_reply`, `resolve`, `notify`. Spec en [mcp/comments-tools-spec.md](mcp/comments-tools-spec.md).

### Sprint 2 · Visibilidad (siguiente) — **objetivo real: cerrar doc + presupuesto + firma**

La analítica no es un fin en sí — es munición para rematar la conversión. Todo lo que hagamos aquí tiene que responder a *"¿por qué este cliente no ha firmado todavía?"* y darte información accionable para el siguiente follow-up.

- [ ] **T8 · Tracking de sección (base del mapa de calor)** — en `view.php` añadir `IntersectionObserver` sobre cada `h2[id]` + tiempo de permanencia (dwell). Envío batched a `api/track.php` cada 10s o al `beforeunload`. Tabla nueva:
  ```sql
  CREATE TABLE propuesta_eventos (
    id INTEGER PRIMARY KEY,
    propuesta_id INTEGER NOT NULL,
    sesion_id TEXT,            -- uuid en sessionStorage del cliente
    visitor_hash TEXT,         -- sha256(ip + ua) para distinguir personas sin invasión
    tipo TEXT NOT NULL,        -- 'open','section_view','section_dwell','click','scroll_depth','presupuesto_open','firma_intent'
    section_anchor TEXT,
    dwell_ms INTEGER,
    scroll_depth INTEGER,      -- 0-100
    meta TEXT,                 -- json con extras (click target, etc)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  );
  ```
- [ ] **T9 · Heatmap por secciones en admin** — vista `admin_analytics.php?propuesta_id=X`:
  - **Barra de calor vertical** a la izquierda replicando el sitemap del doc, con intensidad = dwell total acumulado por sección. Colores: frío (gris) → tibio (amarillo) → caliente (mint).
  - **Drill-down por visitante**: filtro por `visitor_hash` → ves la sesión individual paso a paso (secuencia de secciones, tiempo en cada una).
  - **Diferencia entre firmantes potenciales** (Jennifer, Eloi, Eduard) si detectamos identidades distintas por `tp_signer` guardado.
- [ ] **T10 · Scroll-depth heatmap visual** tipo Hotjar lite:
  - Overlay opcional sobre la vista del admin del propio doc (iframe o clon) donde al pasar el ratón se ven las zonas más visitadas tintadas.
  - Implementación light: `ResizeObserver` + `IntersectionObserver` con % de viewport visible + dwell. No capturamos clicks ni movimiento de ratón (privacidad + peso).
- [ ] **T11 · Señales de cierre en el dashboard principal** — por propuesta:
  - *"Llegó al presupuesto"* ✅/❌ (evento `presupuesto_open`).
  - *"Leyó el resumen ejecutivo"* ✅/❌.
  - *"Secciones ignoradas"* — lista de H2 con dwell < 5s.
  - *"Intento de firma"* — si abrió el modal de firma sin completarla, aviso caliente al admin.
  - *"Tiempo total invertido"* agregado por visitante.
- [ ] **T12 · Inbox unificado** `/admin/inbox.php` reemplazando `admin_feedback.php` — feed cronológico con filtros (*pendientes de responder / sin abrir / firmados esta semana / sin llegar al presupuesto*), favicon badge.
- [ ] **T13 · Telegram/email de alertas calientes** — disparar push cuando:
  - Cliente vuelve a abrir el doc por 3ª vez el mismo día.
  - Llega al presupuesto y no firma en >15 min.
  - Añade un comentario nuevo.
  - Abre pero sale sin pasar del 20% de scroll (señal de rechazo).

**Privacidad y legal** (por estar en live con RGPD):
- Nada de capturar movimiento de ratón, teclas ni scroll continuo. Solo eventos agregados por sección.
- `visitor_hash` es `sha256(ip + ua + slug)` — no identifica personas, solo sesiones.
- Añadir línea en el splash del PIN: *"Registramos accesos y tiempo por sección para el seguimiento comercial"*.
- Datos solo accesibles por admin con sesión; no se exponen en ningún endpoint público.

### Sprint 3 · Pulido comercial (nice-to-have, alto ROI)

- [ ] Pantalla post-firma fullscreen con "Siguiente paso" + descarga PDF firmado server-side (DOMPDF del estado exacto + hash).
- [ ] Diff visual entre versiones usando `propuestas_history`.
- [ ] Reacciones 👍❤️ en comentarios (tabla `comentario_reacciones`).
- [ ] Plantillas de respuesta guardadas por admin ("Lo ajustamos en v1.1", etc.).
- [ ] Estados de propuesta (borrador → enviado → visto → en_revisión → firmado → caducado) + expiración con recordatorios automáticos.
- [ ] Subpin read-only para compartir interno del cliente.

### MCP · Ampliación para agentes (T11)

El MCP actual (`mcp-proposals.vercel.app`) solo expone proposals. Ampliar `api/proposals.php` + MCP tools para:

- [ ] `list_comments(propuesta_id?, status?)` → leer comentarios y respuestas.
- [ ] `get_comment_thread(comment_id)` → comentario raíz + hilo.
- [ ] `reply_to_comment(comment_id, texto)` → crea respuesta staff (usa el mismo endpoint `add_staff_reply`).
- [ ] `resolve_comment(comment_id)` → marcar resuelto desde agente (solo si política lo permite; probablemente admin-only, no cliente).
- [ ] `edit_comment(comment_id, texto)` → editar texto (auditar quién: staff vs autor).
- [ ] Auth: mismo `Bearer API_TOKEN` que el resto.
- [ ] Documentar en `api/API_AGENT_INSTRUCTIONS.md`.

### Decisiones de diseño cerradas

- **Quién cierra un comentario**: solo el autor original (match por `tp_signer` nombre+apellidos). Staff puede responder pero no cerrar en nombre del cliente — evita la sensación de *"me han cerrado la duda sin resolverla"*.
- **Copy explícito** cuando no eres el autor: pill `🟡 Abierto · solo [Nombre] puede cerrarlo` + tooltip *"El comentario lo cerrará quien lo abrió una vez esté resuelto"*.
- **Staff identity** fija: todas las respuestas van firmadas como "Tres Puntos" (no por persona), pill mint para distinguir del cliente. Futuro: permitir firmar por persona si hacemos cuentas internas.
- **Contador de vistas**: ya existe (`views_count` / `last_accessed_at`), lo visibilizamos sin tabla nueva en esta fase.

---

## 📍 Estado actual (2026-04-14)

### ✅ Desplegado en producción
Ruta: `https://doc.trespuntos-lab.com/doc/` (Hostinger, `u296656791`)

- `view.php` — nuevo shell con nav jerárquica H2→H3, IntersectionObserver, progress bar + label flotante, Telegram server-side, tipografía H1/H2 reducida (2.6rem/1.7rem desktop · 1.8rem/1.4rem móvil), content-wrapper 1080px, responsive validado 375/768/1000/1100/1440 sin hscroll.
- `master/doc-library.css` — librería de componentes: tp-card, tp-grid, tp-sitemap (colapsable con árbol visual + búsqueda + leyenda), tp-callout (info/success/warning/note/quote), tp-comparison, tp-timeline, tp-stat, tp-tag (mint/proto/tp/pending/muted), tp-section-divider + tablas responsivas globales con auto-wrap `.table-scroll`.
- Backup: `/doc/view.php.bak` (versión anterior).
- FTP operativo: `ftp.trespuntos-lab.com` · user `u296656791.claude3` · pass `4*wrvJ2D` (rotar cuando proceda).

### ✅ Git
- Remote: `github.com/TresPuntos/docfuncional`
- Commits publicados en `main`:
  - `f87256d` feat(admin,api): restore previous versions from history
  - `5c42ae9` feat(view): visual sitemap + hierarchical nav + responsive tables
  - `cb14d4e` fix(view): responsive overflow — flex item sizing and sitemap nodes
- Cambios de tipografía H1/H2 aplicados en `view.php` local **todavía sin commit+push**.

### ✅ Propuesta `h2bhipotecas-1` (id=21) — v1.3 activa en producción

URL: `https://doc.trespuntos-lab.com/p/h2bhipotecas-1`
Historial guardado: v1.1, v1.2 (restaurables vía API o admin).

**Contenido v1.3**:
- A. Plataforma corporativa (completo: A0 identidad, A1 contexto/objetivos con alcance como tabla por fase, A2 sitemap visual, A3-A9 estructura de páginas).
- B. Herramientas y calculadoras (scope, 7 + Euríbor, fórmulas pendientes del cliente).
- C. Áreas privadas (3 tipos + widget + referidos + 7 roles del brief).
- D. Panel administración (17 módulos).
- **E. Automatización y scoring de leads con n8n + IA** (nuevo — flujo 6 pasos en timeline, 4 rangos de score con acciones).
- F. Funcionalidades transversales.
- G. Propuesta de fases (tp-comparison Fase 1 vs Fase 2 + 5 razones).
- H. Stack tecnológico — Fase 1 **WordPress confirmado**, Fase 2 **abierto** (Angular vs React en `tp-comparison`, backend a definir: Laravel/Node/NestJS).
- I. Siguientes pasos (aprobación + formato presupuesto + cómo seguimos). Eliminados bloques "desglose por bloque", "criterios de éxito", "lo que necesitamos de H2B".

**Reputación** (1.1) — opción C aplicada: *"el broker hipotecario mejor valorado por sus clientes en Google, con más de 1.200 reseñas 5★"*. Elegida porque Hipotecas.com tiene más volumen (3.058 reseñas) — el claim "mejor reputación" sin matizar era indefendible.

### 📌 Decisiones cerradas con el cliente (Jordi)
- Prototipo React del cliente = referencia visual. Diseño UX/UI desde cero por Tres Puntos.
- Brand: paleta cliente respetada (#e1007d fucsia, #211f5e azul, #ffd103 amarillo) + Poppins/Comfortaa.
- Modo claro y oscuro transversal.
- 7 calculadoras **no se reducen** — se jerarquizan UX (decidido pero no añadido al doc; user dijo "no cambiar nada").
- Copys = los aporta el cliente. No redactar textos de ejemplo.
- 7 roles del brief incluidos como requisito del cliente, sin matriz de permisos.
- Fase 1 = WordPress (cerrado). Fase 2 = stack abierto según funcionalidades.
- n8n + IA para lead scoring como bloque propio (E).

### 🔍 Contexto del cliente (Notion + Airtable)
- **Airtable record**: `appR9SHmsc6CZ7VJj / tblqbhaPtZlsPbsYs / recN2FF00VDMhjKsq`
- **Contacto**: Jennifer <jennifer@h2bhipotecas.com> · 646 478 379
- **Decisores**: CEO Eduard Roldós · CMO Eloi Herrero
- **Presupuesto**: 30-50K€ (ampliado tras reunión; estimación inicial 10-15K se quedó corta)
- **Deadline propuesta**: viernes 17 abril 2026
- **Estado**: "Funcional en curso"
- **Notion páginas clave**:
  - Briefing reunión: `3401b33b-8b21-8171-b83a-e051714d7d2e`
  - Ficha lead H2B: `33c1b33b-8b21-816e-a2fe-e974f585fa3d`
  - Prep reunión: `3401b33b-8b21-8155-87db-c3f4f8a1eedc`

### 🎯 Siguiente paso natural
1. ~~Commit + push de los cambios tipográficos (H1/H2 reducidos) a GitHub.~~ ✅ (bd781ac · 8c2555d)
2. ~~h2bhipotecas v1.4 con Resumen ejecutivo, accordion D admin, phase-tags, polish exec-summary.~~ ✅ (750713c)
3. ~~Light mode + toggle en sidebar + protección anti-copia en /p/{slug}.~~ ✅ (4215969)
4. Validar `h2bhipotecas-1` v1.4 con Jordi y promocionar a v1.5 con `save_version: true` cuando esté OK.
5. Enviar a Jennifer tras validación.
6. Crear la **skill `create-functional-doc`** para futuros documentos funcionales.
7. Limpiar cuenta FTP `claude3` de Hostinger cuando dejemos de subir archivos.

---

## P0 · Imprescindible ✅ COMPLETADO

- [x] `/master/doc-library.css` creada.
- [x] `tp-sitemap` implementado y pulido.
- [x] Tablas responsivas + `.table-scroll` automático.
- [x] Nav jerárquica H2→H3 con IntersectionObserver.
- [x] Progress bar unificada + label flotante.
- [x] Telegram server-side (cURL 3s timeout, token fuera del DOM).
- [x] CSS consolidado (dos bloques duplicados eliminados).
- [x] `h2bhipotecas-1` migrada a los componentes nuevos (v1.1 → v1.3).

## P1 · Siguiente iteración

- [ ] Toolbar flotante (copiar-enlace-sección, expandir/colapsar todo, imprimir, A-/A+).
- [ ] Tiempo de lectura estimado en `doc-meta`.
- [ ] "Siguiente sección" al final de cada H2.
- [ ] Modo densidad (Compacto/Cómodo) en `localStorage`.
- [ ] Print stylesheet (`@media print`).
- [ ] Landmarks ARIA + `aria-current="location"`.
- [ ] Revisar tokens: migrar aliases legacy (`--tp-primary` → `--mint`).
- [ ] Quitar `filter: grayscale(20%)` de fotos equipo (contradice brand).
- [ ] Mediaquery intermedia 769-1023 (evita overflow).
- [ ] `metodologia.php` opcional (flag por propuesta).

## P2 · Nice-to-have

- [x] **Comentarios inline por sección (drawer lateral)** — rama `feat/section-feedback`, en local sin push.
- [x] **Firma ligera en aprobaciones** (nombre + apellidos + hash SHA256 + versión) — misma rama.
- [ ] Diff visual entre versiones (ya hay `propuestas_history`).
- [ ] Onboarding 3 pasos primera visita.
- [ ] Light theme en shell (hoy solo dark — documento soporta dark mode pero shell aún no).

---

## 💡 Ideas futuras (pendiente de diseño)

### 1. Presupuesto PDF → factura renderizada con estilos Tres Puntos
Hoy el presupuesto se sube como PDF y se muestra tal cual en `<iframe>`. Propuesta:

- **Input:** el PDF que ya se sube (o datos estructurados copiados del PDF).
- **Output:** render HTML en formato factura con identidad Tres Puntos (tokens, tipografía, tablas `tp-*`).
- **Reordenar vista:** al aprobar un presupuesto, este pasa a ser la **pestaña principal** y el documento funcional queda como referencia secundaria. Hoy es al revés.
- **Cambios en `view.php`:**
  - Detectar si hay presupuesto aprobado → reordenar secciones.
  - Nueva plantilla `tp-invoice` en `doc-library.css` (header cliente, líneas, subtotales, impuestos, total).
- **Cuidado con lógica existente:** `approve_pdf`, `reject_pdf`, notificación Telegram, tabla `aprobaciones` — todo ya firmado con hash. Mantener compatibilidad.

**Bloqueantes antes de construir:**
- Definir esquema del presupuesto (líneas, descuentos, impuestos, plazos de pago).
- Decidir si el PDF sigue siendo fuente de verdad o si se parsea a JSON en BD.
- Flujo de aprobación: ¿firmar sobre la factura HTML o seguir sobre el PDF?

### 2. Trazabilidad "quién ha hecho qué"
Extender el log actual (hoy solo firma la aprobación con nombre del cliente):
- **En el lado cliente:** ya queda registrado en `aprobaciones.firmante_*` y `comentarios_seccion.autor_*`.
- **En el lado Tres Puntos:** falta — ¿quién redactó la propuesta, quién la envió, quién respondió al comentario?
- Solución: tabla `propuesta_actividad (id, propuesta_id, actor_tipo, actor_id, accion, detalle_json, created_at)` con timeline unificada en el admin.
- Integrar con la API de agentes IA (Bird genera propuesta → entrada automática; cada save_version → entrada).

### 3. Jordan-doc (agente Haiku scopeado al documento)
- Código paralelo ya creado en `/TRESPUNTOS-LAB/Jordan/tres-puntos-agent-doc/` (local, sin desplegar).
- Pendiente: endpoint `/api/jordan-doc.php`, tabla `jordan_conversaciones`, flag `enable_ai_assistant` por propuesta.

## Skill `create-functional-doc` (pendiente)

- [ ] `~/.claude/skills/create-functional-doc/SKILL.md` con triggers.
- [ ] `components/` con snippets HTML de cada `tp-*`.
- [ ] `references/` (brand, voice, vocabulary, doc-structure, estructura canónica A0-I).
- [ ] `workflows/` (new-doc, update-doc).
- [ ] `examples/h2b-v13.html` como referencia.

## Librería — estado

| Componente | Estado |
|---|---|
| `tp-card` / `tp-grid` | ✅ en library |
| `team-grid` / `team-card` | en view.php shell (pendiente mover) |
| `cta-block` | en view.php shell (pendiente mover) |
| `tp-sitemap` | ✅ en library |
| `tp-comparison` | ✅ en library |
| `tp-callout` (info/success/warning/note/quote) | ✅ en library |
| `tp-tag` (mint/proto/tp/pending/muted) | ✅ en library |
| `tp-timeline` | ✅ en library |
| `tp-stat` | ✅ en library |
| `tp-section-divider` | ✅ en library |
| `tp-stack` (accordion FAQ) | ✅ en library |
| `exec-summary` + `exec-phase` | ✅ en library |
| `phase-tag` (Fase 1/2 en H2) | ✅ en library |
| Light mode tokens + overrides | ✅ en library |
| `@media print` (bloqueo) | ✅ en library |
| `tp-toc-inline` | pendiente P1 |
| `tp-diff` | pendiente P2 |

## Deuda técnica pendiente

- Cambios tipográficos H1/H2 sin commitear → hacerlo en próxima sesión.
- `view.php:~956` — `grayscale(20%)` en fotos equipo.
- Migración completa a tokens canónicos (`--tp-primary` → `--mint`).
- Eliminar FTP account `claude3` cuando cerremos esta tanda de uploads.

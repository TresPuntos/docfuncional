# Plan · Mejora UX/UI documentos funcionales

Roadmap de trabajo sobre `view.php` y la librería reutilizable `doc-library.css`.
Inicio: 2026-04-14 · Primer caso real: `h2bhipotecas-1`.

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

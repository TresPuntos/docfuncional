# Plan · Mejora UX/UI documentos funcionales

Roadmap de trabajo sobre `view.php` y la librería reutilizable `doc-library.css`.
Inicio: 2026-04-14 · Primer caso real: `h2bhipotecas-1`.

---

## P0 · Imprescindible (esta iteración)

- [ ] **Crear `/master/doc-library.css`** — fuente única de componentes de contenido (tp-card, tp-grid, tp-sitemap, tp-callout, tp-comparison, tp-timeline, tp-stat, tp-tag).
- [ ] **Componente `tp-sitemap`** — árbol visual colapsable con niveles, badges de origen, contador, búsqueda y líneas de conexión.
- [ ] **Tablas responsivas** — estilo global (zebra, sticky head) + wrapper `.table-scroll` automático en `view.php`.
- [ ] **Nav lateral jerárquica** — H2 principal + H3 hijos en acordeón, `IntersectionObserver`, conserva numeración (A1, A2…).
- [ ] **Barra de lectura unificada** — 100% ancho arriba, con % scroll + nombre de sección activa.
- [ ] **Telegram server-side** — mover envío a `approve_doc/comment_doc/approve_pdf/reject_pdf` en PHP. Quitar `BOT_TOKEN`/`CHAT_ID` del JS público.
- [ ] **Consolidar CSS** — quitar el bloque `<style>` duplicado (view.php:815-915 vs 1163-1259), cargar `doc-library.css` vía `<link>`.
- [ ] **Aplicar a `h2bhipotecas-1`** — reescribir sección "Arquitectura y sitemap" con `tp-sitemap`, comparativas con `tp-comparison`.

## P1 · Siguiente iteración

- [ ] Toolbar flotante (copiar-enlace-sección, expandir/colapsar todo, imprimir, A-/A+).
- [ ] Tiempo de lectura estimado en `doc-meta`.
- [ ] "Siguiente sección" al final de cada H2.
- [ ] Modo densidad (Compacto/Cómodo) en `localStorage`.
- [ ] Print stylesheet (`@media print`).
- [ ] Landmarks ARIA + `aria-current="location"`.
- [ ] Revisar tokens: migrar aliases legacy (`--tp-primary` → `--mint`) y texto (`#B0B0B0` → `#f5f5f5`).
- [ ] Quitar `filter: grayscale(20%)` de fotos equipo (contradice brand).
- [ ] Mediaquery intermedia 769-1023 (evita overflow).
- [ ] `metodologia.php` opcional (flag por propuesta).

## P2 · Nice-to-have

- [ ] Comentarios inline por sección (drawer lateral).
- [ ] Diff visual entre versiones (ya hay `propuestas_history`).
- [ ] Onboarding 3 pasos primera visita.
- [ ] Dark/Light toggle (hoy solo dark).

## Skill `create-functional-doc`

- [ ] `~/.claude/skills/create-functional-doc/SKILL.md` con triggers.
- [ ] `components/` con snippets HTML de cada `tp-*`.
- [ ] `references/` (brand, voice, vocabulary, doc-structure).
- [ ] `workflows/` (new-doc, update-doc).
- [ ] `examples/h2b-reviewed.html` como referencia.

## Librería — componentes pendientes de crear

| Componente | Prioridad | Estado |
|---|---|---|
| `tp-card` / `tp-grid` | existe | consolidar |
| `team-grid` / `team-card` | existe | consolidar |
| `cta-block` | existe | consolidar |
| `tp-sitemap` | P0 | pendiente |
| `tp-comparison` | P0 | pendiente |
| `tp-callout` | P0 | pendiente |
| `tp-tag` | P0 | pendiente |
| `tp-timeline` | P1 | pendiente |
| `tp-stat` | P1 | pendiente |
| `tp-stack` (accordion) | P1 | pendiente |
| `tp-toc-inline` | P1 | pendiente |
| `tp-diff` | P2 | pendiente |

## Deuda técnica detectada

- `view.php:1653-1654` — Telegram token expuesto en HTML público.
- `view.php:815-915` vs `1163-1259` — CSS `.tp-card` duplicado.
- `view.php:1622-1651` — scroll-spy ineficiente (debería ser IO).
- `view.php:956` — `grayscale(20%)` en fotos equipo, contradice brand.
- `view.php:325-333` — tokens legacy conviven con `--mint` oficial.
- `view.php:1576` — nav strip quita numeración `A1.`, `A2.`.

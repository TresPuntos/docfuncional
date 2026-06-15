# Tres Puntos - Proposal Management System (docfuncional)

> **Estado actual del proyecto y próximos pasos** → ver [`PLAN.md`](PLAN.md) en la raíz. Léelo al empezar sesión para entender qué está desplegado, qué cliente trabajamos y qué viene después.

---

## ⛔ REGLA ABSOLUTA · Cara-cliente: cero proveedores, mismo proceso en cualquier stack

En cualquier comunicación cara-cliente (doc funcional `html_content`, emails Resend, comentarios `/p/{slug}`, presupuestos, contratos cliente, llamadas) **nunca aparece ningún proveedor externo**, ni nominal (Dani, Truman) ni genérico ("partner técnico", "partner de back-end", "partner de integración", "proveedor especialista", "el dev externo", "colaborador externo"). Cualquier fórmula tipo *partner X* es sinónimo camuflado de proveedor: prohibida.

El relato cara-cliente es siempre el mismo independientemente del stack (WordPress, Angular + Laravel, Node, lo que sea): **Tres Puntos hace UX + UI + desarrollo end-to-end con su equipo**. El reparto interno entre equipo propio y subcontratas vive en CLAUDE.md, memoria de Claudio y portal proveedor — nunca se filtra al cliente.

Aplica también al detallar entregables Fase 2: no decimos "entregamos UI maquetada para que la integre el partner técnico". Decimos: "el equipo construye la app desacoplada (front Angular + API Laravel)".

---

## 🆕 2026-06-14 · Cajas de respuesta del cliente (`tp-respuesta`) — texto libre + Guardar

Nueva funcionalidad para bloques de "dudas/preguntas para el cliente": una **caja de texto con botón Guardar** embebible en cualquier punto del `html_content`. El cliente escribe su respuesta dentro del propio documento y la guarda; queda persistida, editable (se puede volver a guardar) y precargada al recargar. Al guardar, ping Telegram al equipo. Modelada sobre el patrón de `tp-tasks`.

**Archivos:**
- **`database/migrate_respuestas.php`** · tabla `propuesta_respuestas` (respuesta_key, pregunta, respuesta_texto, autor_nombre/email, updated_at · UNIQUE(propuesta_id, respuesta_key)). **No hace falta correrla en prod**: `view.php` la auto-crea con `CREATE TABLE IF NOT EXISTS` en el primer `respuestas_sync`.
- **`master/doc-respuestas.php`** · CSS+JS autocontenido (incluido en `view.php` solo en modo cliente, junto a `doc-tasks.php`). Renderiza textarea + botón Guardar + estado "Guardado · autor · fecha". Botón deshabilitado hasta que hay cambios. Usa `tp_signer` (identidad del login PIN, sin modal).
- **`view.php`** · 2 endpoints nuevos en el bloque `api_action`:
  - `respuestas_sync` · UPSERT de preguntas declaradas en HTML + devuelve respuestas guardadas (auto-crea tabla).
  - `respuesta_save` · upsert del texto (lee signer de sesión) + Telegram alert.

**Markup que el admin pone en html_content de la propuesta** (una caja por pregunta):
```html
<div class="tp-respuesta"
     data-respuesta-key="j2-1-idiomas"
     data-respuesta-label="Vuestra respuesta"
     data-respuesta-pregunta="Idiomas activos en el lanzamiento"></div>
```
- `data-respuesta-key` (obligatorio, estable): identificador único en kebab-case por propuesta.
- `data-respuesta-label` (opcional): etiqueta sobre el textarea (por defecto "Vuestra respuesta").
- `data-respuesta-pregunta` (opcional pero recomendado): texto que sale en la notificación Telegram.

**Solo modo cliente (`/p/{slug}`)** — el endpoint es `/p/`+slug. No se activa en portal proveedor `/s/`.
**Primer uso real:** MAI CDMO (id 31) — una caja tras cada duda del bloque J.2.

### 🆕 2026-06-15 · Botón "Enviar respuestas" (obligar a contestar todas) + estado por caja

Ampliación del componente para forzar que el cliente conteste **todas** las preguntas de un grupo antes de enviar, con buena UX de "por qué no puedes enviar":

- **Agrupación**: cada `tp-respuesta` admite `data-respuesta-grupo="j2"`. Un bloque de envío gobierna su grupo:
  ```html
  <div class="tp-respuestas-enviar" data-respuestas-grupo="j2"
       data-respuestas-titulo="Enviar vuestras respuestas"></div>
  ```
- **`doc-respuestas.php`**: el bloque de envío renderiza barra de progreso "X de N respondidas", **lista por nombre lo que falta**, etiqueta **Pendiente/Respondida** por caja, y un **botón deshabilitado hasta completar las N**. Al completar → habilitado; al enviar → confirmación "Enviadas por … · fecha" y el botón pasa a "Reenviar respuestas". El autosave por pregunta sigue igual (con su Telegram).
- **`view.php`**: endpoint nuevo **`respuestas_submit`** (valida server-side que las N tienen texto; si falta alguna devuelve `faltan:[keys]` sin escribir nada) + **un Telegram consolidado** con todas las respuestas al enviar. `respuestas_sync` ahora devuelve también `envios` (estado de envío por grupo) para restaurar la confirmación al recargar. Tabla nueva auto-creada **`propuesta_respuestas_envios`** (UNIQUE propuesta_id+grupo).
- **Probado end-to-end en local** (0/2→1/2→2/2→enviar→reenviar, persistencia BD) y **verificado en prod** (render + gating + rechazo de envío incompleto, sin ensuciar el doc). **Desplegado por FTP 2026-06-15.**

---

## ✅ DESPLEGADO 2026-05-05 · Aula Clínic v1.0 · Comentarios Dani resueltos + presupuesto Holded + fix privacidad proveedor

> Sesión larga. Tres bloques de trabajo: (1) revisión y resolución de los 5 comentarios de Dani sobre Aula Clínic (id=23) → doc draft sobre v1.0; (2) creación de presupuesto E170386 en Holded vía API directa con horquillas; (3) fix crítico de privacidad en `view.php` que mostraba el HTML del Holded a proveedores aunque el tab estuviera oculto.

### Bloque 1 · Aula Clínic v1.0 — 5 comentarios de Dani resueltos (vía API REST PUT sin save_version)

Doc id=23, slug `aula-clinic`. Sigue como **v1.0** (NO save_version) porque el cliente Hedima nunca vio v1.0 — la primera que verá es la post-Dani. Cuando Dani devuelva matices, ahí sí save_version → v1.1.

**Cambios al HTML**:

1. **Sección nueva 7 · Blog y contenidos editoriales** (~25 vistas, blog dentro del a medida, no WP headless). Lead corto sin entrar en detalle técnico (decisión user). Sub-bloques: 7.1 objetivo, 7.2 qué ve usuario (5 cards), 7.3 gestión panel admin (5 bullets simples), 7.4 posicionamiento orgánico (1 frase, sin SSR/schema/hidratación). Insertada entre Catálogo y Motor fiscal → desplaza 12 secciones (8→9 …, 17→18, 18→19).
2. **Sección nueva 12 · Stack tecnológico** (renumerada desde 11 tras meter Blog) — Front Angular o React + Back Laravel + 5 cards "por qué a medida": SAGE bidireccional, Comisiones e IVA por tipología, B2B con compra delegada, Marketplace de cursos completo, Autonomía total del equipo. Lead con transición suave "primera aproximación valoramos un CMS, ahora vamos a medida". Mantiene "Angular o React" abiertos (kickoff técnico).
3. **Refactor sec 11.3 (CRM) en Integraciones** — pasa de "pendiente decisión" a explícito: 2 vías posibles (CRM mercado vía API o módulo a medida en panel admin). **No presupuestado por falta de info**.
4. **Refactor sec 11.2 (SAGE) · card "API a medida"** — tag pasa de mint "Disponible" a pending "Por validar". Mención explícita "Fase II" como dijo el cliente.
5. **Sec 18 (Dudas) · 2 nuevas filas en tabla**: SAGE entorno y CRM con vías (mantiene CRM como duda alta).
6. **Sec 19 (Siguientes pasos)** reescrita: 19.1 doc "casi completo", 19.2 cierre dudas (incluye SAGE + CRM), 19.3 "Sobre el presupuesto" (partidas cerradas vs horquillas + rango aproximado del coste final), 19.4 cómo seguimos.
7. **Índice general** — quitada cláusula "Qué NO hay aquí" (porque ahora SÍ entregamos presupuesto + equipo + stack).
8. **Renumeración masiva** — H2 7→8 hasta 18→19 + subsecciones 15.X→16.X + 5 refs cruzadas (sección 13/14/15.1/15.2/16) + reescritura sec 19 (era 17).

**HTML**: 82.034 → 90.819 chars. Backups en `/tmp/aula-clinic-stack/v1.0-*.html`.

### Bloque 2 · Presupuesto E170386 en Holded (Hedima D N FORMACION SL)

**No existe wrapper de creación de Holded en el código** — solo lectura (`holded_get_estimate`, search). Creado por **POST directo a Holded API** con autorización explícita del user.

- **Endpoint**: `POST /documents/estimate` (NO `/documents/salesestimate` que dio "Unknown docType")
- **Auth**: `key: {HOLDED_API_KEY}` header
- **Contact ID Hedima**: `68dd03d067343bce8001c273` (encontrado vía `GET /contacts?vatnumber=B82651514`, hay 2 duplicados sin tipo, usar el `type=client`)
- **Estimate ID**: `69fa1af30b28bb40880ace4a` · doc number **E170386**
- **Estado**: borrador (status=0), NO vinculado al doc, NO enviado al cliente

**Estructura final · 8 partidas** (margen 25% sobre Dani por defecto, margen 33% en Plataforma+Virtagora tras decisión comercial):

| # | Partida | Importe |
|---|---|---:|
| 01 | Diseño UX/UI | 5.000 € |
| 02 | Maquetación HTML/CSS responsive | 3.500 € |
| 03 | Plataforma · Angular + Laravel ★ | 13.500 € (max horquilla 10.500-13.500) |
| 04 | Integración Virtagora LMS | 4.000 € (margen 33%) |
| 05 | Sincronización CSV nightly | 1.750 € |
| 06 | Project Manager · QA y despliegue | 2.800 € |
| 07 | SEO, analítica y Cerebro Digital | 4.500 € |
| 08 | Integración SAGE · Fase II ★ | 5.000 € (max horquilla 3.500-5.000) |
| | **Subtotal s/IVA** | **40.050 €** |
| | IVA 21% | 8.410,50 € |
| | **Total c/IVA** | **48.460,50 €** |

★ = horquilla. Holded no soporta rangos por línea → se sube al **máximo** (anchoring psicológico) + nota en descripción "* Precio horquilla X-Y €" + nota pie general "El total quedará entre 35.550 € y 40.050 €".

**Costes Dani (uso interno, no cara-cliente)**:
- Dev front+admin+back: 8.000-10.000 €
- Virtagora: 3.000 €
- CSV: 1.400 €
- SAGE: 2.500-3.600 €
- **Total Dani**: 14.900-18.000 €
- **Margen Tres Puntos sobre subcontratación**: 4.100-5.000 € (~28%)
- **Margen sobre partidas TP propias** (cliente 14.800 € − coste interno equipo TP ~10.000 €): ~4.800 €
- **Beneficio NETO TP**: ~10.650 € (escenario simple) a ~12.050 € (escenario complejo)

**Patrón reutilizable**: para futuras propuestas con incertidumbre real en alguna partida, subir Holded al **importe máximo** + nota "* Precio horquilla X-Y €" en `desc` + nota global en `notes`. Anchoring psicológico mejor que arriesgar fricción de subida posterior.

### Bloque 3 · Fix de privacidad en view.php (commit `1fe46d9`)

**Bug detectado** durante preparación de `holded_link`. El user preguntó si Dani podía ver el presupuesto al vincular Holded. Verificación en código mostró bug serio:

```php
// ANTES (bug)
<?php if ($hasPresupuestoTab): ?>
<div class="doc-view" data-tab="presupuesto" hidden>
<?php endif; ?>                              // ← cierre temprano del condicional
<?php if ($hasHolded):                       // ← se ejecutaba SIEMPRE, incluso con $isProviderMode
    include 'master/presupuesto-holded.php'; // ← HTML del invoice queda en DOM
elseif ($hasPdf): ...
endif; ?>
<?php if ($hasPresupuestoTab): ?>
</div>
<?php endif; ?>
```

Resultado: el botón del tab "Presupuesto" no se renderizaba para proveedores (eso sí funcionaba), pero **el HTML del template `tp-invoice` se imprimía huérfano al final del documento**, visible al hacer scroll.

**Fix**: envolver todo el bloque (apertura div + render Holded/PDF + cierre div) dentro de un único `if ($hasPresupuestoTab)`. Diff de -2 líneas en `view.php` (líneas 2833 y 2918).

**Verificación con Playwright en local**:
- BD prod descargada vía FTP a local (`database/database.sqlite` 4,2 MB).
- E170386 vinculado al doc 23 SOLO en BD local (sin tocar prod).
- Server `php -S localhost:8000 router.php`.
- Test 1 · Cliente con PIN 2024: ✅ ve los 2 tabs · `<div data-tab="presupuesto">` presente · `.tp-invoice` renderizado · "E170386" en DOM.
- Test 2 · Proveedor Dani con token + PIN 5129: ✅ 0 tabs · sin `[data-tab="presupuesto"]` · sin `.tp-invoice` · sin "E170386" · sin "40.050" · sin IBAN.
- Test 3 · Proveedor sigue viendo doc normal: 26 H2, 407 KB de contenido, sec 7 Blog, sec 12 Stack visibles.

**Deploy**:
- Backup prod: `/tmp/tp-prod-backup-20260505-191621-view-fix/view.php`
- Upload FTP: 226 OK, 170.138 bytes
- Smoke 3 propuestas (aula-clinic, h2bhipotecas, gibobs-allbanks): HTTP 200, 0 errores PHP
- Git: `9bcf023..1fe46d9 main -> main`

### Estado al cierre de sesión (2026-05-05 19:20)

- Aula Clínic doc id=23 v1.0 con 5 comentarios Dani resueltos (en BD prod, sin save_version).
- E170386 en Holded como borrador, NO vinculado al doc todavía.
- Fix view.php DESPLEGADO en prod.
- 5 hilos de Dani en `proveedor_mensajes` siguen abiertos sin respuesta staff.
- Pendiente:
  1. **Vincular E170386 al doc 23** con `holded_link` cuando Jordi dé OK (ahora seguro tras fix view.php).
  2. **Esperar matices de Dani** sobre los cambios v1.0 → integrar y subir a v1.1.
  3. **Enviar a Hedima** doc + presupuesto cuando v1.1 esté lista.
  4. **Replicar borradores de respuesta** a los 5 hilos de Dani vía `provider_reply_draft` para que Jordi los publique cuando proceda.

### Patrones reutilizables aprendidos esta sesión

1. **Renumeración masiva del doc**: aplicar siempre en orden DESCENDENTE (18→19, 17→18, 16→17…) o usar tokens temporales para evitar pisar refs ya cambiadas. El script Python con `re.sub` y función lambda es lo más limpio.
2. **Holded API · crear estimate**: `POST /documents/estimate` (NO `salesestimate`). Payload con `contactId`, `date`, `dueDate`, `items[].name|desc|units|subtotal|tax|discount`, `notes`, `language`, `currency`.
3. **Holded API · update estimate**: `PUT /documents/estimate/{id}` con mismo payload (cuerpo). Sustituye items y notes completos.
4. **Anchoring presupuestos con horquilla**: subir al MÁXIMO en Holded + nota visible. Bajar después se siente como descuento; subir después se siente como sorpresa negativa.
5. **Test de privacidad en local con BD prod**: descargar `database.sqlite` vía FTP curl + restaurar BD local original al terminar (`/tmp/aula-clinic-stack/local-test/database.sqlite.before`).
6. **Playwright para tests de privacidad**: `browser_evaluate` con `document.body.innerHTML.includes('XXXXX')` para verificar que un literal NO aparece en DOM. Más fiable que comprobar visibilidad CSS (un `hidden` puede saltarse vía URL hash).

### Backups disponibles (rollback)

- HTML doc Aula Clínic v1.0 pre-comentarios Dani: `/tmp/aula-clinic-stack/v1.0-original.html`
- view.php prod pre-fix: `/tmp/tp-prod-backup-20260505-191621-view-fix/view.php`
- BD prod snapshot 2026-05-05: `/tmp/aula-clinic-stack/local-test/database-prod-doc.sqlite`

### Cara-cliente checklist (regla absoluta sigue intacta)

- ✅ Doc Aula Clínic NO menciona a Dani ni a "partner técnico" en ningún sub-bloque.
- ✅ Frase "primera aproximación valoramos un CMS" introduce el a medida sin nombrar quién hizo el presupuesto previo.
- ✅ E170386 descripción no menciona subcontratación.
- ✅ Costes Dani solo aparecen en CLAUDE.md y memoria interna.

---

## ✅ DESPLEGADO 2026-05-06 · UI "Nuevo mensaje" staff→proveedor + 13 borradores Gibobs + 3 fixes UX portal proveedor

> Sesión completa cerrando el flujo cara-cliente / cara-proveedor con Gibobs Allbanks. Detonante: Ignacio Aymat (cliente Gibobs) había dejado 13 comentarios en el doc funcional v1.9 y Jordi necesitaba trasladarlos a Dani (Truman) en su portal proveedor sin que Dani viera identidad ni copy del cliente. Antes solo se podía responder a hilos que abriera el proveedor — no había forma de iniciar hilos staff→proveedor desde TP. Construido y desplegado el primitivo que faltaba.

### Commits live (orden cronológico)

1. **`cf94be5`** — `feat(providers): UI 'Nuevo mensaje' staff→proveedor + firma personalizable`
2. **`7875cf8`** — `fix(provider-feedback): drawer global · ocultar form sin sección + click en hilo lleva a su sección`
3. **`4a6bb75`** — `fix(provider-feedback): eliminar bloque 'Responder como Tres Puntos' duplicado`

### Bundle 1 · Endpoints + UI "Nuevo mensaje" (`cf94be5`)

**Endpoints nuevos**:
- `POST admin_providers.php?action=new_thread_to_provider` — body `proveedor_id`, `texto`, `section_anchor` (opc), `section_title` (opc), `autor_nombre` (default `'Tres Puntos'`), `as_draft` (1/0). Valida proveedor activo, max 4000 chars, autor max 80.
- `POST api/proposals.php?id=PROVEEDOR_ID&action=provider_new_thread_draft|publish` — body JSON `{texto, section_anchor, section_title, autor_nombre}`. Schema (`?action=schema`) actualizado para que los agentes IA descubran el endpoint. Telegram alert al publicar.

**UI en `admin_providers.php?proveedor_id=X`** — botón "Nuevo mensaje" arriba del bloque MENSAJES + modal:
- Dropdown de sección con las secciones donde el cliente ha comentado + opción "General" + opción "Otra sección" (anchor + título manuales).
- Selector "Insertar cita literal de un comentario del cliente" → al elegir uno inserta automáticamente bloque `🗣️ Comentario del cliente en [sec X.X]: "literal" — Autor` + `💭 Nuestra lectura:` y ajusta la sección al comentario elegido. Lista solo comentarios cliente abiertos (filtra staff/draft).
- Textarea con counter `0 / 4000`.
- Campo **"Firmar como"** (default `Tres Puntos`, editable a `Claudio` / `Jordi` / cualquiera). Esto desbloqueó tener al asistente IA con identidad propia en cara-proveedor.
- Botones Cancelar / Guardar borrador / Publicar ya. Esc cierra.

**Render de hilos staff-iniciados**:
- Antes `mRoots` filtraba `autor_tipo NO-staff`; ampliado para incluir hilos iniciados por TP (publicados + drafts).
- Header del hilo con chip mint **"TP → PROVEEDOR"** para diferenciarlos visualmente de hilos abiertos por el proveedor.
- Mismas pills (Borrador / Sin avisar / Notificado) y botones Publicar/Editar/Descartar que los replies staff.

**Email de notificación adaptativo** en `notify_provider_replies`:
- Detecta si los pendientes son hilos iniciados por TP o respuestas a mensajes del proveedor y ajusta el copy del intro:
  - Solo TP-iniciados → "Te hemos dejado N mensajes con dudas y contexto en el portal de proveedor de [cliente]."
  - Solo respuestas → copy clásico "Hemos respondido a N comentarios que dejaste."
  - Mezcla → "Tienes N novedades (entre respuestas a tus mensajes y dudas que te trasladamos)."
- Si el root es staff (sin pregunta previa del proveedor), omite el div italic con cita vacía `""`.

**Fix · render del autor staff respeta `autor_nombre`**:
- `master/doc-feedback-provider.php` deja de hardcodear `'Tres Puntos'` para `autor_tipo='staff'`. Si el INSERT guardó `Claudio` (o cualquier custom), se respeta y aparece así en el portal del proveedor con la pill **EQUIPO**. Fallback a `Tres Puntos` si vacío.
- `admin_providers.php` replies: misma corrección.

**Sin migración BD** — usa el schema existente: `parent_id NULL` = root, `autor_tipo='staff'`, `autor_nombre TEXT`. 100% compatible con datos previos.

### Bundle 2 · Drawer global del proveedor: oculta form sin sección + click en hilo lleva a su sección (`7875cf8`)

**Bug detectado** por Jordi en directo viendo el portal Dani: en el drawer "Comentarios del documento · Todas las secciones" aparecía el form "Escribe tu comentario sobre esta sección…" abajo. Al pulsar Enviar saltaba un alert pidiendo elegir sección — UX rota: el usuario rellena un form que no funciona.

**Fix en `master/doc-feedback-provider.php`**:
- Vista global (`state.currentAnchor=null`): form se oculta con `display:none`, aparece un hint mint con icono `mouse-pointer-click` al pie: *"Pulsa un comentario para ir a su sección y responder allí."* Markup: nuevo `<div id="tp-pv-drawer-hint">`.
- Vista filtrada por sección: form se mantiene visible y funcional (no toca nada).
- `applyDrawerFormState()` centraliza la decisión, llamada desde `renderDrawer` y `openDrawer`. Si vista global, `openDrawer` no hace `focus()` al textarea.

**Click en hilo → scroll + modal de sección**:
- `wireThreadClickToSection('drawer')` cablea cada `.tp-thread` del drawer global como clickable (cursor:pointer + hover mint sutil + tooltip "Pulsa para ir a esta sección y responder").
- Click no propaga si pulsas botones internos (eliminar, etc.).
- `goToSectionFromDrawer(anchor, title)` cierra drawer, scrollea suave a la sección con flash mint de 1.2s para confirmar dónde aterrizaste, y abre el modal de la sección — donde sí hay form contextualizado "Firmas como Dani · Escribe tu comentario sobre esta sección…".

### Bundle 3 · Eliminar bloque "Responder como Tres Puntos" duplicado (`4a6bb75`)

Detectado en directo: cuando entras con `__admin_view=1`, dentro de cada hilo aparecía un bloque morado "Responder como Tres Puntos" + textarea + botones Cancelar/Enviar. Aparecía tanto en drawer como en modal de sección, y duplicaba con el form principal del proveedor justo abajo (parecía caja para escribir, pero al pulsar abría el otro form). Confundía sin aportar.

**Decisión**: eliminar `adminReplyBlock` por completo en `renderThread`. Ya tenemos:
- `admin_providers.php` para responder a hilos del proveedor (con drafts + pills + email notify).
- Botón "Nuevo mensaje" del Bundle 1 para abrir hilos staff→proveedor.

Las funciones `wireAdminReply` quedan no-op (no encuentran elementos) — sin riesgo, se dejan por compatibilidad hasta limpieza más amplia.

### Datos creados en prod (13 borradores Claudio en Gibobs)

Usando el endpoint API nuevo `provider_new_thread_draft`, script `/tmp/seed-dani-gibobs-drafts.py` creó 13 borradores firmados **`Claudio`** para `proveedor_id=6` (Dani · Truman, Gibobs Allbanks):

| ID | Sección | Tipo |
|---|---|---|
| 15 | 1.1 Situación actual | Pregunta técnica (Wp Rocket vs Cookiebot) |
| 16 | 1.2 Objetivos estratégicos | Verificar redirects 301 subdominios |
| 17 | 1.3 Alcance técnico (blog) | Cómo prefiere import (slugs/fecha/ID) |
| 18 | 1.3 Alcance técnico (simuladores) | Fusionar 2 simuladores |
| 19 | 2.1 Estructura URLs | GTM scope dev (versión aligerada) |
| 20 | 2.3 Subdominios | FYI |
| 21 | 4.1 Simuladores | 3er simulador mihipoteca |
| 22 | 4.3 Tramos precio | FYI content-pruning |
| 23 | 5.1 Inmobiliarias | FYI validación cliente |
| 24 | 6.3 Landings | FYI tarea SEO interna |
| 25 | 8.3 UTMs sin cookies | Vinculado al hilo 15 |
| 26 | 9.2 Multiidioma | Plugin multilang + workflow |
| 27 | 9.4 Core Web Vitals | Plugins caché (WP Rocket+Perfmatters) |

Cada borrador con formato: `🗣️ Comentario del cliente en [sec]: "literal" — Ignacio` + `💭 Nuestra lectura: [opinión TP]`. Tono: técnico, cercano, partner mode. Pendiente que Jordi repase/publique/notifique en `admin_providers.php?proveedor_id=6`.

### Sincronización git histórica recuperada

Detectado durante el deploy: el remote `origin` apuntaba a `https://github.com/trespuntoslab/documento-funcional-.git` que devolvía 404 desde hacía tiempo. **62 commits estaban en prod pero no en GitHub** (commit más antiguo sin push: `4c89e19` portal proveedores).

Resuelto:
- Remote actualizado a `https://github.com/TresPuntos/docfuncional.git` (URL correcta confirmada por Jordi).
- Push fast-forward `8222aaa..4a6bb75` a `origin/main`. 64 commits subidos en bloque, sin conflictos.
- Rama `claude/amazing-jones-c87a44` también subida como backup.

**Estado actual** (verificado): prod = `main` = `claude/amazing-jones-c87a44` = `4a6bb75`. Espejo perfecto.

### Test E2E con Playwright (16 checks · pasaron todos)

1. Login admin local + navegación a vista detalle Dani Gibobs
2. Modal "Nuevo mensaje" abre con todos los componentes
3. Bug `hidden` con `display:grid` arreglado (custom-row inputs ocultos por defecto)
4. 13 comentarios cliente en dropdown de cita
5. Insertar cita auto-rellena bloque + ajusta sección
6. Counter de caracteres
7. Firma "Claudio" persiste tras submit
8. Borrador → BD → UI con pills "BORRADOR"
9. Publicar borrador → pill "SIN AVISAR" + banner email
10. Descartar borrador → DELETE BD
11. Modo "Otra sección" muestra inputs custom
12. Portal proveedor (Dani) ve "Claudio · EQUIPO" (no "Tres Puntos")
13. Drawer global oculta form + muestra hint
14. Click en hilo del drawer → scroll + modal contextualizado
15. Bloque morado "Responder como TP" eliminado en drawer y modal
16. Smoke prod tras cada deploy: 5/5 propuestas (h2b · aula · gibobs · b2b · nextica) HTTP 200 con 0 errores PHP

### Backups disponibles (rollback)

- `/tmp/tp-prod-backup-20260506-220654-new-thread-ui/` (pre-Bundle 1: admin_providers.php + api/proposals.php + master/doc-feedback-provider.php)
- `/tmp/tp-prod-backup-20260506-224309-drawer-fix/` (pre-Bundle 2)
- Pre-Bundle 3 sin backup (cambio mínimo, rollback vía `git revert 4a6bb75` + FTP push del archivo del worktree).

### Lecciones aprendidas

1. **Construir la primitiva > el hack**. La opción "script temporal de un solo uso" para meter 13 borradores cuesta 10 min hoy + 10 min cada vez que se repita el caso. Construir el endpoint + UI cuesta 60 min hoy y 0 min después. Para casos que se van a repetir (Aula con Truman, futuras propuestas con varios proveedores), merece la pena siempre.
2. **Dos fuentes de verdad para `autor_nombre`**: el INSERT en `proveedor_mensajes` permite cualquier string, pero el render hardcodeaba `'Tres Puntos'` en 3 sitios. Cuando se permite identidad personalizable, hay que auditar todos los renders, no solo el INSERT.
3. **Bug `hidden` aplastado por `display:grid|flex`**: documentado ya en CLAUDE.md de sesiones anteriores (master/doc-feedback.php). Se repitió en el modal Nuevo mensaje. **Regla**: si el contenedor lleva `display:grid|flex`, no usar atributo HTML `hidden` para ocultarlo — usar `style="display:none"` directamente y manipular `style.display` en JS.
4. **`origin/main` no es source of truth**: estaba 62 commits atrás de prod sin que nadie se diera cuenta. Confiar en prod (FTP) como source of truth y `main` como su espejo, no al revés. El protocolo de deploy en CLAUDE.md (sección 🔁) ya lo dice — la incidencia recordó por qué importa pushear *cada* deploy.
5. **Email transaccional firmado por agente**: los hilos creados firmados como "Claudio" pero el footer del email de notificación lleva firma "Jordan" del template estándar. Inconsistencia conocida — Dani lo entiende (ambos son asistentes IA de TP), pero unificar es mejora pendiente.

### Pendientes para próxima sesión

- **Bug toggle `ver_comentarios` cosmético** — el flag se guarda y se lee, pero `view.php` (que es lo que ve Dani en `__provider` mode) no consulta `comentarios_seccion` ni mira el flag. Solo `provider.php` lo respeta y ese código está muerto (redirect a view.php antes de renderizar). Fix: cargar comentarios cliente cuando `$isProviderMode && $__provider['ver_comentarios']` y renderizarlos read-only inline (junto a cada H2) o como bloque al final. Confianza estimada: 95% si bloque al final, 85% si inline.
- **Unificar firma email transaccional** — opciones: (a) que el template `tp_render_email_layout` reciba `signer_name` y lo respete; (b) que cuando el origen sea hilo staff, derive el firmante del `autor_nombre` del último mensaje publicado.

---

## ✅ DESPLEGADO 2026-04-27 (tarde) · H2B v1.7 · Stack Fase 2 cerrado (Angular + Laravel)

> Sesión corta tras la del proveedor. Trasladados al doc cliente todos los acuerdos técnicos derivados de los hilos con Dani sin nombrarlo (regla cara-cliente). Solo BD, sin código. v1.6 archivada en `propuestas_history` (history_id=35).

### Cambios aplicados a v1.7 (vía API REST `PUT id=21 save_version=true`)

1. **Cabecera + Novedades v1.7** — nuevo callout success arriba del de v1.5 (que pasa a info, registro histórico).
2. **Resumen ejecutivo** — frases "stack abierto" / "Fase 2 (a definir)" reemplazadas por "Angular + Laravel" en el lead, badge stat (`WordPress · Angular · Laravel`), card Fase 2 (`Angular + Laravel`), índice (`H · Stack tecnológico — WordPress (Fase 1) + Angular y Laravel (Fase 2)`).
3. **A8.b — Reparto técnico de la integración** (NUEVO bloque + `tp-comparison` 2 col):
   - Lo que hace la web: detección click ID → cookie → mapeo procedencia → envío al CRMGO en payload.
   - Lo que hace el CRM: ejecuta postback a Awin/Tradedoubler. **La web no llama nunca a la API de Awin/Tradedoubler.**
   - Mención explícita del Swagger de CRMGO documentado.
4. **A9.5 — Multiidioma técnico de Fase 2** (NUEVO bloque dentro de SEO técnico, `tp-grid` con 6 cards):
   - i18n nativo SPA · prioridad usuario→cookie→navegador→fallback ES.
   - Política de fallback ES si CA/EN vacíos.
   - 3 plantillas por evento transaccional.
   - Widget embeddable trilingüe vía API key/param URL.
   - Implicación de presupuesto: no ×3 desarrollo, sí ×3 contenido + QA.
5. **H.2 — Stack cerrado · Angular + Laravel** (refundido completo):
   - Quitada comparativa Angular vs React.
   - Nueva `tp-comparison` Front Angular / Back Laravel con detalles técnicos.
   - Sub-bloque "Cómo lo entrega Tres Puntos" reforzando: ciclo completo TP, único interlocutor, mismo modelo que Fase 1 con WordPress.
6. **I.3 / I.4** — Stack Fase 2 movido de pendientes a cerrados. Añadidos: catálogo de eventos transaccionales (pendiente), multiidioma técnico Fase 2 (cerrado), reparto integración web↔CRM (cerrado).

### Regla nueva guardada en CLAUDE.md (sección ⛔ arriba) y memoria

Nada de fórmulas tipo "partner técnico" / "partner de back-end" en cara-cliente. **El relato es siempre el mismo en cualquier stack** (WordPress, Angular, Laravel, lo que sea): Tres Puntos hace UX + UI + desarrollo end-to-end. Inputs externos (subcontratas, especialistas) son invisibles para el cliente.

### Backups disponibles (rollback)

- v1.6 H2B en `propuestas_history` (id=35, archivada 2026-04-27 18:40:24) — restaurable con `restore_version`.
- HTML local de v1.7: `/tmp/h2b-v17.html` (123 KB / 2000 líneas).
- Payload PUT: `/tmp/h2b-v17-payload.json`.

### Smoke test

- `GET /p/h2bhipotecas` → HTTP 200 · 170 ms · sirve v1.7.
- `GET /api/proposals.php?id=21` → `version: v1.7`, html length 123414.

### A Dani no se le ha respondido el hilo #4

Por instrucción explícita del usuario: ya ha hablado con él directamente. No se redacta borrador en `proveedor_mensajes` para `parent_msg_id=7`. El hilo queda abierto en la BD pero no requiere acción.

---

## ✅ DESPLEGADO 2026-04-27 · Hilo proveedor end-to-end + H2B v1.6

> Sesión completa centrada en cerrar el flujo de mensajería con proveedores (Dani · Truman) y depurar el alcance de H2B. 4 commits en `main`, 0 errores en prod. Backups en `/tmp/tp-prod-backup-20260427-*/`.

### Commits live (orden cronológico)

1. **`95167fa`** — `fix(proveedor): gate contratos scoped a propuesta + IDs drawer feedback`
2. **`edca8d6`** — `feat(api): endpoints REST para gestionar mensajes de proveedores`
3. **`3d87914`** — `feat(providers): UI de drafts staff + botón notificar a proveedor por email`
4. **(BD)** H2B Hipotecas v1.5 → **v1.6** vía REST API (`PUT save_version=true`). v1.5 archivada en `propuestas_history`.

### Bundle 1 · Aislamiento del gate de contratos por propuesta (`95167fa`)

**Bug detectado**: el link al portal proveedor de Aula Clinic redirigía a Dani al contrato Truman/Cardalis (que estaba vinculado a Aula Clinic), bloqueándole el acceso al documento funcional para comentar.

**Fix**: en `provider.php` (los 2 puntos del flujo, POST PIN + GET con sesión), el gate de contratos pendientes ahora filtra por `AND propuesta_id = ?`. Un contrato vinculado a propuesta X solo bloquea el acceso al token de propuesta X.

**Bug colateral arreglado**: en `master/doc-feedback-provider.php` los 4 IDs del bloque "identity-compact" del drawer no llevaban el scope `-drawer-` que `applyIdentityState('drawer')` esperaba → `null.textContent` al comentar desde el modal con identidad guardada. Renombrados a `tp-pv-drawer-identity-{compact,name,change,fields}` + listener actualizado.

### Bundle 2 · API REST para mensajes de proveedores (`edca8d6`)

**Justificación**: hasta ahora `proveedor_mensajes` solo era accesible desde la UI admin con sesión PHP. No había forma de revisar / responder mensajes de proveedores vía API (Claude.ai vía MCP, agentes externos).

**7 acciones nuevas en `api/proposals.php`** (paridad con flujo de comentarios cliente, contra `proveedor_mensajes`):

| Acción | Método | `id=` | Función |
|---|---|---|---|
| `provider_messages` | GET | propuesta_id | Lista proveedores + sus hilos. Optional `proveedor_id=N`, `include_drafts=1`, `status=open\|closed\|all` |
| `provider_reply_draft` | POST | parent_msg_id | Crea respuesta staff como borrador (`is_draft=1`) |
| `provider_reply_publish` | POST | parent_msg_id | Crea respuesta staff publicada + ping Telegram |
| `provider_publish_reply` | POST | reply_id | Publica un borrador existente. Body opcional `texto` para editar antes de publicar |
| `provider_discard_reply` | POST | reply_id | Borra un borrador staff |
| `provider_resolve` | POST | root_msg_id | Toggle `resuelto` 0↔1 |
| `provider_notify` | POST | propuesta_id | Marca `notificado_at` en respuestas staff publicadas |

Schema (`?action=schema`) actualizado con las 7 acciones para que los agentes IA conectados las descubran.

### Bundle 3 · UI de drafts + email de notificación a proveedor (`3d87914`)

**Justificación**: el flujo cliente tiene drafts visuales + botón "📢 Avisar nueva versión" con email Resend. Para proveedor faltaba paridad — cuando respondías a un proveedor no se le mandaba ningún email, solo veía la respuesta si entraba al portal `/s/{token}`.

**`admin_providers.php?proveedor_id=X` ahora tiene**:

- **Drafts staff visibles inline** dentro de cada hilo, con borde amarillo + badge "Borrador" + 3 botones: `Publicar` · `Editar` · `Descartar`. La query del detalle ahora incluye `is_draft=1` (antes filtraba a solo publicadas).
- **Pills de estado** en cada respuesta staff: `Borrador` (amarillo) · `Sin avisar` (rojo) · `Notificado` (verde con tooltip de fecha).
- **Form de respuesta con doble botón**: `Guardar borrador` y `Responder y publicar`.
- **Banner amarillo** arriba de "Mensajes" si hay respuestas staff publicadas sin notificar → CTA "📢 Avisar a {nombre} por email".
- **Modal de notificación** con textarea opcional para mensaje extra antes del listado.
- **Email Resend** con `tp_render_email_layout()` (api/contratos_lib.php · paleta print mint `#0FA36C`): preheader, "Hola {firstName}", intro extra opcional, **resumen visual de las N respuestas** (sección + extracto pregunta + extracto respuesta), CTA "Revisar respuestas en el portal →" hacia `/s/{token}`. Marca todas como `notificado_at` tras envío exitoso.

**5 endpoints nuevos** en `admin_providers.php` (POST):
- `reply_to_provider_msg` (modificado · acepta `as_draft=1`)
- `update_draft_provider_msg`
- `discard_draft_provider_msg`
- `publish_draft_provider_msg`
- `notify_provider_replies`

### Bundle 4 · H2B Hipotecas v1.6 — retirado el panel admin a medida

**Detonante**: Dani (Truman) dejó 3 mensajes en H2B preguntando, entre otras cosas, sobre "Contenido editorial" dentro del panel admin de Fase 2. Análisis cruzado con los 12 hilos cerrados de Eloi (cliente) confirmó que **el cliente NO había pedido el panel /admin/ a medida** — fue propuesta nuestra (yo, en sesiones anteriores enriqueciendo el doc).

**Análisis honesto del solapamiento**:
- WordPress + ACF + Gutenberg + librería de bloques (Fase 1) ya da autonomía total al equipo H2B para gestionar páginas, blog, FAQ, tarifas, equipo, oficinas, agencias, productos hipotecarios.
- CRMGO ya gestiona toda la operativa comercial de leads (asignación, estados, notas, seguimiento). Eloi confirmó en hilo #5 que NO querían duplicarlo.
- GA4 + Search Console ya dan métricas web.
- Construir un panel paralelo replicaba esas tres herramientas sin aportar valor diferencial.

**Decisión tomada con el usuario**: retirar el bloque D entero, sin sustituto a medida. Conservar las 2 piezas que sí tenían valor propio (Euríbor automático + scoring IA), referenciadas donde corresponden.

**Cambios aplicados al doc** (vía API `PUT id=21 save_version=true`):
- Cabecera v1.5 → v1.6
- Resumen ejecutivo: eliminada línea "Panel admin completo (15 módulos)"
- Índice: eliminada entrada "D · Panel admin"
- Tabla resumen Fase 2: eliminada fila "Panel de administración"
- Sitemap: eliminado nodo `/admin/`
- Bloque D entero (~8.500 chars de tablas con 15 módulos) sustituido por **nota explicativa** que documenta el cambio + 2 cards conservando Euríbor automático + scoring IA
- Multiidioma: "gestión desde panel admin" → "gestión desde WordPress"
- Bloque G: alcance Fase 2 sin "admin completo"; entregables sin "panel admin a medida"; "7 calculadoras" → "8 calculadoras"
- Bloque H: card "Panel admin a medida" → "Administración nativa de WordPress"; eliminado bullet "el panel /admin/ absorbe y amplía"; decisión técnica Fase 2 sin mención al bloque D
- Bloque I.4: eliminada línea "Módulos del panel admin (15 módulos a medida, sin duplicar CRM)"
- Comentarios de scoring: "Dentro del panel de admin de la web" eliminado
- Entregables Fase 1: "panel básico de edición" → "admin nativo de WordPress configurado a medida"

**v1.5 archivada** en `propuestas_history` (history_id consultable). Restaurable con `restore_version`.

### Bundle 5 · 3 borradores publicados a Dani (vía API, sin enviar)

Tras la v1.6 subí 3 `provider_reply_draft` para los hilos #1, #2, #3 de Dani (ids 4, 5, 6 en `proveedor_mensajes`). Quedan en `is_draft=1` esperando que Jordi los revise en la UI nueva, los publique uno a uno (botón "Publicar") y le dé al banner "📢 Avisar a Dani por email".

**Resumen de los 3 borradores** (queda referencia para futuras conversaciones):
- **Hilo #1** (Panel admin Fase 2 / Contenido editorial) → "Buena cazada, lo hemos retirado en v1.6, te explico el reparto WP + CRMGO + GA4 + lo que sí se mantiene (Euríbor automático + scoring IA)".
- **Hilo #2** (8.4 Flujo del lead / API CRMGO Awin) → enlaces Swagger CRMGO + tracking Awin, reparto técnico Fase 1 vs Fase 2.
- **Hilo #3** (Resumen ejecutivo / multiidioma) → 3 idiomas en ambas fases, fallback a ES si CA/EN vacíos, implicaciones para dimensionado.

### Backups disponibles (rollback)

- `/tmp/tp-prod-backup-20260427-104905-prov-fix/` (pre fix gate + IDs drawer)
- `/tmp/tp-prod-backup-20260427-115851-prov-api/` (pre API endpoints proveedor)
- `/tmp/tp-prod-backup-20260427-130631-prov-drafts-ui/` (pre UI drafts + notify)
- v1.5 H2B en `propuestas_history` (restore vía API con `restore_version`)

### Patrón reutilizable · Email transaccional con `tp_render_email_layout`

Confirmado: el flujo de notificación a Dani usa **el mismo layout estándar** que los emails de contratos (paleta print mint `#0FA36C`, ancho 600px, tables + inline styles, CTA bulletproof Outlook, preheader invisible). Esto cumple el estándar obligatorio de la sección "ESTÁNDAR OBLIGATORIO · Plantilla de email transaccional" de este CLAUDE.md.

**Pendientes de migración al layout estándar** (siguen en HTML legacy):
- `admin_providers.php` → `sendProviderInviteEmail()` (invita proveedor)
- `admin_feedback.php` → `sendClientCommentNotification()`, `sendStaffReplyNotification()`, `sendVersionAnnouncement()`
- `provider.php` → invite email proveedor (si aplica)

Cuando se vuelva a tocar cualquiera de esos flujos, migrar a `tp_send_email()` en la misma sesión.

### Lecciones aprendidas esta sesión (2026-04-27)

1. **Verificar siempre quién pidió qué antes de aceptar un alcance grande**. La sección I.4 listaba "Módulos del panel admin (15 módulos)" como cerrado, pero NUNCA Eloi lo pidió en sus 12 hilos. Una regla limpia: si una pieza no aparece en comentarios cliente ni en el prototipo del cliente, es propuesta nuestra y hay que cuestionarla cuando solapa con herramientas que el cliente ya tiene operativas (CRMGO, WP, GA4).
2. **El sitemap del propio doc tenía la respuesta**. Cada nodo en A2 lleva etiqueta "Prototipo" (= viene del cliente) o "Tres Puntos" (= recomendación nuestra). Útil para auditar procedencia rápido en futuras revisiones.
3. **Subir el código además del cambio de BD**. Subí 3 borradores via API pero olvidé deployar la UI que los muestra → el user no veía nada. Regla: si tocas BD y código, asegúrate de deployar ambos antes de avisar.
4. **El gate try/catch de contratos en `provider.php` salvó el deploy de v1.6 del sistema de contratos**. La defensividad pagó cuando hubo que aislar el gate por propuesta — solo añadimos un filtro AND, sin riesgo de romper el flujo si las tablas no existen.

---

## 🔁 PROCESO OBLIGATORIO · Deploy a prod + commit a git

**Esta sección manda en CUALQUIER sesión futura.** Es el único flujo permitido para cambios que toquen producción.

### Requisitos previos a cualquier deploy

- Todos los cambios deben estar probados en local con `php -S localhost:8000 router.php`.
- Mostrar al usuario un resumen con: archivos tocados, efectos esperados, riesgos.
- **Esperar autorización explícita del usuario en el turno actual** con palabras como "sube", "adelante", "deploy", "subelo a real". Sin esas palabras, NO deploy.

### Pasos del deploy (ejecutar siempre en este orden)

1. **Backup prod**: descargar los archivos que voy a sobrescribir a `/tmp/tp-prod-backup-YYYYMMDD-HHMMSS-tag/`.
2. **Migraciones (si las hay)**: subir el `.php` temporalmente a `/doc/` raíz con path adaptado, ejecutar vía HTTPS, borrar inmediatamente.
3. **Upload FTP**: subir los archivos modificados con `curl -T`.
4. **Smoke tests**: `curl` a las URLs afectadas verificando HTTP 200 y ausencia de `Undefined|Deprecated|Fatal|Warning` en la respuesta.
5. **Git commit** (inmediatamente después del deploy exitoso):
   ```bash
   git add -A
   git -c user.email="jordi@trespuntoscomunicacion.es" -c user.name="Jordi (Claudio)" \
       commit -m "<tipo>: <resumen una línea>

   <detalles opcionales>

   🤖 Generated with [Claude Code](https://claude.com/claude-code)
   Co-Authored-By: Claude <noreply@anthropic.com>"
   ```
6. **Git push a main**:
   ```bash
   git push origin main
   ```
   (El credential helper `osxkeychain` gestiona el token después del primer uso.)
7. **Avisar al usuario** con el hash del commit + URLs verificadas + ubicación del backup.

### Regla de ramas

- **Push directo a `main`** por defecto. Equipo de una persona = sin fricción de PRs.
- Solo crear `feat/<nombre>` si el usuario pide explícitamente trabajar en rama, O si el cambio es experimental y queremos poder revertirlo sin tocar `main`.

### Cuándo el usuario autoriza o NO autoriza

Si el usuario dice "**prueba en local**" / "**enséñame antes**" / "**revísalo**" → trabajar solo en local, NO deploy, NO push.
Si dice "**sube**" / "**adelante**" / "**deploy**" → ejecutar los 7 pasos completos (deploy + commit + push).
Si dice solo "**haz el push**" después de un cambio local → git commit + push sin tocar prod.
Si dice "**rollback**" / "**revierte**" → re-subir los archivos del backup por FTP + `git revert <sha>` + push.

### Repo oficial

`https://github.com/trespuntoslab/documento-funcional-.git` (privado, owner `trespuntoslab`).
Branch `main` = espejo de producción.

### Credenciales

`credential.helper = osxkeychain` configurado globalmente. El PAT se guarda tras el primer uso. Si se rota el token, el siguiente push pedirá el nuevo y lo guarda de nuevo.

### Lo que NUNCA hay que hacer

- ❌ Push a `main` sin haber hecho deploy correspondiente (el repo representa lo que está live, sin excepción).
- ❌ Commit con el token o cualquier secreto dentro (config.local.php está en .gitignore).
- ❌ `git push --force` sobre `main` sin autorización explícita.
- ❌ Deploy sin backup previo.
- ❌ Deploy + olvidarse del git push (deja el repo desincronizado de prod → confusión futura).

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

## ✅ DESPLEGADO 2026-04-25 · Sistema de contratos firma electrónica eIDAS

> **Estado**: LIVE en `https://doc.trespuntos-lab.com/`. Deploy ejecutado sábado 2026-04-25 ~11:50 con ventana baja (sin clientes activos mirando). Smoke test 17/17 OK · 5 propuestas activas (Gibobs, Aula, H2B v1.5, B2B, Nextica) siguen 200 sin errores PHP.
>
> **Rama**: `feat/contratos-firma` (último commit `2a65457`). PENDIENTE merge a `main` (debe ser espejo de prod — el usuario tiene que aprobar push).

### Lo que SE desplegó (bundle aislado)

**Archivos NUEVOS en prod**:
- `api/contratos_lib.php` (923+ líneas · core lib + helpers seguridad)
- `sign.php` (URL pública `/sign.php?token=` para firmantes)
- `admin_contratos.php` · `admin_plantillas.php` · `provider_contrato.php`
- `master/admin-breadcrumb.php` · `master/brand/logo-print.{svg,png}`
- `vendor/` (mPDF 8.3.1 + FPDI · 819 archivos · subido como zip + extract via PHP one-shot)
- `composer.json` · `composer.lock`
- `uploads/contratos/.htaccess` · `uploads/contratos_plantillas/.htaccess` (Deny all)

**Archivos MODIFICADOS en prod**:
- `provider.php` — gate try/catch para contratos pendientes + `session_regenerate_id`
- `config.php` — defaults defensivos para constantes TP_* y SIGN_*
- `master/admin-sidebar.php` — entradas Contratos / Plantillas

**Migraciones BD aplicadas** (en orden, desde `database/`):
1. `migrate_contratos.php` — 4 tablas base
2. `migrate_contratos_signing_token.php` — col `signing_token`
3. `migrate_contratos_hardening.php` — `otp_hash`, `otp_attempts`, `otp_last_attempt_at`, `signing_token_expires_at`
4. `seed_contratos.php` — 5 plantillas
5. `update_nda_fiscal.php` — bloque "Identificación de las partes" en NDA

**`config.local.php` del server** se editó añadiendo 16 constantes TP_* y SIGN_* (datos legales TP + política firma).

### Estado BD post-deploy
- `contratos_plantillas`: 5 (NDA, MSA, SOW, DPA, Change Order)
- `contratos`: 0 · `contratos_firmas`: 0 · `contratos_eventos`: 0

### Hardening incluido (auditoría previa al deploy)

**8 BLOCKERS arreglados** (ver commit `36304b5`):
1. OTP plaintext → SHA256 + invalidación post-uso + rate-limit 5 intentos / 30s cooldown
2. Race condition firma → transacción atómica en `sign.php` y `provider_contrato.php`
3. XSS stored vía plantilla HTML → `tp_sanitize_template_html()` strip script/iframe/on*/javascript:
4. CSRF ausente → tokens scope per-página en `admin_contratos` + `admin_plantillas`
5. Regex token inconsistente → unificado `{24,48}` en `provider_contrato.php`
6. Modificadores template `|upper|lower|money|date` no escapaban → ahora siempre `htmlspecialchars`
7. `exec()` TSA con `$hash` no validado → `ctype_xdigit + strlen===64` previo
8. Scroll/5s bypass client-side → validación server-side: `signing_duration_ms ≥ 3000` + `scroll_depth_pct ≥ 70`

**10 WARNINGS arreglados**:
- IP real: `REMOTE_ADDR` por defecto, headers proxy solo si `SIGN_TRUST_PROXY_HEADERS` (no activada en Hostinger)
- `signing_token` con expiry validado al firmar
- PDF upload: `finfo_file` + magic bytes `%PDF-`
- Trazo base64: tamaño + magic bytes PNG validados
- Máquina de estados contratos (`send_to_signer`/`delete_contrato` bloquean según estado)
- `session_regenerate_id` tras PIN proveedor
- mPDF tempDir explícito (`uploads/mpdf_tmp/`)
- `update_nda_fiscal.php` idempotente + guard por proveedor_cif/título Truman
- `migrate_contratos_signing_token.php` guard SQL (avisa si tabla no existe)
- FPDI declarado explícito en `composer.json` (era transitivo)

### Scripts de deploy en `scripts/deploy/` (reusables para futuros deploys)
- `backup-prod.sh` · descarga FTP los archivos a sobreescribir + BD
- `deploy-contratos.sh` · pre-checks PHP + vendor.zip + bundle + migraciones + smoke
- `update-config-local.sh` · descarga config.local, añade constantes idempotentemente, valida sintaxis, sube
- `rollback.sh` · restaura backup + borra archivos NUEVOS (BD aditiva no se toca por defecto)
- `smoke-test.sh` · 17 checks (regresión sistema viejo + URLs nuevas de contratos)

### Backup pre-deploy
`/tmp/tp-prod-backup-20260425-114425/` (provider.php, config.php, master/admin-sidebar.php, database.sqlite 2.5MB con 7 propuestas).

### Test E2E con Dani (Truman) · EN CURSO

Caso de uso: "Contrato subcontratación · Truman Digital · Cardalis" (mantenimiento aplicación Cardalis cliente final Emotion Gallery).

**Datos del contrato**:
- TP: jordi@trespuntoscomunicacion.es / 52407613C / Founder & DXM
- Truman: B13750906 / Calle Pintor Renau 17, Torrent (Valencia) / dani@truman.es
- Representante Truman: **Dani Marquina** (Apoderado) · DNI lo introduce al firmar (queda en certificado eIDAS)
- Tarifa: básico 450€ / avanzado 750€ / hora adicional **30€** (subido de 25€ original)
- Plan: 6h básico / 12h avanzado · IVA aparte

**PDF preview generado** en `~/Downloads/Contrato-Subcontratacion-Truman-Cardalis-tarifa30.pdf` (90KB · paleta light TP + logo mint #0FA36C).

**Modo de envío elegido**: PDF directo (NO desde plantilla) — el user prefiere subir el PDF ya hecho. El sistema añade hoja de audit trail eIDAS al final con FPDI.

**Dani existe como proveedor en BD prod**:
- `propuesta_proveedores #4` · prop H2B Hipotecas (no usar — Eloi activo)
- `propuesta_proveedores #5` · prop Aula Clinic (recomendado para test)

**Flujo del test cuando se ejecute**:
1. https://doc.trespuntos-lab.com/admin_contratos.php → Nuevo contrato → tab **"Subir PDF directo"**
2. Subir el PDF de `~/Downloads/Contrato-Subcontratacion-Truman-Cardalis-tarifa30.pdf`
3. Título: `Contrato subcontratación · Truman Digital · Cardalis`
4. Tipo: `NDA` · Requiere OTP: `No`
5. Vinculado a propuesta: **Aula Clinic** (NO H2B — Eloi activo)
6. Contraparte: **Dani · Truman** (#5)
7. Firmantes: Contraparte (1º) + TP (2º)
8. Crear → entra al detalle del contrato (anotar el `contrato_id`)
9. Panel "Enviar al firmante" → confirmar `dani@truman.es` → click **Enviar al firmante**
10. Dani recibe email Resend con CTA "Firmar contrato →"
11. Dani firma en `/sign.php?token=` — modo PDF directo: ve el PDF en iframe, espera 5s tras carga (no se puede medir scroll dentro de iframe), rellena nombre + email + DNI/NIE (validado server-side) + cargo, dibuja firma, acepta cláusula eIDAS
12. Estado → `firmado_parcial` + Telegram alert al grupo Mesa 3P
13. Jordi firma como TP en `admin_contratos.php?contrato_id=N` → botón **"Firmar como TP"** inline (canvas + cláusula)
14. Estado → `firmado` + PDF final con FPDI: PDF original + hoja audit trail eIDAS al final (14 campos firmados con SHA-256 + sello tiempo Freetsa)
15. Ambos descargan el PDF firmado desde sus respectivos paneles

**Diferencias modo PDF directo vs plantilla**:
- Cuerpo del contrato: se mantiene EXACTO como el PDF que subes (FPDI apila páginas tal cual). La hoja de audit trail eIDAS se añade SOLO al final.
- Validación scroll: client-side desbloquea a los 5s tras carga del iframe (no se puede medir scroll cross-origin). La validación server-side `signing_duration_ms ≥ 3000` sigue activa.
- DNI representante en cuerpo: si el PDF subido dice `[DNI pendiente]`, el cuerpo lo conserva así. El DNI real lo capta el certificado eIDAS adjunto. Para deja DNI en cuerpo: regenerar el PDF con su valor antes de subir.

---

## ✅ DESPLEGADO 2026-04-25 (segunda tanda) · Tasks · UX fixes · Autocomplete proveedores · Gibobs v1.8

> Misma fecha que el sistema de contratos pero deploy posterior (~12:50–13:30). Todo LIVE, smoke 5/5 OK, main sincronizado en `9190326`.

### Bundle 1 · Tasks accionables del cliente
**Commit `92c9223`** (en feat/contratos-firma) → merge a main `bbf4e64`

- **`database/migrate_tasks.php`** · tabla `propuesta_tasks` (key, titulo, asignado, completado, signer, comentario · UNIQUE(propuesta_id, task_key)).
- **`master/doc-tasks.php`** · UI cards con barra progreso + modal "Marcar completada" con compact identity (no pide nombre/email si hay sesión PIN).
- **`view.php`** · 2 endpoints nuevos:
  - `tasks_sync` · UPSERT idempotente de tareas declaradas en HTML
  - `task_complete` · transacción atómica + Telegram alert al grupo Mesa 3P
- **Markup que el admin pone en html_content de la propuesta**:
  ```html
  <div class="tp-tasks">
    <div class="tp-task" data-task-key="acceso-ga4"
         data-task-title="Acceso a Google Analytics 4"
         data-task-assigned="Equipo de marketing">
      <h3>Acceso a Google Analytics 4</h3>
      <p>Necesitamos acceso de Lectura...</p>
    </div>
  </div>
  ```

### Bundle 2 · Sidebar fix
**Mismo commit `92c9223`**

- `view.php` línea 3182: cambio de `low === 'documento funcional'` a `low.startsWith('documento funcional')` para skipping del título del doc en la nav.
- Antes "Documento Funcional · v1.X" salía como entrada navegable. Ahora oculto.

### Bundle 3 · Comentarios sin apellidos (login solo captura nombre+email)
**Mismo commit `92c9223`**

Server (`view.php`):
- `$readSigner` ahora cae a identidad de sesión (`$visitorIdentity` / `$__provider`) si no hay POST.
- Nueva flag `valid_lite` (nombre+email) para comentarios y tareas.
- Firma legal sigue requiriendo `valid` (nombre+apellidos).
- Verificación autoría en edit/delete/resolve usa **email match** (más fiable que nombre+apellidos).

Front (`master/doc-feedback.php`):
- Quitados los inputs `apellidos` del modal y drawer.
- Fix bug `.row { display: grid }` que pisaba el atributo HTML `hidden` → ahora se usa `style.display = 'none'`.

### Bundle 4 · Autocomplete proveedores existentes
**Commit `9190326`** (directo en main · post-merge)

- Nuevo endpoint `GET admin_providers.php?action=search&q=X&propuesta_id=Y`:
  - Devuelve proveedores únicos por email (DISTINCT email) con su último nombre/empresa
  - Por cada email: `num_propuestas`, `in_current`, `revoked_in_current`
  - LIKE en nombre/empresa/email · LIMIT 8
- Form "Invitar proveedor" con caja de búsqueda arriba del form:
  - Debounce 220ms · dropdown con resultados
  - Click pre-rellena nombre/empresa/email
  - Chip lateral: "en N propuestas" / "ya en esta propuesta" (rojo, bloquea submit) / "revocado en esta" (amarillo)
  - Validación on-blur del email manual contra duplicados
- Modelo BD intacto (una fila por propuesta+email, mantiene tokens scoped per-propuesta para revocación granular).

### Bundle 5 · Gibobs v1.8 enriquecido vía API REST (no en código)
- **A1.1 Situación actual** → 4 stats numéricos (84 URLs / 24 a revisar / 3 subdominios / ⚠ Core Web Vitals) + bloque "Otras señales".
- **A8.2 Flujo del lead** → `tp-mermaid` flowchart Usuario→Form→API→Token→Plataforma.
- **A4.1 Simuladores** → 2 feature cards (Simulador hipotecas / Simulador préstamo promotor) con animaciones scoped (`gb-anim-stagger` IntersectionObserver entrada + `gb-card-hover` lift al pasar el ratón).
- v1.6 → v1.7 → v1.8 archivadas en `propuestas_history`. Restaurable con `restore_version`.

### Backups disponibles (rollback)
- `/tmp/tp-prod-backup-20260425-114425/` (pre-contratos)
- `/tmp/tp-prod-backup-20260425-124334-tasks/` (pre-tasks)
- `/tmp/tp-prod-backup-20260425-125644-comments-fix/` (pre-comments fix)
- `/tmp/tp-prod-backup-20260425-133037-providers-search/` (pre-autocomplete)
- v1.6/v1.7 Gibobs en `propuestas_history` (revertible vía API)

### Patrón de animaciones reutilizable (para cualquier propuesta)
Scoped al namespace `gb-*` para no chocar con doc-library global. Inline en el html_content de la propuesta:
- `.gb-anim-stagger > *` con IntersectionObserver añadiendo `.is-visible` al entrar viewport (transition delay escalonado)
- `.gb-card-hover` con `transform: translateY(-4px)` + borde mint en hover
- `__gbAnimInit` window guard para idempotencia
- Compatible con dark/light themes (overrides `[data-theme="light"]`)

### Próximos enriquecidos pendientes para Gibobs (cuando vuelvas a darle)
- **A8.1** Puntos de captación (1004b prosa) → `tp-grid` cards con touchpoints
- **A9.1** Schema markup (1194b prosa) → `tp-comparison` o cards Organization/Service/FAQ/Article
- **A7.1** Área funcional/app/newsletter (2004b prosa) → `tp-tabs` 3 pestañas

---

## 📦 Próximos pasos (orden recomendado para retomar)

1. **Ejecutar test E2E con Dani** (sin tocar código nuevo) — validar end-to-end del sistema de contratos en prod
2. ~~Push `feat/contratos-firma` → `main`~~ ✅ HECHO 2026-04-25 (`bbf4e64`)
3. ~~Decidir qué hacer con `view.php` y archivos `tasks`~~ ✅ HECHO — desplegados en `92c9223`
4. **Refactor sidebar admin** (Fase A original sin contratos) — deploy aparte cuando haya ventana
5. **Enriquecer Gibobs A8.1 / A9.1 / A7.1** (cuando Jordi quiera) — vía API REST con save_version
6. **Aprovechar autocomplete proveedores** para invitar Dani a más propuestas sin recrear datos

---

## 📚 Histórico · Plan de deploy original (archivado)

> Esta sección se mantiene como referencia histórica del plan que se ejecutó parcialmente. Toda la parte "PENDIENTE" ya está en prod (ver sección ✅ DESPLEGADO arriba).

**Rama original**: `feat/contratos-firma` desde `main` commit `03eb1f7` (dashboard refactor 2026-04-24).

- `feat/contratos-firma` (último commit: `07f7500`)
- Basada en `main` (actualmente `main` está 7 commits por delante de lo desplegado en prod)
- Último commit desplegado en prod: **`03eb1f7`** (dashboard refactor · 2026-04-24)

### Qué se construyó (sistema completo de contratos con firma eIDAS)

**Base de datos** (4 tablas nuevas, migración idempotente `database/migrate_contratos.php`):
- `contratos_plantillas` · `contratos` · `contratos_firmas` · `contratos_eventos`

**Librería core** (`api/contratos_lib.php` · 633 líneas):
- Template engine Mustache-lite con modificadores `|money|date|upper|lower`
- Generación PDF con mPDF 8.3.1 (vía composer) + hoja audit trail (14 campos eIDAS)
- FPDI para apilar PDFs subidos + audit trail (feature "PDF directo")
- OTP por email vía Resend (código 6 dígitos, TTL 10min)
- Sello tiempo cualificado RFC 3161 (Freetsa, opcional)
- SHA256 hashing + helpers IP/UA/GeoIP

**5 plantillas seed** (`database/seed_contratos.php`):
1. `nda-subcontratacion-tp` — NDA + subcontratación proveedor (texto íntegro 10 cláusulas del PDF Truman)
   - Actualizado 2026-04-24 con bloque "Identificación de las partes" (razón social + CIF + domicilio + representante legal)
   - 18 variables nuevas incluidas (tp_cif, tp_direccion, proveedor_cif, etc.)
2. `msa-cliente` · Acuerdo marco con cliente
3. `sow-cliente` · Statement of Work
4. `dpa-cliente` · RGPD art. 28
5. `change-order` · Modificación de alcance

**Admin** (`admin_contratos.php` · 1180 líneas):
- Lista con filtros estado + KPIs (total / pendientes / firmados / plantillas)
- Modal "Nuevo contrato" con 2 tabs:
  - **Desde plantilla** — pick plantilla → variables auto-detectadas → asignar contraparte
  - **Subir PDF directo** — upload PDF existente (contratos one-off redactados ad-hoc)
- Vista detalle: firmantes + audit trail cronológico + botón "Firmar como TP" inline con canvas
- Descargas: PDF borrador (v0) y PDF firmado (v_final)

**Editor visual de plantillas** (`admin_plantillas.php` · 628 líneas):
- Lista con: tipo, destinatario, firmantes, variables count, usos, estado
- Editor: textarea HTML + auto-detección de variables `{{xxx}}` con UI config (label, tipo, default)
- Preview PDF con datos ejemplo (icono ojo)
- Actions: editar, duplicar, archivar, borrar (solo si usos=0)
- Bump de versión automático en cada update

**Portal proveedor** (`provider_contrato.php` · 593 líneas + `provider.php` modificado):
- Gate en `/s/{token}` — antes de redirigir a `/p/{slug}` comprueba contratos pendientes
- **try/catch defensivo** (commit `07f7500`) — si las tablas no existen en prod, gate falla silenciosamente y el proveedor accede como siempre. **SEGURO para deploy antes de migrar BD.**
- Pantalla firma: renderiza HTML plantilla o PDF en iframe, scroll obligatorio 95%, canvas firma, consent eIDAS, OTP opcional
- Captura completa de los 14 campos audit
- Pantalla intermedia "✓ Tu firma registrada, esperando TP" cuando es firmado_parcial
- Generación PDF final consolidado al completar todas las firmas

**Sidebar admin** — entradas nuevas: "Contratos" (badge con pendientes) + "Plantillas"

**Datos firmante TP** (en `config.local.php` + defaults defensivos en `config.php`):
```
Jordi Expósito Lozano · 52407613C · Founder & Digital Experience Manager
Tres Puntos Comunicación S.L. · B66018490 · Calle Sant Josep 22, Barcelona
```

### Decisiones tomadas

- Solo Jordi contra-firma por TP (Jordan sin poderes notariales)
- OTP opcional por plantilla (activado cuando importe > 3.000€ según `SIGN_OTP_THRESHOLD_EUR`)
- Freetsa TSA **activado por defecto** (`SIGN_TSA_ENABLED = true`)
- Retención contratos: 6 años (Código Comercio)
- **Sin revisión legal previa** — plantillas redactadas por Jordi + Claude, no abogado

### Datos Truman ya sacados de Holded (2026-04-24)

- **TRUMAN DIGITAL S.L.** · CIF `B13750906`
- Calle Pintor Renau 17, Esc. 1 · 4º 7ª, 46900 Torrent (Valencia)
- Web original `wearetruman.es` está squat (cogió el dominio caducado un sitio SEO de juegos móviles "CCTV Rush Hour"). Web operativa: **`truman.es`**
- Email contacto: **`dani@truman.es`** (confirmado por Jordi 2026-04-25)
- Representante legal: **Dani Marquina** (Apoderado) — DNI no en Holded (Holded `email='', contactPersons=[]`)
- DNI del representante: lo introduce Dani al firmar electrónicamente (queda en certificado eIDAS adjunto)
- Tarifa hora actualizada: **30€/h + IVA** (subida de 25€ original)
- PDF preview generado en `~/Downloads/Contrato-Subcontratacion-Truman-Cardalis-tarifa30.pdf` listo para subir vía "PDF directo" en `admin_contratos.php`
- Dani existe ya como proveedor en BD prod: `propuesta_proveedores #5` (Aula Clinic, recomendado para test) y `#4` (H2B, no usar)

---

## ✉️ ESTÁNDAR OBLIGATORIO · Plantilla de email transaccional

**Regla**: TODOS los emails que salgan de la web (cliente, proveedor, notificaciones internas, OTPs, alertas, marketing transaccional, reminders) deben renderizarse con `tp_render_email_layout()` definida en `api/contratos_lib.php`.

**Nunca escribir HTML de email a pelo.** Si añades un nuevo flujo de email, lo envías con `tp_send_email()` pasando los opts del layout. Si el caso no encaja (p.ej. código OTP), pasas el bloque custom en `body_html` pero **mantienes el layout (header brand + card + footer)**.

### API

```php
// Render puro (devuelve string HTML)
$html = tp_render_email_layout([
    'preheader'    => 'Texto invisible que aparece como preview en el inbox',
    'title'        => 'Título grande en la card',
    'intro'        => 'Párrafo intro opcional (acepta HTML)',
    'highlight'    => 'Texto destacado en callout mint (ej. título doc)',
    'body_html'    => 'Cuerpo opcional con HTML libre',
    'cta_label'    => 'Firmar contrato →',
    'cta_url'      => 'https://...',
    'fallback_url' => 'https://...',  // se muestra en caja gris si el botón falla
    'footer_note'  => 'Pie legal / disclaimer',
]);

// Envío end-to-end (Resend)
tp_send_email($to, $subject, [...opts...], $replyTo = null);
```

### Reglas de diseño del layout (NO tocar)

- **Paleta**: mint print `#0FA36C` (nunca `#5DFFBF`), texto `#141414`, bg `#F7F6F3`, cards `#ffffff`
- **Ancho fijo 600px** (compatibilidad Outlook)
- **Tables + inline styles** en todo el HTML (clientes corporativos strippean `<style>`)
- **CTA bulletproof** con VML fallback Outlook + `mso-padding-alt`
- **Preheader invisible** para que el preview del inbox sea útil
- **Brand header**: "TRES PUNTOS" en mint con letter-spacing `.25em`
- **Accent stripe mint** arriba de la card (4px)
- **Firma** Jordan · Tres Puntos (asistente IA · partner cercano)
- **Footer corporativo**: razón social + domicilio + email + web, en gris

### Qué hay que migrar (TODO)

| Archivo | Email actual | Migrar a tp_send_email |
|---|---|---|
| `admin_providers.php` | `sendProviderInviteEmail()` (invita proveedor) | pendiente |
| `admin_feedback.php` | `sendClientCommentNotification()`, `sendStaffReplyNotification()`, `sendVersionAnnouncement()` | pendiente |
| `provider.php` | invite email proveedor | pendiente |
| `api/jordan-doc.php` | — (no envía) | N/A |
| `api/contratos_lib.php` | `contrato_send_invite_email`, `contrato_send_otp_email` | ✅ ya usan el layout |

Cuando toques cualquier email de los "pendientes", migra al layout estándar en la misma sesión. No dejes nada en HTML a pelo.

### Cómo añadir un nuevo email

1. En el módulo correspondiente, construye `$opts` según el tipo de email.
2. Llama `tp_send_email($to, $subject, $opts, $replyTo)`.
3. Usa `preheader` siempre (mejora apertura ~20%).
4. Si es un email con código/token (tipo OTP), mete la "caja especial" como HTML en `body_html` y **omite `cta_label`**.
5. Para alertas de pago, deadline, etc., añade icono de contexto dentro de `highlight`.

### Testing del layout

```bash
# Previsualizar el layout con datos de ejemplo:
php -r "require 'api/contratos_lib.php'; echo tp_render_email_layout(['title'=>'Prueba','intro'=>'Hola','cta_label'=>'Click','cta_url'=>'http://x']);" > /tmp/email.html
open /tmp/email.html
```

---

## 🧭 Estado actual del repo (actualizado 2026-04-24)

### En producción (main → doc.trespuntos-lab.com)

- ✅ **Sistema feedback cliente** (comentarios por sección + firma + Resend · 2026-04-22)
- ✅ **Shell admin unificado** — sidebar 272px Linear-style compartido en 4 vistas admin (2026-04-23)
- ✅ **Portal proveedores** — `/s/{token}` con PIN + email match, upload PDF, mensajes, panel admin (deploy 2026-04-23 commit `9160f2a`)
- ✅ **Login identidad upfront** — `/p/` y `/s/` piden nombre+email+PIN, sesión con `visitor_identity_*`, tracking persiste `visitor_name/visitor_email/is_internal`, admin filtra internos automáticamente (deploy 2026-04-23 commit `8735685`)
- ✅ **Beta badge** en sidebar cliente/proveedor (deploy 2026-04-24 commit `01a56af`)
- ✅ **Feedback form simplificado** — al estar identificado, el drawer/modal de comentario ya no pide nombre/email, solo textarea (deploy 2026-04-24 commit `d3269b3`)
- ✅ **Dashboard refactor · `refactoring-ui` fixes 9/10** — paleta chips 7→3 tiers (urgente/interesante/info), tabla 9 columnas→5 fusionadas (Cliente · Documento · Estado · Tráfico · Acciones), jerarquía celda cliente (nombre 16px semibold + chips stacked + metadata), dot pulsante rojo en lugar de chip "EN VIVO", KPIs con pulse solo en dot, toggle switches w-9 h-5 (de 7×4), labels tracking-widest font-bold → tracking-wider font-semibold (menos gritonas), elevation hierarchy distinta entre KPIs y tabla. Revertido Fix 4 (featured col-span-2) por feedback de Jordi. Deploy `03eb1f7` en prod 2026-04-24.

### 🔧 En rama local pendiente de merge (NO en prod)

**Rama**: `feat/sidebar-refactor` (último commit: `5825aeb`)

Cambios acumulados en esta rama (10 fixes `ux-heuristics` + feedback iterativo + bugfix iconos):

- **Sidebar reorganizado** (`master/admin-sidebar.php`):
  - Top-level: **solo Dashboard + Proveedores** (revertidos Bandeja + Analytics globales por feedback de Jordi — redundantes con los per-propuesta)
  - Search input `⌘K` con filtro instantáneo de propuestas por nombre (normalizado sin tildes)
  - Botones de acción junto al label "PROPUESTAS · N": `⇅` colapsa todas · `+` nueva propuesta
  - Nuevo sub-item **"Editar documento"** por propuesta → abre `admin.php?edit_id=X`
  - Grupos del submenu renombrados: **Gestión** (Editar · Comentarios · Analytics) · **Proveedores** · **Documento** (Abrir documento · Preview como cliente)
  - Badges de propuesta con iconos Lucide (`message-circle` + `hard-hat`) en lugar de dots de color → sin leyenda
  - Toggle "Navegador interno ON/OFF" **movido al footer del sidebar** (antes solo estaba en admin.php header)
  - Visual aligerado: iconos 13-14px, font-weight 450, active state con barra mint lateral + bg sutil (antes fill verde entero)
  - Safety net CSS con `svg.lucide` + `!important` porque Lucide reemplaza `<i>` por `<svg>` al cargar y los selectores antiguos no aplicaban (iconos salían a 24px default)

- **Nuevo `master/admin-breadcrumb.php`** reusable:
  - Breadcrumb estándar `Dashboard › Cliente › Vista` clicable en las 4 vistas admin
  - Nav ←/→ entre propuestas + dropdown "Ir a propuesta" cuando la vista es detalle (comentarios/analytics/proveedores)

- **Directorio global de proveedores** (`admin_providers.php` sin `propuesta_id`):
  - Antes: mensaje inútil "Elige una propuesta en el sidebar"
  - Ahora: grid de cards con TODOS los proveedores invitados across propuestas
  - Cada card: avatar inicial + nombre + empresa + estado activo/revocado + email + propuesta a la que pertenece + último acceso + stats (presupuestos / mensajes / accesos)
  - Search en vivo por nombre/empresa/email/propuesta
  - Hint al final anunciando próximo sprint con perfil completo (contratos, docs, fiscal)

- **Hook de sincronización** `tpSidebarRefresh()` → `admin.php toggleStatus` lo llama tras archivar una propuesta para actualizar el sidebar sin full page reload (fetch + DOM swap del `<aside>`)

- **Bugfix colateral** en `admin_analytics.php`: `$includeInternal` faltaba en el `use()` de `render_layout` → 5 warnings PHP eliminados

### 📋 Pendiente para el siguiente sprint

- **Perfil completo del proveedor** (idea conversada, no implementada): tabla nueva `proveedor_documentos` + UI de upload de contratos/NDAs + datos fiscales (IBAN, CIF, dirección) + tags. Estimado ~3h. Decidir si arrancamos después del merge de `feat/sidebar-refactor`.
- **Auditorías UX pendientes**: cuando haya cambios grandes, considerar `design:accessibility-review` (WCAG 2.1 AA — contraste mint dark/light, keyboard nav) y `design:ux-copy` (calibrar copy de error messages).
- **Tech debt**: `view.php` ~3200 líneas, `admin.php` ~2350. Extracción de componentes es deseable pero no urgente.

### Skills disponibles relevantes para este proyecto

Inventario completo en `/TRESPUNTOS-LAB/Skills/SKILLS-INVENTARIO.md`. Las que hemos usado o vamos a usar:

- ✅ `refactoring-ui` — auditoría visual del dashboard admin (2026-04-24). 9 de 10 fixes aplicados y en prod, 1 revertido (featured KPI col-span-2) por feedback.
- ✅ `ux-heuristics` — auditoría Nielsen + Krug del sidebar/navegación (2026-04-24). 10 hallazgos identificados, feedback iterativo de Jordi resultó en revertir los 2 top-level globales innecesarios (Bandeja + Analytics).
- ⏳ `engineering:deploy-checklist` — los 7 pasos ya están formalizados en la sección "PROCESO OBLIGATORIO · Deploy a prod" de este CLAUDE.md.
- 📋 `design:accessibility-review` — reservado para cuando haya refactor visual grande.
- 📋 `design:ux-copy` — reservado para calibrar error messages y copy de modo borrador.
- 📋 `engineering:tech-debt` — reservado para cuando view.php/admin.php se vuelvan inmanejables.

### Lecciones aprendidas esta sesión (2026-04-24)

1. **No sobre-diseñar navegación global cuando el usuario maneja 4 propuestas** — añadir Bandeja/Analytics top-level fue over-engineering de Nielsen H1. Para volúmenes pequeños, la navegación per-propuesta es suficiente.
2. **Lucide CSS gotcha**: `<i data-lucide>` se reemplaza por `<svg class="lucide">` al cargar. Selectores `i[data-lucide]` dejan de aplicar. SIEMPRE targetear ambos con `i[data-lucide], svg.lucide` + considerar `!important` o max-width como safety net.
3. **Emojis unicode rompen la consistencia visual** en dark admin UI. Sustituir todos por Lucide (había quedado `🏗️`/`📄` en celda Colaboradores del dashboard).
4. **Featured KPI con col-span-2** creó asimetría indeseada (la 4ª tarjeta caía a una 2ª fila huérfana). Preferible 4 KPIs uniformes + borde/color diferenciado en la accionable.
5. **Validación en local con el usuario antes del merge** funciona: identificó los 2 items globales sobrantes, el bug de empty state, y el bug de iconos — cosas que las auditorías automatizadas no capturaron.

---

## 🏗️ Portal proveedores · LIVE en prod (desde 2026-04-23)

Deploy commit `9160f2a`. Ajustes posteriores (login identidad + email validation): commits `8735685` y siguientes.

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

## 🎨 Shell admin unificado · LIVE (desde 2026-04-23)

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

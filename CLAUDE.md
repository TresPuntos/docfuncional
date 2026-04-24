# Tres Puntos - Proposal Management System (docfuncional)

> **Estado actual del proyecto y próximos pasos** → ver [`PLAN.md`](PLAN.md) en la raíz. Léelo al empezar sesión para entender qué está desplegado, qué cliente trabajamos y qué viene después.

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

## 🔒 PENDIENTE DE DEPLOY · Sistema de contratos + cambios main acumulados (congelado 2026-04-24)

> **Estado**: TODO EN LOCAL, NO DESPLEGADO. Hay clientes revisando propuestas en prod (H2B Hipotecas v1.5, Eloi activo), y queremos evitar cualquier riesgo. Jordi decidió esperar una ventana de bajo tráfico.

### Rama donde vive todo

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
- Web: `wearetruman.es`
- Email y representante legal: pendientes de rellenar (en Holded no figuran)
- Contrato ya creado en BD local (id=1) con estos datos fiscales rellenados

---

## 🚨 PLAN DE DEPLOY EN 2 FASES (cuando Jordi dé luz verde)

### 📋 Pre-requisitos antes de deploy

- [ ] **FTP password renovado** — el de `~/.claude/settings.json` (`HOSTINGER_FTP_PASS`) devuelve `530 Login incorrect`. Actualizar en settings o pasarlo al arrancar la sesión.
- [ ] **Hostinger MCP token válido** — actualmente responde `Unauthenticated`. Alternativa viable si FTP falla.
- [ ] **Ventana temporal de bajo tráfico** — idealmente noche (22h+) o fin de semana.
- [ ] **Descargar backup completo de `/doc/`** a `/tmp/tp-prod-backup-YYYYMMDD-HHMMSS/` antes de cada fase.

### 🟢 Fase A — Cambios seguros (admin + WAL + sidebar)

**NO toca view.php. NO toca cosas de clientes.** Bajo riesgo.

Archivos a subir (subset de lo pendiente en main, ya commiteado en main):
```
config.php                    # WAL mode + constantes TP_* defensivas
master/admin-sidebar.php      # Refactor Linear-style + entradas Contratos/Plantillas
master/admin-breadcrumb.php   # NUEVO · breadcrumb reusable
admin.php                     # Adaptado al sidebar refactor
admin_providers.php           # Directorio global + avatar
admin_analytics.php           # Fix includeInternal warnings + sidebar
admin_feedback.php            # Sidebar refactor
```

**Smoke tests post-deploy (5 min):**
- `https://doc.trespuntos-lab.com/p/h2bhipotecas` → debe renderizar IGUAL que antes (view.php sigue siendo el viejo)
- `/admin.php` → sidebar nuevo con Dashboard + Proveedores top-level + lista propuestas accordion
- `/admin_providers.php` → directorio global con cards de proveedores
- `/admin_feedback.php?propuesta_id=21` → filtros "Abiertos · Todos · Cerrados"
- `/s/{token}` Diego → PIN gate → redirige a `/p/{slug}?__provider={token}` normal

Si algo falla: rollback del archivo concreto desde backup (< 2 min).

### 🟡 Fase B — view.php + Contratos (horario bajo tráfico)

**Orden estricto (muy importante):**

1. **Crear backup completo:**
   ```bash
   mkdir /tmp/tp-prod-backup-YYYYMMDD-HHMMSS-faseB
   # Descargar todos los archivos que se van a tocar + database.sqlite
   ```

2. **Ejecutar migración de contratos en prod** (subir `database/migrate_contratos.php` a `/doc/`, ejecutar vía HTTPS, borrar inmediatamente):
   ```
   https://doc.trespuntos-lab.com/migrate_contratos.php
   → debe responder "Migración contratos aplicada: + 4 tablas + 2 carpetas uploads"
   ```

3. **Ejecutar seed plantillas** (mismo patrón):
   ```
   https://doc.trespuntos-lab.com/seed_contratos.php
   → 5 plantillas creadas
   ```

4. **Actualizar plantilla NDA con bloque fiscal** (si se subió la seed original con bloque sin fiscales):
   ```
   https://doc.trespuntos-lab.com/update_nda_fiscal.php
   → plantilla nda-subcontratacion-tp actualizada con bloque "Identificación de las partes"
   ```

5. **Subir archivos (en este orden):**
   ```
   config.php                  # si no se subió en fase A
   api/contratos_lib.php       # NUEVO
   composer.json               # NUEVO
   composer.lock               # NUEVO
   vendor/                     # NUEVO · ~15 MB, subida larga (mPDF + FPDI + deps)
   uploads/contratos/.htaccess # NUEVO (Deny all)
   uploads/contratos_plantillas/.htaccess # NUEVO
   provider.php                # Gate contratos con try/catch defensivo
   provider_contrato.php       # NUEVO
   admin_contratos.php         # NUEVO
   admin_plantillas.php        # NUEVO
   view.php                    # ⚠️ ALTO RIESGO · Mermaid + journey + tp-tabs + tp-bar-chart
   ```

6. **Añadir constantes TP a `config.local.php` del server** (NO se sube por gitignore):
   ```php
   define('TP_RAZON_SOCIAL', 'Tres Puntos Comunicación S.L.');
   define('TP_CIF', 'B66018490');
   define('TP_DIRECCION', 'Calle Sant Josep 22, Barcelona');
   define('TP_EMAIL_CONTACTO', 'jordi@trespuntoscomunicacion.es');
   define('TP_EMAIL_LOPD', 'jordi@trespuntoscomunicacion.es');
   define('TP_WEB', 'trespuntoscomunicacion.es');
   define('TP_FIRMANTE_NOMBRE', 'Jordi Expósito Lozano');
   define('TP_FIRMANTE_DNI', '52407613C');
   define('TP_FIRMANTE_CARGO', 'Founder & Digital Experience Manager');
   define('TP_FIRMANTE_EMAIL', 'jordi@trespuntoscomunicacion.es');
   define('SIGN_OTP_THRESHOLD_EUR', 3000);
   define('SIGN_OTP_TTL_MINUTES', 10);
   define('SIGN_TSA_ENABLED', true);
   define('SIGN_TSA_URL', 'https://freetsa.org/tsr');
   define('SIGN_RETENTION_YEARS', 6);
   define('SIGN_CONTRACT_EXPIRES_DAYS', 30);
   ```

**Smoke tests post-deploy (15 min, EN ORDEN):**
- `https://doc.trespuntos-lab.com/p/h2bhipotecas` → renderiza TODO bien (especialmente journey component, tp-bar-chart si los hay)
- Otras propuestas en vivo → mismo check visual
- `/admin_contratos.php` → lista vacía + KPIs + 5 plantillas disponibles abajo
- `/admin_plantillas.php` → 5 plantillas con preview accesible
- Click preview de nda-subcontratacion-tp → se abre PDF con bloque "Identificación de las partes"
- `/s/{token}` Diego → PIN + accede a propuesta (sin contrato pendiente, gate silencioso)
- Crear contrato test para Diego → enviar → firmar como proveedor → firmar como TP → descargar PDF final firmado

**Rollback plan por si view.php rompe:**
- Re-subir `view.php` desde backup faseB
- Si también rompió BD → restaurar `database.sqlite` desde backup (⚠️ perdería cualquier tracking/comentario recibido entre backup y rollback, avisar al cliente)

### 🔴 Lo que NO se despliega hasta confirmación explícita

- **view.php** — cambios de Mermaid/journey/tp-bar-chart/tp-tabs (353 líneas). Vienen de sesiones previas (`99892f3` + `d9a560b`). Claudio (yo) no los he tocado en esta sesión, no puedo vouch por ellos. Antes de Fase B releer y testar en local con propuestas actuales.
- **config.local.php del server** — hay que editarlo manualmente SSH/panel, no se sube por gitignore.

### 📦 Resumen commits en feat/contratos-firma

```
07f7500 fix(contratos): try/catch defensivo en gate contratos de provider.php
94b2891 feat(contratos): añade bloque "Identificación de las partes" con datos fiscales
37bdaaf feat(contratos): subir PDF directo one-off + editor visual de plantillas
06a3e5c feat(contratos): admin panel + provider gate + UI firma (Sprints 1+2+3)
e5499d0 feat(contratos): librería core (mPDF + audit + OTP + TSA) + 5 plantillas seed
700fe4e feat(contratos): migración BD + mPDF setup + estructura uploads
d9a560b feat(view): journey reemplazado por componente custom + tp-bar-chart refined      ← HEREDADO DE MAIN
a930bb7 fix(db): SQLite WAL mode + busy_timeout                                           ← HEREDADO DE MAIN
99892f3 feat(view): integrar Mermaid + tp-tabs + tp-bar-chart en visor                   ← HEREDADO DE MAIN
```

### 📋 TODO para retomar (orden recomendado)

1. Conseguir FTP password nuevo (o arreglar Hostinger MCP token)
2. Elegir ventana temporal de deploy (noche/weekend)
3. Antes de Fase B: **leer view.php con detalle** y validar que Mermaid/journey/tp-bar-chart no rompen render con HTMLs existentes en prod
4. Hacer Fase A primero (sin riesgo para clientes)
5. Dejar Fase A 24h en prod para ver si algo cae
6. Hacer Fase B con ventana de mantenimiento
7. Tras Fase B: invitar a Dani como proveedor, crear contrato mantenimiento (plantilla NDA), firmar end-to-end en prod
8. Merge `feat/contratos-firma` → `main` y push

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

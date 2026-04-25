# Deploy del sistema de contratos · operativa

Bundle aislado, sin tocar `view.php`. Riesgo cero para clientes activos
(Eloi/H2B y demás): si los archivos nuevos rompen, solo afectan a las
URLs `/sign.php`, `/admin_contratos.php`, `/admin_plantillas.php`,
`/provider_contrato.php` (que ahora dan 404, así que la peor regresión
posible sería 404 → 500 en URLs todavía no anunciadas).

## Pre-requisitos antes de tocar nada

1. **FTP password renovado** (el de `~/.claude/settings.json` da 530)
2. **Editar `config.local.php` del server** (vía SSH/panel, NO se sube por gitignore):
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
   // Cabeceras proxy: solo true si Hostinger está detrás de CloudFlare
   // En el setup actual NO lo está → dejar comentado para usar REMOTE_ADDR.
   // define('SIGN_TRUST_PROXY_HEADERS', false);
   ```
3. **Resend ya está configurado** (verificado: emails funcionan)

## Flujo de ejecución

```bash
# Variables de FTP (en una sola sesión de terminal)
export TP_FTP_PASS='xxxxx'

# 1) Backup pre-deploy (~30s)
./scripts/deploy/backup-prod.sh
# → crea /tmp/tp-prod-backup-YYYYMMDD-HHMMSS/ con provider.php,
#   config.php, master/admin-sidebar.php y database.sqlite

# Anota el path del backup que muestra al final:
export TP_BACKUP_DIR=/tmp/tp-prod-backup-YYYYMMDD-HHMMSS

# 2) Deploy (sube vendor/ + bundle + ejecuta migraciones + smoke test)
#    Tarda ~5-8 min por la subida de vendor/ (~15MB / 500+ archivos pequeños)
./scripts/deploy/deploy-contratos.sh
# Pide confirmación interactiva 2 veces.

# 3) Si algo falla → rollback (~1 min)
./scripts/deploy/rollback.sh
```

## Qué hace cada script

| Script | Qué hace |
|---|---|
| `backup-prod.sh` | Descarga via FTP los 4 archivos que el deploy va a sobreescribir + `database/database.sqlite`. Valida que la BD no sea < 1KB y que sea SQLite válido. |
| `deploy-contratos.sh` | Pre-checks locales (sintaxis PHP, archivos del bundle existen, prod responde 200) → sube `vendor/` (15MB) → sube bundle de contratos → sube archivos modificados al final → ejecuta 3 migraciones BD via HTTPS y las borra → opcional seed + update_nda_fiscal → smoke tests post-deploy. |
| `smoke-test.sh` | Verifica que las 5 propuestas activas siguen devolviendo 200 sin errores PHP, que admin.php sigue OK, y que las URLs nuevas de contratos cargan correctamente. |
| `rollback.sh` | Restaura los 3 archivos modificados desde el backup, borra los archivos NUEVOS de contratos (los que en prod no existían antes), no toca BD por defecto (las migraciones son aditivas — el sistema viejo sigue funcionando con las tablas nuevas existentes). |

## Bundle exacto que se sube (`bundle.txt`)

Archivos NUEVOS (no existen en prod):
- `api/contratos_lib.php`
- `sign.php`
- `admin_contratos.php`
- `admin_plantillas.php`
- `provider_contrato.php`
- `master/admin-breadcrumb.php`
- `master/brand/logo-print.svg`
- `master/brand/logo-print.png`
- `composer.json`, `composer.lock`, `vendor/` (entero)
- `uploads/contratos/.htaccess`
- `uploads/contratos_plantillas/.htaccess`

Archivos MODIFICADOS (requieren backup previo):
- `provider.php` — gate try/catch + session_regenerate_id
- `config.php` — constantes TP_* y SIGN_* defensivas
- `master/admin-sidebar.php` — entradas Contratos / Plantillas

Migraciones (suben temporalmente, ejecutan, borran):
- `database/migrate_contratos.php` (4 tablas base)
- `database/migrate_contratos_signing_token.php` (col signing_token)
- `database/migrate_contratos_hardening.php` (otp_hash, otp_attempts, signing_token_expires_at)
- `database/seed_contratos.php` (5 plantillas) · solo primera vez
- `database/update_nda_fiscal.php` (bloque fiscal NDA) · solo primera vez

## Lo que NO se sube (decisión consciente)

- `view.php` — heredado de commits previos no validados en esta sesión. Se queda fuera para evitar regresión visual en propuestas activas (Eloi). Se subirá en una ventana posterior tras revisar.
- `admin.php`, `admin_providers.php`, `admin_analytics.php`, `admin_feedback.php` — el refactor del sidebar de la rama trae cambios en estas vistas que NO son parte del sistema de contratos. Si quieres el refactor entero, va en deploy aparte.

## Aislamiento confirmado

Verificado leyendo el código:
- `view.php` no requiere `vendor/` ni `contratos_lib.php`
- `provider.php` no requiere `contratos_lib.php` (el gate usa PDO directo + try/catch)
- `admin.php` no requiere ninguna pieza nueva

Por tanto: si `vendor/` se sube parcial o `contratos_lib.php` tiene un fatal,
el sistema viejo sigue funcionando intacto. Solo se ven afectadas las URLs
nuevas de contratos.

## Test post-deploy end-to-end (manual, 5 min)

Después de que el script termine OK, verifica manualmente:

1. Abrir `/p/h2bhipotecas` con el PIN — debe renderizar igual que antes
2. Abrir `/admin.php` — sidebar puede mostrar entrada "Contratos" o no según si subiste master/admin-sidebar.php
3. Abrir `/admin_contratos.php` — login admin → debe mostrar lista vacía + 5 plantillas
4. Crear contrato test desde plantilla NDA con destinatario = Jordi (proveedor que crees) → enviar → abrir el `/sign.php?token=...` → firmar end-to-end → comprobar PDF final descargable

## Si algo va mal

```bash
# Rollback completo
TP_BACKUP_DIR=/tmp/tp-prod-backup-XXXX TP_FTP_PASS='xxx' ./scripts/deploy/rollback.sh

# Solo restaurar BD (las migraciones son aditivas, no urgente)
curl -T /tmp/tp-prod-backup-XXXX/database/database.sqlite \
  --user 'u296656791.jorditrespuntos:xxx' \
  ftp://ftp.trespuntos-lab.com/doc/database/database.sqlite
```

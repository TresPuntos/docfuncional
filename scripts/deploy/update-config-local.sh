#!/usr/bin/env bash
# Añade las constantes TP_* y SIGN_* al config.local.php del server.
# Idempotente: si ya están, no las duplica. Hace backup local antes de subir.
#
# Uso: TP_FTP_PASS='xxx' ./scripts/deploy/update-config-local.sh

set -euo pipefail

FTP_HOST="${TP_FTP_HOST:-ftp.trespuntos-lab.com}"
FTP_USER="${TP_FTP_USER:-u296656791.jorditrespuntos}"
FTP_PASS="${TP_FTP_PASS:-}"

if [ -z "$FTP_PASS" ]; then
    echo "ERROR: TP_FTP_PASS no definido." >&2
    exit 1
fi

TS=$(date +%Y%m%d-%H%M%S)
WORK_DIR=$(mktemp -d -t tp-config-XXXX)
ORIG="${WORK_DIR}/config.local.original.php"
NEW="${WORK_DIR}/config.local.new.php"

echo "📥 Descargando config.local.php actual de prod..."
curl -sS --fail --user "${FTP_USER}:${FTP_PASS}" \
    "ftp://${FTP_HOST}/doc/config.local.php" -o "$ORIG"
echo "  ✓ $(wc -c < "$ORIG") bytes"

# Backup local con timestamp (para rollback)
BACKUP_LOCAL="/tmp/tp-config-local-backup-${TS}.php"
cp "$ORIG" "$BACKUP_LOCAL"
echo "  💾 backup local: $BACKUP_LOCAL"

# Comprobar si ya tiene TP_RAZON_SOCIAL (idempotencia)
if grep -q "TP_RAZON_SOCIAL" "$ORIG"; then
    echo "  ✓ config.local.php ya tiene constantes TP_*. No hace falta tocar nada."
    rm -rf "$WORK_DIR"
    exit 0
fi

echo "  ⚠ config.local.php NO tiene constantes TP_*. Añadiendo..."

# Generar la nueva versión: copia del original + bloque añadido al final
cp "$ORIG" "$NEW"
cat >> "$NEW" <<'EOPHP'

// === SISTEMA DE CONTRATOS · firma electrónica eIDAS (añadido 2026-04-25) ===

// Datos legales Tres Puntos para PDFs y emails
define('TP_RAZON_SOCIAL', 'Tres Puntos Comunicación S.L.');
define('TP_CIF', 'B66018490');
define('TP_DIRECCION', 'Calle Sant Josep 22, Barcelona');
define('TP_EMAIL_CONTACTO', 'jordi@trespuntoscomunicacion.es');
define('TP_EMAIL_LOPD', 'jordi@trespuntoscomunicacion.es');
define('TP_WEB', 'trespuntoscomunicacion.es');

// Firmante TP (Jordi por defecto · único con poderes notariales)
define('TP_FIRMANTE_NOMBRE', 'Jordi Expósito Lozano');
define('TP_FIRMANTE_DNI', '52407613C');
define('TP_FIRMANTE_CARGO', 'Founder & Digital Experience Manager');
define('TP_FIRMANTE_EMAIL', 'jordi@trespuntoscomunicacion.es');

// Política firma electrónica
define('SIGN_OTP_THRESHOLD_EUR', 3000);    // Activar OTP cuando contrato > este importe
define('SIGN_OTP_TTL_MINUTES', 10);
define('SIGN_TSA_ENABLED', true);
define('SIGN_TSA_URL', 'https://freetsa.org/tsr');
define('SIGN_RETENTION_YEARS', 6);          // Código de Comercio
define('SIGN_CONTRACT_EXPIRES_DAYS', 30);
// Hostinger NO está tras CloudFlare en este setup → REMOTE_ADDR es la fuente real.
// Si en el futuro se pone CF delante, descomentar:
// define('SIGN_TRUST_PROXY_HEADERS', true);
EOPHP

echo "  ✓ nueva versión generada ($(wc -c < "$NEW") bytes)"

# Validar sintaxis PHP
if ! php -l "$NEW" >/dev/null 2>&1; then
    echo "❌ Sintaxis PHP rota tras la modificación. ABORTANDO."
    php -l "$NEW"
    exit 2
fi
echo "  ✓ sintaxis PHP válida"

# Mostrar el diff antes de subir
echo ""
echo "  Diff (último bloque añadido):"
diff "$ORIG" "$NEW" | head -50 | sed 's/^/    /'

echo ""
read -p "  ¿Subir esta versión a prod? [SI para continuar]: " C
if [ "$C" != "SI" ]; then
    echo "  Cancelado. Backup queda en $BACKUP_LOCAL"
    exit 0
fi

# Subir
echo ""
echo "📤 Subiendo nuevo config.local.php a prod..."
curl -sS --fail --user "${FTP_USER}:${FTP_PASS}" \
    -T "$NEW" \
    "ftp://${FTP_HOST}/doc/config.local.php"
echo "  ✓ subido"

# Verificar que sigue cargando (404 sería preocupante)
echo ""
echo "🔎 Verificando prod sigue funcionando..."
HTTP=$(curl -sS -o /dev/null -w "%{http_code}" "https://doc.trespuntos-lab.com/p/h2bhipotecas")
if [ "$HTTP" = "200" ]; then
    echo "  ✓ /p/h2bhipotecas responde 200"
else
    echo "  ❌ /p/h2bhipotecas responde $HTTP — POSIBLE FATAL EN config.local.php"
    echo "  Restaura desde backup:"
    echo "    curl -T $BACKUP_LOCAL --user '${FTP_USER}:\$TP_FTP_PASS' ftp://${FTP_HOST}/doc/config.local.php"
    exit 3
fi

rm -rf "$WORK_DIR"
echo ""
echo "✅ config.local.php actualizado con constantes TP_* y SIGN_*"
echo "   Backup local: $BACKUP_LOCAL"

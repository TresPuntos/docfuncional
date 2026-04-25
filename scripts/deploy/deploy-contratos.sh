#!/usr/bin/env bash
# Sube el bundle aislado del sistema de contratos a producción.
# Ejecuta migraciones via HTTPS. Borra los scripts de migración después.
# Smoke test post-deploy. Aborta a la primera señal de fallo.
#
# REQUISITOS PREVIOS (no automatizables):
#   1. Backup hecho (./scripts/deploy/backup-prod.sh)
#   2. config.local.php del server tiene constantes TP_* y SIGN_* (verificar via SSH)
#   3. FTP password renovado en TP_FTP_PASS
#
# Uso: TP_FTP_PASS='xxx' ./scripts/deploy/deploy-contratos.sh

set -euo pipefail

FTP_HOST="${TP_FTP_HOST:-ftp.trespuntos-lab.com}"
FTP_USER="${TP_FTP_USER:-u296656791.claude3}"
FTP_PASS="${TP_FTP_PASS:-}"
PROD_URL="${TP_PROD_URL:-https://doc.trespuntos-lab.com}"

if [ -z "$FTP_PASS" ]; then
    echo "ERROR: TP_FTP_PASS no definido." >&2
    exit 1
fi

cd "$(dirname "$0")/../.." # raíz del proyecto

# ----------------------------------------------------------------------
# 0) PRE-CHECKS LOCALES
# ----------------------------------------------------------------------
echo "═══════════════════════════════════════════════"
echo "0) PRE-CHECKS"
echo "═══════════════════════════════════════════════"

REQUIRED_FILES=(
    "api/contratos_lib.php"
    "sign.php"
    "admin_contratos.php"
    "admin_plantillas.php"
    "provider_contrato.php"
    "composer.json"
    "composer.lock"
    "vendor/autoload.php"
    "database/migrate_contratos.php"
    "database/migrate_contratos_signing_token.php"
    "database/migrate_contratos_hardening.php"
    "database/seed_contratos.php"
    "database/update_nda_fiscal.php"
    "master/brand/logo-print.svg"
    "master/brand/logo-print.png"
    "master/admin-breadcrumb.php"
    "master/admin-sidebar.php"
    "uploads/contratos/.htaccess"
    "uploads/contratos_plantillas/.htaccess"
    "provider.php"
    "config.php"
)
for f in "${REQUIRED_FILES[@]}"; do
    if [ ! -e "$f" ]; then
        echo "❌ Falta archivo local: $f"
        exit 2
    fi
done
echo "✓ ${#REQUIRED_FILES[@]} archivos del bundle existen en local"

# Validar sintaxis PHP de los modificables
for f in api/contratos_lib.php sign.php admin_contratos.php admin_plantillas.php provider_contrato.php provider.php config.php; do
    if ! php -l "$f" >/dev/null 2>&1; then
        echo "❌ Sintaxis PHP rota en $f"
        php -l "$f"
        exit 3
    fi
done
echo "✓ Sintaxis PHP OK en todos los archivos a subir"

# Comprobar que prod responde antes de tocar nada
HTTP_BEFORE=$(curl -sS -o /dev/null -w "%{http_code}" "${PROD_URL}/p/h2bhipotecas")
if [ "$HTTP_BEFORE" != "200" ]; then
    echo "❌ prod no devuelve 200 antes del deploy (HTTP ${HTTP_BEFORE}). ABORTANDO."
    exit 4
fi
echo "✓ prod responde 200 (h2bhipotecas)"

read -p $'\n¿Backup hecho y config.local.php verificado? [escribe SI para continuar]: ' CONFIRM
if [ "$CONFIRM" != "SI" ]; then
    echo "Cancelado."
    exit 0
fi

# ----------------------------------------------------------------------
# Helper FTP upload con retry
# ----------------------------------------------------------------------
ftp_upload() {
    local local_path="$1"
    local remote_path="$2"
    local attempts=0
    local max_attempts=3
    while [ $attempts -lt $max_attempts ]; do
        if curl -sS --fail --user "${FTP_USER}:${FTP_PASS}" \
                --ftp-create-dirs -T "$local_path" \
                "ftp://${FTP_HOST}/doc/$remote_path"; then
            return 0
        fi
        attempts=$((attempts + 1))
        echo "    ⚠ retry $attempts/$max_attempts: $remote_path"
        sleep 2
    done
    echo "❌ FALLO upload tras $max_attempts intentos: $remote_path"
    return 1
}

# Helper FTP delete (para borrar migraciones tras ejecutar)
ftp_delete() {
    local remote_path="$1"
    curl -sS --user "${FTP_USER}:${FTP_PASS}" \
        --quote "DELE doc/$remote_path" \
        "ftp://${FTP_HOST}/" >/dev/null 2>&1 || true
}

# ----------------------------------------------------------------------
# 1) UPLOAD vendor/ (96MB · zip → descomprimir via PHP)
# ----------------------------------------------------------------------
echo ""
echo "═══════════════════════════════════════════════"
echo "1) Subiendo vendor/ (zip → descomprimir vía PHP)"
echo "═══════════════════════════════════════════════"

# Comprimir vendor/ localmente
ZIP_LOCAL=$(mktemp -t tp-vendor-XXXXX).zip
echo "  📦 Comprimiendo vendor/ → $ZIP_LOCAL"
zip -qr "$ZIP_LOCAL" vendor/
ZIP_SIZE=$(du -h "$ZIP_LOCAL" | awk '{print $1}')
echo "  ✓ zip local listo ($ZIP_SIZE)"

# Subir zip
echo "  ↑ vendor.zip → /doc/vendor.zip"
ftp_upload "$ZIP_LOCAL" "vendor.zip"

# Generar y subir un script PHP temporal que descomprime + borra el zip
EXTRACT_SCRIPT=$(mktemp -t tp-extract-XXXXX).php
cat > "$EXTRACT_SCRIPT" <<'EOPHP'
<?php
// Script temporal para descomprimir vendor.zip en producción.
// Se borra a sí mismo + el zip al terminar. Solo accesible vía HTTPS.
$zipPath = __DIR__ . '/vendor.zip';
if (!file_exists($zipPath)) { http_response_code(404); echo "vendor.zip no encontrado"; exit; }

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) { http_response_code(500); echo "No se pudo abrir vendor.zip"; exit; }

// Extraer
if (!$zip->extractTo(__DIR__)) { $zip->close(); http_response_code(500); echo "Extract falló"; exit; }
$count = $zip->numFiles;
$zip->close();

// Limpieza
@unlink($zipPath);
@unlink(__FILE__);

header('Content-Type: text/plain; charset=utf-8');
echo "✓ vendor/ descomprimido ($count archivos)\n";
echo "✓ vendor.zip borrado\n";
echo "✓ extract-vendor.php auto-borrado\n";
echo "vendor/autoload.php existe: " . (file_exists(__DIR__ . '/vendor/autoload.php') ? 'SI' : 'NO') . "\n";
EOPHP

echo "  ↑ extract-vendor.php → /doc/extract-vendor.php"
ftp_upload "$EXTRACT_SCRIPT" "extract-vendor.php"
rm -f "$EXTRACT_SCRIPT"

# Ejecutar descompresión vía HTTPS
echo "  → ejecutando ${PROD_URL}/extract-vendor.php"
EXTRACT_OUT=$(curl -sS --max-time 60 "${PROD_URL}/extract-vendor.php" || echo "ERROR_CURL")
echo "$EXTRACT_OUT" | sed 's/^/      /'

if ! echo "$EXTRACT_OUT" | grep -q "vendor/autoload.php existe: SI"; then
    echo "❌ vendor/ no se descomprimió correctamente. ABORTANDO."
    rm -f "$ZIP_LOCAL"
    exit 5
fi
echo "✓ vendor/ desplegado en prod"
rm -f "$ZIP_LOCAL"

# ----------------------------------------------------------------------
# 2) UPLOAD bundle (archivos PHP + assets)
# ----------------------------------------------------------------------
echo ""
echo "═══════════════════════════════════════════════"
echo "2) Subiendo archivos PHP + assets"
echo "═══════════════════════════════════════════════"

BUNDLE_FILES=(
    "api/contratos_lib.php"
    "composer.json"
    "composer.lock"
    "sign.php"
    "admin_contratos.php"
    "admin_plantillas.php"
    "provider_contrato.php"
    "master/brand/logo-print.svg"
    "master/brand/logo-print.png"
    "master/admin-breadcrumb.php"
    "uploads/contratos/.htaccess"
    "uploads/contratos_plantillas/.htaccess"
)
# Archivos modificados (último, por si rompe algo, que no afecte hasta el final)
MODIFIED_FILES=(
    "config.php"
    "master/admin-sidebar.php"
    "provider.php"
)

for f in "${BUNDLE_FILES[@]}" "${MODIFIED_FILES[@]}"; do
    echo "  ↑ $f"
    ftp_upload "$f" "$f"
done
echo "✓ bundle subido"

# ----------------------------------------------------------------------
# 3) MIGRACIONES BD (subir, ejecutar via HTTPS, BORRAR)
# ----------------------------------------------------------------------
echo ""
echo "═══════════════════════════════════════════════"
echo "3) Ejecutando migraciones BD"
echo "═══════════════════════════════════════════════"

MIGRATIONS=(
    "migrate_contratos.php"
    "migrate_contratos_signing_token.php"
    "migrate_contratos_hardening.php"
)

for m in "${MIGRATIONS[@]}"; do
    echo ""
    echo "  → $m"
    # 3a. Subir a /doc/ raíz (las migraciones leen __DIR__/../config.php)
    ftp_upload "database/$m" "$m"
    # 3b. Ejecutar via HTTPS
    OUTPUT=$(curl -sS --max-time 30 "${PROD_URL}/$m" || echo "ERROR_CURL")
    echo "$OUTPUT" | sed 's/^/      /'
    # 3c. Borrar inmediatamente
    ftp_delete "$m"
    echo "    ✓ $m ejecutada y borrada"

    # Validación: la salida debe contener "+" (filas creadas) o "(nada que hacer)" (idempotente)
    if ! echo "$OUTPUT" | grep -qE "^\s*(\+|=|·|Migración|\(nada)"; then
        echo "❌ La migración $m no devolvió output esperado. ABORTANDO."
        echo "Restaura el backup de BD antes de continuar."
        exit 5
    fi
done

# Seed + update NDA (solo primera vez)
read -p $'\n¿Ejecutar seed_contratos.php (5 plantillas) y update_nda_fiscal.php? [SI/no]: ' SEED
if [ "$SEED" = "SI" ]; then
    for m in seed_contratos.php update_nda_fiscal.php; do
        echo "  → $m"
        ftp_upload "database/$m" "$m"
        OUTPUT=$(curl -sS --max-time 30 "${PROD_URL}/$m" || echo "ERROR_CURL")
        echo "$OUTPUT" | sed 's/^/      /'
        ftp_delete "$m"
    done
fi

# ----------------------------------------------------------------------
# 4) SMOKE TESTS POST-DEPLOY
# ----------------------------------------------------------------------
echo ""
echo "═══════════════════════════════════════════════"
echo "4) Smoke tests post-deploy"
echo "═══════════════════════════════════════════════"

bash "$(dirname "$0")/smoke-test.sh" || {
    echo "❌ Smoke tests fallaron. CONSIDERA ROLLBACK."
    exit 6
}

echo ""
echo "═══════════════════════════════════════════════"
echo "✅ DEPLOY COMPLETO"
echo "═══════════════════════════════════════════════"
echo ""
echo "Próximo paso: probar end-to-end creando un contrato test"
echo "  ${PROD_URL}/admin_contratos.php"
echo ""
echo "Si algo va mal: ejecutar ./scripts/deploy/rollback.sh"

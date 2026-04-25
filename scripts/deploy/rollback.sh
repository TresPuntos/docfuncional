#!/usr/bin/env bash
# Rollback del deploy de contratos.
# Restaura los archivos modificados desde el backup pre-deploy y opcionalmente
# borra los archivos NUEVOS (que en prod no existían antes).
#
# Uso:
#   TP_BACKUP_DIR=/tmp/tp-prod-backup-YYYYMMDD-HHMMSS \
#   TP_FTP_PASS='xxx' \
#   ./scripts/deploy/rollback.sh
#
# Por seguridad NO borra database.sqlite ni hace DROP de tablas. Si hay que
# revertir migraciones, hay que restaurar database.sqlite a mano (lo verifica
# y lo deja preparado el script para subirlo manualmente).

set -euo pipefail

FTP_HOST="${TP_FTP_HOST:-ftp.trespuntos-lab.com}"
FTP_USER="${TP_FTP_USER:-u296656791.claude3}"
FTP_PASS="${TP_FTP_PASS:-}"
BACKUP_DIR="${TP_BACKUP_DIR:-}"

if [ -z "$FTP_PASS" ] || [ -z "$BACKUP_DIR" ]; then
    echo "ERROR: TP_FTP_PASS y TP_BACKUP_DIR son obligatorias." >&2
    exit 1
fi
if [ ! -d "$BACKUP_DIR" ]; then
    echo "ERROR: $BACKUP_DIR no existe." >&2
    exit 2
fi

echo "🔄 Rollback desde ${BACKUP_DIR}"
read -p "¿Confirmas? [SI para continuar]: " C
[ "$C" = "SI" ] || exit 0

ftp_upload() {
    local local_path="$1"
    local remote_path="$2"
    curl -sS --fail --user "${FTP_USER}:${FTP_PASS}" \
        --ftp-create-dirs -T "$local_path" \
        "ftp://${FTP_HOST}/doc/$remote_path"
}

ftp_delete() {
    local remote_path="$1"
    curl -sS --user "${FTP_USER}:${FTP_PASS}" \
        --quote "DELE doc/$remote_path" \
        "ftp://${FTP_HOST}/" >/dev/null 2>&1 || true
}

# 1) Restaurar archivos modificados desde backup
echo ""
echo "1) Restaurando archivos modificados desde backup"
RESTORE=(
    "provider.php"
    "config.php"
    "master/admin-sidebar.php"
)
for f in "${RESTORE[@]}"; do
    if [ -f "${BACKUP_DIR}/$f" ]; then
        echo "  ↑ $f (desde backup)"
        ftp_upload "${BACKUP_DIR}/$f" "$f"
    else
        echo "  ⚠ $f no existe en backup, saltado"
    fi
done

# 2) Borrar archivos NUEVOS que no existían antes en prod
echo ""
echo "2) Borrando archivos del sistema de contratos"
NEW_FILES=(
    "sign.php"
    "admin_contratos.php"
    "admin_plantillas.php"
    "provider_contrato.php"
    "api/contratos_lib.php"
    "master/admin-breadcrumb.php"
    "master/brand/logo-print.svg"
    "master/brand/logo-print.png"
    "uploads/contratos/.htaccess"
    "uploads/contratos_plantillas/.htaccess"
)
for f in "${NEW_FILES[@]}"; do
    echo "  × $f"
    ftp_delete "$f"
done

echo ""
echo "3) vendor/ — NO se borra automáticamente (15MB, lento)."
echo "   Si quieres limpiarlo, hazlo a mano por FTP cuando puedas."
echo ""

# 4) Aviso BD
if [ -f "${BACKUP_DIR}/database/database.sqlite" ]; then
    echo "4) database.sqlite está en backup."
    echo "   Las migraciones son ADITIVAS (solo añaden tablas/columnas nuevas)."
    echo "   El sistema VIEJO sigue funcionando aunque las tablas nuevas existan."
    echo ""
    echo "   Si AÚN ASÍ quieres restaurar la BD vieja:"
    echo "     curl -T ${BACKUP_DIR}/database/database.sqlite \\"
    echo "       --user '${FTP_USER}:\$TP_FTP_PASS' \\"
    echo "       ftp://${FTP_HOST}/doc/database/database.sqlite"
    echo ""
    echo "   ⚠ ESTO PERDERÁ eventos/firmas/comentarios creados desde el backup."
fi

# 5) Smoke test post-rollback
echo ""
echo "5) Smoke test post-rollback"
bash "$(dirname "$0")/smoke-test.sh" || echo "⚠ Algunos checks fallaron tras rollback"

echo ""
echo "✅ Rollback completado"

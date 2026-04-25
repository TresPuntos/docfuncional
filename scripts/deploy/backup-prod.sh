#!/usr/bin/env bash
# Descarga de prod los archivos que el deploy va a sobreescribir + database.sqlite.
# Crea /tmp/tp-prod-backup-YYYYMMDD-HHMMSS/ con todo dentro.
#
# Variables requeridas:
#   TP_FTP_HOST  (default ftp.trespuntos-lab.com)
#   TP_FTP_USER  (default u296656791.claude3)
#   TP_FTP_PASS  (obligatoria)
#
# Uso: TP_FTP_PASS='xxx' ./scripts/deploy/backup-prod.sh

set -euo pipefail

FTP_HOST="${TP_FTP_HOST:-ftp.trespuntos-lab.com}"
FTP_USER="${TP_FTP_USER:-u296656791.claude3}"
FTP_PASS="${TP_FTP_PASS:-}"

if [ -z "$FTP_PASS" ]; then
    echo "ERROR: TP_FTP_PASS no definido. Exporta el password de FTP." >&2
    exit 1
fi

TS=$(date +%Y%m%d-%H%M%S)
BACKUP_DIR="/tmp/tp-prod-backup-${TS}"
mkdir -p "${BACKUP_DIR}"
echo "📦 Backup → ${BACKUP_DIR}"

# Lista de archivos a descargar (los que el deploy va a sobreescribir)
FILES=(
    "provider.php"
    "config.php"
    "master/admin-sidebar.php"
    "database/database.sqlite"
)

cd "${BACKUP_DIR}"
for f in "${FILES[@]}"; do
    dir=$(dirname "$f")
    [ "$dir" != "." ] && mkdir -p "$dir"
    echo "  ↓ $f"
    if curl -sS --fail --user "${FTP_USER}:${FTP_PASS}" \
            "ftp://${FTP_HOST}/doc/$f" \
            -o "$f"; then
        SIZE=$(wc -c < "$f" | tr -d ' ')
        echo "    ✓ ${SIZE} bytes"
    else
        echo "    ⚠ NO descargado (puede no existir aún en prod). Continúo."
        rm -f "$f"
    fi
done

# Verificar BD descargada
if [ -f "database/database.sqlite" ]; then
    SQLITE_BYTES=$(wc -c < database/database.sqlite | tr -d ' ')
    if [ "$SQLITE_BYTES" -lt 1000 ]; then
        echo "❌ database.sqlite < 1KB, descarga sospechosa. ABORTANDO."
        exit 2
    fi
    # Validar estructura SQLite
    if ! sqlite3 database/database.sqlite "SELECT count(*) FROM propuestas" >/dev/null 2>&1; then
        echo "❌ database.sqlite corrupto o no es SQLite válido. ABORTANDO."
        exit 3
    fi
    PROPS=$(sqlite3 database/database.sqlite "SELECT count(*) FROM propuestas")
    echo "  ✓ BD válida con ${PROPS} propuestas"
fi

echo ""
echo "✅ Backup completo en ${BACKUP_DIR}"
echo "   Para rollback: TP_BACKUP_DIR=${BACKUP_DIR} ./scripts/deploy/rollback.sh"
echo ""
ls -laR "${BACKUP_DIR}"

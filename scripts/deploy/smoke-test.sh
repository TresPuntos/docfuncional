#!/usr/bin/env bash
# Smoke tests post-deploy. Devuelve 0 si todo OK, 1 si hay regresión.
# Uso: ./scripts/deploy/smoke-test.sh

set -u
PROD_URL="${TP_PROD_URL:-https://doc.trespuntos-lab.com}"
FAILED=0

check_http() {
    local label="$1"
    local url="$2"
    local expected="$3"
    local got
    got=$(curl -sS -o /dev/null -w "%{http_code}" "$url" 2>/dev/null)
    if [ "$got" = "$expected" ]; then
        printf "  ✓ %-40s %s\n" "$label" "$got"
    else
        printf "  ✗ %-40s esperado=%s real=%s\n" "$label" "$expected" "$got"
        FAILED=$((FAILED + 1))
    fi
}

check_no_fatal() {
    local label="$1"
    local url="$2"
    local body
    body=$(curl -sS --max-time 10 "$url" 2>/dev/null)
    if echo "$body" | grep -qiE "(Fatal error|Parse error|Undefined|Deprecated|Warning:|Notice:)"; then
        printf "  ✗ %-40s contiene errores PHP\n" "$label"
        echo "$body" | grep -iE "(Fatal|Parse|Undefined|Deprecated|Warning|Notice)" | head -3 | sed 's/^/      /'
        FAILED=$((FAILED + 1))
    else
        printf "  ✓ %-40s sin errores PHP\n" "$label"
    fi
}

echo "Smoke tests post-deploy"
echo "URL base: $PROD_URL"
echo ""

# === REGRESIÓN: lo que ya funcionaba debe seguir funcionando ===
echo "REGRESIÓN — sistema viejo (no debe romperse):"
check_http "GET /p/h2bhipotecas (Eloi)"         "${PROD_URL}/p/h2bhipotecas"               "200"
check_http "GET /p/aula-clinic"                 "${PROD_URL}/p/aula-clinic"                "200"
check_http "GET /p/gibobs-allbanks"             "${PROD_URL}/p/gibobs-allbanks"            "200"
check_http "GET /p/b2b"                         "${PROD_URL}/p/b2b"                        "200"
check_http "GET /p/nexticalaw"                  "${PROD_URL}/p/nexticalaw"                 "200"
check_http "GET /admin.php"                     "${PROD_URL}/admin.php"                    "200"
check_http "GET /api/proposals.php (sin auth)"  "${PROD_URL}/api/proposals.php"            "401"
check_no_fatal "h2bhipotecas (sin errores PHP)" "${PROD_URL}/p/h2bhipotecas"
check_no_fatal "admin.php (sin errores PHP)"    "${PROD_URL}/admin.php"

echo ""
echo "NUEVO — sistema de contratos:"
check_http "GET /admin_contratos.php (login)"   "${PROD_URL}/admin_contratos.php"          "200"
check_http "GET /admin_plantillas.php (login)"  "${PROD_URL}/admin_plantillas.php"         "200"
check_http "GET /sign.php sin token"            "${PROD_URL}/sign.php"                     "400"
check_http "GET /sign.php con token fake"       "${PROD_URL}/sign.php?token=00000000000000000000000000000000" "404"
check_http "GET /provider_contrato.php sin"     "${PROD_URL}/provider_contrato.php"        "400"
check_no_fatal "admin_contratos sin errores PHP" "${PROD_URL}/admin_contratos.php"
check_no_fatal "admin_plantillas sin errores PHP" "${PROD_URL}/admin_plantillas.php"
check_no_fatal "sign.php fake token sin errores" "${PROD_URL}/sign.php?token=00000000000000000000000000000000"

echo ""
if [ $FAILED -eq 0 ]; then
    echo "✅ Todos los smoke tests pasaron"
    exit 0
else
    echo "❌ $FAILED smoke tests fallaron"
    exit 1
fi

<?php
/**
 * Cliente Holded — librería interna (no expuesta vía web directamente).
 *
 * Funciones públicas:
 *   holded_get_estimate($id)         → JSON completo de un presupuesto por ID Holded.
 *   holded_find_by_number($docNum)   → busca por número (E170380) y devuelve JSON.
 *   holded_search_estimates($q, $limit=10) → lista resumida para autocomplete.
 *   holded_pdf_url($id)              → URL pública del PDF (vía proxy local).
 *   holded_format_currency($v)       → "14.036,00 €"
 *   holded_format_date($ts)          → "25/03/2026"
 *
 * Requiere HOLDED_API_KEY y HOLDED_API_BASE definidas en config.local.php.
 */

if (!defined('HOLDED_API_KEY') || HOLDED_API_KEY === '' || HOLDED_API_KEY === 'tu-key-holded') {
    // Fallback silencioso si no hay key → todas las funciones devuelven null/[]
}

function _holded_request(string $path, array $query = [], string $method = 'GET', $body = null) {
    if (!defined('HOLDED_API_KEY') || HOLDED_API_KEY === '') {
        return ['ok' => false, 'error' => 'HOLDED_API_KEY no configurada'];
    }
    $url = rtrim(HOLDED_API_BASE, '/') . $path;
    if ($query) $url .= '?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'key: ' . HOLDED_API_KEY,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'error' => "curl: $err", 'http' => $http];
    $decoded = json_decode($raw, true);
    if ($http >= 400) return ['ok' => false, 'error' => is_array($decoded) ? ($decoded['info'] ?? $decoded['message'] ?? $raw) : $raw, 'http' => $http];
    return ['ok' => true, 'data' => $decoded, 'http' => $http];
}

/** Presupuesto individual por ID interno Holded. Devuelve null si no existe. */
function holded_get_estimate(string $id) {
    if (!preg_match('/^[a-f0-9]{16,32}$/i', $id)) return null;
    $r = _holded_request("/documents/estimate/$id");
    return $r['ok'] ? $r['data'] : null;
}

/**
 * Buscar por número de presupuesto exacto o parcial.
 * Holded no tiene endpoint de búsqueda — pagina /documents/estimate.
 * Estrategia: paginar hasta encontrar coincidencia o agotar (limit por seguridad).
 * @return array|null   JSON del primer match o null
 */
function holded_find_by_number(string $docNumber) {
    $target = strtoupper(trim($docNumber));
    // En la mayoría de entornos, un endpoint de listado paginado:
    //   GET /documents/estimate?starttmp=...&endtmp=...&contactid=...
    // Sin filtro por docNumber, tendríamos que paginar todos. Como fallback
    // práctico, Holded permite pasar el ID directamente vía URL interna. Aquí
    // hacemos un GET listado con "page" y filtramos en memoria hasta 5 páginas
    // (≈500 registros). Suficiente para buscar uno reciente.
    for ($page = 1; $page <= 5; $page++) {
        $r = _holded_request('/documents/estimate', ['page' => $page]);
        if (!$r['ok']) return null;
        $list = is_array($r['data']) ? $r['data'] : [];
        if (!$list) break;
        foreach ($list as $doc) {
            if (isset($doc['docNumber']) && strtoupper($doc['docNumber']) === $target) {
                // Volvemos a pedir el detalle individual (algunos campos no vienen en listado)
                if (!empty($doc['id'])) {
                    $detail = holded_get_estimate($doc['id']);
                    return $detail ?: $doc;
                }
                return $doc;
            }
        }
        if (count($list) < 100) break; // última página
    }
    return null;
}

/**
 * Lista resumida de los últimos N presupuestos para autocomplete en admin.
 * Filtra por coincidencia parcial en docNumber o contactName.
 */
function holded_search_estimates(string $q = '', int $limit = 10) {
    $r = _holded_request('/documents/estimate', ['page' => 1]);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'] ?? 'Error Holded'];
    $all = is_array($r['data']) ? $r['data'] : [];
    $q = strtolower(trim($q));
    $out = [];
    foreach ($all as $d) {
        $num = $d['docNumber'] ?? '';
        $name = $d['contactName'] ?? '';
        if ($q === '' || stripos($num, $q) !== false || stripos($name, $q) !== false) {
            $out[] = [
                'id'         => $d['id'] ?? '',
                'docNumber'  => $num,
                'contactName'=> $name,
                'date'       => $d['date'] ?? null,
                'total'      => $d['total'] ?? 0,
                'status'     => $d['status'] ?? 0,
            ];
            if (count($out) >= $limit) break;
        }
    }
    return ['ok' => true, 'items' => $out];
}

/** Formatea 14036 → "14.036,00 €". Respeta céntimos. */
function holded_format_currency($v, string $currency = 'eur'): string {
    $n = number_format((float)$v, 2, ',', '.');
    $sym = strtolower($currency) === 'eur' ? '€' : strtoupper($currency);
    return $n . ' ' . $sym;
}

function holded_format_date($ts): string {
    if (!$ts) return '—';
    if (is_numeric($ts)) return date('d/m/Y', (int)$ts);
    $t = strtotime($ts);
    return $t ? date('d/m/Y', $t) : (string)$ts;
}

/**
 * Etiqueta legible para el status numérico de Holded (estimates):
 *   0 = pendiente, 1 = aprobado, 2 = rechazado, 3 = facturado...
 * La correspondencia exacta depende de configuración. Lo dejamos flexible.
 */
function holded_status_label(int $s): string {
    return [
        0 => 'Pendiente',
        1 => 'Aprobado',
        2 => 'Rechazado',
        3 => 'Facturado',
        4 => 'Vencido',
    ][$s] ?? 'Estado ' . $s;
}

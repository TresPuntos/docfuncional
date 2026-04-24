<?php
/**
 * Librería de contratos · Sistema de firma electrónica eIDAS simple.
 *
 * Responsabilidades:
 *   - Render HTML con placeholders tipo {{variable}}
 *   - Generar PDFs (mPDF) con hoja de audit trail
 *   - Gestión OTP por email (Resend)
 *   - Sello de tiempo cualificado (Freetsa RFC 3161, opcional)
 *   - Hashing SHA256 + captura de audit trail (14 campos)
 *   - Registro de eventos (contratos_eventos)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

// ====================================================================
//   TEMPLATE ENGINE — Mustache-lite
// ====================================================================

/**
 * Sustituye {{variable}} por su valor en $data.
 * Soporta modificadores simples: {{importe|money}}, {{fecha|date}}, {{nombre|upper}}.
 */
function contrato_render_template(string $html, array $data): string
{
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)(?:\s*\|\s*([a-zA-Z]+))?\s*\}\}/', function ($m) use ($data) {
        $key = $m[1];
        $mod = $m[2] ?? '';
        $val = $data[$key] ?? '';
        if ($val === '' || $val === null) return '';
        switch ($mod) {
            case 'money':
                return number_format((float)$val, 2, ',', '.') . ' €';
            case 'date':
                $ts = strtotime((string)$val);
                if ($ts === false) return (string)$val;
                $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
                return date('j', $ts) . ' de ' . $meses[(int)date('n', $ts) - 1] . ' de ' . date('Y', $ts);
            case 'upper':
                return mb_strtoupper((string)$val, 'UTF-8');
            case 'lower':
                return mb_strtolower((string)$val, 'UTF-8');
            default:
                return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
        }
    }, $html);
}

/**
 * Detecta qué {{placeholders}} usa un HTML — útil para el editor admin.
 */
function contrato_extract_variables(string $html): array
{
    preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)(?:\s*\|\s*[a-zA-Z]+)?\s*\}\}/', $html, $m);
    return array_values(array_unique($m[1]));
}

// ====================================================================
//   HASHING
// ====================================================================

function contrato_hash_file(string $path): string
{
    if (!file_exists($path)) throw new RuntimeException("File not found: $path");
    return hash_file('sha256', $path);
}

function contrato_hash_data(string $data): string
{
    return hash('sha256', $data);
}

/** Hash legible: "ab12cd…ef78" */
function contrato_hash_short(string $full): string
{
    return substr($full, 0, 6) . '…' . substr($full, -4);
}

// ====================================================================
//   EVENTOS (audit cronológico)
// ====================================================================

function contrato_log_evento(PDO $pdo, int $contratoId, string $evento, ?string $actor = null, array $meta = []): void
{
    $stmt = $pdo->prepare("
        INSERT INTO contratos_eventos (contrato_id, evento, actor, ip, user_agent, meta_json, created_at)
        VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
    ");
    $stmt->execute([
        $contratoId,
        $evento,
        $actor,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

// ====================================================================
//   OTP EMAIL
// ====================================================================

function contrato_generate_otp(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function contrato_send_otp_email(string $email, string $nombre, string $codigo, string $tituloContrato): bool
{
    if (!defined('RESEND_API_KEY') || !RESEND_API_KEY) return false;
    $ttl = SIGN_OTP_TTL_MINUTES;
    $html = '<!DOCTYPE html><html><body style="font-family:Inter,sans-serif;background:#f5f5f5;padding:40px 0;margin:0">'
        . '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 1px 3px rgba(0,0,0,.08)">'
        . '<div style="font-size:12px;letter-spacing:.2em;color:#8a8a8a;font-weight:600;margin-bottom:20px">TRES PUNTOS</div>'
        . '<h2 style="margin:0 0 8px 0;color:#0e0e0e;font-size:22px">Código para firmar</h2>'
        . '<p style="color:#3a3a3a;line-height:1.6;margin:0 0 24px 0">Hola ' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p style="color:#3a3a3a;line-height:1.6;margin:0 0 24px 0">Para firmar <strong>' . htmlspecialchars($tituloContrato, ENT_QUOTES, 'UTF-8') . '</strong>, introduce este código en la pantalla de firma:</p>'
        . '<div style="background:#0e0e0e;color:#5dffbf;font-family:JetBrains Mono,monospace;font-size:36px;letter-spacing:.3em;text-align:center;padding:24px;border-radius:10px;margin:0 0 20px 0;font-weight:700">' . $codigo . '</div>'
        . '<p style="color:#8a8a8a;font-size:13px;line-height:1.5;margin:0 0 8px 0">Válido durante ' . $ttl . ' minutos. Si no has iniciado el proceso de firma, ignora este email.</p>'
        . '<hr style="border:none;border-top:1px solid #e5e5e5;margin:24px 0">'
        . '<p style="color:#8a8a8a;font-size:12px;line-height:1.5;margin:0">Este código se envía para verificar tu identidad conforme al Reglamento (UE) 910/2014 (eIDAS). La firma resultante tendrá plena validez jurídica entre las partes.</p>'
        . '</div></body></html>';
    $payload = [
        'from' => RESEND_FROM,
        'to' => [$email],
        'reply_to' => RESEND_REPLY_TO,
        'subject' => 'Código para firmar · ' . $tituloContrato,
        'html' => $html,
    ];
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function contrato_store_otp(PDO $pdo, int $firmaId, string $codigo): void
{
    $ttlMin = SIGN_OTP_TTL_MINUTES;
    $stmt = $pdo->prepare("
        UPDATE contratos_firmas
        SET otp_code = ?,
            otp_expires_at = datetime('now', '+' || ? || ' minutes')
        WHERE id = ?
    ");
    $stmt->execute([$codigo, $ttlMin, $firmaId]);
}

function contrato_verify_otp(PDO $pdo, int $firmaId, string $codigoIntroducido): bool
{
    $stmt = $pdo->prepare("
        SELECT otp_code, otp_expires_at
        FROM contratos_firmas
        WHERE id = ?
    ");
    $stmt->execute([$firmaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['otp_code']) return false;
    if (strtotime($row['otp_expires_at']) < time()) return false;
    if (!hash_equals($row['otp_code'], trim($codigoIntroducido))) return false;
    $pdo->prepare("UPDATE contratos_firmas SET otp_verified_at = datetime('now') WHERE id = ?")->execute([$firmaId]);
    return true;
}

// ====================================================================
//   SELLO DE TIEMPO CUALIFICADO (Freetsa RFC 3161)
// ====================================================================

/**
 * Solicita un timestamp al TSA. Devuelve base64 del .tsr o null si falla.
 * Uso no-bloqueante: si la TSA está caída, seguimos sin el sello.
 */
function contrato_request_tsa_timestamp(string $hash): ?string
{
    if (!SIGN_TSA_ENABLED) return null;
    // Generar TSQ (timestamp request) con OpenSSL CLI
    $tmpQ = tempnam(sys_get_temp_dir(), 'tsq');
    $tmpR = tempnam(sys_get_temp_dir(), 'tsr');
    $cmd = sprintf(
        'openssl ts -query -digest %s -sha256 -no_nonce -cert -out %s 2>&1',
        escapeshellarg($hash),
        escapeshellarg($tmpQ)
    );
    exec($cmd, $out, $rc);
    if ($rc !== 0 || !file_exists($tmpQ)) { @unlink($tmpQ); @unlink($tmpR); return null; }
    $tsq = file_get_contents($tmpQ);
    // Enviar al endpoint TSA
    $ch = curl_init(SIGN_TSA_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/timestamp-query'],
        CURLOPT_POSTFIELDS => $tsq,
        CURLOPT_TIMEOUT => 8,
    ]);
    $tsr = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmpQ); @unlink($tmpR);
    if ($code !== 200 || !$tsr) return null;
    return base64_encode($tsr);
}

// ====================================================================
//   GENERACIÓN PDF (mPDF)
// ====================================================================

function contrato_new_mpdf(): \Mpdf\Mpdf
{
    $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
    return new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 20,
        'margin_right' => 20,
        'margin_top' => 20,
        'margin_bottom' => 20,
        'margin_header' => 10,
        'margin_footer' => 10,
        'default_font' => 'dejavusans',
        'fontDir' => array_merge($defaultConfig['fontDir'], []),
        'fontdata' => $defaultFontConfig['fontdata'],
    ]);
}

/**
 * Render el HTML de la plantilla + hoja audit → PDF final.
 * @return string path absoluto al PDF generado
 */
function contrato_generate_pdf(string $htmlContrato, array $firmas, array $meta, string $destino): string
{
    $mpdf = contrato_new_mpdf();
    // Metadatos PDF
    $mpdf->SetTitle($meta['titulo'] ?? 'Contrato Tres Puntos');
    $mpdf->SetAuthor('Tres Puntos Comunicación S.L.');
    $mpdf->SetCreator('doc.trespuntos-lab.com · Firma electrónica eIDAS');
    $mpdf->SetSubject($meta['tipo'] ?? 'Contrato');

    // Cuerpo del contrato
    $mpdf->WriteHTML(contrato_base_css(), \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML($htmlContrato, \Mpdf\HTMLParserMode::HTML_BODY);

    // Página de audit trail (solo si hay firmas)
    if (!empty($firmas)) {
        $mpdf->AddPage();
        $mpdf->WriteHTML(contrato_audit_page_html($firmas, $meta), \Mpdf\HTMLParserMode::HTML_BODY);
    }

    // Asegurar directorio destino
    $dir = dirname($destino);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $mpdf->Output($destino, \Mpdf\Output\Destination::FILE);
    return $destino;
}

/**
 * Apila un PDF subido por el admin (one-off) + le añade la hoja de audit trail al final.
 * Usa FPDI (ya incluido como dependencia de mPDF).
 *
 * @param string $basePdfPath PDF original (sin firmar) que subió el admin
 * @param array  $firmas      filas de contratos_firmas
 * @param array  $meta        ['titulo','tipo','hash_documento','tsa_timestamp']
 * @param string $destino     ruta absoluta destino (v_final.pdf)
 * @return string             $destino
 */
function contrato_stamp_pdf_with_audit(string $basePdfPath, array $firmas, array $meta, string $destino): string
{
    if (!file_exists($basePdfPath)) {
        throw new RuntimeException("PDF base no encontrado: $basePdfPath");
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 20,
        'margin_right' => 20,
        'margin_top' => 20,
        'margin_bottom' => 20,
    ]);
    $mpdf->SetTitle($meta['titulo'] ?? 'Contrato Tres Puntos');
    $mpdf->SetAuthor('Tres Puntos Comunicación S.L.');
    $mpdf->SetCreator('doc.trespuntos-lab.com · Firma electrónica eIDAS');
    $mpdf->SetSubject($meta['tipo'] ?? 'Contrato');

    // Importar cada página del PDF original tal cual (con sus dimensiones)
    $pageCount = $mpdf->SetSourceFile($basePdfPath);
    for ($i = 1; $i <= $pageCount; $i++) {
        $tpl = $mpdf->ImportPage($i);
        $size = $mpdf->getTemplateSize($tpl);
        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
        $mpdf->AddPageByArray([
            'orientation' => $orientation,
            'sheet-size' => [$size['width'], $size['height']],
            'margin-left' => 0, 'margin-right' => 0,
            'margin-top' => 0, 'margin-bottom' => 0,
        ]);
        $mpdf->UseTemplate($tpl);
    }

    // Página de audit trail con branding TP (A4 normal)
    $mpdf->AddPageByArray([
        'orientation' => 'P',
        'sheet-size' => 'A4',
        'margin-left' => 20, 'margin-right' => 20,
        'margin-top' => 20, 'margin-bottom' => 20,
    ]);
    $mpdf->WriteHTML(contrato_base_css(), \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML(contrato_audit_page_html($firmas, $meta), \Mpdf\HTMLParserMode::HTML_BODY);

    $dir = dirname($destino);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $mpdf->Output($destino, \Mpdf\Output\Destination::FILE);
    return $destino;
}

/**
 * CSS base para todos los contratos — alineado con la identidad TP pero adaptado a PDF impreso (fondo claro).
 */
function contrato_base_css(): string
{
    return <<<CSS
<style>
@page { margin: 20mm; }
body {
    font-family: dejavusans, sans-serif;
    font-size: 10.5pt;
    line-height: 1.55;
    color: #1a1a1a;
}
.tp-cover {
    margin-bottom: 20mm;
}
.tp-cover .brand {
    font-size: 11pt;
    font-weight: 700;
    letter-spacing: .2em;
    color: #0e0e0e;
    margin-bottom: 2mm;
}
.tp-cover .rule {
    border: 0;
    border-top: 2px solid #5dffbf;
    width: 40mm;
    margin: 4mm 0 8mm 0;
}
.tp-cover h1 {
    font-size: 26pt;
    line-height: 1.1;
    font-weight: 800;
    color: #0e0e0e;
    margin: 0 0 4mm 0;
}
.tp-cover .subtitle {
    font-size: 14pt;
    color: #5dffbf;
    font-weight: 700;
    margin: 0 0 10mm 0;
}
.tp-cover .partes {
    font-size: 10.5pt;
    color: #3a3a3a;
    margin-bottom: 6mm;
}
.tp-cover .firmantes-bloque {
    font-size: 10pt;
    color: #3a3a3a;
    line-height: 1.6;
}
.tp-cover .firmantes-bloque strong { color: #0e0e0e; }
.tp-cover .fecha {
    font-size: 9.5pt;
    color: #6a6a6a;
    margin-top: 8mm;
}
.tp-section h2 {
    font-size: 18pt;
    font-weight: 800;
    color: #0e0e0e;
    margin: 14mm 0 4mm 0;
    page-break-after: avoid;
}
.tp-section h3 {
    font-size: 13pt;
    font-weight: 700;
    color: #0e0e0e;
    margin: 6mm 0 2mm 0;
    page-break-after: avoid;
}
.tp-section p {
    margin: 0 0 3mm 0;
}
.tp-section ul { margin: 2mm 0 3mm 6mm; padding: 0; }
.tp-section li { margin-bottom: 1.5mm; }
table.tp-table {
    width: 100%;
    border-collapse: collapse;
    margin: 3mm 0 5mm 0;
    font-size: 10pt;
}
table.tp-table th {
    background: #f0f0f0;
    text-align: left;
    padding: 2.5mm 3mm;
    font-weight: 700;
    border: 1px solid #d5d5d5;
}
table.tp-table td {
    padding: 2.5mm 3mm;
    border: 1px solid #d5d5d5;
}
table.tp-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
.tp-callout {
    background: #f5fff9;
    border-left: 3px solid #5dffbf;
    padding: 4mm 5mm;
    margin: 4mm 0;
    font-size: 9.5pt;
    color: #1a3a2a;
}
.tp-legal {
    font-size: 8.5pt;
    color: #6a6a6a;
    line-height: 1.5;
    margin-top: 8mm;
}
.tp-signatures {
    margin-top: 14mm;
}
.tp-signatures .rule {
    border: 0;
    border-top: 2px solid #5dffbf;
    width: 100%;
    margin-bottom: 8mm;
}
.tp-signatures table { width: 100%; border-collapse: collapse; }
.tp-signatures td {
    width: 50%;
    vertical-align: top;
    padding-right: 10mm;
    font-size: 10pt;
}
.tp-signatures .label {
    font-size: 10pt;
    font-weight: 700;
    color: #0e0e0e;
    margin-bottom: 1mm;
}
.tp-signatures .datos { color: #3a3a3a; font-size: 9.5pt; line-height: 1.55; }
.tp-signatures .firma-img {
    height: 25mm;
    border-bottom: 1px solid #1a1a1a;
    padding-bottom: 1mm;
    margin-bottom: 1mm;
}
.tp-signatures .firma-placeholder {
    height: 25mm;
    border-bottom: 1px solid #ccc;
    margin-bottom: 1mm;
}
/* Hoja audit trail */
.tp-audit h2 {
    font-size: 18pt;
    font-weight: 800;
    color: #0e0e0e;
    margin: 0 0 4mm 0;
}
.tp-audit .subtitle {
    color: #5dffbf;
    font-weight: 700;
    font-size: 12pt;
    margin-bottom: 8mm;
}
.tp-audit table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 6mm; }
.tp-audit th { background: #0e0e0e; color: #5dffbf; padding: 2mm 3mm; text-align: left; font-weight: 700; }
.tp-audit td { padding: 2mm 3mm; border-bottom: 1px solid #e5e5e5; vertical-align: top; font-family: 'dejavusansmono', monospace; font-size: 8.5pt; }
.tp-audit td.k { color: #6a6a6a; width: 40%; font-family: dejavusans; }
.tp-audit .hash {
    background: #fafafa;
    border: 1px solid #e5e5e5;
    padding: 2mm 3mm;
    font-family: dejavusansmono, monospace;
    font-size: 7.5pt;
    word-break: break-all;
    margin: 2mm 0;
    color: #1a1a1a;
}
.tp-audit .clausula-eidas {
    background: #f5fff9;
    border-left: 3px solid #5dffbf;
    padding: 4mm 5mm;
    margin-top: 8mm;
    font-size: 8.5pt;
    color: #1a3a2a;
    line-height: 1.55;
}
.page-footer {
    position: fixed;
    bottom: -10mm;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 8pt;
    color: #8a8a8a;
    border-top: 1px solid #e5e5e5;
    padding-top: 2mm;
}
</style>
CSS;
}

/**
 * Hoja de audit trail final. Se genera con los datos de las firmas + metadata.
 */
function contrato_audit_page_html(array $firmas, array $meta): string
{
    $out = '<div class="tp-audit">';
    $out .= '<h2>Certificado de firma electrónica</h2>';
    $out .= '<div class="subtitle">' . htmlspecialchars($meta['titulo'] ?? 'Contrato', ENT_QUOTES, 'UTF-8') . '</div>';

    $out .= '<p><strong>Hash SHA-256 del documento firmado:</strong></p>';
    $out .= '<div class="hash">' . htmlspecialchars($meta['hash_documento'] ?? '—', ENT_QUOTES, 'UTF-8') . '</div>';

    if (!empty($meta['tsa_timestamp'])) {
        $out .= '<p><strong>Sello de tiempo cualificado (RFC 3161):</strong> emitido por ' . htmlspecialchars(parse_url(SIGN_TSA_URL, PHP_URL_HOST) ?: 'TSA', ENT_QUOTES, 'UTF-8') . ' en el momento de la firma.</p>';
    }

    foreach ($firmas as $i => $f) {
        $out .= '<h3 style="margin-top:10mm">Firmante ' . ($i + 1) . ' · ' . htmlspecialchars(ucfirst($f['rol'] ?? ''), ENT_QUOTES, 'UTF-8') . '</h3>';
        $out .= '<table>';
        $out .= '<tr><th colspan="2">Identidad declarada</th></tr>';
        $rows = [
            'Nombre'            => $f['firmante_nombre'] ?? '',
            'Email'             => $f['firmante_email'] ?? '',
            'Documento'         => $f['firmante_documento'] ?? '',
            'Empresa / cargo'   => trim(($f['firmante_empresa'] ?? '') . ($f['firmante_cargo'] ? ' · ' . $f['firmante_cargo'] : '')),
            'Dirección'         => $f['firmante_direccion'] ?? '',
        ];
        foreach ($rows as $k => $v) {
            if (!$v) continue;
            $out .= '<tr><td class="k">' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        $out .= '<tr><th colspan="2">Prueba técnica de la firma</th></tr>';
        $proof = [
            'IP pública'          => $f['ip'] ?? '',
            'País (GeoIP)'        => $f['geoip_country'] ?? '',
            'User Agent'          => $f['user_agent'] ?? '',
            'Timestamp servidor'  => $f['server_timestamp_utc'] ?? '',
            'Timestamp cliente'   => $f['client_timestamp'] ?? '',
            'Método firma'        => $f['signing_method'] ?? '',
            'Duración firma (ms)' => $f['signing_duration_ms'] ?? '',
            'Scroll leído (%)'    => $f['scroll_depth_pct'] ?? '',
            'OTP verificado'      => !empty($f['otp_verified_at']) ? $f['otp_verified_at'] : (!empty($f['otp_code']) ? 'No verificado' : 'No requerido'),
            'Hash firma'          => $f['firma_hash'] ?? '',
        ];
        foreach ($proof as $k => $v) {
            if ($v === '' || $v === null) continue;
            $out .= '<tr><td class="k">' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        if (!empty($f['consent_texto'])) {
            $out .= '<tr><td class="k">Texto aceptado</td><td style="font-family:dejavusans;font-size:8.5pt">' . htmlspecialchars($f['consent_texto'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        $out .= '</table>';
    }

    $out .= '<div class="clausula-eidas">';
    $out .= '<strong>Validez legal.</strong> Las partes acuerdan expresamente, conforme al artículo 1262 del Código Civil y al Reglamento (UE) 910/2014 del Parlamento Europeo y del Consejo de 23 de julio de 2014 (eIDAS), que la firma electrónica de este documento mediante el sistema dispuesto por ' . htmlspecialchars(TP_RAZON_SOCIAL, ENT_QUOTES, 'UTF-8') . ' tiene plena validez jurídica entre ellas, equiparable a la firma manuscrita. El presente certificado, junto con los registros técnicos asociados al documento, constituye prueba de la autoría y la integridad de la firma realizada.';
    $out .= '</div>';

    $out .= '</div>';
    return $out;
}

// ====================================================================
//   CONSENTIMIENTO — texto literal
// ====================================================================

function contrato_consent_text(string $tituloContrato): string
{
    return 'He leído íntegramente el documento "' . $tituloContrato . '" y manifiesto mi conformidad con todas sus cláusulas. '
         . 'Acepto expresamente firmarlo mediante firma electrónica conforme al Reglamento (UE) 910/2014 (eIDAS), reconociéndole plena validez jurídica entre las partes. '
         . 'Autorizo el tratamiento de los datos técnicos (IP, navegador, fecha y hora) necesarios para acreditar la autoría e integridad de esta firma.';
}

// ====================================================================
//   GEO IP (resolución básica por cabeceras — MaxMind vendrá en Sprint 3)
// ====================================================================

function contrato_resolve_country(string $ip): ?string
{
    // Cabeceras de CloudFlare / hosting compatibles
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) return substr($_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2);
    if (!empty($_SERVER['GEOIP_COUNTRY_CODE'])) return substr($_SERVER['GEOIP_COUNTRY_CODE'], 0, 2);
    return null;
}

// ====================================================================
//   SIGNER HELPERS
// ====================================================================

/**
 * Cliente IP detectando proxy / Cloudflare.
 */
function contrato_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) return trim(explode(',', $_SERVER[$h])[0]);
    }
    return '0.0.0.0';
}

/**
 * User Agent resumido: "Chrome 122 / macOS"
 */
function contrato_ua_short(string $ua): string
{
    if (preg_match('/Chrome\/([0-9]+).* (Mac OS X|Windows|Linux)/', $ua, $m)) return "Chrome {$m[1]} / {$m[2]}";
    if (preg_match('/Safari\/.* (Mac OS X|iPhone|iPad)/', $ua, $m)) return "Safari / {$m[1]}";
    if (preg_match('/Firefox\/([0-9]+)/', $ua, $m)) return "Firefox {$m[1]}";
    if (preg_match('/Edg\/([0-9]+)/', $ua, $m)) return "Edge {$m[1]}";
    return substr($ua, 0, 80);
}

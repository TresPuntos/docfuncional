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
    $ttl = SIGN_OTP_TTL_MINUTES;
    $codigoFormatted = chunk_split($codigo, 3, ' ');
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $nombreSeguro = $e($nombre);
    $tituloSeguro = $e($tituloContrato);

    // Caja especial del código OTP (inline, no usa tp_render porque es visual único)
    $bodyHtml = 'Hola <strong>' . $nombreSeguro . '</strong>,<br><br>'
              . 'Para firmar <strong>' . $tituloSeguro . '</strong>, introduce este código en la pantalla de firma:'
              . '<div style="background:#141414;color:#ffffff;font-family:\'JetBrains Mono\',Menlo,Consolas,monospace;font-size:32px;letter-spacing:.25em;text-align:center;padding:24px;border-radius:10px;margin:24px 0 8px 0;font-weight:700">' . $e($codigoFormatted) . '</div>'
              . '<div style="font-size:12px;color:#8a8a8a;text-align:center">Válido durante ' . $ttl . ' minutos</div>';

    return tp_send_email($email, 'Código de firma · ' . $tituloContrato, [
        'preheader'   => 'Código para firmar ' . $tituloContrato . ' · válido ' . $ttl . ' min.',
        'title'       => 'Tu código de verificación',
        'body_html'   => $bodyHtml,
        'footer_note' => 'Este código se envía para verificar tu identidad conforme al Reglamento (UE) 910/2014 (eIDAS). Si no has iniciado ningún proceso de firma, ignora este email. El código caduca automáticamente en ' . $ttl . ' minutos.',
    ]);
}

/**
 * Devuelve la etiqueta <img> con el logo Tres Puntos embedido en base64.
 * Funciona en Gmail, Apple Mail, iOS Mail, Yahoo. Outlook desktop → fallback al alt text.
 *
 * @param int $width Ancho en px (alto se calcula proporcional: logo original 280×107).
 */
function tp_email_logo_img(int $width = 124): string
{
    static $cached = null;
    if ($cached === null) {
        $pngPath = __DIR__ . '/../master/brand/logo-print.png';
        $cached = file_exists($pngPath) ? base64_encode(file_get_contents($pngPath)) : '';
    }
    if (!$cached) {
        // Fallback si no hay PNG: wordmark text styled
        return '<span style="font-family:\'Plus Jakarta Sans\',Helvetica,Arial,sans-serif;font-size:13px;letter-spacing:.25em;font-weight:800;color:#0FA36C">TRES&nbsp;PUNTOS</span>';
    }
    $height = (int)round($width * 107 / 280);
    return '<img src="data:image/png;base64,' . $cached . '" width="' . $width . '" height="' . $height . '" alt="Tres Puntos" style="display:block;width:' . $width . 'px;height:' . $height . 'px;border:0;outline:none">';
}

/**
 * Valida DNI, NIE o CIF español. Devuelve ['valid'=>bool, 'type'=>'dni|nie|cif|null', 'reason'=>string]
 *
 * DNI: 8 dígitos + letra. Letra = TRWAGMYFPDXBNJZSQVHLCKE[num mod 23]
 * NIE: X/Y/Z + 7 dígitos + letra. Se sustituye X=0, Y=1, Z=2, misma tabla de letras.
 * CIF: letra tipo + 7 dígitos + carácter control.
 *      Tipo válido: ABCDEFGHJNPQRSUVW. Control = letra si tipo ∈ {P,Q,R,S,N,W} o si tipo ∈ {A,B,E,H} (para organismos).
 *      Algoritmo: suma pares + suma dígitos_impares*2 (sumando cifras del producto) → (10 - último dígito) mod 10
 *      → letra si aplica: JABCDEFGHI
 */
function tp_validar_dni_nie_cif(string $input): array
{
    $s = strtoupper(trim(str_replace([' ', '-', '.'], '', $input)));
    if ($s === '') return ['valid' => false, 'type' => null, 'reason' => 'Vacío'];

    $letras = 'TRWAGMYFPDXBNJZSQVHLCKE';

    // DNI: 8 dígitos + letra
    if (preg_match('/^([0-9]{8})([A-Z])$/', $s, $m)) {
        $num = (int)$m[1];
        $letraEsperada = $letras[$num % 23];
        if ($m[2] === $letraEsperada) return ['valid' => true, 'type' => 'dni', 'reason' => 'OK'];
        return ['valid' => false, 'type' => 'dni', 'reason' => "Letra de DNI incorrecta (debería ser $letraEsperada)"];
    }

    // NIE: X/Y/Z + 7 dígitos + letra
    if (preg_match('/^([XYZ])([0-9]{7})([A-Z])$/', $s, $m)) {
        $prefijo = ['X' => '0', 'Y' => '1', 'Z' => '2'][$m[1]];
        $num = (int)($prefijo . $m[2]);
        $letraEsperada = $letras[$num % 23];
        if ($m[3] === $letraEsperada) return ['valid' => true, 'type' => 'nie', 'reason' => 'OK'];
        return ['valid' => false, 'type' => 'nie', 'reason' => "Letra de NIE incorrecta (debería ser $letraEsperada)"];
    }

    // CIF: letra + 7 dígitos + letra/dígito
    if (preg_match('/^([ABCDEFGHJNPQRSUVW])([0-9]{7})([0-9A-J])$/', $s, $m)) {
        $digits = $m[2];
        $sumPares = 0; $sumImpares = 0;
        for ($i = 0; $i < 7; $i++) {
            $d = (int)$digits[$i];
            if ($i % 2 === 0) {
                $producto = $d * 2;
                $sumImpares += ($producto > 9) ? ($producto - 9) : $producto;
            } else {
                $sumPares += $d;
            }
        }
        $total = $sumPares + $sumImpares;
        $lastDigit = $total % 10;
        $controlNum = ($lastDigit === 0) ? 0 : (10 - $lastDigit);
        $controlLetra = 'JABCDEFGHI'[$controlNum];

        $tipo = $m[1];
        $control = $m[3];
        $ok = false;
        // Organizaciones que exigen LETRA: P, Q, R, S, N, W
        if (strpos('PQRSNW', $tipo) !== false) {
            $ok = ($control === $controlLetra);
        }
        // Organizaciones que exigen DÍGITO: A, B, E, H
        elseif (strpos('ABEH', $tipo) !== false) {
            $ok = ($control === (string)$controlNum);
        }
        // Resto: acepta cualquiera de las dos
        else {
            $ok = ($control === $controlLetra) || ($control === (string)$controlNum);
        }
        if ($ok) return ['valid' => true, 'type' => 'cif', 'reason' => 'OK'];
        return ['valid' => false, 'type' => 'cif', 'reason' => "Carácter de control CIF incorrecto (debería ser $controlLetra o $controlNum)"];
    }

    return ['valid' => false, 'type' => null, 'reason' => 'Formato no reconocido · esperado 12345678A (DNI), X1234567A (NIE) o A12345678 (CIF)'];
}

/**
 * Render del layout estándar Tres Puntos para TODOS los emails transaccionales.
 *
 * Cumplimiento email-safe:
 *   - Tables + inline styles (Outlook, Yahoo, dark mode clients lo requieren)
 *   - Dual-mode: funciona en clientes con fondo claro y con fondo oscuro
 *   - Max 600px ancho, fuentes web-safe con fallbacks, responsive básico
 *
 * @param array $opts  ['preheader','title','intro','highlight','cta_label','cta_url','body_html','fallback_url','footer_note']
 * @return string HTML completo listo para enviar
 */
function tp_render_email_layout(array $opts): string
{
    $preheader    = $opts['preheader']    ?? '';
    $title        = $opts['title']        ?? '';
    $intro        = $opts['intro']        ?? '';
    $highlight    = $opts['highlight']    ?? '';
    $ctaLabel     = $opts['cta_label']    ?? '';
    $ctaUrl       = $opts['cta_url']      ?? '';
    $bodyHtml     = $opts['body_html']    ?? '';
    $fallbackUrl  = $opts['fallback_url'] ?? '';
    $footerNote   = $opts['footer_note']  ?? 'Este email es parte del sistema de documentos funcionales y contratos de Tres Puntos.';

    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $tpRazon = defined('TP_RAZON_SOCIAL') ? TP_RAZON_SOCIAL : 'Tres Puntos Comunicación S.L.';
    $tpEmail = defined('TP_EMAIL_CONTACTO') ? TP_EMAIL_CONTACTO : 'jordi@trespuntoscomunicacion.es';
    $tpDir   = defined('TP_DIRECCION') ? TP_DIRECCION : 'Barcelona';

    // Preheader: texto invisible que aparece como preview en el inbox
    $preheaderHtml = $preheader
        ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;visibility:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#ffffff">' . $e($preheader) . '</div>'
        : '';

    // CTA bulletproof (funciona en Outlook)
    $ctaHtml = '';
    if ($ctaLabel && $ctaUrl) {
        $ctaHtml = '
        <tr><td style="padding:8px 40px 32px 40px" align="left">
            <table cellspacing="0" cellpadding="0" border="0"><tr>
                <td align="center" bgcolor="#0FA36C" style="border-radius:10px">
                    <a href="' . $e($ctaUrl) . '" target="_blank" style="display:inline-block;background:#0FA36C;color:#ffffff;font-family:Inter,Helvetica,Arial,sans-serif;font-size:15px;font-weight:700;line-height:1;text-decoration:none;padding:16px 28px;border-radius:10px;mso-padding-alt:0">
                        <!--[if mso]>&nbsp;&nbsp;&nbsp;&nbsp;<![endif]-->' . $e($ctaLabel) . '<!--[if mso]>&nbsp;&nbsp;&nbsp;&nbsp;<![endif]-->
                    </a>
                </td>
            </tr></table>
        </td></tr>';
    }

    $highlightHtml = $highlight
        ? '<tr><td style="padding:0 40px 24px 40px">
            <div style="background:#F7F6F3;border-left:4px solid #0FA36C;padding:16px 20px;border-radius:6px;color:#141414;font-size:15px;font-weight:600;line-height:1.5">' . $e($highlight) . '</div>
        </td></tr>'
        : '';

    $introHtml = $intro
        ? '<tr><td style="padding:0 40px 16px 40px;color:#3a3a3a;font-family:Inter,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6">' . $intro . '</td></tr>'
        : '';

    $bodyBlock = $bodyHtml
        ? '<tr><td style="padding:0 40px 16px 40px;color:#3a3a3a;font-family:Inter,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6">' . $bodyHtml . '</td></tr>'
        : '';

    $fallbackHtml = $fallbackUrl
        ? '<tr><td style="padding:0 40px 32px 40px">
            <div style="background:#f5f5f4;border-radius:6px;padding:12px 16px;color:#6a6a6a;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.5">
                <strong style="color:#141414;display:block;margin-bottom:4px;font-size:11px;text-transform:uppercase;letter-spacing:.08em">¿El botón no funciona?</strong>
                <span style="word-break:break-all;font-family:\'JetBrains Mono\',Menlo,Consolas,monospace;font-size:11px">' . $e($fallbackUrl) . '</span>
            </div>
        </td></tr>'
        : '';

    return '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>' . $e($title) . '</title>
<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
</head>
<body style="margin:0;padding:0;background:#F7F6F3;font-family:Inter,-apple-system,BlinkMacSystemFont,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased">
' . $preheaderHtml . '
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F7F6F3">
    <tr><td align="center" style="padding:32px 16px 48px 16px">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px">

            <!-- Header brand bar con logo base64 (email-safe, Gmail/Apple/iOS; Outlook fallback alt) -->
            <tr><td style="padding:0 0 20px 0" align="left">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>
                    <td>' . tp_email_logo_img(124) . '</td>
                </tr></table>
            </td></tr>

            <!-- Card -->
            <tr><td style="background:#ffffff;border:1px solid #eeece7;border-radius:14px;overflow:hidden">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

                    <!-- Top accent stripe -->
                    <tr><td style="background:#0FA36C;height:4px;line-height:4px;font-size:0">&nbsp;</td></tr>

                    <!-- Title -->
                    <tr><td style="padding:36px 40px 8px 40px">
                        <h1 style="margin:0;font-family:\'Plus Jakarta Sans\',Helvetica,Arial,sans-serif;font-size:24px;line-height:1.25;font-weight:800;color:#141414;letter-spacing:-.01em">' . $e($title) . '</h1>
                    </td></tr>

                    ' . $introHtml . '
                    ' . $highlightHtml . '
                    ' . $bodyBlock . '
                    ' . $ctaHtml . '
                    ' . $fallbackHtml . '

                    <!-- Divider -->
                    <tr><td style="padding:0 40px"><div style="border-top:1px solid #eeece7;height:1px;line-height:1px;font-size:0">&nbsp;</div></td></tr>

                    <!-- Legal note -->
                    <tr><td style="padding:20px 40px 8px 40px;color:#8a8a8a;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6">' . $e($footerNote) . '</td></tr>

                    <!-- Signature -->
                    <tr><td style="padding:8px 40px 32px 40px;color:#3a3a3a;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.55">
                        <strong style="color:#141414">Jordan · Tres Puntos</strong><br>
                        <span style="color:#8a8a8a">Asistente IA · Partner cercano</span>
                    </td></tr>
                </table>
            </td></tr>

            <!-- Footer identificación empresa -->
            <tr><td style="padding:24px 8px 0 8px;text-align:center;color:#8a8a8a;font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;line-height:1.65">
                <strong style="color:#6a6a6a">' . $e($tpRazon) . '</strong> · ' . $e($tpDir) . '<br>
                <a href="mailto:' . $e($tpEmail) . '" style="color:#0FA36C;text-decoration:none">' . $e($tpEmail) . '</a>
                &nbsp;·&nbsp;
                <a href="https://trespuntoscomunicacion.es" style="color:#0FA36C;text-decoration:none">trespuntoscomunicacion.es</a>
            </td></tr>
            <tr><td style="padding:14px 8px 0 8px;text-align:center;color:#b3b3b3;font-family:Inter,Helvetica,Arial,sans-serif;font-size:10px;line-height:1.5">
                Si recibiste este email por error, puedes ignorarlo sin problema.<br>
                No respondas a este mensaje · contacta directamente con nosotros.
            </td></tr>

        </table>
    </td></tr>
</table>
</body></html>';
}

/**
 * Wrapper HTTP para enviar emails via Resend usando el layout estándar.
 * Devuelve true si Resend respondió 2xx.
 */
function tp_send_email(string $to, string $subject, array $layoutOpts, ?string $replyTo = null): bool
{
    if (!defined('RESEND_API_KEY') || !RESEND_API_KEY) return false;
    $html = tp_render_email_layout($layoutOpts);
    $payload = [
        'from' => defined('RESEND_FROM') ? RESEND_FROM : 'Tres Puntos <noreply@trespuntos-lab.com>',
        'to' => [$to],
        'reply_to' => $replyTo ?: (defined('RESEND_REPLY_TO') ? RESEND_REPLY_TO : 'jordi@trespuntoscomunicacion.es'),
        'subject' => $subject,
        'html' => $html,
    ];
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/**
 * Email al firmante cuando el admin envía el contrato.
 */
function contrato_send_invite_email(string $email, string $nombre, string $titulo, string $signUrl): bool
{
    $tpRazon = defined('TP_RAZON_SOCIAL') ? TP_RAZON_SOCIAL : 'Tres Puntos Comunicación S.L.';
    $nombreSeguro = htmlspecialchars($nombre ?: 'firmante', ENT_QUOTES, 'UTF-8');
    $tpRazonSeguro = htmlspecialchars($tpRazon, ENT_QUOTES, 'UTF-8');
    return tp_send_email($email, 'Firma pendiente · ' . $titulo, [
        'preheader'    => $tpRazon . ' te ha enviado un contrato para firmar electrónicamente.',
        'title'        => 'Tienes un contrato para firmar',
        'intro'        => 'Hola <strong>' . $nombreSeguro . '</strong>,<br><br>' . $tpRazonSeguro . ' te ha enviado para firma electrónica el siguiente documento:',
        'highlight'    => $titulo,
        'body_html'    => 'Haz clic en el botón, revisa el documento completo y firma al final. Todo el proceso dura menos de <strong>2 minutos</strong> y queda registrado con plena validez jurídica.',
        'cta_label'    => 'Firmar contrato →',
        'cta_url'      => $signUrl,
        'fallback_url' => $signUrl,
        'footer_note'  => 'Este documento se firma electrónicamente conforme al Reglamento (UE) 910/2014 (eIDAS). La firma tendrá plena validez jurídica entre las partes. Al firmar, capturamos datos técnicos (IP, navegador, fecha y hora, hash SHA-256) como prueba de autoría e integridad.',
    ]);
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

    // Auto-inyectar el logo SVG dentro del primer .tp-cover si no hay ya <img class="tp-logo">
    $htmlContrato = contrato_inject_logo($htmlContrato);

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
 * Inyecta el logo SVG print de Tres Puntos al inicio del .tp-cover.
 * Reemplaza el texto "<div class='brand'>TRES PUNTOS</div>" por el logo SVG directamente.
 * Si ya hay un <img class="tp-logo"> no hace nada.
 */
function contrato_inject_logo(string $html): string
{
    if (strpos($html, 'tp-logo') !== false) return $html;
    $logoPath = __DIR__ . '/../master/brand/logo-print.svg';
    if (!file_exists($logoPath)) return $html;
    // mPDF acepta img con src=file:// o ruta absoluta
    $imgTag = '<img class="tp-logo" src="' . $logoPath . '" alt="Tres Puntos" />';
    // Reemplazar la primera aparición de <div class="brand">...</div> por logo + brand (o solo logo)
    $replaced = preg_replace(
        '/<div\s+class=["\']brand["\']\s*>[^<]*<\/div>/u',
        $imgTag,
        $html,
        1
    );
    return $replaced ?? $html;
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
    // Paleta LIGHT/PRINT oficial (Notion "Design System Light + Dark"):
    // --tp-mint: #0FA36C (oscurecido para AA sobre blanco)
    // --tp-bg-base: #F7F6F3 · --tp-bg-surface: #FFFFFF · --tp-text-primary: #141414
    return <<<CSS
<style>
@page { margin: 22mm 20mm 22mm 20mm; }
body {
    font-family: dejavusans, sans-serif;
    font-size: 10.5pt;
    line-height: 1.6;
    color: #141414;
    background: #ffffff;
}
.tp-cover {
    margin-bottom: 20mm;
}
.tp-cover .tp-logo {
    width: 42mm;
    height: auto;
    margin-bottom: 6mm;
}
.tp-cover .brand {
    font-size: 10pt;
    font-weight: 700;
    letter-spacing: .22em;
    color: #0FA36C;
    margin-bottom: 3mm;
}
.tp-cover .rule {
    border: 0;
    border-top: 2px solid #0FA36C;
    width: 40mm;
    margin: 4mm 0 8mm 0;
}
.tp-cover h1 {
    font-size: 26pt;
    line-height: 1.1;
    font-weight: 800;
    color: #141414;
    margin: 0 0 4mm 0;
}
.tp-cover .subtitle {
    font-size: 13pt;
    color: #0FA36C;
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
    line-height: 1.7;
    background: #F7F6F3;
    padding: 4mm 5mm;
    border-radius: 4px;
}
.tp-cover .firmantes-bloque strong { color: #141414; }
.tp-cover .fecha {
    font-size: 9.5pt;
    color: #6a6a6a;
    margin-top: 8mm;
}
.tp-section h2 {
    font-size: 18pt;
    font-weight: 800;
    color: #141414;
    margin: 14mm 0 4mm 0;
    page-break-after: avoid;
}
.tp-section h3 {
    font-size: 12.5pt;
    font-weight: 700;
    color: #141414;
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
    background: #141414;
    color: #ffffff;
    text-align: left;
    padding: 2.5mm 3mm;
    font-weight: 700;
    border: 1px solid #141414;
    letter-spacing: .02em;
}
table.tp-table td {
    padding: 2.5mm 3mm;
    border: 1px solid #d5d5d5;
    background: #ffffff;
}
table.tp-table tr:nth-child(even) td { background: #fafafa; }
table.tp-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
.tp-callout {
    background: #F7F6F3;
    border-left: 3px solid #0FA36C;
    padding: 4mm 5mm;
    margin: 4mm 0;
    font-size: 9.5pt;
    color: #2a2a2a;
}
.tp-legal {
    font-size: 8.5pt;
    color: #6a6a6a;
    line-height: 1.5;
    margin-top: 8mm;
}
/* Hoja audit trail */
.tp-audit h2 {
    font-size: 18pt;
    font-weight: 800;
    color: #141414;
    margin: 0 0 4mm 0;
}
.tp-audit .subtitle {
    color: #0FA36C;
    font-weight: 700;
    font-size: 12pt;
    margin-bottom: 8mm;
}
.tp-audit table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 6mm; }
.tp-audit th { background: #141414; color: #ffffff; padding: 2mm 3mm; text-align: left; font-weight: 700; }
.tp-audit td { padding: 2mm 3mm; border-bottom: 1px solid #e5e5e5; vertical-align: top; font-family: 'dejavusansmono', monospace; font-size: 8.5pt; color: #141414; }
.tp-audit td.k { color: #6a6a6a; width: 40%; font-family: dejavusans; }
.tp-audit .hash {
    background: #F7F6F3;
    border: 1px solid #e5e5e5;
    padding: 2mm 3mm;
    font-family: dejavusansmono, monospace;
    font-size: 7.5pt;
    word-break: break-all;
    margin: 2mm 0;
    color: #141414;
}
.tp-audit .clausula-eidas {
    background: #F7F6F3;
    border-left: 3px solid #0FA36C;
    padding: 4mm 5mm;
    margin-top: 8mm;
    font-size: 8.5pt;
    color: #2a2a2a;
    line-height: 1.6;
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

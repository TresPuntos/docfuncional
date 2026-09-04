<?php
/**
 * Seed — Plantilla "Acta de aprobación de diseño UX/UI".
 *
 * Documento de UNA HOJA con el que el cliente da por aprobado el diseño,
 * autoriza el paso a desarrollo y acepta que a partir de ese momento
 * cualquier cambio de diseño se presupuesta aparte.
 *
 * Idempotente: no toca la plantilla si el slug ya existe.
 *
 * Uso:  php database/seed_plantilla_aprobacion_diseno.php
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();
$log = [];

$slug = 'acta-aprobacion-diseno';

$exists = $pdo->prepare("SELECT id FROM contratos_plantillas WHERE slug = ?");
$exists->execute([$slug]);
if ($exists->fetchColumn()) {
    $isCli = php_sapi_name() === 'cli';
    if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
    echo "= {$slug} (ya existe, no se toca)\n";
    exit;
}

$html = <<<HTML
<div class="tp-cover" style="margin-bottom:3mm">
    <div class="brand">TRES PUNTOS</div>
    <hr class="rule" style="margin:2mm 0 4mm 0">
    <h1 style="font-size:19pt;margin:0 0 1.5mm 0">Acta de aprobación de diseño UX/UI</h1>
    <div class="subtitle" style="font-size:11pt;margin:0 0 4mm 0">{{proyecto}}</div>
    <div class="firmantes-bloque" style="padding:3mm 4mm;line-height:1.5">
        <div><strong>{{tp_razon_social}}</strong> · CIF {{tp_cif}} — en adelante, Tres Puntos</div>
        <div><strong>{{cliente_razon_social}}</strong> · CIF {{cliente_cif}} — en adelante, el Cliente</div>
    </div>
    <div class="fecha" style="margin-top:2mm">{{fecha_contrato|date}}</div>
</div>

<div class="tp-section" style="font-size:10pt;line-height:1.42">
<h2 style="font-size:12.5pt;margin:0 0 1.5mm 0">1. Objeto</h2>
<p style="margin:0 0 2mm 0">Este documento recoge la conformidad del Cliente con el diseño de experiencia de usuario e interfaz (UX/UI) elaborado por Tres Puntos para {{proyecto}}, y habilita el inicio de la fase de desarrollo.</p>

<h2 style="font-size:12.5pt;margin:4mm 0 1.5mm 0">2. Diseño que se aprueba</h2>
<p style="margin:0 0 2mm 0">El diseño aprobado es el prototipo navegable publicado en <strong>{{url_prototipo}}</strong>, en el estado en que se encuentra a fecha de la firma de este documento. Comprende la arquitectura de la información, los flujos de navegación, las plantillas de página, el sistema visual (tipografía, color, iconografía y escalas de espaciado) y los componentes de interfaz que lo forman. Tres Puntos conserva una copia del prototipo en ese estado exacto, que sirve como referencia de lo aprobado a todos los efectos de este acta.</p>

<h2 style="font-size:12.5pt;margin:4mm 0 1.5mm 0">3. Paso a desarrollo</h2>
<p style="margin:0 0 2mm 0">Con la firma de este documento el Cliente da por cerrada la fase de UX/UI y autoriza a Tres Puntos a iniciar el desarrollo, tomando el prototipo aprobado como especificación de partida.</p>

<h2 style="font-size:12.5pt;margin:4mm 0 1.5mm 0">4. Cambios posteriores</h2>
<p style="margin:0 0 2mm 0"><strong>A partir de esta firma el diseño queda cerrado.</strong> Cualquier modificación posterior de la arquitectura de la información, los flujos, las plantillas, el sistema visual o los componentes queda fuera del alcance aprobado: Tres Puntos la valorará y la presupuestará por separado, indicando su coste y su impacto en el plazo, antes de ejecutarla.</p>
<p style="margin:0 0 2mm 0">Se exceptúa la sustitución de textos e imágenes dentro de las plantillas ya aprobadas, que no se considera un cambio de diseño siempre que no altere su estructura ni obligue a rehacerlas.</p>

<h2 style="font-size:12.5pt;margin:4mm 0 1.5mm 0">5. Aceptación</h2>
<p style="margin:0 0 2mm 0">El Cliente declara haber revisado el prototipo indicado en el punto 2 y lo aprueba en los términos recogidos en este documento.</p>
<div class="tp-callout" style="margin:2.5mm 0;padding:3mm 4mm">Este documento se firma electrónicamente conforme al Reglamento (UE) 910/2014 (eIDAS). La identidad del firmante, la fecha y la huella del documento quedan en el certificado adjunto.</div>
</div>
HTML;

$stmt = $pdo->prepare("
    INSERT INTO contratos_plantillas
    (slug, nombre, tipo, destinatario, html_content, variables_json, firmantes_json, require_otp, require_tsa, retencion_anios, version, activo)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
");
$stmt->execute([
    $slug,
    'Acta de aprobación de diseño UX/UI',
    'acta_diseno',
    'cliente',
    $html,
    json_encode([
        ['name' => 'proyecto',             'label' => 'Proyecto (subtítulo)',        'type' => 'text',  'default' => ''],
        ['name' => 'tp_razon_social',      'label' => 'Razón social Tres Puntos',    'type' => 'text',  'default' => 'Tres Puntos Comunicación S.L.'],
        ['name' => 'tp_cif',               'label' => 'CIF Tres Puntos',             'type' => 'text',  'default' => 'B66018490'],
        ['name' => 'cliente_razon_social', 'label' => 'Razón social del cliente',    'type' => 'text',  'default' => ''],
        ['name' => 'cliente_cif',          'label' => 'CIF del cliente',             'type' => 'text',  'default' => ''],
        ['name' => 'url_prototipo',        'label' => 'URL del prototipo aprobado',  'type' => 'text',  'default' => ''],
        ['name' => 'fecha_contrato',       'label' => 'Fecha del acta',              'type' => 'date',  'default' => ''],
    ], JSON_UNESCAPED_UNICODE),
    json_encode(['cliente', 'tp'], JSON_UNESCAPED_UNICODE),
    0,  // require_otp
    1,  // require_tsa
    6,  // retención años
    1,
]);

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "+ {$slug} creada (id " . $pdo->lastInsertId() . ")\n";

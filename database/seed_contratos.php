<?php
/**
 * Seed — Plantillas iniciales de contratos.
 *
 * Idempotente: usa INSERT OR IGNORE por slug.
 *
 * Plantillas:
 *   1) nda-subcontratacion-tp        — NDA + subcontratación con proveedor (la usada con Truman)
 *   2) msa-cliente                    — Acuerdo marco de servicios con cliente
 *   3) sow-cliente                    — Statement of work derivado de propuesta aprobada
 *   4) dpa-cliente                    — Acuerdo de tratamiento de datos (RGPD art. 28)
 *   5) change-order                   — Mini-contrato para ampliaciones de alcance
 *
 * Uso:  php database/seed_contratos.php
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

$log = [];

function upsert_plantilla(PDO $pdo, array $p, array &$log): void {
    $exists = $pdo->prepare("SELECT id FROM contratos_plantillas WHERE slug = ?");
    $exists->execute([$p['slug']]);
    if ($exists->fetchColumn()) {
        $log[] = "= {$p['slug']} (ya existe)";
        return;
    }
    $stmt = $pdo->prepare("
        INSERT INTO contratos_plantillas
        (slug, nombre, tipo, destinatario, html_content, variables_json, firmantes_json, require_otp, require_tsa, retencion_anios, version, activo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        $p['slug'],
        $p['nombre'],
        $p['tipo'],
        $p['destinatario'],
        $p['html'],
        json_encode($p['variables'], JSON_UNESCAPED_UNICODE),
        json_encode($p['firmantes'], JSON_UNESCAPED_UNICODE),
        $p['require_otp'] ?? 0,
        $p['require_tsa'] ?? 1,
        $p['retencion_anios'] ?? 6,
        $p['version'] ?? 1,
    ]);
    $log[] = "+ {$p['slug']}";
}

// ====================================================================
//   PLANTILLA 1 · NDA + SUBCONTRATACIÓN PROVEEDOR (la del PDF de Truman)
// ====================================================================

$ndaHtml = <<<HTML
<div class="tp-cover">
    <div class="brand">TRES PUNTOS</div>
    <hr class="rule">
    <h1>{{titulo_contrato}}</h1>
    <div class="subtitle">{{servicio}}</div>
    <div class="partes">Entre {{tp_razon_social}} y {{proveedor_razon_social}}</div>
    <div class="firmantes-bloque">
        <div style="margin-bottom:2mm"><strong>Partes firmantes</strong></div>
        <div><strong>{{tp_razon_social}}</strong> (contratante)</div>
        <div><strong>{{proveedor_razon_social}}</strong> (proveedor)</div>
    </div>
    <div class="fecha">{{fecha_contrato|date}}<br><em style="color:#8a8a8a">Documento confidencial entre las partes</em></div>
</div>

<div class="tp-section">
<h2>Objeto del contrato</h2>
<p>El presente contrato regula la relación de subcontratación entre {{tp_razon_social}} (en adelante, "{{tp_alias}}") y {{proveedor_razon_social}} (en adelante, "{{proveedor_alias}}") para la prestación del servicio de {{servicio_descripcion}}, cuyo cliente final es {{cliente_final}}.</p>
<p>{{tp_alias}} actúa como interlocutor principal frente al cliente final y responsable de la capa UX/UI y coordinación del servicio. {{proveedor_alias}} presta los servicios técnicos de desarrollo, corrección de errores y mantenimiento de la capa aplicativa ({{stack_tecnico}}) conforme a las condiciones establecidas en este documento.</p>
<p>Las condiciones aquí recogidas están alineadas con las que {{tp_alias}} ofrece al cliente final, de forma que el servicio prestado por {{proveedor_alias}} sirva como cobertura técnica íntegra del contrato con el cliente.</p>
</div>

<div class="tp-section">
<h2>Índice de cláusulas</h2>
<p>El contrato se estructura en los siguientes bloques:</p>
<ul>
    <li>1. Servicios incluidos y horas mensuales</li>
    <li>2. Condiciones económicas</li>
    <li>3. Horas adicionales fuera del mantenimiento</li>
    <li>4. Actualizaciones mayores forzadas por stores</li>
    <li>5. Acumulación de horas mensuales</li>
    <li>6. Definición de ajuste menor y fuera de alcance</li>
    <li>7. Repositorio Git y propiedad del código</li>
    <li>8. Duración, rescisión anticipada y cláusula espejo</li>
    <li>9. Transferencia de conocimiento al finalizar</li>
    <li>10. Confidencialidad y condiciones generales</li>
</ul>
</div>

<div class="tp-section">
<h2>1. Servicios incluidos y horas mensuales</h2>
<p>{{proveedor_alias}} presta a {{tp_alias}} los siguientes servicios para la aplicación {{nombre_aplicacion}}, conforme al plan que {{tp_alias}} tenga contratado con el cliente final en cada momento:</p>

<h3>Plan básico</h3>
<ul>
    <li>{{plan_basico_horas}} horas mensuales de desarrollo y mantenimiento aplicativo</li>
    <li>Corrección de bugs, ajustes menores y actualizaciones de seguridad</li>
    <li>Soporte técnico durante horario laboral (lunes a viernes, horario habitual)</li>
    <li>Actualizaciones patch y minor dentro de las horas disponibles</li>
</ul>

<h3>Plan avanzado</h3>
<ul>
    <li>{{plan_avanzado_horas}} horas mensuales de desarrollo y mantenimiento aplicativo</li>
    <li>Todas las prestaciones del plan básico</li>
    <li>Mayor capacidad para absorber picos puntuales de actividad</li>
    <li>Prioridad en la atención de incidencias</li>
</ul>

<p>{{tp_alias}} comunicará a {{proveedor_alias}}, con la antelación razonable necesaria, el plan contratado por el cliente final. {{proveedor_alias}} se compromete a prestar el servicio conforme al plan vigente en cada momento.</p>
</div>

<div class="tp-section">
<h2>2. Condiciones económicas</h2>
<p>Las tarifas que {{tp_alias}} abona a {{proveedor_alias}} por la prestación del servicio son las siguientes:</p>
<table class="tp-table">
    <thead><tr><th>Plan</th><th>Horas/mes</th><th class="num">Tarifa mensual</th></tr></thead>
    <tbody>
    <tr><td><strong>Plan básico</strong></td><td>{{plan_basico_horas}} horas</td><td class="num"><strong>{{tarifa_basico|money}} (+ IVA)</strong></td></tr>
    <tr><td><strong>Plan avanzado</strong></td><td>{{plan_avanzado_horas}} horas</td><td class="num"><strong>{{tarifa_avanzado|money}} (+ IVA)</strong></td></tr>
    </tbody>
</table>

<h3>Estabilidad tarifaria</h3>
<p>Las tarifas mensuales son fijas durante los 12 meses iniciales del contrato, sin posibilidad de revisión al alza durante ese período.</p>
<p>Superado el primer año, cualquier revisión de tarifa en renovación se comunica por escrito con al menos 60 días de antelación al vencimiento del contrato, permitiendo a {{tp_alias}} evaluar su continuidad.</p>

<h3>Facturación y forma de pago</h3>
<p>{{proveedor_alias}} emite factura mensual por el servicio prestado. La factura se abona por transferencia bancaria a 30 días desde su emisión, salvo acuerdo distinto entre las partes.</p>
</div>

<div class="tp-section">
<h2>3. Horas adicionales fuera del mantenimiento</h2>
<p>Cuando surjan necesidades puntuales de capacidad adicional que excedan las horas mensuales contratadas, o trabajos calificados como fuera de alcance conforme a la cláusula 6 de este contrato, se aplica la siguiente tarifa horaria:</p>
<table class="tp-table">
    <tbody><tr><td><strong>Hora adicional de desarrollo y mantenimiento</strong></td><td class="num"><strong>{{tarifa_hora|money}}/h (+ IVA)</strong></td></tr></tbody>
</table>
<p>Esta tarifa horaria es fija durante los 12 meses iniciales del contrato y se aplica a todos los trabajos puntuales que {{tp_alias}} encargue a {{proveedor_alias}} fuera del alcance del mantenimiento mensual.</p>
<p>Antes de ejecutar cualquier trabajo fuera de alcance, {{proveedor_alias}} presenta a {{tp_alias}} una estimación cerrada de horas. {{tp_alias}} confirma por escrito (email es suficiente) antes de iniciar el trabajo. Sin esa confirmación previa, {{proveedor_alias}} no procede y no puede facturar horas adicionales.</p>
</div>

<div class="tp-section">
<h2>4. Actualizaciones mayores forzadas por stores</h2>
<p>Las actualizaciones mayores de stack ({{stack_tecnico}}) impuestas por Apple o Google, cuando son requisito obligatorio para que la aplicación continúe disponible en las stores, se tratan del siguiente modo:</p>
<ul>
    <li>Si el trabajo estimado entra en las horas mensuales disponibles del plan vigente, se ejecuta dentro del mantenimiento sin coste adicional.</li>
    <li>Si el trabajo estimado excede las horas mensuales, {{proveedor_alias}} presenta a {{tp_alias}} un presupuesto cerrado antes de iniciar la migración, con un margen temporal razonable respecto al plazo impuesto por Apple o Google.</li>
    <li>El presupuesto se calcula aplicando la tarifa horaria de {{tarifa_hora|money}}/h recogida en la cláusula 3.</li>
</ul>
<p>{{tp_alias}} informará al cliente final en los términos pactados con este, manteniendo la coordinación necesaria para que la migración se ejecute dentro del plazo de la store.</p>
</div>

<div class="tp-section">
<h2>5. Acumulación de horas mensuales</h2>
<p>Las horas mensuales contratadas no son acumulables entre meses. El servicio de mantenimiento está concebido como soporte técnico continuo que garantiza disponibilidad recurrente, no como una bolsa de horas acumulable.</p>
<p>Las horas no consumidas en un mes se consideran perdidas y no generan crédito a favor de {{tp_alias}} ni del cliente final en meses posteriores.</p>
<p>Para cubrir necesidades puntuales de mayor capacidad, {{tp_alias}} puede solicitar horas adicionales conforme a la cláusula 3 del presente contrato.</p>
</div>

<div class="tp-section">
<h2>6. Definición de ajuste menor y fuera de alcance</h2>
<p>Para evitar interpretaciones divergentes entre las partes, se establece el siguiente criterio alineado con el estándar semántico de versionado (X.Y.Z):</p>
<table class="tp-table">
    <thead><tr><th>Tipo</th><th>Descripción</th><th>Alcance</th></tr></thead>
    <tbody>
    <tr><td><strong>Patch (x.x.Z)</strong></td><td>Corrección de bug puntual, actualización de seguridad menor, ajuste interno sin impacto funcional.</td><td><strong>Siempre incluido</strong></td></tr>
    <tr><td><strong>Minor (x.Y.x)</strong></td><td>Actualización de librerías sin cambios relevantes, pequeños ajustes de compatibilidad, funcionalidades retrocompatibles.</td><td>Incluido si entra en horas del mes</td></tr>
    <tr><td><strong>Major (X.x.x)</strong></td><td>Migración de stack que implique cambios de código o pruebas adicionales, cambios sustanciales de iOS o Android que afecten a la app.</td><td>Evaluado caso a caso, incluido si entra en horas</td></tr>
    </tbody>
</table>

<h3>Criterio funcional complementario</h3>
<p><strong>Incluido en el mantenimiento:</strong> cambios de textos, ajustes visuales puntuales, corrección de errores de interfaz, modificación del comportamiento de funcionalidades existentes sin alterar su lógica, optimizaciones menores de rendimiento.</p>
<p><strong>Fuera de alcance (se presupuesta aparte conforme a la cláusula 3):</strong> nuevas pantallas, nuevos flujos de usuario, rediseños completos de interfaz, incorporación de nueva lógica de negocio, integraciones con sistemas externos no contempladas.</p>
</div>

<div class="tp-section">
<h2>7. Repositorio Git y propiedad del código</h2>
<p>La propiedad del código fuente de la aplicación {{nombre_aplicacion}} corresponde íntegramente al cliente final. {{proveedor_alias}} reconoce expresamente que no ostenta derechos sobre el código producido en el marco de este contrato, más allá de los necesarios para prestar el servicio contratado.</p>

<h3>Gestión del repositorio durante la vigencia del contrato</h3>
<p>Las partes convienen como modelo preferente que el repositorio esté bajo control del cliente final, quien dará acceso a {{proveedor_alias}} con los permisos necesarios para el desarrollo y mantenimiento.</p>
<p>Alternativamente, si el repositorio se mantiene bajo control de {{proveedor_alias}} durante la fase de desarrollo o los primeros meses de mantenimiento, {{proveedor_alias}} se compromete a garantizar acceso de lectura continuo al cliente final durante toda la vigencia del contrato, y a transferir íntegramente el repositorio al cliente final o al proveedor que este designe, sin coste adicional, cuando sea requerido.</p>
</div>

<div class="tp-section">
<h2>8. Duración, rescisión anticipada y cláusula espejo</h2>
<h3>Duración</h3>
<p>El presente contrato tiene una duración mínima inicial de 12 meses desde la fecha de alta del servicio. Transcurrido ese período, se entiende renovado automáticamente por períodos anuales, salvo comunicación en contrario conforme a lo establecido en esta misma cláusula.</p>

<h3>Rescisión durante los 12 meses iniciales</h3>
<p><strong>Por incumplimiento grave:</strong> cualquiera de las partes puede rescindir el contrato en caso de incumplimiento grave de la otra, mediante notificación escrita y un plazo de 30 días para subsanar. Si no se subsana, el contrato termina sin penalización.</p>
<p><strong>Rescisión por {{tp_alias}} sin causa:</strong> si {{tp_alias}} decide rescindir el contrato sin incumplimiento previo de {{proveedor_alias}} durante los 12 meses iniciales, abonará a {{proveedor_alias}} una compensación equivalente a tres (3) mensualidades del plan vigente en el momento de la rescisión.</p>
<p><strong>Rescisión por {{proveedor_alias}} sin causa:</strong> si {{proveedor_alias}} decide rescindir el contrato sin incumplimiento previo de {{tp_alias}} durante los 12 meses iniciales, devolverá a {{tp_alias}} las mensualidades pagadas y no consumidas de forma proporcional al mes en curso, y asumirá la continuidad del servicio durante un mínimo de 60 días adicionales para permitir a {{tp_alias}} organizar una alternativa.</p>

<h3>Cláusula espejo — Rescisión por el cliente final</h3>
<p>En el supuesto de que el cliente final ({{nombre_aplicacion}}, a través de {{cliente_final}}) rescinda anticipadamente su contrato de mantenimiento con {{tp_alias}}, esta podrá rescindir el presente contrato con {{proveedor_alias}} sin coste ni penalización, siempre que:</p>
<ul>
    <li>Notifique a {{proveedor_alias}} por escrito la rescisión del cliente final dentro de los 15 días naturales siguientes a que esta se haya producido.</li>
    <li>La fecha efectiva de terminación del contrato con {{proveedor_alias}} coincida con la fecha efectiva de terminación del contrato con el cliente final.</li>
    <li>{{tp_alias}} traslade a {{proveedor_alias}} la compensación equivalente que haya recibido del cliente final en aplicación de la cláusula de rescisión anticipada del contrato principal, hasta el máximo de tres (3) mensualidades.</li>
</ul>
<p>Esta cláusula tiene por objeto evitar que {{tp_alias}} quede expuesta al pago de compensaciones en cascada cuando la rescisión se origine en el cliente final, preservando el equilibrio económico entre las partes.</p>

<h3>Rescisión tras los 12 meses iniciales</h3>
<p>Superado el primer año, cualquiera de las partes puede rescindir el contrato con un preaviso por escrito de sesenta (60) días, sin compensación ni penalización alguna.</p>
</div>

<div class="tp-section">
<h2>9. Transferencia de conocimiento al finalizar</h2>
<p>Al finalizar el contrato, ya sea por rescisión anticipada o por no renovación, {{proveedor_alias}} se compromete a entregar lo siguiente:</p>

<h3>Código y repositorio</h3>
<p>Si el repositorio está bajo control del cliente final, no se requiere transferencia. Si se mantiene bajo control de {{proveedor_alias}}, se transfiere íntegramente al cliente final o al proveedor que este designe, sin coste adicional.</p>

<h3>Credenciales</h3>
<p>{{proveedor_alias}} entrega los accesos a los servicios directamente relacionados con el desarrollo y gestionados por su equipo. Quedan fuera de esta entrega los servicios contratados o gestionados por terceros (hosting, cuentas cloud) o aquellos cuyo acceso fue proporcionado originalmente por el cliente final.</p>

<h3>Documentación</h3>
<p>{{proveedor_alias}} entrega toda la documentación técnica y material del proyecto existente en el estado en que se encuentre al finalizar el contrato. No se contempla la generación adicional de documentación específica para el traspaso, dado que el servicio contratado es de mantenimiento y no de desarrollo.</p>

<h3>Sesión de traspaso</h3>
<p>{{proveedor_alias}} incluye una sesión de transferencia de conocimiento de hasta 4 horas con el proveedor entrante designado por el cliente final o por {{tp_alias}}. Esta sesión se realiza dentro del último mes de contrato y se descuenta de las horas mensuales disponibles de ese período. Está orientada a facilitar la continuidad del servicio sin fricciones.</p>
</div>

<div class="tp-section">
<h2>10. Confidencialidad y condiciones generales</h2>
<h3>Confidencialidad</h3>
<p>{{proveedor_alias}} se compromete a tratar con carácter confidencial toda la información relativa al cliente final, al proyecto {{nombre_aplicacion}}, a las condiciones comerciales establecidas entre {{tp_alias}} y el cliente final, y a cualquier dato técnico, de negocio o económico que conozca con motivo de la ejecución del presente contrato.</p>
<p>Esta obligación de confidencialidad se extiende a todo el equipo de {{proveedor_alias}} y se mantiene en vigor durante la vigencia del contrato y los dos años posteriores a su finalización.</p>
<p>En particular, {{proveedor_alias}} no comunicará directamente con el cliente final ni con {{cliente_final}} sin autorización expresa de {{tp_alias}}, salvo en el marco de reuniones conjuntas coordinadas por {{tp_alias}}.</p>

<h3>No competencia sobre el cliente final</h3>
<p>Durante la vigencia del contrato y los 12 meses posteriores a su finalización, {{proveedor_alias}} se compromete a no ofrecer directamente servicios de desarrollo, mantenimiento o UX/UI al cliente final ni a {{cliente_final}} en relación con la aplicación {{nombre_aplicacion}}, salvo acuerdo expreso con {{tp_alias}}.</p>

<h3>Relación entre las partes</h3>
<p>{{proveedor_alias}} presta sus servicios como proveedor independiente. Este contrato no crea relación laboral, de agencia ni de joint venture entre las partes. Cada parte asume sus propias obligaciones fiscales, laborales y de seguridad social respecto de su personal.</p>

<h3>Ley aplicable y jurisdicción</h3>
<p>El presente contrato se rige por la legislación española. Para cualquier controversia derivada de su interpretación o ejecución, las partes se someten expresamente a los juzgados y tribunales de Barcelona, con renuncia a cualquier otro fuero que pudiera corresponderles.</p>

<h3>Integridad del contrato</h3>
<p>El presente documento constituye el acuerdo completo entre las partes respecto del servicio de mantenimiento de la aplicación {{nombre_aplicacion}}, y deja sin efecto cualquier acuerdo verbal o escrito anterior sobre la misma materia. Cualquier modificación requiere acuerdo por escrito firmado por ambas partes.</p>
</div>

<div class="tp-section">
<h2>Aceptación y firma</h2>
<p>Las partes manifiestan haber leído el presente contrato y estar conformes con todas sus cláusulas, firmando en prueba de conformidad en la fecha indicada en el certificado de firma adjunto.</p>
<div class="tp-callout">Este documento se firma electrónicamente conforme al Reglamento (UE) 910/2014 (eIDAS). El certificado de firma adjunto al final del documento contiene la prueba técnica de la autoría e integridad de las firmas.</div>
</div>
HTML;

upsert_plantilla($pdo, [
    'slug' => 'nda-subcontratacion-tp',
    'nombre' => 'NDA + Subcontratación con proveedor',
    'tipo' => 'nda',
    'destinatario' => 'proveedor',
    'html' => $ndaHtml,
    'variables' => [
        ['name' => 'titulo_contrato', 'label' => 'Título del contrato', 'type' => 'text', 'default' => 'Contrato de subcontratación'],
        ['name' => 'servicio', 'label' => 'Subtítulo · servicio', 'type' => 'text', 'default' => 'Mantenimiento de la aplicación Cardalis'],
        ['name' => 'tp_razon_social', 'label' => 'Razón social Tres Puntos', 'type' => 'text', 'default' => 'Tres Puntos Comunicación S.L.'],
        ['name' => 'tp_alias', 'label' => 'Alias Tres Puntos', 'type' => 'text', 'default' => 'Tres Puntos'],
        ['name' => 'proveedor_razon_social', 'label' => 'Razón social proveedor', 'type' => 'text'],
        ['name' => 'proveedor_alias', 'label' => 'Alias proveedor', 'type' => 'text'],
        ['name' => 'servicio_descripcion', 'label' => 'Descripción del servicio', 'type' => 'text', 'default' => 'mantenimiento técnico continuo'],
        ['name' => 'cliente_final', 'label' => 'Cliente final', 'type' => 'text'],
        ['name' => 'nombre_aplicacion', 'label' => 'Nombre de la aplicación', 'type' => 'text'],
        ['name' => 'stack_tecnico', 'label' => 'Stack técnico', 'type' => 'text', 'default' => 'Ionic, Angular, Capacitor, Laravel'],
        ['name' => 'plan_basico_horas', 'label' => 'Horas plan básico', 'type' => 'number', 'default' => 6],
        ['name' => 'plan_avanzado_horas', 'label' => 'Horas plan avanzado', 'type' => 'number', 'default' => 12],
        ['name' => 'tarifa_basico', 'label' => 'Tarifa plan básico (€)', 'type' => 'number', 'default' => 450],
        ['name' => 'tarifa_avanzado', 'label' => 'Tarifa plan avanzado (€)', 'type' => 'number', 'default' => 750],
        ['name' => 'tarifa_hora', 'label' => 'Tarifa hora adicional (€/h)', 'type' => 'number', 'default' => 25],
        ['name' => 'fecha_contrato', 'label' => 'Fecha del contrato', 'type' => 'date'],
    ],
    'firmantes' => ['tp', 'proveedor'],
    'require_otp' => 0,
    'require_tsa' => 1,
], $log);

// ====================================================================
//   PLANTILLA 2 · MSA CLIENTE
// ====================================================================
$msaHtml = <<<HTML
<div class="tp-cover">
    <div class="brand">TRES PUNTOS</div>
    <hr class="rule">
    <h1>Acuerdo marco de servicios</h1>
    <div class="subtitle">{{cliente_razon_social}}</div>
    <div class="partes">Entre {{tp_razon_social}} y {{cliente_razon_social}}</div>
    <div class="firmantes-bloque">
        <div style="margin-bottom:2mm"><strong>Partes firmantes</strong></div>
        <div><strong>{{tp_razon_social}}</strong> (proveedor)</div>
        <div><strong>{{cliente_razon_social}}</strong> (cliente)</div>
    </div>
    <div class="fecha">{{fecha_contrato|date}}</div>
</div>

<div class="tp-section">
<h2>1. Objeto del acuerdo</h2>
<p>El presente Acuerdo Marco de Servicios (en adelante, "MSA") regula la relación general entre {{tp_razon_social}} y {{cliente_razon_social}} para la prestación de servicios de diseño, desarrollo, comunicación digital y consultoría tecnológica.</p>
<p>Los servicios concretos a prestar bajo este MSA se documentan en uno o varios anexos denominados Statement of Work (SOW), que detallan alcance, hitos, importes y plazos. Cada SOW se firma independientemente y queda regulado por las condiciones generales del presente MSA, salvo que el SOW indique expresamente lo contrario.</p>
</div>

<div class="tp-section">
<h2>2. Duración</h2>
<p>El presente MSA entra en vigor en la fecha de firma y permanece vigente mientras existan SOW activos firmados al amparo del mismo, o hasta su resolución expresa por cualquiera de las partes con un preaviso de 60 días.</p>
</div>

<div class="tp-section">
<h2>3. Facturación y forma de pago</h2>
<p>{{tp_razon_social}} factura conforme a los hitos pactados en cada SOW. El plazo estándar de pago es de 30 días desde la emisión de la factura, salvo acuerdo distinto en el SOW correspondiente.</p>
<p>El retraso en el pago superior a 30 días desde la fecha de vencimiento devenga el interés legal correspondiente y faculta a {{tp_razon_social}} a suspender la prestación del servicio hasta su regularización.</p>
</div>

<div class="tp-section">
<h2>4. Propiedad intelectual</h2>
<p>La propiedad de los entregables producidos en el marco de cada SOW se transfiere al cliente una vez completado el pago íntegro de los mismos. Hasta ese momento, {{tp_razon_social}} conserva todos los derechos sobre el material producido.</p>
<p>{{tp_razon_social}} se reserva el derecho a utilizar elementos genéricos, frameworks propios y conocimiento adquirido en futuros proyectos, siempre que no se reproduzcan elementos identificativos del cliente ni información confidencial.</p>
<p>{{tp_razon_social}} podrá incluir el proyecto en su portfolio público, salvo que el cliente lo prohíba expresamente por escrito.</p>
</div>

<div class="tp-section">
<h2>5. Confidencialidad</h2>
<p>Las partes se obligan a tratar como confidencial toda la información intercambiada en el marco del presente acuerdo y de los SOW asociados, durante su vigencia y los dos años posteriores a su finalización.</p>
<p>Esta obligación no se extiende a información de dominio público, conocida con anterioridad por la parte receptora, o desarrollada de forma independiente sin uso de información confidencial.</p>
</div>

<div class="tp-section">
<h2>6. Limitación de responsabilidad</h2>
<p>La responsabilidad económica de {{tp_razon_social}} frente al cliente por incumplimiento o cumplimiento defectuoso queda limitada al importe efectivamente abonado por el SOW correspondiente en los 12 meses anteriores al hecho que motiva la reclamación.</p>
<p>En ningún caso {{tp_razon_social}} responde por lucro cesante, daños indirectos o consecuenciales.</p>
</div>

<div class="tp-section">
<h2>7. Ley aplicable y jurisdicción</h2>
<p>El presente MSA se rige por la legislación española. Para cualquier controversia derivada de su interpretación o ejecución, las partes se someten a los juzgados y tribunales de Barcelona, con renuncia a cualquier otro fuero.</p>
</div>

<div class="tp-section">
<h2>Aceptación y firma</h2>
<p>Las partes manifiestan haber leído el presente acuerdo y estar conformes con todas sus cláusulas, firmando en prueba de conformidad en la fecha indicada en el certificado de firma adjunto.</p>
<div class="tp-callout">Este documento se firma electrónicamente conforme al Reglamento (UE) 910/2014 (eIDAS). El certificado de firma adjunto al final del documento contiene la prueba técnica de la autoría e integridad de las firmas.</div>
</div>
HTML;

upsert_plantilla($pdo, [
    'slug' => 'msa-cliente',
    'nombre' => 'MSA · Acuerdo marco de servicios con cliente',
    'tipo' => 'msa',
    'destinatario' => 'cliente',
    'html' => $msaHtml,
    'variables' => [
        ['name' => 'tp_razon_social', 'label' => 'Razón social Tres Puntos', 'type' => 'text', 'default' => 'Tres Puntos Comunicación S.L.'],
        ['name' => 'cliente_razon_social', 'label' => 'Razón social cliente', 'type' => 'text'],
        ['name' => 'fecha_contrato', 'label' => 'Fecha del contrato', 'type' => 'date'],
    ],
    'firmantes' => ['cliente', 'tp'],
    'require_otp' => 1,
    'require_tsa' => 1,
], $log);

// ====================================================================
//   PLANTILLA 3 · SOW CLIENTE (ligada a propuesta aprobada)
// ====================================================================
$sowHtml = <<<HTML
<div class="tp-cover">
    <div class="brand">TRES PUNTOS</div>
    <hr class="rule">
    <h1>{{titulo_sow}}</h1>
    <div class="subtitle">Anexo al MSA · {{cliente_razon_social}}</div>
    <div class="firmantes-bloque">
        <div><strong>{{tp_razon_social}}</strong> (proveedor)</div>
        <div><strong>{{cliente_razon_social}}</strong> (cliente)</div>
    </div>
    <div class="fecha">{{fecha_contrato|date}}</div>
</div>

<div class="tp-section">
<h2>1. Alcance del servicio</h2>
<p>{{alcance_descripcion}}</p>
<p>Esta SOW se rige por las condiciones generales del MSA firmado entre las partes con fecha {{fecha_msa|date}}. Cualquier discrepancia entre esta SOW y el MSA prevalece lo establecido en esta SOW.</p>
</div>

<div class="tp-section">
<h2>2. Hitos y entregables</h2>
<p>{{hitos_descripcion}}</p>
</div>

<div class="tp-section">
<h2>3. Importe y forma de pago</h2>
<table class="tp-table">
    <thead><tr><th>Concepto</th><th class="num">Importe</th></tr></thead>
    <tbody>
    <tr><td>Importe total del proyecto (sin IVA)</td><td class="num"><strong>{{importe_total|money}}</strong></td></tr>
    <tr><td>IVA (21%)</td><td class="num">{{importe_iva|money}}</td></tr>
    <tr><td><strong>Total con IVA</strong></td><td class="num"><strong>{{importe_total_con_iva|money}}</strong></td></tr>
    </tbody>
</table>
<p>Forma de pago: {{forma_pago}}</p>
</div>

<div class="tp-section">
<h2>4. Plazo de ejecución</h2>
<p>Fecha estimada de inicio: {{fecha_inicio|date}}.</p>
<p>Fecha estimada de finalización: {{fecha_fin|date}}.</p>
<p>Estos plazos son orientativos y pueden verse afectados por la disponibilidad de información, validaciones del cliente y dependencias externas. Cualquier desviación significativa se comunicará por escrito.</p>
</div>

<div class="tp-section">
<h2>5. Cambios de alcance</h2>
<p>Cualquier solicitud que exceda el alcance descrito en la cláusula 1 se documenta mediante un Change Order, firmado por ambas partes antes de su ejecución, con su correspondiente impacto en plazo e importe.</p>
</div>

<div class="tp-section">
<h2>Aceptación y firma</h2>
<p>Las partes manifiestan haber leído la presente SOW y estar conformes con todas sus cláusulas, firmando en prueba de conformidad en la fecha indicada en el certificado de firma adjunto.</p>
<div class="tp-callout">Este documento se firma electrónicamente conforme al Reglamento (UE) 910/2014 (eIDAS). El certificado de firma adjunto al final del documento contiene la prueba técnica de la autoría e integridad de las firmas.</div>
</div>
HTML;

upsert_plantilla($pdo, [
    'slug' => 'sow-cliente',
    'nombre' => 'SOW · Statement of Work con cliente',
    'tipo' => 'sow',
    'destinatario' => 'cliente',
    'html' => $sowHtml,
    'variables' => [
        ['name' => 'titulo_sow', 'label' => 'Título del SOW', 'type' => 'text'],
        ['name' => 'tp_razon_social', 'label' => 'Razón social Tres Puntos', 'type' => 'text', 'default' => 'Tres Puntos Comunicación S.L.'],
        ['name' => 'cliente_razon_social', 'label' => 'Razón social cliente', 'type' => 'text'],
        ['name' => 'alcance_descripcion', 'label' => 'Descripción del alcance', 'type' => 'textarea'],
        ['name' => 'hitos_descripcion', 'label' => 'Hitos y entregables', 'type' => 'textarea'],
        ['name' => 'importe_total', 'label' => 'Importe sin IVA (€)', 'type' => 'number'],
        ['name' => 'importe_iva', 'label' => 'IVA (€)', 'type' => 'number'],
        ['name' => 'importe_total_con_iva', 'label' => 'Total con IVA (€)', 'type' => 'number'],
        ['name' => 'forma_pago', 'label' => 'Forma de pago', 'type' => 'text', 'default' => 'Transferencia bancaria a 30 días desde emisión de factura.'],
        ['name' => 'fecha_inicio', 'label' => 'Fecha inicio estimado', 'type' => 'date'],
        ['name' => 'fecha_fin', 'label' => 'Fecha fin estimado', 'type' => 'date'],
        ['name' => 'fecha_msa', 'label' => 'Fecha del MSA marco', 'type' => 'date'],
        ['name' => 'fecha_contrato', 'label' => 'Fecha del SOW', 'type' => 'date'],
    ],
    'firmantes' => ['cliente', 'tp'],
    'require_otp' => 1,
    'require_tsa' => 1,
], $log);

// ====================================================================
//   PLANTILLA 4 · DPA CLIENTE (RGPD art. 28)
// ====================================================================
$dpaHtml = <<<HTML
<div class="tp-cover">
    <div class="brand">TRES PUNTOS</div>
    <hr class="rule">
    <h1>Acuerdo de tratamiento de datos</h1>
    <div class="subtitle">RGPD art. 28 · {{cliente_razon_social}}</div>
    <div class="firmantes-bloque">
        <div><strong>{{cliente_razon_social}}</strong> (responsable del tratamiento)</div>
        <div><strong>{{tp_razon_social}}</strong> (encargado del tratamiento)</div>
    </div>
    <div class="fecha">{{fecha_contrato|date}}</div>
</div>

<div class="tp-section">
<h2>1. Objeto del acuerdo</h2>
<p>El presente Acuerdo de Tratamiento de Datos (en adelante, "DPA") regula las condiciones en que {{tp_razon_social}} (en adelante, "el Encargado") trata datos personales por cuenta de {{cliente_razon_social}} (en adelante, "el Responsable"), conforme al artículo 28 del Reglamento (UE) 2016/679 (RGPD).</p>
</div>

<div class="tp-section">
<h2>2. Detalles del tratamiento</h2>
<p><strong>Objeto del tratamiento:</strong> {{objeto_tratamiento}}.</p>
<p><strong>Naturaleza y finalidad:</strong> {{finalidad_tratamiento}}.</p>
<p><strong>Tipo de datos:</strong> {{tipos_datos}}.</p>
<p><strong>Categorías de interesados:</strong> {{categorias_interesados}}.</p>
<p><strong>Duración:</strong> mientras dure la prestación de los servicios contratados, prorrogable mientras existan obligaciones contractuales pendientes.</p>
</div>

<div class="tp-section">
<h2>3. Obligaciones del Encargado</h2>
<p>El Encargado se compromete a:</p>
<ul>
    <li>Tratar los datos personales únicamente conforme a las instrucciones documentadas del Responsable.</li>
    <li>Garantizar que las personas autorizadas para tratar los datos se hayan comprometido a respetar la confidencialidad.</li>
    <li>Tomar todas las medidas técnicas y organizativas apropiadas conforme al art. 32 RGPD.</li>
    <li>Asistir al Responsable en el cumplimiento de las obligaciones de los arts. 32 a 36 RGPD.</li>
    <li>Notificar al Responsable, sin dilación indebida, cualquier violación de la seguridad de los datos.</li>
    <li>Suprimir o devolver al Responsable todos los datos personales una vez finalizada la prestación de los servicios.</li>
    <li>Poner a disposición del Responsable toda la información necesaria para demostrar el cumplimiento de las obligaciones del presente DPA y permitir auditorías razonables.</li>
</ul>
</div>

<div class="tp-section">
<h2>4. Subencargados autorizados</h2>
<p>El Responsable autoriza al Encargado a contratar a los siguientes subencargados para la prestación del servicio:</p>
<table class="tp-table">
    <thead><tr><th>Proveedor</th><th>Servicio</th><th>Ubicación</th></tr></thead>
    <tbody>
    <tr><td>Hostinger International Ltd.</td><td>Hosting de aplicaciones y datos</td><td>UE (Lituania)</td></tr>
    <tr><td>Resend, Inc.</td><td>Servicio de envío de emails transaccionales</td><td>EE. UU. (Cláusulas contractuales tipo)</td></tr>
    <tr><td>Anthropic, PBC</td><td>Asistente IA documentos (Claude)</td><td>EE. UU. (Cláusulas contractuales tipo)</td></tr>
    <tr><td>Google LLC</td><td>Workspace + Drive (correo, documentos)</td><td>EE. UU. (Cláusulas contractuales tipo)</td></tr>
    <tr><td>Vercel Inc.</td><td>Hosting de funciones serverless (MCP)</td><td>EE. UU. (Cláusulas contractuales tipo)</td></tr>
    </tbody>
</table>
<p>Cualquier modificación de esta lista se notificará al Responsable con al menos 30 días de antelación, otorgándole derecho a oponerse motivadamente.</p>
</div>

<div class="tp-section">
<h2>5. Notificación de brechas</h2>
<p>El Encargado notificará al Responsable cualquier violación de la seguridad de los datos personales en un plazo máximo de 72 horas desde su conocimiento, proporcionando toda la información razonablemente disponible para permitir al Responsable cumplir con sus obligaciones de notificación a la autoridad de control y, en su caso, a los interesados.</p>
</div>

<div class="tp-section">
<h2>6. Devolución o supresión de datos</h2>
<p>Una vez finalizado el servicio, el Encargado, a elección del Responsable, devolverá o suprimirá todos los datos personales tratados, incluyendo copias existentes, salvo que la legislación aplicable exija su conservación por un período adicional.</p>
</div>

<div class="tp-section">
<h2>7. Ley aplicable y jurisdicción</h2>
<p>El presente DPA se rige por la legislación española y europea aplicable. Para cualquier controversia, las partes se someten a los juzgados y tribunales de Barcelona.</p>
</div>

<div class="tp-section">
<h2>Aceptación y firma</h2>
<p>Las partes manifiestan haber leído el presente DPA y estar conformes con todas sus cláusulas.</p>
<div class="tp-callout">Este documento se firma electrónicamente conforme al Reglamento (UE) 910/2014 (eIDAS). El certificado de firma adjunto al final del documento contiene la prueba técnica de la autoría e integridad de las firmas.</div>
</div>
HTML;

upsert_plantilla($pdo, [
    'slug' => 'dpa-cliente',
    'nombre' => 'DPA · Acuerdo tratamiento de datos (RGPD art. 28)',
    'tipo' => 'dpa',
    'destinatario' => 'cliente',
    'html' => $dpaHtml,
    'variables' => [
        ['name' => 'tp_razon_social', 'label' => 'Razón social Tres Puntos', 'type' => 'text', 'default' => 'Tres Puntos Comunicación S.L.'],
        ['name' => 'cliente_razon_social', 'label' => 'Razón social cliente', 'type' => 'text'],
        ['name' => 'objeto_tratamiento', 'label' => 'Objeto del tratamiento', 'type' => 'textarea', 'default' => 'Datos de leads, formularios web y comunicaciones del cliente recogidos en el desarrollo y mantenimiento del proyecto'],
        ['name' => 'finalidad_tratamiento', 'label' => 'Finalidad del tratamiento', 'type' => 'textarea', 'default' => 'Almacenamiento, procesamiento y análisis de datos para la prestación de los servicios contratados, incluyendo desarrollo, soporte, analítica y comunicación.'],
        ['name' => 'tipos_datos', 'label' => 'Tipos de datos', 'type' => 'textarea', 'default' => 'Datos identificativos (nombre, email, teléfono), datos de uso (logs de aplicación, dirección IP, navegador), datos comerciales (interacciones, preferencias).'],
        ['name' => 'categorias_interesados', 'label' => 'Categorías de interesados', 'type' => 'textarea', 'default' => 'Clientes finales, leads, usuarios registrados de las aplicaciones del Responsable.'],
        ['name' => 'fecha_contrato', 'label' => 'Fecha del DPA', 'type' => 'date'],
    ],
    'firmantes' => ['cliente', 'tp'],
    'require_otp' => 0,
    'require_tsa' => 1,
], $log);

// ====================================================================
//   PLANTILLA 5 · CHANGE ORDER
// ====================================================================
$coHtml = <<<HTML
<div class="tp-cover">
    <div class="brand">TRES PUNTOS</div>
    <hr class="rule">
    <h1>Change Order #{{numero_co}}</h1>
    <div class="subtitle">Modificación de alcance · {{cliente_razon_social}}</div>
    <div class="firmantes-bloque">
        <div><strong>{{tp_razon_social}}</strong> (proveedor)</div>
        <div><strong>{{cliente_razon_social}}</strong> (cliente)</div>
    </div>
    <div class="fecha">{{fecha_contrato|date}}</div>
</div>

<div class="tp-section">
<h2>1. SOW de referencia</h2>
<p>Esta orden de cambio (Change Order) modifica el SOW <strong>"{{sow_titulo}}"</strong> firmado el <strong>{{fecha_sow|date}}</strong> entre las partes (hash referencia: <code style="font-family:dejavusansmono;font-size:9pt">{{sow_hash}}</code>).</p>
</div>

<div class="tp-section">
<h2>2. Descripción del cambio</h2>
<p>{{descripcion_cambio}}</p>
</div>

<div class="tp-section">
<h2>3. Impacto económico</h2>
<table class="tp-table">
    <tbody>
    <tr><td>Importe SOW original (sin IVA)</td><td class="num">{{importe_sow_original|money}}</td></tr>
    <tr><td>Delta de este Change Order (sin IVA)</td><td class="num"><strong>{{delta_importe|money}}</strong></td></tr>
    <tr><td><strong>Importe SOW resultante (sin IVA)</strong></td><td class="num"><strong>{{importe_resultante|money}}</strong></td></tr>
    </tbody>
</table>
</div>

<div class="tp-section">
<h2>4. Impacto en plazo</h2>
<p><strong>Fecha fin SOW original:</strong> {{fecha_fin_original|date}}</p>
<p><strong>Nueva fecha fin tras este Change Order:</strong> {{nueva_fecha_fin|date}}</p>
</div>

<div class="tp-section">
<h2>5. Resto de condiciones</h2>
<p>Las demás condiciones del SOW original y del MSA marco permanecen inalteradas.</p>
</div>

<div class="tp-section">
<h2>Aceptación y firma</h2>
<p>Las partes aceptan expresamente las modificaciones recogidas en el presente Change Order.</p>
<div class="tp-callout">Este documento se firma electrónicamente conforme al Reglamento (UE) 910/2014 (eIDAS).</div>
</div>
HTML;

upsert_plantilla($pdo, [
    'slug' => 'change-order',
    'nombre' => 'Change Order · Modificación de alcance',
    'tipo' => 'change_order',
    'destinatario' => 'cliente',
    'html' => $coHtml,
    'variables' => [
        ['name' => 'numero_co', 'label' => 'Número de Change Order', 'type' => 'text', 'default' => '1'],
        ['name' => 'tp_razon_social', 'label' => 'Razón social Tres Puntos', 'type' => 'text', 'default' => 'Tres Puntos Comunicación S.L.'],
        ['name' => 'cliente_razon_social', 'label' => 'Razón social cliente', 'type' => 'text'],
        ['name' => 'sow_titulo', 'label' => 'Título del SOW original', 'type' => 'text'],
        ['name' => 'sow_hash', 'label' => 'Hash SHA256 del SOW original', 'type' => 'text'],
        ['name' => 'fecha_sow', 'label' => 'Fecha del SOW original', 'type' => 'date'],
        ['name' => 'descripcion_cambio', 'label' => 'Descripción del cambio', 'type' => 'textarea'],
        ['name' => 'importe_sow_original', 'label' => 'Importe SOW original (€)', 'type' => 'number'],
        ['name' => 'delta_importe', 'label' => 'Delta de importe (€)', 'type' => 'number'],
        ['name' => 'importe_resultante', 'label' => 'Importe resultante (€)', 'type' => 'number'],
        ['name' => 'fecha_fin_original', 'label' => 'Fecha fin original', 'type' => 'date'],
        ['name' => 'nueva_fecha_fin', 'label' => 'Nueva fecha fin', 'type' => 'date'],
        ['name' => 'fecha_contrato', 'label' => 'Fecha del Change Order', 'type' => 'date'],
    ],
    'firmantes' => ['cliente', 'tp'],
    'require_otp' => 0,
    'require_tsa' => 1,
], $log);

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Seed plantillas:\n" . implode("\n", $log) . "\n";

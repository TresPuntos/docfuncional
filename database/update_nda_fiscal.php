<?php
/**
 * Parche — añade "Identificación de las partes" con datos fiscales completos a la plantilla NDA.
 * Añade variables: proveedor_cif, proveedor_direccion, proveedor_web.
 * Actualiza el contrato id=1 con datos reales de Truman obtenidos de Holded.
 *
 * Idempotente: pone siempre los valores actuales sobrescribiendo.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

// ====================================================================
//   Nuevo HTML de la plantilla NDA con bloque "Identificación de las partes"
// ====================================================================
$newHtml = <<<HTML
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
<h2>Identificación de las partes</h2>

<h3>De una parte · {{tp_razon_social}} ("{{tp_alias}}")</h3>
<table class="tp-table">
    <tbody>
    <tr><td style="width:35%"><strong>Razón social</strong></td><td>{{tp_razon_social}}</td></tr>
    <tr><td><strong>CIF</strong></td><td>{{tp_cif}}</td></tr>
    <tr><td><strong>Domicilio</strong></td><td>{{tp_direccion}}</td></tr>
    <tr><td><strong>Email de contacto</strong></td><td>{{tp_email}}</td></tr>
    <tr><td><strong>Representada por</strong></td><td>{{tp_representante}}, con DNI {{tp_representante_dni}}, en calidad de {{tp_representante_cargo}}</td></tr>
    </tbody>
</table>

<h3>De otra parte · {{proveedor_razon_social}} ("{{proveedor_alias}}")</h3>
<table class="tp-table">
    <tbody>
    <tr><td style="width:35%"><strong>Razón social</strong></td><td>{{proveedor_razon_social}}</td></tr>
    <tr><td><strong>CIF / NIF</strong></td><td>{{proveedor_cif}}</td></tr>
    <tr><td><strong>Domicilio</strong></td><td>{{proveedor_direccion}}</td></tr>
    <tr><td><strong>Email de contacto</strong></td><td>{{proveedor_email}}</td></tr>
    <tr><td><strong>Web</strong></td><td>{{proveedor_web}}</td></tr>
    <tr><td><strong>Representada por</strong></td><td>{{proveedor_representante}}, con DNI / NIE {{proveedor_representante_dni}}, en calidad de {{proveedor_representante_cargo}}</td></tr>
    </tbody>
</table>

<p>Ambas partes se reconocen mutuamente capacidad legal suficiente para otorgar el presente contrato.</p>
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
<p>{{proveedor_alias}} emite factura mensual por el servicio prestado a {{tp_razon_social}} (CIF {{tp_cif}}). La factura se abona por transferencia bancaria a 30 días desde su emisión al IBAN facilitado por {{proveedor_alias}}, salvo acuerdo distinto entre las partes.</p>
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

$newVars = [
    ['name' => 'titulo_contrato', 'label' => 'Título del contrato', 'type' => 'text', 'default' => 'Contrato de subcontratación'],
    ['name' => 'servicio', 'label' => 'Subtítulo · servicio', 'type' => 'text', 'default' => 'Mantenimiento de la aplicación'],
    // --- Tres Puntos ---
    ['name' => 'tp_razon_social', 'label' => 'TP razón social', 'type' => 'text', 'default' => 'Tres Puntos Comunicación S.L.'],
    ['name' => 'tp_alias', 'label' => 'TP alias', 'type' => 'text', 'default' => 'Tres Puntos'],
    ['name' => 'tp_cif', 'label' => 'TP CIF', 'type' => 'text', 'default' => 'B66018490'],
    ['name' => 'tp_direccion', 'label' => 'TP domicilio fiscal', 'type' => 'text', 'default' => 'Calle Sant Josep 22, Barcelona'],
    ['name' => 'tp_email', 'label' => 'TP email contacto', 'type' => 'text', 'default' => 'jordi@trespuntoscomunicacion.es'],
    ['name' => 'tp_representante', 'label' => 'TP representante legal', 'type' => 'text', 'default' => 'Jordi Expósito Lozano'],
    ['name' => 'tp_representante_dni', 'label' => 'TP DNI representante', 'type' => 'text', 'default' => '52407613C'],
    ['name' => 'tp_representante_cargo', 'label' => 'TP cargo representante', 'type' => 'text', 'default' => 'Founder & Digital Experience Manager'],
    // --- Proveedor ---
    ['name' => 'proveedor_razon_social', 'label' => 'Proveedor razón social', 'type' => 'text'],
    ['name' => 'proveedor_alias', 'label' => 'Proveedor alias', 'type' => 'text'],
    ['name' => 'proveedor_cif', 'label' => 'Proveedor CIF/NIF', 'type' => 'text'],
    ['name' => 'proveedor_direccion', 'label' => 'Proveedor domicilio fiscal', 'type' => 'text'],
    ['name' => 'proveedor_email', 'label' => 'Proveedor email contacto', 'type' => 'text'],
    ['name' => 'proveedor_web', 'label' => 'Proveedor web', 'type' => 'text'],
    ['name' => 'proveedor_representante', 'label' => 'Proveedor representante legal', 'type' => 'text'],
    ['name' => 'proveedor_representante_dni', 'label' => 'Proveedor DNI representante', 'type' => 'text'],
    ['name' => 'proveedor_representante_cargo', 'label' => 'Proveedor cargo representante', 'type' => 'text', 'default' => 'Apoderado'],
    // --- Proyecto ---
    ['name' => 'servicio_descripcion', 'label' => 'Descripción del servicio', 'type' => 'text', 'default' => 'mantenimiento técnico continuo'],
    ['name' => 'cliente_final', 'label' => 'Cliente final', 'type' => 'text'],
    ['name' => 'nombre_aplicacion', 'label' => 'Nombre aplicación', 'type' => 'text'],
    ['name' => 'stack_tecnico', 'label' => 'Stack técnico', 'type' => 'text', 'default' => 'Ionic, Angular, Capacitor, Laravel'],
    ['name' => 'plan_basico_horas', 'label' => 'Horas plan básico', 'type' => 'number', 'default' => 6],
    ['name' => 'plan_avanzado_horas', 'label' => 'Horas plan avanzado', 'type' => 'number', 'default' => 12],
    ['name' => 'tarifa_basico', 'label' => 'Tarifa plan básico (€)', 'type' => 'number', 'default' => 450],
    ['name' => 'tarifa_avanzado', 'label' => 'Tarifa plan avanzado (€)', 'type' => 'number', 'default' => 750],
    ['name' => 'tarifa_hora', 'label' => 'Tarifa hora adicional (€/h)', 'type' => 'number', 'default' => 25],
    ['name' => 'fecha_contrato', 'label' => 'Fecha del contrato', 'type' => 'date'],
];

// Update plantilla
$pdo->prepare("UPDATE contratos_plantillas SET
    html_content = ?, variables_json = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP
    WHERE slug = 'nda-subcontratacion-tp'")
    ->execute([$newHtml, json_encode($newVars, JSON_UNESCAPED_UNICODE)]);
echo "✓ Plantilla nda-subcontratacion-tp actualizada con bloque Identificación de las partes\n";

// ====================================================================
//   Actualizar contrato id=1 (TEST AcmeDev) con los datos REALES de Truman
// ====================================================================
$stmt = $pdo->prepare("SELECT id, titulo FROM contratos WHERE id = 1");
$stmt->execute();
$c = $stmt->fetch(PDO::FETCH_ASSOC);
if ($c) {
    $datosTruman = [
        'titulo_contrato' => 'Contrato de subcontratación',
        'servicio' => 'Mantenimiento de la aplicación Cardalis',
        'tp_razon_social' => 'Tres Puntos Comunicación S.L.',
        'tp_alias' => 'Tres Puntos',
        'tp_cif' => 'B66018490',
        'tp_direccion' => 'Calle Sant Josep 22, 08012 Barcelona, España',
        'tp_email' => 'jordi@trespuntoscomunicacion.es',
        'tp_representante' => 'Jordi Expósito Lozano',
        'tp_representante_dni' => '52407613C',
        'tp_representante_cargo' => 'Founder & Digital Experience Manager',
        // Datos reales Truman desde Holded
        'proveedor_razon_social' => 'Truman Digital S.L.',
        'proveedor_alias' => 'Truman',
        'proveedor_cif' => 'B13750906',
        'proveedor_direccion' => 'Calle Pintor Renau 17, Esc. 1 · 4º 7ª, 46900 Torrent (Valencia), España',
        'proveedor_email' => '—',
        'proveedor_web' => 'wearetruman.es',
        'proveedor_representante' => '—',
        'proveedor_representante_dni' => '—',
        'proveedor_representante_cargo' => 'Apoderado',
        // Proyecto
        'servicio_descripcion' => 'mantenimiento técnico continuo',
        'cliente_final' => 'Emotion Gallery',
        'nombre_aplicacion' => 'Cardalis',
        'stack_tecnico' => 'Ionic, Angular, Capacitor, Laravel',
        'plan_basico_horas' => 6,
        'plan_avanzado_horas' => 12,
        'tarifa_basico' => 450,
        'tarifa_avanzado' => 750,
        'tarifa_hora' => 25,
        'fecha_contrato' => date('Y-m-d'),
    ];
    // Recalcular hash con el nuevo HTML + datos
    require __DIR__ . '/../api/contratos_lib.php';
    $plant = $pdo->query("SELECT html_content FROM contratos_plantillas WHERE slug='nda-subcontratacion-tp'")->fetch(PDO::FETCH_ASSOC);
    $renderedHtml = contrato_render_template($plant['html_content'], $datosTruman);
    $newHash = contrato_hash_data($renderedHtml);

    $pdo->prepare("UPDATE contratos SET
        titulo = ?, datos_json = ?, hash_documento = ?
        WHERE id = 1")
        ->execute([
            'Contrato subcontratación · Truman Digital S.L. · Cardalis',
            json_encode($datosTruman, JSON_UNESCAPED_UNICODE),
            $newHash,
        ]);
    echo "✓ Contrato id=1 actualizado con datos fiscales reales de Truman (CIF B13750906, Torrent)\n";
    echo "  Hash nuevo: " . contrato_hash_short($newHash) . "\n";
}

echo "\nLos datos de Truman se han añadido. Revisa en http://localhost:8000/admin_contratos.php?contrato_id=1\n";

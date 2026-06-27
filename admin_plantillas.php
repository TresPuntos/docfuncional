<?php
/**
 * Admin · Editor de plantillas de contrato.
 *
 * URLs:
 *   /admin_plantillas.php           — listado
 *   /admin_plantillas.php?new=1     — editor vacío
 *   /admin_plantillas.php?id=N      — editar existente
 *
 * Acciones POST:
 *   - save           (crea o actualiza)
 *   - duplicate
 *   - toggle_active  (archivar/activar)
 *   - delete         (borra la plantilla, SOLO si no tiene contratos asociados)
 *   - preview        (genera PDF temporal con datos ejemplo y devuelve ruta)
 */

require __DIR__ . '/config.php';
require __DIR__ . '/api/contratos_lib.php';
session_start();

// Sesión admin unificada: acepta también la sesión iniciada en admin.php (is_admin).
if (!empty($_SESSION['is_admin']))     { $_SESSION['admin_logged'] = true; }
if (!empty($_SESSION['admin_logged'])) { $_SESSION['is_admin']     = true; }
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        if ($_POST['admin_password'] === ADMIN_PASSWORD) {
            $_SESSION['admin_logged'] = true;
            header('Location: admin_plantillas.php'); exit;
        }
    }
    ?>
    <!doctype html><meta charset="utf-8"><title>Admin</title>
    <style>body{background:#0e0e0e;color:#f5f5f5;font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0}form{background:#141414;padding:2rem;border-radius:12px;border:1px solid #1f1f1f;display:grid;gap:.75rem;width:320px}input{background:#191919;border:1px solid #1f1f1f;color:#fff;padding:.6rem;border-radius:6px}button{background:#5dffbf;color:#000;border:none;padding:.6rem;border-radius:6px;font-weight:700;cursor:pointer}</style>
    <form method="post"><strong>Admin Plantillas</strong><input name="admin_password" type="password" placeholder="Contraseña" autofocus><button>Entrar</button></form>
    <?php exit;
}

$pdo = getDBConnection();
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

// ====================================================================
//   GET — preview PDF con datos ejemplo
// ====================================================================
if (isset($_GET['preview_pdf'])) {
    $id = (int)$_GET['preview_pdf'];
    $stmt = $pdo->prepare("SELECT * FROM contratos_plantillas WHERE id = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) { http_response_code(404); echo 'No existe'; exit; }
    $vars = json_decode($p['variables_json'] ?: '[]', true);
    $data = [];
    foreach ($vars as $v) $data[$v['name']] = $v['default'] ?? '[' . $v['name'] . ']';
    // Añadimos defaults para las variables que no tienen default:
    if (empty($data['fecha_contrato'])) $data['fecha_contrato'] = date('Y-m-d');

    $html = contrato_render_template($p['html_content'], $data);
    $tmp = sys_get_temp_dir() . '/plantilla_preview_' . $id . '_' . time() . '.pdf';
    $firmasMock = [];
    contrato_generate_pdf($html, $firmasMock, [
        'titulo' => 'PREVIEW · ' . $p['nombre'],
        'tipo' => $p['tipo'],
        'hash_documento' => hash('sha256', $html),
    ], $tmp);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="preview.pdf"');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// ====================================================================
//   POST — acciones
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF: todas las acciones mutadoras requieren token válido
    if (!tp_csrf_check('admin_plantillas', $_POST['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'error' => 'CSRF token inválido. Recarga la página.']); exit;
    }

    $action = $_POST['action'];

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $slug = trim($_POST['slug'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $tipo = trim($_POST['tipo'] ?? 'custom');
        $destinatario = $_POST['destinatario'] ?? 'cliente';
        $htmlRaw = $_POST['html_content'] ?? '';
        $firmantesRaw = json_decode($_POST['firmantes'] ?? '[]', true);
        $variables = json_decode($_POST['variables'] ?? '[]', true);
        $requireOtp = !empty($_POST['require_otp']) ? 1 : 0;
        $requireTsa = !empty($_POST['require_tsa']) ? 1 : 0;
        $retencion = (int)($_POST['retencion_anios'] ?? 6);

        if (!$slug || !$nombre || !$htmlRaw) {
            echo json_encode(['success' => false, 'error' => 'Slug, nombre y contenido HTML son obligatorios']); exit;
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            echo json_encode(['success' => false, 'error' => 'Slug solo puede contener letras minúsculas, números y guiones']); exit;
        }
        if (!in_array($destinatario, ['cliente','proveedor','ambos'], true)) {
            echo json_encode(['success' => false, 'error' => 'Destinatario inválido']); exit;
        }

        // Whitelist de firmantes — solo roles conocidos
        if (!is_array($firmantesRaw)) $firmantesRaw = [];
        $firmantes = array_values(array_intersect($firmantesRaw, ['cliente','proveedor','tp']));
        if (empty($firmantes)) {
            echo json_encode(['success' => false, 'error' => 'Debe haber al menos un firmante válido (cliente, proveedor o tp)']); exit;
        }

        // Sanitización HTML: strip tags/attrs peligrosos antes de guardar.
        // El HTML se renderiza luego en iframe al firmante + mPDF → defensa en profundidad.
        $html = tp_sanitize_template_html($htmlRaw);

        // Auto-detectar variables que el admin no haya declarado (les ponemos defaults)
        if (!is_array($variables)) $variables = [];
        $detected = contrato_extract_variables($html);
        $declaredNames = array_column($variables, 'name');
        foreach ($detected as $vn) {
            if (!in_array($vn, $declaredNames, true)) {
                $variables[] = ['name' => $vn, 'label' => ucfirst(str_replace('_', ' ', $vn)), 'type' => 'text'];
            }
        }

        if ($id) {
            // UPDATE — solo bumpeamos versión si el HTML cambió (evita inflación en re-guardados sin diff)
            $prev = $pdo->prepare("SELECT html_content FROM contratos_plantillas WHERE id = ?");
            $prev->execute([$id]);
            $prevHtml = $prev->fetchColumn();
            $versionExpr = ($prevHtml !== $html) ? 'version + 1' : 'version';
            $pdo->prepare("UPDATE contratos_plantillas SET
                slug = ?, nombre = ?, tipo = ?, destinatario = ?, html_content = ?,
                variables_json = ?, firmantes_json = ?, require_otp = ?, require_tsa = ?,
                retencion_anios = ?, updated_at = CURRENT_TIMESTAMP, version = $versionExpr
                WHERE id = ?")
                ->execute([
                    $slug, $nombre, $tipo, $destinatario, $html,
                    json_encode($variables, JSON_UNESCAPED_UNICODE),
                    json_encode($firmantes, JSON_UNESCAPED_UNICODE),
                    $requireOtp, $requireTsa, $retencion, $id,
                ]);
            echo json_encode(['success' => true, 'id' => $id, 'action' => 'updated']);
        } else {
            // CREATE
            // Check slug duplicado
            $exists = $pdo->prepare("SELECT id FROM contratos_plantillas WHERE slug = ?");
            $exists->execute([$slug]);
            if ($exists->fetchColumn()) {
                echo json_encode(['success' => false, 'error' => 'Ya existe una plantilla con ese slug']); exit;
            }
            $pdo->prepare("INSERT INTO contratos_plantillas
                (slug, nombre, tipo, destinatario, html_content, variables_json, firmantes_json, require_otp, require_tsa, retencion_anios, activo, version)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)")
                ->execute([
                    $slug, $nombre, $tipo, $destinatario, $html,
                    json_encode($variables, JSON_UNESCAPED_UNICODE),
                    json_encode($firmantes, JSON_UNESCAPED_UNICODE),
                    $requireOtp, $requireTsa, $retencion,
                ]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'action' => 'created']);
        }
        exit;
    }

    if ($action === 'duplicate') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM contratos_plantillas WHERE id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) { echo json_encode(['success' => false, 'error' => 'No existe']); exit; }

        $newSlug = $p['slug'] . '-copia-' . bin2hex(random_bytes(3));
        $pdo->prepare("INSERT INTO contratos_plantillas
            (slug, nombre, tipo, destinatario, html_content, variables_json, firmantes_json, require_otp, require_tsa, retencion_anios, activo, version)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)")
            ->execute([
                $newSlug, $p['nombre'] . ' (copia)', $p['tipo'], $p['destinatario'], $p['html_content'],
                $p['variables_json'], $p['firmantes_json'], $p['require_otp'], $p['require_tsa'], $p['retencion_anios'],
            ]);
        echo json_encode(['success' => true, 'new_id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE contratos_plantillas SET activo = 1 - activo WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = $pdo->prepare("SELECT COUNT(*) FROM contratos WHERE plantilla_id = ?");
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'No se puede borrar: hay contratos creados desde esta plantilla. Archívala en su lugar.']);
            exit;
        }
        $pdo->prepare("DELETE FROM contratos_plantillas WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción desconocida']);
    exit;
}

// ====================================================================
//   GET — listado / editor
// ====================================================================
$editingId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$creatingNew = isset($_GET['new']);
$editing = null;
if ($editingId) {
    $stmt = $pdo->prepare("SELECT * FROM contratos_plantillas WHERE id = ?");
    $stmt->execute([$editingId]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editing) { header('Location: admin_plantillas.php'); exit; }
}

$plantillas = $pdo->query("SELECT p.*,
    (SELECT COUNT(*) FROM contratos WHERE plantilla_id = p.id) AS usos
    FROM contratos_plantillas p ORDER BY p.activo DESC, p.tipo, p.nombre")->fetchAll(PDO::FETCH_ASSOC);

$editingHtml = $editing['html_content'] ?? '';
$editingVars = $editing ? (json_decode($editing['variables_json'] ?: '[]', true)) : [];
$editingFirmantes = $editing ? (json_decode($editing['firmantes_json'] ?: '[]', true)) : ['cliente','tp'];
$csrfToken = tp_csrf_token('admin_plantillas');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Plantillas de contrato · Admin</title>
<style>
:root {
    --mint: #5dffbf; --mint-rgb: 93,255,191; --mint-hover: #49e6a8;
    --bg-base: #0e0e0e; --bg-surface: #141414; --bg-subtle: #191919; --bg-muted: #1f1f1f;
    --text-primary: #f5f5f5; --text-secondary: #b3b3b3; --text-muted: #8a8a8a;
    --border-base: #1f1f1f; --border-subtle: #1a1a1a;
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg-base); color: var(--text-primary); font-family: 'Inter', system-ui, sans-serif; font-size: 14px; }
.admin-layout { display: grid; grid-template-columns: 272px 1fr; min-height: 100vh; }
.admin-main { padding: 1.5rem 2rem; overflow-x: hidden; }
.admin-main-header { display: flex; justify-content: space-between; align-items: center; margin: 1rem 0 1.5rem 0; }
.admin-main-title { font-size: 1.55rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: .55rem; letter-spacing: -.015em; }
.admin-main-title small { font-weight: 400; color: var(--text-muted); font-size: 1rem; }
.btn { background: var(--bg-muted); color: var(--text-primary); border: 1px solid var(--border-base); padding: .55rem 1rem; border-radius: 8px; cursor: pointer; font-size: .85rem; font-weight: 500; display: inline-flex; align-items: center; gap: .4rem; text-decoration: none; }
.btn:hover { background: var(--bg-subtle); border-color: var(--mint); color: var(--mint); }
.btn-primary { background: var(--mint); color: #000; border-color: var(--mint); font-weight: 600; }
.btn-primary:hover { background: var(--mint-hover); color: #000; }
.btn-ghost { background: transparent; }
.btn-danger { color: #ff6b6b; border-color: #3a1f1f; }
.btn-danger:hover { background: #1f0d0d; border-color: #ff6b6b; color: #ff8a8a; }

.card { background: var(--bg-surface); border: 1px solid var(--border-base); border-radius: 12px; padding: 0; margin-bottom: 1.5rem; }
.card-pad { padding: 1.4rem; }

table.plantillas { width: 100%; border-collapse: collapse; font-size: .85rem; }
table.plantillas th { text-align: left; font-weight: 600; color: var(--text-muted); font-size: .68rem; text-transform: uppercase; letter-spacing: .1em; padding: .6rem .75rem; border-bottom: 1px solid var(--border-base); }
table.plantillas td { padding: .8rem .75rem; border-bottom: 1px solid var(--border-subtle); vertical-align: middle; }
table.plantillas tr:hover td { background: var(--bg-subtle); }
.tipo-chip { background: var(--bg-muted); color: var(--text-secondary); padding: .12rem .5rem; border-radius: 4px; font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
.dest-chip { background: rgba(93,255,191,.08); color: var(--mint); padding: .12rem .5rem; border-radius: 4px; font-size: .7rem; font-weight: 600; }
.inactive { opacity: .5; }
.actions { display: flex; gap: .35rem; justify-content: flex-end; }
.icon-btn { background: transparent; border: 1px solid transparent; color: var(--text-muted); padding: .35rem .5rem; border-radius: 6px; cursor: pointer; }
.icon-btn:hover { color: var(--mint); border-color: var(--border-base); background: var(--bg-subtle); }

/* Editor */
.editor-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; }
@media (max-width: 1100px) { .editor-grid { grid-template-columns: 1fr; } }
.field { margin-bottom: 1rem; }
.field label { display: block; font-size: .74rem; color: var(--text-muted); margin-bottom: .35rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
.field input, .field select, .field textarea { width: 100%; background: var(--bg-subtle); border: 1px solid var(--border-base); color: var(--text-primary); padding: .6rem .75rem; border-radius: 8px; font-size: .85rem; font-family: inherit; }
.field textarea { font-family: 'JetBrains Mono', 'Courier New', monospace; font-size: .78rem; line-height: 1.55; resize: vertical; }
.field textarea.html-editor { min-height: 520px; }
.field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: var(--mint); }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.field-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }

.sticky-side { position: sticky; top: 1rem; }
.vars-panel { background: var(--bg-subtle); border-radius: 10px; padding: 1rem; font-size: .8rem; }
.vars-panel h3 { margin: 0 0 .75rem 0; font-size: .88rem; font-weight: 600; }
.vars-detected { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: 1rem; }
.var-tag { background: var(--bg-muted); padding: .25rem .55rem; border-radius: 99px; font-family: 'JetBrains Mono', monospace; font-size: .73rem; color: var(--mint); }
.var-editor { border: 1px solid var(--border-base); border-radius: 8px; padding: .65rem .75rem; margin-bottom: .55rem; display: grid; grid-template-columns: 1fr 1fr 100px 30px; gap: .5rem; align-items: center; font-size: .78rem; }
.var-editor input, .var-editor select { background: var(--bg-muted); border: 1px solid var(--border-base); color: var(--text-primary); padding: .4rem .55rem; border-radius: 6px; font-size: .78rem; }
.var-editor .var-del { cursor: pointer; color: var(--text-muted); text-align: center; }
.var-editor .var-del:hover { color: #ff6b6b; }

.firmantes-picker { display: flex; gap: .75rem; background: var(--bg-subtle); padding: .8rem 1rem; border-radius: 8px; }
.firmantes-picker label { display: flex; align-items: center; gap: .4rem; color: var(--text-secondary); font-size: .85rem; margin: 0; cursor: pointer; }
.firmantes-picker input { width: auto; }

.pill { display: inline-flex; align-items: center; gap: .3rem; padding: .18rem .55rem; border-radius: 99px; font-size: .68rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; background: var(--bg-muted); color: var(--text-secondary); }
.pill.active { background: rgba(93,255,191,.15); color: var(--mint); }

.help { font-size: .72rem; color: var(--text-muted); margin-top: .35rem; line-height: 1.5; }
.empty { text-align: center; padding: 3rem 1rem; color: var(--text-muted); }
</style>
</head>
<body>
<?php include __DIR__ . '/master/admin-faceid.php'; ?>
<?php
$adminSidebarActive = 'plantillas';
$adminSidebarPropuestas = $pdo->query("SELECT id, slug, client_name FROM propuestas WHERE status = 1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="admin-layout">
<?php include __DIR__ . '/master/admin-sidebar.php'; ?>

<main class="admin-main">
<?php
$adminBreadcrumbItems = [
    ['label' => 'Dashboard', 'href' => 'admin.php'],
    ['label' => 'Contratos', 'href' => 'admin_contratos.php'],
    ['label' => 'Plantillas', 'href' => $editing || $creatingNew ? 'admin_plantillas.php' : null],
];
if ($editing) $adminBreadcrumbItems[] = ['label' => e($editing['nombre']), 'href' => null];
if ($creatingNew) $adminBreadcrumbItems[] = ['label' => 'Nueva plantilla', 'href' => null];
@include __DIR__ . '/master/admin-breadcrumb.php';
?>

<?php if (!$editing && !$creatingNew): ?>

<div class="admin-main-header">
    <h1 class="admin-main-title">
        <i data-lucide="layout-template"></i>
        Plantillas de contrato
        <small>· reutilizables con variables</small>
    </h1>
    <div>
        <a class="btn btn-primary" href="?new=1">
            <i data-lucide="plus" style="width:14px;height:14px"></i> Nueva plantilla
        </a>
    </div>
</div>

<div class="card" style="padding:0">
<?php if (empty($plantillas)): ?>
    <div class="empty">
        <i data-lucide="file-text" style="width:36px;height:36px;color:var(--text-muted);margin-bottom:.5rem"></i>
        <div>No hay plantillas. Pulsa <strong>Nueva plantilla</strong> para crear la primera.</div>
    </div>
<?php else: ?>
<table class="plantillas">
    <thead><tr>
        <th style="padding-left:1.4rem">Plantilla</th>
        <th>Tipo</th>
        <th>Destinatario</th>
        <th>Firmantes</th>
        <th>Variables</th>
        <th>Usos</th>
        <th>Estado</th>
        <th style="text-align:right;padding-right:1.4rem">Acciones</th>
    </tr></thead>
    <tbody>
    <?php foreach ($plantillas as $p):
        $vars = json_decode($p['variables_json'] ?: '[]', true);
        $firms = json_decode($p['firmantes_json'] ?: '[]', true);
    ?>
    <tr class="<?= $p['activo'] ? '' : 'inactive' ?>">
        <td style="padding-left:1.4rem">
            <a href="?id=<?=$p['id']?>" style="color:var(--text-primary);text-decoration:none;font-weight:600"><?=e($p['nombre'])?></a>
            <div style="color:var(--text-muted);font-size:.72rem;font-family:JetBrains Mono,monospace;margin-top:.15rem"><?=e($p['slug'])?></div>
        </td>
        <td><span class="tipo-chip"><?=e($p['tipo'])?></span></td>
        <td><span class="dest-chip"><?=e($p['destinatario'])?></span></td>
        <td style="font-size:.78rem;color:var(--text-secondary)"><?=e(implode(' → ', $firms))?></td>
        <td style="color:var(--text-muted);font-size:.78rem"><?=count($vars)?></td>
        <td style="color:var(--text-muted);font-size:.78rem"><?=(int)$p['usos']?></td>
        <td><span class="pill <?= $p['activo'] ? 'active' : '' ?>"><?= $p['activo'] ? 'Activa' : 'Archivada' ?></span></td>
        <td>
            <div class="actions">
                <a class="icon-btn" href="?preview_pdf=<?=$p['id']?>" target="_blank" title="Preview PDF"><i data-lucide="eye" style="width:14px;height:14px"></i></a>
                <a class="icon-btn" href="?id=<?=$p['id']?>" title="Editar"><i data-lucide="pencil" style="width:14px;height:14px"></i></a>
                <button class="icon-btn" onclick="duplicatePl(<?=$p['id']?>)" title="Duplicar"><i data-lucide="copy" style="width:14px;height:14px"></i></button>
                <button class="icon-btn" onclick="toggleActive(<?=$p['id']?>)" title="<?= $p['activo'] ? 'Archivar' : 'Activar' ?>"><i data-lucide="<?= $p['activo'] ? 'archive' : 'unarchive' ?>" style="width:14px;height:14px"></i></button>
                <?php if ((int)$p['usos'] === 0): ?>
                <button class="icon-btn" onclick="deletePl(<?=$p['id']?>, '<?=e(addslashes($p['nombre']))?>')" title="Borrar"><i data-lucide="trash-2" style="width:14px;height:14px;color:#ff6b6b"></i></button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>

<div class="card card-pad">
    <div style="font-size:.85rem;line-height:1.7;color:var(--text-secondary)">
        <strong style="color:var(--text-primary)">Cómo funcionan las plantillas</strong><br>
        El HTML se escribe con placeholders <code style="background:var(--bg-subtle);padding:.1rem .3rem;border-radius:3px;color:var(--mint)">{{variable}}</code> que se rellenan cuando creas un contrato concreto. Puedes usar modificadores: <code style="background:var(--bg-subtle);padding:.1rem .3rem;border-radius:3px">{{importe|money}}</code>, <code style="background:var(--bg-subtle);padding:.1rem .3rem;border-radius:3px">{{fecha|date}}</code>, <code style="background:var(--bg-subtle);padding:.1rem .3rem;border-radius:3px">{{nombre|upper}}</code>. Las clases CSS disponibles son <code style="background:var(--bg-subtle);padding:.1rem .3rem;border-radius:3px">.tp-cover</code>, <code style="background:var(--bg-subtle);padding:.1rem .3rem;border-radius:3px">.tp-section</code>, <code style="background:var(--bg-subtle);padding:.1rem .3rem;border-radius:3px">.tp-table</code>, <code style="background:var(--bg-subtle);padding:.1rem .3rem;border-radius:3px">.tp-callout</code>.
    </div>
</div>

<?php else: /* === EDITOR === */ ?>

<form id="editForm" onsubmit="return submitEdit(event)">
<input type="hidden" name="id" value="<?= $editing['id'] ?? '' ?>">

<div class="admin-main-header">
    <h1 class="admin-main-title">
        <i data-lucide="<?= $editing ? 'pencil' : 'plus' ?>"></i>
        <?= $editing ? 'Editar plantilla' : 'Nueva plantilla' ?>
        <?php if ($editing): ?><small>· v<?=$editing['version']?> · <?=(int)$editing['version']?> actualización<?=(int)$editing['version']===1?'':'es'?></small><?php endif; ?>
    </h1>
    <div style="display:flex;gap:.5rem">
        <a class="btn btn-ghost" href="admin_plantillas.php">Cancelar</a>
        <?php if ($editing): ?>
        <a class="btn" href="?preview_pdf=<?=$editing['id']?>" target="_blank">
            <i data-lucide="eye" style="width:14px;height:14px"></i> Preview PDF
        </a>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">
            <i data-lucide="save" style="width:14px;height:14px"></i> Guardar
        </button>
    </div>
</div>

<div class="editor-grid">
    <div>
        <div class="card card-pad">
            <div class="field-row">
                <div class="field">
                    <label>Nombre</label>
                    <input type="text" name="nombre" value="<?=e($editing['nombre'] ?? '')?>" placeholder="Ej. NDA colaboración freelance" required>
                </div>
                <div class="field">
                    <label>Slug (único)</label>
                    <input type="text" name="slug" value="<?=e($editing['slug'] ?? '')?>" placeholder="nda-colaboracion-freelance" pattern="[a-z0-9\-]+" required>
                    <div class="help">Solo minúsculas, números y guiones. No se puede cambiar después.</div>
                </div>
            </div>

            <div class="field-row-3">
                <div class="field">
                    <label>Tipo</label>
                    <select name="tipo">
                        <?php foreach (['nda','msa','sow','dpa','change_order','mantenimiento','custom'] as $t): ?>
                        <option value="<?=$t?>" <?= ($editing['tipo'] ?? 'custom') === $t ? 'selected' : '' ?>><?=$t?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Destinatario</label>
                    <select name="destinatario">
                        <?php foreach (['cliente','proveedor','ambos'] as $d): ?>
                        <option value="<?=$d?>" <?= ($editing['destinatario'] ?? 'cliente') === $d ? 'selected' : '' ?>><?=$d?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Retención (años)</label>
                    <input type="number" name="retencion_anios" value="<?=(int)($editing['retencion_anios'] ?? 6)?>" min="1" max="30">
                </div>
            </div>

            <div class="field">
                <label>Firmantes (en orden)</label>
                <div class="firmantes-picker">
                    <label><input type="checkbox" id="firm_cliente" <?= in_array('cliente', $editingFirmantes, true) ? 'checked' : '' ?>> Cliente</label>
                    <label><input type="checkbox" id="firm_proveedor" <?= in_array('proveedor', $editingFirmantes, true) ? 'checked' : '' ?>> Proveedor</label>
                    <label><input type="checkbox" id="firm_tp" <?= in_array('tp', $editingFirmantes, true) ? 'checked' : '' ?>> Tres Puntos</label>
                </div>
                <div class="help">Orden: quien firma primero va marcado primero. La contra-firma de TP casi siempre es la última.</div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label>OTP email obligatorio</label>
                    <select name="require_otp">
                        <option value="0" <?= empty($editing['require_otp']) ? 'selected' : '' ?>>No</option>
                        <option value="1" <?= !empty($editing['require_otp']) ? 'selected' : '' ?>>Sí</option>
                    </select>
                    <div class="help">Recomendado para contratos &gt;3.000€.</div>
                </div>
                <div class="field">
                    <label>Sello tiempo TSA (Freetsa)</label>
                    <select name="require_tsa">
                        <option value="1" <?= !empty($editing['require_tsa']) ? 'selected' : '' ?>>Sí</option>
                        <option value="0" <?= empty($editing['require_tsa']) ? 'selected' : '' ?>>No</option>
                    </select>
                    <div class="help">Refuerza probatorio. Gratis.</div>
                </div>
            </div>
        </div>

        <div class="card card-pad">
            <div class="field">
                <label>HTML del contrato</label>
                <textarea name="html_content" id="htmlEditor" class="html-editor" oninput="detectVars()" placeholder='<div class="tp-cover"><div class="brand">TRES PUNTOS</div><hr class="rule"><h1>Título del contrato</h1>...</div>&#10;&#10;<div class="tp-section"><h2>1. Cláusula</h2><p>Texto con {{variable}}...</p></div>' required><?=e($editingHtml)?></textarea>
                <div class="help">
                    Usa <code>{{nombre_variable}}</code> para placeholders. Modificadores: <code>|money</code>, <code>|date</code>, <code>|upper</code>, <code>|lower</code>.
                    Clases CSS: <code>.tp-cover</code> (portada), <code>.tp-section</code> (sección), <code>.tp-table</code> (tabla), <code>.tp-callout</code> (recuadro mint).
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="sticky-side">
            <div class="vars-panel">
                <h3>Variables detectadas</h3>
                <div class="vars-detected" id="varsDetected">
                    <div style="color:var(--text-muted);font-size:.75rem">Escribe <code>{{variable}}</code> en el HTML…</div>
                </div>

                <h3 style="margin-top:1.2rem">Configuración de variables</h3>
                <div id="varsConfig"></div>
                <div class="help" style="margin-top:.5rem">Se auto-declaran al detectarse. Solo haz falta personalizar label/tipo/default.</div>
            </div>

            <div class="vars-panel" style="margin-top:1rem">
                <h3>Clases CSS disponibles</h3>
                <div style="font-size:.73rem;color:var(--text-muted);line-height:1.7">
                    <code style="color:var(--mint)">.tp-cover</code> portada con logo + título<br>
                    <code style="color:var(--mint)">.tp-cover h1</code> título principal<br>
                    <code style="color:var(--mint)">.tp-cover .subtitle</code> subtítulo mint<br>
                    <code style="color:var(--mint)">.tp-cover .rule</code> línea decorativa<br>
                    <code style="color:var(--mint)">.tp-section</code> bloque de cláusula<br>
                    <code style="color:var(--mint)">.tp-section h2</code> título sección<br>
                    <code style="color:var(--mint)">.tp-section h3</code> subtítulo<br>
                    <code style="color:var(--mint)">.tp-table</code> tabla con cabeceras grises<br>
                    <code style="color:var(--mint)">.tp-callout</code> recuadro destacado<br>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<?php endif; ?>
</main>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
lucide.createIcons();
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

<?php if ($editing || $creatingNew): ?>
const INITIAL_VARS = <?= json_encode($editingVars) ?>;

function detectVars(){
    const html = document.getElementById('htmlEditor').value;
    const rx = /\{\{\s*([a-zA-Z0-9_]+)(?:\s*\|\s*[a-zA-Z]+)?\s*\}\}/g;
    const seen = new Set();
    let m;
    while ((m = rx.exec(html)) !== null) seen.add(m[1]);
    const arr = Array.from(seen);
    renderVars(arr);
}

function renderVars(names){
    const detected = document.getElementById('varsDetected');
    const config = document.getElementById('varsConfig');
    if (names.length === 0) {
        detected.innerHTML = '<div style="color:var(--text-muted);font-size:.75rem">Escribe <code>{{variable}}</code> en el HTML…</div>';
        config.innerHTML = '';
        return;
    }
    detected.innerHTML = names.map(n => `<span class="var-tag">{{${n}}}</span>`).join('');

    // Mapear a INITIAL_VARS para preservar labels/defaults
    const existing = {};
    INITIAL_VARS.forEach(v => existing[v.name] = v);
    config.innerHTML = names.map(n => {
        const v = existing[n] || { name: n, label: n.replace(/_/g,' '), type: 'text', default: '' };
        return `<div class="var-editor" data-name="${n}">
            <div style="font-family:monospace;color:var(--mint);font-size:.75rem">${n}</div>
            <input type="text" data-k="label" value="${escAttr(v.label||'')}" placeholder="Etiqueta">
            <select data-k="type">
                <option value="text" ${v.type==='text'?'selected':''}>text</option>
                <option value="textarea" ${v.type==='textarea'?'selected':''}>textarea</option>
                <option value="number" ${v.type==='number'?'selected':''}>number</option>
                <option value="date" ${v.type==='date'?'selected':''}>date</option>
            </select>
            <input type="text" data-k="default" value="${escAttr(v.default||'')}" placeholder="Default" style="grid-column: span 4; margin-top:.3rem">
        </div>`;
    }).join('');
}

function escAttr(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function collectVars(){
    return Array.from(document.querySelectorAll('#varsConfig .var-editor')).map(el => {
        const name = el.dataset.name;
        const label = el.querySelector('[data-k=label]')?.value || name;
        const type = el.querySelector('[data-k=type]')?.value || 'text';
        const def = el.querySelector('[data-k=default]')?.value || '';
        return { name, label, type, default: def };
    });
}

async function submitEdit(ev){
    ev.preventDefault();
    const form = document.getElementById('editForm');
    const fd = new FormData(form);
    fd.append('action', 'save');
    fd.append('csrf_token', CSRF_TOKEN);

    const firmantes = [];
    if (document.getElementById('firm_cliente').checked) firmantes.push('cliente');
    if (document.getElementById('firm_proveedor').checked) firmantes.push('proveedor');
    if (document.getElementById('firm_tp').checked) firmantes.push('tp');
    if (firmantes.length === 0) { alert('Marca al menos un firmante'); return false; }
    fd.append('firmantes', JSON.stringify(firmantes));

    fd.append('variables', JSON.stringify(collectVars()));

    const res = await fetch('admin_plantillas.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) {
        location.href = 'admin_plantillas.php?id=' + data.id;
    } else {
        alert('Error: ' + (data.error || 'desconocido'));
    }
    return false;
}

detectVars();
<?php endif; ?>

async function duplicatePl(id){
    if (!confirm('¿Duplicar plantilla?')) return;
    const fd = new FormData(); fd.append('action','duplicate'); fd.append('id', id); fd.append('csrf_token', CSRF_TOKEN);
    const res = await fetch('admin_plantillas.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) location.href = '?id=' + data.new_id;
    else alert(data.error);
}
async function toggleActive(id){
    const fd = new FormData(); fd.append('action','toggle_active'); fd.append('id', id); fd.append('csrf_token', CSRF_TOKEN);
    const res = await fetch('admin_plantillas.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) location.reload();
}
async function deletePl(id, nombre){
    if (!confirm('¿Borrar "'+nombre+'" definitivamente? Esto solo funciona si no tiene contratos asociados.')) return;
    const fd = new FormData(); fd.append('action','delete'); fd.append('id', id); fd.append('csrf_token', CSRF_TOKEN);
    const res = await fetch('admin_plantillas.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) location.reload(); else alert(data.error);
}
</script>
</body>
</html>

<?php
/**
 * Admin · Comentarios por sección + Firmas de aprobación
 *
 * Vista separada (no toca admin.php) para:
 *   - Ver comentarios de todas las propuestas o filtrar por una
 *   - Ver aprobaciones con firma (nombre, apellidos, hash, versión)
 *   - Marcar comentarios como resueltos
 *
 * Protegida por la misma ADMIN_PASSWORD del panel general.
 */

require __DIR__ . '/config.php';
session_start();

// Auth (idéntica al admin principal)
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        if ($_POST['admin_password'] === ADMIN_PASSWORD) {
            $_SESSION['admin_logged'] = true;
            header('Location: admin_feedback.php');
            exit;
        }
    }
    ?>
    <!doctype html><meta charset="utf-8"><title>Admin · Feedback</title>
    <style>body{background:#0e0e0e;color:#f5f5f5;font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0}form{background:#141414;padding:2rem;border-radius:12px;border:1px solid #1f1f1f;display:grid;gap:.75rem;width:320px}input{background:#191919;border:1px solid #1f1f1f;color:#fff;padding:.6rem;border-radius:6px}button{background:#5dffbf;color:#000;border:none;padding:.6rem;border-radius:6px;font-weight:700;cursor:pointer}</style>
    <form method="post"><strong>Admin Feedback</strong><input name="admin_password" type="password" placeholder="Contraseña" autofocus><button>Entrar</button></form>
    <?php exit;
}

$pdo = getDBConnection();

// Acción: marcar comentario resuelto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'toggle_resolved' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("UPDATE comentarios_seccion SET resuelto = CASE resuelto WHEN 1 THEN 0 ELSE 1 END WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false]);
    exit;
}

$filterPropuesta = isset($_GET['propuesta_id']) ? (int)$_GET['propuesta_id'] : 0;

$propuestas = $pdo->query("SELECT id, slug, client_name, version FROM propuestas ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$where = $filterPropuesta > 0 ? "WHERE c.propuesta_id = " . $filterPropuesta : "";
$comentarios = $pdo->query("
    SELECT c.*, p.slug, p.client_name
    FROM comentarios_seccion c
    LEFT JOIN propuestas p ON p.id = c.propuesta_id
    $where
    ORDER BY c.created_at DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$whereAprob = $filterPropuesta > 0 ? "WHERE a.propuesta_id = " . $filterPropuesta : "WHERE a.firmante_nombre IS NOT NULL";
$aprobaciones = $pdo->query("
    SELECT a.*, p.slug, p.client_name
    FROM aprobaciones a
    LEFT JOIN propuestas p ON p.id = a.propuesta_id
    $whereAprob
    ORDER BY a.aprobado_at DESC
") ->fetchAll(PDO::FETCH_ASSOC);

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Admin · Comentarios y firmas</title>
<style>
:root {
    --mint: #5dffbf;
    --bg-base: #0e0e0e; --bg-surface: #141414; --bg-subtle: #191919; --bg-muted: #1f1f1f;
    --text-primary: #f5f5f5; --text-secondary: #b3b3b3; --text-muted: #8a8a8a;
    --border-base: #1f1f1f; --border-strong: #2a2a2a;
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg-base); color: var(--text-primary); font: 14px/1.5 system-ui, sans-serif; }
header { padding: 1.25rem 2rem; border-bottom: 1px solid var(--border-base); background: var(--bg-surface); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; }
h1 { margin: 0; font-size: 1.15rem; }
header a { color: var(--mint); text-decoration: none; }
main { padding: 1.5rem 2rem; display: grid; gap: 2rem; max-width: 1300px; }
h2 { font-size: 1rem; color: var(--text-secondary); letter-spacing:.04em; text-transform: uppercase; margin: 0 0 .75rem; border-bottom: 1px solid var(--border-base); padding-bottom: .5rem; }
select { background: var(--bg-subtle); border: 1px solid var(--border-base); color: var(--text-primary); padding: .4rem .6rem; border-radius: 6px; }
table { width: 100%; border-collapse: collapse; background: var(--bg-surface); border-radius: 8px; overflow: hidden; }
th, td { padding: .7rem .9rem; text-align: left; border-bottom: 1px solid var(--border-base); vertical-align: top; font-size: .85rem; }
th { background: var(--bg-subtle); color: var(--text-secondary); font-weight: 600; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; }
tr:last-child td { border-bottom: 0; }
tr.resolved { opacity: .5; }
.cliente { color: var(--mint); font-weight: 600; }
.section-tag { display: inline-block; background: var(--bg-muted); color: var(--text-secondary); padding: .15rem .45rem; border-radius: 999px; font-size: .7rem; font-family: monospace; }
.texto { white-space: pre-wrap; max-width: 480px; }
.hash { font-family: monospace; color: var(--text-muted); font-size: .72rem; word-break: break-all; }
.btn { background: var(--bg-muted); color: var(--text-primary); border: 1px solid var(--border-strong); padding: .3rem .55rem; border-radius: 4px; cursor: pointer; font-size: .72rem; }
.btn:hover { border-color: var(--mint); color: var(--mint); }
.pill { display:inline-block; padding:.15rem .5rem; border-radius:999px; font-size:.7rem; font-weight:600; }
.pill.doc { background: rgba(93,255,191,.15); color: var(--mint); }
.pill.pdf { background: rgba(123,150,255,.15); color: #7b96ff; }
.empty { color: var(--text-muted); padding: 2rem; text-align: center; background: var(--bg-surface); border-radius: 8px; }
.filter { display: flex; gap: .75rem; align-items: center; }
</style>
</head>
<body>
<header>
    <h1>📝 Comentarios y firmas · <span style="color: var(--text-muted); font-weight:400">Tres Puntos</span></h1>
    <div class="filter">
        <form method="get">
            <label style="color:var(--text-muted);margin-right:.4rem">Propuesta:</label>
            <select name="propuesta_id" onchange="this.form.submit()">
                <option value="0">— Todas —</option>
                <?php foreach ($propuestas as $p): ?>
                    <option value="<?=$p['id']?>" <?=$filterPropuesta===(int)$p['id']?'selected':''?>>#<?=$p['id']?> · <?=e($p['client_name'])?> (<?=e($p['slug'])?>)</option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="admin.php">← Admin principal</a>
    </div>
</header>

<main>
    <section>
        <h2>Firmas de aprobación (<?= count($aprobaciones) ?>)</h2>
        <?php if (!$aprobaciones): ?>
            <div class="empty">Sin firmas todavía.</div>
        <?php else: ?>
        <table>
            <thead><tr>
                <th>Fecha</th><th>Cliente / Propuesta</th><th>Tipo</th><th>Firmante</th><th>Email</th><th>Versión</th><th>Hash</th><th>IP</th>
            </tr></thead>
            <tbody>
            <?php foreach ($aprobaciones as $a): ?>
                <tr>
                    <td><?=e(date('d/m/Y H:i', strtotime($a['aprobado_at'])))?></td>
                    <td><span class="cliente"><?=e($a['client_name'])?></span><br><small><?=e($a['slug'])?></small></td>
                    <td><span class="pill <?=$a['tipo']==='presupuesto'?'pdf':'doc'?>"><?=e($a['tipo'])?></span></td>
                    <td><?=e($a['firmante_nombre'] ?: '—')?> <?=e($a['firmante_apellidos'] ?: '')?></td>
                    <td><?=e($a['firmante_email'] ?: '—')?></td>
                    <td><?=e($a['version_firmada'] ?: '—')?></td>
                    <td class="hash" title="<?=e($a['firma_hash'])?>"><?=e(substr($a['firma_hash'] ?? '', 0, 24))?><?=$a['firma_hash']?'…':'—'?></td>
                    <td><?=e($a['ip_address'] ?: '—')?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <section>
        <h2>Comentarios por sección (<?= count($comentarios) ?>)</h2>
        <?php if (!$comentarios): ?>
            <div class="empty">Sin comentarios todavía.</div>
        <?php else: ?>
        <table>
            <thead><tr>
                <th>Fecha</th><th>Cliente</th><th>Sección</th><th>Autor</th><th>Comentario</th><th>Estado</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($comentarios as $c): ?>
                <tr class="<?=$c['resuelto']?'resolved':''?>" data-id="<?=$c['id']?>">
                    <td><?=e(date('d/m/Y H:i', strtotime($c['created_at'])))?></td>
                    <td><span class="cliente"><?=e($c['client_name'])?></span><br><small><a href="/p/<?=e($c['slug'])?>#<?=e($c['section_anchor'])?>" target="_blank" style="color:var(--text-muted)"><?=e($c['slug'])?></a></small></td>
                    <td><span class="section-tag"><?=e($c['section_title'] ?: $c['section_anchor'])?></span></td>
                    <td><?=e($c['autor_nombre'])?> <?=e($c['autor_apellidos'])?></td>
                    <td class="texto"><?=nl2br(e($c['texto']))?></td>
                    <td><?=$c['resuelto']?'✅ Resuelto':'🟡 Abierto'?></td>
                    <td><button class="btn" onclick="toggleResolved(<?=$c['id']?>)"><?=$c['resuelto']?'Reabrir':'Resolver'?></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</main>

<script>
async function toggleResolved(id) {
    const body = new URLSearchParams();
    body.append('action', 'toggle_resolved');
    body.append('id', id);
    const r = await fetch('admin_feedback.php', { method: 'POST', body }).then(r => r.json()).catch(() => ({}));
    if (r.success) location.reload();
}
</script>
</body></html>

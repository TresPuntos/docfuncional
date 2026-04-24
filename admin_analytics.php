<?php
/**
 * Admin · Analítica por propuesta
 *
 * Vista completa para cada propuesta:
 *   - Mapa de calor por sección (vertical, con barras)
 *   - Timeline cronológica de sesiones
 *   - Drill-down por sesión individual
 *   - Identidad del visitante cruzada con comentarios/firmas
 *
 * URL: /admin_analytics.php?propuesta_id=X
 * Protegida con ADMIN_PASSWORD (misma sesión que admin.php y admin_feedback.php).
 */

require __DIR__ . '/config.php';
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        if ($_POST['admin_password'] === ADMIN_PASSWORD) {
            $_SESSION['admin_logged'] = true;
            $redir = 'admin_analytics.php' . (isset($_GET['propuesta_id']) ? '?propuesta_id=' . (int)$_GET['propuesta_id'] : '');
            header('Location: ' . $redir);
            exit;
        }
    }
    ?>
    <!doctype html><meta charset="utf-8"><title>Admin · Analytics</title>
    <style>body{background:#0e0e0e;color:#f5f5f5;font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0}form{background:#141414;padding:2rem;border-radius:12px;border:1px solid #1f1f1f;display:grid;gap:.75rem;width:320px}input{background:#191919;border:1px solid #1f1f1f;color:#fff;padding:.6rem;border-radius:6px}button{background:#5dffbf;color:#000;border:none;padding:.6rem;border-radius:6px;font-weight:700;cursor:pointer}</style>
    <form method="post"><strong>Admin Analytics</strong><input name="admin_password" type="password" placeholder="Contraseña" autofocus><button>Entrar</button></form>
    <?php exit;
}

$pdo = getDBConnection();
$propuestaId = isset($_GET['propuesta_id']) ? (int)$_GET['propuesta_id'] : 0;

if ($propuestaId <= 0) {
    // Listar propuestas con actividad
    $list = $pdo->query("
        SELECT p.id, p.slug, p.client_name, COUNT(DISTINCT e.sesion_id) AS sesiones, MAX(e.created_at) AS last_event
        FROM propuestas p
        LEFT JOIN propuesta_eventos e ON e.propuesta_id = p.id
        WHERE p.status = 1
        GROUP BY p.id
        ORDER BY last_event DESC, p.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    render_layout('Analytics — elige propuesta', function() use ($list) {
        echo '<h2>Propuestas activas</h2>';
        echo '<div class="grid">';
        foreach ($list as $p) {
            $sesiones = (int)$p['sesiones'];
            $last = $p['last_event'] ? date('d/m/y H:i', strtotime($p['last_event'])) : '—';
            $href = 'admin_analytics.php?propuesta_id=' . (int)$p['id'];
            echo '<a href="' . htmlspecialchars($href) . '" class="card">';
            echo '<div class="card-title">' . htmlspecialchars($p['client_name']) . '</div>';
            echo '<div class="card-sub">' . htmlspecialchars($p['slug']) . '</div>';
            echo '<div class="card-stats">' . $sesiones . ' sesiones · última ' . $last . '</div>';
            echo '</a>';
        }
        echo '</div>';
    });
    exit;
}

$prop = $pdo->prepare("SELECT id, slug, client_name, version FROM propuestas WHERE id = ?");
$prop->execute([$propuestaId]);
$prop = $prop->fetch(PDO::FETCH_ASSOC);
if (!$prop) {
    render_layout('Propuesta no encontrada', function() { echo '<p class="empty">No existe esa propuesta.</p>'; });
    exit;
}

// --- Dwell agregado por sección ---
$dwellRows = $pdo->prepare("
    SELECT section_anchor,
           SUM(dwell_ms) AS total_ms,
           COUNT(DISTINCT sesion_id) AS sesiones,
           COUNT(*) AS hits
    FROM propuesta_eventos
    WHERE propuesta_id = ? AND tipo = 'section_dwell' AND section_anchor IS NOT NULL
    GROUP BY section_anchor
    ORDER BY total_ms DESC
");
$dwellRows->execute([$propuestaId]);
$dwellBySection = $dwellRows->fetchAll(PDO::FETCH_ASSOC);

// --- Filtro: por defecto ocultamos eventos internos (equipo Tres Puntos).
// ?include_internal=1 en la URL los muestra (para auditoría o debug).
$includeInternal = isset($_GET['include_internal']) && $_GET['include_internal'] === '1';
$internalFilter = $includeInternal ? '' : ' AND (is_internal IS NULL OR is_internal = 0)';

// --- Sesiones ---
$sesiones = $pdo->prepare("
    SELECT sesion_id, visitor_hash,
           MAX(visitor_name)  AS visitor_name,
           MAX(visitor_email) AS visitor_email,
           MAX(is_internal)   AS is_internal,
           MIN(created_at) AS started_at,
           MAX(created_at) AS ended_at,
           COUNT(*) AS event_count,
           MAX(CASE WHEN tipo = 'scroll_depth_100' THEN 100
                    WHEN tipo = 'scroll_depth_75' THEN 75
                    WHEN tipo = 'scroll_depth_50' THEN 50
                    WHEN tipo = 'scroll_depth_25' THEN 25 ELSE 0 END) AS max_scroll,
           SUM(CASE WHEN tipo = 'presupuesto_open' THEN 1 ELSE 0 END) AS vio_presupuesto,
           SUM(CASE WHEN tipo = 'firma_abandoned' THEN 1 ELSE 0 END) AS firma_aborted,
           SUM(CASE WHEN tipo = 'firma_approved' THEN 1 ELSE 0 END) AS firma_ok
    FROM propuesta_eventos
    WHERE propuesta_id = ? $internalFilter
    GROUP BY sesion_id
    ORDER BY started_at DESC
");
$sesiones->execute([$propuestaId]);
$sesiones = $sesiones->fetchAll(PDO::FETCH_ASSOC);

// --- Identidad: cruzar visitor_hash con firmantes/comentaristas por IP/UA aproximado ---
// No tenemos visitor_hash en aprobaciones/comentarios, así que usamos heurística por ip_address + tiempo
$identitiesByHash = [];
$firmantes = $pdo->prepare("
    SELECT ip_address, firmante_nombre, firmante_apellidos
    FROM aprobaciones WHERE propuesta_id = ? AND firmante_nombre IS NOT NULL
");
$firmantes->execute([$propuestaId]);
foreach ($firmantes->fetchAll(PDO::FETCH_ASSOC) as $f) {
    if (!empty($f['ip_address'])) {
        $identitiesByHash[$f['ip_address']] = trim($f['firmante_nombre'] . ' ' . $f['firmante_apellidos']);
    }
}
$comentaristas = $pdo->prepare("
    SELECT ip_address, autor_nombre, autor_apellidos
    FROM comentarios_seccion WHERE propuesta_id = ? AND autor_nombre IS NOT NULL AND ip_address IS NOT NULL
    GROUP BY ip_address
");
$comentaristas->execute([$propuestaId]);
foreach ($comentaristas->fetchAll(PDO::FETCH_ASSOC) as $c) {
    if (!isset($identitiesByHash[$c['ip_address']])) {
        $identitiesByHash[$c['ip_address']] = trim($c['autor_nombre'] . ' ' . $c['autor_apellidos']);
    }
}
// Como visitor_hash usa IP+UA, si la IP matchea probablemente es esta persona. Aproximación — imperfecta pero útil.

// --- Drill down ---
$drillSesion = isset($_GET['sesion_id']) ? trim($_GET['sesion_id']) : '';
$drillEvents = [];
if ($drillSesion) {
    $st = $pdo->prepare("
        SELECT tipo, section_anchor, dwell_ms, scroll_depth, meta, created_at
        FROM propuesta_eventos
        WHERE propuesta_id = ? AND sesion_id = ?
        ORDER BY created_at ASC
    ");
    $st->execute([$propuestaId, $drillSesion]);
    $drillEvents = $st->fetchAll(PDO::FETCH_ASSOC);
}

// --- Totales ---
$totalSesiones = count($sesiones);
$totalDwellMs = array_sum(array_column($dwellBySection, 'total_ms'));
$maxDwell = $dwellBySection ? max(1, (int)$dwellBySection[0]['total_ms']) : 1;
$visitantesUnicos = count(array_unique(array_column($sesiones, 'visitor_hash')));

function render_layout(string $title, callable $body): void {
    global $propuestaId;
    ?>
    <!doctype html>
    <html lang="es"><head>
    <meta charset="utf-8"><title>Admin · <?=htmlspecialchars($title)?></title>
    <style>
    :root {
        --mint: #5dffbf; --mint-rgb: 93, 255, 191;
        --bg-base: #0e0e0e; --bg-surface: #141414; --bg-subtle: #191919; --bg-muted: #1f1f1f;
        --text-primary: #f5f5f5; --text-secondary: #b3b3b3; --text-muted: #8a8a8a;
        --border-base: #1f1f1f; --border-strong: #2a2a2a;
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg-base); color: var(--text-primary); font: 14px/1.5 system-ui, sans-serif; }
    header { padding: 1.25rem 2rem; border-bottom: 1px solid var(--border-base); background: var(--bg-surface); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; position:sticky; top:0; z-index:10; }
    h1 { margin: 0; font-size: 1.15rem; }
    header a { color: var(--mint); text-decoration: none; }
    main { padding: 1.5rem 2rem; max-width: 1400px; margin: 0 auto; }
    h2 { font-size: 1.05rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .04em; margin: 0 0 1rem; border-bottom: 1px solid var(--border-base); padding-bottom: .5rem; }
    h3 { font-size: .9rem; color: var(--text-secondary); margin: 1rem 0 .5rem; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem; }
    .card { background: var(--bg-surface); border: 1px solid var(--border-base); border-radius: 10px; padding: 1rem 1.2rem; text-decoration: none; color: inherit; transition: border-color .15s; display: block; }
    .card:hover { border-color: var(--mint); }
    .card-title { font-weight: 600; color: var(--mint); }
    .card-sub { font-family: monospace; font-size: .72rem; color: var(--text-muted); margin: .15rem 0 .35rem; }
    .card-stats { font-size: .78rem; color: var(--text-secondary); }
    .empty { color: var(--text-muted); padding: 2rem; text-align: center; background: var(--bg-surface); border-radius: 8px; }
    .cols { display: grid; grid-template-columns: 1fr 1.3fr; gap: 2rem; margin-top: 1.5rem; }
    @media (max-width: 960px) { .cols { grid-template-columns: 1fr; } }
    .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .75rem; margin-bottom: 1.5rem; }
    .kpi { background: var(--bg-surface); border: 1px solid var(--border-base); border-radius: 8px; padding: .75rem .9rem; }
    .kpi-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); }
    .kpi-value { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-top: .2rem; }
    .kpi-value small { font-size: .72rem; font-weight: 400; color: var(--text-muted); margin-left: .3rem; }
    .heatmap { background: var(--bg-surface); border: 1px solid var(--border-base); border-radius: 10px; padding: 1rem; }
    .heat-row { display: grid; grid-template-columns: 140px 1fr 80px; align-items: center; gap: .75rem; padding: .35rem 0; border-bottom: 1px dashed var(--border-base); font-size: .82rem; }
    .heat-row:last-child { border-bottom: 0; }
    .heat-label { color: var(--text-secondary); font-family: monospace; font-size: .72rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .heat-bar { background: var(--bg-muted); border-radius: 4px; height: 14px; overflow: hidden; position: relative; }
    .heat-fill { height: 100%; background: linear-gradient(90deg, rgba(var(--mint-rgb), .2), var(--mint)); border-radius: 4px; }
    .heat-value { text-align: right; font-variant-numeric: tabular-nums; color: var(--text-secondary); font-size: .75rem; }
    .sessions { background: var(--bg-surface); border: 1px solid var(--border-base); border-radius: 10px; padding: .25rem 0; }
    .session { padding: .7rem 1rem; border-bottom: 1px dashed var(--border-base); display: flex; gap: .7rem; align-items: flex-start; text-decoration: none; color: inherit; transition: background .1s; }
    .session:hover { background: var(--bg-subtle); }
    .session:last-child { border-bottom: 0; }
    .session.active { background: rgba(var(--mint-rgb), .08); }
    .session-time { font-variant-numeric: tabular-nums; color: var(--text-muted); font-size: .72rem; min-width: 90px; }
    .session-body { flex: 1; }
    .session-title { color: var(--text-primary); font-weight: 500; font-size: .84rem; }
    .session-meta { font-size: .72rem; color: var(--text-muted); margin-top: .15rem; }
    .pill { display: inline-block; background: var(--bg-muted); color: var(--text-secondary); padding: .08rem .45rem; border-radius: 999px; font-size: .65rem; font-weight: 600; margin-right: .3rem; margin-top: .15rem; }
    .pill.hot { background: rgba(var(--mint-rgb), .18); color: var(--mint); }
    .pill.warn { background: rgba(255,150,50,.15); color: #ff9632; }
    .pill.error { background: rgba(255,80,80,.15); color: #ff5050; }
    .timeline { background: var(--bg-surface); border: 1px solid var(--border-base); border-radius: 10px; padding: 1rem; margin-top: 1rem; }
    .timeline-item { display: grid; grid-template-columns: 90px 1fr; gap: 1rem; padding: .35rem 0; font-size: .78rem; border-bottom: 1px dashed var(--border-base); }
    .timeline-item:last-child { border-bottom: 0; }
    .timeline-time { font-variant-numeric: tabular-nums; color: var(--text-muted); font-size: .72rem; }
    .timeline-text { color: var(--text-primary); }
    .timeline-text strong { color: var(--mint); }
    .event-icon { display: inline-block; width: 1em; }
    </style>
    <script src="https://unpkg.com/lucide@latest"></script>
    </head><body>
    <?php
    global $pdo;
    $adminSidebarActive = 'analytics';
    $adminSidebarPropuestaId = $propuestaId ?? 0;
    $adminSidebarPropuestaSlug = null;
    if (($adminSidebarPropuestaId ?? 0) > 0 && isset($pdo)) {
        $stq = $pdo->prepare("SELECT slug FROM propuestas WHERE id = ?");
        $stq->execute([$adminSidebarPropuestaId]);
        $adminSidebarPropuestaSlug = $stq->fetchColumn() ?: null;
    }
    $adminSidebarPropuestas = isset($pdo) ? $pdo->query("SELECT id, slug, client_name FROM propuestas WHERE status = 1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) : [];
    ?>
    <div class="admin-layout">
    <?php include __DIR__ . '/master/admin-sidebar.php'; ?>
    <main class="admin-main">
        <?php
        // H3 + H5: breadcrumb + nav prev/next
        if ($propuestaId > 0) {
            $adminBreadcrumbItems = [
                ['label' => 'Dashboard', 'href' => 'admin.php'],
                ['label' => htmlspecialchars($title), 'href' => null],
                ['label' => 'Analytics', 'href' => null],
            ];
            $adminBreadcrumbPropNav = ['current_id' => $propuestaId, 'view' => 'analytics'];
        } else {
            $adminBreadcrumbItems = [
                ['label' => 'Dashboard', 'href' => 'admin.php'],
                ['label' => 'Analytics', 'href' => null],
            ];
            $adminBreadcrumbPropNav = null;
        }
        include __DIR__ . '/master/admin-breadcrumb.php';
        ?>
        <div class="admin-main-header">
            <h1 class="admin-main-title">
                <i data-lucide="bar-chart-3"></i>
                Analytics
                <small>· <?=htmlspecialchars($title)?></small>
            </h1>
        </div>
        <?php $body(); ?>
    </main>
    </div><!-- /.admin-layout -->
    <script>if (window.lucide) lucide.createIcons();</script>
    </body></html>
    <?php
}

function format_ms(int $ms): string {
    if ($ms < 1000) return $ms . 'ms';
    $s = $ms / 1000;
    if ($s < 60) return round($s, 1) . 's';
    $m = floor($s / 60); $r = (int)($s - $m * 60);
    return $m . 'm ' . $r . 's';
}

function format_relative(string $ts): string {
    $diff = time() - strtotime($ts);
    if ($diff < 60) return 'hace ' . $diff . 's';
    if ($diff < 3600) return 'hace ' . (int)($diff / 60) . 'min';
    if ($diff < 86400) return 'hace ' . (int)($diff / 3600) . 'h';
    return date('d/m/y H:i', strtotime($ts));
}

function format_session_duration($started, $ended): string {
    return format_ms((int)((strtotime($ended) - strtotime($started)) * 1000));
}

// --- Render ---
render_layout($prop['client_name'] . ' (' . $prop['slug'] . ')', function() use (
    $prop, $propuestaId, $dwellBySection, $sesiones, $totalSesiones, $totalDwellMs, $maxDwell, $visitantesUnicos,
    $drillSesion, $drillEvents, $identitiesByHash, $includeInternal
) {
    ?>
    <div class="kpi-row">
        <div class="kpi"><div class="kpi-label">Sesiones totales</div><div class="kpi-value"><?=$totalSesiones?></div></div>
        <div class="kpi"><div class="kpi-label">Visitantes únicos</div><div class="kpi-value"><?=$visitantesUnicos?></div></div>
        <div class="kpi"><div class="kpi-label">Tiempo total leído</div><div class="kpi-value"><?=format_ms($totalDwellMs)?></div></div>
        <div class="kpi"><div class="kpi-label">Secciones cubiertas</div><div class="kpi-value"><?=count($dwellBySection)?></div></div>
    </div>

    <div class="cols">

        <div>
            <h2>Sesiones (<?=$totalSesiones?>)</h2>
            <div class="sessions">
                <?php if (!$sesiones): ?>
                    <div class="empty">Sin aperturas registradas todavía.</div>
                <?php else: foreach ($sesiones as $s):
                    $dur = format_session_duration($s['started_at'], $s['ended_at']);
                    // Prioridad: nombre/email del login → fallback heurístico por IP → fallback hash
                    $visitorName = trim($s['visitor_name'] ?? '');
                    $visitorEmail = trim($s['visitor_email'] ?? '');
                    if ($visitorName !== '' || $visitorEmail !== '') {
                        $ident = $visitorName !== '' ? $visitorName : $visitorEmail;
                        $subIdent = $visitorName !== '' && $visitorEmail !== '' ? $visitorEmail : '';
                    } else {
                        $ident = $identitiesByHash[$s['visitor_hash']] ?? ('visitante ' . substr($s['visitor_hash'], 0, 6));
                        $subIdent = '';
                    }
                    $cls = $drillSesion === $s['sesion_id'] ? 'session active' : 'session';
                    $isIntRow = (int)($s['is_internal'] ?? 0) === 1;
                ?>
                    <a href="?propuesta_id=<?=(int)$propuestaId?>&sesion_id=<?=urlencode($s['sesion_id'])?><?=$includeInternal?'&include_internal=1':''?>" class="<?=$cls?>">
                        <div class="session-time">
                            <?=format_relative($s['started_at'])?><br>
                            <span style="color:var(--text-muted); font-size:.65rem;"><?=$dur?></span>
                        </div>
                        <div class="session-body">
                            <div class="session-title">
                                <?=htmlspecialchars($ident)?>
                                <?php if ($isIntRow): ?><span class="pill" style="background:rgba(147,51,234,.12);color:#c084fc;border-color:rgba(147,51,234,.4);">interno</span><?php endif; ?>
                            </div>
                            <?php if ($subIdent): ?>
                                <div style="color:var(--text-muted); font-size:.68rem; margin-top:2px;"><?=htmlspecialchars($subIdent)?></div>
                            <?php endif; ?>
                            <div class="session-meta">
                                <?=$s['event_count']?> eventos · scroll <?=$s['max_scroll']?>%
                                <?php if ((int)$s['vio_presupuesto'] > 0): ?><span class="pill hot">💰 presupuesto</span><?php endif; ?>
                                <?php if ((int)$s['firma_aborted'] > 0 && (int)$s['firma_ok'] === 0): ?><span class="pill warn">⚠ firma abortada</span><?php endif; ?>
                                <?php if ((int)$s['firma_ok'] > 0): ?><span class="pill hot">✓ firmó</span><?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; endif; ?>
            </div>

            <div style="margin-top:1rem;font-size:.72rem;color:var(--text-muted);">
                <?php if ($includeInternal): ?>
                    Mostrando todas las sesiones (incluye equipo Tres Puntos). <a href="?propuesta_id=<?=(int)$propuestaId?>" style="color:var(--tp-primary);">Ocultar internos →</a>
                <?php else: ?>
                    Sesiones del equipo Tres Puntos ocultas. <a href="?propuesta_id=<?=(int)$propuestaId?>&include_internal=1" style="color:var(--tp-primary);">Mostrar todas →</a>
                <?php endif; ?>
            </div>

            <?php if ($drillSesion && $drillEvents): ?>
                <div class="timeline">
                    <h3 style="margin-top:0;">Recorrido · <?=htmlspecialchars(substr($drillSesion, 0, 8))?>…</h3>
                    <?php foreach ($drillEvents as $e):
                        // Lucide icon per tipo de evento
                        $luc = [
                            'open' => 'log-in', 'close' => 'log-out', 'section_view' => 'eye',
                            'section_dwell' => 'clock', 'presupuesto_open' => 'euro',
                            'firma_open' => 'pen-tool', 'firma_abandoned' => 'alert-triangle', 'firma_approved' => 'check-circle',
                            'scroll_depth_25' => 'trending-up', 'scroll_depth_50' => 'trending-up',
                            'scroll_depth_75' => 'trending-up', 'scroll_depth_100' => 'flag',
                            'comment_add' => 'message-square',
                        ][$e['tipo']] ?? 'circle';
                        $icon = '<i data-lucide="' . $luc . '" style="width:12px;height:12px;vertical-align:-2px;"></i>';
                        $label = $e['tipo'];
                        if ($e['section_anchor']) $label .= ' · <strong>' . htmlspecialchars($e['section_anchor']) . '</strong>';
                        if ($e['dwell_ms']) $label .= ' · ' . format_ms((int)$e['dwell_ms']);
                        if ($e['scroll_depth']) $label .= ' · ' . $e['scroll_depth'] . '%';
                    ?>
                        <div class="timeline-item">
                            <div class="timeline-time"><?=date('H:i:s', strtotime($e['created_at']))?></div>
                            <div class="timeline-text"><span class="event-icon"><?=$icon?></span> <?=$label?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <h2>Mapa de calor por sección</h2>
            <div class="heatmap">
                <?php if (!$dwellBySection): ?>
                    <div class="empty">Sin eventos de lectura todavía.</div>
                <?php else: foreach ($dwellBySection as $s):
                    $pct = round(((int)$s['total_ms']) / $maxDwell * 100, 1);
                ?>
                    <div class="heat-row">
                        <div class="heat-label" title="<?=htmlspecialchars($s['section_anchor'])?>"><?=htmlspecialchars($s['section_anchor'])?></div>
                        <div class="heat-bar"><div class="heat-fill" style="width: <?=$pct?>%;"></div></div>
                        <div class="heat-value" title="<?=$s['sesiones']?> sesiones"><?=format_ms((int)$s['total_ms'])?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <p style="color:var(--text-muted); font-size:.72rem; margin-top:1rem; line-height:1.55;">
                Las barras reflejan el tiempo total de lectura acumulado entre todas las sesiones.
                Las secciones ausentes nunca se han visto. Si una sección tiene mucha acumulación pero
                pocos visitantes distintos, probablemente ahí es donde una persona concreta pasó más rato
                — señal útil para entender qué le importa a cada stakeholder.
            </p>
        </div>

    </div>
    <?php
});

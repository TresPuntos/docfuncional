<?php
/**
 * master/admin-sidebar.php — Sidebar admin (refined technical minimalism).
 *
 * Uso:
 *   $adminSidebarActive = 'dashboard' | 'comentarios' | 'analytics' | 'proveedores';
 *   $adminSidebarPropuestaId = 0;  // propuesta abierta ahora (para auto-expand + highlight)
 *   include __DIR__ . '/master/admin-sidebar.php';
 */

$active = $adminSidebarActive ?? '';
$activePropId = (int)($adminSidebarPropuestaId ?? 0);

if (!isset($pdo) || !($pdo instanceof PDO)) {
    try { $pdo = getDBConnection(); } catch (Exception $e) { $pdo = null; }
}

$allProps = [];
$statsByProp = [];
if ($pdo) {
    try {
        $allProps = $pdo->query("SELECT id, slug, client_name, status, version FROM propuestas ORDER BY status DESC, client_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $allProps = []; }
    try {
        $cq = $pdo->query("SELECT propuesta_id, SUM(CASE WHEN parent_id IS NULL AND resuelto = 0 THEN 1 ELSE 0 END) AS open_comments FROM comentarios_seccion GROUP BY propuesta_id");
        foreach ($cq as $r) $statsByProp[(int)$r['propuesta_id']]['comments'] = (int)$r['open_comments'];
    } catch (Exception $e) {}
    try {
        $pq = $pdo->query("SELECT propuesta_id, COUNT(*) AS n FROM propuesta_proveedores WHERE activo = 1 GROUP BY propuesta_id");
        foreach ($pq as $r) $statsByProp[(int)$r['propuesta_id']]['providers'] = (int)$r['n'];
    } catch (Exception $e) {}

    // Listado de proveedores por propuesta (para desplegar en el submenu)
    $provListByProp = [];
    try {
        $pvl = $pdo->query("SELECT id, propuesta_id, nombre, empresa FROM propuesta_proveedores WHERE activo = 1 ORDER BY propuesta_id, nombre");
        foreach ($pvl as $r) {
            $provListByProp[(int)$r['propuesta_id']][] = $r;
        }
    } catch (Exception $e) { $provListByProp = []; }
}

$activeProps = array_values(array_filter($allProps, fn($p) => (int)$p['status'] === 1));
$archivedProps = array_values(array_filter($allProps, fn($p) => (int)$p['status'] === 0));
?>
<style>
/* =========================================================
   Sidebar — refined technical minimalism
   Reference: Linear · Supabase · Vercel
   ========================================================= */

.admin-layout {
    display: flex;
    min-height: 100vh;
    background: var(--bg-base, #0e0e0e);
}

/* ----- Sidebar shell ----- */
.admin-sidebar {
    width: 272px;
    flex-shrink: 0;
    background: var(--bg-surface, #141414);
    border-right: 1px solid var(--border-base, #1f1f1f);
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
    font-family: var(--font-body, 'Inter', -apple-system, sans-serif);
    font-size: 13px;
    line-height: 1.4;
    color: var(--text-secondary, #b3b3b3);
    -webkit-font-smoothing: antialiased;
    user-select: none;
}

.admin-sidebar__scroll {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 16px 12px 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    scrollbar-width: thin;
    scrollbar-color: transparent transparent;
}
.admin-sidebar__scroll:hover { scrollbar-color: var(--border-strong, #2a2a2a) transparent; }
.admin-sidebar__scroll::-webkit-scrollbar { width: 4px; }
.admin-sidebar__scroll::-webkit-scrollbar-track { background: transparent; }
.admin-sidebar__scroll::-webkit-scrollbar-thumb { background: transparent; border-radius: 2px; transition: background .15s; }
.admin-sidebar__scroll:hover::-webkit-scrollbar-thumb { background: var(--border-strong, #2a2a2a); }

/* ----- Brand ----- */
.admin-sidebar__brand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 2px 8px 14px;
    margin-bottom: 2px;
    border-bottom: 1px solid var(--border-base, #1f1f1f);
}
.admin-sidebar__brand-mark {
    width: 22px; height: 22px;
    flex-shrink: 0;
    color: var(--mint, #5dffbf);
}
.admin-sidebar__brand-text {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
}
.admin-sidebar__brand-name {
    font-family: var(--font-heading, 'Plus Jakarta Sans', sans-serif);
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary, #f5f5f5);
    letter-spacing: -0.01em;
}
.admin-sidebar__brand-sub {
    font-size: 10px;
    font-weight: 500;
    color: var(--text-muted, #8a8a8a);
    letter-spacing: 0.04em;
    text-transform: uppercase;
    margin-top: 1px;
}

/* ----- Section label (PROPOSALS ACTIVE 4) ----- */
.admin-sidebar__label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 8px 6px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted, #8a8a8a);
}
.admin-sidebar__label-count {
    font-variant-numeric: tabular-nums;
    font-size: 10px;
    font-weight: 500;
    color: var(--text-muted, #8a8a8a);
    opacity: 0.6;
}

/* ----- Single-level nav item (Dashboard · Cerrar sesión) ----- */
.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 8px;
    border-radius: 6px;
    color: var(--text-secondary, #b3b3b3);
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    position: relative;
    transition: background-color .12s ease, color .12s ease;
    line-height: 1.3;
}
.nav-item:hover {
    background: rgba(255, 255, 255, 0.03);
    color: var(--text-primary, #f5f5f5);
}
.nav-item i[data-lucide] {
    width: 15px; height: 15px;
    stroke-width: 1.75;
    flex-shrink: 0;
    color: var(--text-muted, #8a8a8a);
    transition: color .12s ease;
}
.nav-item:hover i[data-lucide] { color: var(--text-secondary, #b3b3b3); }
.nav-item.is-active {
    color: var(--mint, #5dffbf);
    background: rgba(var(--mint-rgb, 93, 255, 191), 0.06);
}
.nav-item.is-active i[data-lucide] { color: var(--mint, #5dffbf); }
.nav-item.is-active::before {
    content: "";
    position: absolute;
    left: -12px;
    top: 50%;
    transform: translateY(-50%);
    width: 2px;
    height: 16px;
    background: var(--mint, #5dffbf);
    border-radius: 0 2px 2px 0;
}

/* ----- Proposal accordion ----- */
.prop {
    position: relative;
    border-radius: 6px;
    margin: 0 0 1px;
}
.prop > summary {
    list-style: none;
    cursor: pointer;
    outline: none;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    border-radius: 6px;
    color: var(--text-secondary, #b3b3b3);
    font-size: 13px;
    font-weight: 500;
    line-height: 1.3;
    transition: background-color .12s ease, color .12s ease;
}
.prop > summary::-webkit-details-marker { display: none; }
.prop > summary:hover {
    background: rgba(255, 255, 255, 0.03);
    color: var(--text-primary, #f5f5f5);
}
.prop.is-active > summary {
    color: var(--text-primary, #f5f5f5);
}
.prop.is-active::before {
    content: "";
    position: absolute;
    left: -12px;
    top: 9px;
    width: 2px;
    height: 16px;
    background: var(--mint, #5dffbf);
    border-radius: 0 2px 2px 0;
}

.prop__chevron {
    width: 12px; height: 12px;
    stroke-width: 2;
    flex-shrink: 0;
    color: var(--text-muted, #8a8a8a);
    transition: transform .15s ease, color .12s ease;
}
.prop[open] > summary .prop__chevron { transform: rotate(90deg); color: var(--text-secondary, #b3b3b3); }
.prop.is-active[open] > summary .prop__chevron { color: var(--mint, #5dffbf); }

.prop__name {
    flex: 1;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    letter-spacing: -0.005em;
}
.prop__version {
    font-family: var(--font-mono, 'JetBrains Mono', ui-monospace, monospace);
    font-size: 10px;
    font-weight: 400;
    color: var(--text-muted, #8a8a8a);
    margin-left: 5px;
    letter-spacing: 0.02em;
    font-feature-settings: "tnum";
}

/* Linear-style badge: tiny dot + tabular number */
.prop__badges {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-shrink: 0;
    padding-right: 2px;
}
.prop__badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: var(--text-muted, #8a8a8a);
    transition: color .12s ease;
}
.prop > summary:hover .prop__badge { color: var(--text-secondary, #b3b3b3); }
.prop__badge-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.prop__badge--comments { color: var(--mint, #5dffbf); }
.prop__badge--comments .prop__badge-dot { background: var(--mint, #5dffbf); box-shadow: 0 0 6px rgba(var(--mint-rgb, 93, 255, 191), .35); }
.prop__badge--providers { color: #c084fc; }
.prop__badge--providers .prop__badge-dot { background: #c084fc; box-shadow: 0 0 6px rgba(192, 132, 252, .35); }

/* ----- Submenu (tree) ----- */
.prop__sub {
    position: relative;
    padding: 2px 0 4px 13px;
    margin-left: 7px;
}
.prop__sub::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 8px;
    width: 1px;
    background: var(--border-base, #1f1f1f);
}
.prop__sub a {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 5px 8px 5px 10px;
    border-radius: 5px;
    color: var(--text-muted, #8a8a8a);
    font-size: 12.5px;
    font-weight: 450;
    text-decoration: none;
    position: relative;
    line-height: 1.3;
    transition: background-color .12s ease, color .12s ease;
}
.prop__sub a:hover {
    background: rgba(255, 255, 255, 0.03);
    color: var(--text-primary, #f5f5f5);
}
.prop__sub a.is-active {
    color: var(--mint, #5dffbf);
    background: rgba(var(--mint-rgb, 93, 255, 191), 0.06);
    font-weight: 500;
}
.prop__sub a.is-active::before {
    content: "";
    position: absolute;
    left: -14px;
    top: 9px;
    width: 6px;
    height: 1px;
    background: var(--mint, #5dffbf);
}
.prop__sub a i[data-lucide] {
    width: 13px; height: 13px;
    stroke-width: 1.75;
    color: var(--text-muted, #8a8a8a);
    flex-shrink: 0;
    transition: color .12s ease;
}
.prop__sub a:hover i[data-lucide] { color: var(--text-secondary, #b3b3b3); }
.prop__sub a.is-active i[data-lucide] { color: var(--mint, #5dffbf); }

.prop__sub-ext {
    margin-left: auto;
    width: 11px; height: 11px;
    color: var(--text-muted, #8a8a8a);
    opacity: 0;
    transition: opacity .12s ease;
}
.prop__sub a:hover .prop__sub-ext { opacity: 0.6; }

/* Labels de grupo dentro del submenu (Cliente · Proveedores · Documento) */
.prop__sub-group {
    padding: 8px 8px 4px 10px;
    font-size: 9.5px;
    font-weight: 600;
    letter-spacing: 0.09em;
    text-transform: uppercase;
    color: var(--text-muted, #8a8a8a);
    opacity: 0.65;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    line-height: 1;
}
.prop__sub-group:first-child { padding-top: 4px; }
.prop__sub-group-count {
    font-variant-numeric: tabular-nums;
    font-weight: 500;
    opacity: 0.85;
}

/* Badge inline dentro de un sub-item (ej. pending count en Comentarios) */
.prop__sub-inline-badge {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 10.5px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: var(--mint, #5dffbf);
    letter-spacing: 0.01em;
}
.prop__sub-inline-badge::before {
    content: "";
    width: 5px; height: 5px;
    border-radius: 50%;
    background: var(--mint, #5dffbf);
    box-shadow: 0 0 6px rgba(var(--mint-rgb, 93, 255, 191), 0.45);
}

/* Proveedor como sub-item con nombre + empresa */
.prop__sub-provider {
    display: flex !important;
    align-items: center;
    gap: 9px;
}
.prop__sub-provider-initials {
    width: 18px; height: 18px;
    flex-shrink: 0;
    border-radius: 50%;
    background: rgba(192, 132, 252, 0.12);
    color: #c084fc;
    display: grid;
    place-items: center;
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.02em;
    border: 1px solid rgba(192, 132, 252, 0.2);
}
.prop__sub-provider-name {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 1px;
    overflow: hidden;
}
.prop__sub-provider-name > span:first-child {
    font-size: 12.5px;
    font-weight: 500;
    color: inherit;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.25;
}
.prop__sub-provider-name > span.empresa {
    font-size: 10px;
    color: var(--text-muted, #8a8a8a);
    font-weight: 400;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
}

/* "Invitar proveedor" como sub-item sutil */
.prop__sub-add {
    color: var(--text-muted, #8a8a8a) !important;
    font-size: 11.5px !important;
    font-style: italic;
}
.prop__sub-add:hover {
    color: var(--mint, #5dffbf) !important;
    background: rgba(var(--mint-rgb, 93, 255, 191), 0.04) !important;
}
.prop__sub-add i[data-lucide] {
    color: var(--text-muted, #8a8a8a) !important;
}
.prop__sub-add:hover i[data-lucide] { color: var(--mint, #5dffbf) !important; }

.prop__sub-empty {
    padding: 5px 8px 5px 10px;
    font-size: 11.5px;
    color: var(--text-muted, #8a8a8a);
    font-style: italic;
    opacity: 0.7;
}

/* ----- Archived collapse ----- */
.prop--archived > summary { opacity: 0.55; }
.prop--archived:hover > summary { opacity: 0.85; }

.archived-toggle {
    margin: 8px 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    padding: 7px 8px;
    background: transparent;
    border: 1px solid var(--border-base, #1f1f1f);
    border-radius: 6px;
    color: var(--text-muted, #8a8a8a);
    font-family: inherit;
    font-size: 11.5px;
    font-weight: 500;
    cursor: pointer;
    letter-spacing: 0.01em;
    transition: color .12s ease, border-color .12s ease, background-color .12s ease;
}
.archived-toggle:hover {
    color: var(--text-secondary, #b3b3b3);
    border-color: var(--border-strong, #2a2a2a);
    background: rgba(255, 255, 255, 0.02);
}
.archived-toggle i[data-lucide] { width: 13px; height: 13px; stroke-width: 1.75; }
.archived-toggle__count {
    font-variant-numeric: tabular-nums;
    font-size: 11px;
    color: var(--text-muted, #8a8a8a);
    opacity: 0.7;
}

/* ----- Footer ----- */
.admin-sidebar__footer {
    flex-shrink: 0;
    padding: 10px 12px 14px;
    border-top: 1px solid var(--border-base, #1f1f1f);
    background: var(--bg-surface, #141414);
    display: flex;
    flex-direction: column;
    gap: 8px;
}

/* Leyenda de badges */
.admin-sidebar__legend {
    padding: 2px 8px 8px;
    border-bottom: 1px solid var(--border-base, #1f1f1f);
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.admin-sidebar__legend-title {
    font-size: 9.5px;
    font-weight: 600;
    letter-spacing: 0.09em;
    text-transform: uppercase;
    color: var(--text-muted, #8a8a8a);
    opacity: 0.65;
    line-height: 1;
    padding-bottom: 2px;
}
.admin-sidebar__legend-row {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: var(--text-muted, #8a8a8a);
    line-height: 1.3;
}
.admin-sidebar__legend-row strong {
    color: var(--text-secondary, #b3b3b3);
    font-weight: 500;
}
.admin-sidebar__legend-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.admin-sidebar__legend-dot--mint { background: var(--mint, #5dffbf); box-shadow: 0 0 4px rgba(var(--mint-rgb, 93, 255, 191), .4); }
.admin-sidebar__legend-dot--purple { background: #c084fc; box-shadow: 0 0 4px rgba(192, 132, 252, .4); }

/* ----- Main content area ----- */
.admin-main {
    flex: 1;
    min-width: 0;
    padding: 24px 32px 48px;
}
.admin-main-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding-bottom: 20px;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--border-base, #1f1f1f);
    flex-wrap: wrap;
}
.admin-main-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-family: var(--font-heading, 'Plus Jakarta Sans', sans-serif);
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -0.015em;
    color: var(--text-primary, #f5f5f5);
    margin: 0;
}
.admin-main-title i[data-lucide] { width: 20px; height: 20px; color: var(--mint, #5dffbf); }
.admin-main-title small {
    font-weight: 400;
    color: var(--text-muted, #8a8a8a);
    font-size: 13px;
    letter-spacing: 0;
    margin-left: 6px;
}
.admin-main-actions { display: flex; gap: 8px; align-items: center; }

/* Responsive */
@media (max-width: 900px) {
    .admin-layout { flex-direction: column; }
    .admin-sidebar {
        width: 100%;
        height: auto;
        position: static;
        border-right: 0;
        border-bottom: 1px solid var(--border-base);
    }
    .admin-sidebar__scroll { padding-bottom: 8px; }
    .admin-main { padding: 16px; }
    .nav-item.is-active::before, .prop.is-active::before { display: none; }
}
</style>

<aside class="admin-sidebar" aria-label="Navegación admin">
    <div class="admin-sidebar__scroll">

        <!-- Brand -->
        <div class="admin-sidebar__brand">
            <!-- Tres Puntos mark: three overlapping circles -->
            <svg class="admin-sidebar__brand-mark" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="6" cy="12" r="3.5" stroke="currentColor" stroke-width="1.5"/>
                <circle cx="12" cy="12" r="3.5" stroke="currentColor" stroke-width="1.5"/>
                <circle cx="18" cy="12" r="3.5" stroke="currentColor" stroke-width="1.5"/>
            </svg>
            <div class="admin-sidebar__brand-text">
                <span class="admin-sidebar__brand-name">Tres Puntos</span>
                <span class="admin-sidebar__brand-sub">Proposals</span>
            </div>
        </div>

        <!-- Top-level nav -->
        <a href="admin.php" class="nav-item <?= $active === 'dashboard' ? 'is-active' : '' ?>">
            <i data-lucide="layout-dashboard"></i>
            Dashboard
        </a>

        <?php if (!empty($activeProps)): ?>
            <div class="admin-sidebar__label">
                <span>Propuestas</span>
                <span class="admin-sidebar__label-count"><?= count($activeProps) ?></span>
            </div>
            <?php foreach ($activeProps as $p):
                $pid = (int)$p['id'];
                $isActivePid = $pid === $activePropId;
                $s = $statsByProp[$pid] ?? [];
                $commentsN = $s['comments'] ?? 0;
                $providersN = $s['providers'] ?? 0;
            ?>
                <details class="prop <?= $isActivePid ? 'is-active' : '' ?>" <?= $isActivePid ? 'open' : '' ?>>
                    <summary>
                        <i data-lucide="chevron-right" class="prop__chevron"></i>
                        <span class="prop__name" title="<?= htmlspecialchars($p['client_name']) ?>">
                            <?= htmlspecialchars($p['client_name']) ?><?php if ($p['version']): ?><span class="prop__version"><?= htmlspecialchars($p['version']) ?></span><?php endif; ?>
                        </span>
                        <?php if ($commentsN > 0 || $providersN > 0): ?>
                            <span class="prop__badges">
                                <?php if ($commentsN > 0): ?>
                                    <span class="prop__badge prop__badge--comments" title="<?= $commentsN ?> hilo<?= $commentsN === 1 ? '' : 's' ?> de comentarios">
                                        <span class="prop__badge-dot"></span><?= $commentsN ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($providersN > 0): ?>
                                    <span class="prop__badge prop__badge--providers" title="<?= $providersN ?> proveedor<?= $providersN === 1 ? '' : 'es' ?>">
                                        <span class="prop__badge-dot"></span><?= $providersN ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </summary>
                    <div class="prop__sub">
                        <!-- GRUPO 1 · Cliente -->
                        <div class="prop__sub-group">Cliente</div>
                        <a href="admin_feedback.php?propuesta_id=<?= $pid ?>" class="<?= $isActivePid && $active === 'comentarios' ? 'is-active' : '' ?>">
                            <i data-lucide="message-square-text"></i>
                            <span>Comentarios</span>
                            <?php if ($commentsN > 0): ?>
                                <span class="prop__sub-inline-badge"><?= $commentsN ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="admin_analytics.php?propuesta_id=<?= $pid ?>" class="<?= $isActivePid && $active === 'analytics' ? 'is-active' : '' ?>">
                            <i data-lucide="bar-chart-3"></i>
                            <span>Analytics</span>
                        </a>
                        <a href="/p/<?= urlencode($p['slug']) ?>?__admin_view=1" target="_blank" rel="noopener">
                            <i data-lucide="file-text"></i>
                            <span>Ver doc con comentarios</span>
                            <i data-lucide="arrow-up-right" class="prop__sub-ext"></i>
                        </a>

                        <!-- GRUPO 2 · Proveedores (listados individualmente) -->
                        <div class="prop__sub-group">
                            <span>Proveedores</span>
                            <?php if ($providersN > 0): ?><span class="prop__sub-group-count"><?= $providersN ?></span><?php endif; ?>
                        </div>
                        <?php
                        $__sidebarProvList = $provListByProp[$pid] ?? [];
                        if (!empty($__sidebarProvList)):
                            foreach ($__sidebarProvList as $__sidebarPv):
                                $__sbPvId = (int)$__sidebarPv['id'];
                                $__sbPvNombre = $__sidebarPv['nombre'] ?? '';
                                $__sbPvEmpresa = $__sidebarPv['empresa'] ?? '';
                                $__sbInitial = mb_strtoupper(mb_substr($__sbPvNombre, 0, 1));
                        ?>
                            <a href="admin_providers.php?proveedor_id=<?= $__sbPvId ?>" class="prop__sub-provider" title="<?= htmlspecialchars($__sbPvNombre . ($__sbPvEmpresa ? ' · ' . $__sbPvEmpresa : '')) ?>">
                                <span class="prop__sub-provider-initials"><?= htmlspecialchars($__sbInitial) ?></span>
                                <span class="prop__sub-provider-name">
                                    <span><?= htmlspecialchars($__sbPvNombre) ?></span>
                                    <?php if ($__sbPvEmpresa): ?><span class="empresa"><?= htmlspecialchars($__sbPvEmpresa) ?></span><?php endif; ?>
                                </span>
                            </a>
                        <?php
                            endforeach;
                            unset($__sidebarPv);
                        else: ?>
                            <div class="prop__sub-empty">Sin proveedores invitados</div>
                        <?php endif; ?>
                        <a href="admin_providers.php?propuesta_id=<?= $pid ?>" class="prop__sub-add">
                            <i data-lucide="plus"></i>
                            <span>Invitar proveedor</span>
                        </a>

                        <!-- GRUPO 3 · Documento -->
                        <div class="prop__sub-group">Documento</div>
                        <a href="/p/<?= urlencode($p['slug']) ?>" target="_blank" rel="noopener">
                            <i data-lucide="eye"></i>
                            <span>Abrir como cliente</span>
                            <i data-lucide="arrow-up-right" class="prop__sub-ext"></i>
                        </a>
                    </div>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($archivedProps)): ?>
            <div id="tp-archived-section" hidden>
                <div class="admin-sidebar__label">
                    <span>Archivadas</span>
                    <span class="admin-sidebar__label-count"><?= count($archivedProps) ?></span>
                </div>
                <?php foreach ($archivedProps as $p):
                    $pid = (int)$p['id'];
                    $isActivePid = $pid === $activePropId;
                ?>
                    <details class="prop prop--archived <?= $isActivePid ? 'is-active' : '' ?>" <?= $isActivePid ? 'open' : '' ?>>
                        <summary>
                            <i data-lucide="chevron-right" class="prop__chevron"></i>
                            <span class="prop__name"><?= htmlspecialchars($p['client_name']) ?><?php if ($p['version']): ?><span class="prop__version"><?= htmlspecialchars($p['version']) ?></span><?php endif; ?></span>
                        </summary>
                        <div class="prop__sub">
                            <a href="admin_feedback.php?propuesta_id=<?= $pid ?>"><i data-lucide="message-square-text"></i> Comentarios</a>
                            <a href="admin_providers.php?propuesta_id=<?= $pid ?>"><i data-lucide="hard-hat"></i> Proveedores</a>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
            <button type="button" class="archived-toggle" id="tp-archived-toggle" onclick="tpToggleArchived()">
                <span style="display:inline-flex; align-items:center; gap:6px;">
                    <i data-lucide="archive"></i>
                    <span id="tp-archived-toggle-label">Mostrar archivadas</span>
                </span>
                <span class="archived-toggle__count"><?= count($archivedProps) ?></span>
            </button>
        <?php endif; ?>
    </div>

    <div class="admin-sidebar__footer">
        <div class="admin-sidebar__legend" aria-label="Leyenda de badges">
            <div class="admin-sidebar__legend-title">Leyenda</div>
            <div class="admin-sidebar__legend-row">
                <span class="admin-sidebar__legend-dot admin-sidebar__legend-dot--mint"></span>
                <strong>Comentarios</strong> abiertos sin resolver
            </div>
            <div class="admin-sidebar__legend-row">
                <span class="admin-sidebar__legend-dot admin-sidebar__legend-dot--purple"></span>
                <strong>Proveedores</strong> invitados a la propuesta
            </div>
        </div>
        <a href="?logout=1" class="nav-item">
            <i data-lucide="log-out"></i>
            Cerrar sesión
        </a>
    </div>
</aside>

<script>
(function () {
    'use strict';

    // Toggle archivadas con persistencia
    const KEY_ARCH = 'tp_sidebar_show_archived';
    const section = document.getElementById('tp-archived-section');
    const label = document.getElementById('tp-archived-toggle-label');
    if (section && label) {
        function applyArch(show) {
            section.hidden = !show;
            label.textContent = show ? 'Ocultar archivadas' : 'Mostrar archivadas';
        }
        try {
            if (sessionStorage.getItem(KEY_ARCH) === '1') applyArch(true);
        } catch (e) {}
        window.tpToggleArchived = function () {
            const show = section.hidden === true;
            applyArch(show);
            try { sessionStorage.setItem(KEY_ARCH, show ? '1' : '0'); } catch (e) {}
        };
    }

    // Persistir estado expanded/collapsed de cada propuesta
    const KEY_OPEN = 'tp_sidebar_open_props';
    let openList = [];
    try { openList = JSON.parse(sessionStorage.getItem(KEY_OPEN) || '[]'); } catch (e) {}

    document.querySelectorAll('details.prop').forEach((d) => {
        const nameEl = d.querySelector('.prop__name');
        if (!nameEl) return;
        const name = (nameEl.childNodes[0] && nameEl.childNodes[0].textContent || nameEl.textContent).trim();
        // Si la propuesta está activa, forzamos abierto. Si no, aplicamos estado guardado.
        if (!d.hasAttribute('open') && openList.includes(name)) d.setAttribute('open', '');

        d.addEventListener('toggle', () => {
            let current = [];
            try { current = JSON.parse(sessionStorage.getItem(KEY_OPEN) || '[]'); } catch (e) {}
            if (d.open) {
                if (!current.includes(name)) current.push(name);
            } else {
                current = current.filter((n) => n !== name);
            }
            try { sessionStorage.setItem(KEY_OPEN, JSON.stringify(current)); } catch (e) {}
        });
    });
})();
</script>

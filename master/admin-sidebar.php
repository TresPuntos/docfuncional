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
$globalCommentsOpen = 0;
$globalProvidersTotal = 0;
$globalBudgetsUnseen = 0;
if ($pdo) {
    try {
        $allProps = $pdo->query("SELECT id, slug, client_name, status, version FROM propuestas ORDER BY status DESC, client_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $allProps = []; }
    try {
        $cq = $pdo->query("SELECT propuesta_id, SUM(CASE WHEN parent_id IS NULL AND resuelto = 0 THEN 1 ELSE 0 END) AS open_comments FROM comentarios_seccion GROUP BY propuesta_id");
        foreach ($cq as $r) {
            $statsByProp[(int)$r['propuesta_id']]['comments'] = (int)$r['open_comments'];
            $globalCommentsOpen += (int)$r['open_comments'];
        }
    } catch (Exception $e) {}
    try {
        $pq = $pdo->query("SELECT propuesta_id, COUNT(*) AS n FROM propuesta_proveedores WHERE activo = 1 GROUP BY propuesta_id");
        foreach ($pq as $r) {
            $statsByProp[(int)$r['propuesta_id']]['providers'] = (int)$r['n'];
            $globalProvidersTotal += (int)$r['n'];
        }
    } catch (Exception $e) {}
    // Presupuestos subidos por proveedor sin ver por admin (seen_at IS NULL)
    // try defensivo: si la columna seen_at no existe (pre-migración), simplemente no contamos
    try {
        $bq = $pdo->query("SELECT pv.propuesta_id, COUNT(*) AS n
                           FROM proveedor_presupuestos pp
                           JOIN propuesta_proveedores pv ON pv.id = pp.proveedor_id
                           WHERE pp.seen_at IS NULL
                           GROUP BY pv.propuesta_id");
        foreach ($bq as $r) {
            $statsByProp[(int)$r['propuesta_id']]['budgets_unseen'] = (int)$r['n'];
            $globalBudgetsUnseen += (int)$r['n'];
        }
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

/* Safety net: ningún icono Lucide dentro del sidebar puede crecer por defecto */
.admin-sidebar svg.lucide,
.admin-sidebar i[data-lucide] {
    width: 14px;
    height: 14px;
    max-width: 14px;
    max-height: 14px;
}

/* ----- Brand ----- */
.admin-sidebar__brand {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 2px 8px 12px;
    margin-bottom: 2px;
}
.admin-sidebar__brand-mark {
    width: 18px; height: 18px;
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
    font-size: 12px;
    font-weight: 600;
    color: var(--text-primary, #f5f5f5);
    letter-spacing: -0.01em;
}
.admin-sidebar__brand-sub {
    font-size: 9px;
    font-weight: 500;
    color: var(--text-muted, #8a8a8a);
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-top: 1px;
    opacity: 0.7;
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
.admin-sidebar__label-actions {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.label-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 4px;
    background: transparent;
    border: 0;
    color: var(--text-muted, #8a8a8a);
    opacity: 0.5;
    cursor: pointer;
    text-decoration: none;
    transition: opacity .12s ease, color .12s ease, background-color .12s ease;
}
.label-action-btn:hover { opacity: 1; color: var(--mint, #5dffbf); background: rgba(var(--mint-rgb, 93,255,191), 0.08); }
.label-action-btn i[data-lucide] { width: 12px; height: 12px; stroke-width: 2; }

/* H6: Search input */
.sidebar-search {
    position: relative;
    margin: 6px 0 10px;
    padding: 0 4px;
}
.sidebar-search__icon {
    position: absolute;
    left: 14px; top: 50%; transform: translateY(-50%);
    width: 13px; height: 13px;
    color: var(--text-muted, #8a8a8a);
    stroke-width: 1.75;
    pointer-events: none;
}
.sidebar-search input {
    width: 100%;
    box-sizing: border-box;
    background: var(--bg-base, #0e0e0e);
    border: 1px solid var(--border-base, #1f1f1f);
    border-radius: 6px;
    padding: 7px 38px 7px 32px;
    font-family: inherit;
    font-size: 12.5px;
    color: var(--text-primary, #f5f5f5);
    outline: none;
    transition: border-color .12s ease, background-color .12s ease;
}
.sidebar-search input::placeholder { color: var(--text-muted, #8a8a8a); }
.sidebar-search input:focus {
    border-color: var(--mint, #5dffbf);
    background: var(--bg-surface, #141414);
}
.sidebar-search__kbd {
    position: absolute;
    right: 10px; top: 50%; transform: translateY(-50%);
    font-family: var(--font-mono, 'JetBrains Mono', monospace);
    font-size: 9.5px;
    color: var(--text-muted, #8a8a8a);
    background: var(--bg-surface, #141414);
    border: 1px solid var(--border-base, #1f1f1f);
    border-radius: 3px;
    padding: 1px 5px;
    letter-spacing: 0.02em;
    pointer-events: none;
    opacity: 0.6;
}
.sidebar-search input:focus + .sidebar-search__kbd { opacity: 0; }

/* H1: badges inline en nav-item top-level */
.nav-item__badge {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    font-size: 10.5px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: var(--mint, #5dffbf);
    background: rgba(var(--mint-rgb, 93,255,191), 0.1);
    border-radius: 10px;
    padding: 1px 7px;
    letter-spacing: 0.01em;
    line-height: 1.3;
}
.nav-item__badge--budgets {
    color: #ffd84d;
    background: rgba(255, 216, 77, .12);
    border: 1px solid rgba(255, 216, 77, .3);
    position: relative;
    padding-left: 14px;
    margin-right: 4px;
    gap: 0;
}
.nav-item__badge--budgets .prop__badge-pulse {
    position: absolute;
    left: 5px; top: 50%; transform: translateY(-50%);
    width: 5px; height: 5px; border-radius: 50%;
    background: #ffd84d;
    box-shadow: 0 0 0 0 rgba(255, 216, 77, .65);
    animation: tpBudgetPulse 1.6s ease-in-out infinite;
}
.nav-item__badge--purple {
    color: #c084fc;
    background: rgba(192, 132, 252, 0.1);
}

/* Estados filtrados por search */
.prop.is-filtered-out { display: none; }
.admin-sidebar__label.is-filtered-out { display: none; }

/* Breadcrumbs (H3) */
.admin-breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--text-muted, #8a8a8a);
    margin-bottom: 6px;
}
.admin-breadcrumb a {
    color: var(--text-muted, #8a8a8a);
    text-decoration: none;
    transition: color .12s ease;
}
.admin-breadcrumb a:hover {
    color: var(--text-primary, #f5f5f5);
}
.admin-breadcrumb__sep {
    color: var(--text-muted, #8a8a8a);
    opacity: 0.5;
    font-size: 11px;
}
.admin-breadcrumb__current {
    color: var(--text-secondary, #b3b3b3);
    font-weight: 500;
}

/* H5: navegación ←/→ en header */
.admin-prop-nav {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    margin-left: 8px;
    background: var(--bg-surface, #141414);
    border: 1px solid var(--border-base, #1f1f1f);
    border-radius: 6px;
    padding: 2px;
}
.admin-prop-nav__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px; height: 26px;
    border-radius: 4px;
    background: transparent;
    border: 0;
    color: var(--text-secondary, #b3b3b3);
    cursor: pointer;
    text-decoration: none;
    transition: background-color .12s ease, color .12s ease;
}
.admin-prop-nav__btn:hover {
    background: rgba(255,255,255,0.04);
    color: var(--text-primary, #f5f5f5);
}
.admin-prop-nav__btn[aria-disabled="true"] {
    opacity: 0.3;
    pointer-events: none;
}
.admin-prop-nav__btn i[data-lucide] { width: 14px; height: 14px; stroke-width: 2; }
.admin-prop-nav__select {
    background: transparent;
    border: 0;
    color: var(--text-secondary, #b3b3b3);
    font: inherit;
    font-size: 12px;
    padding: 3px 8px;
    cursor: pointer;
    max-width: 160px;
    text-overflow: ellipsis;
}
.admin-prop-nav__select:focus { outline: 1px solid var(--mint, #5dffbf); border-radius: 3px; }

/* H4: toggle navegador interno dentro del footer sidebar */
.sidebar-internal-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 8px;
    background: transparent;
    border: 1px solid var(--border-base, #1f1f1f);
    border-radius: 6px;
    color: var(--text-muted, #8a8a8a);
    font-family: inherit;
    font-size: 11.5px;
    font-weight: 500;
    cursor: pointer;
    width: 100%;
    transition: color .12s ease, border-color .12s ease, background-color .12s ease;
}
.sidebar-internal-toggle:hover {
    color: var(--text-secondary, #b3b3b3);
    border-color: var(--border-strong, #2a2a2a);
}
.sidebar-internal-toggle.is-on {
    color: #c084fc;
    border-color: rgba(192, 132, 252, .5);
    background: rgba(192, 132, 252, .08);
}
.sidebar-internal-toggle__dot {
    width: 7px; height: 7px;
    border-radius: 999px;
    background: #666;
    flex-shrink: 0;
}
.sidebar-internal-toggle.is-on .sidebar-internal-toggle__dot { background: #c084fc; box-shadow: 0 0 6px rgba(192,132,252,.5); }
.sidebar-internal-toggle__text { flex: 1; text-align: left; }

/* ----- Single-level nav item (Dashboard · Cerrar sesión) ----- */
.nav-item {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 5px 8px;
    border-radius: 5px;
    color: var(--text-secondary, #b3b3b3);
    font-size: 12.5px;
    font-weight: 450;
    text-decoration: none;
    position: relative;
    transition: background-color .12s ease, color .12s ease;
    line-height: 1.3;
}
.nav-item:hover {
    background: rgba(255, 255, 255, 0.03);
    color: var(--text-primary, #f5f5f5);
}
.nav-item i[data-lucide],
.nav-item svg.lucide {
    width: 14px !important; height: 14px !important;
    stroke-width: 1.75;
    flex-shrink: 0;
    color: var(--text-muted, #8a8a8a);
    transition: color .12s ease;
}
.nav-item:hover i[data-lucide] { color: var(--text-secondary, #b3b3b3); }
.nav-item.is-active {
    color: var(--text-primary, #f5f5f5);
    background: rgba(255, 255, 255, 0.04);
    font-weight: 500;
}
.nav-item.is-active i[data-lucide] { color: var(--mint, #5dffbf); }
.nav-item.is-active::before {
    content: "";
    position: absolute;
    left: -12px;
    top: 50%;
    transform: translateY(-50%);
    width: 2px;
    height: 14px;
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

.prop__chevron,
svg.prop__chevron.lucide {
    width: 12px !important; height: 12px !important;
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
.prop__badge i[data-lucide],
.prop__badge svg.lucide {
    width: 12px !important; height: 12px !important;
    stroke-width: 1.9;
    flex-shrink: 0;
}
.prop__badge--comments { color: var(--mint, #5dffbf); }
.prop__badge--comments .prop__badge-dot { background: var(--mint, #5dffbf); box-shadow: 0 0 6px rgba(var(--mint-rgb, 93, 255, 191), .35); }
.prop__badge--providers { color: #c084fc; }
.prop__badge--providers .prop__badge-dot { background: #c084fc; box-shadow: 0 0 6px rgba(192, 132, 252, .35); }
/* Presupuestos nuevos sin ver — color print mint + pulso para llamar la atención */
.prop__badge--budgets {
    color: #ffd84d;
    background: rgba(255, 216, 77, .08);
    border: 1px solid rgba(255, 216, 77, .25);
    padding: 1px 6px 1px 5px;
    border-radius: 999px;
    position: relative;
    padding-left: 14px;
}
.prop__badge--budgets .prop__badge-pulse {
    position: absolute;
    left: 5px; top: 50%; transform: translateY(-50%);
    width: 6px; height: 6px; border-radius: 50%;
    background: #ffd84d;
    box-shadow: 0 0 0 0 rgba(255, 216, 77, .65);
    animation: tpBudgetPulse 1.6s ease-in-out infinite;
}
@keyframes tpBudgetPulse {
    0%   { box-shadow: 0 0 0 0 rgba(255, 216, 77, .55); }
    70%  { box-shadow: 0 0 0 6px rgba(255, 216, 77, 0); }
    100% { box-shadow: 0 0 0 0 rgba(255, 216, 77, 0); }
}

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
.prop__sub a i[data-lucide],
.prop__sub a svg.lucide {
    width: 13px !important; height: 13px !important;
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

/* ----- Barra superior móvil + hamburguesa (ocultas en escritorio) ----- */
.admin-mobile-topbar { display: none; }
.admin-sidebar-backdrop { display: none; }
.admin-mobile-topbar {
    position: fixed; top: 0; left: 0; right: 0; height: 52px; z-index: 1100;
    align-items: center; gap: 12px; padding: 0 14px;
    background: color-mix(in srgb, var(--bg-surface, #141414) 94%, transparent);
    backdrop-filter: saturate(140%) blur(8px);
    -webkit-backdrop-filter: saturate(140%) blur(8px);
    border-bottom: 1px solid var(--border-base, #1f1f1f);
    font-family: var(--font-body, 'Inter', sans-serif);
}
.admin-mobile-burger {
    display: inline-flex; align-items: center; justify-content: center;
    width: 40px; height: 40px; flex: 0 0 auto;
    background: transparent; border: 1px solid var(--border-base, #1f1f1f);
    border-radius: 10px; color: var(--text-primary, #f5f5f5); cursor: pointer;
}
.admin-mobile-burger:active { background: var(--bg-subtle, #191919); }
.admin-mobile-burger i { width: 20px; height: 20px; }
.admin-mobile-topbar__brand {
    font-size: 13px; font-weight: 600; letter-spacing: .01em;
    color: var(--text-primary, #f5f5f5);
}

/* Responsive */
@media (max-width: 900px) {
    .admin-mobile-topbar { display: flex; }
    .admin-layout { flex-direction: column; }
    /* El sidebar pasa a panel deslizante (off-canvas), oculto por defecto */
    .admin-sidebar {
        position: fixed; top: 0; left: 0;
        width: min(300px, 86vw); height: 100vh; height: 100dvh;
        transform: translateX(-100%);
        transition: transform .25s ease;
        z-index: 1200;
        border-right: 1px solid var(--border-base);
        border-bottom: 0;
        box-shadow: 4px 0 30px rgba(0,0,0,.45);
    }
    .admin-sidebar.is-open { transform: translateX(0); }
    .admin-sidebar-backdrop {
        display: block; position: fixed; inset: 0; border: 0; padding: 0;
        background: rgba(0,0,0,.55);
        opacity: 0; pointer-events: none; transition: opacity .2s ease; z-index: 1150;
    }
    .admin-sidebar-backdrop.is-open { opacity: 1; pointer-events: auto; }
    .admin-sidebar__scroll { padding-bottom: 8px; }
    /* El contenido sube al top; deja hueco para la barra fija (52px) */
    .admin-main { padding: 16px; padding-top: 68px; }
    .nav-item.is-active::before, .prop.is-active::before { display: none; }
    body.admin-nav-open { overflow: hidden; }
}

/* =========================================================
   Tablas → tarjetas apiladas en móvil (clase reutilizable
   .tp-table-cards en cualquier <table> del admin)
   ========================================================= */
@media (max-width: 900px) {
    .tp-table-cards { display: block; width: 100%; min-width: 0 !important; }
    .tp-table-cards thead { display: none; }
    .tp-table-cards tbody { display: block; width: 100%; }
    .tp-table-cards tr {
        display: block;
        margin: 0 0 12px;
        background: var(--bg-surface, #141414);
        border: 1px solid var(--border-base, #1f1f1f) !important;
        border-radius: 12px;
        overflow: hidden;
    }
    .tp-table-cards td {
        display: block;
        width: auto !important;
        white-space: normal !important;
        text-align: left !important;
        padding: 10px 16px !important;
        border: 0 !important;
    }
    /* Línea sutil entre celdas dentro de la misma tarjeta */
    .tp-table-cards td + td { border-top: 1px solid var(--border-subtle, #1a1a1a) !important; }
    .tp-table-cards td:first-child { padding-top: 14px !important; }
    .tp-table-cards td:last-child  { padding-bottom: 14px !important; }
    /* Acciones: que los botones quepan y se alineen a la izquierda */
    .tp-table-cards td:last-child > * { justify-content: flex-start !important; }
    /* Fila vacía con colspan (“No hay propuestas”) se deja centrada */
    .tp-table-cards td[colspan] { text-align: center !important; }
}
</style>

<button class="admin-sidebar-backdrop" id="adminNavBackdrop" aria-hidden="true" tabindex="-1"></button>
<header class="admin-mobile-topbar">
    <button class="admin-mobile-burger" id="adminNavToggle" type="button" aria-label="Abrir navegación" aria-expanded="false" aria-controls="adminNavSidebar">
        <i data-lucide="menu"></i>
    </button>
    <span class="admin-mobile-topbar__brand">Tres Puntos · Proposals</span>
</header>

<aside class="admin-sidebar" id="adminNavSidebar" aria-label="Navegación admin">
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

        <!-- H6: Search filter -->
        <div class="sidebar-search">
            <i data-lucide="search" class="sidebar-search__icon"></i>
            <input type="text" id="tp-sidebar-search" placeholder="Buscar propuesta…" autocomplete="off" aria-label="Buscar propuesta">
            <kbd class="sidebar-search__kbd">⌘K</kbd>
        </div>

        <!-- Top-level nav: solo Dashboard + Proveedores (global directory) -->
        <a href="admin.php" class="nav-item <?= $active === 'dashboard' ? 'is-active' : '' ?>">
            <i data-lucide="layout-dashboard"></i>
            Dashboard
        </a>
        <a href="admin_providers.php" class="nav-item <?= ($active === 'proveedores' && !$activePropId) ? 'is-active' : '' ?>" title="<?= $globalBudgetsUnseen > 0 ? $globalBudgetsUnseen . ' presupuesto' . ($globalBudgetsUnseen === 1 ? '' : 's') . ' nuevo' . ($globalBudgetsUnseen === 1 ? '' : 's') . ' sin ver · ' : '' ?>Directorio de todos los proveedores con sus contactos y documentos">
            <i data-lucide="hard-hat"></i>
            <span>Proveedores</span>
            <?php if ($globalBudgetsUnseen > 0): ?>
                <span class="nav-item__badge nav-item__badge--budgets" title="<?= $globalBudgetsUnseen ?> presupuesto<?= $globalBudgetsUnseen === 1 ? '' : 's' ?> sin ver">
                    <span class="prop__badge-pulse"></span><?= $globalBudgetsUnseen ?>
                </span>
            <?php endif; ?>
            <?php if ($globalProvidersTotal > 0): ?>
                <span class="nav-item__badge nav-item__badge--purple"><?= $globalProvidersTotal ?></span>
            <?php endif; ?>
        </a>
        <?php
        // Badge contratos pendientes de firma
        $pendientesContratos = 0;
        try {
            $pendientesContratos = (int)$pdo->query("SELECT COUNT(*) FROM contratos WHERE estado IN ('enviado','visto','firmado_parcial')")->fetchColumn();
        } catch (\Throwable $_) { /* tabla aún no migrada */ }
        ?>
        <a href="admin_contratos.php" class="nav-item <?= $active === 'contratos' ? 'is-active' : '' ?>" title="Contratos con firma electrónica eIDAS (NDA, MSA, SOW, DPA…)">
            <i data-lucide="file-signature"></i>
            <span>Contratos</span>
            <?php if ($pendientesContratos > 0): ?>
                <span class="nav-item__badge nav-item__badge--purple"><?= $pendientesContratos ?></span>
            <?php endif; ?>
        </a>
        <a href="admin_plantillas.php" class="nav-item <?= $active === 'plantillas' ? 'is-active' : '' ?>" title="Plantillas de contrato reutilizables con variables">
            <i data-lucide="layout-template"></i>
            <span>Plantillas</span>
        </a>

        <?php if (!empty($activeProps)): ?>
            <div class="admin-sidebar__label">
                <span>Propuestas</span>
                <div class="admin-sidebar__label-actions">
                    <span class="admin-sidebar__label-count"><?= count($activeProps) ?></span>
                    <!-- H7: colapsar todas -->
                    <button type="button" class="label-action-btn" id="tp-collapse-all-btn" title="Colapsar todas las propuestas">
                        <i data-lucide="chevrons-up-down"></i>
                    </button>
                    <!-- H10: [+] nueva propuesta -->
                    <a href="admin.php?new=1" class="label-action-btn" title="Nueva propuesta">
                        <i data-lucide="plus"></i>
                    </a>
                </div>
            </div>
            <?php foreach ($activeProps as $p):
                $pid = (int)$p['id'];
                $isActivePid = $pid === $activePropId;
                $s = $statsByProp[$pid] ?? [];
                $commentsN = $s['comments'] ?? 0;
                $providersN = $s['providers'] ?? 0;
                $budgetsN = $s['budgets_unseen'] ?? 0;
            ?>
                <details class="prop <?= $isActivePid ? 'is-active' : '' ?>" <?= $isActivePid ? 'open' : '' ?>>
                    <summary>
                        <i data-lucide="chevron-right" class="prop__chevron"></i>
                        <span class="prop__name" title="<?= htmlspecialchars($p['client_name']) ?>">
                            <?= htmlspecialchars($p['client_name']) ?><?php if ($p['version']): ?><span class="prop__version"><?= htmlspecialchars($p['version']) ?></span><?php endif; ?>
                        </span>
                        <?php if ($commentsN > 0 || $providersN > 0 || $budgetsN > 0): ?>
                            <span class="prop__badges">
                                <?php if ($budgetsN > 0): ?>
                                    <span class="prop__badge prop__badge--budgets" title="<?= $budgetsN ?> presupuesto<?= $budgetsN === 1 ? '' : 's' ?> nuevo<?= $budgetsN === 1 ? '' : 's' ?> sin ver">
                                        <span class="prop__badge-pulse"></span>
                                        <i data-lucide="file-text"></i><?= $budgetsN ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($commentsN > 0): ?>
                                    <!-- H8: icono Lucide en lugar de dot -->
                                    <span class="prop__badge prop__badge--comments" title="<?= $commentsN ?> hilo<?= $commentsN === 1 ? '' : 's' ?> de comentarios sin resolver">
                                        <i data-lucide="message-circle"></i><?= $commentsN ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($providersN > 0): ?>
                                    <span class="prop__badge prop__badge--providers" title="<?= $providersN ?> proveedor<?= $providersN === 1 ? '' : 'es' ?> invitado<?= $providersN === 1 ? '' : 's' ?>">
                                        <i data-lucide="hard-hat"></i><?= $providersN ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </summary>
                    <div class="prop__sub">
                        <!-- GRUPO 1 · Gestión (acciones internas) -->
                        <div class="prop__sub-group">Gestión</div>
                        <a href="admin.php?edit_id=<?= $pid ?>" title="Editar contenido HTML de la propuesta">
                            <i data-lucide="edit-3"></i>
                            <span>Editar documento</span>
                        </a>
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

                        <!-- GRUPO 3 · Documento (H2: consolidado — 1 link + modo en query) -->
                        <div class="prop__sub-group">Documento</div>
                        <a href="/p/<?= urlencode($p['slug']) ?>?__admin_view=1" target="_blank" rel="noopener" title="Vista modo admin — puedes responder a comentarios como Tres Puntos">
                            <i data-lucide="file-text"></i>
                            <span>Abrir documento</span>
                            <i data-lucide="arrow-up-right" class="prop__sub-ext"></i>
                        </a>
                        <a href="/p/<?= urlencode($p['slug']) ?>" target="_blank" rel="noopener" title="Vista sin herramientas de admin — tal cual la ve el cliente">
                            <i data-lucide="eye"></i>
                            <span>Preview como cliente</span>
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
        <!-- H4: Toggle navegador interno, global para las 4 vistas -->
        <?php $__tpInt = ($_COOKIE['tp_internal'] ?? '') === '1'; ?>
        <button type="button"
                class="sidebar-internal-toggle <?= $__tpInt ? 'is-on' : '' ?>"
                id="tp-sidebar-internal-toggle"
                title="Cuando está ON, tus visitas a /p/ no cuentan como sesiones del cliente ni activan 'EN VIVO'">
            <span class="sidebar-internal-toggle__dot"></span>
            <span class="sidebar-internal-toggle__text">Navegador interno</span>
            <span style="font-weight:700; letter-spacing:0.04em;"><?= $__tpInt ? 'ON' : 'OFF' ?></span>
        </button>

        <a href="?logout=1" class="nav-item">
            <i data-lucide="log-out"></i>
            Cerrar sesión
        </a>
    </div>
</aside>

<script>
(function () {
    'use strict';

    // ─── Toggle archivadas con persistencia ──────────
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

    // ─── Persistir estado expanded/collapsed de cada propuesta ──────────
    const KEY_OPEN = 'tp_sidebar_open_props';
    let openList = [];
    try { openList = JSON.parse(sessionStorage.getItem(KEY_OPEN) || '[]'); } catch (e) {}

    const propDetails = Array.from(document.querySelectorAll('details.prop'));
    propDetails.forEach((d) => {
        const nameEl = d.querySelector('.prop__name');
        if (!nameEl) return;
        const name = (nameEl.childNodes[0] && nameEl.childNodes[0].textContent || nameEl.textContent).trim();
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

    // ─── H7: Colapsar todas ──────────
    const collapseBtn = document.getElementById('tp-collapse-all-btn');
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function(e) {
            e.preventDefault();
            propDetails.forEach(d => { if (d.open) d.removeAttribute('open'); });
            try { sessionStorage.setItem(KEY_OPEN, '[]'); } catch (e) {}
        });
    }

    // ─── H6: Search input + Cmd+K ──────────
    const searchInput = document.getElementById('tp-sidebar-search');
    if (searchInput) {
        function normalize(s) {
            return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        function applyFilter(q) {
            const needle = normalize(q.trim());
            let anyVisible = false;
            propDetails.forEach(d => {
                const nameEl = d.querySelector('.prop__name');
                const name = normalize(nameEl ? nameEl.textContent : '');
                const match = !needle || name.includes(needle);
                d.classList.toggle('is-filtered-out', !match);
                if (match) {
                    anyVisible = true;
                    if (needle && !d.hasAttribute('open')) d.setAttribute('open', '');
                }
            });
            // Ocultar el label "Propuestas · N" si no hay coincidencias
            document.querySelectorAll('.admin-sidebar__label').forEach(l => {
                l.classList.toggle('is-filtered-out', !anyVisible && !!needle);
            });
        }
        searchInput.addEventListener('input', e => applyFilter(e.target.value));
        searchInput.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                e.target.value = '';
                applyFilter('');
                e.target.blur();
            } else if (e.key === 'Enter') {
                // Primer match → ir a sus Comentarios
                const firstMatch = propDetails.find(d => !d.classList.contains('is-filtered-out'));
                if (firstMatch) {
                    const firstLink = firstMatch.querySelector('.prop__sub a');
                    if (firstLink) window.location.href = firstLink.href;
                }
            }
        });

        // Cmd+K / Ctrl+K global → foco al search
        document.addEventListener('keydown', e => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        });
    }

    // ─── H4: Toggle Navegador interno ──────────
    const internalBtn = document.getElementById('tp-sidebar-internal-toggle');
    if (internalBtn) {
        internalBtn.addEventListener('click', function() {
            const on = document.cookie.split(';').some(c => c.trim().startsWith('tp_internal=1'));
            if (on) {
                document.cookie = 'tp_internal=; Path=/; Max-Age=0; SameSite=Lax';
            } else {
                document.cookie = 'tp_internal=1; Path=/; Max-Age=31536000; SameSite=Lax';
            }
            // Reload para que el estado se refleje en toda la app (dashboard KPIs, analytics, etc.)
            location.reload();
        });
    }

    // ─── H9: refrescar sidebar al archivar desde dashboard (sin reload completo) ──────────
    // Exponemos una función que admin.php puede llamar tras archivar/reactivar una propuesta
    window.tpSidebarRefresh = function() {
        // Reload del sidebar con fetch a la misma URL y swap del <aside>
        fetch(window.location.href, { credentials: 'same-origin' })
            .then(r => r.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newAside = doc.querySelector('.admin-sidebar');
                const oldAside = document.querySelector('.admin-sidebar');
                if (newAside && oldAside) {
                    oldAside.replaceWith(newAside);
                    if (window.lucide) lucide.createIcons();
                }
            })
            .catch(() => location.reload());
    };

    /* ----- Drawer móvil: abrir/cerrar el sidebar off-canvas ----- */
    (function () {
        var toggle   = document.getElementById('adminNavToggle');
        var sidebar  = document.getElementById('adminNavSidebar');
        var backdrop = document.getElementById('adminNavBackdrop');
        if (!toggle || !sidebar) return;
        function open() {
            sidebar.classList.add('is-open');
            if (backdrop) backdrop.classList.add('is-open');
            document.body.classList.add('admin-nav-open');
            toggle.setAttribute('aria-expanded', 'true');
        }
        function close() {
            sidebar.classList.remove('is-open');
            if (backdrop) backdrop.classList.remove('is-open');
            document.body.classList.remove('admin-nav-open');
            toggle.setAttribute('aria-expanded', 'false');
        }
        toggle.addEventListener('click', function () {
            sidebar.classList.contains('is-open') ? close() : open();
        });
        if (backdrop) backdrop.addEventListener('click', close);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        // Al pulsar un enlace de navegación, cerrar el drawer
        sidebar.addEventListener('click', function (e) { if (e.target.closest('a')) close(); });
        // Si se agranda a escritorio, resetear estado
        window.addEventListener('resize', function () { if (window.innerWidth > 900) close(); });
    })();
})();
</script>

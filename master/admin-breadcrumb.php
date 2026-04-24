<?php
/**
 * master/admin-breadcrumb.php
 *
 * Renderiza el breadcrumb + nav prev/next entre propuestas.
 * Incluido en admin-main-header de las 4 vistas admin.
 *
 * Variables esperadas en el scope del include:
 *   $adminBreadcrumbItems  array of ['label' => ..., 'href' => nullable]
 *                          Ejemplo: [
 *                              ['label' => 'Dashboard', 'href' => 'admin.php'],
 *                              ['label' => 'H2B Hipotecas', 'href' => null],
 *                              ['label' => 'Comentarios', 'href' => null],
 *                          ]
 *   $adminBreadcrumbPropNav  opcional. Si hay propuesta_id activa y la vista
 *                            soporta filtrar por propuesta, render nav ←/→.
 *                            ['current_id' => int, 'view' => 'comentarios'|'analytics'|'proveedores']
 *
 * Depende de que $pdo esté en scope (lo está tras el sidebar).
 */

if (empty($adminBreadcrumbItems) || !is_array($adminBreadcrumbItems)) return;
?>

<div class="admin-breadcrumb" aria-label="Breadcrumb">
    <?php foreach ($adminBreadcrumbItems as $i => $item):
        $isLast = ($i === count($adminBreadcrumbItems) - 1);
    ?>
        <?php if (!empty($item['href']) && !$isLast): ?>
            <a href="<?= htmlspecialchars($item['href']) ?>"><?= htmlspecialchars($item['label']) ?></a>
        <?php else: ?>
            <span class="<?= $isLast ? 'admin-breadcrumb__current' : '' ?>"><?= htmlspecialchars($item['label']) ?></span>
        <?php endif; ?>
        <?php if (!$isLast): ?>
            <span class="admin-breadcrumb__sep">›</span>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php
    // H5 · nav prev/next entre propuestas (opcional)
    if (!empty($adminBreadcrumbPropNav) && is_array($adminBreadcrumbPropNav)
        && isset($adminBreadcrumbPropNav['current_id'], $adminBreadcrumbPropNav['view'])
        && isset($pdo) && ($pdo instanceof PDO)):

        $__bcCurrentId = (int)$adminBreadcrumbPropNav['current_id'];
        $__bcView = $adminBreadcrumbPropNav['view'];

        // Mapa vista → URL builder
        $__bcUrl = function($pid) use ($__bcView) {
            switch ($__bcView) {
                case 'comentarios': return 'admin_feedback.php?propuesta_id=' . $pid;
                case 'analytics': return 'admin_analytics.php?propuesta_id=' . $pid;
                case 'proveedores': return 'admin_providers.php?propuesta_id=' . $pid;
                default: return '#';
            }
        };

        try {
            $__bcAll = $pdo->query("SELECT id, client_name FROM propuestas WHERE status = 1 ORDER BY client_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $__bcAll = []; }

        if (count($__bcAll) > 1):
            // Buscar índice actual + determinar prev/next
            $__bcIdx = null;
            foreach ($__bcAll as $__i => $__row) {
                if ((int)$__row['id'] === $__bcCurrentId) { $__bcIdx = $__i; break; }
            }
            $__bcPrev = $__bcIdx !== null && $__bcIdx > 0 ? $__bcAll[$__bcIdx - 1] : null;
            $__bcNext = $__bcIdx !== null && $__bcIdx < count($__bcAll) - 1 ? $__bcAll[$__bcIdx + 1] : null;
    ?>
        <div class="admin-prop-nav" role="navigation" aria-label="Ir a otra propuesta">
            <?php if ($__bcPrev): ?>
                <a href="<?= $__bcUrl((int)$__bcPrev['id']) ?>" class="admin-prop-nav__btn" title="Anterior: <?= htmlspecialchars($__bcPrev['client_name']) ?>">
                    <i data-lucide="chevron-left"></i>
                </a>
            <?php else: ?>
                <span class="admin-prop-nav__btn" aria-disabled="true"><i data-lucide="chevron-left"></i></span>
            <?php endif; ?>

            <select onchange="if(this.value)location.href=this.value" class="admin-prop-nav__select" aria-label="Ir a propuesta">
                <?php foreach ($__bcAll as $__row):
                    $__rid = (int)$__row['id'];
                ?>
                    <option value="<?= $__bcUrl($__rid) ?>" <?= $__rid === $__bcCurrentId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($__row['client_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($__bcNext): ?>
                <a href="<?= $__bcUrl((int)$__bcNext['id']) ?>" class="admin-prop-nav__btn" title="Siguiente: <?= htmlspecialchars($__bcNext['client_name']) ?>">
                    <i data-lucide="chevron-right"></i>
                </a>
            <?php else: ?>
                <span class="admin-prop-nav__btn" aria-disabled="true"><i data-lucide="chevron-right"></i></span>
            <?php endif; ?>
        </div>
    <?php
        endif;
    endif;
    ?>
</div>

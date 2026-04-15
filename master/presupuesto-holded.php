<?php
/**
 * Render de un presupuesto Holded con la identidad Tres Puntos.
 *
 * Uso desde view.php:
 *   $holded_doc = json_decode($presupuesto_row['holded_json'], true);
 *   include __DIR__ . '/master/presupuesto-holded.php';
 *
 * Espera en scope:
 *   $holded_doc   (array)   — el JSON de Holded parseado
 *   $propuesta    (array)   — la propuesta (para el cliente display)
 *   $slug         (string)  — opcional, para el anchor
 *   $isPdfApproved(bool)    — si ya hay firma del presupuesto
 *
 * NO incluye <html>/<head>. Es un bloque a inyectar dentro del shell del documento.
 */

if (!isset($holded_doc) || !is_array($holded_doc)) return;

require_once __DIR__ . '/../api/holded_client.php';

$docNum   = $holded_doc['docNumber'] ?? '—';
$docDate  = holded_format_date($holded_doc['date'] ?? null);
$dueDate  = holded_format_date($holded_doc['dueDate'] ?? null);
$contact  = $holded_doc['contactName'] ?? '—';
$desc     = $holded_doc['desc'] ?? '';
$notes    = $holded_doc['notes'] ?? '';
$items    = is_array($holded_doc['products'] ?? null) ? $holded_doc['products'] : [];
$currency = $holded_doc['currency'] ?? 'eur';
$subtotal = (float)($holded_doc['subtotal'] ?? 0);
$taxTotal = (float)($holded_doc['tax'] ?? 0);
$total    = (float)($holded_doc['total'] ?? 0);
?>
<style>
/* =========================================================================
   tp-invoice — Presupuesto/Factura con identidad Tres Puntos
   ========================================================================= */
.tp-invoice {
    max-width: 1080px; margin: 0 auto; padding: 2rem 0 3rem;
    font-family: var(--font-body, 'Inter', sans-serif);
    color: var(--text-primary, #f5f5f5);
}
.tp-invoice__header {
    display: grid; grid-template-columns: 1fr auto; gap: 2rem; align-items: start;
    padding-bottom: 2rem; border-bottom: 1px solid var(--border-base, #1f1f1f);
}
.tp-invoice__brand { display: flex; align-items: center; gap: 1rem; }
.tp-invoice__logo {
    width: 56px; height: 56px; border-radius: 50%;
    background: var(--mint, #5dffbf);
    display: inline-flex; align-items: center; justify-content: center;
    color: var(--text-inverse, #000); font-family: var(--font-heading, 'Plus Jakarta Sans');
    font-weight: 800; font-size: 1.5rem;
}
.tp-invoice__issuer { font-size: .78rem; color: var(--text-secondary, #b3b3b3); line-height: 1.5; }
.tp-invoice__issuer strong { color: var(--text-primary, #f5f5f5); display: block; font-size: .9rem; margin-bottom: .25rem; }
.tp-invoice__meta { text-align: right; }
.tp-invoice__meta .tp-invoice__docnum {
    font-family: var(--font-heading, 'Plus Jakarta Sans'); font-weight: 800;
    color: var(--mint, #5dffbf); font-size: 1.5rem; line-height: 1;
    margin-bottom: .5rem;
}
.tp-invoice__meta .tp-invoice__dates { font-size: .78rem; color: var(--text-secondary, #b3b3b3); }
.tp-invoice__meta .tp-invoice__dates span { display: block; }

.tp-invoice__totalbox {
    margin-top: 1rem;
    background: rgba(93, 255, 191, .12);
    border: 1px solid rgba(93, 255, 191, .35);
    padding: 1rem 1.25rem; border-radius: var(--radius-md, 10px);
}
.tp-invoice__totalbox .label { font-size: .65rem; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted, #8a8a8a); }
.tp-invoice__totalbox .value {
    font-family: var(--font-heading, 'Plus Jakarta Sans'); font-weight: 800;
    font-size: 2rem; line-height: 1; color: var(--mint, #5dffbf);
}

.tp-invoice__parties {
    margin: 2.5rem 0; display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;
}
.tp-invoice__party { font-size: .85rem; line-height: 1.6; }
.tp-invoice__party .label { font-size: .65rem; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted, #8a8a8a); margin-bottom: .4rem; }
.tp-invoice__party strong { display: block; color: var(--text-primary, #f5f5f5); font-size: 1rem; margin-bottom: .25rem; }
.tp-invoice__party span { color: var(--text-secondary, #b3b3b3); display: block; }

.tp-invoice__desc {
    margin-bottom: 2rem; padding: 1rem 1.25rem;
    background: var(--bg-nav-hover, #1a1a1a); border-left: 3px solid var(--mint, #5dffbf);
    border-radius: var(--radius-sm, 6px); font-size: .95rem; color: var(--text-primary, #f5f5f5);
    font-weight: 500;
}

.tp-invoice__items {
    width: 100%; border-collapse: collapse; margin-bottom: 1.5rem;
}
.tp-invoice__items thead th {
    text-align: left; font-size: .7rem; text-transform: uppercase; letter-spacing: .08em;
    color: var(--text-secondary, #b3b3b3); font-weight: 700;
    padding: .7rem .9rem; border-bottom: 1px solid var(--border-base, #1f1f1f);
    background: var(--bg-surface, #141414);
}
.tp-invoice__items thead th.right { text-align: right; }
.tp-invoice__items thead th.center { text-align: center; }
.tp-invoice__items tbody td {
    padding: 1rem .9rem; border-bottom: 1px solid var(--border-base, #1f1f1f);
    font-size: .88rem; vertical-align: top;
}
.tp-invoice__items tbody td.right { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.tp-invoice__items tbody td.center { text-align: center; }
.tp-invoice__items .item-name { color: var(--text-primary, #f5f5f5); font-weight: 600; margin-bottom: .3rem; }
.tp-invoice__items .item-desc { color: var(--text-secondary, #b3b3b3); font-size: .8rem; line-height: 1.55; white-space: pre-wrap; }
.tp-invoice__items tbody tr:last-child td { border-bottom: 0; }

.tp-invoice__totals {
    margin-left: auto; min-width: 280px; margin-bottom: 2rem;
    background: var(--bg-surface, #141414);
    border: 1px solid var(--border-base, #1f1f1f); border-radius: var(--radius-md, 10px);
    padding: 1.25rem 1.5rem;
}
.tp-invoice__totals .row { display: flex; justify-content: space-between; padding: .35rem 0; font-size: .9rem; color: var(--text-secondary, #b3b3b3); font-variant-numeric: tabular-nums; }
.tp-invoice__totals .row.total {
    border-top: 1px solid var(--border-base, #1f1f1f); margin-top: .5rem; padding-top: .75rem;
    font-family: var(--font-heading, 'Plus Jakarta Sans'); font-size: 1.3rem;
    font-weight: 800; color: var(--mint, #5dffbf);
}

.tp-invoice__notes {
    padding: 1.1rem 1.25rem; background: var(--bg-nav-hover, #1a1a1a);
    border-radius: var(--radius-sm, 6px); font-size: .9rem;
    color: var(--text-primary, #f5f5f5); line-height: 1.6;
    white-space: pre-wrap;
    margin-bottom: 2rem;
}
.tp-invoice__notes strong { color: var(--text-secondary, #b3b3b3); display: block; margin-bottom: .5rem; font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 700; }

.tp-invoice__footer {
    margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-base, #1f1f1f);
    display: flex; justify-content: space-between; gap: 2rem; flex-wrap: wrap;
    font-size: .82rem; color: var(--text-secondary, #b3b3b3);
}
.tp-invoice__footer .iban { font-family: var(--font-mono, monospace); color: var(--text-primary, #f5f5f5); font-weight: 500; }

/* Modo claro */
[data-theme="light"] .tp-invoice__totalbox { background: rgba(16, 163, 127, .08); border-color: rgba(16, 163, 127, .3); }
[data-theme="light"] .tp-invoice__totalbox .value { color: #0e7a5f; }
[data-theme="light"] .tp-invoice__desc { border-left-color: #0e7a5f; }
[data-theme="light"] .tp-invoice__items .item-desc { color: var(--text-secondary); }
[data-theme="light"] .tp-invoice__totals .row.total { color: #0e7a5f; }
[data-theme="light"] .tp-invoice__meta .tp-invoice__docnum { color: #0e7a5f; }

/* Responsive */
@media (max-width: 720px) {
    .tp-invoice__header { grid-template-columns: 1fr; }
    .tp-invoice__meta { text-align: left; }
    .tp-invoice__parties { grid-template-columns: 1fr; gap: 1rem; }
    .tp-invoice__items thead { display: none; }
    .tp-invoice__items tbody td { display: block; padding: .4rem .2rem; border: 0; }
    .tp-invoice__items tbody tr { display: block; padding: 1rem 0; border-bottom: 1px solid var(--border-base); }
    .tp-invoice__items tbody td.right { text-align: left; }
    .tp-invoice__totals { min-width: 100%; }
}
</style>

<section class="tp-invoice" id="sec-presupuesto-holded" aria-label="Presupuesto">
    <header class="tp-invoice__header">
        <div class="tp-invoice__brand">
            <span class="tp-invoice__logo" aria-hidden="true">•••</span>
            <div class="tp-invoice__issuer">
                <strong>Tres Puntos Comunicación, S.L.</strong>
                B66018490<br>
                Calle Sant Josep 22<br>
                Santa Coloma de Gramenet (08921), Barcelona<br>
                93 011 77 33 · facturacion@trespuntoscomunicacion.es
            </div>
        </div>
        <div class="tp-invoice__meta">
            <div class="tp-invoice__docnum">Presupuesto <?php echo htmlspecialchars($docNum, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="tp-invoice__dates">
                <span>Emisión: <strong><?php echo htmlspecialchars($docDate, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                <?php if ($dueDate && $dueDate !== '—' && $dueDate !== $docDate): ?>
                <span>Vencimiento: <strong><?php echo htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                <?php endif; ?>
            </div>
            <div class="tp-invoice__totalbox">
                <div class="label">Total</div>
                <div class="value"><?php echo htmlspecialchars(holded_format_currency($total, $currency), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
    </header>

    <div class="tp-invoice__parties">
        <div class="tp-invoice__party">
            <div class="label">Cliente</div>
            <strong><?php echo htmlspecialchars($contact, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="tp-invoice__party">
            <div class="label">Referencia</div>
            <strong>Propuesta <?php echo htmlspecialchars($propuesta['version'] ?? 'v1.0', ENT_QUOTES, 'UTF-8'); ?></strong>
            <span>doc.trespuntos-lab.com/p/<?php echo htmlspecialchars($slug ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </div>

    <?php if ($desc): ?>
    <div class="tp-invoice__desc"><?php echo nl2br(htmlspecialchars($desc, ENT_QUOTES, 'UTF-8')); ?></div>
    <?php endif; ?>

    <table class="tp-invoice__items">
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="right">Precio</th>
                <th class="center">Uds.</th>
                <th class="right">Subtotal</th>
                <th class="center">IVA</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it):
                $lineSubtotal = (float)($it['price'] ?? 0) * (float)($it['units'] ?? 0);
                $lineTax = (float)($it['tax'] ?? 0);
                $lineTotal = $lineSubtotal + ($lineSubtotal * $lineTax / 100);
            ?>
            <tr>
                <td>
                    <div class="item-name"><?php echo htmlspecialchars($it['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if (!empty($it['desc'])): ?>
                    <div class="item-desc"><?php echo htmlspecialchars($it['desc'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </td>
                <td class="right"><?php echo htmlspecialchars(holded_format_currency($it['price'] ?? 0, $currency), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="center"><?php echo (float)($it['units'] ?? 0); ?></td>
                <td class="right"><?php echo htmlspecialchars(holded_format_currency($lineSubtotal, $currency), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="center"><?php echo number_format($lineTax, 0); ?>%</td>
                <td class="right"><?php echo htmlspecialchars(holded_format_currency($lineTotal, $currency), ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="tp-invoice__totals">
        <div class="row"><span>Base imponible</span><span><?php echo htmlspecialchars(holded_format_currency($subtotal, $currency), ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="row"><span>IVA</span><span><?php echo htmlspecialchars(holded_format_currency($taxTotal, $currency), ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="row total"><span>Total</span><span><?php echo htmlspecialchars(holded_format_currency($total, $currency), ENT_QUOTES, 'UTF-8'); ?></span></div>
    </div>

    <?php if ($notes): ?>
    <div class="tp-invoice__notes">
        <strong>Condiciones y notas</strong>
        <?php echo htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <footer class="tp-invoice__footer">
        <span>Forma de pago por transferencia</span>
        <span class="iban">CaixaBank · ES33 2100 0340 6202 0012 5089</span>
    </footer>
</section>

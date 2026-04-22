<?php
/**
 * master/provider-upload.php — Panel de upload del proveedor (PDF + importe + plazo + notas).
 *
 * Se incluye desde view.php cuando $isProviderMode === true.
 * Sube vía AJAX a /s/{token} (provider.php).
 * Lista presupuestos previos del mismo proveedor con botón de descarga.
 */
if (!isset($__provider) || !$__provider) return;
$providerApiUrl = '/s/' . $__provider['token'];
?>
<style>
.pv-panel {
    background: linear-gradient(135deg, rgba(var(--mint-rgb, 93,255,191), .06), rgba(var(--mint-rgb, 93,255,191), .01));
    border: 1px solid rgba(var(--mint-rgb, 93,255,191), .25);
    border-radius: var(--radius-lg, 14px);
    padding: 1.6rem 1.8rem;
    margin: 0 0 2.5rem;
}
.pv-panel h3 { margin: 0 0 .3rem; font-size: 1.15rem; display: flex; align-items: center; gap: .55rem; font-family: var(--font-heading, inherit); }
.pv-panel p.pv-intro { margin: 0 0 1rem; color: var(--text-secondary, #b3b3b3); font-size: .88rem; line-height: 1.55; }

.pv-form { display: grid; gap: .85rem; }
.pv-form .row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
.pv-form label { display: block; font-size: .72rem; color: var(--text-secondary, #b3b3b3); text-transform: uppercase; letter-spacing: .04em; font-weight: 600; margin-bottom: .3rem; }
.pv-form input[type=number], .pv-form textarea, .pv-form input[type=file] {
    width: 100%; box-sizing: border-box;
    background: var(--bg-subtle, #191919); color: var(--text-primary, #f5f5f5);
    border: 1px solid var(--border-base, #1f1f1f);
    padding: .65rem .75rem; border-radius: var(--radius-sm, 6px);
    font-family: inherit; font-size: .92rem;
}
.pv-form input[type=file] { padding: .5rem; cursor: pointer; }
.pv-form textarea { min-height: 70px; resize: vertical; line-height: 1.55; }
.pv-form input:focus, .pv-form textarea:focus { outline: none; border-color: var(--mint, #5dffbf); }
.pv-submit {
    background: var(--mint, #5dffbf); color: #000; border: none;
    padding: .75rem 1.4rem; border-radius: var(--radius-sm, 6px);
    font-weight: 700; cursor: pointer; font-family: inherit; font-size: .92rem;
    align-self: flex-start; justify-self: flex-start;
    display: inline-flex; align-items: center; gap: .5rem;
}
.pv-submit:hover { background: var(--mint-hover, #49e6a8); }
.pv-submit:disabled { opacity: .5; cursor: not-allowed; }

.pv-history { margin-top: 1.25rem; padding-top: 1rem; border-top: 1px dashed rgba(var(--mint-rgb, 93,255,191), .2); }
.pv-history h4 { margin: 0 0 .65rem; font-size: .75rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }
.pv-hist-empty { color: var(--text-muted, #8a8a8a); font-size: .82rem; }
.pv-hist-item {
    display: grid; grid-template-columns: 50px 1fr auto;
    gap: 1rem; padding: .6rem 0;
    border-bottom: 1px dashed var(--border-base, #1f1f1f);
    align-items: center; font-size: .85rem;
}
.pv-hist-item:last-child { border-bottom: 0; }
.pv-hist-v {
    background: var(--mint, #5dffbf); color: #000;
    padding: .15rem .5rem; border-radius: 999px;
    font-size: .68rem; font-weight: 700; text-align: center; width: fit-content;
}
.pv-hist-meta { color: var(--text-secondary, #b3b3b3); font-size: .76rem; margin-top: .2rem; }

.pv-greeting { font-size: .85rem; color: var(--text-secondary, #b3b3b3); margin-bottom: .75rem; display: flex; align-items: center; gap: .5rem; }
.pv-greeting .pv-chip { background: rgba(var(--mint-rgb, 93,255,191), .15); color: var(--mint, #5dffbf); padding: .15rem .55rem; border-radius: 999px; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
</style>

<section class="pv-panel">
    <div class="pv-greeting">
        <span class="pv-chip"><i data-lucide="user" style="width:12px;height:12px;vertical-align:-1px;"></i> Proveedor</span>
        <span>Hola <strong style="color: var(--text-primary);"><?=htmlspecialchars($__provider['nombre'])?></strong><?=$__provider['empresa'] ? ' · ' . htmlspecialchars($__provider['empresa']) : ''?></span>
    </div>

    <h3><i data-lucide="file-up" style="width:18px;height:18px;"></i> Sube tu presupuesto</h3>
    <p class="pv-intro">Revisa el documento completo abajo y sube tu propuesta en PDF con los campos estructurados para que podamos compararla objetivamente. Puedes subir tantas versiones como necesites. Si tienes dudas sobre el alcance, usa los botones <strong>Comentar</strong> junto a cada sección.</p>

    <form class="pv-form" id="pv-budget-form" enctype="multipart/form-data">
        <div>
            <label for="pv-file">Archivo PDF</label>
            <input type="file" id="pv-file" name="archivo" accept="application/pdf" required>
        </div>
        <div class="row">
            <div>
                <label for="pv-importe">Importe total (€)</label>
                <input type="number" id="pv-importe" name="importe" step="0.01" min="0" placeholder="12500.00">
            </div>
            <div>
                <label for="pv-plazo">Plazo (días)</label>
                <input type="number" id="pv-plazo" name="plazo" min="1" placeholder="45">
            </div>
        </div>
        <div>
            <label for="pv-notas">Notas</label>
            <textarea id="pv-notas" name="notas" placeholder="Condiciones, exclusiones, matices..."></textarea>
        </div>
        <button type="submit" class="pv-submit">
            <i data-lucide="upload-cloud" style="width:16px;height:16px;"></i>
            Enviar presupuesto
        </button>
    </form>

    <div class="pv-history">
        <h4>Tus presupuestos enviados</h4>
        <div id="pv-history-list">
            <div class="pv-hist-empty">Cargando…</div>
        </div>
    </div>
</section>

<script>
(function () {
    'use strict';
    const API = window.__providerApiUrl || '<?=$providerApiUrl?>';

    async function post(params, file) {
        if (file) {
            const fd = new FormData();
            fd.append('upload_budget', '1');
            for (const [k, v] of Object.entries(params || {})) fd.append(k, v);
            fd.append('archivo', file);
            return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json());
        }
        const body = new URLSearchParams({api_action: 'list_budgets'});
        return fetch(API, { method: 'POST', body, credentials: 'same-origin' }).then(r => r.json());
    }

    function esc(s){return (s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

    async function loadBudgets() {
        const r = await post();
        const list = document.getElementById('pv-history-list');
        if (!r || !r.success || !r.budgets || !r.budgets.length) {
            list.innerHTML = '<div class="pv-hist-empty">Todavía no has enviado ningún presupuesto.</div>';
            return;
        }
        list.innerHTML = r.budgets.map(b => {
            const fecha = new Date(b.uploaded_at.replace(' ','T') + 'Z').toLocaleString('es-ES', {day:'2-digit',month:'2-digit',year:'2-digit',hour:'2-digit',minute:'2-digit'});
            const imp = b.importe_total ? Number(b.importe_total).toLocaleString('es-ES',{minimumFractionDigits:2,maximumFractionDigits:2}) + '€' : '—';
            const plazo = b.plazo_dias ? b.plazo_dias + 'd' : '—';
            return `<div class="pv-hist-item">
                <div class="pv-hist-v">v${b.version_num}</div>
                <div>
                    <div style="color: var(--text-primary);">${esc(b.archivo_nombre)}</div>
                    <div class="pv-hist-meta">
                        <i data-lucide="euro" style="width:12px;height:12px;vertical-align:-1px;"></i> ${imp} ·
                        <i data-lucide="clock" style="width:12px;height:12px;vertical-align:-1px;"></i> ${plazo} ·
                        ${fecha}${b.notas ? ' · <em>' + esc(b.notas.slice(0,80)) + (b.notas.length > 80 ? '…' : '') + '</em>' : ''}
                    </div>
                </div>
                <div style="color: var(--text-muted, #8a8a8a); font-size:.72rem; display:inline-flex; align-items:center; gap:.3rem;"><i data-lucide="check" style="width:12px;height:12px;"></i> Enviado</div>
            </div>`;
        }).join('');
        if (window.lucide) lucide.createIcons();
    }

    document.getElementById('pv-budget-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const file = document.getElementById('pv-file').files[0];
        if (!file) return;
        const importe = document.getElementById('pv-importe').value;
        const plazo = document.getElementById('pv-plazo').value;
        const notas = document.getElementById('pv-notas').value;
        const btn = e.target.querySelector('.pv-submit');
        btn.disabled = true; btn.innerHTML = '<i data-lucide="loader-2" style="width:16px;height:16px;"></i> Subiendo…';
        const r = await post({importe, plazo, notas}, file);
        btn.disabled = false; btn.innerHTML = '<i data-lucide="upload-cloud" style="width:16px;height:16px;"></i> Enviar presupuesto';
        if (window.lucide) lucide.createIcons();
        if (!r || !r.success) { alert((r && r.error) || 'Error al subir'); return; }
        document.getElementById('pv-budget-form').reset();
        alert('Presupuesto v' + r.version + ' enviado. Tres Puntos ya tiene tu propuesta.');
        loadBudgets();
    });

    loadBudgets();
})();
</script>

<?php
/**
 * doc-feedback-provider.php — UX de comentarios idéntica al cliente pero para proveedor.
 *
 * Se incluye desde provider.php cuando el proveedor ya ha pasado el PIN.
 * Replica el sistema del cliente (drawer ancho + modal central + botones inline + FAB)
 * apuntando a los endpoints del proveedor (tabla `proveedor_mensajes`).
 *
 * Endpoints proxy-eados (en provider.php):
 *   POST api_action=list_messages  → { success, messages }
 *   POST api_action=add_message    → { success, id }
 *   POST api_action=edit_message   → { success }  (edita mensaje propio)
 *   POST api_action=delete_message → { success }
 */
?>
<style>
/* Normaliza tokens: si el shell ya define --tp-primary, lo mapeamos a --mint.
   Esto garantiza que todo el módulo funcione tanto en provider.php standalone
   como dentro de view.php (que solo define --tp-primary). */
:root {
    --mint: var(--tp-primary, #5dffbf);
    --mint-hover: #49e6a8;
    --mint-rgb: var(--tp-primary-rgb, 93, 255, 191);
    --bg-subtle: var(--bg-subtle, #191919);
    --bg-muted: var(--bg-muted, #1f1f1f);
    --border-strong: var(--border-strong, #2a2a2a);
}

/* Campos firma */
.tp-sign-fields { display: grid; gap: .65rem; margin: 1.25rem 0 .5rem; }
.tp-sign-fields label { display: grid; gap: .3rem; font-size: .8rem; color: var(--text-secondary); font-weight: 500; }
.tp-sign-fields input {
    background: var(--bg-subtle); border: 1px solid var(--border-base); color: var(--text-primary);
    padding: .6rem .8rem; border-radius: var(--radius-sm, 8px); font-family: inherit; font-size: .95rem;
}
.tp-sign-fields input:focus { outline: none; border-color: var(--mint, var(--tp-primary)); }

/* Botón "Comentar" inline junto a titulares */
.tp-sec-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    vertical-align: middle; margin-left: .9rem;
    background: var(--bg-subtle); color: var(--text-secondary);
    border: 1px solid var(--border-base); border-radius: var(--radius-full, 999px);
    font-size: .68rem; line-height: 1; font-weight: 600; padding: .35rem .7rem;
    cursor: pointer; opacity: .55; transition: opacity .2s ease, color .2s ease, border-color .2s ease;
    user-select: none; font-family: var(--font-body, inherit);
}
.tp-sec-btn:hover { opacity: 1; color: var(--mint); border-color: var(--mint); }
.tp-sec-btn .tp-sec-count { background: var(--mint); color: #000; border-radius: 999px; padding: 0 .45rem; min-width: 1.1rem; text-align: center; font-size: .65rem; font-weight: 700; }
.tp-sec-btn.has-comments { opacity: 1; border-color: var(--mint); }
.tp-sec-btn.state-pending { border-color: #ffcc33; color: #ffcc33; opacity: 1; }
.tp-sec-btn.state-pending .tp-sec-count { background: #ffcc33; color: #000; }
.tp-sec-btn.state-answered { border-color: var(--mint); color: var(--mint); opacity: 1; animation: tpSecPulse 2.2s ease-out infinite; }
.tp-sec-btn.state-answered .tp-sec-count::before { content: "✓ "; }
@keyframes tpSecPulse {
    0% { box-shadow: 0 0 0 0 rgba(var(--mint-rgb, 93,255,191), .45); }
    70% { box-shadow: 0 0 0 6px rgba(var(--mint-rgb, 93,255,191), 0); }
    100% { box-shadow: 0 0 0 0 rgba(var(--mint-rgb, 93,255,191), 0); }
}
h2:hover > .tp-sec-btn, h3:hover > .tp-sec-btn { opacity: 1; }

/* FAB */
.tp-fab {
    position: fixed; right: 1.25rem; bottom: 1.25rem; z-index: 500;
    background: var(--mint); color: #000;
    border: none; border-radius: 999px; padding: .9rem 1.15rem; font-weight: 700;
    display: inline-flex; align-items: center; gap: .5rem; cursor: pointer;
    box-shadow: 0 6px 20px rgba(0,0,0,.35); font-family: inherit; font-size: .88rem;
}
.tp-fab:hover { transform: translateY(-2px); }
.tp-fab .tp-fab-count { background: #000; color: var(--mint); padding: 0 .55rem; border-radius: 999px; font-size: .72rem; }
.tp-fab.has-answered { animation: tpFabPulse 2.4s ease-out infinite; }
@keyframes tpFabPulse {
    0% { box-shadow: 0 6px 20px rgba(0,0,0,.35), 0 0 0 0 rgba(var(--mint-rgb), .6); }
    70% { box-shadow: 0 6px 20px rgba(0,0,0,.35), 0 0 0 14px rgba(var(--mint-rgb), 0); }
    100% { box-shadow: 0 6px 20px rgba(0,0,0,.35), 0 0 0 0 rgba(var(--mint-rgb), 0); }
}

/* Drawer */
.tp-drawer-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.55); opacity: 0; pointer-events: none; transition: opacity .2s; z-index: 1000; }
.tp-drawer-backdrop.open { opacity: 1; pointer-events: auto; }
.tp-drawer {
    position: fixed; top: 0; right: 0; height: 100vh; width: min(820px, 92vw);
    background: var(--bg-surface); border-left: 1px solid var(--border-base);
    z-index: 1001; display: flex; flex-direction: column;
    transform: translateX(100%); transition: transform .25s; box-shadow: -4px 0 30px rgba(0,0,0,.4);
}
.tp-drawer.open { transform: translateX(0); }
.tp-drawer-head { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-base); display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.tp-drawer-head h3 { margin: 0; font-size: .95rem; font-family: var(--font-heading, inherit); color: var(--text-primary); }
.tp-drawer-sub { display: block; color: var(--text-muted); font-size: .75rem; margin-top: .2rem; font-weight: 400; }
.tp-drawer-close { background: transparent; border: none; color: var(--text-secondary); cursor: pointer; padding: .4rem; border-radius: 6px; }
.tp-drawer-close:hover { background: var(--bg-subtle); color: var(--text-primary); }
.tp-drawer-body { flex: 1; overflow-y: auto; padding: 1rem 1.25rem; display: flex; flex-direction: column; gap: .9rem; }
.tp-drawer-form { border-top: 1px solid var(--border-base); padding: 1rem 1.25rem; background: var(--bg-base); display: grid; gap: .6rem; }
.tp-drawer-form .row { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
.tp-drawer-form input, .tp-drawer-form textarea {
    background: var(--bg-subtle); border: 1px solid var(--border-base); color: var(--text-primary);
    padding: .55rem .7rem; border-radius: 6px; font-family: inherit; font-size: .88rem;
    width: 100%; box-sizing: border-box;
}
.tp-drawer-form textarea { min-height: 80px; resize: vertical; }
.tp-drawer-form input:focus, .tp-drawer-form textarea:focus { outline: none; border-color: var(--mint); }
.tp-identity-compact { font-size: .78rem; color: var(--text-secondary); display: flex; justify-content: space-between; align-items: center; }
.tp-identity-compact a { color: var(--mint); cursor: pointer; text-decoration: underline; }
.tp-drawer-submit { background: var(--mint); color: #000; border: none; padding: .65rem 1rem; border-radius: 6px; font-weight: 700; cursor: pointer; font-family: inherit; }
.tp-drawer-submit:disabled { opacity: .5; cursor: not-allowed; }
.tp-drawer-empty { color: var(--text-muted); font-size: .85rem; text-align: center; padding: 2rem 1rem; }

/* Comentarios */
.tp-comment { background: var(--bg-subtle); border: 1px solid var(--border-base); border-radius: 10px; padding: .75rem .9rem; font-size: .88rem; line-height: 1.5; }
.tp-comment + .tp-comment { margin-top: .55rem; }
.tp-comment.mine { border-color: rgba(var(--mint-rgb), .35); }
.tp-comment.reply { margin-left: 1.25rem; border-left: 2px solid var(--mint); padding-left: .8rem; background: transparent; border-top: 0; border-right: 0; border-bottom: 0; border-radius: 0; margin-top: .5rem; }
.tp-comment.staff { background: linear-gradient(180deg, rgba(var(--mint-rgb), .07), rgba(var(--mint-rgb), .02)); border-color: rgba(var(--mint-rgb), .3); }
.tp-comment-meta { font-size: .72rem; color: var(--text-muted); margin-bottom: .35rem; display: flex; justify-content: space-between; gap: .5rem; align-items: center; }
.tp-comment-author { color: var(--text-secondary); font-weight: 600; }
.tp-comment-text { color: var(--text-primary); white-space: pre-wrap; word-wrap: break-word; }
.tp-comment-actions { display: flex; gap: .4rem; margin-top: .5rem; }
.tp-comment-actions button { background: transparent; border: 1px solid var(--border-base); color: var(--text-muted); padding: .2rem .55rem; border-radius: 4px; font-size: .7rem; cursor: pointer; font-family: inherit; }
.tp-comment-actions button:hover { color: var(--text-primary); border-color: var(--border-strong, var(--text-muted)); }
.tp-staff-pill { display: inline-block; background: var(--mint); color: #000; padding: .08rem .45rem; border-radius: 999px; font-size: .62rem; font-weight: 700; letter-spacing: .04em; margin-left: .4rem; text-transform: uppercase; }

/* Admin reply block — solo visible en modo admin */
.tp-admin-reply {
    margin-top: .85rem; padding-top: .75rem;
    border-top: 1px dashed rgba(192,132,252,.35);
}
.tp-admin-reply-toggle {
    background: rgba(192,132,252,.12); color: #c084fc;
    border: 1px dashed rgba(192,132,252,.45);
    padding: .45rem .8rem; border-radius: 6px;
    font-family: inherit; font-size: .78rem; font-weight: 600;
    cursor: pointer; display: inline-flex; align-items: center; gap: .35rem;
    width: 100%; justify-content: center;
}
.tp-admin-reply-toggle:hover { background: rgba(192,132,252,.22); border-style: solid; }
.tp-admin-reply-form { display: flex; flex-direction: column; gap: .5rem; }
.tp-admin-reply-form textarea {
    background: var(--bg-subtle); color: var(--text-primary);
    border: 1px solid rgba(192,132,252,.3); border-radius: 6px;
    padding: .6rem .75rem; font-family: inherit; font-size: .88rem;
    min-height: 80px; resize: vertical; line-height: 1.55;
}
.tp-admin-reply-form textarea:focus { outline: none; border-color: #c084fc; }
.tp-admin-reply-actions { display: flex; gap: .5rem; justify-content: flex-end; }
.tp-admin-reply-cancel, .tp-admin-reply-send {
    padding: .4rem .85rem; border-radius: 6px; font-family: inherit;
    font-size: .78rem; font-weight: 600; cursor: pointer;
}
.tp-admin-reply-cancel { background: transparent; border: 1px solid var(--border-base); color: var(--text-muted); }
.tp-admin-reply-cancel:hover { color: var(--text-primary); border-color: var(--border-strong); }
.tp-admin-reply-send { background: #c084fc; color: #fff; border: none; }
.tp-admin-reply-send:hover { background: #a855f7; }
.tp-admin-reply-send:disabled { opacity: .5; cursor: not-allowed; }

/* Hilos agrupados */
.tp-thread { display: flex; flex-direction: column; gap: 0; border: 1px solid var(--border-base); border-radius: 10px; background: var(--bg-subtle); padding: .75rem .9rem; }
.tp-thread > .tp-comment { background: transparent; border: 0; padding: 0; }
.tp-thread > .tp-comment.reply { padding: .55rem 0 .2rem .85rem; margin-top: .5rem; }

.tp-section-flash { animation: tpSectionFlash 1.8s ease-out; }
@keyframes tpSectionFlash { 0%, 100% { background-color: transparent; } 25% { background-color: rgba(var(--mint-rgb), .18); } }

/* Modal central */
.tp-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(2px); opacity: 0; pointer-events: none; transition: opacity .2s; z-index: 1100; }
.tp-modal-backdrop.open { opacity: 1; pointer-events: auto; }
.tp-modal {
    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(.96);
    width: min(720px, 94vw); max-height: 85vh;
    background: var(--bg-surface); border: 1px solid var(--border-strong, var(--border-base));
    border-radius: 14px; z-index: 1101;
    display: flex; flex-direction: column;
    opacity: 0; pointer-events: none; transition: opacity .2s, transform .2s;
    box-shadow: 0 24px 60px rgba(0,0,0,.55); overflow: hidden;
}
.tp-modal.open { opacity: 1; pointer-events: auto; transform: translate(-50%, -50%) scale(1); }
.tp-modal-head { padding: 1.15rem 1.5rem; border-bottom: 1px solid var(--border-base); display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; background: var(--bg-subtle); }
.tp-modal-eyebrow { display: inline-block; background: rgba(var(--mint-rgb), .15); color: var(--mint); padding: .15rem .55rem; border-radius: 999px; font-size: .65rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; margin-bottom: .4rem; }
.tp-modal-title { margin: 0; font-size: 1.15rem; font-weight: 700; font-family: var(--font-heading, inherit); color: var(--text-primary); line-height: 1.3; word-wrap: break-word; }
.tp-modal-close { background: transparent; border: none; color: var(--text-secondary); cursor: pointer; padding: .4rem; border-radius: 6px; }
.tp-modal-close:hover { background: var(--bg-muted); color: var(--text-primary); }
.tp-modal-body { flex: 1; overflow-y: auto; padding: 1.25rem 1.5rem 0; display: flex; flex-direction: column; gap: .9rem; }
.tp-modal-body .tp-comment-text { font-size: .95rem; line-height: 1.65; }
.tp-modal-form { border-top: 1px solid var(--border-base); padding: 1rem 1.5rem 1.25rem; background: var(--bg-base); display: grid; gap: .6rem; }
.tp-modal-form .row { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
.tp-modal-form input, .tp-modal-form textarea {
    background: var(--bg-subtle); border: 1px solid var(--border-base); color: var(--text-primary);
    padding: .6rem .8rem; border-radius: 6px; font-family: inherit; font-size: .92rem;
    width: 100%; box-sizing: border-box;
}
.tp-modal-form textarea { min-height: 100px; resize: vertical; line-height: 1.55; }
.tp-modal-form input:focus, .tp-modal-form textarea:focus { outline: none; border-color: var(--mint); }
.tp-modal-submit { background: var(--mint); color: #000; border: none; padding: .7rem 1rem; border-radius: 6px; font-weight: 700; cursor: pointer; font-family: inherit; font-size: .9rem; }
.tp-modal-submit:disabled { opacity: .5; cursor: not-allowed; }
.tp-modal-hint { font-size: .72rem; color: var(--text-muted); display: flex; justify-content: space-between; }
.tp-modal-hint kbd { background: var(--bg-muted); border: 1px solid var(--border-base); padding: 0 .3rem; border-radius: 3px; font-size: .7rem; font-family: inherit; }

@media (max-width: 720px) {
    .tp-modal { top: 0; left: 0; transform: none; width: 100vw; height: 100vh; max-height: 100vh; border-radius: 0; border: 0; }
    .tp-modal.open { transform: none; }
    .tp-drawer-form .row, .tp-modal-form .row { grid-template-columns: 1fr; }
    .tp-fab { right: .75rem; bottom: .75rem; padding: .75rem .9rem; font-size: .82rem; }
    .tp-sec-btn { display: inline-flex; opacity: 1; font-size: .65rem; padding: .3rem .55rem; }
    .tp-sec-btn span:not(.tp-sec-count) { display: none; }
}
</style>

<!-- Modal central -->
<div class="tp-modal-backdrop" id="tp-pv-modal-backdrop" hidden></div>
<aside class="tp-modal" id="tp-pv-modal" role="dialog" hidden>
    <div class="tp-modal-head">
        <div>
            <span class="tp-modal-eyebrow" id="tp-pv-modal-eyebrow">Comentar sección</span>
            <h2 class="tp-modal-title" id="tp-pv-modal-title">Sección</h2>
        </div>
        <button class="tp-modal-close" id="tp-pv-modal-close" type="button"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="tp-modal-body" id="tp-pv-modal-body"><div class="tp-drawer-empty">Cargando…</div></div>
    <form class="tp-modal-form" id="tp-pv-modal-form" autocomplete="on">
        <div class="tp-identity-compact" id="tp-pv-modal-identity-compact" hidden>
            <span>Firmas como <strong id="tp-pv-modal-identity-name">—</strong></span>
            <a id="tp-pv-modal-identity-change">cambiar</a>
        </div>
        <div class="row" id="tp-pv-modal-identity-fields">
            <input type="text" id="tp-pv-modal-nombre" placeholder="Nombre" required>
            <input type="text" id="tp-pv-modal-apellidos" placeholder="Apellidos (opcional)">
        </div>
        <input type="email" id="tp-pv-modal-email" placeholder="Email — para avisarte de respuestas" required>
        <textarea id="tp-pv-modal-texto" placeholder="Escribe tu comentario sobre esta sección…" required></textarea>
        <div class="tp-modal-hint"><span>✨ <kbd>Ctrl</kbd>+<kbd>Enter</kbd> envía · <kbd>Esc</kbd> cierra</span></div>
        <button type="submit" class="tp-modal-submit" id="tp-pv-modal-submit">Enviar comentario</button>
    </form>
</aside>

<!-- FAB + Drawer -->
<button class="tp-fab" type="button" id="tp-pv-fab" title="Comentarios del documento">
    <i data-lucide="message-square-text" style="width:18px;height:18px;"></i>
    <span>Comentarios</span>
    <span class="tp-fab-count" id="tp-pv-fab-count" style="display:none">0</span>
</button>

<div class="tp-drawer-backdrop" id="tp-pv-drawer-backdrop" hidden></div>
<aside class="tp-drawer" id="tp-pv-drawer" role="dialog" hidden>
    <div class="tp-drawer-head">
        <div>
            <h3 id="tp-pv-drawer-title">Comentarios</h3>
            <span class="tp-drawer-sub" id="tp-pv-drawer-sub">Todas las secciones</span>
        </div>
        <button class="tp-drawer-close" id="tp-pv-drawer-close"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
    </div>
    <div class="tp-drawer-body" id="tp-pv-drawer-body"><div class="tp-drawer-empty">Cargando…</div></div>
    <form class="tp-drawer-form" id="tp-pv-drawer-form" autocomplete="on">
        <div class="tp-identity-compact" id="tp-pv-identity-compact" hidden>
            <span>Firmas como <strong id="tp-pv-identity-name">—</strong></span>
            <a id="tp-pv-identity-change">cambiar</a>
        </div>
        <div class="row" id="tp-pv-identity-fields">
            <input type="text" id="tp-pv-drawer-nombre" placeholder="Nombre" required>
            <input type="text" id="tp-pv-drawer-apellidos" placeholder="Apellidos (opcional)">
        </div>
        <input type="email" id="tp-pv-drawer-email" placeholder="Email — para avisarte de respuestas" required>
        <textarea id="tp-pv-drawer-texto" placeholder="Escribe tu comentario sobre esta sección…" required></textarea>
        <button type="submit" class="tp-drawer-submit" id="tp-pv-drawer-submit">Enviar comentario</button>
    </form>
</aside>

<?php
// Identidad ya capturada en el login del proveedor. La inyectamos como
// TP_PV_INITIAL_SIGNER para que el form arranque sin pedir nada.
$__pvInitialSigner = null;
if (!empty($__provider['email'])) {
    $__pvParts = explode(' ', trim($__provider['nombre'] ?? ''), 2);
    $__pvInitialSigner = [
        'nombre'    => $__pvParts[0] ?? '',
        'apellidos' => $__pvParts[1] ?? '',
        'email'     => $__provider['email'],
    ];
}
?>
<script>
window.TP_PV_INITIAL_SIGNER = <?= json_encode($__pvInitialSigner, JSON_UNESCAPED_UNICODE) ?>;
(function () {
    'use strict';
    const SIGNER_KEY = 'tp_pv_signer';
    const state = {
        comments: [],
        currentAnchor: null,
        currentTitle: 'Todas las secciones',
        signer: loadSigner(),
    };
    function loadSigner() {
        if (window.TP_PV_INITIAL_SIGNER && window.TP_PV_INITIAL_SIGNER.email) {
            try { localStorage.setItem(SIGNER_KEY, JSON.stringify(window.TP_PV_INITIAL_SIGNER)); } catch(_) {}
            return window.TP_PV_INITIAL_SIGNER;
        }
        try { return JSON.parse(localStorage.getItem(SIGNER_KEY) || 'null'); } catch(_) { return null; }
    }
    function saveSigner(s) { try { localStorage.setItem(SIGNER_KEY, JSON.stringify(s)); } catch(_) {} state.signer = s; refreshIdentityUI(); }

    const API_URL = window.__providerApiUrl || (window.location.pathname + window.location.search);

    function apiPost(action, params) {
        const body = new URLSearchParams();
        body.append('api_action', action);
        Object.keys(params || {}).forEach(k => body.append(k, params[k]));
        return fetch(API_URL, {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body,
            credentials: 'same-origin',
        }).then(r => r.json()).catch(() => ({success: false, error: 'Red'}));
    }

    // --- Inject section buttons ---
    const EXCLUDE_SEL = '.tp-card, .tp-callout, .tp-timeline, .tp-comparison, .tp-sitemap, .tp-stat, .tp-tag, .team-card, .team-grid, .cta-block, table, .modal-box, .tp-drawer, .tp-stack';
    function getSections() {
        const area = document.querySelector('#provider-doc') || document.getElementById('content-area') || document.body;
        return Array.from(area.querySelectorAll('h2[id], h3[id]')).filter(h => !h.closest(EXCLUDE_SEL));
    }

    let _injecting = false;
    function injectSectionButtons() {
        if (_injecting) return; _injecting = true;
        try {
            getSections().forEach(h => {
                if (h.querySelector(':scope > .tp-sec-btn')) return;
                const btn = document.createElement('button');
                btn.type = 'button'; btn.className = 'tp-sec-btn';
                btn.dataset.anchor = h.id;
                btn.dataset.title = (h.textContent || '').trim().slice(0, 200);
                btn.innerHTML = '<i data-lucide="message-square-text" style="width:13px;height:13px;"></i><span>Comentar</span><span class="tp-sec-count" style="display:none">0</span>';
                if (window.lucide) setTimeout(() => lucide.createIcons(), 0);
                btn.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); openModal(h.id, btn.dataset.title); });
                h.appendChild(btn);
            });
            updateCounts();
        } finally { _injecting = false; }
    }

    function updateCounts() {
        const perAnchor = {};
        const allRoots = state.comments.filter(c => !c.parent_id);
        const staffByRoot = {};
        state.comments.filter(c => c.parent_id && c.autor_tipo === 'staff').forEach(r => { staffByRoot[r.parent_id] = true; });
        allRoots.forEach(root => {
            const a = root.section_anchor || '__general__';
            const e = perAnchor[a] || (perAnchor[a] = {total:0, open:0, answered:0, resolved:0});
            e.total++;
            if (Number(root.resuelto) === 1) e.resolved++;
            else if (staffByRoot[root.id]) e.answered++;
            else e.open++;
        });
        document.querySelectorAll('.tp-sec-btn').forEach(b => {
            const e = perAnchor[b.dataset.anchor];
            const span = b.querySelector('.tp-sec-count');
            b.classList.remove('has-comments', 'state-pending', 'state-answered', 'state-resolved');
            if (!e || e.total === 0) { span.style.display = 'none'; return; }
            b.classList.add('has-comments');
            span.style.display = 'inline-block'; span.textContent = e.total;
            if (e.answered > 0) { b.classList.add('state-answered'); b.title = 'Tres Puntos ya ha respondido'; }
            else if (e.open > 0) { b.classList.add('state-pending'); b.title = 'Pendiente de respuesta'; }
            else { b.classList.add('state-resolved'); b.title = 'Todo cerrado'; }
        });
        const pending = allRoots.filter(r => Number(r.resuelto) !== 1).length;
        const answered = allRoots.filter(r => Number(r.resuelto) !== 1 && staffByRoot[r.id]).length;
        const fab = document.getElementById('tp-pv-fab');
        const fabCount = document.getElementById('tp-pv-fab-count');
        fab.classList.remove('has-answered');
        if (pending > 0) {
            fabCount.style.display = 'inline-block'; fabCount.textContent = pending;
            if (answered > 0) fab.classList.add('has-answered');
        } else { fabCount.style.display = 'none'; }
    }

    // --- Drawer ---
    function openDrawer(anchor, title) {
        state.currentAnchor = anchor || null;
        state.currentTitle = title || 'Todas las secciones';
        document.getElementById('tp-pv-drawer-title').textContent = anchor ? 'Comentarios de la sección' : 'Comentarios del documento';
        document.getElementById('tp-pv-drawer-sub').textContent = state.currentTitle;
        document.getElementById('tp-pv-drawer').hidden = false;
        document.getElementById('tp-pv-drawer-backdrop').hidden = false;
        requestAnimationFrame(() => {
            document.getElementById('tp-pv-drawer').classList.add('open');
            document.getElementById('tp-pv-drawer-backdrop').classList.add('open');
        });
        renderDrawer(); refreshIdentityUI();
        setTimeout(() => document.getElementById('tp-pv-drawer-texto').focus(), 200);
    }
    function closeDrawer() {
        document.getElementById('tp-pv-drawer').classList.remove('open');
        document.getElementById('tp-pv-drawer-backdrop').classList.remove('open');
        setTimeout(() => { document.getElementById('tp-pv-drawer').hidden = true; document.getElementById('tp-pv-drawer-backdrop').hidden = true; }, 250);
    }

    // --- Modal ---
    function openModal(anchor, title) {
        if (!anchor) return;
        state.currentAnchor = anchor;
        state.currentTitle = title || anchor;
        document.getElementById('tp-pv-modal-title').textContent = title || 'Sección';
        const existing = state.comments.filter(c => c.section_anchor === anchor && !c.parent_id);
        document.getElementById('tp-pv-modal-eyebrow').textContent = existing.length
            ? existing.length + ' hilo' + (existing.length === 1 ? '' : 's') + ' en esta sección' : 'Comentar sección';
        document.getElementById('tp-pv-modal').hidden = false;
        document.getElementById('tp-pv-modal-backdrop').hidden = false;
        requestAnimationFrame(() => {
            document.getElementById('tp-pv-modal').classList.add('open');
            document.getElementById('tp-pv-modal-backdrop').classList.add('open');
        });
        renderModal(); refreshModalIdentityUI();
        setTimeout(() => document.getElementById('tp-pv-modal-texto').focus(), 200);
    }
    function closeModal() {
        document.getElementById('tp-pv-modal').classList.remove('open');
        document.getElementById('tp-pv-modal-backdrop').classList.remove('open');
        setTimeout(() => { document.getElementById('tp-pv-modal').hidden = true; document.getElementById('tp-pv-modal-backdrop').hidden = true; }, 220);
    }

    // --- Helpers render ---
    const esc = s => (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const same = (a,b) => (a||'').trim().toLowerCase() === (b||'').trim().toLowerCase();
    const fmt = d => { if (!d) return ''; const dt = new Date(d.replace(' ','T')+'Z'); return isNaN(dt)?d:dt.toLocaleString('es-ES', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}); };

    function renderOne(c, isReply) {
        const staff = c.autor_tipo === 'staff';
        const mine = !staff && state.signer && state.signer.nombre && same(state.signer.nombre, c.autor_nombre);
        const cls = 'tp-comment' + (isReply?' reply':'') + (staff?' staff':'') + (mine?' mine':'');
        const autor = staff ? 'Tres Puntos' : esc(c.autor_nombre || 'Proveedor');
        const pill = staff ? '<span class="tp-staff-pill">Equipo</span>' : '';
        const actions = mine ? `<div class="tp-comment-actions"><button type="button" class="tp-btn-delete" data-id="${c.id}">Eliminar</button></div>` : '';
        return `<article class="${cls}" data-id="${c.id}" data-anchor="${esc(c.section_anchor||'')}" data-title="${esc(c.section_title||'')}">
            <div class="tp-comment-meta"><span><span class="tp-comment-author">${autor}</span>${pill}</span><span>${esc(fmt(c.created_at))}</span></div>
            <div class="tp-comment-text">${esc(c.texto)}</div>${actions}</article>`;
    }

    function renderThread(root, repliesByRoot) {
        const replies = (repliesByRoot[root.id] || []).sort((a,b) => a.created_at > b.created_at ? 1 : -1);
        const adminReplyBlock = window.__isAdminViewing ? `
            <div class="tp-admin-reply" data-root="${root.id}">
                <button type="button" class="tp-admin-reply-toggle" data-root="${root.id}">
                    <i data-lucide="reply" style="width:14px;height:14px;vertical-align:-2px;"></i>
                    Responder como Tres Puntos
                </button>
                <div class="tp-admin-reply-form" hidden>
                    <textarea placeholder="Escribe la respuesta…" maxlength="4000"></textarea>
                    <div class="tp-admin-reply-actions">
                        <button type="button" class="tp-admin-reply-cancel">Cancelar</button>
                        <button type="button" class="tp-admin-reply-send" data-root="${root.id}">Enviar respuesta</button>
                    </div>
                </div>
            </div>` : '';
        return `<section class="tp-thread" data-root="${root.id}">
            ${renderOne(root, false)}${replies.map(r => renderOne(r, true)).join('')}
            ${adminReplyBlock}</section>`;
    }

    function wireAdminReply(scope) {
        if (!window.__isAdminViewing) return;
        document.querySelectorAll('.tp-admin-reply-toggle').forEach(btn => {
            btn.onclick = () => {
                const wrap = btn.closest('.tp-admin-reply');
                wrap.querySelector('.tp-admin-reply-form').hidden = false;
                btn.hidden = true;
                wrap.querySelector('textarea').focus();
            };
        });
        document.querySelectorAll('.tp-admin-reply-cancel').forEach(btn => {
            btn.onclick = () => {
                const wrap = btn.closest('.tp-admin-reply');
                wrap.querySelector('.tp-admin-reply-form').hidden = true;
                wrap.querySelector('.tp-admin-reply-toggle').hidden = false;
            };
        });
        document.querySelectorAll('.tp-admin-reply-send').forEach(btn => {
            btn.onclick = async () => {
                const wrap = btn.closest('.tp-admin-reply');
                const ta = wrap.querySelector('textarea');
                const texto = ta.value.trim();
                if (!texto) return;
                btn.disabled = true; btn.textContent = 'Enviando…';
                const r = await apiPost('staff_reply', {parent_id: btn.dataset.root, texto});
                btn.disabled = false; btn.textContent = 'Enviar respuesta';
                if (!r.success) { alert(r.error || 'Error'); return; }
                await refresh();
            };
        });
    }

    function renderDrawer() {
        const body = document.getElementById('tp-pv-drawer-body');
        const roots = state.comments.filter(c => !c.parent_id);
        const list = state.currentAnchor ? roots.filter(c => c.section_anchor === state.currentAnchor) : roots;
        if (!list.length) { body.innerHTML = '<div class="tp-drawer-empty">Sin comentarios todavía. Empieza tú.</div>'; return; }
        const repliesByRoot = {};
        state.comments.filter(c => c.parent_id).forEach(r => (repliesByRoot[r.parent_id] = repliesByRoot[r.parent_id] || []).push(r));
        body.innerHTML = list.slice().sort((a,b) => a.created_at > b.created_at ? 1 : -1).map(r => renderThread(r, repliesByRoot)).join('');
        body.querySelectorAll('.tp-btn-delete').forEach(b => b.addEventListener('click', onDelete));
        wireAdminReply('drawer');
        if (window.lucide) lucide.createIcons();
    }

    function renderModal() {
        const body = document.getElementById('tp-pv-modal-body');
        const anchor = state.currentAnchor;
        if (!anchor) { body.innerHTML = ''; return; }
        const roots = state.comments.filter(c => !c.parent_id && c.section_anchor === anchor);
        if (!roots.length) { body.innerHTML = '<div class="tp-drawer-empty" style="padding:1.5rem 0;">Sé el primero en comentar esta sección.</div>'; return; }
        const repliesByRoot = {};
        state.comments.filter(c => c.parent_id).forEach(r => (repliesByRoot[r.parent_id] = repliesByRoot[r.parent_id] || []).push(r));
        body.innerHTML = roots.sort((a,b) => a.created_at > b.created_at ? 1 : -1).map(r => renderThread(r, repliesByRoot)).join('');
        body.querySelectorAll('.tp-btn-delete').forEach(b => b.addEventListener('click', onDelete));
        wireAdminReply('modal');
        if (window.lucide) lucide.createIcons();
    }

    async function onDelete(e) {
        if (!confirm('¿Eliminar este comentario?')) return;
        const id = +e.target.dataset.id;
        const r = await apiPost('delete_message', {id});
        if (!r.success) { alert(r.error || 'Error'); return; }
        await refresh();
    }

    // Gestión de identidad: cuando ya tenemos nombre + email guardado, colapsamos a "Firmas como X · cambiar"
    // y quitamos required de los inputs ocultos (si no, el navegador bloquea submit).
    function applyIdentityState(scope) {
        const compact = document.getElementById('tp-pv-' + scope + '-identity-compact');
        const fields = document.getElementById('tp-pv-' + scope + '-identity-fields');
        const nameInput = document.getElementById('tp-pv-' + scope + '-nombre');
        const apellidosInput = document.getElementById('tp-pv-' + scope + '-apellidos');
        const emailInput = document.getElementById('tp-pv-' + scope + '-email');
        const hasIdentity = !!(state.signer && state.signer.nombre && state.signer.email);

        if (hasIdentity) {
            const nameLabel = state.signer.nombre + (state.signer.apellidos ? ' ' + state.signer.apellidos : '');
            document.getElementById('tp-pv-' + scope + '-identity-name').textContent = nameLabel;
            compact.hidden = false;
            fields.hidden = true;
            // Rellena los valores por si se envían pero quita el required de los ocultos para no bloquear submit
            nameInput.value = state.signer.nombre;
            apellidosInput.value = state.signer.apellidos || '';
            emailInput.value = state.signer.email;
            nameInput.required = false;
            emailInput.required = false;
            emailInput.hidden = true;  // también ocultamos el email una vez guardado
        } else {
            compact.hidden = true;
            fields.hidden = false;
            nameInput.required = true;
            emailInput.required = true;
            emailInput.hidden = false;
        }
    }
    function refreshIdentityUI() { applyIdentityState('drawer'); }
    function refreshModalIdentityUI() { applyIdentityState('modal'); }

    async function refresh() {
        const r = await apiPost('list_messages');
        if (r && r.success) { state.comments = r.messages || []; updateCounts(); renderDrawer();
            if (document.getElementById('tp-pv-modal').classList.contains('open')) renderModal();
        }
    }

    // --- Submit drawer ---
    async function submitDrawer(e) {
        e.preventDefault();
        const nombre = document.getElementById('tp-pv-drawer-nombre').value.trim();
        const apellidos = document.getElementById('tp-pv-drawer-apellidos').value.trim();
        const email = (document.getElementById('tp-pv-drawer-email').value || '').trim();
        const texto = document.getElementById('tp-pv-drawer-texto').value.trim();
        if (!nombre) { alert('Nombre obligatorio.'); return; }
        if (!email) { alert('Email obligatorio.'); document.getElementById('tp-pv-drawer-email').focus(); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { alert('Email no válido.'); return; }
        if (!texto) return;
        if (!state.currentAnchor) { alert('Selecciona una sección con el botón "Comentar" para comentar.'); return; }

        saveSigner({nombre, apellidos, email});
        const btn = document.getElementById('tp-pv-drawer-submit');
        btn.disabled = true; btn.textContent = 'Enviando…';
        const r = await apiPost('add_message', {anchor: state.currentAnchor, section_title: state.currentTitle, texto});
        btn.disabled = false; btn.textContent = 'Enviar comentario';
        if (!r.success) { alert(r.error || 'Error'); return; }
        document.getElementById('tp-pv-drawer-texto').value = '';
        await refresh();
    }

    async function submitModal(e) {
        e.preventDefault();
        const nombre = document.getElementById('tp-pv-modal-nombre').value.trim();
        const apellidos = document.getElementById('tp-pv-modal-apellidos').value.trim();
        const email = (document.getElementById('tp-pv-modal-email').value || '').trim();
        const texto = document.getElementById('tp-pv-modal-texto').value.trim();
        if (!nombre) { alert('Nombre obligatorio.'); return; }
        if (!email) { alert('Email obligatorio.'); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { alert('Email no válido.'); return; }
        if (!texto) return;
        if (!state.currentAnchor) return;

        saveSigner({nombre, apellidos, email});
        const btn = document.getElementById('tp-pv-modal-submit');
        btn.disabled = true; btn.textContent = 'Enviando…';
        const r = await apiPost('add_message', {anchor: state.currentAnchor, section_title: state.currentTitle, texto});
        btn.disabled = false; btn.textContent = 'Enviar comentario';
        if (!r.success) { alert(r.error || 'Error'); return; }
        document.getElementById('tp-pv-modal-texto').value = '';
        await refresh();
        renderModal();
    }

    // --- Init ---
    function init() {
        injectSectionButtons();
        refresh();

        document.getElementById('tp-pv-fab').addEventListener('click', () => openDrawer(null, 'Todas las secciones'));
        document.getElementById('tp-pv-drawer-close').addEventListener('click', closeDrawer);
        document.getElementById('tp-pv-drawer-backdrop').addEventListener('click', closeDrawer);
        document.getElementById('tp-pv-drawer-form').addEventListener('submit', submitDrawer);
        document.getElementById('tp-pv-identity-change').addEventListener('click', () => {
            document.getElementById('tp-pv-identity-compact').hidden = true;
            document.getElementById('tp-pv-identity-fields').hidden = false;
            const em = document.getElementById('tp-pv-drawer-email');
            em.hidden = false; em.required = true;
            document.getElementById('tp-pv-drawer-nombre').required = true;
        });

        document.getElementById('tp-pv-modal-close').addEventListener('click', closeModal);
        document.getElementById('tp-pv-modal-backdrop').addEventListener('click', closeModal);
        document.getElementById('tp-pv-modal-form').addEventListener('submit', submitModal);
        document.getElementById('tp-pv-modal-identity-change').addEventListener('click', () => {
            document.getElementById('tp-pv-modal-identity-compact').hidden = true;
            document.getElementById('tp-pv-modal-identity-fields').hidden = false;
            const em = document.getElementById('tp-pv-modal-email');
            em.hidden = false; em.required = true;
            document.getElementById('tp-pv-modal-nombre').required = true;
        });
        document.getElementById('tp-pv-modal-texto').addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); document.getElementById('tp-pv-modal-form').requestSubmit(); }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (document.getElementById('tp-pv-modal').classList.contains('open')) closeModal();
                else if (document.getElementById('tp-pv-drawer').classList.contains('open')) closeDrawer();
            }
        });

        [100, 300, 700, 1500, 3000].forEach(ms => setTimeout(injectSectionButtons, ms));
        window.addEventListener('load', () => setTimeout(injectSectionButtons, 200));
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>

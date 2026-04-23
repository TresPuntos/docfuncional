<?php
/**
 * doc-feedback.php — Feature "Comentarios por sección" + "Firma ligera"
 *
 * Se incluye desde view.php solo cuando el usuario ya pasó el PIN.
 * Inyecta en cliente un botón 💬 por cada H2[id]/H3[id] dentro de #content-area,
 * un drawer lateral con historial + formulario, y un badge con el total.
 *
 * Estado del servidor que consume:
 *   POST api_action=list_section_comments → { success, comments[] }
 *   POST api_action=add_section_comment   → { success, id, created_at }
 *
 * Guarda nombre/apellidos/email en localStorage('tp_signer') para no preguntar cada vez.
 */
?>
<style>
/* --- Campos de firma dentro de los modales --- */
.tp-sign-fields { display: grid; gap: .65rem; margin: 1.25rem 0 .5rem; }
.tp-sign-fields label { display: grid; gap: .3rem; font-size: .8rem; color: var(--text-secondary); font-weight: 500; }
.tp-sign-fields input {
    background: var(--bg-subtle); border: 1px solid var(--border-base); color: var(--text-primary);
    padding: .6rem .8rem; border-radius: var(--radius-sm, 8px); font-family: inherit; font-size: .95rem;
    transition: border-color .15s ease;
}
.tp-sign-fields input:focus { outline: none; border-color: var(--mint, var(--tp-primary)); }
.tp-sign-legal { color: var(--text-muted); font-size: .72rem; line-height: 1.4; display: block; margin-top: .4rem; }

/* --- Botón "Comentar" inline junto al título de sección --- */
.tp-sec-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    vertical-align: middle; margin-left: .9rem;
    background: var(--bg-subtle); color: var(--text-secondary);
    border: 1px solid var(--border-base); border-radius: var(--radius-full, 999px);
    font-size: .68rem; line-height: 1; font-weight: 600; padding: .35rem .7rem;
    cursor: pointer; opacity: .55; transition: opacity .2s ease, color .2s ease, border-color .2s ease;
    user-select: none; font-family: var(--font-body, inherit);
    text-transform: none; letter-spacing: 0;
    -webkit-font-smoothing: antialiased;
}
.tp-sec-btn:hover { opacity: 1; color: var(--mint, var(--tp-primary)); border-color: var(--mint, var(--tp-primary)); }
.tp-sec-btn > svg { flex-shrink: 0; }
.tp-sec-btn .tp-sec-count { background: var(--mint, var(--tp-primary)); color: var(--text-inverse, #000); border-radius: 999px; padding: 0 .45rem; min-width: 1.1rem; text-align: center; font-size: .65rem; font-weight: 700; }
.tp-sec-btn.has-comments { opacity: 1; border-color: var(--mint, var(--tp-primary)); }

/* Estado: pendiente (abierto sin respuesta) → color ámbar */
.tp-sec-btn.state-pending { border-color: #ffcc33; color: #ffcc33; opacity: 1; }
.tp-sec-btn.state-pending .tp-sec-count { background: #ffcc33; color: #000; }

/* Estado: respondido por equipo (espera cierre del cliente) → mint con pulso sutil */
.tp-sec-btn.state-answered { border-color: var(--mint, var(--tp-primary)); color: var(--mint, var(--tp-primary)); opacity: 1; box-shadow: 0 0 0 0 rgba(var(--mint-rgb, 93,255,191), .4); animation: tpSecPulse 2.2s ease-out infinite; }
.tp-sec-btn.state-answered .tp-sec-count::before { content: "✓ "; }
@keyframes tpSecPulse {
    0%   { box-shadow: 0 0 0 0 rgba(var(--mint-rgb, 93,255,191), .45); }
    70%  { box-shadow: 0 0 0 6px rgba(var(--mint-rgb, 93,255,191), 0); }
    100% { box-shadow: 0 0 0 0 rgba(var(--mint-rgb, 93,255,191), 0); }
}

/* Estado: todo cerrado → tick apagado */
.tp-sec-btn.state-resolved { border-color: var(--border-strong, var(--text-muted)); color: var(--text-muted); opacity: .85; }
.tp-sec-btn.state-resolved .tp-sec-count { background: var(--bg-muted); color: var(--text-muted); }
.tp-sec-btn.state-resolved .tp-sec-count::before { content: "✓ "; }
h2:hover > .tp-sec-btn, h3:hover > .tp-sec-btn { opacity: 1; }

@media (max-width: 720px) {
    .tp-sec-btn { display: inline-flex; margin-left: .5rem; opacity: 1; font-size: .65rem; padding: .3rem .55rem; }
    .tp-sec-btn span:not(.tp-sec-count) { display: none; }
}

/* --- FAB flotante global --- */
.tp-fab {
    position: fixed; right: 1.25rem; bottom: 1.25rem; z-index: 500;
    background: var(--mint, var(--tp-primary)); color: #000;
    border: none; border-radius: 999px; padding: .9rem 1.15rem; font-weight: 700;
    display: inline-flex; align-items: center; gap: .5rem; cursor: pointer;
    box-shadow: 0 6px 20px rgba(0,0,0,.35); font-family: inherit; font-size: .88rem;
}
.tp-fab:hover { transform: translateY(-2px); }
.tp-fab .tp-fab-count { background: #000; color: var(--mint, var(--tp-primary)); padding: 0 .55rem; border-radius: 999px; font-size: .72rem; }
.tp-fab.has-answered { animation: tpFabPulse 2.4s ease-out infinite; }
@keyframes tpFabPulse {
    0%   { box-shadow: 0 6px 20px rgba(0,0,0,.35), 0 0 0 0 rgba(var(--mint-rgb, 93,255,191), .6); }
    70%  { box-shadow: 0 6px 20px rgba(0,0,0,.35), 0 0 0 14px rgba(var(--mint-rgb, 93,255,191), 0); }
    100% { box-shadow: 0 6px 20px rgba(0,0,0,.35), 0 0 0 0 rgba(var(--mint-rgb, 93,255,191), 0); }
}

/* --- Drawer lateral --- */
.tp-drawer-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,.55); opacity: 0; pointer-events: none;
    transition: opacity .2s ease; z-index: 1000;
}
.tp-drawer-backdrop.open { opacity: 1; pointer-events: auto; }
.tp-drawer {
    position: fixed; top: 0; right: 0; height: 100vh; width: min(820px, 92vw);
    background: var(--bg-surface); border-left: 1px solid var(--border-base);
    z-index: 1001; display: flex; flex-direction: column;
    transform: translateX(100%); transition: transform .25s ease; box-shadow: -4px 0 30px rgba(0,0,0,.4);
}
.tp-drawer.open { transform: translateX(0); }
.tp-drawer-head {
    padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-base);
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
}
.tp-drawer-head h3 { margin: 0; font-size: .95rem; font-family: var(--font-heading, inherit); color: var(--text-primary); }
.tp-drawer-head .tp-drawer-sub { display: block; color: var(--text-muted); font-size: .75rem; margin-top: .2rem; font-weight: 400; }
.tp-drawer-close {
    background: transparent; border: none; color: var(--text-secondary); cursor: pointer;
    padding: .4rem; border-radius: var(--radius-sm, 6px);
}
.tp-drawer-close:hover { background: var(--bg-subtle); color: var(--text-primary); }

.tp-drawer-body { flex: 1; overflow-y: auto; padding: 1rem 1.25rem; display: flex; flex-direction: column; gap: .9rem; }
.tp-comment {
    background: var(--bg-subtle); border: 1px solid var(--border-base); border-radius: var(--radius-md, 10px);
    padding: .75rem .9rem; font-size: .88rem; line-height: 1.5;
    cursor: pointer; transition: border-color .15s ease, background .15s ease;
}
.tp-comment:hover { border-color: var(--mint, var(--tp-primary)); }
.tp-comment.mine { border-color: rgba(var(--mint-rgb, 93, 255, 191), .35); }
.tp-comment-meta { font-size: .72rem; color: var(--text-muted); margin-bottom: .35rem; display: flex; justify-content: space-between; gap: .5rem; align-items: center; }
.tp-comment-author { color: var(--text-secondary); font-weight: 600; }
.tp-comment-text { color: var(--text-primary); white-space: pre-wrap; word-wrap: break-word; }
.tp-comment.reply { margin-left: 1.25rem; border-left: 2px solid var(--mint, var(--tp-primary)); padding-left: .8rem; background: transparent; border-top: 0; border-right: 0; border-bottom: 0; border-radius: 0; }
.tp-comment.staff { background: linear-gradient(180deg, rgba(var(--mint-rgb, 93,255,191), .07), rgba(var(--mint-rgb, 93,255,191), .02)); border-color: rgba(var(--mint-rgb, 93,255,191), .3); }
.tp-staff-pill { display: inline-block; background: var(--mint, var(--tp-primary)); color: var(--text-inverse, #000); padding: .08rem .45rem; border-radius: 999px; font-size: .62rem; font-weight: 700; letter-spacing: .04em; margin-left: .4rem; text-transform: uppercase; }
.tp-thread-status { display: flex; align-items: center; gap: .5rem; margin-top: .55rem; padding-top: .5rem; border-top: 1px dashed var(--border-base); }
.tp-status-pill { padding: .15rem .55rem; border-radius: 999px; font-size: .7rem; font-weight: 600; }
.tp-status-pill.open { background: rgba(255, 200, 0, .15); color: #ffcc33; }
.tp-status-pill.closed { background: rgba(var(--mint-rgb, 93,255,191), .18); color: var(--mint, var(--tp-primary)); }
.tp-status-pill.waiting { background: var(--bg-muted); color: var(--text-muted); font-weight: 500; }
.tp-btn-resolve { background: transparent; border: 1px solid rgba(var(--mint-rgb, 93,255,191), .4); color: var(--mint, var(--tp-primary)); padding: .25rem .65rem; border-radius: 999px; font-size: .72rem; cursor: pointer; font-family: inherit; font-weight: 600; }
.tp-btn-resolve:hover { background: rgba(var(--mint-rgb, 93,255,191), .1); border-color: var(--mint, var(--tp-primary)); }
.tp-btn-reopen { background: transparent; border: 1px solid var(--border-strong, var(--text-muted)); color: var(--text-secondary); padding: .25rem .65rem; border-radius: 999px; font-size: .72rem; cursor: pointer; font-family: inherit; }
.tp-btn-reopen:hover { color: var(--text-primary); border-color: var(--text-primary); }

/* Admin reply block — solo visible cuando window.__isAdminViewing === true */
.tp-admin-reply { margin-top: .85rem; padding-top: .75rem; border-top: 1px dashed rgba(192,132,252,.35); }
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
.tp-admin-reply-cancel:hover { color: var(--text-primary); border-color: var(--border-strong, var(--text-muted)); }
.tp-admin-reply-send { background: #c084fc; color: #fff; border: none; }
.tp-admin-reply-send:hover { background: #a855f7; }
.tp-admin-reply-send:disabled { opacity: .5; cursor: not-allowed; }

.tp-thread { display: flex; flex-direction: column; gap: 0; border: 1px solid var(--border-base); border-radius: var(--radius-md, 10px); background: var(--bg-subtle); padding: .75rem .9rem; }
.tp-thread.resolved { opacity: .65; }
.tp-thread > .tp-comment { background: transparent; border: 0; padding: 0; }
.tp-thread > .tp-comment.reply { padding: .55rem 0 .2rem .85rem; margin-top: .5rem; }
.tp-comment-actions { display: flex; gap: .4rem; margin-top: .5rem; }
.tp-comment-actions button {
    background: transparent; border: 1px solid var(--border-base); color: var(--text-muted);
    padding: .2rem .55rem; border-radius: 4px; font-size: .7rem; cursor: pointer;
    font-family: inherit;
}
.tp-comment-actions button:hover { color: var(--text-primary); border-color: var(--border-strong, var(--text-muted)); }
.tp-comment-actions .tp-btn-delete:hover { color: #ff6b6b; border-color: #ff6b6b; }
.tp-comment-edit-area { display: grid; gap: .4rem; margin-top: .4rem; }
.tp-comment-edit-area textarea {
    background: var(--bg-base, #0e0e0e); color: var(--text-primary); border: 1px solid var(--border-base);
    border-radius: 4px; padding: .5rem; font-family: inherit; font-size: .85rem; min-height: 60px; resize: vertical;
}
/* Highlight de la sección cuando se navega desde un comentario */
.tp-section-flash {
    animation: tpSectionFlash 1.8s ease-out;
}
@keyframes tpSectionFlash {
    0%, 100% { background-color: transparent; }
    25%      { background-color: rgba(var(--mint-rgb, 93, 255, 191), .18); }
}

.tp-drawer-empty { color: var(--text-muted); font-size: .85rem; text-align: center; padding: 2rem 1rem; }

.tp-drawer-form { border-top: 1px solid var(--border-base); padding: 1rem 1.25rem; background: var(--bg-base); display: grid; gap: .6rem; }
.tp-drawer-form .row { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
.tp-drawer-form input, .tp-drawer-form textarea {
    background: var(--bg-subtle); border: 1px solid var(--border-base); color: var(--text-primary);
    padding: .55rem .7rem; border-radius: var(--radius-sm, 6px); font-family: inherit; font-size: .88rem;
    width: 100%; box-sizing: border-box;
}
.tp-drawer-form textarea { min-height: 80px; resize: vertical; }
.tp-drawer-form input:focus, .tp-drawer-form textarea:focus { outline: none; border-color: var(--mint, var(--tp-primary)); }
.tp-drawer-form .tp-identity-compact { font-size: .78rem; color: var(--text-secondary); display: flex; justify-content: space-between; align-items: center; }
.tp-drawer-form .tp-identity-compact a { color: var(--mint, var(--tp-primary)); cursor: pointer; text-decoration: underline; }
.tp-drawer-submit {
    background: var(--mint, var(--tp-primary)); color: #000; border: none; padding: .65rem 1rem;
    border-radius: var(--radius-sm, 6px); font-weight: 700; cursor: pointer; font-family: inherit;
}
.tp-drawer-submit:disabled { opacity: .5; cursor: not-allowed; }

@media (max-width: 600px) {
    .tp-drawer-form .row { grid-template-columns: 1fr; }
    .tp-fab { right: .75rem; bottom: .75rem; padding: .75rem .9rem; font-size: .82rem; }
}

/* ═══════════════════════════════════════════════════
   MODAL CENTRAL — enfoque en un hilo concreto
   ═══════════════════════════════════════════════════ */
.tp-modal-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(2px);
    opacity: 0; pointer-events: none; transition: opacity .2s ease; z-index: 1100;
}
.tp-modal-backdrop.open { opacity: 1; pointer-events: auto; }

.tp-modal {
    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(.96);
    width: min(720px, 94vw); max-height: 85vh;
    background: var(--bg-surface); border: 1px solid var(--border-strong, var(--border-base));
    border-radius: var(--radius-lg, 14px); z-index: 1101;
    display: flex; flex-direction: column;
    opacity: 0; pointer-events: none; transition: opacity .2s ease, transform .2s ease;
    box-shadow: 0 24px 60px rgba(0,0,0,.55);
    overflow: hidden;
}
.tp-modal.open { opacity: 1; pointer-events: auto; transform: translate(-50%, -50%) scale(1); }

.tp-modal-head {
    padding: 1.15rem 1.5rem; border-bottom: 1px solid var(--border-base);
    display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;
    background: var(--bg-subtle);
}
.tp-modal-head-text { flex: 1; min-width: 0; }
.tp-modal-eyebrow {
    display: inline-block; background: rgba(var(--mint-rgb, 93,255,191), .15);
    color: var(--mint, var(--tp-primary)); padding: .15rem .55rem;
    border-radius: 999px; font-size: .65rem; font-weight: 700; letter-spacing: .04em;
    text-transform: uppercase; margin-bottom: .4rem;
}
.tp-modal-title {
    margin: 0; font-size: 1.15rem; font-weight: 700;
    font-family: var(--font-heading, inherit); color: var(--text-primary);
    line-height: 1.3; word-wrap: break-word;
}
.tp-modal-close {
    background: transparent; border: none; color: var(--text-secondary);
    cursor: pointer; padding: .4rem; border-radius: var(--radius-sm, 6px);
    flex-shrink: 0;
}
.tp-modal-close:hover { background: var(--bg-muted); color: var(--text-primary); }

.tp-modal-body {
    flex: 1; overflow-y: auto; padding: 1.25rem 1.5rem 0;
    display: flex; flex-direction: column; gap: .9rem;
}
.tp-modal-body .tp-thread { border: 0; padding: 0; background: transparent; }
.tp-modal-body .tp-thread > .tp-comment { padding: .4rem 0; }
.tp-modal-body .tp-comment-text {
    font-size: .95rem; line-height: 1.65;  /* más aire de lectura */
}

.tp-modal-form {
    border-top: 1px solid var(--border-base); padding: 1rem 1.5rem 1.25rem;
    background: var(--bg-base); display: grid; gap: .6rem;
}
.tp-modal-form .row { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
.tp-modal-form input, .tp-modal-form textarea {
    background: var(--bg-subtle); border: 1px solid var(--border-base);
    color: var(--text-primary); padding: .6rem .8rem;
    border-radius: var(--radius-sm, 6px); font-family: inherit; font-size: .92rem;
    width: 100%; box-sizing: border-box;
}
.tp-modal-form textarea { min-height: 100px; resize: vertical; line-height: 1.55; }
.tp-modal-form input:focus, .tp-modal-form textarea:focus {
    outline: none; border-color: var(--mint, var(--tp-primary));
}
.tp-modal-form .tp-identity-compact {
    font-size: .78rem; color: var(--text-secondary);
    display: flex; justify-content: space-between; align-items: center;
}
.tp-modal-form .tp-identity-compact a { color: var(--mint, var(--tp-primary)); cursor: pointer; text-decoration: underline; }
.tp-modal-form .tp-modal-submit {
    background: var(--mint, var(--tp-primary)); color: #000;
    border: none; padding: .7rem 1rem;
    border-radius: var(--radius-sm, 6px); font-weight: 700; cursor: pointer;
    font-family: inherit; font-size: .9rem;
}
.tp-modal-form .tp-modal-submit:disabled { opacity: .5; cursor: not-allowed; }
.tp-modal-form .tp-modal-hint {
    font-size: .72rem; color: var(--text-muted);
    display: flex; justify-content: space-between; margin-top: .25rem;
}
.tp-modal-form .tp-modal-hint kbd {
    background: var(--bg-muted); border: 1px solid var(--border-base);
    padding: 0 .3rem; border-radius: 3px; font-size: .7rem; font-family: inherit;
}

@media (max-width: 720px) {
    .tp-modal {
        top: 0; left: 0; transform: none;
        width: 100vw; height: 100vh; max-height: 100vh;
        border-radius: 0; border: 0;
    }
    .tp-modal.open { transform: none; }
    .tp-modal-form .row { grid-template-columns: 1fr; }
}
</style>

<!-- Modal central (un hilo enfocado) -->
<div class="tp-modal-backdrop" id="tp-modal-backdrop" hidden></div>
<aside class="tp-modal" id="tp-modal" role="dialog" aria-modal="true" aria-labelledby="tp-modal-title" hidden>
    <div class="tp-modal-head">
        <div class="tp-modal-head-text">
            <span class="tp-modal-eyebrow" id="tp-modal-eyebrow">Comentar sección</span>
            <h2 class="tp-modal-title" id="tp-modal-title">Sección</h2>
        </div>
        <button class="tp-modal-close" id="tp-modal-close" type="button" aria-label="Cerrar">
            <i data-lucide="x" style="width:20px;height:20px;"></i>
        </button>
    </div>
    <div class="tp-modal-body" id="tp-modal-body">
        <div class="tp-drawer-empty">Cargando…</div>
    </div>
    <form class="tp-modal-form" id="tp-modal-form" autocomplete="on">
        <div class="tp-identity-compact" id="tp-modal-identity-compact" hidden>
            <span>Firmas como <strong id="tp-modal-identity-name">—</strong></span>
            <a id="tp-modal-identity-change">cambiar</a>
        </div>
        <div class="row" id="tp-modal-identity-fields">
            <input type="text" id="tp-modal-nombre" placeholder="Nombre" autocomplete="given-name" required>
            <input type="text" id="tp-modal-apellidos" placeholder="Apellidos" autocomplete="family-name" required>
        </div>
        <input type="email" id="tp-modal-email" placeholder="Email — para avisarte de respuestas" autocomplete="email" required>
        <textarea id="tp-modal-texto" placeholder="Escribe tu comentario sobre esta sección…" required></textarea>
        <div class="tp-modal-hint">
            <span>✨ <kbd>Ctrl</kbd>+<kbd>Enter</kbd> envía · <kbd>Esc</kbd> cierra</span>
        </div>
        <button type="submit" class="tp-modal-submit" id="tp-modal-submit">Enviar comentario</button>
    </form>
</aside>

<!-- FAB + Drawer -->
<button class="tp-fab" type="button" id="tp-fab" aria-label="Abrir comentarios del documento" title="Comentarios del documento">
    <i data-lucide="message-square-text" style="width:18px;height:18px;"></i>
    <span>Comentarios</span>
    <span class="tp-fab-count" id="tp-fab-count" style="display:none">0</span>
</button>

<div class="tp-drawer-backdrop" id="tp-drawer-backdrop" hidden></div>
<aside class="tp-drawer" id="tp-drawer" role="dialog" aria-labelledby="tp-drawer-title" hidden>
    <div class="tp-drawer-head">
        <div>
            <h3 id="tp-drawer-title">Comentarios</h3>
            <span class="tp-drawer-sub" id="tp-drawer-sub">Todas las secciones</span>
        </div>
        <button class="tp-drawer-close" id="tp-drawer-close" aria-label="Cerrar">
            <i data-lucide="x" style="width:18px;height:18px;"></i>
        </button>
    </div>
    <div class="tp-drawer-body" id="tp-drawer-body">
        <div class="tp-drawer-empty">Cargando…</div>
    </div>
    <form class="tp-drawer-form" id="tp-drawer-form" autocomplete="on">
        <div class="tp-identity-compact" id="tp-identity-compact" hidden>
            <span>Firmas como <strong id="tp-identity-name">—</strong></span>
            <a id="tp-identity-change">cambiar</a>
        </div>
        <div class="row" id="tp-identity-fields">
            <input type="text" id="tp-drawer-nombre" placeholder="Nombre" autocomplete="given-name" required>
            <input type="text" id="tp-drawer-apellidos" placeholder="Apellidos" autocomplete="family-name" required>
        </div>
        <input type="email" id="tp-drawer-email" placeholder="Email — para avisarte de respuestas" autocomplete="email" required style="background: var(--bg-subtle); border: 1px solid var(--border-base); color: var(--text-primary); padding: .55rem .7rem; border-radius: var(--radius-sm, 6px); font-family: inherit; font-size: .85rem; width: 100%; box-sizing: border-box;">
        <textarea id="tp-drawer-texto" placeholder="Escribe tu comentario sobre esta sección…" required></textarea>
        <button type="submit" class="tp-drawer-submit" id="tp-drawer-submit">
            Enviar comentario
        </button>
    </form>
</aside>

<?php
// Identidad ya capturada en el login (view.php o provider.php).
// La exponemos como TP_INITIAL_SIGNER para que el drawer/modal arranque en modo compacto.
$__initialSigner = null;
if (!empty($visitorIdentity['email'])) {
    $__parts = explode(' ', trim($visitorIdentity['nombre']), 2);
    $__initialSigner = [
        'nombre'    => $__parts[0] ?? '',
        'apellidos' => $__parts[1] ?? '',
        'email'     => $visitorIdentity['email'],
    ];
} elseif (!empty($__provider['email'])) {
    $__parts = explode(' ', trim($__provider['nombre'] ?? ''), 2);
    $__initialSigner = [
        'nombre'    => $__parts[0] ?? '',
        'apellidos' => $__parts[1] ?? '',
        'email'     => $__provider['email'],
    ];
}
?>
<script>
window.TP_INITIAL_SIGNER = <?= json_encode($__initialSigner, JSON_UNESCAPED_UNICODE) ?>;
(function () {
    'use strict';

    const state = {
        comments: [],
        currentAnchor: null,        // null = todos
        currentTitle: 'Todas las secciones',
        signer: loadSigner(),
        loaded: false,
    };

    function loadSigner() {
        // Prioridad 1: identidad del login del servidor (authoritative)
        if (window.TP_INITIAL_SIGNER && window.TP_INITIAL_SIGNER.email) {
            try { localStorage.setItem('tp_signer', JSON.stringify(window.TP_INITIAL_SIGNER)); } catch (_) {}
            return window.TP_INITIAL_SIGNER;
        }
        // Prioridad 2: localStorage (visitas anteriores o migración)
        try { return JSON.parse(localStorage.getItem('tp_signer') || 'null'); } catch (_) { return null; }
    }
    function saveSigner(s) {
        try { localStorage.setItem('tp_signer', JSON.stringify(s)); } catch (_) {}
        state.signer = s;
        refreshIdentityUI();
    }

    function apiPost(action, params) {
        const body = new URLSearchParams();
        body.append('api_action', action);
        Object.keys(params || {}).forEach(k => body.append(k, params[k]));
        return fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
            body,
        }).then(r => r.json()).catch(() => ({ success: false, error: 'Red' }));
    }

    // -------- Enumerar secciones del documento --------
    // Solo H2/H3 con id que estén a nivel de sección, no dentro de tarjetas,
    // callouts, timelines, comparativas, sitemap, team-cards o tablas.
    const EXCLUDE_SELECTOR = '.tp-card, .tp-callout, .tp-timeline, .tp-comparison, .tp-sitemap, .tp-stat, .tp-tag, .team-card, .team-grid, .cta-block, table, .modal-box, .tp-drawer, .tp-stack';
    function getSections() {
        const area = document.getElementById('content-area') || document.querySelector('article.doc-main') || document.body;
        return Array.from(area.querySelectorAll('h2[id], h3[id]'))
            .filter(h => !h.closest(EXCLUDE_SELECTOR));
    }

    let _injecting = false;
    function injectSectionButtons() {
        if (_injecting) return;
        _injecting = true;
        try {
            getSections().forEach(h => {
                if (h.querySelector(':scope > .tp-sec-btn')) return;
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'tp-sec-btn';
                btn.dataset.anchor = h.id;
                btn.dataset.title = (h.textContent || '').trim().slice(0, 200);
                // SVG inline (evitamos lucide.createIcons() que rebotaba el MutationObserver)
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="9" y1="10" x2="15" y2="10"/><line x1="12" y1="7" x2="12" y2="13"/></svg><span>Comentar</span><span class="tp-sec-count" style="display:none">0</span>';
                btn.addEventListener('click', (e) => {
                    e.preventDefault(); e.stopPropagation();
                    openModal(h.id, btn.dataset.title);
                });
                h.appendChild(btn);
            });
            updateCounts();
        } finally {
            _injecting = false;
        }
    }

    function updateCounts() {
        // Agrupa por sección con el estado de cada hilo raíz.
        const perAnchor = {};
        const allRoots = state.comments.filter(c => !c.parent_id);
        const staffRepliesByRoot = {};
        state.comments.filter(c => c.parent_id && Number(c.is_staff) === 1).forEach(r => {
            staffRepliesByRoot[r.parent_id] = true;
        });

        allRoots.forEach(root => {
            const a = root.section_anchor;
            const entry = perAnchor[a] || (perAnchor[a] = { total: 0, open: 0, answered: 0, resolved: 0 });
            entry.total++;
            if (Number(root.resuelto) === 1) {
                entry.resolved++;
            } else if (staffRepliesByRoot[root.id]) {
                entry.answered++;  // abierto con respuesta del equipo → espera cierre del autor
            } else {
                entry.open++;      // abierto sin respuesta todavía
            }
        });

        document.querySelectorAll('.tp-sec-btn').forEach(b => {
            const e = perAnchor[b.dataset.anchor];
            const span = b.querySelector('.tp-sec-count');
            b.classList.remove('has-comments', 'state-pending', 'state-answered', 'state-resolved');
            if (!e || e.total === 0) { span.style.display = 'none'; return; }

            b.classList.add('has-comments');
            span.style.display = 'inline-block';
            span.textContent = e.total;

            if (e.answered > 0) {
                b.classList.add('state-answered');
                b.title = 'Tres Puntos ya ha respondido · revisa y marca resuelto';
            } else if (e.open > 0) {
                b.classList.add('state-pending');
                b.title = 'Comentario abierto esperando respuesta';
            } else {
                b.classList.add('state-resolved');
                b.title = 'Todos los comentarios de esta sección están cerrados';
            }
        });

        // FAB: contador global = hilos con respuesta esperando + abiertos sin respuesta
        const pendingGlobal = allRoots.filter(r => Number(r.resuelto) !== 1).length;
        const answeredGlobal = allRoots.filter(r => Number(r.resuelto) !== 1 && staffRepliesByRoot[r.id]).length;
        const fab = document.getElementById('tp-fab');
        const fabCount = document.getElementById('tp-fab-count');
        fab.classList.remove('has-answered');
        if (pendingGlobal > 0) {
            fabCount.style.display = 'inline-block';
            fabCount.textContent = pendingGlobal;
            if (answeredGlobal > 0) fab.classList.add('has-answered');
        } else {
            fabCount.style.display = 'none';
        }
    }

    // -------- Drawer --------
    function openDrawer(anchor, title) {
        state.currentAnchor = anchor || null;
        state.currentTitle = title || 'Todas las secciones';
        document.getElementById('tp-drawer-title').textContent = anchor ? 'Comentarios de la sección' : 'Comentarios del documento';
        document.getElementById('tp-drawer-sub').textContent = state.currentTitle;
        document.getElementById('tp-drawer').hidden = false;
        document.getElementById('tp-drawer-backdrop').hidden = false;
        requestAnimationFrame(() => {
            document.getElementById('tp-drawer').classList.add('open');
            document.getElementById('tp-drawer-backdrop').classList.add('open');
        });
        renderComments();
        refreshIdentityUI();
        setTimeout(() => document.getElementById('tp-drawer-texto').focus(), 200);
    }
    function closeDrawer() {
        document.getElementById('tp-drawer').classList.remove('open');
        document.getElementById('tp-drawer-backdrop').classList.remove('open');
        setTimeout(() => {
            document.getElementById('tp-drawer').hidden = true;
            document.getElementById('tp-drawer-backdrop').hidden = true;
        }, 250);
    }

    function renderComments() {
        const body = document.getElementById('tp-drawer-body');
        const all = state.comments || [];

        // Agrupa por raíz: roots son los de parent_id nulo; replies se anidan
        const roots = all.filter(c => !c.parent_id);
        const repliesByRoot = {};
        all.filter(c => c.parent_id).forEach(r => {
            (repliesByRoot[r.parent_id] = repliesByRoot[r.parent_id] || []).push(r);
        });

        const list = state.currentAnchor
            ? roots.filter(c => c.section_anchor === state.currentAnchor)
            : roots;

        if (!list.length) {
            body.innerHTML = '<div class="tp-drawer-empty">Sin comentarios todavía. Sé el primero en aportar feedback.</div>';
            return;
        }
        const esc = (s) => (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const signer = state.signer || {};
        const same = (a, b) => (a || '').trim().toLowerCase() === (b || '').trim().toLowerCase();
        const fmtDate = (d) => {
            if (!d) return '';
            const dt = new Date(d.replace(' ','T') + 'Z');
            return isNaN(dt) ? d : dt.toLocaleString('es-ES', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
        };

        const renderOne = (c, isReply) => {
            const staff = Number(c.is_staff) === 1;
            const mine = !staff && signer.nombre && signer.apellidos && same(signer.nombre, c.autor_nombre) && same(signer.apellidos, c.autor_apellidos);
            const cls = 'tp-comment' + (isReply ? ' reply' : '') + (staff ? ' staff' : '') + (mine ? ' mine' : '');
            const autor = staff ? 'Tres Puntos' : esc(c.autor_nombre + ' ' + c.autor_apellidos);
            const staffPill = staff ? '<span class="tp-staff-pill">Equipo</span>' : '';
            const when = esc(fmtDate(c.created_at));
            const actions = mine
                ? `<div class="tp-comment-actions">
                     <button type="button" class="tp-btn-edit" data-id="${c.id}">Editar</button>
                     <button type="button" class="tp-btn-delete" data-id="${c.id}">Eliminar</button>
                   </div>`
                : '';
            return `<article class="${cls}" data-id="${c.id}" data-anchor="${esc(c.section_anchor)}" data-title="${esc(c.section_title || c.section_anchor)}">
                <div class="tp-comment-meta">
                    <span><span class="tp-comment-author">${autor}</span>${staffPill}</span>
                    <span>${when}</span>
                </div>
                <div class="tp-comment-text" data-id="${c.id}">${esc(c.texto)}</div>
                ${actions}
            </article>`;
        };

        const renderThread = (root) => {
            const replies = (repliesByRoot[root.id] || []).slice().sort((a, b) => (a.created_at > b.created_at ? 1 : -1));
            const isResolved = Number(root.resuelto) === 1;
            const rootAuthor = (root.autor_nombre + ' ' + root.autor_apellidos).trim();
            const iAmAuthor = signer.nombre && signer.apellidos && same(signer.nombre, root.autor_nombre) && same(signer.apellidos, root.autor_apellidos);
            const hasStaffReply = replies.some(r => Number(r.is_staff) === 1);

            let statusHtml = '';
            if (isResolved) {
                const who = root.resuelto_por || rootAuthor;
                const when = esc(fmtDate(root.resuelto_at));
                statusHtml = `<span class="tp-status-pill closed">✓ Cerrado por ${esc(who)}${when ? ' · ' + when : ''}</span>`;
                if (iAmAuthor) {
                    statusHtml += `<button type="button" class="tp-btn-reopen" data-resolve-id="${root.id}">Reabrir</button>`;
                }
            } else {
                if (iAmAuthor) {
                    statusHtml = `<button type="button" class="tp-btn-resolve" data-resolve-id="${root.id}" title="Solo tú puedes cerrar tu comentario">✓ Marcar como resuelto</button>`;
                    if (hasStaffReply) {
                        statusHtml += `<span class="tp-status-pill waiting">· cuando confirmes, queda cerrado</span>`;
                    }
                } else {
                    statusHtml = `<span class="tp-status-pill open">● Abierto</span>
                        <span class="tp-status-pill waiting" title="Solo el autor del comentario puede marcarlo como resuelto">solo ${esc(rootAuthor)} puede cerrarlo</span>`;
                }
            }

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
            return `<section class="tp-thread${isResolved ? ' resolved' : ''}" data-root="${root.id}">
                ${renderOne(root, false)}
                ${replies.map(r => renderOne(r, true)).join('')}
                <div class="tp-thread-status">${statusHtml}</div>
                ${adminReplyBlock}
            </section>`;
        };

        // Orden: abiertos primero, luego cerrados, cada grupo por fecha asc
        const ordered = list.slice().sort((a, b) => {
            const ra = Number(a.resuelto), rb = Number(b.resuelto);
            if (ra !== rb) return ra - rb;
            return (a.created_at > b.created_at ? 1 : -1);
        });

        body.innerHTML = ordered.map(renderThread).join('');
        body.scrollTop = 0;

        body.querySelectorAll('.tp-comment').forEach(art => {
            art.addEventListener('click', (e) => {
                if (e.target.closest('.tp-comment-actions') || e.target.closest('.tp-comment-edit-area') || e.target.closest('.tp-thread-status')) return;
                scrollToSection(art.dataset.anchor, art.dataset.title);
            });
        });
        body.querySelectorAll('.tp-btn-edit').forEach(b => b.addEventListener('click', onEdit));
        body.querySelectorAll('.tp-btn-delete').forEach(b => b.addEventListener('click', onDelete));
        body.querySelectorAll('[data-resolve-id]').forEach(b => b.addEventListener('click', onToggleResolve));
        wireAdminReplyClient(body);
        if (window.lucide) lucide.createIcons();
    }

    function wireAdminReplyClient(body) {
        if (!window.__isAdminViewing) return;
        body.querySelectorAll('.tp-admin-reply-toggle').forEach(btn => {
            btn.onclick = () => {
                const wrap = btn.closest('.tp-admin-reply');
                wrap.querySelector('.tp-admin-reply-form').hidden = false;
                btn.hidden = true;
                wrap.querySelector('textarea').focus();
            };
        });
        body.querySelectorAll('.tp-admin-reply-cancel').forEach(btn => {
            btn.onclick = () => {
                const wrap = btn.closest('.tp-admin-reply');
                wrap.querySelector('.tp-admin-reply-form').hidden = true;
                wrap.querySelector('.tp-admin-reply-toggle').hidden = false;
            };
        });
        body.querySelectorAll('.tp-admin-reply-send').forEach(btn => {
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

    async function onToggleResolve(e) {
        const id = +e.currentTarget.dataset.resolveId;
        if (!state.signer) { alert('Pon tu nombre y apellidos primero.'); return; }
        const r = await apiPost('toggle_resolved_comment', {
            id,
            firmante_nombre: state.signer.nombre, firmante_apellidos: state.signer.apellidos,
        });
        if (!r.success) { alert(r.error || 'No se pudo actualizar.'); return; }
        await refresh();
    }

    function scrollToSection(anchor, title) {
        if (!anchor) return;
        const target = document.getElementById(anchor);
        if (!target) return;
        closeDrawer();
        setTimeout(() => {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            target.classList.remove('tp-section-flash'); void target.offsetWidth;
            target.classList.add('tp-section-flash');
            setTimeout(() => target.classList.remove('tp-section-flash'), 2000);
        }, 260);
    }

    async function onEdit(e) {
        const id = +e.target.dataset.id;
        const art = e.target.closest('.tp-comment');
        const textBox = art.querySelector('.tp-comment-text');
        const actions = art.querySelector('.tp-comment-actions');
        const current = textBox.textContent;
        if (art.querySelector('.tp-comment-edit-area')) return;

        const editArea = document.createElement('div');
        editArea.className = 'tp-comment-edit-area';
        editArea.innerHTML = `
            <textarea>${current.replace(/</g, '&lt;')}</textarea>
            <div class="tp-comment-actions">
                <button type="button" class="tp-btn-save">Guardar</button>
                <button type="button" class="tp-btn-cancel">Cancelar</button>
            </div>
        `;
        textBox.style.display = 'none';
        actions.style.display = 'none';
        art.appendChild(editArea);
        const ta = editArea.querySelector('textarea');
        ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length);
        editArea.querySelector('.tp-btn-cancel').addEventListener('click', () => {
            textBox.style.display = ''; actions.style.display = ''; editArea.remove();
        });
        editArea.querySelector('.tp-btn-save').addEventListener('click', async () => {
            const nuevo = ta.value.trim();
            if (!nuevo) return;
            if (!state.signer) { alert('Sesión perdida.'); return; }
            const r = await apiPost('edit_section_comment', {
                id, texto: nuevo,
                firmante_nombre: state.signer.nombre, firmante_apellidos: state.signer.apellidos,
            });
            if (!r.success) { alert(r.error || 'Error al editar.'); return; }
            await refresh();
        });
    }

    async function onDelete(e) {
        const id = +e.target.dataset.id;
        if (!confirm('¿Eliminar este comentario?')) return;
        if (!state.signer) { alert('Sesión perdida.'); return; }
        const r = await apiPost('delete_section_comment', {
            id,
            firmante_nombre: state.signer.nombre, firmante_apellidos: state.signer.apellidos,
        });
        if (!r.success) { alert(r.error || 'Error al eliminar.'); return; }
        await refresh();
    }

    function refreshIdentityUI() {
        const compact = document.getElementById('tp-identity-compact');
        const fields = document.getElementById('tp-identity-fields');
        if (state.signer && state.signer.nombre && state.signer.apellidos) {
            document.getElementById('tp-identity-name').textContent = state.signer.nombre + ' ' + state.signer.apellidos;
            compact.hidden = false; fields.hidden = true;
            document.getElementById('tp-drawer-nombre').value = state.signer.nombre;
            document.getElementById('tp-drawer-apellidos').value = state.signer.apellidos;
            if (state.signer.email) document.getElementById('tp-drawer-email').value = state.signer.email;
        } else {
            compact.hidden = true; fields.hidden = false;
        }
    }

    // -------- Cargar comentarios --------
    async function refresh() {
        const r = await apiPost('list_section_comments', {});
        if (r && r.success) {
            state.comments = r.comments || [];
            state.loaded = true;
            updateCounts();
            renderComments();
            // Si el modal está abierto, re-render también
            const modal = document.getElementById('tp-modal');
            if (modal && modal.classList.contains('open')) renderModalThread();
        }
    }

    // -------- Enviar --------
    async function submit(e) {
        e.preventDefault();
        const nombre = document.getElementById('tp-drawer-nombre').value.trim();
        const apellidos = document.getElementById('tp-drawer-apellidos').value.trim();
        const email = (document.getElementById('tp-drawer-email').value || '').trim();
        const texto = document.getElementById('tp-drawer-texto').value.trim();
        if (!nombre || !apellidos) { alert('Necesitamos nombre y apellidos.'); return; }
        if (!email) { alert('Necesitamos tu email para avisarte cuando te respondan.'); document.getElementById('tp-drawer-email').focus(); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { alert('Email no válido.'); document.getElementById('tp-drawer-email').focus(); return; }
        if (!texto) { document.getElementById('tp-drawer-texto').focus(); return; }
        if (!state.currentAnchor) { alert('Selecciona una sección concreta para comentar (botón "Comentar" junto al título).'); return; }

        saveSigner({ nombre, apellidos, email: email || (state.signer && state.signer.email) || '' });

        const btn = document.getElementById('tp-drawer-submit');
        btn.disabled = true; btn.textContent = 'Enviando…';
        const r = await apiPost('add_section_comment', {
            anchor: state.currentAnchor,
            section_title: state.currentTitle,
            texto, firmante_nombre: nombre, firmante_apellidos: apellidos,
            firmante_email: email || (state.signer && state.signer.email) || '',
        });
        btn.disabled = false; btn.textContent = 'Enviar comentario';
        if (!r || !r.success) { alert((r && r.error) || 'No se pudo enviar.'); return; }
        document.getElementById('tp-drawer-texto').value = '';
        await refresh();
    }

    // -------- Modal central (enfoque en un hilo) --------
    function openModal(anchor, title) {
        if (!anchor) return; // modal solo tiene sentido con sección concreta
        state.currentAnchor = anchor;
        state.currentTitle = title || anchor;
        document.getElementById('tp-modal-title').textContent = title || 'Sección';

        // Ajustar eyebrow según si hay hilo previo o se está abriendo fresco
        const existing = state.comments.filter(c => c.section_anchor === anchor && !c.parent_id);
        document.getElementById('tp-modal-eyebrow').textContent = existing.length
            ? existing.length + ' hilo' + (existing.length === 1 ? '' : 's') + ' en esta sección'
            : 'Comentar sección';

        document.getElementById('tp-modal').hidden = false;
        document.getElementById('tp-modal-backdrop').hidden = false;
        requestAnimationFrame(() => {
            document.getElementById('tp-modal').classList.add('open');
            document.getElementById('tp-modal-backdrop').classList.add('open');
        });
        renderModalThread();
        refreshModalIdentityUI();
        setTimeout(() => document.getElementById('tp-modal-texto').focus(), 200);
    }

    function closeModal() {
        const modal = document.getElementById('tp-modal');
        const backdrop = document.getElementById('tp-modal-backdrop');
        modal.classList.remove('open');
        backdrop.classList.remove('open');
        setTimeout(() => { modal.hidden = true; backdrop.hidden = true; }, 220);
    }

    function renderModalThread() {
        const body = document.getElementById('tp-modal-body');
        const anchor = state.currentAnchor;
        if (!anchor) { body.innerHTML = ''; return; }

        const all = state.comments || [];
        const roots = all.filter(c => !c.parent_id && c.section_anchor === anchor);
        const repliesByRoot = {};
        all.filter(c => c.parent_id).forEach(r => {
            (repliesByRoot[r.parent_id] = repliesByRoot[r.parent_id] || []).push(r);
        });

        if (!roots.length) {
            body.innerHTML = '<div class="tp-drawer-empty" style="padding: 1.5rem 0;">Sé el primero en comentar esta sección.</div>';
            return;
        }

        const esc = (s) => (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const signer = state.signer || {};
        const same = (a, b) => (a || '').trim().toLowerCase() === (b || '').trim().toLowerCase();
        const fmtDate = (d) => {
            if (!d) return '';
            const dt = new Date(d.replace(' ','T') + 'Z');
            return isNaN(dt) ? d : dt.toLocaleString('es-ES', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
        };

        const renderOne = (c, isReply) => {
            const staff = Number(c.is_staff) === 1;
            const mine = !staff && signer.nombre && signer.apellidos && same(signer.nombre, c.autor_nombre) && same(signer.apellidos, c.autor_apellidos);
            const cls = 'tp-comment' + (isReply ? ' reply' : '') + (staff ? ' staff' : '') + (mine ? ' mine' : '');
            const autor = staff ? 'Tres Puntos' : esc(c.autor_nombre + ' ' + c.autor_apellidos);
            const staffPill = staff ? '<span class="tp-staff-pill">Equipo</span>' : '';
            const when = esc(fmtDate(c.created_at));
            const actions = mine
                ? `<div class="tp-comment-actions">
                     <button type="button" class="tp-btn-edit" data-id="${c.id}">Editar</button>
                     <button type="button" class="tp-btn-delete" data-id="${c.id}">Eliminar</button>
                   </div>`
                : '';
            return `<article class="${cls}" data-id="${c.id}">
                <div class="tp-comment-meta">
                    <span><span class="tp-comment-author">${autor}</span>${staffPill}</span>
                    <span>${when}</span>
                </div>
                <div class="tp-comment-text" data-id="${c.id}">${esc(c.texto)}</div>
                ${actions}
            </article>`;
        };

        const renderThread = (root) => {
            const replies = (repliesByRoot[root.id] || []).slice().sort((a, b) => (a.created_at > b.created_at ? 1 : -1));
            const isResolved = Number(root.resuelto) === 1;
            const rootAuthor = (root.autor_nombre + ' ' + root.autor_apellidos).trim();
            const iAmAuthor = signer.nombre && signer.apellidos && same(signer.nombre, root.autor_nombre) && same(signer.apellidos, root.autor_apellidos);
            const hasStaffReply = replies.some(r => Number(r.is_staff) === 1);

            let statusHtml = '';
            if (isResolved) {
                const who = root.resuelto_por || rootAuthor;
                const when = esc(fmtDate(root.resuelto_at));
                statusHtml = `<span class="tp-status-pill closed">✓ Cerrado por ${esc(who)}${when ? ' · ' + when : ''}</span>`;
                if (iAmAuthor) statusHtml += `<button type="button" class="tp-btn-reopen" data-resolve-id="${root.id}">Reabrir</button>`;
            } else {
                if (iAmAuthor) {
                    statusHtml = `<button type="button" class="tp-btn-resolve" data-resolve-id="${root.id}">✓ Marcar como resuelto</button>`;
                    if (hasStaffReply) statusHtml += `<span class="tp-status-pill waiting">· cuando confirmes, queda cerrado</span>`;
                } else {
                    statusHtml = `<span class="tp-status-pill open">● Abierto</span>
                        <span class="tp-status-pill waiting">solo ${esc(rootAuthor)} puede cerrarlo</span>`;
                }
            }

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
            return `<section class="tp-thread${isResolved ? ' resolved' : ''}" data-root="${root.id}">
                ${renderOne(root, false)}
                ${replies.map(r => renderOne(r, true)).join('')}
                <div class="tp-thread-status">${statusHtml}</div>
                ${adminReplyBlock}
            </section>`;
        };

        body.innerHTML = roots.slice().sort((a,b) => (a.created_at > b.created_at ? 1 : -1)).map(renderThread).join('');

        body.querySelectorAll('.tp-btn-edit').forEach(b => b.addEventListener('click', onEdit));
        body.querySelectorAll('.tp-btn-delete').forEach(b => b.addEventListener('click', onDelete));
        body.querySelectorAll('[data-resolve-id]').forEach(b => b.addEventListener('click', onToggleResolve));
    }

    function refreshModalIdentityUI() {
        const compact = document.getElementById('tp-modal-identity-compact');
        const fields = document.getElementById('tp-modal-identity-fields');
        if (state.signer && state.signer.nombre && state.signer.apellidos) {
            document.getElementById('tp-modal-identity-name').textContent = state.signer.nombre + ' ' + state.signer.apellidos;
            compact.hidden = false; fields.hidden = true;
            document.getElementById('tp-modal-nombre').value = state.signer.nombre;
            document.getElementById('tp-modal-apellidos').value = state.signer.apellidos;
            if (state.signer.email) document.getElementById('tp-modal-email').value = state.signer.email;
        } else {
            compact.hidden = true; fields.hidden = false;
        }
    }

    async function submitModal(e) {
        e.preventDefault();
        const nombre = document.getElementById('tp-modal-nombre').value.trim();
        const apellidos = document.getElementById('tp-modal-apellidos').value.trim();
        const email = (document.getElementById('tp-modal-email').value || '').trim();
        const texto = document.getElementById('tp-modal-texto').value.trim();
        if (!nombre || !apellidos) { alert('Necesitamos nombre y apellidos.'); return; }
        if (!email) { alert('Necesitamos tu email para avisarte cuando te respondan.'); document.getElementById('tp-modal-email').focus(); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { alert('Email no válido.'); document.getElementById('tp-modal-email').focus(); return; }
        if (!texto) { document.getElementById('tp-modal-texto').focus(); return; }
        if (!state.currentAnchor) { alert('No hay sección seleccionada.'); return; }

        saveSigner({ nombre, apellidos, email: email || (state.signer && state.signer.email) || '' });

        const btn = document.getElementById('tp-modal-submit');
        btn.disabled = true; btn.textContent = 'Enviando…';
        const r = await apiPost('add_section_comment', {
            anchor: state.currentAnchor,
            section_title: state.currentTitle,
            texto, firmante_nombre: nombre, firmante_apellidos: apellidos,
            firmante_email: email || (state.signer && state.signer.email) || '',
        });
        btn.disabled = false; btn.textContent = 'Enviar comentario';
        if (!r || !r.success) { alert((r && r.error) || 'No se pudo enviar.'); return; }
        document.getElementById('tp-modal-texto').value = '';
        await refresh();
        renderModalThread();
    }

    // -------- Wire-up --------
    function init() {
        injectSectionButtons();
        refresh();

        document.getElementById('tp-fab').addEventListener('click', () => openDrawer(null, 'Todas las secciones'));
        document.getElementById('tp-drawer-close').addEventListener('click', closeDrawer);
        document.getElementById('tp-drawer-backdrop').addEventListener('click', closeDrawer);
        document.getElementById('tp-drawer-form').addEventListener('submit', submit);
        document.getElementById('tp-identity-change').addEventListener('click', () => {
            document.getElementById('tp-identity-compact').hidden = true;
            document.getElementById('tp-identity-fields').hidden = false;
        });

        // Modal central wire-up
        document.getElementById('tp-modal-close').addEventListener('click', closeModal);
        document.getElementById('tp-modal-backdrop').addEventListener('click', closeModal);
        document.getElementById('tp-modal-form').addEventListener('submit', submitModal);
        document.getElementById('tp-modal-identity-change').addEventListener('click', () => {
            document.getElementById('tp-modal-identity-compact').hidden = true;
            document.getElementById('tp-modal-identity-fields').hidden = false;
        });
        // Ctrl+Enter en el textarea del modal = submit
        document.getElementById('tp-modal-texto').addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('tp-modal-form').requestSubmit();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (document.getElementById('tp-modal').classList.contains('open')) closeModal();
                else if (document.getElementById('tp-drawer').classList.contains('open')) closeDrawer();
            }
        });

        // Los IDs de los H2/H3 se asignan por JS después del DOMContentLoaded. Reintentamos
        // varias veces en vez de usar MutationObserver (que rebotaba con las mutaciones
        // del propio script nav de view.php y colgaba la página).
        [100, 300, 700, 1500, 3000].forEach(ms => setTimeout(injectSectionButtons, ms));
        window.addEventListener('load', () => setTimeout(injectSectionButtons, 200));
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>

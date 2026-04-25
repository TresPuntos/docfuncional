<?php
/**
 * doc-tasks.php — Tareas para el cliente dentro del documento funcional.
 *
 * El admin declara tareas en el HTML del documento con esta estructura:
 *
 *   <div class="tp-tasks">
 *     <div class="tp-task" data-task-key="acceso-ga"
 *          data-task-title="Acceso a Google Analytics"
 *          data-task-assigned="Equipo de marketing">
 *       <h3>Acceso a Google Analytics</h3>
 *       <p>Necesitamos que nos deis acceso de Lectura a la propiedad GA4...</p>
 *     </div>
 *   </div>
 *
 * Al cargar la página, este script:
 *   - Sincroniza las tareas con la BD (UPSERT) → action `tasks_sync`.
 *   - Renderiza el estado actual de cada tarea (pendiente / completada con quién y cuándo).
 *   - Permite al cliente marcar una tarea como completada con email + comentario opcional.
 *   - Al completar, el server dispara notificación Telegram al equipo Tres Puntos.
 *
 * Reutiliza `tp_signer` (localStorage con nombre/apellidos/email) que ya usa doc-feedback.
 */
?>
<style>
.tp-tasks {
    margin: 1.5rem 0 2rem;
    background: var(--bg-surface, #141414);
    border: 1px solid var(--border-base, #1f1f1f);
    border-radius: var(--radius-lg, 14px);
    overflow: hidden;
}
.tp-tasks__header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem; padding: 1rem 1.25rem;
    background: linear-gradient(180deg, rgba(var(--mint-rgb, 93,255,191), .08) 0%, transparent 100%);
    border-bottom: 1px solid var(--border-base, #1f1f1f);
}
.tp-tasks__header-title { display: flex; align-items: center; gap: .6rem; font-weight: 600; font-size: .95rem; color: var(--text-primary, #f5f5f5); }
.tp-tasks__header-title i { width: 18px; height: 18px; color: var(--mint, var(--tp-primary, #5dffbf)); }
.tp-tasks__progress {
    font-size: .78rem; color: var(--text-muted, #8a8a8a);
    display: flex; align-items: center; gap: .5rem;
    font-variant-numeric: tabular-nums;
}
.tp-tasks__progress-bar {
    width: 60px; height: 4px; background: var(--bg-subtle, #191919); border-radius: 2px; overflow: hidden;
}
.tp-tasks__progress-fill {
    height: 100%; background: var(--mint, var(--tp-primary, #5dffbf));
    transition: width .35s ease;
}

.tp-task {
    padding: 1.1rem 1.25rem;
    border-bottom: 1px solid var(--border-subtle, #1a1a1a);
    display: grid;
    grid-template-columns: 28px 1fr auto;
    gap: 1rem;
    align-items: start;
    transition: background .15s ease;
}
.tp-task:last-child { border-bottom: 0; }
.tp-task[data-task-completed="1"] { background: rgba(var(--mint-rgb, 93,255,191), .035); }
.tp-task[data-task-completed="1"] .tp-task__title { text-decoration: line-through; color: var(--text-muted, #8a8a8a); }

.tp-task__check {
    width: 22px; height: 22px; border-radius: 50%;
    border: 2px solid var(--border-strong, #2a2a2a);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; margin-top: 2px;
    transition: all .2s ease;
}
.tp-task[data-task-completed="1"] .tp-task__check {
    background: var(--mint, var(--tp-primary, #5dffbf));
    border-color: var(--mint, var(--tp-primary, #5dffbf));
}
.tp-task[data-task-completed="1"] .tp-task__check i { color: var(--bg-base, #0e0e0e); width: 14px; height: 14px; }
.tp-task__check i { width: 0; height: 0; transition: width .2s ease, height .2s ease; }

.tp-task__body { min-width: 0; }
.tp-task__title { font-weight: 600; font-size: 1rem; color: var(--text-primary, #f5f5f5); margin: 0 0 .35rem; line-height: 1.35; }
.tp-task__assigned {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .72rem; color: var(--text-muted, #8a8a8a);
    background: var(--bg-subtle, #191919); border: 1px solid var(--border-subtle, #1a1a1a);
    padding: .2rem .55rem; border-radius: 999px; margin-bottom: .55rem;
}
.tp-task__assigned i { width: 12px; height: 12px; }
.tp-task__desc { font-size: .9rem; color: var(--text-secondary, #b3b3b3); line-height: 1.55; margin: 0; }
.tp-task__desc p { margin: 0 0 .5rem; }
.tp-task__desc p:last-child { margin: 0; }
.tp-task__desc code {
    background: var(--bg-subtle, #191919); padding: .1rem .35rem;
    border-radius: 4px; font-size: .82em; font-family: var(--font-mono, monospace);
}

.tp-task__meta {
    margin-top: .65rem;
    font-size: .78rem; color: var(--text-muted, #8a8a8a);
    display: flex; flex-wrap: wrap; align-items: center; gap: .55rem;
}
.tp-task__meta-by { color: var(--text-secondary, #b3b3b3); font-weight: 500; }
.tp-task__meta-comment {
    width: 100%;
    padding: .65rem .8rem; margin-top: .35rem;
    background: var(--bg-subtle, #191919); border-left: 2px solid var(--mint, var(--tp-primary, #5dffbf));
    border-radius: 4px;
    font-size: .85rem; color: var(--text-secondary, #b3b3b3); line-height: 1.5;
    font-style: italic;
}

.tp-task__action { display: flex; align-items: center; }
.tp-task__btn {
    display: inline-flex; align-items: center; gap: .4rem;
    background: var(--bg-subtle, #191919); color: var(--text-primary, #f5f5f5);
    border: 1px solid var(--border-strong, #2a2a2a);
    padding: .5rem .85rem; border-radius: var(--radius-sm, 8px);
    font-size: .82rem; font-weight: 500; cursor: pointer;
    transition: all .15s ease;
    font-family: inherit;
}
.tp-task__btn:hover { border-color: var(--mint, var(--tp-primary, #5dffbf)); color: var(--mint, var(--tp-primary, #5dffbf)); }
.tp-task__btn i { width: 14px; height: 14px; }

.tp-task__done-tag {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .72rem; font-weight: 600;
    color: var(--mint, var(--tp-primary, #5dffbf));
    background: rgba(var(--mint-rgb, 93,255,191), .08);
    border: 1px solid rgba(var(--mint-rgb, 93,255,191), .25);
    padding: .25rem .6rem; border-radius: 999px;
    text-transform: uppercase; letter-spacing: .04em;
}

@media (max-width: 640px) {
    .tp-task { grid-template-columns: 24px 1fr; }
    .tp-task__action { grid-column: 2; margin-top: .35rem; }
}

/* --- Modal completar tarea --- */
.tp-task-modal-backdrop {
    position: fixed; inset: 0; z-index: 9050;
    background: rgba(0,0,0,.7); backdrop-filter: blur(4px);
    display: none; align-items: center; justify-content: center;
    padding: 1rem;
}
.tp-task-modal-backdrop.is-open { display: flex; }
.tp-task-modal {
    width: 100%; max-width: 520px;
    background: var(--bg-surface, #141414);
    border: 1px solid var(--border-strong, #2a2a2a);
    border-radius: var(--radius-lg, 14px);
    padding: 1.5rem; box-shadow: 0 20px 60px rgba(0,0,0,.5);
}
.tp-task-modal__title { margin: 0 0 .35rem; font-size: 1.05rem; font-weight: 600; color: var(--text-primary, #f5f5f5); }
.tp-task-modal__sub { margin: 0 0 1.2rem; font-size: .85rem; color: var(--text-muted, #8a8a8a); line-height: 1.5; }
.tp-task-modal label { display: grid; gap: .35rem; font-size: .78rem; font-weight: 500; color: var(--text-secondary, #b3b3b3); margin-bottom: .85rem; }
.tp-task-modal input,
.tp-task-modal textarea {
    background: var(--bg-subtle, #191919);
    border: 1px solid var(--border-base, #1f1f1f);
    color: var(--text-primary, #f5f5f5);
    padding: .6rem .8rem; border-radius: var(--radius-sm, 8px);
    font-family: inherit; font-size: .9rem;
    transition: border-color .15s ease;
    width: 100%; box-sizing: border-box;
}
.tp-task-modal textarea { resize: vertical; min-height: 80px; }
.tp-task-modal input:focus,
.tp-task-modal textarea:focus { outline: none; border-color: var(--mint, var(--tp-primary, #5dffbf)); }
.tp-task-modal__row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
@media (max-width: 480px) { .tp-task-modal__row { grid-template-columns: 1fr; } }
.tp-task-modal__actions {
    display: flex; gap: .65rem; justify-content: flex-end; margin-top: 1rem;
}
.tp-task-modal__btn {
    padding: .55rem 1rem; border-radius: var(--radius-sm, 8px);
    font-size: .85rem; font-weight: 500; cursor: pointer;
    border: 1px solid transparent; font-family: inherit;
    transition: all .15s ease;
}
.tp-task-modal__btn--cancel {
    background: transparent; border-color: var(--border-strong, #2a2a2a); color: var(--text-secondary, #b3b3b3);
}
.tp-task-modal__btn--cancel:hover { color: var(--text-primary, #f5f5f5); border-color: var(--text-muted, #8a8a8a); }
.tp-task-modal__btn--confirm {
    background: var(--mint, var(--tp-primary, #5dffbf)); color: var(--bg-base, #0e0e0e); font-weight: 600;
}
.tp-task-modal__btn--confirm:hover { background: var(--mint-hover, #49e6a8); }
.tp-task-modal__btn--confirm:disabled { opacity: .5; cursor: not-allowed; }
.tp-task-modal__error {
    margin-top: .65rem; padding: .55rem .75rem;
    background: rgba(239, 68, 68, .1); border: 1px solid rgba(239, 68, 68, .3);
    border-radius: 6px; color: #fca5a5; font-size: .8rem;
    display: none;
}
.tp-task-modal__error.is-visible { display: block; }
.tp-task-modal__legal {
    margin-top: .85rem; padding-top: .85rem; border-top: 1px solid var(--border-subtle, #1a1a1a);
    font-size: .7rem; color: var(--text-muted, #8a8a8a); line-height: 1.5;
}
[data-theme="light"] .tp-task__btn { background: #f7f7f7; color: #141414; border-color: #e5e5e5; }
[data-theme="light"] .tp-task__assigned { background: #f7f7f7; border-color: #e5e5e5; }
[data-theme="light"] .tp-task__meta-comment { background: #f7f7f7; }
[data-theme="light"] .tp-task-modal { background: #ffffff; border-color: #e5e5e5; }
[data-theme="light"] .tp-task-modal input,
[data-theme="light"] .tp-task-modal textarea { background: #ffffff; border-color: #e5e5e5; color: #141414; }
</style>

<div class="tp-task-modal-backdrop" id="tpTaskModal" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="tp-task-modal">
        <h4 class="tp-task-modal__title">Marcar tarea como completada</h4>
        <p class="tp-task-modal__sub" id="tpTaskModalSub"></p>

        <div class="tp-identity-compact" id="tpTaskIdentityCompact" hidden style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.85rem;padding:.55rem .75rem;background:var(--bg-subtle,#191919);border:1px solid var(--border-subtle,#1a1a1a);border-radius:var(--radius-sm,8px);font-size:.78rem;color:var(--text-secondary,#b3b3b3);">
            <span>Confirmas como <strong id="tpTaskIdentityName" style="color:var(--text-primary,#f5f5f5);">—</strong></span>
        </div>
        <div id="tpTaskIdentityFields">
            <div class="tp-task-modal__row">
                <label>Nombre
                    <input type="text" id="tpTaskName" autocomplete="given-name">
                </label>
                <label>Apellidos
                    <input type="text" id="tpTaskSurname" autocomplete="family-name">
                </label>
            </div>
            <label>Email
                <input type="email" id="tpTaskEmail" autocomplete="email" placeholder="tu@empresa.com">
            </label>
        </div>
        <label>Comentario (opcional)
            <textarea id="tpTaskComment" placeholder="Si quieres, déjanos detalle. Ej.: 'Os he añadido como Editor con el email solicitado'"></textarea>
        </label>

        <div class="tp-task-modal__error" id="tpTaskError"></div>

        <div class="tp-task-modal__actions">
            <button class="tp-task-modal__btn tp-task-modal__btn--cancel" id="tpTaskCancel">Cancelar</button>
            <button class="tp-task-modal__btn tp-task-modal__btn--confirm" id="tpTaskConfirm">Marcar completada</button>
        </div>

        <p class="tp-task-modal__legal">Tus datos quedan registrados como prueba de la confirmación de esta tarea. Al equipo de Tres Puntos le llega un aviso al instante.</p>
    </div>
</div>

<script>
(function () {
    'use strict';
    const blocks = document.querySelectorAll('.tp-tasks');
    if (!blocks.length) return;

    const SLUG = <?= json_encode($slug); ?>;
    const ENDPOINT = '/p/' + SLUG;

    // Recolectar todas las tareas declaradas en el HTML
    const declared = [];
    let orderCounter = 0;
    blocks.forEach(block => {
        block.querySelectorAll('.tp-task[data-task-key]').forEach(el => {
            const key = (el.dataset.taskKey || '').trim();
            if (!key) return;
            // Título: explícito en data-task-title, o el primer h3/h4 dentro
            let titulo = (el.dataset.taskTitle || '').trim();
            if (!titulo) {
                const h = el.querySelector('h3, h4, h5');
                titulo = h ? h.textContent.trim() : key;
            }
            // Descripción: data-task-desc, o todo el HTML interno excluyendo título y meta
            let descripcion = (el.dataset.taskDesc || '').trim();
            const asignado = (el.dataset.taskAssigned || '').trim();
            declared.push({
                key, titulo, descripcion, asignado_a: asignado, orden: orderCounter++
            });
            // Marcar elemento con su estructura interna
            initTaskElement(el, titulo, asignado);
        });
    });

    if (!declared.length) return;

    function initTaskElement(el, titulo, asignado) {
        // Buscar h3/h4 existente para envolver
        const existingHeading = el.querySelector('h3, h4, h5');
        const existingDesc = Array.from(el.children).filter(c => c !== existingHeading);

        // Reescribir estructura interna manteniendo descripción
        const check = document.createElement('div');
        check.className = 'tp-task__check';
        check.innerHTML = '<i data-lucide="check"></i>';

        const body = document.createElement('div');
        body.className = 'tp-task__body';

        const title = document.createElement('div');
        title.className = 'tp-task__title';
        title.textContent = titulo;
        body.appendChild(title);

        if (asignado) {
            const ass = document.createElement('span');
            ass.className = 'tp-task__assigned';
            ass.innerHTML = '<i data-lucide="user-cog"></i>' + escapeHtml(asignado);
            body.appendChild(ass);
        }

        const desc = document.createElement('div');
        desc.className = 'tp-task__desc';
        // Mover el contenido descriptivo dentro de .tp-task__desc
        existingDesc.forEach(node => desc.appendChild(node));
        if (existingHeading) existingHeading.remove();
        body.appendChild(desc);

        const meta = document.createElement('div');
        meta.className = 'tp-task__meta';
        meta.style.display = 'none';
        body.appendChild(meta);

        const action = document.createElement('div');
        action.className = 'tp-task__action';
        const btn = document.createElement('button');
        btn.className = 'tp-task__btn';
        btn.type = 'button';
        btn.innerHTML = '<i data-lucide="check-circle"></i> Marcar completada';
        btn.addEventListener('click', () => openModal(el));
        action.appendChild(btn);

        // Limpiar y montar
        el.textContent = '';
        el.appendChild(check);
        el.appendChild(body);
        el.appendChild(action);
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // Sync inicial
    syncTasks().then(state => {
        renderState(state);
        if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
        updateProgressBars();
    });

    async function syncTasks() {
        const fd = new FormData();
        fd.append('api_action', 'tasks_sync');
        fd.append('tasks', JSON.stringify(declared));
        try {
            const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            return data.success ? (data.state || {}) : {};
        } catch (e) {
            console.warn('[tp-tasks] sync failed', e);
            return {};
        }
    }

    function renderState(state) {
        document.querySelectorAll('.tp-task[data-task-key]').forEach(el => {
            const key = el.dataset.taskKey;
            const s = state[key];
            renderOne(el, s);
        });
    }

    function renderOne(el, s) {
        const meta = el.querySelector('.tp-task__meta');
        const action = el.querySelector('.tp-task__action');
        if (s && s.completado) {
            el.dataset.taskCompleted = '1';
            const fecha = formatDate(s.completado_at);
            meta.style.display = 'flex';
            meta.innerHTML = '<span class="tp-task__done-tag"><i data-lucide="check"></i> Completada</span>'
                + ' <span class="tp-task__meta-by">' + escapeHtml(s.completado_por || '') + '</span>'
                + (s.completado_por_email ? ' <span>· ' + escapeHtml(s.completado_por_email) + '</span>' : '')
                + ' <span>· ' + escapeHtml(fecha) + '</span>'
                + (s.comentario ? '<div class="tp-task__meta-comment">' + escapeHtml(s.comentario).replace(/\n/g, '<br>') + '</div>' : '');
            action.innerHTML = '';
        } else {
            el.dataset.taskCompleted = '0';
            meta.style.display = 'none';
            // Botón ya está, no tocar
        }
    }

    function formatDate(iso) {
        if (!iso) return '';
        try {
            const d = new Date(iso.replace(' ', 'T') + (iso.includes('Z') || iso.includes('+') ? '' : 'Z'));
            return d.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' })
                + ' · ' + d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        } catch (e) { return iso; }
    }

    function updateProgressBars() {
        blocks.forEach(block => {
            const tasks = block.querySelectorAll('.tp-task[data-task-key]');
            const done = block.querySelectorAll('.tp-task[data-task-completed="1"]').length;
            let header = block.querySelector('.tp-tasks__header');
            if (!header) {
                header = document.createElement('div');
                header.className = 'tp-tasks__header';
                const titleAttr = block.dataset.tasksTitle || 'Tareas para el cliente';
                header.innerHTML =
                    '<div class="tp-tasks__header-title"><i data-lucide="list-checks"></i> ' + escapeHtml(titleAttr) + '</div>'
                    + '<div class="tp-tasks__progress">'
                    +   '<span class="tp-tasks__progress-text"></span>'
                    +   '<div class="tp-tasks__progress-bar"><div class="tp-tasks__progress-fill"></div></div>'
                    + '</div>';
                block.insertBefore(header, block.firstChild);
            }
            const text = header.querySelector('.tp-tasks__progress-text');
            const fill = header.querySelector('.tp-tasks__progress-fill');
            text.textContent = done + ' / ' + tasks.length + ' completadas';
            fill.style.width = (tasks.length ? (done / tasks.length * 100) : 0) + '%';
        });
        if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
    }

    // --- Modal ---
    const modal = document.getElementById('tpTaskModal');
    const subEl = document.getElementById('tpTaskModalSub');
    const nameEl = document.getElementById('tpTaskName');
    const surEl = document.getElementById('tpTaskSurname');
    const emailEl = document.getElementById('tpTaskEmail');
    const commentEl = document.getElementById('tpTaskComment');
    const errEl = document.getElementById('tpTaskError');
    const cancelBtn = document.getElementById('tpTaskCancel');
    const confirmBtn = document.getElementById('tpTaskConfirm');
    let currentEl = null;

    function readSigner() {
        try { return JSON.parse(localStorage.getItem('tp_signer') || '{}'); } catch (e) { return {}; }
    }
    function saveSigner(s) {
        try { localStorage.setItem('tp_signer', JSON.stringify(s)); } catch (e) {}
    }

    const compactEl = document.getElementById('tpTaskIdentityCompact');
    const fieldsEl = document.getElementById('tpTaskIdentityFields');
    const compactNameEl = document.getElementById('tpTaskIdentityName');

    function openModal(el) {
        currentEl = el;
        const titulo = el.querySelector('.tp-task__title').textContent.trim();
        subEl.textContent = titulo;
        const stored = readSigner();
        nameEl.value = stored.nombre || '';
        surEl.value = stored.apellidos || '';
        emailEl.value = stored.email || '';
        commentEl.value = '';
        errEl.classList.remove('is-visible');
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Marcar completada';

        // Identidad ya capturada en el login: ocultamos inputs y mostramos chip compacto.
        const hasIdentity = !!(stored.nombre && stored.email);
        if (hasIdentity) {
            compactNameEl.textContent = (stored.nombre || '') + (stored.apellidos ? ' ' + stored.apellidos : '');
            compactEl.hidden = false;
            fieldsEl.hidden = true;
        } else {
            compactEl.hidden = true;
            fieldsEl.hidden = false;
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        setTimeout(() => (hasIdentity ? commentEl : nameEl).focus(), 50);
    }
    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        currentEl = null;
    }
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal(); });

    confirmBtn.addEventListener('click', async () => {
        if (!currentEl) return;
        const comentario = commentEl.value.trim();
        const identityHidden = fieldsEl.hidden;

        // Si la identidad ya está capturada en sesión (compact mode), no validamos inputs:
        // el server lee el signer de sesión (visitor_identity_*).
        if (!identityHidden) {
            const nombre = nameEl.value.trim();
            const email = emailEl.value.trim();
            const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!nombre) { showError('Indica tu nombre.'); return; }
            if (!email || !emailRe.test(email)) { showError('Email no válido.'); return; }
            saveSigner({ nombre, apellidos: surEl.value.trim(), email });
        }

        confirmBtn.disabled = true;
        confirmBtn.innerHTML = 'Enviando…';

        const fd = new FormData();
        fd.append('api_action', 'task_complete');
        fd.append('task_key', currentEl.dataset.taskKey);
        fd.append('comentario', comentario);

        try {
            const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (!data.success) { showError(data.error || 'No se pudo completar.'); confirmBtn.disabled = false; confirmBtn.textContent = 'Marcar completada'; return; }
            renderOne(currentEl, {
                completado: true,
                completado_at: data.completado_at,
                completado_por: data.completado_por,
                completado_por_email: data.completado_por_email,
                comentario: data.comentario,
            });
            updateProgressBars();
            if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
            closeModal();
        } catch (e) {
            showError('Error de red. Inténtalo de nuevo.');
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Marcar completada';
        }
    });

    function showError(msg) {
        errEl.textContent = msg;
        errEl.classList.add('is-visible');
    }
})();
</script>

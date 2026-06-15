<?php
/**
 * doc-respuestas.php — Cajas de respuesta del cliente dentro del documento funcional.
 *
 * El admin declara cajas de respuesta en el HTML del documento:
 *
 *   <div class="tp-respuesta" data-respuesta-key="j2-1-idiomas"
 *        data-respuesta-grupo="j2"
 *        data-respuesta-label="Vuestra respuesta"
 *        data-respuesta-pregunta="Idiomas activos en el lanzamiento"></div>
 *
 * Y, opcionalmente, un bloque de envío al final del grupo:
 *
 *   <div class="tp-respuestas-enviar" data-respuestas-grupo="j2"
 *        data-respuestas-titulo="Enviar vuestras respuestas"></div>
 *
 * Comportamiento:
 *   - Cada caja: textarea + botón Guardar. Autosave por pregunta (UPSERT) → action
 *     `respuesta_save`, con aviso Telegram por cada respuesta guardada.
 *   - Estado por caja: "Pendiente" / "Guardado · autor · fecha".
 *   - Bloque de envío: barra de progreso "X de N respondidas", indica QUÉ falta, y un
 *     botón "Enviar respuestas" DESHABILITADO hasta que todas las del grupo tengan texto.
 *     Al enviar → action `respuestas_submit` → aviso Telegram consolidado + confirmación.
 *
 * Reutiliza `tp_signer` (identidad capturada en el login: nombre/email). Solo /p/{slug}.
 */
?>
<style>
.tp-respuesta {
    margin: .85rem 0 1.6rem;
    background: var(--bg-surface, #141414);
    border: 1px solid var(--border-base, #1f1f1f);
    border-left: 3px solid var(--mint, var(--tp-primary, #5dffbf));
    border-radius: var(--radius-sm, 8px);
    padding: .9rem 1rem 1rem;
}
.tp-respuesta__head { display: flex; align-items: center; justify-content: space-between; gap: .6rem; margin-bottom: .55rem; }
.tp-respuesta__label {
    display: flex; align-items: center; gap: .45rem;
    font-size: .78rem; font-weight: 600; letter-spacing: .01em;
    color: var(--text-secondary, #b3b3b3);
}
.tp-respuesta__label i { width: 14px; height: 14px; color: var(--mint, var(--tp-primary, #5dffbf)); }
.tp-respuesta__pill {
    font-size: .68rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em;
    padding: .2rem .55rem; border-radius: 999px; white-space: nowrap;
}
.tp-respuesta__pill--pending { color: #f5c97a; background: rgba(245,201,122,.1); border: 1px solid rgba(245,201,122,.28); }
.tp-respuesta__pill--done { color: var(--mint, #5dffbf); background: rgba(var(--mint-rgb,93,255,191),.1); border: 1px solid rgba(var(--mint-rgb,93,255,191),.28); }
.tp-respuesta__textarea {
    width: 100%; box-sizing: border-box;
    background: var(--bg-subtle, #191919);
    border: 1px solid var(--border-base, #1f1f1f);
    color: var(--text-primary, #f5f5f5);
    padding: .7rem .85rem; border-radius: var(--radius-sm, 8px);
    font-family: inherit; font-size: .92rem; line-height: 1.55;
    resize: vertical; min-height: 92px;
    transition: border-color .15s ease;
}
.tp-respuesta__textarea::placeholder { color: var(--text-muted, #8a8a8a); }
.tp-respuesta__textarea:focus { outline: none; border-color: var(--mint, var(--tp-primary, #5dffbf)); }
.tp-respuesta__footer {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem; margin-top: .6rem; flex-wrap: wrap;
}
.tp-respuesta__status {
    font-size: .76rem; color: var(--text-muted, #8a8a8a);
    display: flex; align-items: center; gap: .35rem; min-height: 16px;
}
.tp-respuesta__status i { width: 13px; height: 13px; }
.tp-respuesta__status--saved { color: var(--mint, var(--tp-primary, #5dffbf)); }
.tp-respuesta__status--error { color: #fca5a5; }
.tp-respuesta__status-by { color: var(--text-secondary, #b3b3b3); font-weight: 500; }
.tp-respuesta__btn {
    display: inline-flex; align-items: center; gap: .4rem;
    background: var(--mint, var(--tp-primary, #5dffbf)); color: var(--bg-base, #0e0e0e);
    border: 1px solid var(--mint, var(--tp-primary, #5dffbf));
    padding: .5rem .95rem; border-radius: var(--radius-sm, 8px);
    font-size: .82rem; font-weight: 600; cursor: pointer;
    font-family: inherit; transition: all .15s ease;
}
.tp-respuesta__btn:hover { background: var(--mint-hover, #49e6a8); border-color: var(--mint-hover, #49e6a8); }
.tp-respuesta__btn:disabled { opacity: .45; cursor: not-allowed; }
.tp-respuesta__btn i { width: 14px; height: 14px; }

/* --- Bloque de envío --- */
.tp-respuestas-enviar {
    margin: 1.5rem 0 2rem;
    background: var(--bg-surface, #141414);
    border: 1px solid var(--border-base, #1f1f1f);
    border-radius: var(--radius-lg, 14px);
    padding: 1.15rem 1.25rem 1.25rem;
}
.tp-re-enviar__head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem; flex-wrap: wrap; margin-bottom: .75rem;
}
.tp-re-enviar__title { display: flex; align-items: center; gap: .55rem; font-weight: 600; font-size: .98rem; color: var(--text-primary, #f5f5f5); }
.tp-re-enviar__title i { width: 18px; height: 18px; color: var(--mint, var(--tp-primary, #5dffbf)); }
.tp-re-enviar__progress { display: flex; align-items: center; gap: .55rem; font-size: .8rem; color: var(--text-muted, #8a8a8a); font-variant-numeric: tabular-nums; }
.tp-re-enviar__bar { width: 90px; height: 5px; background: var(--bg-subtle, #191919); border-radius: 3px; overflow: hidden; }
.tp-re-enviar__fill { height: 100%; width: 0; background: var(--mint, var(--tp-primary, #5dffbf)); transition: width .35s ease; }
.tp-re-enviar__help {
    font-size: .85rem; line-height: 1.55; color: var(--text-secondary, #b3b3b3);
    display: flex; align-items: flex-start; gap: .45rem; margin: .35rem 0 .9rem;
}
.tp-re-enviar__help i { width: 15px; height: 15px; flex-shrink: 0; margin-top: .15rem; }
.tp-re-enviar__help--ok { color: var(--mint, var(--tp-primary, #5dffbf)); }
.tp-re-enviar__help--ok i { color: var(--mint, var(--tp-primary, #5dffbf)); }
.tp-re-enviar__help b { color: var(--text-primary, #f5f5f5); font-weight: 600; }
.tp-re-enviar__confirm {
    display: none; align-items: flex-start; gap: .45rem;
    padding: .7rem .85rem; margin-bottom: .9rem;
    background: rgba(var(--mint-rgb,93,255,191),.08); border: 1px solid rgba(var(--mint-rgb,93,255,191),.28);
    border-radius: var(--radius-sm, 8px); font-size: .85rem; color: var(--text-secondary,#b3b3b3); line-height: 1.5;
}
.tp-re-enviar__confirm.is-visible { display: flex; }
.tp-re-enviar__confirm i { width: 15px; height: 15px; color: var(--mint, #5dffbf); flex-shrink: 0; margin-top: .15rem; }
.tp-re-enviar__confirm b { color: var(--mint, #5dffbf); }
.tp-re-enviar__btn {
    display: inline-flex; align-items: center; gap: .45rem;
    background: var(--mint, var(--tp-primary, #5dffbf)); color: var(--bg-base, #0e0e0e);
    border: 1px solid var(--mint, var(--tp-primary, #5dffbf));
    padding: .65rem 1.3rem; border-radius: var(--radius-sm, 8px);
    font-size: .9rem; font-weight: 700; cursor: pointer; font-family: inherit;
    transition: all .15s ease;
}
.tp-re-enviar__btn:hover:not(:disabled) { background: var(--mint-hover, #49e6a8); border-color: var(--mint-hover, #49e6a8); }
.tp-re-enviar__btn:disabled { opacity: .4; cursor: not-allowed; background: var(--bg-subtle,#191919); color: var(--text-muted,#8a8a8a); border-color: var(--border-strong,#2a2a2a); }
.tp-re-enviar__btn i { width: 16px; height: 16px; }

[data-theme="light"] .tp-respuesta,
[data-theme="light"] .tp-respuestas-enviar { background: #ffffff; }
[data-theme="light"] .tp-respuesta__textarea { background: #ffffff; border-color: #e5e5e5; color: #141414; }
[data-theme="light"] .tp-respuesta__btn,
[data-theme="light"] .tp-re-enviar__btn:not(:disabled) { color: #0e0e0e; }
</style>

<script>
(function () {
    'use strict';
    const items = document.querySelectorAll('.tp-respuesta[data-respuesta-key]');
    const submitBlocks = document.querySelectorAll('.tp-respuestas-enviar');
    if (!items.length) return;

    const SLUG = <?= json_encode($slug); ?>;
    const ENDPOINT = '/p/' + SLUG;

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function formatDate(iso) {
        if (!iso) return '';
        try {
            const d = new Date(iso.replace(' ', 'T') + (iso.includes('Z') || iso.includes('+') ? '' : 'Z'));
            return d.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' })
                + ' · ' + d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        } catch (e) { return iso; }
    }

    // grupos: { grupo: { boxes: [el...], submitEl, enviado: {enviado_at, autor}|null } }
    const groups = {};
    function group(g) { if (!groups[g]) groups[g] = { boxes: [], submitEl: null, enviado: null }; return groups[g]; }

    const declared = [];
    let order = 0;
    items.forEach(el => {
        const key = (el.dataset.respuestaKey || '').trim();
        if (!key) return;
        const grupo = (el.dataset.respuestaGrupo || '').trim();
        const label = (el.dataset.respuestaLabel || 'Vuestra respuesta').trim();
        const pregunta = (el.dataset.respuestaPregunta || '').trim();
        declared.push({ key, pregunta, orden: order++ });

        const head = document.createElement('div');
        head.className = 'tp-respuesta__head';
        const labelEl = document.createElement('div');
        labelEl.className = 'tp-respuesta__label';
        labelEl.innerHTML = '<i data-lucide="message-square-text"></i>' + escapeHtml(label);
        const pill = document.createElement('span');
        pill.className = 'tp-respuesta__pill tp-respuesta__pill--pending';
        pill.textContent = 'Pendiente';
        head.appendChild(labelEl);
        head.appendChild(pill);

        const ta = document.createElement('textarea');
        ta.className = 'tp-respuesta__textarea';
        ta.placeholder = 'Escribe aquí la respuesta de vuestro equipo…';

        const footer = document.createElement('div');
        footer.className = 'tp-respuesta__footer';
        const status = document.createElement('div');
        status.className = 'tp-respuesta__status';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tp-respuesta__btn';
        btn.innerHTML = '<i data-lucide="save"></i> Guardar';
        btn.disabled = true;
        footer.appendChild(status);
        footer.appendChild(btn);

        el.textContent = '';
        el.appendChild(head);
        el.appendChild(ta);
        el.appendChild(footer);

        el._tp = { key, grupo, pregunta, ta, btn, status, pill, savedText: '' };
        group(grupo).boxes.push(el);

        ta.addEventListener('input', () => { btn.disabled = (ta.value === el._tp.savedText); });
        btn.addEventListener('click', () => saveOne(el));
    });

    // Construir bloques de envío
    submitBlocks.forEach(block => {
        const grupo = (block.dataset.respuestasGrupo || '').trim();
        const g = group(grupo);
        g.submitEl = block;
        const titulo = (block.dataset.respuestasTitulo || 'Enviar vuestras respuestas').trim();
        block.innerHTML =
            '<div class="tp-re-enviar__head">'
          +   '<div class="tp-re-enviar__title"><i data-lucide="send"></i> ' + escapeHtml(titulo) + '</div>'
          +   '<div class="tp-re-enviar__progress"><span class="tp-re-enviar__progress-text"></span>'
          +     '<div class="tp-re-enviar__bar"><div class="tp-re-enviar__fill"></div></div></div>'
          + '</div>'
          + '<div class="tp-re-enviar__confirm"></div>'
          + '<div class="tp-re-enviar__help"></div>'
          + '<button type="button" class="tp-re-enviar__btn" disabled><i data-lucide="send"></i> Enviar respuestas</button>';
        block.querySelector('.tp-re-enviar__btn').addEventListener('click', () => submitGroup(grupo));
    });

    if (!declared.length) return;

    // Sync inicial
    syncRespuestas().then(data => {
        const state = data.state || {};
        const envios = data.envios || {};
        items.forEach(el => {
            if (!el._tp) return;
            const s = state[el._tp.key];
            if (s) {
                el._tp.ta.value = s.texto || '';
                el._tp.savedText = s.texto || '';
                if (s.texto) setSavedStatus(el, s);
            }
            updatePill(el);
            el._tp.btn.disabled = (el._tp.ta.value === el._tp.savedText);
        });
        Object.keys(groups).forEach(g => { groups[g].enviado = envios[g] || null; refreshGroup(g); });
        if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
    });

    async function syncRespuestas() {
        const fd = new FormData();
        fd.append('api_action', 'respuestas_sync');
        fd.append('respuestas', JSON.stringify(declared));
        try {
            const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            return data.success ? data : { state: {}, envios: {} };
        } catch (e) {
            console.warn('[tp-respuestas] sync failed', e);
            return { state: {}, envios: {} };
        }
    }

    function isAnswered(el) { return (el._tp.savedText || '').trim() !== ''; }

    function updatePill(el) {
        const pill = el._tp.pill;
        if (isAnswered(el)) {
            pill.className = 'tp-respuesta__pill tp-respuesta__pill--done';
            pill.textContent = 'Respondida';
        } else {
            pill.className = 'tp-respuesta__pill tp-respuesta__pill--pending';
            pill.textContent = 'Pendiente';
        }
    }
    function setSavedStatus(el, s) {
        const st = el._tp.status;
        st.className = 'tp-respuesta__status tp-respuesta__status--saved';
        st.innerHTML = '<i data-lucide="check"></i> Guardado'
            + (s.autor ? ' · <span class="tp-respuesta__status-by">' + escapeHtml(s.autor) + '</span>' : '')
            + (s.updated_at ? ' · ' + escapeHtml(formatDate(s.updated_at)) : '');
        if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
    }
    function setStatus(el, msg, kind) {
        const st = el._tp.status;
        st.className = 'tp-respuesta__status' + (kind ? ' tp-respuesta__status--' + kind : '');
        st.textContent = msg;
    }

    async function saveOne(el) {
        const { ta, btn } = el._tp;
        const texto = ta.value.trim();
        btn.disabled = true;
        btn.innerHTML = 'Guardando…';
        const fd = new FormData();
        fd.append('api_action', 'respuesta_save');
        fd.append('respuesta_key', el._tp.key);
        fd.append('texto', texto);
        try {
            const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            btn.innerHTML = '<i data-lucide="save"></i> Guardar';
            if (!data.success) {
                setStatus(el, data.error || 'No se pudo guardar.', 'error');
                btn.disabled = false;
                if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
                return;
            }
            el._tp.savedText = data.texto || '';
            setSavedStatus(el, { autor: data.autor, updated_at: data.updated_at });
            updatePill(el);
            refreshGroup(el._tp.grupo);
            btn.disabled = true;
        } catch (e) {
            btn.innerHTML = '<i data-lucide="save"></i> Guardar';
            setStatus(el, 'Error de red. Inténtalo de nuevo.', 'error');
            btn.disabled = false;
        }
        if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
    }

    function refreshGroup(grupo) {
        const g = groups[grupo];
        if (!g || !g.submitEl) return;
        const total = g.boxes.length;
        const answered = g.boxes.filter(isAnswered);
        const missing = g.boxes.filter(b => !isAnswered(b));
        const done = answered.length;

        const txt = g.submitEl.querySelector('.tp-re-enviar__progress-text');
        const fill = g.submitEl.querySelector('.tp-re-enviar__fill');
        const help = g.submitEl.querySelector('.tp-re-enviar__help');
        const confirm = g.submitEl.querySelector('.tp-re-enviar__confirm');
        const btn = g.submitEl.querySelector('.tp-re-enviar__btn');
        txt.textContent = done + ' de ' + total + ' respondidas';
        fill.style.width = (total ? (done / total * 100) : 0) + '%';

        const allDone = done === total && total > 0;

        // Confirmación de envío
        if (g.enviado && g.enviado.enviado_at) {
            confirm.classList.add('is-visible');
            confirm.innerHTML = '<i data-lucide="check-circle"></i> <span><b>Respuestas enviadas</b>'
                + (g.enviado.autor ? ' por ' + escapeHtml(g.enviado.autor) : '')
                + (g.enviado.enviado_at ? ' · ' + escapeHtml(formatDate(g.enviado.enviado_at)) : '')
                + '. Si editáis alguna, podéis reenviarlas.</span>';
        } else {
            confirm.classList.remove('is-visible');
            confirm.innerHTML = '';
        }

        if (allDone) {
            help.className = 'tp-re-enviar__help tp-re-enviar__help--ok';
            help.innerHTML = '<i data-lucide="check-circle"></i> Todo listo: las ' + total + ' respuestas están guardadas. Pulsa el botón para enviárnoslas.';
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="send"></i> ' + (g.enviado ? 'Reenviar respuestas' : 'Enviar respuestas');
        } else {
            const nombres = missing.map(b => b._tp.pregunta || b._tp.key);
            help.className = 'tp-re-enviar__help';
            help.innerHTML = '<i data-lucide="info"></i> <span>El botón se activa cuando estén las ' + total + ' respondidas. '
                + 'Te ' + (missing.length === 1 ? 'falta' : 'faltan') + ' <b>' + missing.length + '</b> por contestar y guardar'
                + (nombres.length ? ': ' + nombres.map(escapeHtml).join(', ') : '') + '.</span>';
            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="send"></i> Enviar respuestas';
        }
        if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
    }

    async function submitGroup(grupo) {
        const g = groups[grupo];
        if (!g || !g.submitEl) return;
        const btn = g.submitEl.querySelector('.tp-re-enviar__btn');
        const keys = g.boxes.map(b => b._tp.key);
        btn.disabled = true;
        btn.innerHTML = 'Enviando…';
        const fd = new FormData();
        fd.append('api_action', 'respuestas_submit');
        fd.append('grupo', grupo);
        fd.append('keys', JSON.stringify(keys));
        try {
            const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (!data.success) {
                const help = g.submitEl.querySelector('.tp-re-enviar__help');
                help.className = 'tp-re-enviar__help';
                help.innerHTML = '<i data-lucide="alert-triangle"></i> <span>' + escapeHtml(data.error || 'No se pudo enviar.') + '</span>';
                refreshGroup(grupo);
                return;
            }
            g.enviado = { enviado_at: data.enviado_at, autor: data.autor };
            refreshGroup(grupo);
        } catch (e) {
            const help = g.submitEl.querySelector('.tp-re-enviar__help');
            help.className = 'tp-re-enviar__help';
            help.innerHTML = '<i data-lucide="alert-triangle"></i> <span>Error de red al enviar. Inténtalo de nuevo.</span>';
            refreshGroup(grupo);
        }
        if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
    }
})();
</script>

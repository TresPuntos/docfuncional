<?php
/**
 * doc-respuestas.php — Cajas de respuesta del cliente dentro del documento funcional.
 *
 * El admin declara cajas de respuesta en el HTML del documento con esta estructura:
 *
 *   <div class="tp-respuesta" data-respuesta-key="j2-1-idiomas"
 *        data-respuesta-label="Vuestra respuesta"
 *        data-respuesta-pregunta="Idiomas activos en el lanzamiento"></div>
 *
 * Al cargar la página, este script:
 *   - Sincroniza las preguntas declaradas con la BD (UPSERT) → action `respuestas_sync`.
 *   - Renderiza dentro de cada caja un textarea + botón "Guardar", precargado con la
 *     respuesta ya guardada (si existe) + quién la guardó y cuándo.
 *   - Permite al cliente escribir y guardar (editable, se puede volver a guardar).
 *   - Al guardar, el server dispara notificación Telegram al equipo Tres Puntos.
 *
 * Reutiliza `tp_signer` (identidad capturada en el login: nombre/email).
 * Pensado para bloques de "dudas / preguntas para el cliente".
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
.tp-respuesta__label {
    display: flex; align-items: center; gap: .45rem;
    font-size: .78rem; font-weight: 600; letter-spacing: .01em;
    color: var(--text-secondary, #b3b3b3);
    margin-bottom: .55rem;
}
.tp-respuesta__label i { width: 14px; height: 14px; color: var(--mint, var(--tp-primary, #5dffbf)); }
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
[data-theme="light"] .tp-respuesta { background: #ffffff; }
[data-theme="light"] .tp-respuesta__textarea { background: #ffffff; border-color: #e5e5e5; color: #141414; }
[data-theme="light"] .tp-respuesta__btn { color: #0e0e0e; }
</style>

<script>
(function () {
    'use strict';
    const items = document.querySelectorAll('.tp-respuesta[data-respuesta-key]');
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

    // Construir UI dentro de cada caja y recolectar las preguntas declaradas
    const declared = [];
    let order = 0;
    items.forEach(el => {
        const key = (el.dataset.respuestaKey || '').trim();
        if (!key) return;
        const label = (el.dataset.respuestaLabel || 'Vuestra respuesta').trim();
        const pregunta = (el.dataset.respuestaPregunta || '').trim();
        declared.push({ key, pregunta, orden: order++ });

        const labelEl = document.createElement('div');
        labelEl.className = 'tp-respuesta__label';
        labelEl.innerHTML = '<i data-lucide="message-square-text"></i>' + escapeHtml(label);

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
        el.appendChild(labelEl);
        el.appendChild(ta);
        el.appendChild(footer);

        el._tp = { key, ta, btn, status, savedText: '' };

        ta.addEventListener('input', () => { btn.disabled = (ta.value === el._tp.savedText); });
        btn.addEventListener('click', () => saveOne(el));
    });

    if (!declared.length) return;

    // Sync inicial: registra preguntas + trae respuestas guardadas
    syncRespuestas().then(state => {
        items.forEach(el => {
            if (!el._tp) return;
            const s = state[el._tp.key];
            if (s) {
                el._tp.ta.value = s.texto || '';
                el._tp.savedText = s.texto || '';
                if (s.texto) setSavedStatus(el, s);
            }
            el._tp.btn.disabled = (el._tp.ta.value === el._tp.savedText);
        });
        if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
    });

    async function syncRespuestas() {
        const fd = new FormData();
        fd.append('api_action', 'respuestas_sync');
        fd.append('respuestas', JSON.stringify(declared));
        try {
            const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            return data.success ? (data.state || {}) : {};
        } catch (e) {
            console.warn('[tp-respuestas] sync failed', e);
            return {};
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
            btn.disabled = true;
        } catch (e) {
            btn.innerHTML = '<i data-lucide="save"></i> Guardar';
            setStatus(el, 'Error de red. Inténtalo de nuevo.', 'error');
            btn.disabled = false;
            if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
        }
    }
})();
</script>

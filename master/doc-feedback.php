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

/* --- Botón flotante 💬 en cada sección --- */
.tp-sec-btn {
    position: absolute; display: inline-flex; align-items: center; gap: .35rem;
    background: var(--bg-subtle); color: var(--text-secondary);
    border: 1px solid var(--border-base); border-radius: var(--radius-full, 999px);
    font-size: .72rem; font-weight: 600; padding: .35rem .65rem; cursor: pointer;
    opacity: .55; transition: all .2s ease; z-index: 5; user-select: none;
    font-family: var(--font-body, inherit);
}
.tp-sec-btn:hover { opacity: 1; color: var(--mint, var(--tp-primary)); border-color: var(--mint, var(--tp-primary)); transform: translateY(-1px); }
.tp-sec-btn .tp-sec-count { background: var(--mint, var(--tp-primary)); color: var(--text-inverse, #000); border-radius: 999px; padding: 0 .45rem; min-width: 1.1rem; text-align: center; font-size: .7rem; }
.tp-sec-btn.has-comments { opacity: 1; border-color: var(--mint, var(--tp-primary)); }

@media (max-width: 900px) {
    .tp-sec-btn { position: static; display: inline-flex; margin-top: .4rem; opacity: 1; }
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

/* --- Drawer lateral --- */
.tp-drawer-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,.55); opacity: 0; pointer-events: none;
    transition: opacity .2s ease; z-index: 1000;
}
.tp-drawer-backdrop.open { opacity: 1; pointer-events: auto; }
.tp-drawer {
    position: fixed; top: 0; right: 0; height: 100vh; width: min(460px, 92vw);
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
}
.tp-comment.mine { border-color: rgba(var(--mint-rgb, 93, 255, 191), .35); }
.tp-comment-meta { font-size: .72rem; color: var(--text-muted); margin-bottom: .35rem; display: flex; justify-content: space-between; gap: .5rem; }
.tp-comment-author { color: var(--text-secondary); font-weight: 600; }
.tp-comment-text { color: var(--text-primary); white-space: pre-wrap; word-wrap: break-word; }
.tp-comment.reply { margin-left: 1.25rem; border-left: 2px solid var(--mint, var(--tp-primary)); }

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
</style>

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
        <textarea id="tp-drawer-texto" placeholder="Escribe tu comentario sobre esta sección…" required></textarea>
        <button type="submit" class="tp-drawer-submit" id="tp-drawer-submit">
            Enviar comentario
        </button>
    </form>
</aside>

<script>
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
    function getSections() {
        const area = document.getElementById('content-area') || document.querySelector('article.doc-main') || document.body;
        return Array.from(area.querySelectorAll('h2[id], h3[id]'))
            .filter(h => !h.closest('.tp-drawer'));
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
                    openDrawer(h.id, btn.dataset.title);
                });
                if (getComputedStyle(h).position === 'static') h.style.position = 'relative';
                h.appendChild(btn);
            });
            updateCounts();
        } finally {
            _injecting = false;
        }
    }

    function updateCounts() {
        const byAnchor = {};
        state.comments.forEach(c => { byAnchor[c.section_anchor] = (byAnchor[c.section_anchor] || 0) + 1; });
        document.querySelectorAll('.tp-sec-btn').forEach(b => {
            const n = byAnchor[b.dataset.anchor] || 0;
            const span = b.querySelector('.tp-sec-count');
            if (n > 0) { b.classList.add('has-comments'); span.style.display = 'inline-block'; span.textContent = n; }
            else { b.classList.remove('has-comments'); span.style.display = 'none'; }
        });
        const fabCount = document.getElementById('tp-fab-count');
        if (state.comments.length > 0) { fabCount.style.display = 'inline-block'; fabCount.textContent = state.comments.length; }
        else { fabCount.style.display = 'none'; }
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
        const list = state.currentAnchor
            ? state.comments.filter(c => c.section_anchor === state.currentAnchor)
            : state.comments;

        if (!list.length) {
            body.innerHTML = '<div class="tp-drawer-empty">Sin comentarios todavía. Sé el primero en aportar feedback.</div>';
            return;
        }
        const esc = (s) => (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const signer = state.signer || {};
        const rows = list.map(c => {
            const mine = signer.nombre && signer.apellidos && (signer.nombre === c.autor_nombre) && (signer.apellidos === c.autor_apellidos);
            const dt = c.created_at ? new Date(c.created_at.replace(' ','T') + 'Z') : new Date();
            const when = isNaN(dt) ? c.created_at : dt.toLocaleString('es-ES', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
            const section = !state.currentAnchor && c.section_title ? `<span title="${esc(c.section_title)}">· ${esc((c.section_title||'').slice(0,40))}</span>` : '';
            return `<article class="tp-comment${mine ? ' mine' : ''}">
                <div class="tp-comment-meta">
                    <span><span class="tp-comment-author">${esc(c.autor_nombre)} ${esc(c.autor_apellidos)}</span> ${section}</span>
                    <span>${esc(when)}</span>
                </div>
                <div class="tp-comment-text">${esc(c.texto)}</div>
            </article>`;
        }).join('');
        body.innerHTML = rows;
        body.scrollTop = body.scrollHeight;
    }

    function refreshIdentityUI() {
        const compact = document.getElementById('tp-identity-compact');
        const fields = document.getElementById('tp-identity-fields');
        if (state.signer && state.signer.nombre && state.signer.apellidos) {
            document.getElementById('tp-identity-name').textContent = state.signer.nombre + ' ' + state.signer.apellidos;
            compact.hidden = false; fields.hidden = true;
            document.getElementById('tp-drawer-nombre').value = state.signer.nombre;
            document.getElementById('tp-drawer-apellidos').value = state.signer.apellidos;
        } else {
            compact.hidden = true; fields.hidden = false;
        }
    }

    // -------- Cargar comentarios --------
    async function refresh() {
        const r = await apiPost('list_section_comments', {});
        if (r && r.success) { state.comments = r.comments || []; state.loaded = true; updateCounts(); renderComments(); }
    }

    // -------- Enviar --------
    async function submit(e) {
        e.preventDefault();
        const nombre = document.getElementById('tp-drawer-nombre').value.trim();
        const apellidos = document.getElementById('tp-drawer-apellidos').value.trim();
        const texto = document.getElementById('tp-drawer-texto').value.trim();
        if (!nombre || !apellidos) { alert('Necesitamos nombre y apellidos.'); return; }
        if (!texto) { document.getElementById('tp-drawer-texto').focus(); return; }
        if (!state.currentAnchor) { alert('Selecciona una sección concreta para comentar (botón "Comentar" junto al título).'); return; }

        saveSigner({ nombre, apellidos, email: (state.signer && state.signer.email) || '' });

        const btn = document.getElementById('tp-drawer-submit');
        btn.disabled = true; btn.textContent = 'Enviando…';
        const r = await apiPost('add_section_comment', {
            anchor: state.currentAnchor,
            section_title: state.currentTitle,
            texto, firmante_nombre: nombre, firmante_apellidos: apellidos,
            firmante_email: (state.signer && state.signer.email) || '',
        });
        btn.disabled = false; btn.textContent = 'Enviar comentario';
        if (!r || !r.success) { alert((r && r.error) || 'No se pudo enviar.'); return; }
        document.getElementById('tp-drawer-texto').value = '';
        await refresh();
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
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && document.getElementById('tp-drawer').classList.contains('open')) closeDrawer();
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

<?php
/**
 * Jordan-doc widget · UI embebible en view.php
 *
 * Se incluye después del FAB de comentarios (esquina inferior derecha).
 * Este widget ocupa la esquina inferior izquierda para no chocar.
 *
 * Se incluye SOLO si JORDAN_DOC_ENABLED=true y la propuesta tiene
 * enable_ai_assistant=1 (filtrado en view.php antes del include).
 */

if (!defined('JORDAN_DOC_ENABLED') || !JORDAN_DOC_ENABLED) return;

// Contexto inyectado: $proposal (array con id, slug, client_name, version)
$slug = $proposal['slug'] ?? '';
$clientFirst = explode(' ', trim($proposal['client_name'] ?? ''))[0] ?? '';
?>
<style>
/* ============================================= */
/* Jordan-doc widget — esquina inferior izquierda */
/* ============================================= */
.jd-fab {
    position: fixed; left: 1.25rem; bottom: 1.25rem; z-index: 499;
    background: var(--mint, #5dffbf); color: #000; border: none; border-radius: 999px;
    padding: .85rem 1.1rem; font-weight: 700; display: inline-flex; align-items: center;
    gap: .5rem; cursor: pointer; box-shadow: 0 6px 20px rgba(0,0,0,.35);
    font-family: inherit; font-size: .88rem;
    transition: transform .15s ease, box-shadow .15s ease;
}
.jd-fab:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,.45); }
.jd-fab .jd-fab-badge {
    background: #000; color: var(--mint, #5dffbf);
    padding: 0 .5rem; border-radius: 999px; font-size: .65rem;
    font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
}

.jd-panel {
    position: fixed; left: 1.25rem; bottom: 4.5rem; width: min(400px, 92vw);
    height: min(580px, 80vh);
    background: var(--bg-surface, #141414); color: var(--text-primary, #f5f5f5);
    border: 1px solid var(--border-base, #1f1f1f); border-radius: 16px; overflow: hidden;
    display: none; flex-direction: column; z-index: 500;
    box-shadow: 0 20px 50px rgba(0,0,0,.5);
    font-family: var(--font-body, inherit);
}
.jd-panel.open { display: flex; animation: jdFadeIn .2s ease; }
@keyframes jdFadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

.jd-head {
    padding: .9rem 1.1rem;
    border-bottom: 1px solid var(--border-base, #1f1f1f);
    display: flex; justify-content: space-between; align-items: center;
    background: var(--bg-base, #0e0e0e);
}
.jd-head-title { display: flex; align-items: center; gap: .6rem; }
.jd-head-title strong { font-family: var(--font-heading, inherit); font-size: .95rem; }
.jd-head-title small { color: var(--text-muted, #8a8a8a); font-size: .72rem; }
.jd-head-title .jd-avatar {
    width: 28px; height: 28px; border-radius: 999px;
    background: linear-gradient(135deg, var(--mint, #5dffbf), #49e6a8);
    color: #000; display: inline-flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: .8rem; flex-shrink: 0;
}
.jd-head-actions { display: flex; gap: .25rem; }
.jd-head button {
    background: transparent; border: none; color: var(--text-secondary, #b3b3b3);
    cursor: pointer; padding: .35rem; border-radius: 6px; display: inline-flex;
}
.jd-head button:hover { background: var(--bg-subtle, #191919); color: var(--text-primary, #f5f5f5); }

.jd-body {
    flex: 1; overflow-y: auto; padding: 1rem 1.1rem;
    display: flex; flex-direction: column; gap: .7rem;
    scroll-behavior: smooth;
}
.jd-msg {
    padding: .6rem .85rem; border-radius: 12px; font-size: .86rem; line-height: 1.5;
    max-width: 88%; white-space: pre-wrap; word-wrap: break-word;
}
.jd-msg.bot {
    background: var(--bg-subtle, #191919); align-self: flex-start;
    border-top-left-radius: 4px;
}
.jd-msg.user {
    background: var(--mint, #5dffbf); color: #000; align-self: flex-end;
    border-top-right-radius: 4px; font-weight: 500;
}
.jd-msg.bot strong { color: var(--mint, #5dffbf); }
.jd-msg.bot a { color: var(--mint, #5dffbf); }
.jd-msg.typing {
    background: var(--bg-subtle, #191919); align-self: flex-start;
    color: var(--text-muted, #8a8a8a); font-style: italic;
    border-top-left-radius: 4px;
}
.jd-msg.typing::after {
    content: '…'; display: inline-block; animation: jdDots 1.4s steps(4, end) infinite;
}
@keyframes jdDots { 0% { opacity: .2; } 50% { opacity: 1; } 100% { opacity: .2; } }

.jd-error {
    background: rgba(255, 107, 107, .1); color: #ff9090;
    border: 1px solid rgba(255, 107, 107, .3);
    padding: .5rem .8rem; border-radius: 8px; font-size: .8rem;
}

.jd-form {
    display: flex; gap: .5rem; padding: .85rem 1rem;
    border-top: 1px solid var(--border-base, #1f1f1f);
    background: var(--bg-base, #0e0e0e);
}
.jd-form textarea {
    flex: 1; resize: none; min-height: 40px; max-height: 120px;
    background: var(--bg-subtle, #191919); border: 1px solid var(--border-base, #1f1f1f);
    color: inherit; padding: .55rem .75rem; border-radius: 8px;
    font-family: inherit; font-size: .88rem; line-height: 1.4;
}
.jd-form textarea:focus { outline: none; border-color: var(--mint, #5dffbf); }
.jd-form button {
    background: var(--mint, #5dffbf); color: #000; border: none;
    padding: 0 1rem; border-radius: 8px; font-weight: 700; cursor: pointer;
    display: inline-flex; align-items: center; gap: .4rem;
    font-family: inherit; flex-shrink: 0;
}
.jd-form button:disabled { opacity: .5; cursor: not-allowed; }

.jd-footer-hint {
    padding: .4rem 1rem .6rem; font-size: .7rem; color: var(--text-muted, #8a8a8a);
    text-align: center; background: var(--bg-base, #0e0e0e);
}
.jd-footer-hint a { color: var(--text-secondary, #b3b3b3); cursor: pointer; text-decoration: underline; }

/* Light mode */
[data-theme="light"] .jd-panel { box-shadow: 0 20px 50px rgba(20,20,20,.15); }
[data-theme="light"] .jd-msg.bot { background: var(--bg-subtle); color: var(--text-primary); }

@media (max-width: 600px) {
    .jd-fab { padding: .7rem .9rem; font-size: .82rem; }
    .jd-panel { left: .5rem; right: .5rem; bottom: 4rem; width: auto; height: 70vh; }
}
</style>

<button class="jd-fab" id="jd-fab" type="button" aria-label="Abrir Jordan">
    <span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:999px;background:#000;color:var(--mint,#5dffbf);font-weight:800;font-size:.7rem">J</span>
    <span>Pregunta a Jordan</span>
</button>

<section class="jd-panel" id="jd-panel" role="dialog" aria-labelledby="jd-title" aria-hidden="true">
    <header class="jd-head">
        <div class="jd-head-title">
            <span class="jd-avatar">J</span>
            <div>
                <strong id="jd-title">Jordan</strong><br>
                <small>sobre este documento</small>
            </div>
        </div>
        <div class="jd-head-actions">
            <button id="jd-reset" title="Empezar de nuevo" aria-label="Reiniciar conversación">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
            </button>
            <button id="jd-close" title="Cerrar" aria-label="Cerrar">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </header>

    <div class="jd-body" id="jd-body"></div>

    <form class="jd-form" id="jd-form" autocomplete="off">
        <textarea id="jd-input" placeholder="Pregúntame algo del documento…" rows="1" required></textarea>
        <button type="submit" id="jd-send" aria-label="Enviar">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </button>
    </form>

    <div class="jd-footer-hint">
        Jordan solo habla de esta propuesta y de Tres Puntos. <a id="jd-footer-reset">Reiniciar</a>
    </div>
</section>

<script>
(function () {
    'use strict';
    if (window.__JD_LOADED__) return;
    window.__JD_LOADED__ = true;

    const CFG = {
        endpoint: <?php echo json_encode(($base_path ?? '') . '/api/jordan-doc.php'); ?>,
        slug: <?php echo json_encode($slug); ?>,
        clientFirst: <?php echo json_encode($clientFirst); ?>,
        storageKey: 'jd_session_' + <?php echo json_encode($slug); ?>,
        welcomeKey: 'jd_welcomed_' + <?php echo json_encode($slug); ?>,
    };

    const $ = (s) => document.querySelector(s);

    const body = $('#jd-body');
    const form = $('#jd-form');
    const input = $('#jd-input');
    const sendBtn = $('#jd-send');
    const panel = $('#jd-panel');
    const fab = $('#jd-fab');

    let sessionId = null;
    try {
        const stored = JSON.parse(localStorage.getItem(CFG.storageKey) || 'null');
        if (stored && stored.session_id) sessionId = stored.session_id;
    } catch (_) {}

    function esc(s) { return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    // Markdown ligero: **bold**, salto de línea, listas simples.
    function mdToHtml(text) {
        let s = esc(text);
        s = s.replace(/\*\*([^\*]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/\n/g, '<br>');
        return s;
    }

    function addMessage(role, text, opts) {
        const msg = document.createElement('div');
        msg.className = 'jd-msg ' + role + (opts && opts.extra ? ' ' + opts.extra : '');
        if (role === 'bot') msg.innerHTML = mdToHtml(text);
        else msg.textContent = text;
        body.appendChild(msg);
        body.scrollTop = body.scrollHeight;
        return msg;
    }

    function addError(text) {
        const e = document.createElement('div');
        e.className = 'jd-error';
        e.textContent = text;
        body.appendChild(e);
        body.scrollTop = body.scrollHeight;
    }

    function welcome() {
        body.innerHTML = '';
        const greeting = CFG.clientFirst
            ? `Hola ${CFG.clientFirst}. Soy Jordan. He leído la propuesta contigo — pregúntame lo que quieras sobre el documento.`
            : 'Hola. Soy Jordan. He leído la propuesta contigo — pregúntame lo que quieras sobre el documento.';
        addMessage('bot', greeting);
    }

    function openPanel() {
        panel.classList.add('open');
        panel.setAttribute('aria-hidden', 'false');
        if (!body.childElementCount) welcome();
        setTimeout(() => input.focus(), 150);
    }
    function closePanel() {
        panel.classList.remove('open');
        panel.setAttribute('aria-hidden', 'true');
    }

    async function send(text) {
        addMessage('user', text);
        input.value = '';
        input.style.height = 'auto';
        const typing = addMessage('bot', '', { extra: 'typing' });
        sendBtn.disabled = true;

        try {
            const res = await fetch(CFG.endpoint, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ slug: CFG.slug, message: text, session_id: sessionId }),
            });
            const data = await res.json().catch(() => ({}));
            typing.remove();

            if (!res.ok || !data.success) {
                addError(data.error || `Error (${res.status}). Inténtalo de nuevo.`);
                return;
            }
            sessionId = data.session_id || sessionId;
            try { localStorage.setItem(CFG.storageKey, JSON.stringify({ session_id: sessionId })); } catch(_) {}
            addMessage('bot', data.reply || '…');
        } catch (err) {
            typing.remove();
            addError('Sin conexión con el servidor.');
        } finally {
            sendBtn.disabled = false;
            input.focus();
        }
    }

    async function reset() {
        sessionId = null;
        try { localStorage.removeItem(CFG.storageKey); } catch(_) {}
        welcome();
    }

    // Autoresize textarea
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    });
    // Enter envía, shift+enter nueva línea
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const text = input.value.trim();
        if (!text) return;
        send(text);
    });

    fab.addEventListener('click', () => panel.classList.contains('open') ? closePanel() : openPanel());
    $('#jd-close').addEventListener('click', closePanel);
    $('#jd-reset').addEventListener('click', reset);
    $('#jd-footer-reset').addEventListener('click', reset);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && panel.classList.contains('open')) closePanel();
    });
})();
</script>

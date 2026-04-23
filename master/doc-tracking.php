<?php
/**
 * doc-tracking.php — Captura de analítica para view.php
 *
 * Se incluye SOLO cuando el visitante ha pasado el PIN.
 * Registra:
 *   - open / close del doc
 *   - section_view (H2 entra al viewport)
 *   - section_dwell (tiempo en cada sección, batched)
 *   - scroll_depth 25/50/75/100
 *   - presupuesto_open (tab presupuesto)
 *   - firma_open / firma_abandoned / firma_approved (modal de firma)
 *
 * Batches cada 10s + flush con sendBeacon en beforeunload.
 * Eventos rechazados en /api/track.php si no vienen con sesion_id UUID válido.
 *
 * Privacidad: sin mouse, sin keystrokes, sin grabación. Solo secciones + hitos.
 */

// Recuperar slug desde variables del contexto (view.php define $proposal, $clientName, etc.)
$trackSlug = isset($proposal['slug']) ? $proposal['slug'] : '';
if ($trackSlug === '') return;

// Si estamos en modo proveedor, enviamos el token para que track.php lea la identidad del proveedor
$trackProviderToken = '';
if (!empty($isProviderMode) && !empty($__provider['token'])) {
    $trackProviderToken = preg_replace('/[^a-f0-9]/i', '', (string)$__provider['token']);
}
?>
<script>
(function () {
    'use strict';
    var SLUG = <?= json_encode($trackSlug, JSON_UNESCAPED_SLASHES) ?>;
    var PROVIDER_TOKEN = <?= json_encode($trackProviderToken, JSON_UNESCAPED_SLASHES) ?>;
    var TRACK_URL = '/api/track.php';

    // --- Session ID (uuid v4) ---
    function uuid() {
        if (crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
    var sesionId;
    try {
        sesionId = sessionStorage.getItem('tp_track_sid');
        if (!sesionId) {
            sesionId = uuid();
            sessionStorage.setItem('tp_track_sid', sesionId);
        }
    } catch (e) { sesionId = uuid(); }

    // --- Buffer + flush ---
    var buffer = [];
    var flushTimer = null;

    function enqueue(ev) {
        buffer.push(ev);
        if (buffer.length >= 10) return flush();
        if (!flushTimer) flushTimer = setTimeout(flush, 10000);
    }

    function flush(useBeacon) {
        if (flushTimer) { clearTimeout(flushTimer); flushTimer = null; }
        if (!buffer.length) return;
        var payloadObj = {
            propuesta_slug: SLUG,
            sesion_id: sesionId,
            events: buffer,
        };
        if (PROVIDER_TOKEN) payloadObj.provider_token = PROVIDER_TOKEN;
        var payload = JSON.stringify(payloadObj);
        buffer = [];
        try {
            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon(TRACK_URL, new Blob([payload], {type: 'application/json'}));
            } else {
                fetch(TRACK_URL, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: payload,
                    credentials: 'same-origin',
                    keepalive: true,
                }).catch(function () {});
            }
        } catch (e) { /* ignore */ }
    }

    // --- Helpers ---
    function track(tipo, extra) {
        var ev = Object.assign({tipo: tipo, at: Date.now()}, extra || {});
        enqueue(ev);
    }

    // --- Open ---
    track('open');

    // --- Section tracking (IntersectionObserver) ---
    // Solo H2 raíz del documento (no dentro de callouts, cards, etc.)
    var EXCLUDE_SEL = '.tp-card, .tp-callout, .tp-timeline, .tp-comparison, .tp-sitemap, .tp-stat, .tp-tag, .team-card, .team-grid, .cta-block, table, .modal-box';

    var currentAnchor = null;
    var currentEnterTs = 0;
    var dwellByAnchor = {};
    var seenSections = {};  // anchor → true (una vez por sesión)

    function endDwell() {
        if (currentAnchor && currentEnterTs) {
            var delta = Date.now() - currentEnterTs;
            if (delta > 500) {  // ignorar micro-visits
                dwellByAnchor[currentAnchor] = (dwellByAnchor[currentAnchor] || 0) + delta;
            }
        }
        currentAnchor = null;
        currentEnterTs = 0;
    }

    function startDwell(anchor) {
        endDwell();
        currentAnchor = anchor;
        currentEnterTs = Date.now();
    }

    function flushDwellBatch() {
        // Mueve el dwell actual al acumulador
        if (currentAnchor && currentEnterTs) {
            var now = Date.now();
            var delta = now - currentEnterTs;
            if (delta > 500) dwellByAnchor[currentAnchor] = (dwellByAnchor[currentAnchor] || 0) + delta;
            currentEnterTs = now;
        }
        Object.keys(dwellByAnchor).forEach(function (anchor) {
            if (dwellByAnchor[anchor] > 0) {
                track('section_dwell', {anchor: anchor, dwell_ms: dwellByAnchor[anchor]});
                dwellByAnchor[anchor] = 0;
            }
        });
    }
    // Batch dwell cada 15s
    setInterval(flushDwellBatch, 15000);

    function initSectionObserver() {
        var area = document.getElementById('content-area') || document.body;
        var sections = Array.from(area.querySelectorAll('h2[id], section[id]')).filter(function (h) {
            return !h.closest(EXCLUDE_SEL);
        });

        if (!sections.length || !('IntersectionObserver' in window)) return;

        var io = new IntersectionObserver(function (entries) {
            // Encontrar la sección más centrada que esté visible
            var best = null, bestRatio = 0;
            entries.forEach(function (entry) {
                if (entry.isIntersecting && entry.intersectionRatio > bestRatio) {
                    best = entry.target;
                    bestRatio = entry.intersectionRatio;
                }
            });
            if (best) {
                var anchor = best.id;
                if (!seenSections[anchor]) {
                    seenSections[anchor] = true;
                    track('section_view', {anchor: anchor});
                }
                if (anchor !== currentAnchor) startDwell(anchor);
            }
        }, {
            rootMargin: '-20% 0px -40% 0px',
            threshold: [0.1, 0.3, 0.5, 0.7],
        });

        sections.forEach(function (s) { io.observe(s); });
    }

    // --- Scroll depth thresholds ---
    var scrollThresholds = [25, 50, 75, 100];
    var scrollReached = {};
    function checkScrollDepth() {
        var doc = document.documentElement;
        var body = document.body;
        var scrollTop = window.pageYOffset || doc.scrollTop || body.scrollTop;
        var scrollHeight = Math.max(doc.scrollHeight, body.scrollHeight) - window.innerHeight;
        if (scrollHeight < 100) return;
        var percent = Math.round((scrollTop / scrollHeight) * 100);
        scrollThresholds.forEach(function (t) {
            if (percent >= t && !scrollReached[t]) {
                scrollReached[t] = true;
                track('scroll_depth_' + t, {scroll_depth: t});
            }
        });
    }
    var scrollRaf = null;
    window.addEventListener('scroll', function () {
        if (scrollRaf) return;
        scrollRaf = requestAnimationFrame(function () {
            scrollRaf = null;
            checkScrollDepth();
        });
    }, {passive: true});

    // --- Hitos de conversión: tabs + modales ---
    function hookConversionEvents() {
        // Tab presupuesto → click en botón con data-tab="presupuesto"
        document.body.addEventListener('click', function (e) {
            var tabBtn = e.target.closest('[data-tab="presupuesto"]');
            if (tabBtn) track('presupuesto_open');
        });

        // Detectar apertura de modal de firma (buscamos data-modal="sign-doc" o similar)
        // Nota: depende de la estructura del modal en view.php. Si no matchea, no pasa nada.
        var signOpened = false;
        document.body.addEventListener('click', function (e) {
            var signBtn = e.target.closest('[data-action="sign-doc"], [data-action="sign-pdf"], .btn-sign-document, .btn-sign-presupuesto, [onclick*="approveDoc"], [onclick*="approvePdf"]');
            if (signBtn) {
                track('firma_open', {meta: {btn: signBtn.className || signBtn.dataset.action || 'unknown'}});
                signOpened = true;
            }
        });

        // Si se cierra el modal sin completar firma → firma_abandoned
        // Escucha eventos custom si los hay, o detecta "cerrar" en el modal
        window.addEventListener('tp-sign-abandoned', function () {
            if (signOpened) { track('firma_abandoned'); signOpened = false; }
        });
        window.addEventListener('tp-sign-approved', function () {
            if (signOpened) { track('firma_approved'); signOpened = false; }
        });
    }

    // --- Unload: flush con beacon ---
    function onUnload() {
        endDwell();
        flushDwellBatch();
        track('close');
        flush(true);  // beacon
    }
    window.addEventListener('beforeunload', onUnload);
    window.addEventListener('pagehide', onUnload);
    // Visibility change → si se va a background >30s, flush preventivo
    var hiddenSince = 0;
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            hiddenSince = Date.now();
            endDwell();
            flushDwellBatch();
            flush();  // fetch normal, keepalive
        } else {
            if (hiddenSince && (Date.now() - hiddenSince) > 30000) {
                // Consideramos que volvió de una pausa larga → nueva sesión lógica
                // (mantenemos sesion_id pero reiniciamos el dwell tracking)
            }
            hiddenSince = 0;
        }
    });

    // --- Init ---
    function init() {
        initSectionObserver();
        hookConversionEvents();
        checkScrollDepth();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

/* ============================================================================
 * Atlas · Módulo AUTH (PIN 4 dígitos + WebAuthn/FaceID)
 * ----------------------------------------------------------------------------
 * Autocontenido, vanilla JS, sin build. Bloquea la app hasta desbloqueo.
 *
 * Modelo de seguridad (un solo usuario: Jordi):
 *   - PIN de 4 dígitos: red de seguridad SIEMPRE disponible. Se guarda como
 *     hash PBKDF2-SHA256 (150k iteraciones) + salt aleatorio en localStorage.
 *     Nunca se guarda el PIN en claro. La verificación es en cliente.
 *   - WebAuthn (FaceID/TouchID): capa biométrica POR ENCIMA del PIN, usando el
 *     autenticador de PLATAFORMA (no llaves externas). Alta y desbloqueo.
 *     El PIN queda como fallback si el biométrico falla o no está disponible.
 *
 * IMPORTANTE: esto es protección de acceso local a una PWA personal, no un
 * sistema de identidad federada. No sustituye al token de la API (ese lo añade
 * el backend de Jordan). Ver auth.integracion.md.
 *
 * API pública (window.AtlasAuth):
 *   AtlasAuth.isEnrolled()            -> bool   (¿hay PIN dado de alta?)
 *   AtlasAuth.isBiometricEnrolled()   -> bool
 *   AtlasAuth.isBiometricSupported()  -> Promise<bool>
 *   AtlasAuth.enrollPin(pin)          -> Promise<void>
 *   AtlasAuth.verifyPin(pin)          -> Promise<bool>
 *   AtlasAuth.enrollBiometric()       -> Promise<bool>   (tras desbloqueo)
 *   AtlasAuth.unlockBiometric()       -> Promise<bool>
 *   AtlasAuth.lock()                  -> void
 *   AtlasAuth.isUnlocked()            -> bool   (sesión en memoria)
 *   AtlasAuth.requireUnlock(opts)     -> Promise<void>  (monta la pantalla)
 *   AtlasAuth.reset()                 -> void   (borra credenciales locales)
 * ========================================================================== */
(function (global) {
  'use strict';

  // --- Claves de almacenamiento -------------------------------------------
  var LS_PIN = 'atlas.auth.pin.v1';          // { saltB64, hashB64, iter }
  var LS_WEBAUTHN = 'atlas.auth.webauthn.v1'; // { credentialIdB64, createdAt }
  var SS_UNLOCKED = 'atlas.auth.unlocked';    // sesión (sessionStorage)

  var PIN_LENGTH = 4;
  var PBKDF2_ITER = 150000;

  // ------------------------------------------------------------------------
  // Utilidades base64url <-> ArrayBuffer
  // ------------------------------------------------------------------------
  function bufToB64(buf) {
    var bytes = new Uint8Array(buf), bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin);
  }
  function b64ToBuf(b64) {
    var bin = atob(b64), bytes = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return bytes.buffer;
  }

  // ------------------------------------------------------------------------
  // PIN — derivación de clave con PBKDF2 (WebCrypto)
  // ------------------------------------------------------------------------
  function derivePin(pin, saltBuf, iter) {
    var enc = new TextEncoder();
    return crypto.subtle.importKey('raw', enc.encode(pin), { name: 'PBKDF2' }, false, ['deriveBits'])
      .then(function (key) {
        return crypto.subtle.deriveBits(
          { name: 'PBKDF2', salt: saltBuf, iterations: iter, hash: 'SHA-256' },
          key, 256
        );
      });
  }

  function enrollPin(pin) {
    if (!/^\d{4}$/.test(pin)) return Promise.reject(new Error('El PIN debe tener 4 dígitos'));
    var salt = crypto.getRandomValues(new Uint8Array(16));
    return derivePin(pin, salt.buffer, PBKDF2_ITER).then(function (hashBuf) {
      localStorage.setItem(LS_PIN, JSON.stringify({
        saltB64: bufToB64(salt.buffer),
        hashB64: bufToB64(hashBuf),
        iter: PBKDF2_ITER
      }));
    });
  }

  function verifyPin(pin) {
    var raw = localStorage.getItem(LS_PIN);
    if (!raw) return Promise.resolve(false);
    var rec;
    try { rec = JSON.parse(raw); } catch (e) { return Promise.resolve(false); }
    return derivePin(pin, b64ToBuf(rec.saltB64), rec.iter || PBKDF2_ITER).then(function (hashBuf) {
      // comparación en tiempo (aprox.) constante
      var a = new Uint8Array(hashBuf), b = new Uint8Array(b64ToBuf(rec.hashB64));
      if (a.length !== b.length) return false;
      var diff = 0;
      for (var i = 0; i < a.length; i++) diff |= a[i] ^ b[i];
      if (diff === 0) { markUnlocked(); return true; }
      return false;
    });
  }

  function isEnrolled() { return !!localStorage.getItem(LS_PIN); }

  // ------------------------------------------------------------------------
  // WebAuthn — autenticador de plataforma (FaceID / TouchID)
  // ------------------------------------------------------------------------
  function isBiometricSupported() {
    if (!global.PublicKeyCredential ||
        !PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
      return Promise.resolve(false);
    }
    return PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
      .catch(function () { return false; });
  }

  function isBiometricEnrolled() { return !!localStorage.getItem(LS_WEBAUTHN); }

  function rpId() {
    // En localhost WebAuthn permite rpId 'localhost'. En prod = dominio exacto.
    return location.hostname;
  }

  // Alta del biométrico. Solo debería llamarse cuando la app YA está
  // desbloqueada (con PIN), para vincular el dispositivo a Atlas.
  function enrollBiometric() {
    return isBiometricSupported().then(function (ok) {
      if (!ok) throw new Error('Este dispositivo no soporta biometría de plataforma');
      var challenge = crypto.getRandomValues(new Uint8Array(32));
      var userId = crypto.getRandomValues(new Uint8Array(16));
      var opts = {
        publicKey: {
          challenge: challenge,
          rp: { name: 'Atlas', id: rpId() },
          user: { id: userId, name: 'jordi', displayName: 'Jordi' },
          pubKeyCredParams: [
            { type: 'public-key', alg: -7 },   // ES256
            { type: 'public-key', alg: -257 }  // RS256
          ],
          authenticatorSelection: {
            authenticatorAttachment: 'platform',
            userVerification: 'required',
            residentKey: 'preferred'
          },
          timeout: 60000,
          attestation: 'none'
        }
      };
      return navigator.credentials.create(opts).then(function (cred) {
        localStorage.setItem(LS_WEBAUTHN, JSON.stringify({
          credentialIdB64: bufToB64(cred.rawId),
          createdAt: Date.now()
        }));
        return true;
      });
    });
  }

  // Desbloqueo biométrico. Verificación local: que el SO confirme al usuario
  // (FaceID/TouchID) sobre la credencial dada de alta. No hay servidor que
  // valide la firma — es gating de acceso local a la PWA personal.
  function unlockBiometric() {
    var raw = localStorage.getItem(LS_WEBAUTHN);
    if (!raw) return Promise.resolve(false);
    var rec;
    try { rec = JSON.parse(raw); } catch (e) { return Promise.resolve(false); }
    var challenge = crypto.getRandomValues(new Uint8Array(32));
    var opts = {
      publicKey: {
        challenge: challenge,
        rpId: rpId(),
        allowCredentials: [{
          type: 'public-key',
          id: b64ToBuf(rec.credentialIdB64),
          transports: ['internal']
        }],
        userVerification: 'required',
        timeout: 60000
      }
    };
    return navigator.credentials.get(opts).then(function (assertion) {
      if (assertion) { markUnlocked(); return true; }
      return false;
    }).catch(function () { return false; });
  }

  // ------------------------------------------------------------------------
  // Estado de sesión (en memoria + sessionStorage para sobrevivir reload)
  // ------------------------------------------------------------------------
  var _unlocked = false;
  function markUnlocked() {
    _unlocked = true;
    try { sessionStorage.setItem(SS_UNLOCKED, '1'); } catch (e) {}
  }
  function isUnlocked() {
    if (_unlocked) return true;
    try { return sessionStorage.getItem(SS_UNLOCKED) === '1'; } catch (e) { return false; }
  }
  function lock() {
    _unlocked = false;
    try { sessionStorage.removeItem(SS_UNLOCKED); } catch (e) {}
  }
  function reset() {
    localStorage.removeItem(LS_PIN);
    localStorage.removeItem(LS_WEBAUTHN);
    lock();
  }

  // ------------------------------------------------------------------------
  // Pantalla de bloqueo (UI). Inyecta su propio CSS scoped (.atlas-lock).
  // requireUnlock() resuelve la promesa SOLO cuando se desbloquea.
  // ------------------------------------------------------------------------
  var CSS = [
    '.atlas-lock{position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;',
    'align-items:center;justify-content:center;font-family:var(--font,-apple-system,"SF Pro Display",Inter,system-ui,sans-serif);',
    'background:radial-gradient(120% 55% at 50% -8%,var(--screen1,rgba(0,230,160,.10)),transparent 60%),var(--bg,#070809);color:var(--tx,#F4F5F7);',
    'animation:atlas-fade .35s ease}',
    '@keyframes atlas-fade{from{opacity:0}to{opacity:1}}',
    '@keyframes atlas-shake{10%,90%{transform:translateX(-2px)}20%,80%{transform:translateX(4px)}30%,50%,70%{transform:translateX(-8px)}40%,60%{transform:translateX(8px)}}',
    '.atlas-lock .al-logo{width:54px;height:54px;border-radius:16px;background:linear-gradient(145deg,var(--mint,#00E6A0),var(--teal,#34D6C8));',
    'display:flex;align-items:center;justify-content:center;font-weight:800;color:#04241b;font-size:26px;margin-bottom:20px}',
    '.atlas-lock .al-title{font-size:21px;font-weight:700;letter-spacing:-.03em}',
    '.atlas-lock .al-sub{font-size:13px;color:var(--tx2,#9A9CA4);margin-top:6px;min-height:18px;text-align:center;padding:0 32px}',
    '.atlas-lock .al-dots{display:flex;gap:16px;margin:30px 0 26px}',
    '.atlas-lock .al-dot{width:15px;height:15px;border-radius:50%;border:2px solid var(--tx3,#62646B);transition:.18s}',
    '.atlas-lock .al-dot.on{background:var(--mint,#00E6A0);border-color:var(--mint,#00E6A0);box-shadow:0 0 10px var(--mint,#00E6A0)}',
    '.atlas-lock .al-dots.err{animation:atlas-shake .5s}',
    '.atlas-lock .al-dots.err .al-dot{border-color:var(--red,#FF5A6A)}',
    '.atlas-lock .al-pad{display:grid;grid-template-columns:repeat(3,76px);gap:18px}',
    '.atlas-lock .al-key{height:76px;border-radius:50%;border:1px solid var(--hair,rgba(255,255,255,.08));background:var(--card,rgba(255,255,255,.04));',
    'color:var(--tx,#F4F5F7);font-size:28px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;',
    'transition:transform .1s,background .15s;-webkit-tap-highlight-color:transparent;user-select:none}',
    '.atlas-lock .al-key:active{transform:scale(.93);background:var(--card-h,rgba(255,255,255,.07))}',
    '.atlas-lock .al-key.ghost{background:transparent;border:none;cursor:default}',
    '.atlas-lock .al-key svg{width:26px;height:26px;stroke:var(--tx2,#9A9CA4);fill:none;stroke-width:2}',
    '.atlas-lock .al-bio{margin-top:26px;display:flex;align-items:center;gap:9px;background:var(--card,rgba(255,255,255,.04));',
    'border:1px solid var(--hair,rgba(255,255,255,.08));color:var(--tx,#F4F5F7);font-size:14px;font-weight:600;padding:12px 20px;border-radius:30px;cursor:pointer}',
    '.atlas-lock .al-bio svg{width:19px;height:19px;stroke:var(--mint,#00E6A0);fill:none;stroke-width:2}',
    '.atlas-lock .al-bio[hidden]{display:none}'
  ].join('');

  var FACE_SVG = '<svg viewBox="0 0 24 24"><path d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2"/><path d="M9 10h.01M15 10h.01M9 15c.8.7 1.9 1 3 1s2.2-.3 3-1"/></svg>';
  var DEL_SVG = '<svg viewBox="0 0 24 24"><path d="M21 5H8l-5 7 5 7h13a1 1 0 001-1V6a1 1 0 00-1-1z"/><path d="M15 9l-4 4M11 9l4 4"/></svg>';

  function injectCss() {
    if (document.getElementById('atlas-auth-css')) return;
    var s = document.createElement('style');
    s.id = 'atlas-auth-css';
    s.textContent = CSS;
    document.head.appendChild(s);
  }

  // opts: { mode:'unlock'|'enroll', onUnlocked:fn, allowReset:bool }
  function mountLockScreen(opts) {
    opts = opts || {};
    injectCss();
    var enrolling = !isEnrolled() || opts.mode === 'enroll';
    var firstPin = null; // para confirmación en alta

    // Textos/branding configurables (retrocompatible: fallback a los de Atlas).
    var LOGO   = opts.logo != null ? opts.logo : 'A';
    var T_LOCK = opts.title || 'Atlas bloqueado';
    var S_LOCK = opts.subtitle || 'Introduce tu PIN para continuar';
    var T_NEW  = opts.enrollTitle || 'Crea tu PIN';
    var S_NEW  = opts.enrollSubtitle || 'Elige un PIN de 4 dígitos';

    var root = document.createElement('div');
    root.className = 'atlas-lock';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.innerHTML =
      '<div class="al-logo">' + LOGO + '</div>' +
      '<div class="al-title">' + (enrolling ? T_NEW : T_LOCK) + '</div>' +
      '<div class="al-sub" id="al-sub">' + (enrolling ? S_NEW : S_LOCK) + '</div>' +
      '<div class="al-dots" id="al-dots">' +
        '<div class="al-dot"></div><div class="al-dot"></div><div class="al-dot"></div><div class="al-dot"></div>' +
      '</div>' +
      '<div class="al-pad" id="al-pad"></div>' +
      '<button class="al-bio" id="al-bio" hidden>' + FACE_SVG + 'Usar Face ID</button>';

    var sub = root.querySelector('#al-sub');
    var dotsEl = root.querySelector('#al-dots');
    var dots = root.querySelectorAll('.al-dot');
    var pad = root.querySelector('#al-pad');
    var bioBtn = root.querySelector('#al-bio');
    var buffer = '';

    function render() {
      for (var i = 0; i < 4; i++) dots[i].classList.toggle('on', i < buffer.length);
    }
    function fail(msg) {
      dotsEl.classList.add('err');
      sub.textContent = msg;
      if (navigator.vibrate) try { navigator.vibrate(60); } catch (e) {}
      setTimeout(function () { dotsEl.classList.remove('err'); buffer = ''; render(); }, 550);
    }
    function done() {
      root.style.transition = 'opacity .3s'; root.style.opacity = '0';
      setTimeout(function () {
        if (root.parentNode) root.parentNode.removeChild(root);
        if (typeof opts.onUnlocked === 'function') opts.onUnlocked();
      }, 300);
    }

    // Tras desbloquear/crear PIN: si hay biometria de plataforma y NO esta dada
    // de alta, ofrecer activar Face ID (registro requiere gesto del usuario).
    function finishUnlock() {
      if (isBiometricEnrolled()) { done(); return; }
      isBiometricSupported().then(function (ok) {
        if (!ok) { done(); return; }
        if (pad) pad.style.display = 'none';
        if (dotsEl) dotsEl.style.display = 'none';
        var titleEl = root.querySelector('.al-title');
        if (titleEl) titleEl.textContent = 'Activa Face ID';
        sub.textContent = 'Entra con tu cara la proxima vez';
        bioBtn.hidden = false;
        bioBtn.innerHTML = FACE_SVG + 'Activar Face ID';
        var skip = document.createElement('div');
        skip.textContent = 'Ahora no';
        skip.style.cssText = 'margin-top:16px;color:var(--tx2,#9A9CA4);font-size:14px;cursor:pointer';
        skip.addEventListener('click', function () { done(); });
        bioBtn.parentNode.appendChild(skip);
        bioBtn.addEventListener('click', function () {
          sub.textContent = 'Mira tu iPhone...';
          enrollBiometric().then(function () {
            sub.textContent = 'Face ID activado!';
            if (navigator.vibrate) { try { navigator.vibrate(20); } catch (e) {} }
            setTimeout(function () { done(); }, 700);
          }).catch(function () {
            sub.textContent = 'No se pudo activar. Sigues con PIN';
            setTimeout(function () { done(); }, 1400);
          });
        });
      });
    }

    function commit() {
      if (enrolling) {
        if (firstPin === null) {
          firstPin = buffer; buffer = ''; render();
          sub.textContent = 'Repite el PIN para confirmar';
        } else if (firstPin === buffer) {
          enrollPin(buffer).then(function () { markUnlocked(); finishUnlock(); });
        } else {
          firstPin = null; fail('No coincide. Empieza de nuevo');
        }
      } else {
        verifyPin(buffer).then(function (ok) {
          if (ok) finishUnlock(); else fail('PIN incorrecto');
        });
      }
    }

    function press(d) {
      if (buffer.length >= PIN_LENGTH) return;
      buffer += d; render();
      if (navigator.vibrate) try { navigator.vibrate(8); } catch (e) {}
      if (buffer.length === PIN_LENGTH) setTimeout(commit, 120);
    }
    function backspace() { buffer = buffer.slice(0, -1); render(); }

    // teclado numérico 1..9, [Face/0/Del]
    var keys = ['1','2','3','4','5','6','7','8','9','bio','0','del'];
    keys.forEach(function (k) {
      var el = document.createElement('div');
      if (k === 'bio') {
        // tecla inferior izquierda: atajo biométrico (si está dado de alta)
        if (!enrolling && isBiometricEnrolled()) {
          el.className = 'al-key'; el.innerHTML = FACE_SVG;
          el.addEventListener('click', tryBiometric);
        } else { el.className = 'al-key ghost'; }
      } else if (k === 'del') {
        el.className = 'al-key'; el.innerHTML = DEL_SVG;
        el.addEventListener('click', backspace);
      } else {
        el.className = 'al-key'; el.textContent = k;
        el.addEventListener('click', function () { press(k); });
      }
      pad.appendChild(el);
    });

    // soporte de teclado físico (desktop / pruebas)
    function onKey(e) {
      if (e.key >= '0' && e.key <= '9') press(e.key);
      else if (e.key === 'Backspace') backspace();
    }
    document.addEventListener('keydown', onKey);
    var _done = done;
    done = function () { document.removeEventListener('keydown', onKey); _done(); };

    function tryBiometric() {
      sub.textContent = 'Esperando Face ID…';
      unlockBiometric().then(function (ok) {
        if (ok) done(); else sub.textContent = 'Face ID no reconocido. Usa el PIN';
      });
    }

    // En desbloqueo: si hay biométrico, ofrecerlo y lanzarlo automáticamente
    if (!enrolling && isBiometricEnrolled()) {
      bioBtn.hidden = false;
      bioBtn.addEventListener('click', tryBiometric);
      if (opts.autoBiometric !== false) setTimeout(tryBiometric, 350);
    }

    render();
    document.body.appendChild(root);
    return root;
  }

  // Promesa que se resuelve al desbloquear. Atajo principal para la app.
  function requireUnlock(opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (isUnlocked() && opts.force !== true) { resolve(); return; }
      mountLockScreen({
        mode: opts.mode,
        autoBiometric: opts.autoBiometric,
        logo: opts.logo,
        title: opts.title,
        subtitle: opts.subtitle,
        enrollTitle: opts.enrollTitle,
        enrollSubtitle: opts.enrollSubtitle,
        onUnlocked: resolve
      });
    });
  }

  global.AtlasAuth = {
    isEnrolled: isEnrolled,
    isBiometricEnrolled: isBiometricEnrolled,
    isBiometricSupported: isBiometricSupported,
    enrollPin: enrollPin,
    verifyPin: verifyPin,
    enrollBiometric: enrollBiometric,
    unlockBiometric: unlockBiometric,
    lock: lock,
    isUnlocked: isUnlocked,
    requireUnlock: requireUnlock,
    reset: reset,
    mountLockScreen: mountLockScreen
  };
})(window);

<?php
/**
 * master/admin-faceid.php
 * --------------------------------------------------------------------------
 * Candado biométrico LOCAL (PIN + Face ID / Touch ID vía WebAuthn) sobre el
 * panel admin. Es una capa de CONVENIENCIA en el dispositivo de quien entra,
 * NO autenticación de servidor: la seguridad real sigue siendo ADMIN_PASSWORD
 * + la sesión PHP. Si el módulo no carga, falla en abierto (no bloquea nada).
 *
 * Reutiliza master/atlas-auth.js (módulo del repo TresPuntos/atlas-auth).
 * Solo se renderiza cuando hay sesión admin activa, así que en la pantalla de
 * login por contraseña no aparece. Incluir justo después del <body> de cada
 * vista admin. Idempotente: si se incluye dos veces, solo actúa la primera.
 */
if (!isset($__TP_FACEID_DONE)):
    $__tp_admin_authed =
        (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true) ||
        (isset($_SESSION['is_admin'])     && $_SESSION['is_admin']     === true);
    if ($__tp_admin_authed):
        $__TP_FACEID_DONE = true;
?>
<style>
  /* Paleta Tres Puntos (dark) para la pantalla de bloqueo de atlas-auth */
  .atlas-lock{
    --mint:#5dffbf; --teal:#49e6a8;
    --bg:#0e0e0e; --tx:#f5f5f5; --tx2:#b3b3b3; --tx3:#8a8a8a;
    --card:rgba(255,255,255,.04); --card-h:rgba(255,255,255,.07);
    --hair:rgba(255,255,255,.10); --red:#ff5a6a;
    --screen1:rgba(93,255,191,.10);
    --font:'Plus Jakarta Sans','Inter',system-ui,-apple-system,sans-serif;
  }
  /* Oculta el panel hasta desbloquear (la propia pantalla de bloqueo queda visible).
     Se activa por JS, así que sin JS o si el módulo no carga, no oculta nada. */
  html.tp-faceid-pending body > *:not(.atlas-lock){ visibility:hidden !important; }
</style>
<script src="master/atlas-auth.js"></script>
<script>
(function(){
  var H = document.documentElement;
  // Si el módulo no cargó (404, bloqueo, etc.) → fail-open: no tocamos nada.
  if (!window.AtlasAuth || !window.AtlasAuth.requireUnlock) return;
  H.classList.add('tp-faceid-pending');
  function reveal(){ H.classList.remove('tp-faceid-pending'); }
  function boot(){
    try {
      AtlasAuth.requireUnlock({
        logo: 'TP',
        title: 'Panel bloqueado',
        subtitle: 'Introduce tu PIN para entrar',
        enrollTitle: 'Crea tu PIN',
        enrollSubtitle: 'Elige un PIN de 4 dígitos · Panel Tres Puntos'
      }).then(reveal, reveal);
    } catch (e) { reveal(); }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  // Nota: NO re-bloqueamos en 'visibilitychange'. Este panel es multipágina
  // server-rendered, y navegar entre vistas oculta la página → re-bloquearía y
  // pediría desbloqueo en CADA carga. El estado vive en sessionStorage, así que
  // queda desbloqueado durante toda la sesión del navegador (re-pide en pestaña
  // o sesión nueva). El re-bloqueo en segundo plano solo aplica a PWAs SPA.
})();
</script>
<?php
    endif;
endif;

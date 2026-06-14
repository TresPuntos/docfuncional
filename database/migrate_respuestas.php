<?php
/**
 * Migración — respuestas del cliente dentro del documento funcional.
 *
 * Las "cajas de respuesta" se declaran en el HTML del documento (componente
 * <div class="tp-respuesta" data-respuesta-key="...">) y permiten al cliente
 * escribir texto libre y guardarlo. Pensado para bloques de "dudas" donde
 * queremos que el cliente conteste por escrito dentro del propio documento.
 *
 * El cliente puede editar y volver a guardar (upsert por propuesta+key).
 * Al guardar se envía notificación Telegram al equipo Tres Puntos.
 *
 * NOTA: view.php también crea esta tabla con CREATE TABLE IF NOT EXISTS la
 * primera vez que se sincronizan respuestas, así que el despliegue no requiere
 * correr esta migración a mano. Se mantiene por consistencia del repo.
 *
 * Idempotente: se puede ejecutar varias veces sin romper nada.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS propuesta_respuestas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        propuesta_id INTEGER NOT NULL,
        respuesta_key TEXT NOT NULL,
        pregunta TEXT,
        respuesta_texto TEXT,
        autor_nombre TEXT,
        autor_email TEXT,
        orden INTEGER DEFAULT 0,
        updated_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(propuesta_id, respuesta_key),
        FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE CASCADE
    )
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_respuestas_propuesta ON propuesta_respuestas(propuesta_id)");

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración respuestas aplicada:\n";
echo "+ propuesta_respuestas (IF NOT EXISTS)\n";
echo "+ índice propuesta_id\n";

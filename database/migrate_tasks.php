<?php
/**
 * Migración — tareas para el cliente dentro del documento funcional.
 *
 * Las tareas se declaran en el HTML del documento (componente <div class="tp-tasks">)
 * y se sincronizan con esta tabla la primera vez que un visitante carga la página.
 * El cliente puede marcar cada tarea como completada (con nombre + email + comentario).
 * Al completar se envía notificación Telegram al equipo Tres Puntos.
 *
 * Idempotente: se puede ejecutar varias veces sin romper nada.
 */

require __DIR__ . '/../config.php';
$pdo = getDBConnection();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS propuesta_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        propuesta_id INTEGER NOT NULL,
        task_key TEXT NOT NULL,
        titulo TEXT NOT NULL,
        descripcion TEXT,
        asignado_a TEXT,
        orden INTEGER DEFAULT 0,
        completado INTEGER DEFAULT 0,
        completado_at DATETIME,
        completado_por_nombre TEXT,
        completado_por_email TEXT,
        comentario_completado TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(propuesta_id, task_key),
        FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE CASCADE
    )
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_tasks_propuesta ON propuesta_tasks(propuesta_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_tasks_estado ON propuesta_tasks(propuesta_id, completado)");

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "Migración tasks aplicada:\n";
echo "+ propuesta_tasks (IF NOT EXISTS)\n";
echo "+ índices propuesta_id, (propuesta_id, completado)\n";

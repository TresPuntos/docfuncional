<?php
/**
 * Script de inicialización de la base de datos SQLite.
 * ADVERTENCIA: Este script debe ser eliminado o protegido después de su uso en producción.
 */

// Referenciar el archivo de configuración para obtener el helper PDO
require_once __DIR__ . '/config.php';

echo "Iniciando instalación de base de datos...<br>\n";

try {
    // Obtener conexión y crear directorio si es necesario (manejado en config.php)
    $pdo = getDBConnection();

    // Crear tabla principal `propuestas`
    // Campos:
    // id (int, auto incremental)
    // slug (texto, unico) - sirve para la URL
    // client_name (texto)
    // pin (texto) 
    // html_content (texto)
    // views_count (int, default 0)
    // status (int, 1 = activa, 0 = inactiva)
    // last_accessed_at (timestamp)
    // created_at (timestamp, auto default)
    $sql = "
    CREATE TABLE IF NOT EXISTS propuestas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        client_name TEXT NOT NULL,
        pin TEXT NOT NULL,
        html_content TEXT,
        views_count INTEGER DEFAULT 0,
        status INTEGER DEFAULT 1,
        last_accessed_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        sent_date DATE,
        version TEXT DEFAULT 'v1.0',
        equipo_ids TEXT DEFAULT '[]',
        presupuesto_pdf TEXT DEFAULT NULL
    );
    ";

    $pdo->exec($sql);
    echo "Tabla 'propuestas' creada correctamente (o ya existía).<br>\n";

    $sql_history = "
    CREATE TABLE IF NOT EXISTS propuestas_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        propuesta_id INTEGER NOT NULL,
        version TEXT NOT NULL,
        html_content TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE CASCADE
    );
    ";
    $pdo->exec($sql_history);
    echo "Tabla 'propuestas_history' creada correctamente (o ya existía).<br>\n";

    // Tabla de aprobaciones (documento funcional y presupuesto)
    $sql_aprobaciones = "
    CREATE TABLE IF NOT EXISTS aprobaciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        propuesta_id INTEGER NOT NULL,
        tipo TEXT NOT NULL,  -- 'documento_funcional' o 'presupuesto'
        ip_address TEXT,
        aprobado_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE CASCADE
    );
    ";
    $pdo->exec($sql_aprobaciones);
    echo "Tabla 'aprobaciones' creada correctamente (o ya existía).<br>\n";

    // Tabla de feedback/rechazo de presupuesto
    $sql_feedback = "
    CREATE TABLE IF NOT EXISTS feedback_presupuesto (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        propuesta_id INTEGER NOT NULL,
        tipo_accion TEXT NOT NULL DEFAULT 'presupuesto_rechazado_o_cambios',
        comentario TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(propuesta_id) REFERENCES propuestas(id) ON DELETE CASCADE
    );
    ";
    $pdo->exec($sql_feedback);
    echo "Tabla 'feedback_presupuesto' creada correctamente (o ya existía).<br>\n";

    // Migración: Añadir columnas faltantes si ya existe la tabla
    $missing_cols = [
        "sent_date DATE",
        "version TEXT DEFAULT 'v1.0'",
        "equipo_ids TEXT DEFAULT '[]'",
        "presupuesto_pdf TEXT DEFAULT NULL"
    ];

    foreach ($missing_cols as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try {
            $pdo->exec("ALTER TABLE propuestas ADD COLUMN $col_def");
            echo "Columna '$col_name' añadida a 'propuestas'.<br>\n";
        }
        catch (Exception $e) {
            // Ignorar si ya existe
        }
    }

    // Opcional: Insertar dato mockeado para pruebas
    // Primero, comprobar si ya existe para no duplicar en re-ejecuciones
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM propuestas WHERE slug = ?");
    $stmt->execute(['nextica-test']);

    if ($stmt->fetchColumn() == 0) {
        $insert = "INSERT INTO propuestas (slug, client_name, pin, html_content) VALUES (:slug, :name, :pin, :html)";
        $stmtInsert = $pdo->prepare($insert);
        $stmtInsert->execute([
            ':slug' => 'nextica-test',
            ':name' => 'Nextica Law & Tax',
            ':pin' => '2026',
            ':html' => '<h1>Mock Proposal</h1><p>Contenido inicial de prueba.</p>'
        ]);
        echo "Dato mock ('nextica-test') insertado correctamente para pruebas iniciales.<br>\n";
    }
    else {
        echo "Dato mock ('nextica-test') ya existente, omitiendo inserción.<br>\n";
    }

    // Tabla de Equipo (NUEVO)
    $sql_equipo = "
    CREATE TABLE IF NOT EXISTS equipo (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT NOT NULL,
        cargo TEXT,
        descripcion TEXT,
        foto_url TEXT,
        orden INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    ";
    $pdo->exec($sql_equipo);
    echo "Tabla 'equipo' creada correctamente (o ya existía).<br>\n";

    // Insertar miembros mock si la tabla está vacía
    $stmtTeam = $pdo->query("SELECT COUNT(*) FROM equipo");
    if ($stmtTeam->fetchColumn() == 0) {
        $insertTeam = "INSERT INTO equipo (nombre, cargo, descripcion, orden) VALUES (?, ?, ?, ?)";
        $stmtInsertTeam = $pdo->prepare($insertTeam);
        $stmtInsertTeam->execute(['Jordi TresPuntos', 'Lead Designer', 'Estratega digital con más de 15 años de experiencia.', 1]);
        $stmtInsertTeam->execute(['Carlos Dev', 'Full Stack Developer', 'Especialista en arquitecturas escalables y PHP.', 2]);
        echo "Dato mock para Equipo insertado correctamente.<br>\n";
    }

    echo "<strong>¡Inicialización de la base de datos completada con éxito!</strong>";

}
catch (PDOException $e) {
    echo "Error crítico al inicializar la base de datos: " . $e->getMessage() . "<br>\n";
}
?>
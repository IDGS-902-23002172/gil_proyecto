<?php
// ============================================================
// API REST - Lectura de Temperatura
// Motor BD: SQLite 3 (sin servidor)
// Compatible con: XAMPP (Windows/Linux) y PHP >= 7.4
//
// Endpoints disponibles:
//   GET  /api.php             → Lista todas las lecturas (últimas 100)
//   GET  /api.php?id=5        → Lectura específica por ID
//   GET  /api.php?desde=FECHA → Lecturas desde una fecha (Y-m-d H:i:s)
//   POST /api.php             → Insertar nueva lectura
//
// Formato POST (JSON):
//   { "temperatura": 24.5, "fuente": "arduino" }
//
// Respuestas: JSON con cabecera Content-Type: application/json
// ============================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");                  // CORS abierto (ajustar en producción)
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Pre-flight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Configuración ---
define('DB_PATH', __DIR__ . '/temperatura.db');

// --- Conexión SQLite ---
function getDB(): PDO {
    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Crear tabla si no existe (útil si la BD se creó vacía)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS lecturas (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                temperatura REAL     NOT NULL,
                unidad      TEXT     NOT NULL DEFAULT 'C',
                fuente      TEXT     NOT NULL DEFAULT 'arduino',
                timestamp   DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS configuracion (
                id        INTEGER PRIMARY KEY CHECK (id = 1),
                umbral    REAL    NOT NULL DEFAULT 30.0,
                updated_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
            )
        ");
        // Insertar fila única de configuración si no existe
        $pdo->exec("INSERT OR IGNORE INTO configuracion (id, umbral) VALUES (1, 30.0)");
        return $pdo;
    } catch (PDOException $e) {
        responder(500, ['error' => 'No se pudo conectar a la base de datos: ' . $e->getMessage()]);
        exit;
    }
}

// --- Función de respuesta unificada ---
function responder(int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// --- Router principal ---
$metodo = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ============================================================
// GET /api.php?umbral=1  → devuelve el umbral actual
// POST /api.php?umbral=1 → actualiza el umbral
// ============================================================
if (isset($_GET['umbral'])) {
    if ($metodo === 'GET') {
        $fila = $db->query("SELECT umbral, updated_at FROM configuracion WHERE id = 1")->fetch();
        responder(200, ['status' => 'ok', 'umbral' => (float) $fila['umbral'], 'updated_at' => $fila['updated_at']]);

    } elseif ($metodo === 'POST') {
        $body = file_get_contents('php://input');
        $json = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($json['umbral'])) {
            responder(400, ['status' => 'error', 'mensaje' => 'Se requiere campo "umbral" (número).']);
            exit;
        }

        $umbral = (float) $json['umbral'];
        if ($umbral < -50 || $umbral > 150) {
            responder(422, ['status' => 'error', 'mensaje' => 'Umbral fuera de rango válido (-50 a 150 °C).']);
            exit;
        }

        $stmt = $db->prepare("UPDATE configuracion SET umbral = ?, updated_at = datetime('now','localtime') WHERE id = 1");
        $stmt->execute([$umbral]);
        responder(200, ['status' => 'ok', 'mensaje' => 'Umbral actualizado.', 'umbral' => $umbral]);
    }
    exit;
}

// ============================================================
// GET - Lectura(s)
// ============================================================
if ($metodo === 'GET') {

    // GET ?id=N → lectura específica
    if (isset($_GET['id'])) {
        $id   = (int) $_GET['id'];
        $stmt = $db->prepare("SELECT * FROM lecturas WHERE id = ?");
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        if ($fila) {
            responder(200, ['status' => 'ok', 'data' => $fila]);
        } else {
            responder(404, ['status' => 'error', 'mensaje' => "No se encontró la lectura con id=$id"]);
        }

    // GET ?desde=FECHA → lecturas desde una fecha
    } elseif (isset($_GET['desde'])) {
        $desde = $_GET['desde'];
        $stmt  = $db->prepare(
            "SELECT * FROM lecturas WHERE timestamp >= ? ORDER BY timestamp DESC LIMIT 500"
        );
        $stmt->execute([$desde]);
        $filas = $stmt->fetchAll();
        responder(200, ['status' => 'ok', 'total' => count($filas), 'data' => $filas]);

    // GET sin parámetros → últimas 100 lecturas
    } else {
        $stmt = $db->query(
            "SELECT * FROM lecturas ORDER BY timestamp DESC LIMIT 100"
        );
        $filas = $stmt->fetchAll();
        responder(200, ['status' => 'ok', 'total' => count($filas), 'data' => $filas]);
    }

// ============================================================
// POST - Insertar nueva lectura
// ============================================================
} elseif ($metodo === 'POST') {

    $body = file_get_contents('php://input');
    $json = json_decode($body, true);

    // Validar JSON
    if (json_last_error() !== JSON_ERROR_NONE || !isset($json['temperatura'])) {
        responder(400, [
            'status'  => 'error',
            'mensaje' => 'Body JSON inválido. Se requiere campo "temperatura" (número).'
        ]);
        exit;
    }

    $temperatura = (float) $json['temperatura'];
    $fuente      = isset($json['fuente']) ? trim($json['fuente']) : 'arduino';
    $unidad      = isset($json['unidad']) ? strtoupper(trim($json['unidad'])) : 'C';

    // Validar rango razonable (-50 a 150 °C)
    if ($temperatura < -50 || $temperatura > 150) {
        responder(422, [
            'status'  => 'error',
            'mensaje' => "Temperatura fuera de rango válido (-50 a 150 °C). Valor recibido: $temperatura"
        ]);
        exit;
    }

    $stmt = $db->prepare(
        "INSERT INTO lecturas (temperatura, unidad, fuente) VALUES (?, ?, ?)"
    );
    $stmt->execute([$temperatura, $unidad, $fuente]);
    $nuevoId = $db->lastInsertId();

    responder(201, [
        'status'      => 'ok',
        'mensaje'     => 'Lectura registrada correctamente.',
        'id'          => (int) $nuevoId,
        'temperatura' => $temperatura,
        'fuente'      => $fuente
    ]);

// ============================================================
// Método no permitido
// ============================================================
} else {
    responder(405, ['status' => 'error', 'mensaje' => 'Método no permitido. Usa GET o POST.']);
}

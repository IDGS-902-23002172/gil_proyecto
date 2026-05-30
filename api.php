<?php
// ============================================================
// API REST - Lectura de Temperatura
// Motor BD: MySQL (Railway)
// Variables de entorno requeridas:
//   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD
//
// Endpoints:
//   GET  /api.php             → Últimas 100 lecturas
//   GET  /api.php?id=5        → Lectura por ID
//   GET  /api.php?desde=FECHA → Lecturas desde una fecha
//   POST /api.php             → Insertar lectura { "temperatura": 24.5 }
//   GET  /api.php?umbral=1    → Leer umbral activo
//   POST /api.php?umbral=1    → Actualizar umbral { "umbral": 35.0 }
// ============================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function getDB(): PDO {
    $host = getenv('DB_HOST')     ?: 'localhost';
    $port = getenv('DB_PORT')     ?: '3306';
    $name = getenv('DB_NAME')     ?: 'temperatura';
    $user = getenv('DB_USER')     ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: '';

    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec("CREATE TABLE IF NOT EXISTS lecturas (
            id          INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
            temperatura FLOAT       NOT NULL,
            unidad      VARCHAR(1)  NOT NULL DEFAULT 'C',
            fuente      VARCHAR(50) NOT NULL DEFAULT 'arduino',
            timestamp   DATETIME    NOT NULL DEFAULT NOW()
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS configuracion (
            id         INT   NOT NULL PRIMARY KEY DEFAULT 1,
            umbral     FLOAT NOT NULL DEFAULT 30.0,
            updated_at DATETIME NOT NULL DEFAULT NOW()
        )");

        $pdo->exec("INSERT IGNORE INTO configuracion (id, umbral) VALUES (1, 30.0)");

        return $pdo;
    } catch (PDOException $e) {
        responder(500, ['error' => 'No se pudo conectar a la base de datos: ' . $e->getMessage()]);
        exit;
    }
}

function responder(int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

$metodo = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ============================================================
// GET /api.php?umbral=1  → umbral actual
// POST /api.php?umbral=1 → actualizar umbral
// ============================================================
if (isset($_GET['umbral'])) {
    if ($metodo === 'GET') {
        $fila = $db->query("SELECT umbral, updated_at FROM configuracion WHERE id = 1")->fetch();
        responder(200, ['status' => 'ok', 'umbral' => (float) $fila['umbral'], 'updated_at' => $fila['updated_at']]);

    } elseif ($metodo === 'POST') {
        $json = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($json['umbral'])) {
            responder(400, ['status' => 'error', 'mensaje' => 'Se requiere campo "umbral" (número).']);
            exit;
        }

        $umbral = (float) $json['umbral'];
        if ($umbral < -50 || $umbral > 150) {
            responder(422, ['status' => 'error', 'mensaje' => 'Umbral fuera de rango (-50 a 150 °C).']);
            exit;
        }

        $stmt = $db->prepare("UPDATE configuracion SET umbral = ?, updated_at = NOW() WHERE id = 1");
        $stmt->execute([$umbral]);
        responder(200, ['status' => 'ok', 'mensaje' => 'Umbral actualizado.', 'umbral' => $umbral]);
    }
    exit;
}

// ============================================================
// GET - Lecturas
// ============================================================
if ($metodo === 'GET') {

    if (isset($_GET['id'])) {
        $stmt = $db->prepare("SELECT * FROM lecturas WHERE id = ?");
        $stmt->execute([(int) $_GET['id']]);
        $fila = $stmt->fetch();
        $fila ? responder(200, ['status' => 'ok', 'data' => $fila])
              : responder(404, ['status' => 'error', 'mensaje' => 'Lectura no encontrada.']);

    } elseif (isset($_GET['desde'])) {
        $stmt = $db->prepare("SELECT * FROM lecturas WHERE timestamp >= ? ORDER BY timestamp DESC LIMIT 500");
        $stmt->execute([$_GET['desde']]);
        $filas = $stmt->fetchAll();
        responder(200, ['status' => 'ok', 'total' => count($filas), 'data' => $filas]);

    } else {
        $filas = $db->query("SELECT * FROM lecturas ORDER BY timestamp DESC LIMIT 100")->fetchAll();
        responder(200, ['status' => 'ok', 'total' => count($filas), 'data' => $filas]);
    }

// ============================================================
// POST - Insertar lectura
// ============================================================
} elseif ($metodo === 'POST') {

    $json = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($json['temperatura'])) {
        responder(400, ['status' => 'error', 'mensaje' => 'Se requiere campo "temperatura".']);
        exit;
    }

    $temperatura = (float) $json['temperatura'];
    if ($temperatura < -50 || $temperatura > 150) {
        responder(422, ['status' => 'error', 'mensaje' => "Temperatura fuera de rango: $temperatura"]);
        exit;
    }

    $fuente = isset($json['fuente']) ? trim($json['fuente']) : 'arduino';
    $unidad = isset($json['unidad']) ? strtoupper(trim($json['unidad'])) : 'C';

    $stmt = $db->prepare("INSERT INTO lecturas (temperatura, unidad, fuente) VALUES (?, ?, ?)");
    $stmt->execute([$temperatura, $unidad, $fuente]);

    responder(201, [
        'status'      => 'ok',
        'mensaje'     => 'Lectura registrada.',
        'id'          => (int) $db->lastInsertId(),
        'temperatura' => $temperatura,
        'fuente'      => $fuente
    ]);

} else {
    responder(405, ['status' => 'error', 'mensaje' => 'Método no permitido.']);
}

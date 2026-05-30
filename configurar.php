<?php
function getDB(): PDO {
    $host = getenv('DB_HOST')     ?: 'localhost';
    $port = getenv('DB_PORT')     ?: '3306';
    $name = getenv('DB_NAME')     ?: 'temperatura';
    $user = getenv('DB_USER')     ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: '';

    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracion (
        id         INT   NOT NULL PRIMARY KEY DEFAULT 1,
        umbral     FLOAT NOT NULL DEFAULT 30.0,
        updated_at DATETIME NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("INSERT IGNORE INTO configuracion (id, umbral) VALUES (1, 30.0)");

    return $pdo;
}

$db = getDB();
$umbralActual = $db->query("SELECT umbral FROM configuracion WHERE id = 1")->fetchColumn();

$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['umbral'])) {
    $nuevo = (float) $_POST['umbral'];
    if ($nuevo >= -50 && $nuevo <= 150) {
        $stmt = $db->prepare("UPDATE configuracion SET umbral = ?, updated_at = NOW() WHERE id = 1");
        $stmt->execute([$nuevo]);
        $umbralActual = $nuevo;
        $mensaje = 'ok';
    } else {
        $mensaje = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Control de Temperatura</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: Arial, sans-serif;
      background: #f0f2f5;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
    }
    .card {
      background: white;
      border-radius: 12px;
      padding: 40px;
      width: 380px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    }
    h1 { font-size: 22px; color: #333; margin-bottom: 6px; }
    p.sub { color: #888; font-size: 14px; margin-bottom: 28px; }
    label { display: block; font-size: 14px; color: #555; margin-bottom: 8px; }
    input[type="number"] {
      width: 100%;
      padding: 12px 16px;
      border: 2px solid #ddd;
      border-radius: 8px;
      font-size: 18px;
      text-align: center;
      outline: none;
      transition: border-color 0.2s;
    }
    input[type="number"]:focus { border-color: #4f7be8; }
    button {
      width: 100%;
      margin-top: 16px;
      padding: 14px;
      background: #4f7be8;
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 16px;
      cursor: pointer;
      transition: background 0.2s;
    }
    button:hover { background: #3a63c8; }
    .alerta {
      margin-top: 16px;
      padding: 12px 16px;
      border-radius: 8px;
      font-size: 14px;
      text-align: center;
    }
    .alerta.ok    { background: #e6f9ee; color: #2a7a4b; }
    .alerta.error { background: #fdecea; color: #b91c1c; }
    .umbral-actual {
      margin-top: 24px;
      padding: 14px;
      background: #f7f8fc;
      border-radius: 8px;
      text-align: center;
      font-size: 14px;
      color: #666;
    }
    .umbral-actual span { font-size: 28px; font-weight: bold; color: #4f7be8; display: block; }
  </style>
</head>
<body>
<div class="card">
  <h1>Control de Temperatura</h1>
  <p class="sub">Define a qué temperatura debe encenderse el LED del ESP32</p>

  <form method="POST">
    <label for="umbral">Nueva temperatura umbral (°C)</label>
    <input type="number" id="umbral" name="umbral"
           step="0.5" min="-50" max="150"
           value="<?= htmlspecialchars($umbralActual) ?>"
           required>
    <button type="submit">Guardar y aplicar</button>
  </form>

  <?php if ($mensaje === 'ok'): ?>
    <div class="alerta ok">Umbral actualizado. El ESP32 lo aplicará en la próxima consulta.</div>
  <?php elseif ($mensaje === 'error'): ?>
    <div class="alerta error">Valor fuera de rango. Ingresa entre -50 y 150 °C.</div>
  <?php endif; ?>

  <div class="umbral-actual">
    Umbral activo
    <span><?= htmlspecialchars($umbralActual) ?> °C</span>
  </div>
</div>
</body>
</html>

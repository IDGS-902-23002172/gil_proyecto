-- ============================================================
-- Proyecto: Base de Datos MySQL - Lecturas de Temperatura
-- Motor: MySQL 8.x (Railway)
-- ============================================================

CREATE TABLE IF NOT EXISTS lecturas (
    id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    temperatura FLOAT        NOT NULL,
    unidad      VARCHAR(1)   NOT NULL DEFAULT 'C',
    fuente      VARCHAR(50)  NOT NULL DEFAULT 'arduino',
    timestamp   DATETIME     NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_lecturas_timestamp ON lecturas(timestamp);

CREATE TABLE IF NOT EXISTS errores (
    id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mensaje     TEXT         NOT NULL,
    timestamp   DATETIME     NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS configuracion (
    id         INT   NOT NULL PRIMARY KEY DEFAULT 1,
    umbral     FLOAT NOT NULL DEFAULT 30.0,
    updated_at DATETIME NOT NULL DEFAULT NOW()
);

INSERT IGNORE INTO configuracion (id, umbral) VALUES (1, 30.0);

-- Dato de prueba
INSERT INTO lecturas (temperatura, fuente) VALUES (24.75, 'test_inicial');

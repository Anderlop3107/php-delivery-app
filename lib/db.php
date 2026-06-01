<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function app_db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if ($conn->connect_errno) {
        http_response_code(500);
        exit('Error de conexion a la base de datos.');
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}

function app_query(string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = app_db()->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException(app_db()->error);
    }

    if ($types !== '' && $params !== []) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }

    return $stmt;
}

function app_all(string $sql, string $types = '', array $params = []): array
{
    $stmt = app_query($sql, $types, $params);
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function app_one(string $sql, string $types = '', array $params = []): ?array
{
    $rows = app_all($sql, $types, $params);
    return $rows[0] ?? null;
}

function app_exec(string $sql, string $types = '', array $params = []): int
{
    $stmt = app_query($sql, $types, $params);
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

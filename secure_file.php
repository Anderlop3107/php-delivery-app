<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    exit('No autorizado.');
}

$requestedPath = rawurldecode((string)($_GET['path'] ?? ''));
$requestedPath = str_replace('\\', '/', $requestedPath);

if (
    !preg_match('#^uploads/(documents|payments)/[^/]+$#', $requestedPath) ||
    str_contains($requestedPath, '..') ||
    str_contains($requestedPath, "\0")
) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$isAdmin = ($user['role'] ?? '') === 'admin';
$isOwner = false;

if (!$isAdmin) {
    $isOwner = (bool) app_one(
        "SELECT id FROM users
         WHERE id = ? AND (
             doc_ci_path = ? OR doc_ci_back_path = ? OR
             doc_licencia_path = ? OR doc_licencia_back_path = ? OR
             doc_habilitacion_path = ? OR doc_habilitacion_back_path = ? OR
             doc_cedula_verde_path = ? OR doc_cedula_verde_back_path = ?
         )",
        'issssssss',
        [(int)$user['id'], $requestedPath, $requestedPath, $requestedPath, $requestedPath,
         $requestedPath, $requestedPath, $requestedPath, $requestedPath]
    );

    if (!$isOwner) {
        $isOwner = (bool) app_one(
            'SELECT id FROM driver_payments WHERE driver_user_id = ? AND payment_proof_path = ?',
            'is',
            [(int)$user['id'], $requestedPath]
        );
    }
}

if (!$isAdmin && !$isOwner) {
    http_response_code(403);
    exit('No tienes permiso para ver este archivo.');
}

$baseDirectory = realpath(__DIR__ . '/uploads/' . (str_starts_with($requestedPath, 'uploads/documents/') ? 'documents' : 'payments'));
$filePath = realpath(__DIR__ . '/' . $requestedPath);

if (!$baseDirectory || !$filePath || !is_file($filePath) || !str_starts_with($filePath, $baseDirectory . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    http_response_code(415);
    exit('Tipo de archivo no permitido.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($filePath));
header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($filePath);

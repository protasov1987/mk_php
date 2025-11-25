<?php

function respond_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ean13_checksum(string $base): string
{
    $sum = 0;
    $length = strlen($base);
    for ($i = 0; $i < $length; $i++) {
        $digit = (int) $base[$i];
        $sum += $digit * (($i % 2 === 0) ? 1 : 3);
    }
    $checksum = (10 - ($sum % 10)) % 10;
    return $base . $checksum;
}

function validate_upload(array $file, array $config): ?string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return 'Ошибка загрузки файла';
    }

    $maxBytes = $config['max_upload_mb'] * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        return 'Размер файла превышает ' . $config['max_upload_mb'] . ' МБ';
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $config['allowed_extensions'], true)) {
        return 'Недопустимый тип файла: ' . $ext;
    }

    return null;
}

function log_event(PDO $pdo, int $cardId, string $eventType, array $payload = []): void
{
    $stmt = $pdo->prepare('INSERT INTO logs (card_id, event_type, payload, created_at) VALUES (:card_id, :event_type, :payload, NOW())');
    $stmt->execute([
        ':card_id' => $cardId,
        ':event_type' => $eventType,
        ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

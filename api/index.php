<?php
require __DIR__ . '/../database.php';
require __DIR__ . '/../helpers.php';
$config = require __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath === '') {
    $basePath = '/api';
}

if (!str_starts_with($path, $basePath)) {
    respond_json(['error' => 'not_found'], 404);
}

$route = substr($path, strlen($basePath));
$segments = array_values(array_filter(explode('/', $route)));
if (($segments[0] ?? '') === 'index.php') {
    array_shift($segments);
}

switch ($segments[0] ?? '') {
    case 'health':
        respond_json(['status' => 'ok', 'time' => date(DATE_ATOM)]);
    case 'cards':
        handleCards($pdo, $method, $segments, $config);
        break;
    case 'operations':
        handleOperations($pdo, $method, $segments);
        break;
    case 'centers':
        handleCenters($pdo, $method, $segments);
        break;
    case 'attachments':
        handleAttachmentDownload($pdo, $segments, $config);
        break;
    default:
        respond_json(['error' => 'not_found'], 404);
}

function handleCards(PDO $pdo, string $method, array $segments, array $config): void
{
    // /api/cards
    if (count($segments) === 1) {
        if ($method === 'GET') {
            $query = isset($_GET['q']) ? '%' . $_GET['q'] . '%' : null;
            $status = $_GET['status'] ?? null;
            $archived = isset($_GET['archived']) ? (bool)$_GET['archived'] : false;

            $sql = 'SELECT * FROM cards WHERE 1=1';
            $params = [];
            if ($archived) {
                $sql .= ' AND archived_at IS NOT NULL';
            } else {
                $sql .= ' AND archived_at IS NULL';
            }
            if ($query) {
                $sql .= ' AND (name LIKE :q OR order_no LIKE :q OR ean13 LIKE :q)';
                $params[':q'] = $query;
            }
            if ($status) {
                $sql .= ' AND status = :status';
                $params[':status'] = $status;
            }
            $sql .= ' ORDER BY created_at DESC LIMIT 200';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            respond_json(['items' => $stmt->fetchAll()]);
        }

        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            if (!isset($input['name']) || trim($input['name']) === '') {
                respond_json(['error' => 'validation', 'message' => 'Название обязательно'], 422);
            }

            $base = str_pad((string) random_int(100000000000, 999999999999), 12, '0', STR_PAD_LEFT);
            $ean = ean13_checksum(substr($base, 0, 12));
            $stmt = $pdo->prepare('INSERT INTO cards (ean13, name, qty, order_no, drawing, material, description, status, created_at, updated_at) VALUES (:ean13, :name, :qty, :order_no, :drawing, :material, :description, :status, NOW(), NOW())');
            $stmt->execute([
                ':ean13' => $ean,
                ':name' => $input['name'],
                ':qty' => $input['qty'] ?? null,
                ':order_no' => $input['order_no'] ?? null,
                ':drawing' => $input['drawing'] ?? null,
                ':material' => $input['material'] ?? null,
                ':description' => $input['description'] ?? null,
                ':status' => 'NOT_STARTED',
            ]);
            $cardId = (int) $pdo->lastInsertId();
            log_event($pdo, $cardId, 'created', ['user' => 'system']);
            respond_json(['id' => $cardId, 'ean13' => $ean], 201);
        }
    }

    // /api/cards/{id}
    if (count($segments) >= 2) {
        $cardId = (int) $segments[1];

        if ($method === 'GET' && count($segments) === 2) {
            $cardStmt = $pdo->prepare('SELECT * FROM cards WHERE id = :id');
            $cardStmt->execute([':id' => $cardId]);
            $card = $cardStmt->fetch();
            if (!$card) {
                respond_json(['error' => 'not_found'], 404);
            }

            $opsStmt = $pdo->prepare('SELECT co.*, o.code, o.name AS operation_name, c.name AS center_name FROM card_operations co LEFT JOIN operations o ON co.operation_id = o.id LEFT JOIN centers c ON co.center_id = c.id WHERE co.card_id = :id ORDER BY co.position ASC, co.id ASC');
            $opsStmt->execute([':id' => $cardId]);

            $attachmentsStmt = $pdo->prepare('SELECT id, filename_original, filename_stored, mime_type, size_bytes, created_at FROM attachments WHERE card_id = :id ORDER BY created_at DESC');
            $attachmentsStmt->execute([':id' => $cardId]);

            respond_json([
                'card' => $card,
                'operations' => $opsStmt->fetchAll(),
                'attachments' => $attachmentsStmt->fetchAll(),
            ]);
        }

        if ($method === 'PATCH' && count($segments) === 2) {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            if (isset($input['status']) && !in_array($input['status'], ['NOT_STARTED', 'IN_PROGRESS', 'PAUSED', 'MIXED', 'DONE'], true)) {
                respond_json(['error' => 'validation', 'message' => 'Неверный статус'], 422);
            }
            $fields = [];
            $params = [':id' => $cardId];
            foreach (['name', 'qty', 'order_no', 'drawing', 'material', 'description', 'status'] as $field) {
                if (array_key_exists($field, $input)) {
                    $fields[] = "$field = :$field";
                    $params[':' . $field] = $input[$field];
                }
            }
            if (!$fields) {
                respond_json(['error' => 'validation', 'message' => 'Нет изменений'], 422);
            }
            $sql = 'UPDATE cards SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            log_event($pdo, $cardId, 'updated', $input);
            respond_json(['success' => true]);
        }

        if ($method === 'POST' && isset($segments[2]) && $segments[2] === 'archive') {
            $stmt = $pdo->prepare('UPDATE cards SET archived_at = NOW(), status = "DONE" WHERE id = :id');
            $stmt->execute([':id' => $cardId]);
            log_event($pdo, $cardId, 'archived');
            respond_json(['success' => true]);
        }

        if ($method === 'POST' && isset($segments[2]) && $segments[2] === 'repeat') {
            $base = str_pad((string) random_int(100000000000, 999999999999), 12, '0', STR_PAD_LEFT);
            $ean = ean13_checksum(substr($base, 0, 12));
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO cards (ean13, name, qty, order_no, drawing, material, description, status, created_at, updated_at) SELECT :ean13, name, qty, order_no, drawing, material, description, "NOT_STARTED", NOW(), NOW() FROM cards WHERE id = :id')
                ->execute([':ean13' => $ean, ':id' => $cardId]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO card_operations (card_id, operation_id, center_id, assignee, planned_start, planned_end, actual_time_minutes, status, comments, position) SELECT :new_id, operation_id, center_id, assignee, NULL, NULL, NULL, "NOT_STARTED", NULL, position FROM card_operations WHERE card_id = :old_id')
                ->execute([':new_id' => $newId, ':old_id' => $cardId]);
            $pdo->commit();
            log_event($pdo, $newId, 'repeated', ['source_id' => $cardId]);
            respond_json(['id' => $newId, 'ean13' => $ean]);
        }

        if ($method === 'GET' && count($segments) === 3 && $segments[2] === 'log') {
            $stmt = $pdo->prepare('SELECT * FROM logs WHERE card_id = :id ORDER BY created_at DESC LIMIT 200');
            $stmt->execute([':id' => $cardId]);
            respond_json(['items' => $stmt->fetchAll()]);
        }

        if ($method === 'GET' && count($segments) === 3 && $segments[2] === 'attachments') {
            $stmt = $pdo->prepare('SELECT id, filename_original, filename_stored, mime_type, size_bytes, created_at FROM attachments WHERE card_id = :id ORDER BY created_at DESC');
            $stmt->execute([':id' => $cardId]);
            respond_json(['items' => $stmt->fetchAll()]);
        }

        if ($method === 'POST' && count($segments) === 3 && $segments[2] === 'attachments') {
            $error = validate_upload($_FILES['file'] ?? [], $config);
            if ($error) {
                respond_json(['error' => 'validation', 'message' => $error], 422);
            }
            $dir = $config['upload_dir'];
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $file = $_FILES['file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $unique = uniqid('file_', true) . '.' . $ext;
            $path = rtrim($dir, '/') . '/' . $unique;
            if (!move_uploaded_file($file['tmp_name'], $path)) {
                respond_json(['error' => 'upload_failed'], 500);
            }
            $stmt = $pdo->prepare('INSERT INTO attachments (card_id, filename_original, filename_stored, mime_type, size_bytes, created_at) VALUES (:card_id, :filename_original, :filename_stored, :mime_type, :size_bytes, NOW())');
            $stmt->execute([
                ':card_id' => $cardId,
                ':filename_original' => $file['name'],
                ':filename_stored' => $unique,
                ':mime_type' => $file['type'] ?? 'application/octet-stream',
                ':size_bytes' => $file['size'],
            ]);
            log_event($pdo, $cardId, 'attachment_added', ['name' => $file['name']]);
            respond_json(['success' => true, 'filename' => $unique]);
        }

        if (isset($segments[2]) && $segments[2] === 'operations') {
            handleCardOperations($pdo, $method, $segments, $cardId);
            return;
        }
    }

    respond_json(['error' => 'not_found'], 404);
}

function handleCardOperations(PDO $pdo, string $method, array $segments, int $cardId): void
{
    // /api/cards/{cardId}/operations
    if (count($segments) === 3) {
        if ($method === 'GET') {
            $stmt = $pdo->prepare('SELECT co.*, o.code, o.name AS operation_name, c.name AS center_name FROM card_operations co LEFT JOIN operations o ON co.operation_id = o.id LEFT JOIN centers c ON co.center_id = c.id WHERE co.card_id = :id ORDER BY co.position ASC, co.id ASC');
            $stmt->execute([':id' => $cardId]);
            respond_json(['items' => $stmt->fetchAll()]);
        }
        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            if (empty($input['operation_id'])) {
                respond_json(['error' => 'validation', 'message' => 'Операция обязательна'], 422);
            }
            $stmt = $pdo->prepare('INSERT INTO card_operations (card_id, operation_id, center_id, assignee, planned_start, planned_end, actual_time_minutes, status, comments, position) VALUES (:card_id, :operation_id, :center_id, :assignee, :planned_start, :planned_end, :actual_time_minutes, :status, :comments, :position)');
            $stmt->execute([
                ':card_id' => $cardId,
                ':operation_id' => $input['operation_id'],
                ':center_id' => $input['center_id'] ?? null,
                ':assignee' => $input['assignee'] ?? null,
                ':planned_start' => $input['planned_start'] ?? null,
                ':planned_end' => $input['planned_end'] ?? null,
                ':actual_time_minutes' => $input['actual_time_minutes'] ?? null,
                ':status' => $input['status'] ?? 'NOT_STARTED',
                ':comments' => $input['comments'] ?? null,
                ':position' => $input['position'] ?? 0,
            ]);
            log_event($pdo, $cardId, 'operation_added', ['operation_id' => $input['operation_id']]);
            respond_json(['id' => (int) $pdo->lastInsertId()], 201);
        }
    }

    // /api/cards/{cardId}/operations/{id}
    if (count($segments) === 4) {
        $cardOperationId = (int) $segments[3];
        if ($method === 'PATCH') {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $fields = [];
            $params = [':id' => $cardOperationId, ':card_id' => $cardId];
            $allowed = ['center_id', 'assignee', 'planned_start', 'planned_end', 'actual_time_minutes', 'status', 'comments', 'position'];
            if (isset($input['status']) && !in_array($input['status'], ['NOT_STARTED', 'IN_PROGRESS', 'PAUSED', 'MIXED', 'DONE'], true)) {
                respond_json(['error' => 'validation', 'message' => 'Неверный статус операции'], 422);
            }
            foreach ($allowed as $field) {
                if (array_key_exists($field, $input)) {
                    $fields[] = "$field = :$field";
                    $params[':' . $field] = $input[$field];
                }
            }
            if (!$fields) {
                respond_json(['error' => 'validation', 'message' => 'Нет изменений'], 422);
            }
            $sql = 'UPDATE card_operations SET ' . implode(', ', $fields) . ' WHERE id = :id AND card_id = :card_id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            log_event($pdo, $cardId, 'operation_updated', ['operation_id' => $cardOperationId, 'changes' => $input]);
            respond_json(['success' => true]);
        }

        if ($method === 'DELETE') {
            $stmt = $pdo->prepare('DELETE FROM card_operations WHERE id = :id AND card_id = :card_id');
            $stmt->execute([':id' => $cardOperationId, ':card_id' => $cardId]);
            log_event($pdo, $cardId, 'operation_deleted', ['operation_id' => $cardOperationId]);
            respond_json(['success' => true]);
        }
    }

    respond_json(['error' => 'not_found'], 404);
}

function handleOperations(PDO $pdo, string $method, array $segments): void
{
    if (count($segments) === 1 && $method === 'GET') {
        $stmt = $pdo->query('SELECT * FROM operations ORDER BY code ASC');
        respond_json(['items' => $stmt->fetchAll()]);
    }

    if (count($segments) === 1 && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (!isset($input['code'], $input['name'])) {
            respond_json(['error' => 'validation', 'message' => 'Код и название обязательны'], 422);
        }
        $stmt = $pdo->prepare('INSERT INTO operations (code, name, description, recommended_time_minutes) VALUES (:code, :name, :description, :time)');
        $stmt->execute([
            ':code' => $input['code'],
            ':name' => $input['name'],
            ':description' => $input['description'] ?? null,
            ':time' => $input['recommended_time_minutes'] ?? null,
        ]);
        respond_json(['id' => (int) $pdo->lastInsertId()], 201);
    }

    if (count($segments) === 2) {
        $operationId = (int) $segments[1];
        if ($method === 'PATCH') {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $fields = [];
            $params = [':id' => $operationId];
            foreach (['code', 'name', 'description', 'recommended_time_minutes'] as $field) {
                if (array_key_exists($field, $input)) {
                    $fields[] = "$field = :$field";
                    $params[':' . $field] = $input[$field];
                }
            }
            if (!$fields) {
                respond_json(['error' => 'validation', 'message' => 'Нет изменений'], 422);
            }
            $sql = 'UPDATE operations SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            respond_json(['success' => true]);
        }

        if ($method === 'DELETE') {
            $stmt = $pdo->prepare('DELETE FROM operations WHERE id = :id');
            $stmt->execute([':id' => $operationId]);
            respond_json(['success' => true]);
        }
    }

    respond_json(['error' => 'not_found'], 404);
}

function handleCenters(PDO $pdo, string $method, array $segments): void
{
    if (count($segments) === 1 && $method === 'GET') {
        $stmt = $pdo->query('SELECT * FROM centers ORDER BY name ASC');
        respond_json(['items' => $stmt->fetchAll()]);
    }

    if (count($segments) === 1 && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (!isset($input['name'])) {
            respond_json(['error' => 'validation', 'message' => 'Название участка обязательно'], 422);
        }
        $stmt = $pdo->prepare('INSERT INTO centers (name, description) VALUES (:name, :description)');
        $stmt->execute([
            ':name' => $input['name'],
            ':description' => $input['description'] ?? null,
        ]);
        respond_json(['id' => (int) $pdo->lastInsertId()], 201);
    }

    if (count($segments) === 2) {
        $centerId = (int) $segments[1];
        if ($method === 'PATCH') {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $fields = [];
            $params = [':id' => $centerId];
            foreach (['name', 'description'] as $field) {
                if (array_key_exists($field, $input)) {
                    $fields[] = "$field = :$field";
                    $params[':' . $field] = $input[$field];
                }
            }
            if (!$fields) {
                respond_json(['error' => 'validation', 'message' => 'Нет изменений'], 422);
            }
            $sql = 'UPDATE centers SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            respond_json(['success' => true]);
        }

        if ($method === 'DELETE') {
            $stmt = $pdo->prepare('DELETE FROM centers WHERE id = :id');
            $stmt->execute([':id' => $centerId]);
            respond_json(['success' => true]);
        }
    }

    respond_json(['error' => 'not_found'], 404);
}

function handleAttachmentDownload(PDO $pdo, array $segments, array $config): void
{
    if (count($segments) === 2) {
        $attachmentId = (int) $segments[1];
        $stmt = $pdo->prepare('SELECT * FROM attachments WHERE id = :id');
        $stmt->execute([':id' => $attachmentId]);
        $attachment = $stmt->fetch();
        if (!$attachment) {
            respond_json(['error' => 'not_found'], 404);
        }
        $filePath = rtrim($config['upload_dir'], '/') . '/' . $attachment['filename_stored'];
        if (!is_file($filePath)) {
            respond_json(['error' => 'not_found'], 404);
        }
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Length: ' . $attachment['size_bytes']);
        header('Content-Disposition: attachment; filename="' . $attachment['filename_original'] . '"');
        readfile($filePath);
        exit;
    }

    respond_json(['error' => 'not_found'], 404);
}

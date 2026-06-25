<?php
require_once '../auth_check.php';
require_once '../config/database.php';
require_once '../includes/commerce.php';

header('Content-Type: application/json');

try {
    $context = commerce_get_tenant_context();
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conn = $context['conn'];
$prefix = $context['prefix'];

$input = commerce_read_input();
$action = $_GET['action'] ?? $input['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $stmt = $conn->query("SELECT id, name, type, is_default, created_at FROM {$prefix}communication_configs ORDER BY type ASC, name ASC");
            $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            commerce_json_response(['success' => true, 'configs' => $configs]);

        case 'get':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) throw new RuntimeException('Config ID required');
            $stmt = $conn->prepare("SELECT * FROM {$prefix}communication_configs WHERE id = ?");
            $stmt->execute([$id]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$config) throw new RuntimeException('Config not found');
            $config['config_data'] = json_decode($config['config_data'], true) ?? [];
            commerce_json_response(['success' => true, 'config' => $config]);

        case 'save':
            $id = (int)($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $type = trim($input['type'] ?? 'smtp');
            $is_default = (int)($input['is_default'] ?? 0);
            
            $configData = $input['config_data'] ?? [];
            if (is_string($configData)) {
                $configData = json_decode($configData, true) ?? [];
            }
            $configJson = json_encode($configData);

            if (!$name) throw new RuntimeException('Name required');
            if (!in_array($type, ['smtp', 'whatsapp'])) throw new RuntimeException('Invalid type');

            if ($is_default) {
                // Remove default from others of same type
                $conn->prepare("UPDATE {$prefix}communication_configs SET is_default = 0 WHERE type = ?")->execute([$type]);
            } else {
                // If it's the only one, make it default
                $stmt = $conn->prepare("SELECT COUNT(*) FROM {$prefix}communication_configs WHERE type = ? AND id != ?");
                $stmt->execute([$type, $id]);
                if ($stmt->fetchColumn() == 0) {
                    $is_default = 1;
                }
            }

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE {$prefix}communication_configs SET name = ?, type = ?, config_data = ?, is_default = ? WHERE id = ?");
                $stmt->execute([$name, $type, $configJson, $is_default, $id]);
            } else {
                $stmt = $conn->prepare("INSERT INTO {$prefix}communication_configs (name, type, config_data, is_default) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $type, $configJson, $is_default]);
                $id = $conn->lastInsertId();
            }
            commerce_json_response(['success' => true, 'id' => $id]);

        case 'delete':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new RuntimeException('Config ID required');
            
            // Check if it's default
            $stmt = $conn->prepare("SELECT type, is_default FROM {$prefix}communication_configs WHERE id = ?");
            $stmt->execute([$id]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $conn->prepare("DELETE FROM {$prefix}communication_configs WHERE id = ?")->execute([$id]);
            
            // If we deleted the default, make another one default
            if ($config && $config['is_default']) {
                $conn->prepare("UPDATE {$prefix}communication_configs SET is_default = 1 WHERE type = ? LIMIT 1")->execute([$config['type']]);
            }
            
            // Nullify config ID in campaigns/workflows
            $conn->prepare("UPDATE {$prefix}campaigns SET communication_config_id = NULL WHERE communication_config_id = ?")->execute([$id]);
            $conn->prepare("UPDATE {$prefix}module_workflows SET communication_config_id = NULL WHERE communication_config_id = ?")->execute([$id]);
            
            commerce_json_response(['success' => true]);

        default:
            throw new RuntimeException("Unknown action: $action");
    }
} catch (Throwable $e) {
    commerce_json_response(['success' => false, 'error' => $e->getMessage()], 400);
}

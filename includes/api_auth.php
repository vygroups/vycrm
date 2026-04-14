<?php
require_once __DIR__ . '/../config/database.php';

function api_ensure_token_table(PDO $masterConn, string $masterPrefix): void
{
    $masterConn->exec("
        CREATE TABLE IF NOT EXISTS {$masterPrefix}api_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            username VARCHAR(100) NOT NULL,
            tenant_slug VARCHAR(150) NOT NULL,
            tenant_db VARCHAR(150) NOT NULL,
            tenant_prefix VARCHAR(150) NOT NULL DEFAULT '',
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_api_tokens_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function api_extract_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if ($header && preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
        return trim($matches[1]);
    }

    return $_GET['token'] ?? $_POST['token'] ?? null;
}

function api_issue_token(array $user, string $tenantSlug, string $tenantDb, string $tenantPrefix): string
{
    $masterConn = Database::getMasterConn();
    $masterPrefix = Database::getMasterPrefix();
    api_ensure_token_table($masterConn, $masterPrefix);

    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);
    $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 3600));

    $stmt = $masterConn->prepare("
        INSERT INTO {$masterPrefix}api_tokens (
            token_hash, user_id, username, tenant_slug, tenant_db, tenant_prefix, expires_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $tokenHash,
        (int) $user['id'],
        (string) $user['username'],
        $tenantSlug,
        $tenantDb,
        $tenantPrefix,
        $expiresAt
    ]);

    return $plainToken;
}

function api_require_context(): array
{
    if (isset($_SESSION['user_id'], $_SESSION['tenant_db'], $_SESSION['tenant_prefix'])) {
        $conn = Database::getTenantConn($_SESSION['tenant_db']);
        if (!$conn) {
            throw new RuntimeException('Database connection failed');
        }

        return [
            'user_id' => (int) $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'tenant_slug' => $_SESSION['tenant_slug'] ?? '',
            'db_name' => $_SESSION['tenant_db'],
            'prefix' => $_SESSION['tenant_prefix'],
            'conn' => $conn,
            'auth_mode' => 'session',
        ];
    }

    $token = api_extract_bearer_token();
    if (!$token) {
        throw new RuntimeException('Unauthorized');
    }

    $masterConn = Database::getMasterConn();
    $masterPrefix = Database::getMasterPrefix();
    api_ensure_token_table($masterConn, $masterPrefix);

    $stmt = $masterConn->prepare("
        SELECT *
        FROM {$masterPrefix}api_tokens
        WHERE token_hash = ? AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([hash('sha256', $token)]);
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenRow) {
        throw new RuntimeException('Unauthorized');
    }

    $conn = Database::getTenantConn($tokenRow['tenant_db']);
    if (!$conn) {
        throw new RuntimeException('Database connection failed');
    }

    return [
        'user_id' => (int) $tokenRow['user_id'],
        'username' => $tokenRow['username'],
        'tenant_slug' => $tokenRow['tenant_slug'],
        'db_name' => $tokenRow['tenant_db'],
        'prefix' => $tokenRow['tenant_prefix'],
        'conn' => $conn,
        'auth_mode' => 'token',
    ];
}


<?php
/**
 * includes/dynamic_modules.php
 * 
 * Dynamic Module System — Schema, helpers, and rendering engine.
 * Provides the EAV backbone for admin-defined modules with blocks, fields,
 * dependent/conditional logic, and editable dropdown options.
 */

require_once __DIR__ . '/../config/database.php';

/* ─────────────────────────── FIELD TYPE REGISTRY ─────────────────────────── */

function dm_field_types(): array
{
    return [
        'text'            => ['label' => 'Text Field',           'icon' => 'fa-solid fa-font'],
        'email'           => ['label' => 'Email Field',          'icon' => 'fa-solid fa-envelope'],
        'phone'           => ['label' => 'Phone Number',         'icon' => 'fa-solid fa-phone'],
        'number'          => ['label' => 'Number Field',         'icon' => 'fa-solid fa-hashtag'],
        'currency'        => ['label' => 'Currency Field',       'icon' => 'fa-solid fa-indian-rupee-sign'],
        'url'             => ['label' => 'URL Field',            'icon' => 'fa-solid fa-link'],
        'textarea'        => ['label' => 'Textarea',             'icon' => 'fa-solid fa-align-left'],
        'checkbox'        => ['label' => 'Checkbox',             'icon' => 'fa-solid fa-square-check'],
        'dropdown'        => ['label' => 'Dropdown',             'icon' => 'fa-solid fa-caret-down'],
        'multi_picker'    => ['label' => 'Multi Picker',         'icon' => 'fa-solid fa-list-check'],
        'date'            => ['label' => 'Date Picker',          'icon' => 'fa-solid fa-calendar'],
        'datetime'        => ['label' => 'Date & Time Picker',   'icon' => 'fa-solid fa-calendar-day'],
        'time'            => ['label' => 'Time Picker',          'icon' => 'fa-solid fa-clock'],
        'name'            => ['label' => 'Name Field',           'icon' => 'fa-solid fa-id-card'],
        'country'         => ['label' => 'Country Picker',       'icon' => 'fa-solid fa-globe'],
        'state'           => ['label' => 'State Picker',         'icon' => 'fa-solid fa-map-location-dot'],
        'assigned_to'     => ['label' => 'Assigned To',          'icon' => 'fa-solid fa-user-check'],
        'api_call_picker' => ['label' => 'API Call Picker',      'icon' => 'fa-solid fa-plug'],
        'attachment'      => ['label' => 'Attachment Picker',    'icon' => 'fa-solid fa-paperclip'],
    ];
}

/* ──────────────────────────── TABLE CREATION ──────────────────────────── */

function dm_ensure_tables(PDO $conn, string $p): void
{
    // 1. Modules
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$p}modules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            slug VARCHAR(150) NOT NULL UNIQUE,
            icon VARCHAR(100) DEFAULT 'fa-solid fa-cube',
            description TEXT DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_modules_slug (slug),
            INDEX idx_modules_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 2. Blocks (sections within a module form)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$p}module_blocks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (module_id) REFERENCES {$p}modules(id) ON DELETE CASCADE,
            INDEX idx_blocks_module (module_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 3. Fields
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$p}module_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            block_id INT NOT NULL,
            module_id INT NOT NULL,
            field_key VARCHAR(100) NOT NULL,
            label VARCHAR(150) NOT NULL,
            field_type VARCHAR(50) NOT NULL DEFAULT 'text',
            placeholder VARCHAR(255) DEFAULT NULL,
            default_value TEXT DEFAULT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_unique TINYINT(1) NOT NULL DEFAULT 0,
            is_searchable TINYINT(1) NOT NULL DEFAULT 0,
            is_list_visible TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            config JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (block_id) REFERENCES {$p}module_blocks(id) ON DELETE CASCADE,
            FOREIGN KEY (module_id) REFERENCES {$p}modules(id) ON DELETE CASCADE,
            INDEX idx_fields_block (block_id),
            INDEX idx_fields_module (module_id),
            UNIQUE KEY uk_field_key_module (module_id, field_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 4. Field Options (for dropdown / multi_picker)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$p}module_field_options (
            id INT AUTO_INCREMENT PRIMARY KEY,
            field_id INT NOT NULL,
            label VARCHAR(255) NOT NULL,
            value VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            FOREIGN KEY (field_id) REFERENCES {$p}module_fields(id) ON DELETE CASCADE,
            INDEX idx_options_field (field_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 5. Field Rules (dependencies & conditionals)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$p}module_field_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            field_id INT NOT NULL,
            rule_type ENUM('dependency','conditional') NOT NULL,
            source_field_id INT NOT NULL,
            operator VARCHAR(20) NOT NULL DEFAULT 'equals',
            value TEXT DEFAULT NULL,
            action VARCHAR(50) NOT NULL DEFAULT 'show',
            config JSON DEFAULT NULL,
            FOREIGN KEY (field_id) REFERENCES {$p}module_fields(id) ON DELETE CASCADE,
            FOREIGN KEY (source_field_id) REFERENCES {$p}module_fields(id) ON DELETE CASCADE,
            INDEX idx_rules_field (field_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 6. Records (EAV — header row per module entry)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$p}module_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module_id INT NOT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (module_id) REFERENCES {$p}modules(id) ON DELETE CASCADE,
            INDEX idx_records_module (module_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 7. Record Values (EAV — one row per field value)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS {$p}module_record_values (
            id INT AUTO_INCREMENT PRIMARY KEY,
            record_id INT NOT NULL,
            field_id INT NOT NULL,
            value TEXT DEFAULT NULL,
            FOREIGN KEY (record_id) REFERENCES {$p}module_records(id) ON DELETE CASCADE,
            FOREIGN KEY (field_id) REFERENCES {$p}module_fields(id) ON DELETE CASCADE,
            INDEX idx_values_record (record_id),
            INDEX idx_values_field (field_id),
            UNIQUE KEY uk_record_field (record_id, field_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/* ──────────────────────────── HELPER FUNCTIONS ──────────────────────────── */

/**
 * Generate a URL-safe slug from a human-readable name.
 */
function dm_slugify(string $text): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $text), '_'));
    return $slug ?: 'module_' . time();
}

/**
 * Generate a field_key from a label (lowercase, underscored, unique per module).
 */
function dm_field_key(string $label): string
{
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));
}

/**
 * Fetch all active modules for sidebar rendering.
 */
function dm_fetch_active_modules(PDO $conn, string $p): array
{
    $stmt = $conn->query("
        SELECT id, name, slug, icon, description
        FROM {$p}modules
        WHERE status = 'active'
        ORDER BY sort_order ASC, name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch all modules (including inactive) for admin management.
 */
function dm_fetch_all_modules(PDO $conn, string $p): array
{
    $stmt = $conn->query("
        SELECT m.*, 
               (SELECT COUNT(*) FROM {$p}module_blocks WHERE module_id = m.id) AS block_count,
               (SELECT COUNT(*) FROM {$p}module_fields WHERE module_id = m.id) AS field_count,
               (SELECT COUNT(*) FROM {$p}module_records WHERE module_id = m.id) AS record_count
        FROM {$p}modules m
        ORDER BY m.sort_order ASC, m.name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch a single module with its blocks and fields (fully hydrated).
 */
function dm_fetch_module_full(PDO $conn, string $p, int $moduleId): ?array
{
    $mStmt = $conn->prepare("SELECT * FROM {$p}modules WHERE id = ?");
    $mStmt->execute([$moduleId]);
    $module = $mStmt->fetch(PDO::FETCH_ASSOC);
    if (!$module) return null;

    // Blocks
    $bStmt = $conn->prepare("SELECT * FROM {$p}module_blocks WHERE module_id = ? ORDER BY sort_order ASC");
    $bStmt->execute([$moduleId]);
    $blocks = $bStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fields grouped by block
    $fStmt = $conn->prepare("SELECT * FROM {$p}module_fields WHERE module_id = ? ORDER BY sort_order ASC");
    $fStmt->execute([$moduleId]);
    $allFields = $fStmt->fetchAll(PDO::FETCH_ASSOC);

    // Options for dropdown / multi_picker fields
    $fieldIds = array_column($allFields, 'id');
    $options = [];
    if ($fieldIds) {
        $inClause = implode(',', array_map('intval', $fieldIds));
        $oStmt = $conn->query("SELECT * FROM {$p}module_field_options WHERE field_id IN ($inClause) ORDER BY sort_order ASC");
        foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
            $options[(int)$opt['field_id']][] = $opt;
        }
    }

    // Rules
    $rules = [];
    if ($fieldIds) {
        $inClause = implode(',', array_map('intval', $fieldIds));
        $rStmt = $conn->query("SELECT * FROM {$p}module_field_rules WHERE field_id IN ($inClause)");
        foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $rules[(int)$rule['field_id']][] = $rule;
        }
    }

    // Attach options & rules to fields, group by block
    $blockFields = [];
    foreach ($allFields as &$f) {
        $fid = (int)$f['id'];
        $f['options'] = $options[$fid] ?? [];
        $f['rules'] = $rules[$fid] ?? [];
        if ($f['config']) {
            $f['config'] = json_decode($f['config'], true);
        }
        $blockFields[(int)$f['block_id']][] = $f;
    }
    unset($f);

    foreach ($blocks as &$b) {
        $b['fields'] = $blockFields[(int)$b['id']] ?? [];
    }
    unset($b);

    $module['blocks'] = $blocks;
    return $module;
}

/**
 * Fetch records for a module with values pivoted.
 */
function dm_fetch_records(PDO $conn, string $p, int $moduleId, ?string $search = null, int $limit = 50, int $offset = 0): array
{
    // Get list-visible fields
    $fStmt = $conn->prepare("
        SELECT id, field_key, label, field_type 
        FROM {$p}module_fields 
        WHERE module_id = ? AND is_list_visible = 1 
        ORDER BY sort_order ASC
    ");
    $fStmt->execute([$moduleId]);
    $fields = $fStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get record IDs
    $sql = "SELECT r.id, r.created_at, r.created_by FROM {$p}module_records r WHERE r.module_id = ?";
    $params = [$moduleId];

    if ($search) {
        $sql .= " AND r.id IN (
            SELECT DISTINCT rv.record_id 
            FROM {$p}module_record_values rv 
            WHERE rv.value LIKE ?
        )";
        $params[] = '%' . $search . '%';
    }

    $sql .= " ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset";
    $rStmt = $conn->prepare($sql);
    $rStmt->execute($params);
    $records = $rStmt->fetchAll(PDO::FETCH_ASSOC);

    // Pivot values
    $recordIds = array_column($records, 'id');
    $valueMap = [];
    if ($recordIds) {
        $inClause = implode(',', array_map('intval', $recordIds));
        $vStmt = $conn->query("
            SELECT rv.record_id, rv.field_id, rv.value
            FROM {$p}module_record_values rv
            WHERE rv.record_id IN ($inClause)
        ");
        foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $valueMap[(int)$v['record_id']][(int)$v['field_id']] = $v['value'];
        }
    }

    foreach ($records as &$rec) {
        $rec['values'] = $valueMap[(int)$rec['id']] ?? [];
    }
    unset($rec);

    // Count total
    $cStmt = $conn->prepare("SELECT COUNT(*) FROM {$p}module_records WHERE module_id = ?");
    $cStmt->execute([$moduleId]);
    $total = (int)$cStmt->fetchColumn();

    return ['fields' => $fields, 'records' => $records, 'total' => $total];
}

/**
 * Fetch a single record with all its values.
 */
function dm_fetch_record(PDO $conn, string $p, int $recordId): ?array
{
    $rStmt = $conn->prepare("SELECT * FROM {$p}module_records WHERE id = ?");
    $rStmt->execute([$recordId]);
    $record = $rStmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) return null;

    $vStmt = $conn->prepare("SELECT field_id, value FROM {$p}module_record_values WHERE record_id = ?");
    $vStmt->execute([$recordId]);
    $record['values'] = [];
    foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $record['values'][(int)$v['field_id']] = $v['value'];
    }

    return $record;
}

/**
 * Fetch users list for "Assigned To" field type.
 */
function dm_fetch_users(PDO $conn, string $p): array
{
    try {
        $stmt = $conn->query("SELECT id, username FROM users ORDER BY username ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/* ──────────────────────── COUNTRIES & STATES DATA ────────────────────────── */

function dm_get_countries(): array
{
    return [
        'IN' => 'India', 'US' => 'United States', 'GB' => 'United Kingdom',
        'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'AU' => 'Australia',
        'CA' => 'Canada', 'SG' => 'Singapore', 'MY' => 'Malaysia', 'DE' => 'Germany',
        'FR' => 'France', 'JP' => 'Japan', 'CN' => 'China', 'KR' => 'South Korea',
        'BR' => 'Brazil', 'ZA' => 'South Africa', 'NZ' => 'New Zealand',
        'QA' => 'Qatar', 'KW' => 'Kuwait', 'BH' => 'Bahrain',
        'OM' => 'Oman', 'NP' => 'Nepal', 'LK' => 'Sri Lanka', 'BD' => 'Bangladesh',
    ];
}

function dm_get_states(): array
{
    return [
        'IN' => [
            'AN' => 'Andaman and Nicobar Islands', 'AP' => 'Andhra Pradesh', 'AR' => 'Arunachal Pradesh',
            'AS' => 'Assam', 'BR' => 'Bihar', 'CH' => 'Chandigarh', 'CT' => 'Chhattisgarh',
            'DL' => 'Delhi', 'GA' => 'Goa', 'GJ' => 'Gujarat', 'HR' => 'Haryana',
            'HP' => 'Himachal Pradesh', 'JK' => 'Jammu and Kashmir', 'JH' => 'Jharkhand',
            'KA' => 'Karnataka', 'KL' => 'Kerala', 'MP' => 'Madhya Pradesh',
            'MH' => 'Maharashtra', 'MN' => 'Manipur', 'ML' => 'Meghalaya', 'MZ' => 'Mizoram',
            'NL' => 'Nagaland', 'OR' => 'Odisha', 'PB' => 'Punjab', 'RJ' => 'Rajasthan',
            'SK' => 'Sikkim', 'TN' => 'Tamil Nadu', 'TG' => 'Telangana', 'TR' => 'Tripura',
            'UP' => 'Uttar Pradesh', 'UK' => 'Uttarakhand', 'WB' => 'West Bengal',
        ],
        'US' => [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'FL' => 'Florida',
            'GA' => 'Georgia', 'IL' => 'Illinois', 'NY' => 'New York', 'TX' => 'Texas',
            'WA' => 'Washington',
        ],
        'GB' => ['ENG' => 'England', 'SCT' => 'Scotland', 'WLS' => 'Wales', 'NIR' => 'Northern Ireland'],
        'AE' => [
            'AUH' => 'Abu Dhabi', 'DXB' => 'Dubai', 'SHJ' => 'Sharjah', 'AJM' => 'Ajman',
            'UMQ' => 'Umm Al-Quwain', 'RAK' => 'Ras Al Khaimah', 'FUJ' => 'Fujairah',
        ],
    ];
}

<?php
require_once 'config/database.php';
$masterConn = Database::getMasterConn();
$res = $masterConn->query("SELECT db_name, db_prefix FROM core_tenants LIMIT 1");
$tenant = $res->fetch(PDO::FETCH_ASSOC);
$conn = Database::getTenantConn($tenant['db_name']);
$prefix = $tenant['db_prefix'];
$res = $conn->query("DESCRIBE {$prefix}salaries");
while($row = $res->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
?>

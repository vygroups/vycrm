<?php
// check_columns.php
require_once 'config/database.php';
$masterConn = Database::getMasterConn();
$prefix = Database::getMasterPrefix();
$res = $masterConn->query("DESCRIBE {$prefix}companies");
while($row = $res->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
?>

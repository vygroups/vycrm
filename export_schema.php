<?php
$host = 'localhost';
$user = 'u495954467_vycrm';
$pass = 'Tn02aps2391*';
$db   = 'u495954467_vycrm';

try {
    $conn = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
    $tables = ['attendance', 'leaves', 'permissions', 'users'];
    $schema = "";
    foreach ($tables as $table) {
        $res = $conn->query("SHOW CREATE TABLE $table");
        $row = $res->fetch(PDO::FETCH_NUM);
        if ($row) {
            $schema .= $row[1] . ";\n\n";
        }
    }
    // Add default admin user insert
    $schema .= "INSERT INTO users (username, password, email, role) VALUES ('admin', '" . password_hash('admin@123', PASSWORD_DEFAULT) . "', 'admin@vycrm.com', 'admin');\n\n";
    
    echo base64_encode($schema);
} catch(Exception $e) { echo "ERROR:" . $e->getMessage(); }
?>
<?php
$host = 'localhost';
$user = 'u495954467_vycrm';
$pass = 'Tn02aps2391*';
try {
    $conn = new PDO("mysql:host=$host", $user, $pass);
    $res = $conn->query("SHOW GRANTS FOR CURRENT_USER()");
    while ($row = $res->fetch(PDO::FETCH_NUM)) {
        echo $row[0] . "\n";
    }
} catch(Exception $e) { echo "ERROR: " . $e->getMessage(); }
?>
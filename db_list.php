<?php
$host = 'localhost';
$user = 'u495954467_vycrm';
$pass = 'Tn02aps2391*';
try {
    $conn = new PDO("mysql:host=$host", $user, $pass);
    $res = $conn->query("SHOW DATABASES");
    while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Database'] . "\n";
    }
} catch(Exception $e) { echo $e->getMessage(); }
?>
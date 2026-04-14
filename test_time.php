<?php
date_default_timezone_set('Asia/Kolkata');
echo "Server Time (Kolkata): " . date('Y-m-d H:i:s') . "\n";
echo "Server Timestamp: " . time() . "\n";
echo "strtotime test: " . strtotime(date('Y-m-d H:i:s')) . "\n";
?>

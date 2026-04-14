<?php
foreach (nls() as $f) echo $f . "\n";
function nls() { return scandir('.'); }
?>
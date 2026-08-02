<?php
$f = 'libs/Trait_Logic.php';
$c = file_get_contents($f);
// Find if it has double encoding
if (strpos($c, 'Ã') !== false) {
    echo "Has Ã!\n";
    // decode
    $fixed = utf8_decode($c);
    if (strpos($fixed, 'Begründung') !== false) {
        echo "Fix successful for Begründung!\n";
    }
}

<?php
$files = [
    'libs/Trait_AI.php',
    'libs/Trait_Helpers.php',
    'libs/Trait_Logic.php',
    'module.php'
];
foreach($files as $f) {
    $c = file_get_contents($f);
    $c = str_replace(
        ['Ã¤', 'Ã¼', 'Ã¶', 'ÃŸ', 'Ã„', 'Ãœ', 'Ã–', 'Ã©'], 
        ['ä', 'ü', 'ö', 'ß', 'Ä', 'Ü', 'Ö', 'é'], 
        $c
    );
    file_put_contents($f, $c);
}
echo 'Done';

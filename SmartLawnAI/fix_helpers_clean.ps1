$ErrorActionPreference = 'Stop'
$noBom = [System.Text.UTF8Encoding]::new($false)
$modPath = 'c:\Users\grass\Documents\Symcon\SymconSmartClimate\SmartLawnAI\libs\Trait_Helpers.php'
$lines = [System.IO.File]::ReadAllLines($modPath)

$newLines = @()
$i = 0
while ($i -lt $lines.Length) {
    $line = $lines[$i]
    if ($line.Contains("return `$result !== false;")) {
        # skip it
    } elseif ($line.Trim() -eq "`$errorMsg = '';" -and $lines[$i+1].Contains("return `$result !== false;")) {
        # skip this and the next line since we handled it
    } else {
        $newLines += $line
    }
    $i++
}

[System.IO.File]::WriteAllLines($modPath, $newLines, $noBom)

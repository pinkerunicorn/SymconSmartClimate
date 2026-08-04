@
$ErrorActionPreference = 'Stop'
$noBom = [System.Text.UTF8Encoding]::new($false)
$modPath = 'c:\Users\grass\Documents\Symcon\SymconSmartClimate\SmartLawnAI\module.php'
$lines = [System.IO.File]::ReadAllLines($modPath)

$newLines = @()
$i = 0
while ($i -lt $lines.Length) {
    $line = $lines[$i]
    
    # We must be careful to rewrite what we messed up
    if ($line -match "public function ApplyChanges\(\): void \{") {
        $newLines += $line
        $newLines += "        parent::ApplyChanges();"
        $newLines += "        // Alte Message-Registrierungen entfernen"
        $newLines += "        foreach (`$this->GetMessageList() as `$senderID => `$messages) {"
        $newLines += "            foreach (`$messages as `$message) {"
        $newLines += "                `$this->UnregisterMessage(`$senderID, `$message);"
        $newLines += "            }"
        $newLines += "        }"
        $i++
        # Skip until $sensorID = $this->ReadPropertyInteger
        while ($i -lt $lines.Length -and -not $lines[$i].Contains("$sensorID = `$this->ReadPropertyInteger")) {
            $i++
        }
        continue
    } else {
        $newLines += $line
    }
    $i++
}

[System.IO.File]::WriteAllLines($modPath, $newLines, $noBom)
@

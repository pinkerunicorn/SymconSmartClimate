$ErrorActionPreference = 'Stop'
$noBom = [System.Text.UTF8Encoding]::new($false)
$modPath = 'c:\Users\grass\Documents\Symcon\SymconSmartClimate\SmartLawnAI\libs\Trait_Helpers.php'
$lines = [System.IO.File]::ReadAllLines($modPath)

$newLines = @()
$i = 0
while ($i -lt $lines.Length) {
    $line = $lines[$i]
    
    if ($line.Contains("`$result = RequestAction(`$variableID, `$value);")) {
        $newLines += $line
        $newLines += "            if (`$result === false) {"
        $newLines += "                `$errorMsg = 'RequestAction gab false zurueck (Ventil-ID: ' . `$variableID . ')';"
        $newLines += "                return false;"
        $newLines += "            }"
        $newLines += "            `$errorMsg = '';"
        $newLines += "            return true;"
        $i++
        # Skip the original return logic
        while ($lines[$i] -match "`$errorMsg = '';" -or $lines[$i] -match "return `$result") {
            $i++
        }
        continue
    } elseif ($line.Contains("`$logVarID = `$this->GetIDForIdent('IrrigationLog');")) {
        $newLines += "        `$logVarID = @`$this->GetIDForIdent('IrrigationLog');"
        $newLines += "        if (`$logVarID === false || !IPS_VariableExists(`$logVarID)) return;"
    } elseif ($line.Contains("IPS_SetEventActive(`$eid, true);") -and $lines[$i-1].Contains("}")) {
        $newLines += "                IPS_SetEventScript(`$eid, `"SLAI_ScheduledEvaluation(\`$_IPS['TARGET']);`");"
        $newLines += $line
    } else {
        $newLines += $line
    }
    $i++
}

[System.IO.File]::WriteAllLines($modPath, $newLines, $noBom)

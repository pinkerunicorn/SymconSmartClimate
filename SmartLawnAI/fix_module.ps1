$ErrorActionPreference = 'Stop'
$noBom = [System.Text.UTF8Encoding]::new($false)
$modPath = 'c:\Users\grass\Documents\Symcon\SymconSmartClimate\SmartLawnAI\module.php'
$lines = [System.IO.File]::ReadAllLines($modPath)

$newLines = @()
$i = 0
while ($i -lt $lines.Length) {
    $line = $lines[$i]
    
    if ($line.Contains("IPS_RequestAction(`$_IPS['TARGET'], 'ResetForceStart', true)")) {
        $line = $line.Replace("IPS_RequestAction(`$_IPS['TARGET'], 'ResetForceStart', true)", "SLAI_ResetForceStart(`$_IPS['TARGET'])")
        $newLines += $line
    } elseif ($line -match "public function ApplyChanges\(\): void \{") {
        $newLines += $line
        $newLines += "        parent::ApplyChanges();"
        $newLines += "        // Alte Message-Registrierungen entfernen"
        $newLines += "        foreach (`$this->GetMessageList() as `$senderID => `$messages) {"
        $newLines += "            foreach (`$messages as `$message) {"
        $newLines += "                `$this->UnregisterMessage(`$senderID, `$message);"
        $newLines += "            }"
        $newLines += "        }"
        $i++ # skip the original parent::ApplyChanges()
        while ($lines[$i] -match "parent::ApplyChanges\(\)") {
            $i++
        }
        continue
    } elseif ($line -match "public function RunTestCommand") {
        $newLines += "    public function ResetForceStart(): void {"
        $newLines += "        `$this->SetValue('ForceStart', false);"
        $newLines += "    }"
        $newLines += ""
        $newLines += $line
    } elseif ($line.Contains("SafeRequestAction(`$res['DurationID'], 5)")) {
        $newLines += "                `$testErr = '';"
        $newLines += "                `$ok = `$this->SafeRequestAction(`$res['DurationID'], 5, `$testErr); // 5 Minuten"
        $newLines += "                if (!`$ok) { echo 'Fehler: ' . `$testErr; return; }"
    } elseif ($line.Contains("SafeRequestAction(`$res['ValveID'], 'START_SECONDS_TO_OVERRIDE')")) {
        $newLines += "                `$testErr = '';"
        $newLines += "                `$ok = `$this->SafeRequestAction(`$res['ValveID'], 'START_SECONDS_TO_OVERRIDE', `$testErr);"
        $newLines += "                if (!`$ok) { echo 'Fehler: ' . `$testErr; return; }"
    } elseif ($line.Contains("SafeRequestAction(`$res['ValveID'], 'STOP_UNTIL_NEXT_TASK')")) {
        $newLines += "                    `$testErr = '';"
        $newLines += "                    `$ok = `$this->SafeRequestAction(`$res['ValveID'], 'STOP_UNTIL_NEXT_TASK', `$testErr);"
        $newLines += "                    if (!`$ok) { echo 'Fehler: ' . `$testErr; return; }"
    } elseif ($line.Contains("SafeRequestAction(`$res['ValveID'], false)")) {
        $newLines += "                    `$testErr = '';"
        $newLines += "                    `$ok = `$this->SafeRequestAction(`$res['ValveID'], false, `$testErr);"
        $newLines += "                    if (!`$ok) { echo 'Fehler: ' . `$testErr; return; }"
    } else {
        $newLines += $line
    }
    $i++
}

[System.IO.File]::WriteAllLines($modPath, $newLines, $noBom)

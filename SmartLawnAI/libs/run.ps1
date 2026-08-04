$file = 'c:\Users\grass\Documents\Symcon\SymconSmartClimate\SmartLawnAI\libs\Trait_Logic.php'
$noBom = [System.Text.UTF8Encoding]::new($false)
$lines = [System.IO.File]::ReadAllLines($file)

$lines[185] = $lines[185].Replace('$aktiveStatus', '$displayAktivStatus')

$lines[380] = "                                `$this->SLogError('TIMEOUT beim Ventil-Start', 'Sprinkler: ' . `$currentSprinklerName . ' meldet nicht OPEN nach 3 Minuten');"
$lines[381] = "                                `$this->SetZoneStatus(`$zone['SensorID'], 'HARDWARE_FEHLER');"
$lines[382] = "                                `$this->SetValue('DeviceAvailable', 0);"

$lines[61] = "        `$t = (`$airTempID > 0 && IPS_VariableExists(`$airTempID)) ? (float)GetValue(`$airTempID) : 20.0;"
$lines[62] = "        `$rh = (`$humidityID > 0 && IPS_VariableExists(`$humidityID)) ? (float)GetValue(`$humidityID) : 50.0;"
$lines[63] = "        `$lux = (`$illuminanceID > 0 && IPS_VariableExists(`$illuminanceID)) ? (float)GetValue(`$illuminanceID) : 0.0;"

$lines[121] = "        `$t = (`$airTempID > 0 && IPS_VariableExists(`$airTempID)) ? (float)GetValue(`$airTempID) : 20.0;"
$lines[122] = "        `$rh = (`$humidityID > 0 && IPS_VariableExists(`$humidityID)) ? (float)GetValue(`$humidityID) : 50.0;"
$lines[123] = "        `$lux = (`$illuminanceID > 0 && IPS_VariableExists(`$illuminanceID)) ? (float)GetValue(`$illuminanceID) : 0.0;"

$lines[428] = "                                `$wEnd   = (`$wLiterID > 0 && IPS_VariableExists(`$wLiterID)) ? (float)GetValue(`$wLiterID) : 0.0;"

$insertH2Start = @"
        if (!IPS_SemaphoreEnter('SmartLawnAI_' . `$this->InstanceID, 500)) {
            return; // Bereits in Bearbeitung
        }
        try {
"@

$insertH2End = @"
        } finally {
            IPS_SemaphoreLeave('SmartLawnAI_' . `$this->InstanceID);
"@

$insertK3 = @"
                    // Failsafe: Absolutes Maximum-Timeout (verhindert endloses WATERING bei Cloud-Freeze)
                    `$maxDur = `$this->GetZoneDauer(`$zone['SensorID']);
                    `$waterStart = `$this->GetZoneWateringStart(`$zone['SensorID']);
                    `$absoluteMax = max((`$maxDur * 60) + 600, 3600); // Geplante Dauer + 10min Puffer, mind. 1h
                    if (`$waterStart > 0 && (time() - `$waterStart) > `$absoluteMax) {
                        `$zoneName = isset(`$zone['GroupName']) && !empty(`$zone['GroupName']) ? `$zone['GroupName'] : 'Zone '. `$zone['SensorID'];
                        `$this->SLogError('FAILSAFE TIMEOUT', `$zoneName . ': Bewaesserung laeuft seit ' . round((time() - `$waterStart) / 60) . ' Min. Erzwinge Abbruch.');
                        `$this->AddLogEvent("{`$zoneName}: Failsafe Timeout", "Bewaesserung nach " . round((time() - `$waterStart) / 60) . " Min erzwungen beendet", '#F44336');
                        `$this->SetZoneStatus(`$zone['SensorID'], 'IDLE');
                        `$this->SetValue('DeviceAvailable', 0);
                        // Ventil stoppen falls moeglich
                        if (`$res['ValveID'] > 0) {
                            `$this->SafeRequestAction(`$res['ValveID'], 'STOP_UNTIL_NEXT_TASK');
                        }
                        `$einVentilIstAktiv = false;
                        break;
                    }
"@

$insertK2 = @"
                case 'HARDWARE_FEHLER':
                    // Recovery: Hardware erneut pruefen
                    if (`$this->isZoneHardwareOk(`$zone, `$sprinklers)) {
                        `$this->SetZoneStatus(`$zone['SensorID'], 'IDLE');
                        `$this->SetValue('DeviceAvailable', 1);
                        `$zoneName = isset(`$zone['GroupName']) && !empty(`$zone['GroupName']) ? `$zone['GroupName'] : 'Zone '. `$zone['SensorID'];
                        `$this->AddLogEvent("{`$zoneName}: Hardware OK", "Sprinkler wieder erreichbar", '#4CAF50');
                        `$this->SLogInfo('Hardware Recovery', `$zoneName . ' ist wieder OK');
                    }
                    break;
"@

$insertK4 = @"
                                `$zoneName = isset(`$zone['GroupName']) && !empty(`$zone['GroupName']) ? `$zone['GroupName'] : 'Zone '. `$zone['SensorID'];
                                `$this->AddLogEvent("{`$zoneName}: Timeout", "{`$currentSprinklerName} meldet nach 3 Min nicht OPEN. Hardware-Fehler.", '#F44336');
                                break;
"@

$insertM2a = "                                        `$this->AddLogEvent(`"{`$zoneName}: Warnung`", 'Wasserverbrauch unplausibel: ' . `$consumed . ' L', '#FF9800');"

$insertM2b = @"
                                    `$zoneName = isset(`$zone['GroupName']) && !empty(`$zone['GroupName']) ? `$zone['GroupName'] : 'Zone '. `$zone['SensorID'];
                                    `$this->AddLogEvent("{`$zoneName}: Warnung", 'Wasserzaehler-Startwert ungueltig', '#FF9800');
"@

$newLines = New-Object System.Collections.Generic.List[string]

for ($i = 0; $i -lt $lines.Length; $i++) {
    
    if ($i -eq 74) { $newLines.AddRange($insertH2Start -split "`r?`n") }
    if ($i -eq 409) { $newLines.AddRange($insertK3 -split "`r?`n") }
    if ($i -eq 475) { $newLines.AddRange($insertK2 -split "`r?`n") }
    if ($i -eq 611) { $newLines.AddRange($insertH2End -split "`r?`n") }
    
    if ($i -eq 227 -or $i -eq 369 -or $i -eq 370) {
        continue
    }

    $newLines.Add($lines[$i])

    if ($i -eq 382) { $newLines.AddRange($insertK4 -split "`r?`n") }
    if ($i -eq 437) { $newLines.AddRange($insertM2a -split "`r?`n") }
    if ($i -eq 440) { $newLines.AddRange($insertM2b -split "`r?`n") }
}

[System.IO.File]::WriteAllLines($file, $newLines.ToArray(), $noBom)

php -l $file

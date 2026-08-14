<?php

declare(strict_types=1);

trait SmartLawnAI_Logic {

    public function ScheduledEvaluation(): void {
        $active = GetValue($this->GetIDForIdent('AutomaticActive'));
        if (!$active) return;
        
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (!is_array($zones) || empty($zones)) return;
        
        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = json_decode($sprinklersJson, true);
        if (!is_array($sprinklers)) $sprinklers = [];
        
        // Prüfen, ob bereits ein Ventil aktiv ist
        foreach ($zones as $zone) {
            $status = $this->GetZoneStatus($zone['SensorID']);
            if ($status === 'WATERING'|| $status === 'QUEUED') {
                $this->LogAndDebug('Planer', 'Zyklusprüfung übersprungen: Ein Ventil ist bereits aktiv oder in Warteschlange.', 0);
                return;
            }
        }
        
        // Sperrzeit prüfen (Switch aktiviert die Funktion, IsTimeForbidden prüft die aktuelle Zeit)
        if ($this->GetValue('SperrzeitActive') && $this->IsTimeForbidden(time())) {
            $this->LogAndDebug('Planer', 'Zyklusprüfung übersprungen: Sperrzeit aktiv.', 0);
            $this->AddLogEvent("Zyklusprüfung", "Sperrzeit aktiv. Keine automatische Bewässerung.", '#FF9800');
            return;
        }

        $defaultStart = GetValue($this->GetIDForIdent('DefaultStartSchwellwert'));
        $needsWater = false;
        foreach ($zones as $zone) {
            $sensor = $this->ResolveSensorObject((int)$zone['SensorID']);
            if ($sensor['MoistureID'] <= 0 || !IPS_VariableExists($sensor['MoistureID'])) continue;
            $aktuelleFeuchte = GetValue($sensor['MoistureID']);
            // 0% = Sensorstoerung (Batterie leer, Funk weg, defekt) - NICHT bewaessern!
            if ($aktuelleFeuchte <= 0) {
                $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                $this->SLogWarning('Sensorstoerung ' . $zoneName, 'Feuchte = 0% ist unplausibel. Zone wird uebersprungen.');
                $this->AddLogEvent("{$zoneName}: Sensorstoerung", "Feuchte 0% - Sensor defekt/offline? Zone wird nicht bewaessert.", '#F44336');
                continue;
            }
            if ($aktuelleFeuchte <= $defaultStart) {
                $needsWater = true;
                break;
            }
        }

        if (!$needsWater) {
            $this->LogAndDebug('Planer', 'Zyklusprüfung: Boden ist ausreichend feucht. Keine Bewässerung nötig.', 0);
            $this->AddLogEvent("Zyklusprüfung", "Boden ist ausreichend feucht. Keine Bewässerung nötig.", '#4CAF50');
            // Wir setzen den Zeitstempel für das Webfront neu, um zu zeigen, dass wir geprüft haben
            $this->SetBuffer('LastPlanCalculation', (string)time());
            $this->ProcessLogic(); // Update Heartbeat
            return;
        }

        $this->LogAndDebug('Planer', 'Zyklusprüfung: Boden ist trocken. Hole Wetter und berechne Laufzeiten...', 0);
        $this->AddLogEvent("Zyklusprüfung", "Boden lokal trocken. Hole Wetter und frage KI...", '#9E9E9E');
        $this->UpdateWeather();
        
        $airTempID = $this->ReadPropertyInteger('GlobalAirTempID');
        $humidityID = $this->ReadPropertyInteger('GlobalHumidityID');
        $illuminanceID = $this->ReadPropertyInteger('GlobalIlluminanceID');
        $t = ($airTempID > 0 && IPS_VariableExists($airTempID)) ? (float)GetValue($airTempID) : 20.0;
        $rh = ($humidityID > 0 && IPS_VariableExists($humidityID)) ? (float)GetValue($humidityID) : 50.0;
        $lux = ($illuminanceID > 0 && IPS_VariableExists($illuminanceID)) ? (float)GetValue($illuminanceID) : 0.0;
        $es = 0.6108 * exp((17.27 * $t) / ($t + 237.3));
        $vpd = $es * (1 - ($rh / 100.0));

        $this->SetBuffer('LastPlanCalculation', (string)time());
        $this->CalculateAndApplyPlan($zones, $sprinklers, false, $vpd, $lux);
        
        $this->ProcessLogic(); // Update Heartbeat und Starte Zonen-Durchlauf
    }

    public function ProcessLogic(): void {
        if (!IPS_SemaphoreEnter('SmartLawnAI_' . $this->InstanceID, 500)) {
            return; // Bereits in Bearbeitung
        }
        try {
        $defaultZiel  = GetValue($this->GetIDForIdent('DefaultZielFeuchte'));
        $defaultStart = GetValue($this->GetIDForIdent('DefaultStartSchwellwert'));
        
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        
        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = json_decode($sprinklersJson, true);
        if (!is_array($sprinklers)) $sprinklers = [];
        
        if (!is_array($zones) || empty($zones)) {
            return; 
        }

        // Zustände, die ein physisch aktives Ventil signalisieren (blockiert andere Zonen)
        $blockierendeStatus = ['WATERING', 'WAITING_FOR_OPEN', 'WAITING_FOR_RESULT'];
        $displayAktivStatus = ['WATERING', 'WAITING_FOR_OPEN'];
        $einVentilIstAktiv = false;
        $anyQueued = false;
        foreach ($zones as $zone) {
            $status = $this->GetZoneStatus($zone['SensorID']);
            if (in_array($status, $blockierendeStatus)) {
                $einVentilIstAktiv = true;
                $this->LogAndDebug('Sequencer', 'Ein anderes Ventil blockiert die Sequenz ('. $status . ' bei Zone '. $zone['SensorID'] . '). Warte...', 0);
            }
            if ($status === 'QUEUED') {
                $anyQueued = true;
            }
        }
        
        // WateringActive: Zeigt "Bewässert" wenn ein Ventil läuft ODER Zonen in Warteschlange stehen
        $displayAktiv = $einVentilIstAktiv || $anyQueued;
        $wasActive = $this->GetValue('WateringActive');
        $this->SetValue('WateringActive', $displayAktiv);
        if ($wasActive !== $displayAktiv) {
            $this->NotifySmartControllerIrrigation($displayAktiv);
        }

        // 2. Thermodynamik (VPD) für alle Zonen vorbereiten
        $airTempID = $this->ReadPropertyInteger('GlobalAirTempID');
        $humidityID = $this->ReadPropertyInteger('GlobalHumidityID');
        $illuminanceID = $this->ReadPropertyInteger('GlobalIlluminanceID');

        $t = ($airTempID > 0 && IPS_VariableExists($airTempID)) ? (float)GetValue($airTempID) : 20.0;
        $rh = ($humidityID > 0 && IPS_VariableExists($humidityID)) ? (float)GetValue($humidityID) : 50.0;
        $lux = ($illuminanceID > 0 && IPS_VariableExists($illuminanceID)) ? (float)GetValue($illuminanceID) : 0.0;

        $es = 0.6108 * exp((17.27 * $t) / ($t + 237.3));
        $vpd = $es * (1 - ($rh / 100.0));

        // 3. Laufzeit-Steuerung des Timers
        $active = GetValue($this->GetIDForIdent('AutomaticActive'));
        if ($active) {
            $this->SetTimerInterval('LawnAITimer', 60000);
        } else {
            $this->SetTimerInterval('LawnAITimer', 0);
        }

        // 3c. Aktuellen Durchfluss von SmartWaterMonitor aktualisieren
        $wFlowID = $this->GetWaterMeterFlowRateVarID();
        if ($wFlowID > 0 && IPS_VariableExists($wFlowID)) {
            $this->SetValue('CurrentFlowRate', (float)GetValue($wFlowID));
        }

        // 3d. Echtzeit-Wasserverbrauch (live hochzaehlen bei jedem Tick)
        $wLiterID = $this->GetWaterMeterLiterVarID();
        if ($wLiterID > 0 && IPS_VariableExists($wLiterID)) {
            $currentLiters = (float)GetValue($wLiterID);
            $lastTickRaw = $this->GetBuffer('WaterMeterLastTick');

            if ($einVentilIstAktiv) {
                if ($lastTickRaw !== '') {
                    $delta = round($currentLiters - (float)$lastTickRaw, 1);
                    if ($delta > 0 && $delta < 100) {
                        $this->SetValue('WaterToday',     round($this->GetValue('WaterToday')     + $delta, 1));
                        $this->SetValue('WaterThisWeek',  round($this->GetValue('WaterThisWeek')  + $delta, 1));
                        $this->SetValue('WaterThisMonth', round($this->GetValue('WaterThisMonth') + $delta, 1));
                    }
                }
                $this->SetBuffer('WaterMeterLastTick', (string)$currentLiters);
            } else {
                if ($lastTickRaw !== '') {
                    $delta = round($currentLiters - (float)$lastTickRaw, 1);
                    if ($delta > 0 && $delta < 100) {
                        $this->SetValue('WaterToday',     round($this->GetValue('WaterToday')     + $delta, 1));
                        $this->SetValue('WaterThisWeek',  round($this->GetValue('WaterThisWeek')  + $delta, 1));
                        $this->SetValue('WaterThisMonth', round($this->GetValue('WaterThisMonth') + $delta, 1));
                    }
                    $this->SetBuffer('WaterMeterLastTick', '');
                }
            }
        }

        // Manueller Start
        $isManualStart = ($this->GetBuffer('CalculatePlanPending') === 'true');
        if ($isManualStart) {
            $this->SetBuffer('CalculatePlanPending', '');
            $this->LogAndDebug('Planer', 'Neuer Bewässerungszyklus (manuell) initiiert. Berechne Laufzeiten...', 0);
            
            // Wenn keine Koordinaten, UpdateWeather hat keinen Effekt, aber sicherheitshalber aufrufen
            $this->UpdateWeather();
            
            $this->CalculateAndApplyPlan($zones, $sprinklers, true, $vpd, $lux);
            
            $einVentilIstAktiv = false;
            foreach ($zones as $zone) {
                $status = $this->GetZoneStatus($zone['SensorID']);
                if (in_array($status, $displayAktivStatus)) {
                    $einVentilIstAktiv = true;
                }
            }
        }

        // 4. Zonen-Durchlauf (State Machine)
        foreach ($zones as $zone) {
            $zielWert  = $defaultZiel;
            $startWert = $defaultStart;
            
            $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
            $zoneSprinklers = [];
            foreach ($sprinklers as $s) {
                if ($s['ZoneName'] === $zoneName) {
                    $zoneSprinklers[] = $s;
                }
            }

            if (!IPS_VariableExists($zone['SensorID']) && !IPS_InstanceExists($zone['SensorID'])) continue;
            $sensor = $this->ResolveSensorObject((int)$zone['SensorID']);
            if ($sensor['MoistureID'] <= 0) continue;
            $aktuelleFeuchte = GetValue($sensor['MoistureID']);
            $aktuellerStatus = $this->GetZoneStatus($zone['SensorID']);
            if (empty($aktuellerStatus)) {
                $aktuellerStatus = 'IDLE';
            }
            $this->SendDebug('ProcessLogic', 'Bearbeite Zone '. $zone['SensorID'] . '(Aktueller Status: '. $aktuellerStatus . ')', 0);

            if (empty($zoneSprinklers)) {
                $this->LogAndDebug('ProcessLogic', 'Zone '. $zone['SensorID'] . 'hat keine zugeordneten Sprinkler. Überspringe.', 0);
                continue;
            }

            // Gardena Not-Aus Check (prüfe alle Sprinkler dieser Zone)
            $hardwareFehler = false;
            $fehlerhafterSprinklerName = '';
            foreach ($zoneSprinklers as $s) {
                $res = $this->ResolveSprinklerObject((int)@$s['ValveID']);
                if ($res['HardwareStatusID'] > 0) {
                    $hwStatus = GetValue($res['HardwareStatusID']);
                    $hwStr = strtoupper((string)$hwStatus);
                    // String-basierte Prüfung (altes Gardena-Modul) + Integer-Prüfung (neues Modul: 0=OK, >0=Fehler)
                    $istFehler = in_array($hwStr, ['ERROR', 'WARNING', 'OFFLINE', 'DEFECT', 'FAULT'])
                                 || (is_int($hwStatus) && $hwStatus > 0);
                    if ($istFehler) {
                        $sName = isset($s['SprinklerName']) && !empty($s['SprinklerName']) ? $s['SprinklerName'] : 'Sprinkler '. $s['ValveID'];
                        $this->LogAndDebug('Hardware-Check', 'Zone ' . $zone['SensorID'] . ' ' . $sName . ' meldet Fehler: ' . $hwStr, 0);
                        $hardwareFehler = true;
                        $fehlerhafterSprinklerName = $sName;
                    }
                }
            }
            if ($hardwareFehler) {
                $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone ' . $zone['SensorID'];
                $hwVal = GetValue($res['HardwareStatusID']);
                $hwDetail = 'Sprinkler: ' . $fehlerhafterSprinklerName . ' | Status: ' . strtoupper((string)$hwVal);
                $this->SetValue('DeviceAvailable', 0);
                $this->SetZoneStatus($zone['SensorID'], 'HARDWARE_FEHLER');
                $this->SLogError('Hardware-Fehler ' . ($zone['GroupName'] ?? $zone['SensorID']), $hwDetail);
                $this->AddLogEvent("{$zoneName}: Hardware Fehler", $hwDetail, '#F44336');
                continue;
            }

            $currentIndex = $this->GetZoneCurrentSprinklerIndex($zone['SensorID']);
            if (!isset($zoneSprinklers[$currentIndex])) {
                $currentIndex = 0;
            }
            $currentSprinkler = $zoneSprinklers[$currentIndex];
            $currentSprinklerName = isset($currentSprinkler['SprinklerName']) && !empty($currentSprinkler['SprinklerName']) ? $currentSprinkler['SprinklerName'] : 'Sprinkler '. $currentSprinkler['ValveID'];

            switch ($aktuellerStatus) {
                case 'IDLE':
                case 'QUEUED':
                    $sollStarten = ($aktuellerStatus === 'QUEUED');

                    if ($sollStarten) {
                        if ($einVentilIstAktiv) {
                            $this->LogAndDebug('Sequencer', 'Zone '. $zone['SensorID'] . 'bleibt QUEUED, da ein anderes Ventil aktiv ist.', 0);
                            $this->SetZoneStatus($zone['SensorID'], 'QUEUED');
                        } else {
                            $this->LogAndDebug('Sequencer', 'Startbedingung erfüllt. Bereite Befehl vor...', 0);
                            
                            $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                            
                            // Berechnete Laufzeit aus Puffer lesen
                            $berechneteMinuten = $this->GetZoneDauer($zone['SensorID']);
                            if ($berechneteMinuten <= 0) {
                                $this->LogAndDebug('Sequencer', 'Zone '. $zone['SensorID'] . 'hat keine gültige Dauer. Überspringe.', 0);
                                $this->SetZoneStatus($zone['SensorID'], 'IDLE');
                                continue 2;
                            }

                            $res = $this->ResolveSprinklerObject((int)@$currentSprinkler['ValveID']);
                            // Gardena Hardware-Watchdog: Dauer setzen
                            if ($res['DurationID'] > 0) {
                                $this->SafeRequestAction($res['DurationID'], $berechneteMinuten);
                            }

                            // Start-Befehl senden (Gardena spezifisch)
                            $startErfolgreich = false;
                            $startError = '';
                            if ($res['ValveID'] > 0) {
                                if (IPS_VariableExists($res['ValveID']) && in_array(strtolower(IPS_GetObject($res['ValveID'])['ObjectIdent']), ['action', 'valvecontrol', 'control'])) {
                                    $startErfolgreich = $this->SafeRequestAction($res['ValveID'], 'START_SECONDS_TO_OVERRIDE', $startError);
                                } else {
                                    $startErfolgreich = $this->SafeRequestAction($res['ValveID'], true, $startError);
                                }
                            } else {
                                $startError = 'ValveID nicht aufloesbar (ValveID=0)';
                            }
                            
                            if ($startErfolgreich) {
                                $this->SetValue('DeviceAvailable', 1);
                                $this->SLogInfo('Bewaesserungs-Startbefehl gesendet', 'Zone: ' . $zone['SensorID'] . ' | Sprinkler: ' . $currentSprinklerName);
                                $this->SetZoneStatus($zone['SensorID'], 'WAITING_FOR_OPEN');
                                $this->SetZoneWateringStart($zone['SensorID'], time());
                                $this->SetZoneCurrentSprinklerIndex($zone['SensorID'], $currentIndex);
                                $this->AddLogEvent("{$zoneName}: Starte Bewaesserung", "Sprinkler: {$currentSprinklerName}", '#2196F3');
                                
                                // Zwischenspeichern fuer den Lern-Algorithmus spaeter
                                $this->SetZoneStartFeuchte($zone['SensorID'], $aktuelleFeuchte);
                                $this->SetZoneDauer($zone['SensorID'], $berechneteMinuten);
                                
                                // Wasserzaehler-Startwert merken (nur beim Start der Zone oder wenn Buffer leer)
                                $wLiterID = $this->GetWaterMeterLiterVarID();
                                if ($wLiterID > 0) {
                                    $existingBuffer = $this->GetBuffer('WaterMeterStart_' . $zone['SensorID']);
                                    if ($existingBuffer === '' || $currentIndex === 0) {
                                        $this->SetBuffer('WaterMeterStart_' . $zone['SensorID'], (string)GetValue($wLiterID));
                                    }
                                }
                                
                                $einVentilIstAktiv = true;
                            } else {
                                $errDetail = $startError !== '' ? $startError : 'SafeRequestAction gab false zurueck';
                                $errInfo = 'Ventil-ID: ' . $res['ValveID'] . ' | ' . $errDetail;
                                $this->SetValue('DeviceAvailable', 0);
                                $this->SetZoneStatus($zone['SensorID'], 'HARDWARE_FEHLER');
                                $this->LogAndDebug('Sequencer', 'HARDWARE_FEHLER Zone ' . $zone['SensorID'] . ': ' . $errInfo, 0);
                                $this->AddLogEvent("{$zoneName}: Hardware Fehler", $errInfo, '#F44336');
                                $this->SLogError('Hardware-Fehler Zone ' . ($zone['GroupName'] ?? $zone['SensorID']), $errInfo);
                            }
                        }
                    } else {
                        $this->SetZoneStatus($zone['SensorID'], 'IDLE');
                    }
                    break;
                    
                case 'WAITING_FOR_OPEN':
                case 'WATERING':
                    // Ventil-Rückkanal von Gardena prüfen
                    $ventilOffen = false;
                    $hwVal = 'UNKNOWN';
                    $res = $this->ResolveSprinklerObject((int)@$currentSprinkler['ValveID']);
                    
                    if ($res['ActivityID'] > 0) {
                        $v = GetValue($res['ActivityID']);
                        if (is_int($v) || is_float($v)) {
                            $act = strtoupper((string)GetValueFormatted($res['ActivityID']));
                        } else {
                            $act = strtoupper((string)$v);
                        }
                        $hwVal = $act;
                        $ventilOffen = (strpos($act, 'WATERING') !== false || strpos($act, 'BEWÄSSERUNG') !== false || strpos($act, 'BEWAESSERUNG') !== false || strpos($act, 'OPEN') !== false || strpos($act, 'GEÖFFNET') !== false || $act === 'MANUELLE BEWAESSERUNG' || $act === 'ZEITPLAN AKTIV' || $v == 1 || $v == 2);
                    }
                    elseif ($res['HardwareStatusID'] > 0) {
                        $hwVal = strtoupper((string)GetValue($res['HardwareStatusID']));
                        $ventilOffen = in_array($hwVal, ['MANUAL_WATERING', 'AUTOMATIC_WATERING', 'WATERING', 'OPEN', 'GEÖFFNET', 'BEWÄSSERUNG']);
                    } else {
                        if ($res['ValveID'] > 0 && IPS_VariableExists($res['ValveID'])) {
                            $v = GetValue($res['ValveID']);
                            $ventilOffen = ($v == 1 || $v === true); // 1 = START_SECONDS_TO_OVERRIDE
                        }
                    }
                    
                    // Fallback: Nur wenn KEIN ActivityID vorhanden ist (generische Ventile ohne Activity-Variable)
                    // Wenn ActivityID vorhanden ist und CLOSED meldet, darf RemainingSeconds das NICHT ueberschreiben!
                    if (!$ventilOffen && $res['ActivityID'] === 0 && $res['RemainingSecondsID'] > 0) {
                        if ((int)GetValue($res['RemainingSecondsID']) > 0) {
                            $ventilOffen = true;
                            $hwVal .= '(Kept alive by RemainingSeconds > 0)';
                        }
                    }

                    if ($aktuellerStatus === 'WAITING_FOR_OPEN') {
                        if ($ventilOffen) {
                            $this->LogAndDebug('Sequencer', 'Rückmeldung erhalten: Ventil ist OFFEN. Bewässerung läuft.', 0);
                            $this->SetValue('DeviceAvailable', 1);
                            $this->SetZoneStatus($zone['SensorID'], 'WATERING');
                            $wLiterID = $this->GetWaterMeterLiterVarID();
                            if ($wLiterID > 0 && $this->GetBuffer('WaterMeterStart_' . $zone['SensorID']) === '') {
                                $this->SetBuffer('WaterMeterStart_' . $zone['SensorID'], (string)GetValue($wLiterID));
                            }
                            $aktuellerStatus = 'WATERING';
                        } else {
                            $wateringStart = $this->GetZoneWateringStart($zone['SensorID']);
                            if ((time() - $wateringStart) > 180) { // 3 Minuten Timeout!
                                $this->SLogError('TIMEOUT beim Ventil-Start', 'Sprinkler: ' . $currentSprinklerName . ' meldet nicht OPEN nach 3 Minuten');
                                $this->SetZoneStatus($zone['SensorID'], 'HARDWARE_FEHLER');
                                $this->SetValue('DeviceAvailable', 0);
                                $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                                $this->AddLogEvent("{$zoneName}: Timeout", "{$currentSprinklerName} meldet nach 3 Min nicht OPEN. Hardware-Fehler.", '#F44336');
                                break;
                            } else {
                                $this->LogAndDebug('Sequencer', 'Warte auf Cloud-Rückmeldung (bisher '. (time()-$wateringStart) . 's)', 0);
                                $einVentilIstAktiv = true; // Blockiere andere Zonen
                            }
                        }
                    }
                    
                    if ($aktuellerStatus === 'WATERING') {
                    $remaining = 0;
                    if ($res['RemainingSecondsID'] > 0) {
                        $remaining = (int)GetValue($res['RemainingSecondsID']);
                    } else {
                        $wStart = $this->GetZoneWateringStart($zone['SensorID']);
                        $dMin = $this->GetZoneDauer($zone['SensorID']);
                        if ($wStart > 0 && $dMin > 0) {
                            $remaining = max(0, ($dMin * 60) - (time() - $wStart));
                        }
                    }
                    if ($remaining > 0) {
                        $m = floor($remaining / 60);
                        $s = $remaining % 60;
                        $remainingText = '(noch '. $m . ':'. str_pad((string)$s, 2, '0', STR_PAD_LEFT) . 'Min)';
                    } else {
                        $remainingText = '';
                    }

                    // Failsafe: Absolutes Maximum-Timeout (verhindert endloses WATERING bei Cloud-Freeze)
                    $maxDur = $this->GetZoneDauer($zone['SensorID']);
                    $waterStart = $this->GetZoneWateringStart($zone['SensorID']);
                    $absoluteMax = max(($maxDur * 60) + 600, 3600); // Geplante Dauer + 10min Puffer, mind. 1h
                    if ($waterStart > 0 && (time() - $waterStart) > $absoluteMax) {
                        $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                        $this->SLogError('FAILSAFE TIMEOUT', $zoneName . ': Bewaesserung laeuft seit ' . round((time() - $waterStart) / 60) . ' Min. Erzwinge Abbruch.');
                        $this->AddLogEvent("{$zoneName}: Failsafe Timeout", "Bewaesserung nach " . round((time() - $waterStart) / 60) . " Min erzwungen beendet", '#F44336');
                        $this->SetZoneStatus($zone['SensorID'], 'IDLE');
                        $this->SetValue('DeviceAvailable', 0);
                        // Ventil stoppen falls moeglich
                        if ($res['ValveID'] > 0) {
                            $this->SafeRequestAction($res['ValveID'], 'STOP_UNTIL_NEXT_TASK');
                        }
                        $einVentilIstAktiv = false;
                        break;
                    }
                    if (!$ventilOffen && $aktuellerStatus === 'WATERING') {
                        $this->SLogInfo('Bewässerung beendet', 'Sprinkler: ' . $currentSprinklerName . ' in Zone ' . $zone['SensorID'] . ' | Status: ' . $hwVal);
                        
                        $currentIndex++;
                        if ($currentIndex < count($zoneSprinklers)) {
                            // Nächster Sprinkler in dieser Zone
                            $this->SetZoneCurrentSprinklerIndex($zone['SensorID'], $currentIndex);
                            $this->SetZoneStatus($zone['SensorID'], 'QUEUED');
                            $this->LogAndDebug('Sequencer', 'Sprinkler gewechselt. Nächster Index: '. $currentIndex, 0);
                            
                            $nextSprinklerName = isset($zoneSprinklers[$currentIndex]['SprinklerName']) && !empty($zoneSprinklers[$currentIndex]['SprinklerName']) ? $zoneSprinklers[$currentIndex]['SprinklerName'] : 'Sprinkler '. ($currentIndex + 1);
                            $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                            $this->AddLogEvent("{$zoneName}: Sprinklerwechsel", "Nächster Sprinkler: {$nextSprinklerName}", '#2196F3');
                        } else {
                            // Alle Sprinkler der Zone fertig â†’ Wasserverbrauch berechnen
                            $wLiterID = $this->GetWaterMeterLiterVarID();
                            if ($wLiterID > 0) {
                                $wStartRaw = $this->GetBuffer('WaterMeterStart_' . $zone['SensorID']);
                                $wStart = (float)$wStartRaw;
                                $wEnd   = ($wLiterID > 0 && IPS_VariableExists($wLiterID)) ? (float)GetValue($wLiterID) : 0.0;
                                
                                if ($wStartRaw !== '' && $wStart > 0 && $wEnd >= $wStart) {
                                    $consumed = round($wEnd - $wStart, 1);
                                    if ($consumed > 0 && $consumed < 5000) {
                                        $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                                        $this->AddLogEvent("{$zoneName}: Verbrauch", "{$consumed} L verbraucht", '#03A9F4');
                                        $this->SLogInfo('Wasserverbrauch Zone ' . ($zone['GroupName'] ?? $zone['SensorID']), $consumed . ' L');
                                    } else {
                                        $this->SLogWarning('Wasserverbrauch unplausibel, ignoriert: ' . $consumed . ' L (Start: ' . $wStart . ' L, Ende: ' . $wEnd . ' L)');
                                        $this->AddLogEvent("{$zoneName}: Warnung", 'Wasserverbrauch unplausibel: ' . $consumed . ' L', '#FF9800');
                                    }
                                } else {
                                    $this->SLogWarning('Wasserzähler-Startwert ungültig oder nicht vorhanden (Start: ' . $wStartRaw . ' L, Ende: ' . $wEnd . ' L)');
                                    $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                                    $this->AddLogEvent("{$zoneName}: Warnung", 'Wasserzaehler-Startwert ungueltig', '#FF9800');
                                }
                                $this->SetBuffer('WaterMeterStart_' . $zone['SensorID'], '');
                            }

                            $this->SetZoneCurrentSprinklerIndex($zone['SensorID'], 0); // Reset
                            $this->SetZoneStatus($zone['SensorID'], 'WAITING_FOR_RESULT');
                            $this->SetZoneSickerpauseStart($zone['SensorID'], time());
                            $this->LogAndDebug('Sequencer', 'Alle Sprinkler fertig. Sickerpause gestartet.', 0);
                            
                            $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                            $sickerpauseMin = $this->GetZoneSickerpauseMinuten($zone['SensorID']);
                            $this->AddLogEvent("{$zoneName}: Sickerpause", "Warte {$sickerpauseMin} Minuten auf Sensormessung", '#FF9800');
                        }
                    }
                        }
                    break;

                case 'WAITING_FOR_RESULT':
                    $sickerStart = $this->GetZoneSickerpauseStart($zone['SensorID']);
                    // Sickerpause in Sekunden abwarten
                    $sickerpauseSek = $this->GetZoneSickerpauseMinuten($zone['SensorID']) * 60;
                    if ((time() - $sickerStart) > $sickerpauseSek) {
                        
                        // Lernerfolg auswerten via Gemini
                        $startFeuchte = $this->GetZoneStartFeuchte($zone['SensorID']);
                        $dauer = $this->GetZoneDauer($zone['SensorID']);
                        
                        if ($dauer > 0) {
                            $this->EvaluateEfficiencyWithGemini($zone['SensorID'], $startFeuchte, $aktuelleFeuchte, $dauer, $vpd, $lux);
                        }

                        $this->SetZoneStatus($zone['SensorID'], 'IDLE');
                    }
                    break;
                case 'HARDWARE_FEHLER':
                    // Recovery: Hardware erneut pruefen
                    if ($this->isZoneHardwareOk($zone, $sprinklers)) {
                        $this->SetZoneStatus($zone['SensorID'], 'QUEUED');
                        $this->SetValue('DeviceAvailable', 1);
                        $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                        $this->AddLogEvent("{$zoneName}: Hardware OK", "Sprinkler wieder erreichbar - Bewaesserung wird fortgesetzt", '#4CAF50');
                        $this->SLogInfo('Hardware Recovery', $zoneName . ' ist wieder OK - zurueck in Warteschlange');
                    }
                    break;
            }
        }

        // 4b. Finaler WateringActive-Status nach dem Zonen-Durchlauf
        $finalAktiv = false;
        foreach ($zones as $zone) {
            $status = $this->GetZoneStatus($zone['SensorID']);
            if (in_array($status, $displayAktivStatus)) {
                $finalAktiv = true;
                break;
            }
        }
        $this->SetValue('WateringActive', $finalAktiv);
        if ($wasActive !== $finalAktiv) {
            $this->NotifySmartControllerIrrigation($finalAktiv);
        }

        // 5. Wasserzähler Tages-/Wochen-/Monatsreset
        $today = date('Y-m-d');
        $week  = date('oW');   // ISO Jahr + Wochennummer
        $month = date('Y-m');
        $wBuf  = json_decode($this->ReadAttributeString('WaterResetDates'), true);
        if (!is_array($wBuf)) $wBuf = [];
        
        // Verhindern, dass nach Update direkt auf 0 resettet wird, wenn Attribute komplett leer waren
        if (empty($wBuf)) {
            $wBuf['day'] = $today;
            $wBuf['week'] = $week;
            $wBuf['month'] = $month;
            $this->WriteAttributeString('WaterResetDates', json_encode($wBuf));
        } else {
            if (($wBuf['day']   ?? '') !== $today) { $this->SetValue('WaterToday',     0.0); $wBuf['day']   = $today; }
            if (($wBuf['week']  ?? '') !== $week)  { $this->SetValue('WaterThisWeek',  0.0); $wBuf['week']  = $week; }
            if (($wBuf['month'] ?? '') !== $month) { $this->SetValue('WaterThisMonth', 0.0); $wBuf['month'] = $month; }
            $this->WriteAttributeString('WaterResetDates', json_encode($wBuf));
        }

        // 6. Heartbeat für die Webfront Anzeige (Zeitstempel aktualisieren)
        $automaticActive = GetValue($this->GetIDForIdent('AutomaticActive'));
        if ($automaticActive) {
            $currentStatus = GetValue($this->GetIDForIdent('SummaryStatus'));
            $baseStatus = preg_replace('/ \(\d{2}:\d{2}\)$/', '', $currentStatus);

            $hwZone = null;
            $waterZone = null;
            $sickerZone = null;
            $queuedZone = null;
            
            foreach ($zones as $zone) {
                $status = $this->GetZoneStatus($zone['SensorID']);
                if ($status === 'HARDWARE_FEHLER') $hwZone = $zone;
                elseif ($status === 'WATERING'|| $status === 'WAITING_FOR_OPEN') $waterZone = $zone;
                elseif ($status === 'WAITING_FOR_RESULT') $sickerZone = $zone;
                elseif ($status === 'QUEUED') $queuedZone = $zone;
            }

            $einVentilIstAktivOderFehler = ($hwZone || $waterZone || $sickerZone || $queuedZone);

            if ($hwZone) {
                $zoneName = isset($hwZone['GroupName']) && !empty($hwZone['GroupName']) ? $hwZone['GroupName'] : 'Zone '. $hwZone['SensorID'];
                $baseStatus = 'HARDWARE-FEHLER: '. $zoneName;
            } elseif ($waterZone) {
                $zoneName = isset($waterZone['GroupName']) && !empty($waterZone['GroupName']) ? $waterZone['GroupName'] : 'Zone '. $waterZone['SensorID'];
                
                $zSprinklers = [];
                foreach ($sprinklers as $s) {
                    if ($s['ZoneName'] === $zoneName) $zSprinklers[] = $s;
                }
                $cIdx = $this->GetZoneCurrentSprinklerIndex($waterZone['SensorID']);
                if (!isset($zSprinklers[$cIdx])) $cIdx = 0;
                
                $remainingText = '';
                $cName = 'Sprinkler';
                if (isset($zSprinklers[$cIdx])) {
                    $cSpr = $zSprinklers[$cIdx];
                    $cName = isset($cSpr['SprinklerName']) && !empty($cSpr['SprinklerName']) ? $cSpr['SprinklerName'] : 'Sprinkler '. $cSpr['ValveID'];
                    
                    $rem = 0;
                    if (isset($cSpr['RemainingSecondsID']) && $cSpr['RemainingSecondsID'] > 0) {
                        $rem = (int)GetValue($cSpr['RemainingSecondsID']);
                    } else {
                        $wStart = $this->GetZoneWateringStart($waterZone['SensorID']);
                        $dMin = $this->GetZoneDauer($waterZone['SensorID']);
                        if ($wStart > 0 && $dMin > 0) {
                            $rem = max(0, ($dMin * 60) - (time() - $wStart));
                        }
                    }
                    if ($rem > 0) {
                        $m = floor($rem / 60);
                        $s = $rem % 60;
                        $remainingText = '(noch '. $m . ':'. str_pad((string)$s, 2, '0', STR_PAD_LEFT) . 'Min)';
                    }
                }
                
                $isWaiting = ($this->GetZoneStatus($waterZone['SensorID']) === 'WAITING_FOR_OPEN');
                if ($isWaiting) {
                    $baseStatus = 'Wartet auf Ventil: '. $zoneName . ' ('. $cName . ')';
                } else {
                    // Fortschrittsbalken berechnen
                    $dMin = $this->GetZoneDauer($waterZone['SensorID']);
                    $wStart = $this->GetZoneWateringStart($waterZone['SensorID']);
                    $totalSec = $dMin * 60;
                    $elapsed = time() - $wStart;
                    $pct = ($totalSec > 0) ? min(100, max(0, (int)round(($elapsed / $totalSec) * 100))) : 0;

                    $barColor = ($pct < 50) ? '#0088FF' : (($pct < 85) ? '#00AACC' : '#00CC88');
                    $progressBar = '<div style="margin-top: 10px; font-family: sans-serif;">'
                        . '<div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 5px; opacity: 0.9;">'
                        . '<span>' . $zoneName . ' &middot; ' . $cName . '</span>'
                        . '<span style="font-weight: 600;">' . $pct . '%</span>'
                        . '</div>'
                        . '<div style="background: rgba(128,128,128,0.15); border-radius: 8px; height: 14px; overflow: hidden;">'
                        . '<div style="background: linear-gradient(90deg, ' . $barColor . ', ' . $barColor . '88); width: ' . $pct . '%; height: 100%; border-radius: 8px; transition: width 1s ease;">'
                        . '</div></div>'
                        . '<div style="display: flex; justify-content: space-between; font-size: 11px; opacity: 0.6; margin-top: 4px;">'
                        . '<span>Laufzeit: ' . floor($elapsed / 60) . ' Min</span>';

                    if ($rem > 0) {
                        $progressBar .= '<span>Verbleibend: ' . floor($rem / 60) . ':' . str_pad((string)($rem % 60), 2, '0', STR_PAD_LEFT) . ' Min</span>';
                    }
                    $progressBar .= '</div></div>';

                    $baseStatus = $progressBar;
                }
            } elseif ($sickerZone) {
                $zoneName = isset($sickerZone['GroupName']) && !empty($sickerZone['GroupName']) ? $sickerZone['GroupName'] : 'Zone '. $sickerZone['SensorID'];
                $sickerStart = $this->GetZoneSickerpauseStart($sickerZone['SensorID']);
                $sickerTotal = $this->GetZoneSickerpauseMinuten($sickerZone['SensorID']) * 60;
                $sickerElapsed = time() - $sickerStart;
                $sickerPct = ($sickerTotal > 0) ? min(100, max(0, (int)round(($sickerElapsed / $sickerTotal) * 100))) : 0;
                $sickerRem = max(0, $sickerTotal - $sickerElapsed);

                $baseStatus = '<div style="margin-top: 10px; font-family: sans-serif;">'
                    . '<div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 5px; opacity: 0.9;">'
                    . '<span>Sickerpause &middot; ' . $zoneName . '</span>'
                    . '<span style="font-weight: 600;">' . $sickerPct . '%</span>'
                    . '</div>'
                    . '<div style="background: rgba(128,128,128,0.15); border-radius: 8px; height: 14px; overflow: hidden;">'
                    . '<div style="background: linear-gradient(90deg, #FF9800, #FF980088); width: ' . $sickerPct . '%; height: 100%; border-radius: 8px; transition: width 1s ease;">'
                    . '</div></div>'
                    . '<div style="font-size: 11px; opacity: 0.6; margin-top: 4px;">'
                    . 'Verbleibend: ' . floor($sickerRem / 60) . ':' . str_pad((string)($sickerRem % 60), 2, '0', STR_PAD_LEFT) . ' Min'
                    . '</div></div>';
            } elseif ($queuedZone) {
                $zoneName = isset($queuedZone['GroupName']) && !empty($queuedZone['GroupName']) ? $queuedZone['GroupName'] : 'Zone '. $queuedZone['SensorID'];
                $baseStatus = 'Bewässerung startet: '. $zoneName;
            } elseif (!$einVentilIstAktivOderFehler && strpos($baseStatus, 'Berechne') === false && strpos($baseStatus, 'Manueller Start') === false && strpos($baseStatus, 'Plan berechnet') === false) {
                $nextTime = $this->GetNextScheduleTime();
                if ($nextTime > 0) {
                    $dayStr = (date('Y-m-d', $nextTime) === date('Y-m-d')) ? 'heute': 'morgen';
                    $baseStatus = 'Bereit (Nächste Ausführung: ' . $dayStr . ' um ' . date('H:i', $nextTime) . ' Uhr)';
                } else {
                    $baseStatus = 'Bereit';
                }
                
                $splitterID = $this->ReadPropertyInteger('GardenaSplitterID');
                if ($splitterID > 0 && IPS_InstanceExists($splitterID)) {
                    $splitterStatus = IPS_GetInstance($splitterID)['InstanceStatus'];
                    if ($splitterStatus >= 200) {
                        $baseStatus = 'Gardena Cloud Verbindung getrennt';
                    }
                }
            }
            
            $this->SetSummaryStatus($baseStatus);

            // Timer-Intervall auf 10s verkürzen, wenn Aktion läuft, sonst 60s
            if ($einVentilIstAktivOderFehler) {
                $this->SetTimerInterval('LawnAITimer', 10000);
            } else {
                $this->SetTimerInterval('LawnAITimer', 60000);
            }
        }
        } finally {
            IPS_SemaphoreLeave('SmartLawnAI_' . $this->InstanceID);
        }
    }

    private function CalculateAndApplyPlan(array $zones, array $sprinklers, bool $isManualStart, float $vpd, float $lux): void {
        $this->SetSummaryStatus('Berechne Bewässerungsplan (Gemini AI)...');

        // SmartGeminiIO auto-discover
        $geminiInstances = IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}');
        if (empty($geminiInstances)) {
            $this->LogAndDebug('Planer', 'SmartGeminiIO Instanz nicht gefunden! Bitte eine erstellen.', 0);
            $this->SLogError('SmartGeminiIO Instanz nicht gefunden', 'Bitte Instanz konfigurieren');
            $this->SetSummaryStatus('Fehler: SmartGeminiIO nicht konfiguriert');
            return;
        }
        $geminiId = $geminiInstances[0];

        $ambientContext = [
            'airTemperatureCelsius'=> ($this->ReadPropertyInteger('GlobalAirTempID') > 0) ? (float)GetValue($this->ReadPropertyInteger('GlobalAirTempID')) : 20.0,
            'relativeHumidityPercent'=> ($this->ReadPropertyInteger('GlobalHumidityID') > 0) ? (float)GetValue($this->ReadPropertyInteger('GlobalHumidityID')) : 50.0,
            'illuminanceLux'=> $lux,
            'vaporPressureDeficitKpa'=> $vpd,
            'manualStartTriggered'=> $isManualStart,
            'timestamp'=> time()
        ];
        
        $rainToday = GetValue($this->GetIDForIdent('ForecastRainToday'));
        $rainTomorrow = GetValue($this->GetIDForIdent('ForecastRainTomorrow'));
        $ambientContext['weatherForecast'] = "Erwartete Regenmenge: Heute $rainToday mm, Morgen $rainTomorrow mm";

        $defaultZiel = GetValue($this->GetIDForIdent('DefaultZielFeuchte'));
        $defaultStart = GetValue($this->GetIDForIdent('DefaultStartSchwellwert'));

        $zonesContext = [];
        foreach ($zones as $zone) {
            $sid = $zone['SensorID'];
            $sensor = $this->ResolveSensorObject((int)$sid);
            if ($sensor['MoistureID'] <= 0) {
                $this->LogAndDebug('Planer', 'Zone '. $sid . ' uebersprungen (Feuchte-Variable nicht aufloesbar).', 0);
                continue;
            }
            if (!$this->isZoneHardwareOk($zone, $sprinklers)) {
                $this->LogAndDebug('Planer', 'Zone '. $sid . 'übersprungen (Hardware-Fehler).', 0);
                $this->SetZoneStatus($sid, 'HARDWARE_FEHLER');
                continue;
            }

            $zielWert  = $defaultZiel;
            $startWert = $defaultStart;
            $aktuelleFeuchte = GetValue($sensor['MoistureID']);

            // Plausibilitaetspruefung: 0% = Sensorstoerung - Zone NICHT bewaessern!
            if ($aktuelleFeuchte <= 0) {
                $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $sid;
                $this->SLogWarning('Sensorstoerung ' . $zoneName, 'Feuchte = 0% ist unplausibel. Zone wird uebersprungen.');
                $this->AddLogEvent("{$zoneName}: Sensorstoerung", "Feuchte 0% - Sensor defekt/offline? Zone wird nicht bewaessert.", '#F44336');
                continue;
            }

            // Bodentemperatur vom Sensor lesen (falls verfuegbar)
            $soilTemp = null;
            if ($sensor['TemperatureID'] > 0 && IPS_VariableExists($sensor['TemperatureID'])) {
                $soilTemp = (float)GetValue($sensor['TemperatureID']);
            }

            // ERZWINGE EREIGNISSTEUERUNG:
            // Zone nur beplanen, wenn manueller Start oder Trigger-Schwellwert erreicht!
            if (!$isManualStart && $aktuelleFeuchte > $startWert) {
                $this->LogAndDebug('Planer', 'Zone '. $sid . 'ignoriert. Feuchte ('. $aktuelleFeuchte . '%) liegt über dem Trigger ('. $startWert . '%).', 0);
                $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $sid;
                $this->AddLogEvent("{$zoneName}: Ignoriert", "Feuchte ({$aktuelleFeuchte}%) > Start-Wert ({$startWert}%)", '#4CAF50');
                continue;
            }

            $effizienz = $this->GetZoneEffizienz($sid);
            if ($effizienz <= 0) $effizienz = 1.0;
            $maxDuration = GetValue($this->GetIDForIdent('GlobalMaxDuration'));

            $zoneContext = [
                'zoneId'=> (int)$sid,
                'groupName'=> isset($zone['GroupName']) ? $zone['GroupName'] : ('Zone '. $sid),
                'currentMoisturePercent'=> $aktuelleFeuchte,
                'targetMoisturePercent'=> $zielWert,
                'startMoisturePercent'=> $startWert,
                'learnedEfficiencyPercentPerMinute'=> $effizienz,
                'maxDurationMinutes'=> $maxDuration
            ];
            if ($soilTemp !== null) {
                $zoneContext['soilTemperatureCelsius'] = $soilTemp;
            }
            $zonesContext[] = $zoneContext;
        }

        if (empty($zonesContext)) {
            $this->LogAndDebug('Planer', 'Keine betriebsbereiten Zonen gefunden.', 0);
            return;
        }

        // 2. Prompt und Instruktion für Gemini erstellen
        $userPrompt = "Erstelle den optimalen Bewässerungsplan.\n\n";
        $userPrompt .= "UMGEBUNGSDATEN & VORHERSAGE:\n". json_encode($ambientContext, JSON_PRETTY_PRINT) . "\n\n";
        $userPrompt .= "ZONEN MIT SENSORIK UND VENTILEN:\n". json_encode($zonesContext, JSON_PRETTY_PRINT) . "\n\n";
        $userPrompt .= "Berücksichtige bei der Laufzeitberechnung:\n";
        $userPrompt .= "- Ist die Bodentemperatur zu niedrig, kühle den Boden nicht weiter ab.\n";
        $userPrompt .= "- Nutze Helligkeit und Luftfeuchte, um die aktuelle Verdunstungsrate abzuschätzen.\n";
        $userPrompt .= "- Berechne für JEDE 'zoneId'die exakte Laufzeit in Minuten (0 bis maxDurationMinutes).\n";
        
        $systemInstruction = "Du bist ein präzises Steuerungsmodul für Agrarsysteme. Deine Aufgabe ist es, für die übergebenen Zonen-IDs (zoneId) Laufzeiten in Minuten zu berechnen. Antworte ausschließlich im vorgegebenen JSON-Format.";

        // 3. API-Aufruf (Gemini mit striktem JSON Schema)

        $responseSchema = [
            'type'=> 'OBJECT',
            'properties'=> [
                'irrigationPlan'=> [
                    'type'=> 'ARRAY',
                    'description'=> 'Liste der berechneten Bewässerungszeiten pro Ventil.',
                    'items'=> [
                        'type'=> 'OBJECT',
                        'properties'=> [
                            'zoneId'=> [
                                'type'=> 'INTEGER',
                                'description'=> 'Die ID der Zone (Beregnungskreis).'
                            ],
                            'durationMinutes'=> [
                                'type'=> 'INTEGER',
                                'description'=> 'Die exakte Bewässerungsdauer in Minuten (0 falls nicht bewässert werden soll).'
                            ],
                            'sickerpauseMinuten'=> [
                                'type'=> 'INTEGER',
                                'description'=> 'Die empfohlene Sickerpause in Minuten nach der Bewässerung dieser Zone (0 bis 180).'
                            ],
                            'reasoning'=> [
                                'type'=> 'STRING',
                                'description'=> 'Kurze agronomische Begründung für diese Entscheidung.'
                            ]
                        ],
                        'required'=> ['zoneId', 'durationMinutes', 'reasoning']
                    ]
                ],
                'recommendedMaxDurationMinutes'=> [
                    'type'=> 'INTEGER',
                    'description'=> 'Die generelle agronomische Empfehlung für die absolut maximale Bewässerungsdauer in Minuten (ohne das Limit des Nutzers zu berücksichtigen).'
                ]
            ],
            'required'=> ['irrigationPlan', 'recommendedMaxDurationMinutes']
        ];

        $this->LogAndDebug('Planer', 'Gemini Anfrage wird gesendet...', 0);
        $this->LogAndDebug('Planer Prompt', $userPrompt, 0);

        $responseSchema = json_encode($responseSchema);
        $instanceId     = $this->InstanceID;
        $isManualInt    = $isManualStart ? 1 : 0;

        // Async via IPS_RunScriptText â€” GIO_Query blockiert, daher in Background
        $script = '<?php

declare(strict_types=1);

$result = GIO_Query(' . $geminiId . ',
                ' . var_export($userPrompt, true) . ',
                ' . var_export($systemInstruction, true) . ',
                ' . var_export($responseSchema, true) . ',
                0.1
            );
            SLAI_ProcessGeminiPlanResult(' . $instanceId . ', $result, ' . $isManualInt . ');
        ';
        IPS_RunScriptText($script);
    }

    public function ProcessGeminiPlanResult(string $jsonText, int $isManualStartInt): void {
        $isManualStart = (bool)$isManualStartInt;
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (!is_array($zones)) $zones = [];

        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = json_decode($sprinklersJson, true);
        if (!is_array($sprinklers)) $sprinklers = [];

        if (empty($jsonText)) {
            $this->LogAndDebug('Planer Fehler', 'SmartGeminiIO lieferte keine Antwort.', 0);
            $this->SLogError('Gemini Plan-Anfrage fehlgeschlagen', 'Leere Antwort');
            $this->SetSummaryStatus('Fehler: Gemini API (keine Antwort)');
            $this->AddLogEvent('API Fehler', 'Keine Antwort von SmartGeminiIO.', '#F44336');
            return;
        }

        $this->LogAndDebug('Planer Antwort', $jsonText, 0);

        $planData = json_decode($jsonText, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($planData['irrigationPlan']) || !is_array($planData['irrigationPlan'])) {
            $this->LogAndDebug('Planer Fehler', 'Plan-JSON konnte nicht geparst werden.', 0);
            $this->SetSummaryStatus('Fehler: Gemini JSON-Parsing fehlgeschlagen');
            return;
        }

        $reasoningText = date('d.m.Y H:i') . "Uhr:\n";
        $maxSicker = 0;
        // Zonennamen-Mapping aufbauen
        $zoneNameMap = [];
        foreach ($zones as $z) {
            $zoneNameMap[(int)$z['SensorID']] = isset($z['GroupName']) && !empty($z['GroupName'])
                ? $z['GroupName'] : 'Zone ' . $z['SensorID'];
        }
        foreach ($planData['irrigationPlan'] as $item) {
            $zId = isset($item['zoneId']) ? $item['zoneId'] : 'Unbekannt';
            $dur = isset($item['durationMinutes']) ? $item['durationMinutes'] : 0;
            $res = isset($item['reasoning']) ? $item['reasoning'] : '-';
            $displayName = $zoneNameMap[(int)$zId] ?? 'Zone ' . $zId;
            $reasoningText .= "{$displayName} ({$dur} Min): {$res}\n";
            
            $itemSicker = isset($item['sickerpauseMinuten']) ? (int)$item['sickerpauseMinuten'] : 0;
            if ($itemSicker > $maxSicker) {
                $maxSicker = $itemSicker;
            }
        }
        
        $recMaxDur = isset($planData['recommendedMaxDurationMinutes']) ? (int)$planData['recommendedMaxDurationMinutes'] : 0;
        if ($recMaxDur > 0) {
             $reasoningText .= "\n💡 KI-Empfehlung für Max. Dauer: {$recMaxDur} Min.";
        }
        
        $this->SetValue('LastGeminiResponse', trim($reasoningText));

        // Apply Gemini calculations
        $planByZone = [];
        foreach ($planData['irrigationPlan'] as $item) {
            if (isset($item['zoneId'])) {
                $planByZone[(int)$item['zoneId']] = $item;
            }
        }

        foreach ($zones as $zone) {
            $sid = $zone['SensorID'];
            if (!$this->isZoneHardwareOk($zone, $sprinklers)) {
                continue;
            }

            if (isset($planByZone[$sid])) {
                $zonePlan = $planByZone[$sid];
                $duration = isset($zonePlan['durationMinutes']) ? (int)$zonePlan['durationMinutes'] : 0;
                $sicker = isset($zonePlan['sickerpauseMinuten']) ? (int)$zonePlan['sickerpauseMinuten'] : 15;
                
                if ($duration <= 0) {
                    // This will be handled in the $duration > 0 check below, so don't continue here
                    // just let it fall through so the reasoning can be logged.
                }

                $reasoning = $zonePlan['reasoning'];
                
                $maxDuration = GetValue($this->GetIDForIdent('GlobalMaxDuration'));
                if ($duration > $maxDuration) {
                    $duration = $maxDuration;
                }

                $this->SetZoneDauer($sid, $duration);
                $this->SetZoneSickerpauseMinuten($sid, $sicker);
                $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $sid;
                
                if ($duration > 0) {
                    $this->SetZoneStatus($sid, 'QUEUED');
                    $this->LogAndDebug('Planer', 'Zone '. $sid . 'eingereiht (Gemini): '. $duration . 'Minuten. Begründung: '. $reasoning, 0);
                    $this->AddLogEvent("{$zoneName}: Plan berechnet", "Dauer: {$duration} Min. Grund: {$reasoning}", '#673AB7');
                } else {
                    $this->SetZoneStatus($sid, 'IDLE');
                    $this->LogAndDebug('Planer', 'Zone '. $sid . 'nicht eingereiht (Gemini Dauer = 0). Begründung: '. $reasoning, 0);
                    $this->AddLogEvent("{$zoneName}: Ausgesetzt", "KI Dauer: 0 Min. Grund: {$reasoning}", '#9E9E9E');
                }
            } else {
                $this->SetZoneStatus($sid, 'IDLE');
                $this->SetZoneDauer($sid, 0);
                $this->LogAndDebug('Planer', 'Zone '. $sid . 'nicht im Gemini Plan enthalten. Gesetzt auf IDLE.', 0);
            }
        }
        
        $anyQueued = false;
        foreach ($zones as $zone) {
            if ($this->GetZoneStatus($zone['SensorID']) === 'QUEUED') {
                $anyQueued = true;
                break;
            }
        }
        if ($anyQueued) {
            $this->SetSummaryStatus('Plan berechnet. Bewässerung startet gleich.');
        } else {
            $this->SetSummaryStatus('Standby (Boden ausreichend feucht)');
        }

        // Timer sofort auf 1s setzen, damit ProcessLogic() im nächsten Tick die Ventile startet
        $this->SetTimerInterval('LawnAITimer', 1000);
    }

    private function resetAllZones(bool $queueForStart, bool $silent = false): void {
        $actionName = $queueForStart ? 'ManualStart (Hard Reset)': 'Automatik Off (Hard Stop)';
        $this->LogAndDebug('Reset', $actionName . 'aufgerufen', 0);
        
        if (!$queueForStart && !$silent) {
            $this->SLogWarning('Automatik deaktiviert', 'Alle Ventile werden gestoppt und Zonen zurückgesetzt.');
            $this->SetSummaryStatus('Automatik deaktiviert (Zonen gestoppt)');
            $this->AddLogEvent("System: Abbruch", "Automatik deaktiviert, alle Ventile gestoppt.", '#F44336');
        }

        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = json_decode($sprinklersJson, true);

        // Bei silent (manueller Restart vor Plan-Berechnung) keine STOP-Befehle senden,
        // da noch nichts läuft und jeder Befehl ein Gardena-API-Call ist (Limit: 100/Tag)
        if (is_array($sprinklers) && !$silent) {
            foreach ($sprinklers as $s) {
                $res = $this->ResolveSprinklerObject((int)@$s['ValveID']);
                if ($res['ValveID'] <= 0) continue;

                // Nur STOP senden, wenn das Ventil tatsächlich aktiv ist
                $isActive = false;
                if ($res['ActivityID'] > 0) {
                    $v = GetValue($res['ActivityID']);
                    $act = strtoupper(is_string($v) ? $v : (string)GetValueFormatted($res['ActivityID']));
                    $isActive = (strpos($act, 'WATERING') !== false || strpos($act, 'OPEN') !== false
                        || strpos($act, 'BEWÄSSERUNG') !== false || strpos($act, 'GEÖFFNET') !== false
                        || $v == 1 || $v == 2 || $v == 3);
                } elseif ($res['RemainingSecondsID'] > 0) {
                    $isActive = ((int)GetValue($res['RemainingSecondsID']) > 0);
                } elseif (IPS_VariableExists($res['ValveID'])) {
                    $v = GetValue($res['ValveID']);
                    $isActive = ($v == 1 || $v === true);
                }

                if ($isActive) {
                    if (IPS_VariableExists($res['ValveID']) && in_array(strtolower(IPS_GetObject($res['ValveID'])['ObjectIdent']), ['action', 'valvecontrol', 'control'])) {
                        $this->SafeRequestAction($res['ValveID'], 'STOP_UNTIL_NEXT_TASK');
                    } else {
                        $this->SafeRequestAction($res['ValveID'], false);
                    }
                } else {
                    $this->LogAndDebug('Reset', 'Sprinkler ' . $s['ValveID'] . ' bereits inaktiv, STOP übersprungen.', 0);
                }
            }
        }

        if (is_array($zones)) {
            foreach ($zones as $zone) {
                $sid = $zone['SensorID'];
                
                $this->SetZoneStartFeuchte($sid, 0.0);
                $this->SetZoneDauer($sid, 0);
                $this->SetZoneCurrentSprinklerIndex($sid, 0);
                $this->SetZoneSickerpauseStart($sid, 0);
                $this->SetZoneWateringStart($sid, 0);
                $this->SetZoneSickerpauseMinuten($sid, 0);
                $this->SetBuffer('WaterMeterStart_' . $sid, '');

                $newStatus = $queueForStart ? 'QUEUED': 'IDLE';
                $this->SetZoneStatus($sid, $newStatus);
                
                if ($queueForStart) {
                    $this->LogAndDebug('Reset', 'Zone '. $sid . 'hart resettet und -> QUEUED.', 0);
                    $this->SLogInfo('Zone manuell zurückgesetzt', 'Zone: ' . $sid . ' in Warteschlange eingereiht');
                } else {
                    $this->LogAndDebug('Reset', 'Zone '. $sid . 'hart resettet und gestoppt -> IDLE.', 0);
                }
            }
        }
        
        // Kurze Pause nur wenn STOP-Befehle gesendet wurden
        if (!$silent) {
            IPS_Sleep(1000);
        }
        
        if ($queueForStart) {
            $this->ProcessLogic();
        }
    }

    private function triggerManualStart(): void {
        $this->SetSummaryStatus('Manueller Start angefordert...');
        $this->LogAndDebug('ManualStart', 'Manueller Start angefordert. Setze Zonen zurück...', 0);
        $this->AddLogEvent("System: Manueller Start", "Bewässerung wird sofort gestartet...", '#2196F3');
        $this->resetAllZones(false, true); // Zonen nur stoppen, nicht sofort QUEUED setzen (Race Condition vermeiden)
        $this->SetBuffer('CalculatePlanPending', 'true');
        $this->SetTimerInterval('LawnAITimer', 1000); // ProcessLogic wird im nächsten Tick aufgerufen
    }

    public function CheckAllHardwareStatus(): string {
        $cloudInstID = $this->ReadPropertyInteger('GardenaSplitterID');
        if ($cloudInstID > 0 && IPS_InstanceExists($cloudInstID)) {
            $inst = IPS_GetInstance($cloudInstID);
            if ($inst['InstanceStatus'] >= 200) {
                return 'Gardena Cloud/Splitter offline (Status: ' . $inst['InstanceStatus'] . ')';
            }
        }

        $zonesJson = $this->ReadPropertyString('Zones');
        if (empty($zonesJson)) return '';
        $zones = json_decode($zonesJson, true);
        if (!is_array($zones)) return '';
        
        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = empty($sprinklersJson) ? [] : json_decode($sprinklersJson, true);
        if (!is_array($sprinklers)) $sprinklers = [];

        foreach ($zones as $zone) {
            if (!$this->isZoneHardwareOk($zone, $sprinklers)) {
                $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                return 'Defekter Sprinkler in ' . $zoneName;
            }
        }
        return '';
    }

    private function isZoneHardwareOk(array $zone, array $sprinklers): bool {
        $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
        foreach ($sprinklers as $s) {
            if ($s['ZoneName'] === $zoneName) {
                $res = $this->ResolveSprinklerObject((int)@$s['ValveID']);
                if ($res['HardwareStatusID'] > 0) {
                    $hwStatus = GetValue($res['HardwareStatusID']);
                    $hwStr = strtoupper((string)$hwStatus);
                    // String-basierte Prüfung (altes Modul) + Integer-Prüfung (neues Modul: 0=OK, >0=Fehler)
                    $istFehler = in_array($hwStr, ['ERROR', 'WARNING', 'OFFLINE', 'DEFECT', 'FAULT'])
                                 || (is_int($hwStatus) && $hwStatus > 0);
                    if ($istFehler) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function GetTimeAsString(string $propertyName): string {
        $val = $this->ReadPropertyString($propertyName);
        if (empty($val)) return "00:00";
        $data = json_decode($val, true);
        if (is_array($data) && isset($data['hour']) && isset($data['minute'])) {
            return sprintf("%02d:%02d", $data['hour'], $data['minute']);
        }
        // Fallback falls es kein JSON ist (alte Version)
        return substr($val, 0, 5);
    }

    private function IsTimeForbidden(int $timestamp): bool {
        $fStart = $this->GetTimeAsString('ForbiddenStartTime');
        $fEnd = $this->GetTimeAsString('ForbiddenEndTime');
        if ($fStart === $fEnd) return false;
        
        $timeStr = date('H:i', $timestamp);
        if ($fEnd < $fStart) {
            if ($timeStr >= $fStart || $timeStr <= $fEnd) return true;
        } else {
            if ($timeStr >= $fStart && $timeStr <= $fEnd) return true;
        }
        return false;
    }

    private function GetNextScheduleTime(): int {
        $schedule = $this->ReadPropertyInteger('IrrigationSchedule');
        $now = time();
        $today = strtotime('today');
        
        $times = [];
        if ($schedule === 1) {
            $times = [6];
        } else if ($schedule === 2) {
            $times = [6, 18];
        } else if ($schedule === 4) {
            $times = [0, 6, 12, 18];
        } else if ($schedule === 6) {
            $times = [0, 4, 8, 12, 16, 20];
        } else if ($schedule === 8) {
            $times = [0, 3, 6, 9, 12, 15, 18, 21];
        } else {
            $times = [6, 18];
        }
        
        // Sperrzeit nur berücksichtigen wenn der Switch aktiv ist
        $sperrzeitAktiv = $this->GetValue('SperrzeitActive');
        for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
            $baseDay = $today + ($dayOffset * 86400);
            foreach ($times as $hour) {
                $t = $baseDay + ($hour * 3600);
                if ($t > $now && (!$sperrzeitAktiv || !$this->IsTimeForbidden($t))) {
                    return $t;
                }
            }
        }
        
        // Fallback
        return $today + 86400 + ($times[0] * 3600);
    }
}

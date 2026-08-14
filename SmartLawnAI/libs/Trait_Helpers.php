<?php

declare(strict_types=1);

trait SmartLawnAI_Helpers {

    private function SetSummaryStatus(string $status): void {
        $id = @$this->GetIDForIdent('SummaryStatus');
        if ($id > 0) {
            $this->SetValue('SummaryStatus', $status);
        }
        
        $vID = @$this->GetIDForIdent('VestaboardMessage');
        if ($vID > 0) {
            $this->SetValue('VestaboardMessage', $this->GetShortStatus($status));
        }
    }

    private function GetShortStatus(string $longStatus): string {
        if (strpos($longStatus, 'HARDWARE-FEHLER') !== false) return 'Fehler (Hardware)';
        if (strpos($longStatus, 'Fehler:') !== false) return 'Fehler (API)';
        if (strpos($longStatus, 'Bewässert:') !== false) {
            if (preg_match('/Bewässert: .*? \((.*?)\) \(noch (\d+(:\d+)?) Min\)/', $longStatus, $m)) {
                return $m[1] . ' (' . $m[2] . ' Min)';
            }
            if (preg_match('/Bewässert: .*? \((.*?)\)/', $longStatus, $m)) {
                return $m[1] . ' läuft';
            }
            return 'Bewässerung läuft';
        }
        if (strpos($longStatus, 'Wartet auf Ventil:') !== false) {
            if (preg_match('/Wartet auf Ventil: (.*?) \(/', $longStatus, $m)) {
                return 'Wartet: ' . $m[1];
            }
            return 'Wartet auf Ventil';
        }
        if (strpos($longStatus, 'Bewässerung startet:') !== false) {
            if (preg_match('/Bewässerung startet: (.*)/', $longStatus, $m)) {
                return $m[1] . ' startet';
            }
            return 'Start läuft';
        }
        if (strpos($longStatus, 'Bewässere:') !== false) {
            return str_replace('Bewässere: ', '', $longStatus) . ' startet';
        }
        if (strpos($longStatus, 'Sickerpause:') !== false) {
            if (preg_match('/Sickerpause: (.*)/', $longStatus, $m)) {
                return 'Pause ' . $m[1];
            }
            return str_replace('Sickerpause: ', 'Pause ', $longStatus);
        }
        if (strpos($longStatus, 'Standby') !== false) {
            return 'Standby';
        }
        if (strpos($longStatus, 'Berechne') !== false) return 'KI rechnet';
        if (strpos($longStatus, 'Plan berechnet') !== false) return 'Plan fertig';
        if (strpos($longStatus, 'Automatik') !== false) return 'Automatik Aus';
        if (strpos($longStatus, 'Manueller Start') !== false) return 'Start angefragt';
        
        if (strpos($longStatus, 'Bereit (Nächste Ausführung:') !== false) {
            if (preg_match('/Nächste Ausführung: (.*?) um (.*?) Uhr/', $longStatus, $m)) {
                return 'Wasser: ' . $m[1] . ' ' . $m[2];
            }
        }
        
        return 'Bereit';
    }

    private function LogAndDebug(string $Topic, string $Payload, int $Format = 0): void {
        $this->SendDebug($Topic, $Payload, $Format);
        if (is_scalar($Payload)) {
            $this->SLogInfo($Topic, (string)$Payload);
        } else {
            $this->SLogInfo($Topic, json_encode($Payload));
        }
    }



    private function EnableArchive(int $variableID): void {
        if ($variableID > 0) {
            $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
            if (count($archiveIDs) > 0) {
                $archiveID = $archiveIDs[0];
                if (!AC_GetLoggingStatus($archiveID, $variableID)) {
                    AC_SetLoggingStatus($archiveID, $variableID, true);
                    IPS_ApplyChanges($archiveID);
                }
            }
        }
    }

    private function MaintainScheduleEvents(bool $active): void {
        $schedule = $this->ReadPropertyInteger('IrrigationSchedule');
        
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

        for ($i = 0; $i <= 23; $i++) {
            $ident = 'SLAI_ScheduleEvent_' . $i;
            $eid = @$this->GetIDForIdent($ident);
            
            if ($active && in_array($i, $times)) {
                if ($eid === false) {
                    $eid = IPS_CreateEvent(1); // Zyklisches Event
                    IPS_SetParent($eid, $this->InstanceID);
                    IPS_SetHidden($eid, true);
                    IPS_SetName($eid, sprintf('Zeitplan Prüfung (%02d:00)', $i));
                    IPS_SetIdent($eid, $ident);
                    IPS_SetEventScript($eid, "SLAI_ScheduledEvaluation(\$_IPS['TARGET']);");
                    IPS_SetEventCyclic($eid, 0, 0, 0, 0, 0, 0); // Täglich
                    IPS_SetEventCyclicTimeFrom($eid, $i, 0, 0);
                }
                IPS_SetEventScript($eid, "SLAI_ScheduledEvaluation(\$_IPS['TARGET']);");
                IPS_SetEventActive($eid, true);
            } else {
                if ($eid !== false) {
                    IPS_DeleteEvent($eid);
                }
            }
        }
    }

    public function AddLogEvent(string $title, string $details = '', string $color = '#2196F3'): void {
        $logVarID = @$this->GetIDForIdent('IrrigationLog');
        if ($logVarID === false || !IPS_VariableExists($logVarID)) return;
        $currentLog = GetValue($logVarID);
        
        // Alten Plaintext bereinigen, wenn kein HTML-Tag vorhanden
        if (strpos($currentLog, 'sl-log-entry') === false) {
            $currentLog = '';
        }
        
        $dateStr = date('d.m.Y');
        $timeStr = date('H:i:s'); 
        
        $newEntry = '
        <div class="sl-log-entry" style="margin-bottom: 8px; padding: 10px; background: rgba(128, 128, 128, 0.1); border-left: 4px solid '.$color.'; border-radius: 4px; font-family: sans-serif;">
            <div style="font-size: 11px; opacity: 0.6; margin-bottom: 4px;">'.$dateStr.' &middot; '.$timeStr.' Uhr</div>
            <div style="font-weight: 600; font-size: 14px; margin-bottom: 3px;">'.$title.'</div>
            <div style="font-size: 13px; opacity: 0.8; line-height: 1.4;">'.$details.'</div>
        </div>';
        
        // Log-Größe begrenzen auf die letzten 30 Einträge (Split am Marker)
        $entries = explode('<div class="sl-log-entry"', $currentLog);
        $entries = array_filter($entries, function($e) { return trim($e) !== ''; });
        
        $htmlEntries = [];
        $htmlEntries[] = $newEntry;
        $count = 1;
        foreach ($entries as $e) {
            if ($count >= 30) break;
            $htmlEntries[] = '<div class="sl-log-entry"' . $e;
            $count++;
        }
        
        $updatedLog = implode("", $htmlEntries);
        $this->SetValue('IrrigationLog', $updatedLog);
    }

    protected function SafeRequestAction(int $variableID, $value, string &$errorMsg = '', int $maxRetries = 2): bool {
        if (!IPS_VariableExists($variableID)) {
            $errorMsg = 'Variable ID ' . $variableID . ' existiert nicht';
            return false;
        }

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $result = RequestAction($variableID, $value);
                if ($result !== false) {
                    if ($attempt > 0) {
                        $this->LogAndDebug('SafeRequestAction', 'Erfolgreich nach ' . ($attempt + 1) . '. Versuch (Ventil-ID: ' . $variableID . ')', 0);
                    }
                    $errorMsg = '';
                    return true;
                }
                $errorMsg = 'RequestAction gab false zurueck (Ventil-ID: ' . $variableID . ')';
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
            }

            if ($attempt < $maxRetries) {
                $this->LogAndDebug('SafeRequestAction', 'Versuch ' . ($attempt + 1) . ' fehlgeschlagen (ID: ' . $variableID . '): ' . $errorMsg . ' - Retry in 3s...', 0);
                IPS_Sleep(3000);
            }
        }

        $this->LogAndDebug('SafeRequestAction', 'Endgueltig fehlgeschlagen nach ' . ($maxRetries + 1) . ' Versuchen (ID: ' . $variableID . '): ' . $errorMsg, 0);
        $this->SLogError('Sende-Fehler', 'Ventil-ID ' . $variableID . ': ' . $errorMsg . ' (nach ' . ($maxRetries + 1) . ' Versuchen)');
        return false;
    }

    public function ResolveSprinklerObject(int $objectId): array {
        $res = [
            'ValveID' => 0,
            'HardwareStatusID' => 0,
            'DurationID' => 0,
            'RemainingSecondsID' => 0,
            'ActivityID' => 0
        ];

        if ($objectId <= 0) return $res;

        if (IPS_VariableExists($objectId)) {
            $res['ValveID'] = $objectId;
            return $res;
        }

        if (IPS_InstanceExists($objectId)) {
            $children = IPS_GetChildrenIDs($objectId);
            foreach ($children as $child) {
                if (!IPS_VariableExists($child)) continue;
                $obj = IPS_GetObject($child);
                $ident = strtolower($obj['ObjectIdent']);
                
                if (in_array($ident, ['action', 'valvecontrol', 'control', 'watering'])) $res['ValveID'] = $child;
                elseif (in_array($ident, ['state', 'status'])) $res['HardwareStatusID'] = $child;
                elseif ($res['HardwareStatusID'] === 0 && in_array($ident, ['lasterror', 'errorcode', 'lasterrorcode', 'valveerror'])) $res['HardwareStatusID'] = $child;
                elseif (in_array($ident, ['duration', 'valveduration', 'wateringduration'])) {
                    if ($ident === 'valveduration') {
                        if ($res['DurationID'] !== 0) {
                            $res['RemainingSecondsID'] = $res['DurationID'];
                        }
                        $res['DurationID'] = $child;
                    } elseif ($res['DurationID'] === 0) {
                        $res['DurationID'] = $child; 
                    } else {
                        $res['RemainingSecondsID'] = $child; 
                    }
                }
                elseif (in_array($ident, ['remaining', 'remainingtime'])) $res['RemainingSecondsID'] = $child;
                elseif (in_array($ident, ['activity', 'valveactivity'])) $res['ActivityID'] = $child;
            }
        }
        return $res;
    }

    /**
     * Loest eine Sensor-Objekt-ID auf.
     * Akzeptiert entweder:
     *   - Eine Variable-ID (z.B. Bodenfeuchte) → wird direkt als MoistureID verwendet
     *   - Eine Instanz-ID (z.B. GardenaSensor) → SoilMoisture + SoilTemperature werden aufgeloest
     */
    public function ResolveSensorObject(int $objectId): array {
        $res = [
            'MoistureID' => 0,
            'TemperatureID' => 0
        ];

        if ($objectId <= 0) return $res;

        // Fall 1: Direkte Variable-ID (backward compatible)
        if (IPS_VariableExists($objectId)) {
            $res['MoistureID'] = $objectId;
            return $res;
        }

        // Fall 2: Instanz-ID → Kinder-Variablen aufloesen
        if (IPS_InstanceExists($objectId)) {
            $children = IPS_GetChildrenIDs($objectId);
            foreach ($children as $child) {
                if (!IPS_VariableExists($child)) continue;
                $obj = IPS_GetObject($child);
                $ident = strtolower($obj['ObjectIdent']);
                
                if (in_array($ident, ['soilmoisture', 'soilhumidity', 'humidity', 'moisture', 'feuchte'])) {
                    $res['MoistureID'] = $child;
                }
                elseif (in_array($ident, ['soiltemperature', 'bodentemperatur'])) {
                    $res['TemperatureID'] = $child;
                }
            }
        }
        return $res;
    }
    /**
     * Gibt die ID der TotalConsumptionLiter-Variable des SmartWaterMonitors zurück.
     * SmartWaterMonitor GUID: {09A99311-87CD-480B-A7B8-6DC226136CFB}
     */
    protected function GetWaterMeterLiterVarID(): int
    {
        $instID = $this->ReadPropertyInteger('WaterMonitorInstanceID');
        if ($instID <= 0 || !@IPS_InstanceExists($instID)) {
            return 0;
        }
        $varID = @IPS_GetObjectIDByIdent('TotalConsumptionLiter', $instID);
        return ($varID !== false && $varID > 0) ? (int)$varID : 0;
    }

    protected function GetWaterMeterFlowRateVarID(): int
    {
        $instID = $this->ReadPropertyInteger('WaterMonitorInstanceID');
        if ($instID <= 0 || !@IPS_InstanceExists($instID)) {
            return 0;
        }
        $varID = @IPS_GetObjectIDByIdent('FlowRate', $instID);
        return ($varID !== false && $varID > 0) ? (int)$varID : 0;
    }

    private function GetZoneStateData(string $key): string {
        $json = $this->ReadAttributeString('ZoneStates');
        if ($json === '') return '';
        $data = json_decode($json, true);
        return $data[$key] ?? '';
    }

    private function SetZoneStateData(string $key, string $value): void {
        $json = $this->ReadAttributeString('ZoneStates');
        $data = $json === '' ? [] : json_decode($json, true);
        if (!is_array($data)) $data = [];
        if ($value === '') {
            unset($data[$key]);
        } else {
            $data[$key] = $value;
        }
        $this->WriteAttributeString('ZoneStates', json_encode($data));
    }

    protected function GetZoneStatus($sid): string {
        $v = $this->GetZoneStateData('ZoneStatus_' . $sid);
        return $v !== '' ? $v : 'IDLE';
    }

    protected function SetZoneStatus($sid, string $status): void {
        $this->SetZoneStateData('ZoneStatus_' . $sid, $status);
    }

    protected function GetZoneEffizienz($sid): float {
        $v = $this->GetZoneStateData('ZoneEffizienz_' . $sid);
        return $v !== '' ? (float)$v : 1.0;
    }

    protected function SetZoneEffizienz($sid, float $eff): void {
        $this->SetZoneStateData('ZoneEffizienz_' . $sid, (string)$eff);
    }

    protected function GetZoneWateringStart($sid): int {
        return (int)$this->GetZoneStateData('ZoneWateringStart_' . $sid);
    }

    protected function SetZoneWateringStart($sid, int $timestamp): void {
        $this->SetZoneStateData('ZoneWateringStart_' . $sid, (string)$timestamp);
    }

    protected function GetZoneSickerpauseStart($sid): int {
        return (int)$this->GetZoneStateData('ZoneSickerpauseStart_' . $sid);
    }

    protected function SetZoneSickerpauseStart($sid, int $timestamp): void {
        $this->SetZoneStateData('ZoneSickerpauseStart_' . $sid, (string)$timestamp);
    }

    protected function GetZoneSickerpauseMinuten($sid): int {
        $v = $this->GetZoneStateData('ZoneSickerpauseMinuten_' . $sid);
        return $v !== '' ? (int)$v : 15;
    }

    protected function SetZoneSickerpauseMinuten($sid, int $val): void {
        $this->SetZoneStateData('ZoneSickerpauseMinuten_' . $sid, (string)$val);
    }

    protected function GetZoneStartFeuchte($sid): float {
        return (float)$this->GetZoneStateData('ZoneStartFeuchte_' . $sid);
    }

    protected function SetZoneStartFeuchte($sid, float $val): void {
        $this->SetZoneStateData('ZoneStartFeuchte_' . $sid, (string)$val);
    }

    protected function GetZoneDauer($sid): int {
        return (int)$this->GetZoneStateData('ZoneDauer_' . $sid);
    }

    protected function SetZoneDauer($sid, int $val): void {
        $this->SetZoneStateData('ZoneDauer_' . $sid, (string)$val);
    }

    protected function GetZoneCurrentSprinklerIndex($sid): int {
        return (int)$this->GetZoneStateData('ZoneCurrentSprinklerIndex_' . $sid);
    }

    protected function SetZoneCurrentSprinklerIndex($sid, int $val): void {
        $this->SetZoneStateData('ZoneCurrentSprinklerIndex_' . $sid, (string)$val);
    }

    /**
     * Sicher den SmartController ueber Bewaesserungsstatus informieren.
     * Faengt Fehler ab wenn die Instanz nicht verfuegbar ist.
     */
    protected function NotifySmartControllerIrrigation(bool $active): void {
        if (!function_exists('SHC_SetIrrigationActive')) return;
        $shcInstances = IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
        if (empty($shcInstances)) return;
        try {
            SHC_SetIrrigationActive($shcInstances[0], $active);
        } catch (\Throwable $e) {
            $this->SendDebug('SmartController', 'Nicht erreichbar: ' . $e->getMessage(), 0);
        }
    }
}

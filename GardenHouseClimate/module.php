<?php

declare(strict_types=1);

require_once __DIR__ . '/../ClimateCommon.php';

class GardenHouseClimate extends IPSModuleStrict
{
    use ClimateCommon;

    public function Create(): void{
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger("SensorTempInside", 0);
        $this->RegisterPropertyInteger("SensorTempOutside", 0);
        $this->RegisterPropertyString("SensorWindows", "[]");
        
        $this->RegisterPropertyInteger("ActuatorHeaterPlug", 0);
        $this->RegisterPropertyInteger("SensorHeaterPower", 0);
        
        $this->RegisterPropertyFloat("TargetTemperature", 5.0);
        $this->RegisterPropertyFloat("Hysteresis", 0.5);
        
        $this->RegisterPropertyFloat("HeaterPowerThreshold", 50.0);
        $this->RegisterPropertyInteger("HeaterDefectTime", 300);
        $this->RegisterPropertyInteger("WindowOpenTime", 900);
        $this->RegisterPropertyFloat("FrostWarningTemp", 3.0);
        
        // Variables
        $this->RegisterVariableBoolean("WinterMode", "Winterbetrieb", "");
        IPS_SetIcon($this->GetIDForIdent('WinterMode'), 'Gear');
        $this->EnableAction("WinterMode");
        $this->SetValue("WinterMode", true); // Default to true
        
        $this->RegisterVariableFloat("TargetTemperature", "Zieltemperatur Frostschutz", "");
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('TargetTemperature'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Temperature',
            'SUFFIX'        => ' °C',
            'DECIMALPLACES' => 1
        ]);
        $this->EnableAction("TargetTemperature");
        
        $this->RegisterVariableInteger("HeaterStatus", "Status Heizung", "");
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('HeaterStatus'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Information'
        ]);
        
        if (!IPS_VariableProfileExists('SmartClimate.HeaterStatus')) {
            IPS_CreateVariableProfile('SmartClimate.HeaterStatus', 1);
            IPS_SetVariableProfileAssociation('SmartClimate.HeaterStatus', 0, 'Aus', 'Sleep', 0x00FF00);
            IPS_SetVariableProfileAssociation('SmartClimate.HeaterStatus', 1, 'Heizen', 'Flame', 0xFF0000);
            IPS_SetVariableProfileAssociation('SmartClimate.HeaterStatus', 2, 'Pausiert (Fenster offen)', 'Window', 0xFFFF00);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('HeaterStatus'), 'SmartClimate.HeaterStatus');
        
        // Alarms (no legacy profiles — use CustomPresentation via Trait)
        $this->RegisterVariableBoolean("AlarmHeaterDefect", "Alarm: Heizung defekt", "");
        IPS_SetIcon($this->GetIDForIdent('AlarmHeaterDefect'), 'Warning');
        $this->EnableAction("AlarmHeaterDefect");
        
        $this->RegisterVariableBoolean("AlarmFrost", "Alarm: Kritischer Frost", "");
        IPS_SetIcon($this->GetIDForIdent('AlarmFrost'), 'Warning');
        $this->EnableAction("AlarmFrost");
        
        $this->RegisterVariableBoolean("AlarmWindowOpen", "Alarm: Fenster offen (Winter)", "");
        IPS_SetIcon($this->GetIDForIdent('AlarmWindowOpen'), 'Warning');
        $this->EnableAction("AlarmWindowOpen");
        
        // Timers
        $this->RegisterTimer("HeaterDefectTimer", 0, 'GHC_TriggerHeaterDefectAlarm($_IPS[\'TARGET\']);');
        $this->RegisterTimer("WindowOpenTimer", 0, 'GHC_TriggerWindowOpenAlarm($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $ref_SensorTempInside = $this->ReadPropertyInteger('SensorTempInside');
        if ($ref_SensorTempInside > 1 && @IPS_ObjectExists($ref_SensorTempInside)) {
            $this->RegisterReference($ref_SensorTempInside);
        }
        $ref_SensorTempOutside = $this->ReadPropertyInteger('SensorTempOutside');
        if ($ref_SensorTempOutside > 1 && @IPS_ObjectExists($ref_SensorTempOutside)) {
            $this->RegisterReference($ref_SensorTempOutside);
        }
        $ref_ActuatorHeaterPlug = $this->ReadPropertyInteger('ActuatorHeaterPlug');
        if ($ref_ActuatorHeaterPlug > 1 && @IPS_ObjectExists($ref_ActuatorHeaterPlug)) {
            $this->RegisterReference($ref_ActuatorHeaterPlug);
        }
        $ref_SensorHeaterPower = $this->ReadPropertyInteger('SensorHeaterPower');
        if ($ref_SensorHeaterPower > 1 && @IPS_ObjectExists($ref_SensorHeaterPower)) {
            $this->RegisterReference($ref_SensorHeaterPower);
        }
        $this->RegisterWindowReferences(); // Trait
        // ---------------------------------

        // Presentations (Symcon 8+)
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('WinterMode'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'Gear'
        ]);

        // Alarm-Variablen via Trait (Switch mit Farben)
        $this->SetupAlarmPresentation('AlarmHeaterDefect', 'ALARM: Heizung defekt');
        $this->SetupAlarmPresentation('AlarmFrost',        'ALARM: Kritischer Frost');
        $this->SetupAlarmPresentation('AlarmWindowOpen',   'ALARM: Fenster offen (Winter)', 'OK', 0xFF6600);

        // Messages neu registrieren (Trait)
        $this->UnregisterAllMessages();
        
        foreach (["SensorTempInside", "SensorTempOutside"] as $sensorName) {
            $id = $this->ReadPropertyInteger($sensorName);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
        $this->RegisterWindowMessages(); // Trait
        
        $powerId = $this->ReadPropertyInteger("SensorHeaterPower");
        if ($powerId > 0 && IPS_VariableExists($powerId)) {
            $this->RegisterMessage($powerId, VM_UPDATE);
        }
        
        $this->UpdateClimate();
    }
    
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void{
        $powerId = $this->ReadPropertyInteger("SensorHeaterPower");
        
        if ($SenderID == $powerId) {
            $this->HandlePowerUpdate((float)$Data[0]);
        } elseif ($this->AnyWindowOpen()) { // Trait: recheck window state
            $this->HandleWindowUpdate(true);
            $this->UpdateClimate();
        } else {
            $this->HandleWindowUpdate(false);
            $this->UpdateClimate();
        }
    }
    
    public function RequestAction(string $Ident, $Value): void{
        switch ($Ident) {
            case "TargetTemperature":
                $this->SetValue($Ident, $Value);
                $this->UpdateClimate();
                break;
            case "WinterMode":
                $this->SetValue("WinterMode", $Value);
                $this->UpdateClimate();
                break;
            case "AlarmHeaterDefect":
            case "AlarmFrost":
            case "AlarmWindowOpen":
                if ($Value == false) {
                    $this->SetValue($Ident, false);
                    $this->UpdateClimate();
                }
                break;
            default:
                throw new Exception("Invalid Ident");
        }
    }

    public function UpdateClimate(): void
    {
        $winterMode = $this->GetValue("WinterMode");
        if (!$winterMode) {
            $this->SetHeater(false, 0); // Off (Summer)
            $this->StopTimer("HeaterDefectTimer"); // Trait
            $this->StopTimer("WindowOpenTimer");   // Trait
            return;
        }
        
        $tempIn  = $this->GetPropertyVarValue("SensorTempInside");  // Trait
        $tempOut = $this->GetPropertyVarValue("SensorTempOutside"); // Trait
        
        $windowOpen = $this->AnyWindowOpen(); // Trait
        
        if ($tempIn === null) return;
        
        // Frost-Alarm
        $frostWarn = $this->ReadPropertyFloat("FrostWarningTemp");
        if ($tempIn <= $frostWarn) {
            $this->SetValue("AlarmFrost", true);
        }
        
        if ($windowOpen) {
            $this->SetHeater(false, 2); // Off (Fenster offen)
            return;
        }
        
        // Zieltemperatur mit Vorsteuerung bei starkem Außenfrost
        $targetTemp = $this->GetValue("TargetTemperature");
        if ($tempOut !== null && $tempOut <= -5.0) {
            $targetTemp += 1.0;
        }
        
        $hysteresis = $this->ReadPropertyFloat("Hysteresis");
        
        $plugId = $this->ReadPropertyInteger("ActuatorHeaterPlug");
        if ($plugId == 0 || !IPS_VariableExists($plugId)) return;
        
        $plugStatus = (bool)GetValue($plugId);
        
        if ($tempIn < ($targetTemp - ($hysteresis / 2))) {
            $this->SetHeater(true, 1);
        } elseif ($tempIn > ($targetTemp + ($hysteresis / 2))) {
            $this->SetHeater(false, 0);
        } else {
            $this->SetValue("HeaterStatus", $plugStatus ? 1 : 0);
        }
    }
    
    private function SetHeater(bool $state, int $statusText): void
    {
        $plugId = $this->ReadPropertyInteger("ActuatorHeaterPlug");
        if ($plugId == 0 || !IPS_VariableExists($plugId)) return;
        
        $plugStatus = (bool)GetValue($plugId);
        if ($plugStatus !== $state) {
            $tempIn = $this->GetPropertyVarValue("SensorTempInside");
            $targetTemp = $this->GetValue("TargetTemperature");
            $this->SLog('INFO', 'Heizung ' . ($state ? 'eingeschaltet' : 'ausgeschaltet'), 'Innentemperatur: ' . $tempIn . '°C | Zieltemperatur: ' . $targetTemp . '°C');
            if (!@RequestAction($plugId, $state)) {
                $this->SLog('WARNING', 'Heizungsbefehl fehlgeschlagen', "Heater Plug ID: $plugId | Ziel: " . ($state ? 'An' : 'Aus'));
            }
        }
        $this->SetValue("HeaterStatus", $statusText);
        
        if (!$state) {
            $this->StopTimer("HeaterDefectTimer"); // Trait
        }
    }
    
    private function HandlePowerUpdate(float $currentPower): void
    {
        $winterMode = $this->GetValue("WinterMode");
        if (!$winterMode) return;
        
        $plugId = $this->ReadPropertyInteger("ActuatorHeaterPlug");
        if ($plugId == 0) return;
        
        $plugStatus = (bool)GetValue($plugId);
        $threshold  = $this->ReadPropertyFloat("HeaterPowerThreshold");
        $timeLimit  = $this->ReadPropertyInteger("HeaterDefectTime");
        
        if ($plugStatus) {
            if ($currentPower < $threshold) {
                if ($this->GetTimerInterval("HeaterDefectTimer") == 0) {
                    $this->SetTimerInterval("HeaterDefectTimer", $timeLimit * 1000);
                }
            } else {
                $this->StopTimer("HeaterDefectTimer"); // Trait
            }
        } else {
            $this->StopTimer("HeaterDefectTimer"); // Trait
        }
    }
    
    public function TriggerHeaterDefectAlarm(): void
    {
        $this->StopTimer("HeaterDefectTimer"); // Trait
        $this->SetValue("AlarmHeaterDefect", true);
    }
    
    private function HandleWindowUpdate(bool $isOpen): void
    {
        $winterMode = $this->GetValue("WinterMode");
        if (!$winterMode) return;
        
        $timeLimit = $this->ReadPropertyInteger("WindowOpenTime");
        
        if ($isOpen) {
            $this->StartTimerOnce("WindowOpenTimer", $timeLimit); // Trait
        } else {
            $this->StopTimer("WindowOpenTimer"); // Trait
        }
    }
    
    public function TriggerWindowOpenAlarm(): void
    {
        $this->StopTimer("WindowOpenTimer"); // Trait
        $this->SetValue("AlarmWindowOpen", true);
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "⚙ Sensoren",
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectVariable",
                            "name": "SensorTempInside",
                            "caption": "Temperatur Gartenhaus"
                        },
                        {
                            "type": "SelectVariable",
                            "name": "SensorTempOutside",
                            "caption": "Temperatur Außen"
                        }
                    ]
                }
            ]
        },
        {
            "type": "List",
            "name": "SensorWindows",
            "caption": "Fenster-/Türkontakte (Gartenhaus)",
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Sensor",
                    "name": "VariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Wert für Geschlossen",
                    "name": "ClosedValue",
                    "width": "150px",
                    "add": "false",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Aktoren\nHier wählst du die passenden Aktoren für das Gartenhaus aus:"
        },
        {
            "type": "SelectVariable",
            "name": "ActuatorHeaterPlug",
            "caption": "Schaltsteckdose Heizung"
        },
        {
            "type": "SelectVariable",
            "name": "SensorHeaterPower",
            "caption": "Leistungsmessung Heizung (Watt)"
        },
        {
            "type": "Label",
            "caption": "Temperatureinstellungen\nHier stellst du ein, ab welcher Temperaturabweichung die Heizung schaltet:"
        },
        {
            "type": "NumberSpinner",
            "name": "Hysteresis",
            "caption": "Schalthysterese (°C)",
            "digits": 1
        },
        {
            "type": "Label",
            "caption": "Ausfallsicherheit / Alarme\nHier stellst du ein, wann ein Alarm wegen Ausfall ausgelöst wird:"
        },
        {
            "type": "NumberSpinner",
            "name": "HeaterPowerThreshold",
            "caption": "Erwarteter Mindest-Verbrauch bei AN (Watt)",
            "digits": 1
        },
        {
            "type": "NumberSpinner",
            "name": "HeaterDefectTime",
            "caption": "Zeit bis Defekt-Alarm (Sekunden)"
        },
        {
            "type": "NumberSpinner",
            "name": "WindowOpenTime",
            "caption": "Zeit bis Fenster-offen-Alarm im Winter (Sekunden)"
        },
        {
            "type": "NumberSpinner",
            "name": "FrostWarningTemp",
            "caption": "Temperatur für kritischen Frost-Alarm (°C)",
            "digits": 1
        }
    ]
}
EOT;
    }
}

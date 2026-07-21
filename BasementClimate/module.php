<?php

declare(strict_types=1);

require_once __DIR__ . '/../ClimateCommon.php';

class BasementClimate extends IPSModuleStrict
{
    use ClimateCommon;

    public function Create(): void{
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger("SensorTempOutside", 0);
        $this->RegisterPropertyInteger("SensorHumOutside", 0);
        $this->RegisterPropertyInteger("SensorTempInside", 0);
        $this->RegisterPropertyInteger("SensorHumInside", 0);
        $this->RegisterPropertyString("SensorWindows", "[]");
        
        $this->RegisterPropertyInteger("ActuatorDehumidifierPlug", 0);
        $this->RegisterPropertyInteger("SensorDehumidifierPower", 0);
        
        $this->RegisterPropertyInteger("ActuatorRadiator1", 0);
        $this->RegisterPropertyInteger("ActuatorRadiator2", 0);
        
        $this->RegisterPropertyFloat("DehumidifierMaxHum", 60.0);
        $this->RegisterPropertyFloat("DehumidifierMinHum", 55.0);
        $this->RegisterPropertyFloat("DehumidifierPowerThreshold", 10.0);
        $this->RegisterPropertyInteger("DehumidifierPowerTime", 60);
        
        $this->RegisterPropertyFloat("TargetTemperature", 18.0);
        $this->RegisterPropertyFloat("VentilationThreshold", 0.5);
        $this->RegisterPropertyFloat("VentilationCloseMargin", 0.3);
        
        // Variables
        if (!IPS_VariableProfileExists('SmartClimate.VentilationRecommendation')) {
            IPS_CreateVariableProfile('SmartClimate.VentilationRecommendation', 0);
            IPS_SetVariableProfileAssociation('SmartClimate.VentilationRecommendation', false, 'Nicht Lüften!', '', -1);
            IPS_SetVariableProfileAssociation('SmartClimate.VentilationRecommendation', true, 'Lüften!', '', -1);
        }
        $this->RegisterVariableBoolean("VentilationRecommendation", "Lüften empfohlen!", "SmartClimate.VentilationRecommendation");
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('VentilationRecommendation'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Wind'
        ]);
        $this->RegisterVariableString("VentilationDetails", "Hinweis", "");
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('VentilationDetails'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Wind'
        ]);
        
        $this->RegisterVariableFloat("DewPointInside", "Taupunkt Keller", "");
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('DewPointInside'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' °C',
            'DECIMALPLACES' => 1
        ]);
        $this->RegisterVariableFloat("DewPointOutside", "Taupunkt Außen", "");
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('DewPointOutside'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' °C',
            'DECIMALPLACES' => 1
        ]);
        
        $this->RegisterVariableFloat("AbsHumInside", "Absolute Feuchte Keller", "");
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('AbsHumInside'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' g/m³',
            'DECIMALPLACES' => 2
        ]);
        $this->RegisterVariableFloat("AbsHumOutside", "Absolute Feuchte Außen", "");
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('AbsHumOutside'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' g/m³',
            'DECIMALPLACES' => 2
        ]);
        $this->RegisterVariableFloat("CurrentHumidity", "Aktuelle Luftfeuchtigkeit", "");
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('CurrentHumidity'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' %',
            'DECIMALPLACES' => 1
        ]);
        
        $this->RegisterVariableFloat("DehumidifierMaxHum", "Einschaltschwelle (Max %)", "");
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('DehumidifierMaxHum'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' %',
            'DECIMALPLACES' => 1
        ]);
        $this->EnableAction("DehumidifierMaxHum");
        
        $this->RegisterVariableFloat("DehumidifierMinHum", "Ausschaltschwelle (Min %)", "");
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('DehumidifierMinHum'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' %',
            'DECIMALPLACES' => 1
        ]);
        $this->EnableAction("DehumidifierMinHum");
        
        $this->RegisterVariableInteger("DehumidifierStatus", "Status Entfeuchter", "");
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('DehumidifierStatus'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops'
        ]);
        
        if (!IPS_VariableProfileExists('SmartClimate.DehumidifierStatus')) {
            IPS_CreateVariableProfile('SmartClimate.DehumidifierStatus', 1);
            IPS_SetVariableProfileAssociation('SmartClimate.DehumidifierStatus', 0, 'Aus', 'Sleep', 0x00FF00);
            IPS_SetVariableProfileAssociation('SmartClimate.DehumidifierStatus', 1, 'Entfeuchten', 'Drops', 0x0000FF);
            IPS_SetVariableProfileAssociation('SmartClimate.DehumidifierStatus', 2, 'Pausiert (Fenster offen)', 'Window', 0xFFFF00);
            IPS_SetVariableProfileAssociation('SmartClimate.DehumidifierStatus', 3, 'Pausiert (Tank voll)', 'Warning', 0xFF0000);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('DehumidifierStatus'), 'SmartClimate.DehumidifierStatus');
        
        // Alarm Variables (no legacy profiles — use CustomPresentation via Trait)
        $this->RegisterVariableBoolean("AlarmTankFull", "Alarm: Wassertank voll", "");
        IPS_SetIcon($this->GetIDForIdent('AlarmTankFull'), 'Warning');
        $this->EnableAction("AlarmTankFull");
        
        $this->RegisterVariableBoolean("AlarmWindowClose", "Alarm: Fenster schließen", "");
        IPS_SetIcon($this->GetIDForIdent('AlarmWindowClose'), 'Warning');
        $this->EnableAction("AlarmWindowClose");
        
        // Timers
        $this->RegisterTimer("PowerCheckTimer", 0, 'BC_CheckPowerThreshold($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $ref_SensorTempOutside = $this->ReadPropertyInteger('SensorTempOutside');
        if ($ref_SensorTempOutside > 1 && @IPS_ObjectExists($ref_SensorTempOutside)) {
            $this->RegisterReference($ref_SensorTempOutside);
        }
        $ref_SensorHumOutside = $this->ReadPropertyInteger('SensorHumOutside');
        if ($ref_SensorHumOutside > 1 && @IPS_ObjectExists($ref_SensorHumOutside)) {
            $this->RegisterReference($ref_SensorHumOutside);
        }
        $ref_SensorTempInside = $this->ReadPropertyInteger('SensorTempInside');
        if ($ref_SensorTempInside > 1 && @IPS_ObjectExists($ref_SensorTempInside)) {
            $this->RegisterReference($ref_SensorTempInside);
        }
        $ref_SensorHumInside = $this->ReadPropertyInteger('SensorHumInside');
        if ($ref_SensorHumInside > 1 && @IPS_ObjectExists($ref_SensorHumInside)) {
            $this->RegisterReference($ref_SensorHumInside);
        }
        $ref_ActuatorDehumidifierPlug = $this->ReadPropertyInteger('ActuatorDehumidifierPlug');
        if ($ref_ActuatorDehumidifierPlug > 1 && @IPS_ObjectExists($ref_ActuatorDehumidifierPlug)) {
            $this->RegisterReference($ref_ActuatorDehumidifierPlug);
        }
        $ref_SensorDehumidifierPower = $this->ReadPropertyInteger('SensorDehumidifierPower');
        if ($ref_SensorDehumidifierPower > 1 && @IPS_ObjectExists($ref_SensorDehumidifierPower)) {
            $this->RegisterReference($ref_SensorDehumidifierPower);
        }
        $ref_ActuatorRadiator1 = $this->ReadPropertyInteger('ActuatorRadiator1');
        if ($ref_ActuatorRadiator1 > 1 && @IPS_ObjectExists($ref_ActuatorRadiator1)) {
            $this->RegisterReference($ref_ActuatorRadiator1);
        }
        $ref_ActuatorRadiator2 = $this->ReadPropertyInteger('ActuatorRadiator2');
        if ($ref_ActuatorRadiator2 > 1 && @IPS_ObjectExists($ref_ActuatorRadiator2)) {
            $this->RegisterReference($ref_ActuatorRadiator2);
        }
        $this->RegisterWindowReferences(); // Trait
        // ---------------------------------

        // Unregister old messages, then re-register (Trait)
        $this->UnregisterAllMessages();
        
        $sensors = ["SensorTempOutside", "SensorHumOutside", "SensorTempInside", "SensorHumInside"];
        foreach ($sensors as $sensorName) {
            $id = $this->ReadPropertyInteger($sensorName);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
        $this->RegisterWindowMessages(); // Trait
        
        $powerId = $this->ReadPropertyInteger("SensorDehumidifierPower");
        if ($powerId > 0 && IPS_VariableExists($powerId)) {
            $this->RegisterMessage($powerId, VM_UPDATE);
        }
        
        // Presentations (Symcon 8+)
        // Presentations are now handled via IPS_SetVariableCustomPresentation in Create()
        
        // Alarm-Variablen via Trait (Switch mit Farben)
        $this->SetupAlarmPresentation('AlarmTankFull',    'ALARM: Wassertank voll');
        $this->SetupAlarmPresentation('AlarmWindowClose', 'ALARM: Fenster schließen', 'OK', 0xFF6600);
        
        $this->UpdateClimate();
    }
    
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void{
        $powerId = $this->ReadPropertyInteger("SensorDehumidifierPower");
        
        if ($SenderID == $powerId) {
            $this->HandlePowerUpdate($Data[0]);
        } else {
            $this->UpdateClimate();
        }
    }
    
    public function RequestAction(string $Ident, $Value): void{
        switch ($Ident) {
            case "AlarmTankFull":
            case "AlarmWindowClose":
                if ($Value == false) {
                    $this->SetValue($Ident, false);
                    $this->UpdateClimate();
                }
                break;
            case "DehumidifierMaxHum":
            case "DehumidifierMinHum":
                $this->SetValue($Ident, $Value);
                $this->UpdateClimate();
                break;
            default:
                throw new Exception("Invalid Ident");
        }
    }

    public function UpdateClimate(): void
    {
        $tempOut = $this->GetPropertyVarValue("SensorTempOutside"); // Trait
        $humOut  = $this->GetPropertyVarValue("SensorHumOutside");  // Trait
        $tempIn  = $this->GetPropertyVarValue("SensorTempInside");  // Trait
        $humIn   = $this->GetPropertyVarValue("SensorHumInside");   // Trait
        
        $windowOpen = $this->AnyWindowOpen(); // Trait
        
        if ($tempIn !== null && $humIn !== null) {
            $this->SetValue("CurrentHumidity", $humIn);
            $this->ControlDehumidifier($humIn, $windowOpen);
        }
        
        if ($tempOut !== null && $humOut !== null && $tempIn !== null && $humIn !== null) {
            $absOut = $this->CalculateAbsoluteHumidity($tempOut, $humOut);
            $dpOut  = $this->CalculateDewPoint($tempOut, $humOut);
            $absIn  = $this->CalculateAbsoluteHumidity($tempIn, $humIn);
            $dpIn   = $this->CalculateDewPoint($tempIn, $humIn);
            
            $this->SetValue("AbsHumOutside", $absOut);
            $this->SetValue("DewPointOutside", $dpOut);
            $this->SetValue("AbsHumInside", $absIn);
            $this->SetValue("DewPointInside", $dpIn);
            
            $threshold   = $this->ReadPropertyFloat("VentilationThreshold");
            $closeMargin = $this->ReadPropertyFloat("VentilationCloseMargin");
            $recommendation = false;
            $closeAlarm     = false;
            $details        = "Keine Aktion erforderlich.";
            
            if (!$windowOpen) {
                if ($absOut <= ($absIn - $threshold)) {
                    $recommendation = true;
                    $details = sprintf("Lüften empfohlen! Außen ist trockener (Außen: %.2f g/m³, Innen: %.2f g/m³)", $absOut, $absIn);
                } else {
                    $details = sprintf("Lüften lohnt nicht (Außen: %.2f g/m³, Innen: %.2f g/m³)", $absOut, $absIn);
                }
                $this->SetValueIfChanged("AlarmWindowClose", false); // Trait
            } else {
                if ($absOut >= ($absIn - $closeMargin)) {
                    $closeAlarm = true;
                    if ($absOut >= $absIn) {
                        $details = sprintf("Fenster SCHLIESSEN! Außen wird es feuchter (Außen: %.2f g/m³, Innen: %.2f g/m³)", $absOut, $absIn);
                    } else {
                        $details = sprintf("Achtung: Fenster bald schließen! Außenfeuchte nähert sich der Innenfeuchte (Außen: %.2f g/m³, Innen: %.2f g/m³)", $absOut, $absIn);
                    }
                } else {
                    $details = sprintf("Lüften trocknet weiterhin (Außen: %.2f g/m³, Innen: %.2f g/m³)", $absOut, $absIn);
                }
                if ($closeAlarm) {
                    $this->SetValueIfChanged("AlarmWindowClose", true); // Trait
                }
            }
            
            $this->SetValueIfChanged("VentilationRecommendation", $recommendation); // Trait
            $this->SetValueIfChanged("VentilationDetails", $details);               // Trait
        }
        
        $this->ControlHeating($humIn);
    }
    
    private function ControlDehumidifier(float $humIn, bool $windowOpen): void
    {
        $plugId = $this->ReadPropertyInteger("ActuatorDehumidifierPlug");
        if ($plugId == 0 || !IPS_VariableExists($plugId)) return;
        
        $maxHum   = $this->GetValue("DehumidifierMaxHum");
        $minHum   = $this->GetValue("DehumidifierMinHum");
        $tankFull = $this->GetValue("AlarmTankFull");
        
        $plugStatus = GetValue($plugId);
        $newStatus  = $plugStatus;
        $statusText = 0; // 0=Aus, 1=Entfeuchten, 2=Fenster offen, 3=Tank voll
        
        if ($windowOpen) {
            $newStatus  = false;
            $statusText = 2;
        } else {
            if ($humIn >= $maxHum) {
                $newStatus = true;
            } elseif ($humIn <= $minHum) {
                $newStatus = false;
            } else {
                $newStatus = $plugStatus;
            }
            $statusText = $tankFull ? 3 : ($newStatus ? 1 : 0);
        }
        
        if ($plugStatus != $newStatus) {
            $this->SLog('INFO', 'Entfeuchter ' . ($newStatus ? 'eingeschaltet' : 'ausgeschaltet'), 'Luftfeuchtigkeit: ' . $humIn . '% | Schwellenwert: ' . ($newStatus ? $maxHum : $minHum) . '%');
            if (!@RequestAction($plugId, $newStatus)) {
                $this->SLog('WARNING', 'Entfeuchterbefehl fehlgeschlagen', "Dehumidifier Plug ID: $plugId | Ziel: " . ($newStatus ? 'An' : 'Aus'));
            }
        }
        
        $this->SetValueIfChanged("DehumidifierStatus", $statusText); // Trait
        $this->SetValueIfChanged("AlarmTankFull", $tankFull);        // Trait
    }
    
    private function ControlHeating(mixed $humIn): void
    {
        $rad1       = $this->ReadPropertyInteger("ActuatorRadiator1");
        $rad2       = $this->ReadPropertyInteger("ActuatorRadiator2");
        $targetBase = $this->ReadPropertyFloat("TargetTemperature");
        
        // Anti-Schimmel: Bei extrem hoher Feuchte Temperatur um 2°C anheben
        $targetTemp = $targetBase;
        if ($humIn > 70.0) {
            $targetTemp += 2.0;
        }
        
        if ($rad1 > 0 && IPS_VariableExists($rad1) && GetValue($rad1) != $targetTemp) {
            $this->SLog('INFO', 'Heizkörper Zieltemperatur gesetzt', "Radiator: $rad1 | Ziel: $targetTemp°C | Feuchte: $humIn%");
            if (!@RequestAction($rad1, $targetTemp)) {
                $this->SLog('WARNING', 'Heizungsbefehl fehlgeschlagen', "Radiator ID: $rad1 | Ziel: $targetTemp°C");
            }
        }
        if ($rad2 > 0 && IPS_VariableExists($rad2) && GetValue($rad2) != $targetTemp) {
            $this->SLog('INFO', 'Heizkörper Zieltemperatur gesetzt', "Radiator: $rad2 | Ziel: $targetTemp°C | Feuchte: $humIn%");
            if (!@RequestAction($rad2, $targetTemp)) {
                $this->SLog('WARNING', 'Heizungsbefehl fehlgeschlagen', "Radiator ID: $rad2 | Ziel: $targetTemp°C");
            }
        }
    }
    
    private function HandlePowerUpdate(float $currentPower): void
    {
        $plugId = $this->ReadPropertyInteger("ActuatorDehumidifierPlug");
        if ($plugId == 0) return;
        
        $plugStatus = GetValue($plugId);
        $threshold  = $this->ReadPropertyFloat("DehumidifierPowerThreshold");
        $timeLimit  = $this->ReadPropertyInteger("DehumidifierPowerTime");
        
        if ($plugStatus) {
            if ($currentPower < $threshold) {
                // Timer nur starten, wenn nicht bereits läuft UND kein aktiver Alarm
                if ($this->GetTimerInterval("PowerCheckTimer") == 0 && !$this->GetValue("AlarmTankFull")) {
                    $this->SetTimerInterval("PowerCheckTimer", $timeLimit * 1000);
                }
            } else {
                $this->StopTimer("PowerCheckTimer"); // Trait
                if ($this->GetValue("AlarmTankFull")) {
                    $this->SetValue("AlarmTankFull", false);
                    $this->UpdateClimate();
                }
            }
        } else {
            $this->StopTimer("PowerCheckTimer"); // Trait
        }
    }
    
    public function CheckPowerThreshold(): void
    {
        $this->StopTimer("PowerCheckTimer"); // Trait
        $this->SetValue("AlarmTankFull", true);
        $this->UpdateClimate();
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "⚙ Sensoren (Außen)",
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectVariable",
                            "name": "SensorTempOutside",
                            "caption": "Temperatur Außen"
                        },
                        {
                            "type": "SelectVariable",
                            "name": "SensorHumOutside",
                            "caption": "Feuchtigkeit Außen"
                        }
                    ]
                },
                {
                    "type": "Label",
                    "caption": "Sensoren (Innen/Keller)\nHier wählst du die Sensoren für den Innenbereich aus:"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectVariable",
                            "name": "SensorTempInside",
                            "caption": "Temperatur Keller"
                        },
                        {
                            "type": "SelectVariable",
                            "name": "SensorHumInside",
                            "caption": "Feuchtigkeit Keller"
                        }
                    ]
                }
            ]
        },
        {
            "type": "List",
            "name": "SensorWindows",
            "caption": "Fenster-/Türkontakte (Keller)",
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
            "caption": "Aktoren\nHier stellst du ein, welche Geräte geschaltet werden sollen:"
        },
        {
            "type": "SelectVariable",
            "name": "ActuatorDehumidifierPlug",
            "caption": "Schaltsteckdose Entfeuchter"
        },
        {
            "type": "SelectVariable",
            "name": "SensorDehumidifierPower",
            "caption": "Leistungsmessung Entfeuchter (Watt)"
        },
        {
            "type": "SelectVariable",
            "name": "ActuatorRadiator1",
            "caption": "Heizkörper 1 (Solltemperatur)"
        },
        {
            "type": "SelectVariable",
            "name": "ActuatorRadiator2",
            "caption": "Heizkörper 2 (Solltemperatur)"
        },
        {
            "type": "Label",
            "caption": "Einstellungen Entfeuchter\n\nHier stellst du ein, wie der Entfeuchter gesteuert werden soll. Das Modul steuert den Entfeuchter automatisch basierend auf der Kellerfeuchtigkeit. Ist ein Fenster geöffnet, pausiert er. Ist der Wassertank voll (erkannt am geringen Stromverbrauch), schlägt das Modul Alarm."
        },
        {
            "type": "NumberSpinner",
            "name": "DehumidifierPowerThreshold",
            "caption": "Schwellwert für Tank-Voll-Erkennung (Watt)",
            "digits": 1
        },
        {
            "type": "NumberSpinner",
            "name": "DehumidifierPowerTime",
            "caption": "Dauer für Grenzwert (Sekunden)"
        },
        {
            "type": "Label",
            "caption": "Einstellungen Heizung\n\nHier legst du die Basis-Temperatur fest. Bei hoher Feuchtigkeit (>70%) hebt das Modul die Temperatur automatisch um 2°C an, um Schimmel zu vermeiden."
        },
        {
            "type": "NumberSpinner",
            "name": "TargetTemperature",
            "caption": "Basis-Solltemperatur (°C)",
            "digits": 1
        },
        {
            "type": "Label",
            "caption": "Lüftungsempfehlung\n\nHier stellst du ein, ab welcher Feuchtigkeits-Differenz du lüften solltest. Das Modul warnt dich rechtzeitig, bevor es draußen zu feucht wird."
        },
        {
            "type": "NumberSpinner",
            "name": "VentilationThreshold",
            "caption": "Mindest-Differenz (g/m³) für Lüftung",
            "digits": 1
        },
        {
            "type": "NumberSpinner",
            "name": "VentilationCloseMargin",
            "caption": "Puffer (g/m³) für Schließ-Warnung",
            "digits": 1
        }
    ]
}
EOT;
    }
}

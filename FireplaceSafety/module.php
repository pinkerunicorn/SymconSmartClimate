<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_ClimateCommon.php';

class FireplaceSafety extends IPSModuleStrict
{
    use SmartLog_Trait;
    use ClimateCommon_Trait;

    public function Create(): void{
        parent::Create();

        // --- Konfiguration (Properties) ---
        // Sensoren
        $this->RegisterPropertyInteger("SensorOvenTemp", 0);
        $this->RegisterPropertyInteger("SensorRoomTemp", 0);
        $this->RegisterPropertyInteger("SensorOvenDoor", 0);
        $this->RegisterPropertyString("OvenDoorClosedValue", "false");
        $this->RegisterPropertyString("SensorWindows", "[]");
        // Aktoren
        $this->RegisterPropertyInteger("ActuatorHood", 0);
        // Parameter
        $this->RegisterPropertyFloat("OvenDeltaTemp", 15.0);
        $this->RegisterPropertyFloat("PeakDropThreshold", 5.0);
        $this->RegisterPropertyFloat("MaxRoomTemp", 24.0);
        $this->RegisterPropertyInteger("DoorAlarmTime", 300);

        // --- Status-Variablen ---
        $this->RegisterVariableFloat("CurrentDeltaTemp", "Aktuelle Temperatur-Differenz", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Temperature',
            'SUFFIX' => ' °C',
            'DECIMALPLACES' => 1
        ], 1);
        
        $doorOptions = json_encode([
            ['Value' => false, 'Caption' => 'Geschlossen', 'IconValue' => 'Window', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00],
            ['Value' => true, 'Caption' => 'Offen', 'IconValue' => 'Window', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0xFFA500, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFFA500]
        ]);
        $this->RegisterVariableBoolean("CurrentDoorStatus", "Status Ofentür", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Window',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $doorOptions
        ], 2);
        
        $ovenOptions = json_encode([
            ['Value' => false, 'Caption' => 'Aus', 'IconValue' => 'Flame', 'IconActive' => true, 'ColorActive' => false, 'ColorDisplay' => -1, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
            ['Value' => true, 'Caption' => 'An', 'IconValue' => 'Flame', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0xFF6600, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF6600]
        ]);
        $this->RegisterVariableBoolean("OvenStatus", "Status Kaminofen", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Flame',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $ovenOptions
        ], 3);
        
        $hoodOptions = json_encode([
            ['Value' => false, 'Caption' => 'Gesperrt', 'IconValue' => 'Lock', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000],
            ['Value' => true, 'Caption' => 'Freigegeben', 'IconValue' => 'Unlock', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0x00FF00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00FF00]
        ]);
        $this->RegisterVariableBoolean("HoodStatus", "Status Dunstabzugshaube", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $hoodOptions
        ], 4);
        
        $this->RegisterVariableBoolean("AlarmOvenDoor", "Alarm Ofentür", [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'Warning'
        ], 200);
        $this->EnableAction("AlarmOvenDoor"); // Quittierbar per Webfront

        $this->RegisterVariableFloat("OvenPeakTemp", "Letzte Spitzen-Temperatur", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Temperature',
            'SUFFIX' => ' °C'
        ], 5);
        
        $woodOptions = json_encode([
            ['Value' => false, 'Caption' => 'Ausreichend', 'IconValue' => 'Flame', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00],
            ['Value' => true, 'Caption' => 'Nachlegen!', 'IconValue' => 'Flame', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0xFFA500, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFFA500]
        ]);
        $this->RegisterVariableBoolean("WoodRefillNeeded", "Bitte Holz nachlegen", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Flame',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $woodOptions
        ], 100);
        $this->RegisterVariableInteger("FiredCount", "Anzahl Angefeuert", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Flame'
        ], 101);

        // --- Timers ---
        $this->RegisterTimer("DoorAlarmTimer", 0, 'FS_TriggerDoorAlarm($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();
        $sensorID = $this->ReadPropertyInteger('SensorOvenTemp');
        if ($sensorID <= 0) {
            $this->SetStatus(104);
            return;
        }

        
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $ref_SensorOvenTemp = $this->ReadPropertyInteger('SensorOvenTemp');
        if ($ref_SensorOvenTemp > 1 && @IPS_ObjectExists($ref_SensorOvenTemp)) {
            $this->RegisterReference($ref_SensorOvenTemp);
        }
        $ref_SensorRoomTemp = $this->ReadPropertyInteger('SensorRoomTemp');
        if ($ref_SensorRoomTemp > 1 && @IPS_ObjectExists($ref_SensorRoomTemp)) {
            $this->RegisterReference($ref_SensorRoomTemp);
        }
        $ref_SensorOvenDoor = $this->ReadPropertyInteger('SensorOvenDoor');
        if ($ref_SensorOvenDoor > 1 && @IPS_ObjectExists($ref_SensorOvenDoor)) {
            $this->RegisterReference($ref_SensorOvenDoor);
        }
        $ref_ActuatorHood = $this->ReadPropertyInteger('ActuatorHood');
        if ($ref_ActuatorHood > 1 && @IPS_ObjectExists($ref_ActuatorHood)) {
            $this->RegisterReference($ref_ActuatorHood);
        }
        $this->RegisterWindowReferences(); // Trait
        // ---------------------------------

        // Presentations (Symcon 8+)
        // Alarm-Variablen via Trait (Switch mit Farben)
        $this->SetupAlarmPresentation('AlarmOvenDoor',     'ALARM: Ofentür offen!');

        // Messages neu registrieren (Trait)
        $this->UnregisterAllMessages();

        $ovenTemp = $this->ReadPropertyInteger("SensorOvenTemp");
        if ($ovenTemp > 0 && IPS_VariableExists($ovenTemp)) {
            $this->RegisterMessage($ovenTemp, VM_UPDATE);
        }
        $roomTemp = $this->ReadPropertyInteger("SensorRoomTemp");
        if ($roomTemp > 0 && IPS_VariableExists($roomTemp)) {
            $this->RegisterMessage($roomTemp, VM_UPDATE);
        }
        $ovenDoor = $this->ReadPropertyInteger("SensorOvenDoor");
        if ($ovenDoor > 0 && IPS_VariableExists($ovenDoor)) {
            $this->RegisterMessage($ovenDoor, VM_UPDATE);
        }
        $this->RegisterWindowMessages(); // Trait

        // Initial update
        $this->UpdateSafety();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void{
        $this->UpdateSafety();
    }

    public function RequestAction(string $Ident, $Value): void{
        switch ($Ident) {
            case "AlarmOvenDoor":
                if ($Value == false) {
                    $this->SetValue($Ident, false);
                    $this->UpdateSafety();
                }
                break;
            default:
                throw new Exception("Invalid Ident");
        }
    }

    public function TriggerDoorAlarm(): void
    {
        $this->StopTimer("DoorAlarmTimer"); // Trait
        $this->SetValueIfChanged("AlarmOvenDoor", true); // Trait
        $this->SendDebug("Timer", "Ofentür-Alarm ausgelöst!", 0);
    }

    public function ResetFiredCount(): void
    {
        $this->SetValue("FiredCount", 0);
        $this->SLogInfo('Anzahl Angefeuert zurückgesetzt');
    }

    private function UpdateSafety(): void
    {
        $wasOvenOn  = (bool)$this->GetValue("OvenStatus");
        $ovenTempId = $this->ReadPropertyInteger("SensorOvenTemp");
        $roomTempId = $this->ReadPropertyInteger("SensorRoomTemp");
        
        $isOvenOn = false;
        
        // --- 1. Temperatur- & Peak-Logik ---
        if ($ovenTempId > 0 && IPS_VariableExists($ovenTempId) && $roomTempId > 0 && IPS_VariableExists($roomTempId)) {
            $tOven        = (float)GetValue($ovenTempId);
            $tRoom        = (float)GetValue($roomTempId);
            $deltaSetting = $this->ReadPropertyFloat("OvenDeltaTemp");
            
            $currentDelta = $tOven - $tRoom;
            $this->SetValueIfChanged("CurrentDeltaTemp", $currentDelta); // Trait
            
            if ($currentDelta >= $deltaSetting) {
                $isOvenOn = true;
            }

            // Peak-Tracking und "Nachlegen"-Logik
            $refillNeeded = false;
            if ($isOvenOn) {
                $peak = (float)$this->GetValue("OvenPeakTemp");
                if ($tOven > $peak) {
                    $peak = $tOven;
                    $this->SetValue("OvenPeakTemp", $peak);
                }
                if ($peak > 0 && $tOven <= ($peak - $this->ReadPropertyFloat("PeakDropThreshold"))) {
                    if ($tRoom < $this->ReadPropertyFloat("MaxRoomTemp")) {
                        $refillNeeded = true;
                    }
                }
            } else {
                $this->SetValueIfChanged("OvenPeakTemp", 0.0); // Trait
            }
            $this->SetValueIfChanged("WoodRefillNeeded", $refillNeeded); // Trait
        } else {
            $this->SetValueIfChanged("OvenPeakTemp", 0.0);    // Trait
            $this->SetValueIfChanged("WoodRefillNeeded", false); // Trait
        }

        if ($isOvenOn && !$wasOvenOn) {
            $firedCount = (int)$this->GetValue("FiredCount");
            $this->SetValue("FiredCount", $firedCount + 1);
            $this->SLogInfo('Kamin angefeuert', 'Anzahl Angefeuert: ' . ($firedCount + 1));
        }
        $this->SetValueIfChanged("OvenStatus", $isOvenOn); // Trait

        // --- 2. Fenster-Sensoren (Trait: AnyWindowOpen) ---
        $anyWindowOpen = $this->AnyWindowOpen();

        // --- 3. Ofentür auswerten (Trait: IsWindowOpen) ---
        $isDoorOpen = false;
        $ovenDoorId = $this->ReadPropertyInteger("SensorOvenDoor");
        if ($ovenDoorId > 0 && IPS_VariableExists($ovenDoorId)) {
            $doorClosedValStr = $this->ReadPropertyString("OvenDoorClosedValue");
            $isDoorOpen = $this->IsWindowOpen($ovenDoorId, $doorClosedValStr); // Trait
        }
        $this->SetValueIfChanged("CurrentDoorStatus", $isDoorOpen); // Trait

        // Tür-Alarm-Logik
        if ($isOvenOn && $isDoorOpen) {
            if ($this->GetTimerInterval("DoorAlarmTimer") == 0 && !$this->GetValue("AlarmOvenDoor")) {
                $delay = $this->ReadPropertyInteger("DoorAlarmTime");
                $this->SetTimerInterval("DoorAlarmTimer", $delay * 1000);
                $this->SendDebug("Timer", "Ofentür geöffnet, Timer gestartet ($delay Sekunden)", 0);
            }
        } else {
            if ($this->GetTimerInterval("DoorAlarmTimer") > 0) {
                $this->StopTimer("DoorAlarmTimer"); // Trait
                $this->SendDebug("Timer", "Ofentür geschlossen oder Ofen aus, Timer gestoppt", 0);
            }
            if ($this->GetValue("AlarmOvenDoor")) {
                $this->SetValue("AlarmOvenDoor", false);
            }
        }

        // --- 4. Dunstabzugshaube Sicherheits-Logik ---
        // Haube darf nur an, wenn Ofen aus ODER ein Fenster offen (Zuluft vorhanden)
        $allowHood = !($isOvenOn && !$anyWindowOpen);
        $this->SetValue("HoodStatus", $allowHood);

        $actuatorId = $this->ReadPropertyInteger("ActuatorHood");
        if ($actuatorId > 0 && IPS_VariableExists($actuatorId)) {
            $currentPlug = (bool)GetValue($actuatorId);
            if ($currentPlug !== $allowHood) {
                $this->SLogInfo('Dunstabzugshaube ' . ($allowHood ? 'freigegeben' : 'gesperrt'), 'Ofen an: ' . ($isOvenOn ? 'Ja' : 'Nein') . ' | Fenster offen: ' . ($anyWindowOpen ? 'Ja' : 'Nein'));
                $this->SendDebug("Actuator", "Schalte Dunstabzugshaube: " . ($allowHood ? "AN" : "AUS"), 0);
                if (!@RequestAction($actuatorId, $allowHood)) {
                    $this->SLogWarning('Haubenbefehl fehlgeschlagen', "Actuator ID: $actuatorId | Ziel: " . ($allowHood ? 'An' : 'Aus'));
                }
            }
        } else {
            $this->SLogWarning('Aktor nicht konfiguriert', 'actuatorId: ' . $actuatorId);
        }
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "status": [
        { "code": 104, "icon": "inactive", "caption": "Sensor nicht konfiguriert" }
    ],
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "⚙ Sensoren (Eingänge)",
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectVariable",
                            "name": "SensorOvenTemp",
                            "caption": "Temperatur Ofen / Abgasrohr"
                        },
                        {
                            "type": "SelectVariable",
                            "name": "SensorRoomTemp",
                            "caption": "Temperatur Raum"
                        }
                    ]
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectVariable",
                            "name": "SensorOvenDoor",
                            "caption": "Ofentür-Kontakt"
                        },
                        {
                            "type": "ValidationTextBox",
                            "name": "OvenDoorClosedValue",
                            "caption": "Ofentür-Kontakt: Wert für 'Geschlossen'(z.B. false, 0, geschlossen)"
                        }
                    ]
                }
            ]
        },
        {
            "type": "List",
            "name": "SensorWindows",
            "caption": "Fenster-Kontakte (Zuluft)",
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Fenster Sensor",
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
            "type": "ExpansionPanel",
            "caption": "🔧 Parameter & Schwellenwerte",
            "items": [
                {
                    "type": "Label",
                    "caption": "Hier stellst du ein, wie sensibel das Modul auf Temperaturveränderungen reagieren soll:"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "NumberSpinner",
                            "name": "OvenDeltaTemp",
                            "caption": "Ofen an ab Temp-Delta (°C)",
                            "digits": 1,
                            "minimum": 1,
                            "maximum": 50
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "PeakDropThreshold",
                            "caption": "Temp-Abfall für 'Nachlegen' (°C)",
                            "digits": 1,
                            "minimum": 1,
                            "maximum": 50
                        }
                    ]
                },
                {
                    "type": "Label",
                    "caption": "Erklärung: \n- 'Ofen an': Der Kamin gilt als eingeschaltet, wenn der Ofenfühler um diesen Wert wärmer ist als der Raum.\n- 'Nachlegen': Fällt die Temperatur nach dem Höhepunkt um diesen Wert ab, wird zum Nachlegen geraten."
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "NumberSpinner",
                            "name": "MaxRoomTemp",
                            "caption": "Max. Raumtemp. für 'Nachlegen' (°C)",
                            "digits": 1,
                            "minimum": 10,
                            "maximum": 35
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "DoorAlarmTime",
                            "caption": "Ofentür-Alarm Vorwarnzeit (s)",
                            "minimum": 0,
                            "maximum": 3600
                        }
                    ]
                },
                {
                    "type": "Label",
                    "caption": "Erklärung: \n- 'Max. Raumtemp': Ist der Raum bereits wärmer als dieser Wert, blockiert das Modul die Nachlege-Meldung.\n- 'Vorwarnzeit': Wie lange darf die Ofentür bei brennendem Ofen offen stehen, bevor ein Alarm ausgelöst wird?"
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Aktoren (Ausgänge)\nHier wählst du den Aktor für die Dunstabzugshaube aus:"
        },
        {
            "type": "SelectVariable",
            "name": "ActuatorHood",
            "caption": "Schaltsteckdose Dunstabzugshaube"
        }
    ]
}
EOT;
    }
}

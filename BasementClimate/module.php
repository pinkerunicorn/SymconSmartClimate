<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_ClimateCommon.php';

class BasementClimate extends IPSModuleStrict
{
    use SmartLog_Trait;
    use ClimateCommon_Trait;

    public function Create(): void{
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger("SensorTempOutside", 0);
        $this->RegisterPropertyInteger("SensorHumOutside", 0);
        $this->RegisterPropertyInteger("SensorTempInside", 0);
        $this->RegisterPropertyInteger("SensorHumInside", 0);
        $this->RegisterPropertyString("SensorWindows", "[]");
        
        $this->RegisterPropertyInteger("SensorRadonShortTerm", 0);
        $this->RegisterPropertyInteger("SensorRadonLongTerm", 0);
        // Defaults: Nicht-Dauerbewohner-Raum (Keller) laut BfS: Warnung 300, Alarm 1000 Bq/m³
        $this->RegisterPropertyFloat("RadonWarningLevel", 300.0);
        $this->RegisterPropertyFloat("RadonAlarmLevel",  1000.0);
        
        $this->RegisterPropertyInteger("SensorCO2", 0);
        $this->RegisterPropertyFloat("CO2WarningLevel", 1000.0);  // ppm
        $this->RegisterPropertyFloat("CO2AlarmLevel",   2000.0);  // ppm
        
        $this->RegisterPropertyInteger("SensorVOC", 0);
        $this->RegisterPropertyFloat("VOCWarningLevel",  500.0);  // µg/m³
        $this->RegisterPropertyFloat("VOCAlarmLevel",   1500.0);  // µg/m³
        
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
        $this->RegisterVariableBoolean("VentilationRecommendation", "Lüften empfohlen!", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Wind'
        ], 100);
        $this->RegisterVariableString("VentilationDetails", "Hinweis", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Wind'
        ], 101);
        
        $this->RegisterVariableFloat("DewPointInside", "Taupunkt Keller", [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' °C',
            'DECIMALPLACES' => 1
        ], 1);
        $this->RegisterVariableFloat("DewPointOutside", "Taupunkt Außen", [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' °C',
            'DECIMALPLACES' => 1
        ], 2);
        
        $this->RegisterVariableFloat("AbsHumInside", "Absolute Feuchte Keller", [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' g/m³',
            'DECIMALPLACES' => 2
        ], 3);
        
        $this->RegisterVariableFloat("AbsHumOutside", "Absolute Feuchte Außen", [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' g/m³',
            'DECIMALPLACES' => 2
        ], 4);
        
        $this->RegisterVariableFloat("CurrentHumidity", "Aktuelle Luftfeuchtigkeit", [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' %',
            'DECIMALPLACES' => 1
        ], 5);
        
        $this->RegisterVariableFloat("RadonShortTerm", "Radon Kurzzeit", [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Gauge',
            'SUFFIX'        => ' Bq/m³',
            'DECIMALPLACES' => 0
        ], 6);
        
        $this->RegisterVariableFloat("RadonLongTerm", "Radon Langzeit", [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Gauge',
            'SUFFIX'        => ' Bq/m³',
            'DECIMALPLACES' => 0
        ], 7);
        
        $this->RegisterVariableInteger("RadonStatus", "Radon Status", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Gauge'
        ], 8);
        
        $this->RegisterVariableString("RadonRecommendation", "Radon Empfehlung", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Gauge'
        ], 102);
        
        $this->RegisterVariableFloat("CO2Value", "CO₂-Konzentration", [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Climate',
            'SUFFIX'        => ' ppm',
            'DECIMALPLACES' => 0
        ], 9);
        
        $this->RegisterVariableInteger("CO2Status", "CO₂ Status", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Climate'
        ], 10);
        
        $this->RegisterVariableString("CO2Recommendation", "CO₂ Empfehlung", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Climate'
        ], 103);
        
        $this->RegisterVariableFloat("VOCValue", "VOC-Konzentration", [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Climate',
            'SUFFIX'        => ' µg/m³',
            'DECIMALPLACES' => 0
        ], 11);
        
        $this->RegisterVariableInteger("VOCStatus", "VOC Status", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Climate'
        ], 12);
        
        $this->RegisterVariableString("VOCRecommendation", "VOC Empfehlung", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Climate'
        ], 104);
        
        $sliderPresentation = [
            'PRESENTATION'  => VARIABLE_PRESENTATION_SLIDER,
            'ICON'          => 'Drops',
            'SUFFIX'        => ' %',
            'MIN'           => 30,
            'MAX'           => 90,
            'STEP'          => 1,
            'DECIMALPLACES' => 1
        ];

        $this->RegisterVariableFloat("DehumidifierMaxHum", "Einschaltschwelle (Max %)", $sliderPresentation, 200);
        $this->EnableAction("DehumidifierMaxHum");
        
        $this->RegisterVariableFloat("DehumidifierMinHum", "Ausschaltschwelle (Min %)", $sliderPresentation, 201);
        $this->EnableAction("DehumidifierMinHum");
        
        $this->RegisterVariableInteger("DehumidifierStatus", "Status Entfeuchter", [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'Drops'
        ], 13);
        
        $this->RegisterVariableBoolean("AlarmTankFull", "Alarm: Wassertank voll", "", 202);
        $this->EnableAction("AlarmTankFull");
        
        $this->RegisterVariableBoolean("AlarmWindowClose", "Alarm: Fenster schließen", "", 203);
        $this->EnableAction("AlarmWindowClose");
        
        // Timers
        $this->RegisterTimer("PowerCheckTimer", 0, 'BC_CheckPowerThreshold($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();
        $sensorID = $this->ReadPropertyInteger('SensorTempInside');
        if ($sensorID <= 0) {
            $this->SetStatus(104);
            return;
        }

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
        $ref_SensorRadonShortTerm = $this->ReadPropertyInteger('SensorRadonShortTerm');
        if ($ref_SensorRadonShortTerm > 1 && @IPS_ObjectExists($ref_SensorRadonShortTerm)) {
            $this->RegisterReference($ref_SensorRadonShortTerm);
        }
        $ref_SensorRadonLongTerm = $this->ReadPropertyInteger('SensorRadonLongTerm');
        if ($ref_SensorRadonLongTerm > 1 && @IPS_ObjectExists($ref_SensorRadonLongTerm)) {
            $this->RegisterReference($ref_SensorRadonLongTerm);
        }
        $ref_SensorCO2 = $this->ReadPropertyInteger('SensorCO2');
        if ($ref_SensorCO2 > 1 && @IPS_ObjectExists($ref_SensorCO2)) {
            $this->RegisterReference($ref_SensorCO2);
        }
        $ref_SensorVOC = $this->ReadPropertyInteger('SensorVOC');
        if ($ref_SensorVOC > 1 && @IPS_ObjectExists($ref_SensorVOC)) {
            $this->RegisterReference($ref_SensorVOC);
        }
        $this->RegisterWindowReferences(); // Trait
        // ---------------------------------

        // Unregister old messages, then re-register (Trait)
        $this->UnregisterAllMessages();
        
        $sensors = ["SensorTempOutside", "SensorHumOutside", "SensorTempInside", "SensorHumInside",
                    "SensorRadonShortTerm", "SensorRadonLongTerm", "SensorCO2", "SensorVOC"];
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
        
        if (!IPS_VariableProfileExists('BC.RadonStatus')) {
            IPS_CreateVariableProfile('BC.RadonStatus', 1);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('RadonStatus'), 'BC.RadonStatus');
        IPS_SetVariableProfileAssociation('BC.RadonStatus', 0, 'Gut', 'Ok', 0x00CC00);
        IPS_SetVariableProfileAssociation('BC.RadonStatus', 1, 'Mittel', 'Warning', 0xFFA500);
        IPS_SetVariableProfileAssociation('BC.RadonStatus', 2, 'Hoch', 'Alert', 0xFF0000);
        IPS_SetVariableProfileAssociation('BC.RadonStatus', 3, 'Sehr hoch', 'Alert', 0xCC0000);

        
        if (!IPS_VariableProfileExists('BC.CO2Status')) {
            IPS_CreateVariableProfile('BC.CO2Status', 1);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('CO2Status'), 'BC.CO2Status');
        IPS_SetVariableProfileAssociation('BC.CO2Status', 0, 'Gut', 'Ok', 0x00CC00);
        IPS_SetVariableProfileAssociation('BC.CO2Status', 1, 'Mittel', 'Warning', 0xFFA500);
        IPS_SetVariableProfileAssociation('BC.CO2Status', 2, 'Hoch', 'Alert', 0xFF0000);
        if (!IPS_VariableProfileExists('BC.VOCStatus')) {
            IPS_CreateVariableProfile('BC.VOCStatus', 1);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('VOCStatus'), 'BC.VOCStatus');
        IPS_SetVariableProfileAssociation('BC.VOCStatus', 0, 'Gut', 'Ok', 0x00CC00);
        IPS_SetVariableProfileAssociation('BC.VOCStatus', 1, 'Mittel', 'Warning', 0xFFA500);
        IPS_SetVariableProfileAssociation('BC.VOCStatus', 2, 'Hoch', 'Alert', 0xFF0000);


        
        if (!IPS_VariableProfileExists('BC.DehumidifierStatus')) {
            IPS_CreateVariableProfile('BC.DehumidifierStatus', 1);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('DehumidifierStatus'), 'BC.DehumidifierStatus');
        IPS_SetVariableProfileAssociation('BC.DehumidifierStatus', 0, 'Aus', 'Sleep', -1);
        IPS_SetVariableProfileAssociation('BC.DehumidifierStatus', 1, 'Aktiv', 'Drops', 0x0088FF);
        IPS_SetVariableProfileAssociation('BC.DehumidifierStatus', 2, 'Fenster offen', 'Window', 0xFFCC00);
        IPS_SetVariableProfileAssociation('BC.DehumidifierStatus', 3, 'Tank voll!', 'Warning', 0xFF0000);

        $ventRecOptions = json_encode([
            ['Value' => false, 'Caption' => 'Nein', 'IconValue' => 'Wind', 'IconActive' => true, 'ColorActive' => false, 'ColorDisplay' => -1, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
            ['Value' => true, 'Caption' => 'Lüften empfohlen', 'IconValue' => 'Wind', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0x0088FF, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x0088FF]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('VentilationRecommendation'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}', 'ICON' => 'Wind', 'COLOR' => -1, 'CONTENT_COLOR' => -1, 'DISPLAY_TYPE' => 0, 'PREVIEW_STYLE' => 1, 'SHOW_PREVIEW' => true, 'OPTIONS' => $ventRecOptions
        ]);

        if (!IPS_VariableProfileExists('SM.Climate.Alarm')) {
            IPS_CreateVariableProfile('SM.Climate.Alarm', 0);
            IPS_SetVariableProfileAssociation('SM.Climate.Alarm', 0, 'OK', 'Ok', 0x00CC00);
            IPS_SetVariableProfileAssociation('SM.Climate.Alarm', 1, 'Alarm!', 'Alert', 0xFF0000);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('AlarmTankFull'), 'SM.Climate.Alarm');
        IPS_SetVariableCustomProfile($this->GetIDForIdent('AlarmWindowClose'), 'SM.Climate.Alarm');
        $defaultMax = $this->ReadPropertyFloat("DehumidifierMaxHum");
        if ($defaultMax == 0.0) {
            $defaultMax = 60.0;
        }
        if ($this->GetValue("DehumidifierMaxHum") == 0.0) {
            $this->SetValue("DehumidifierMaxHum", $defaultMax);
        }

        $defaultMin = $this->ReadPropertyFloat("DehumidifierMinHum");
        if ($defaultMin == 0.0) {
            $defaultMin = 55.0;
        }
        if ($this->GetValue("DehumidifierMinHum") == 0.0) {
            $this->SetValue("DehumidifierMinHum", $defaultMin);
        }

        $this->UpdateClimate();
        $this->SyncAirQualityValues();
    }
    
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void{
        $powerId      = $this->ReadPropertyInteger("SensorDehumidifierPower");
        $radonShortId = $this->ReadPropertyInteger("SensorRadonShortTerm");
        $radonLongId  = $this->ReadPropertyInteger("SensorRadonLongTerm");
        $co2Id        = $this->ReadPropertyInteger("SensorCO2");
        $vocId        = $this->ReadPropertyInteger("SensorVOC");
        
        if ($SenderID == $powerId) {
            $this->HandlePowerUpdate($Data[0]);
        } elseif ($radonShortId > 0 && $SenderID == $radonShortId) {
            $this->SetValueIfChanged("RadonShortTerm", (float) $Data[0]);
            $this->EvaluateRadon((float) $Data[0], $this->GetValue("RadonLongTerm"));
        } elseif ($radonLongId > 0 && $SenderID == $radonLongId) {
            $this->SetValueIfChanged("RadonLongTerm", (float) $Data[0]);
            $this->EvaluateRadon($this->GetValue("RadonShortTerm"), (float) $Data[0]);
        } elseif ($co2Id > 0 && $SenderID == $co2Id) {
            $this->SetValueIfChanged("CO2Value", (float) $Data[0]);
            $this->EvaluateCO2((float) $Data[0]);
        } elseif ($vocId > 0 && $SenderID == $vocId) {
            $this->SetValueIfChanged("VOCValue", (float) $Data[0]);
            $this->EvaluateVOC((float) $Data[0]);
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
        if ($plugId == 0 || !IPS_VariableExists($plugId)) {
            $this->SLog('WARNING', 'Aktor nicht konfiguriert oder nicht gefunden', "Property-ID: ActuatorDehumidifierPlug");
            return;
        }
        
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
            $this->SLog('INFO', 'Heizkörper Zieltemperatur gesetzt', "Radiator: $rad1 | Ziel: {$targetTemp}°C | Feuchte: {$humIn}%");
            if (!@RequestAction($rad1, $targetTemp)) {
                $this->SLog('WARNING', 'Heizungsbefehl fehlgeschlagen', "Radiator ID: $rad1 | Ziel: {$targetTemp}°C");
            }
        }
        if ($rad2 > 0 && IPS_VariableExists($rad2) && GetValue($rad2) != $targetTemp) {
            $this->SLog('INFO', 'Heizkörper Zieltemperatur gesetzt', "Radiator: $rad2 | Ziel: {$targetTemp}°C | Feuchte: {$humIn}%");
            if (!@RequestAction($rad2, $targetTemp)) {
                $this->SLog('WARNING', 'Heizungsbefehl fehlgeschlagen', "Radiator ID: $rad2 | Ziel: {$targetTemp}°C");
            }
        }
    }
    
    private function HandlePowerUpdate(float $currentPower): void
    {
        $plugId = $this->ReadPropertyInteger("ActuatorDehumidifierPlug");
        if ($plugId == 0) {
            $this->SLog('WARNING', 'Aktor nicht konfiguriert', "Property-ID: ActuatorDehumidifierPlug");
            return;
        }
        
        if (!IPS_VariableExists($plugId)) return;
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

    private function SyncAirQualityValues(): void
    {
        // --- Radon ---
        $short = null;
        $long  = null;
        $radonShortId = $this->ReadPropertyInteger("SensorRadonShortTerm");
        if ($radonShortId > 0 && IPS_VariableExists($radonShortId)) {
            $short = (float) GetValue($radonShortId);
            $this->SetValueIfChanged("RadonShortTerm", $short);
        }
        $radonLongId = $this->ReadPropertyInteger("SensorRadonLongTerm");
        if ($radonLongId > 0 && IPS_VariableExists($radonLongId)) {
            $long = (float) GetValue($radonLongId);
            $this->SetValueIfChanged("RadonLongTerm", $long);
        }
        if ($short === null) $short = $this->GetValue("RadonShortTerm");
        if ($long  === null) $long  = $this->GetValue("RadonLongTerm");
        $this->EvaluateRadon($short, $long);
        
        // --- CO2 ---
        $co2Id = $this->ReadPropertyInteger("SensorCO2");
        if ($co2Id > 0 && IPS_VariableExists($co2Id)) {
            $co2 = (float) GetValue($co2Id);
            $this->SetValueIfChanged("CO2Value", $co2);
            $this->EvaluateCO2($co2);
        } else {
            $this->EvaluateCO2($this->GetValue("CO2Value"));
        }
        
        // --- VOC ---
        $vocId = $this->ReadPropertyInteger("SensorVOC");
        if ($vocId > 0 && IPS_VariableExists($vocId)) {
            $voc = (float) GetValue($vocId);
            $this->SetValueIfChanged("VOCValue", $voc);
            $this->EvaluateVOC($voc);
        } else {
            $this->EvaluateVOC($this->GetValue("VOCValue"));
        }
    }
    
    private function EvaluateRadon(float $short, float $long): void
    {
        $warnLevel  = $this->ReadPropertyFloat("RadonWarningLevel");
        $alarmLevel = $this->ReadPropertyFloat("RadonAlarmLevel");
        $maxValue   = max($short, $long);
        
        if ($maxValue >= $alarmLevel) {
            $status = 2;
            $recommendation = sprintf(
                'ALARM: Radon-Wert extrem erhöht! Keller sofort und dauerhaft lüften! '
                . 'Kurzzeit: %d Bq/m³, Langzeit: %d Bq/m³ (Alarmschwelle: %d Bq/m³). '
                . 'Dringende Sanierungsmaßnahmen prüfen!',
                (int) $short, (int) $long, (int) $alarmLevel
            );
        } elseif ($maxValue >= $warnLevel) {
            $status = 1;
            $recommendation = sprintf(
                'Erhöhte Radon-Werte. Regelmäßig lüften empfohlen! '
                . 'Kurzzeit: %d Bq/m³, Langzeit: %d Bq/m³ (Warnschwelle: %d Bq/m³).',
                (int) $short, (int) $long, (int) $warnLevel
            );
        } else {
            $status = 0;
            $recommendation = sprintf(
                'Radon-Werte in Ordnung. Kurzzeit: %d Bq/m³, Langzeit: %d Bq/m³.',
                (int) $short, (int) $long
            );
        }
        
        $this->SetValueIfChanged("RadonStatus", $status);
        $this->SetValueIfChanged("RadonRecommendation", $recommendation);
        if ($status === 2) {
            $this->SLog('WARNING', 'Radon ALARM', sprintf('Kurzzeit: %d, Langzeit: %d Bq/m³', (int) $short, (int) $long));
        } elseif ($status === 1) {
            $this->SLog('INFO', 'Radon erhöht', sprintf('Kurzzeit: %d, Langzeit: %d Bq/m³', (int) $short, (int) $long));
        }
    }
    
    private function EvaluateCO2(float $value): void
    {
        $warnLevel  = $this->ReadPropertyFloat("CO2WarningLevel");
        $alarmLevel = $this->ReadPropertyFloat("CO2AlarmLevel");
        
        if ($value >= $alarmLevel) {
            $status = 2;
            $recommendation = sprintf(
                'SCHLECHTE Luftqualität! CO₂-Konzentration sehr hoch: %d ppm (Alarm ab %d ppm). '
                . 'Sofort lüften – Kellerfenster und -türen öffnen!',
                (int) $value, (int) $alarmLevel
            );
        } elseif ($value >= $warnLevel) {
            $status = 1;
            $recommendation = sprintf(
                'CO₂ erhöht: %d ppm (Warnschwelle: %d ppm). '
                . 'Lüften empfohlen für bessere Luftqualität.',
                (int) $value, (int) $warnLevel
            );
        } else {
            $status = 0;
            $recommendation = sprintf(
                'CO₂-Konzentration gut: %d ppm. Keine Maßnahmen erforderlich.',
                (int) $value
            );
        }
        
        $this->SetValueIfChanged("CO2Status", $status);
        $this->SetValueIfChanged("CO2Recommendation", $recommendation);
        if ($status === 2) {
            $this->SLog('WARNING', 'CO2 ALARM', sprintf('%d ppm', (int) $value));
        }
    }
    
    private function EvaluateVOC(float $value): void
    {
        $warnLevel  = $this->ReadPropertyFloat("VOCWarningLevel");
        $alarmLevel = $this->ReadPropertyFloat("VOCAlarmLevel");
        
        if ($value >= $alarmLevel) {
            $status = 2;
            $recommendation = sprintf(
                'SCHLECHTE Luftqualität! VOC-Belastung sehr hoch: %d µg/m³ (Alarm ab %d µg/m³). '
                . 'Sofort lüften! Mögliche Quellen prüfen (Lacke, Lösungsmittel, Baumaterialien).',
                (int) $value, (int) $alarmLevel
            );
        } elseif ($value >= $warnLevel) {
            $status = 1;
            $recommendation = sprintf(
                'VOC-Belastung erhöht: %d µg/m³ (Warnschwelle: %d µg/m³). '
                . 'Lüften empfohlen.',
                (int) $value, (int) $warnLevel
            );
        } else {
            $status = 0;
            $recommendation = sprintf(
                'VOC-Belastung unbedenklich: %d µg/m³. Keine Maßnahmen erforderlich.',
                (int) $value
            );
        }
        
        $this->SetValueIfChanged("VOCStatus", $status);
        $this->SetValueIfChanged("VOCRecommendation", $recommendation);
        if ($status === 2) {
            $this->SLog('WARNING', 'VOC ALARM', sprintf('%d µg/m³', (int) $value));
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
                },
                {
                    "type": "Label",
                    "caption": "Radon-Sensoren (Keller)\nHier wählst du die Variablen für Radon-Kurz- und Langzeitmessung (Bq/m³) aus:"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectVariable",
                            "name": "SensorRadonShortTerm",
                            "caption": "Radon Kurzzeit (Bq/m³)"
                        },
                        {
                            "type": "SelectVariable",
                            "name": "SensorRadonLongTerm",
                            "caption": "Radon Langzeit (Bq/m³)"
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
        },
        {
            "type": "Label",
            "caption": "Radon-Schwellwerte\n\nFür Keller ohne dauerhaften Aufenthalt: Warnung 300 Bq/m³, Alarm 1000 Bq/m³ (BfS-Empfehlung). WHO-Referenzwert für Wohnräume: 300 Bq/m³."
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "NumberSpinner",
                    "name": "RadonWarningLevel",
                    "caption": "Warnschwelle (Bq/m³)",
                    "digits": 0
                },
                {
                    "type": "NumberSpinner",
                    "name": "RadonAlarmLevel",
                    "caption": "Alarmschwelle (Bq/m³)",
                    "digits": 0
                }
            ]
        },
        {
            "type": "Label",
            "caption": "CO₂-Sensor\n\nKohlendioxid-Konzentration in ppm. Frischluft ca. 400 ppm, gute Raumluft < 1000 ppm, erhöht 1000–2000 ppm, schlecht > 2000 ppm."
        },
        {
            "type": "SelectVariable",
            "name": "SensorCO2",
            "caption": "CO₂-Sensor (ppm)"
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "NumberSpinner",
                    "name": "CO2WarningLevel",
                    "caption": "CO₂ Warnschwelle (ppm)",
                    "digits": 0
                },
                {
                    "type": "NumberSpinner",
                    "name": "CO2AlarmLevel",
                    "caption": "CO₂ Alarmschwelle (ppm)",
                    "digits": 0
                }
            ]
        },
        {
            "type": "Label",
            "caption": "VOC-Sensor\n\nFlüchtige organische Verbindungen in µg/m³. Gut < 500, erhöht 500–1500, schlecht > 1500 µg/m³. Abhängig vom Sensor-Modell – Schwellwerte ggf. anpassen."
        },
        {
            "type": "SelectVariable",
            "name": "SensorVOC",
            "caption": "VOC-Sensor (µg/m³)"
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "NumberSpinner",
                    "name": "VOCWarningLevel",
                    "caption": "VOC Warnschwelle (µg/m³)",
                    "digits": 0
                },
                {
                    "type": "NumberSpinner",
                    "name": "VOCAlarmLevel",
                    "caption": "VOC Alarmschwelle (µg/m³)",
                    "digits": 0
                }
            ]
        }
    ]
}
EOT;
    }
}

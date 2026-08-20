<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_ClimateCommon.php';
require_once __DIR__ . '/../libs/Trait_DeviceRegistration.php';

class SmartClimateZone extends IPSModuleStrict
{
    use SmartLog_Trait;
    use ClimateCommon_Trait;
    use DeviceRegistration_Trait;

    public function Create(): void{
        parent::Create();

        // Feature Toggles
        $this->RegisterPropertyBoolean("EnableFrostProtection", false);
        $this->RegisterPropertyBoolean("EnableDehumidifier", false);
        $this->RegisterPropertyBoolean("EnableAirQuality", false);
        $this->RegisterPropertyBoolean("ForceVentilationOnBadAir", false);
        $this->RegisterPropertyBoolean("EnableFreeCooling", false);
        $this->RegisterPropertyInteger("RegistryID", 0);
        
        $this->RegisterPropertyFloat("TargetCoolingTemp", 23.0);
        $this->RegisterPropertyFloat("MoldWarningThreshold", 60.0);

        // Core Properties (Temperature / Humidity Inside & Outside)
        $this->RegisterPropertyInteger("SensorTempInside", 0);
        $this->RegisterPropertyInteger("SensorHumInside", 0);
        $this->RegisterPropertyInteger("SensorTempOutside", 0);
        $this->RegisterPropertyInteger("SensorHumOutside", 0);
        $this->RegisterPropertyString("SensorWindows", "[]");
        $this->RegisterPropertyFloat("VentilationThreshold", 0.5);
        $this->RegisterPropertyFloat("VentilationCloseMargin", 0.3);

        // Frost Protection Properties
        $this->RegisterPropertyString("ActuatorHeaterPlug", "0");
        $this->RegisterPropertyInteger("SensorHeaterPower", 0);
        $this->RegisterPropertyFloat("Hysteresis", 0.5);
        $this->RegisterPropertyFloat("HeaterPowerThreshold", 50.0);
        $this->RegisterPropertyInteger("HeaterDefectTime", 300);
        $this->RegisterPropertyFloat("FrostWarningTemp", 3.0);

        // Dehumidifier Properties
        $this->RegisterPropertyString("ActuatorDehumidifierPlug", "0");
        $this->RegisterPropertyInteger("SensorDehumidifierPower", 0);
        $this->RegisterPropertyFloat("DehumidifierMaxHum", 60.0);
        $this->RegisterPropertyFloat("DehumidifierMinHum", 55.0);
        $this->RegisterPropertyFloat("DehumidifierPowerThreshold", 10.0);
        $this->RegisterPropertyInteger("DehumidifierPowerTime", 60);

        // Air Quality Properties
        $this->RegisterPropertyInteger("SensorRadonShortTerm", 0);
        $this->RegisterPropertyInteger("SensorRadonLongTerm", 0);
        $this->RegisterPropertyFloat("RadonWarningLevel", 300.0);
        $this->RegisterPropertyFloat("RadonAlarmLevel",  1000.0);
        
        $this->RegisterPropertyInteger("SensorCO2", 0);
        $this->RegisterPropertyFloat("CO2WarningLevel", 1000.0);
        $this->RegisterPropertyFloat("CO2AlarmLevel",   2000.0);
        
        $this->RegisterPropertyInteger("SensorVOC", 0);
        $this->RegisterPropertyFloat("VOCWarningLevel",  500.0);
        $this->RegisterPropertyFloat("VOCAlarmLevel",   1500.0);
        
        // Timers
        $this->RegisterTimer("PowerCheckTimer", 0, 'SCZ_CheckPowerThreshold($_IPS[\'TARGET\']);');
        $this->RegisterTimer("HeaterDefectTimer", 0, 'SCZ_TriggerHeaterDefectAlarm($_IPS[\'TARGET\']);');

        // Dynamic Variables based on Toggles (created in ApplyChanges)
    }

    public function Destroy(): void
    {
        parent::Destroy();
        $this->DR_Unregister();
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();
        
        $sensorID = $this->ReadPropertyInteger('SensorTempInside');
        if ($sensorID <= 0) {
            $this->SetStatus(104);
            return;
        $this->DR_Register('DevicesThermostat');
        }

        // Clean up references and messages
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $this->UnregisterAllMessages();

        $this->RegisterReference($this->ReadPropertyInteger('SmartInventoryID'));
        
        // Cache devices for registry
        $regId = (int)$this->ReadPropertyInteger('SmartInventoryID');
        $socketMap = [];
        if ($regId > 0 && @IPS_InstanceExists($regId)) {
            $sockets = json_decode(@SINV_GetByCategory($regId, 'actor:switch'), true) ?: [];
            foreach ($sockets as $s) {
                // To keep backward compatibility we only consider sockets, though actor:switch might have more.
                // Assuming any actor:switch can act as a socket here.
                $key = ($s['room'] ?? '') . '::' . ($s['name'] ?? 'Unbenannt');
                $socketMap[$key] = (int)($s['varID'] ?? 0);
                // SmartInventory doesn't return Power_VarID natively in the same entry yet
            }
        }
        $this->SetBuffer('SocketMapCache', json_encode($socketMap));
        
        $contactMap = [];
        if ($regId > 0 && @IPS_InstanceExists($regId)) {
            $contacts = json_decode(@SINV_GetByCategory($regId, 'contact'), true) ?: [];
            foreach ($contacts as $c) {
                $key = ($c['room'] ?? '') . '::' . ($c['name'] ?? 'Unbenannt');
                $contactMap[$key] = (int)($c['varID'] ?? 0);
            }
        }
        $this->SetBuffer('ContactMapCache', json_encode($contactMap));


        // 1. Core Registration
        $this->RegisterSensorReferenceAndMessage('SensorTempInside');
        $this->RegisterSensorReferenceAndMessage('SensorTempOutside');
        $this->RegisterSensorReferenceAndMessage('SensorHumInside');
        $this->RegisterSensorReferenceAndMessage('SensorHumOutside');
        $this->RegisterWindowReferences();
        $this->RegisterWindowMessages();
        
        $this->MaintainCoreVariables();

        // 2. Frost Protection
        if ($this->ReadPropertyBoolean("EnableFrostProtection")) {
            $plugKey = $this->ReadPropertyString('ActuatorHeaterPlug');
            $plugId = $this->resolveSocketId($plugKey);
            if ($plugId > 0 && IPS_VariableExists($plugId)) {
                $this->RegisterReference($plugId);
            }
            $powerKey = is_numeric($plugKey) ? '' : ($plugKey . '::Power');
            $powerId = $powerKey !== '' ? $this->resolveSocketId($powerKey) : $this->ReadPropertyInteger('SensorHeaterPower');
            if ($powerId <= 0) $powerId = $this->ReadPropertyInteger('SensorHeaterPower');
            if ($powerId > 0 && IPS_VariableExists($powerId)) {
                $this->RegisterReference($powerId);
                $this->RegisterMessage($powerId, VM_UPDATE);
            }
            $this->SetBuffer('ResolvedHeaterPower', (string)$powerId);
            $this->MaintainFrostVariables(true);
        } else {
            $this->MaintainFrostVariables(false);
        }

        // 4. Dehumidifier
        if ($this->ReadPropertyBoolean("EnableDehumidifier")) {
            $plugKey = $this->ReadPropertyString('ActuatorDehumidifierPlug');
            $plugId = $this->resolveSocketId($plugKey);
            if ($plugId > 0 && IPS_VariableExists($plugId)) {
                $this->RegisterReference($plugId);
            }
            $powerKey = is_numeric($plugKey) ? '' : ($plugKey . '::Power');
            $powerId = $powerKey !== '' ? $this->resolveSocketId($powerKey) : $this->ReadPropertyInteger('SensorDehumidifierPower');
            if ($powerId <= 0) $powerId = $this->ReadPropertyInteger('SensorDehumidifierPower');
            if ($powerId > 0 && IPS_VariableExists($powerId)) {
                $this->RegisterReference($powerId);
                $this->RegisterMessage($powerId, VM_UPDATE);
            }
            $this->SetBuffer('ResolvedDehumidifierPower', (string)$powerId);
            $this->MaintainDehumidifierVariables(true);
            
            $defaultMax = $this->ReadPropertyFloat("DehumidifierMaxHum");
            if ($defaultMax == 0.0) $defaultMax = 60.0;
            if ($this->GetValue("DehumidifierMaxHum") == 0.0) $this->SetValue("DehumidifierMaxHum", $defaultMax);
            
            $defaultMin = $this->ReadPropertyFloat("DehumidifierMinHum");
            if ($defaultMin == 0.0) $defaultMin = 55.0;
            if ($this->GetValue("DehumidifierMinHum") == 0.0) $this->SetValue("DehumidifierMinHum", $defaultMin);
        } else {
            $this->MaintainDehumidifierVariables(false);
        }
        
        // 5. Air Quality
        if ($this->ReadPropertyBoolean("EnableAirQuality")) {
            $this->RegisterSensorReferenceAndMessage('SensorRadonShortTerm');
            $this->RegisterSensorReferenceAndMessage('SensorRadonLongTerm');
            $this->RegisterSensorReferenceAndMessage('SensorCO2');
            $this->RegisterSensorReferenceAndMessage('SensorVOC');
            $this->MaintainAirQualityVariables(true);
        } else {
            $this->MaintainAirQualityVariables(false);
        }
        
        $this->SetStatus(102);
        $this->UpdateClimate();
    }

    private function RegisterSensorReferenceAndMessage(string $propName, bool $message = true): void {
        $id = $this->ReadPropertyInteger($propName);
        if ($id > 1 && @IPS_ObjectExists($id)) {
            $this->RegisterReference($id);
            if ($message && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
    }

    private function resolveSocketId(string|int $idStr): int
    {
        if (is_numeric($idStr)) {
            return (int)$idStr;
        }
        $map = json_decode($this->GetBuffer('SocketMapCache') ?: '[]', true) ?: [];
        return (int)($map[$idStr] ?? 0);
    }

    private function getRegistrySocketOptions(int $regId): array
    {
        $options = [['label' => '(Manuell per Variable)', 'value' => "0"]];
        if ($regId <= 0 || !@IPS_InstanceExists($regId)) return $options;
        $devices = json_decode(@SINV_GetByCategory($regId, 'actor:switch'), true) ?: [];
        $dynamicOptions = [];
        foreach ($devices as $dev) {
            $name = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? 'Unbenannt');
            $varId = (int)($dev['varID'] ?? 0);
            $deviceKey = ($dev['room'] ?? '') . '::' . ($dev['name'] ?? 'Unbenannt');
            if ($varId > 0) {
                $dynamicOptions[] = ['label' => $name, 'value' => $deviceKey];
            }
        }
        usort($dynamicOptions, fn($a, $b) => strcasecmp($a['label'], $b['label']));
        return array_merge($options, $dynamicOptions);
    }

    private function getRegistryContactOptions(int $regId): array
    {
        $options = [['label' => '(Manuell per Variable)', 'value' => "0"]];
        if ($regId <= 0 || !@IPS_InstanceExists($regId)) return $options;
        $devices = json_decode(@SINV_GetByCategory($regId, 'contact'), true) ?: [];
        $dynamicOptions = [];
        foreach ($devices as $dev) {
            $name = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? 'Unbenannt');
            $varId = (int)($dev['varID'] ?? 0);
            $deviceKey = ($dev['room'] ?? '') . '::' . ($dev['name'] ?? 'Unbenannt');
            if ($varId > 0) {
                $dynamicOptions[] = ['label' => $name, 'value' => $deviceKey];
            }
        }
        usort($dynamicOptions, fn($a, $b) => strcasecmp($a['label'], $b['label']));
        return array_merge($options, $dynamicOptions);
    }

    private function MaintainCoreVariables(): void {
        $this->RegisterVariableBoolean("VentilationRecommendation", "Lüften empfohlen!", [
            'PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'          => 'wind',
            'COLOR'         => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE'  => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW'  => true,
            'OPTIONS'       => json_encode([
                ['Value' => false, 'Caption' => 'Nein', 'IconValue' => 'wind', 'IconActive' => true, 'ColorActive' => false, 'ColorDisplay' => -1, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
                ['Value' => true, 'Caption' => 'Lüften empfohlen', 'IconValue' => 'wind', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0x0088FF, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x0088FF]
            ])
        ], 100);
        $this->RegisterVariableString("VentilationDetails", "Hinweis", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'wind'
        ], 101);
        $this->RegisterVariableFloat("DewPointInside", "Taupunkt Innen", ['PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,'ICON'=> 'droplet','SUFFIX'=> ' °C','DECIMALPLACES' => 1], 1);
        $this->RegisterVariableFloat("DewPointOutside", "Taupunkt Außen", ['PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,'ICON'=> 'droplet','SUFFIX'=> ' °C','DECIMALPLACES' => 1], 2);
        $this->RegisterVariableFloat("AbsHumInside", "Absolute Feuchte Innen", ['PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,'ICON'=> 'droplet','SUFFIX'=> ' g/m³','DECIMALPLACES' => 2], 3);
        $this->RegisterVariableFloat("AbsHumOutside", "Absolute Feuchte Außen", ['PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,'ICON'=> 'droplet','SUFFIX'=> ' g/m³','DECIMALPLACES' => 2], 4);
        $this->RegisterVariableFloat("CurrentHumidity", "Aktuelle Luftfeuchtigkeit", ['PRESENTATION'  => VARIABLE_PRESENTATION_VALUE_PRESENTATION,'ICON'=> 'droplet','SUFFIX'=> ' %','DECIMALPLACES' => 1], 5);
        
        $moldIntervals = [
            [
                'IntervalMinValue' => 0, 'IntervalMaxValue' => 49,
                'ConstantActive' => false, 'ConstantValue' => '',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => true, 'SuffixValue' => ' %',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'circle-check',
                'ColorActive' => true, 'ColorValue' => 0x00CC00,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 50, 'IntervalMaxValue' => 74,
                'ConstantActive' => false, 'ConstantValue' => '',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => true, 'SuffixValue' => ' %',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'triangle-exclamation',
                'ColorActive' => true, 'ColorValue' => 0xFFAA00,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 75, 'IntervalMaxValue' => 100,
                'ConstantActive' => false, 'ConstantValue' => '',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => true, 'SuffixValue' => ' %',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'bell',
                'ColorActive' => true, 'ColorValue' => 0xFF0000,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ]
        ];
        
        $this->RegisterVariableInteger("MoldRiskIndex", "Schimmelrisiko", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'virus',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => json_encode($moldIntervals)
        ], 6);

        $this->RegisterVariableBoolean("AlarmWindowClose", "Alarm: Fenster schließen", ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 203);
        $this->EnableAction("AlarmWindowClose");
    }

    private function MaintainFrostVariables(bool $active): void {
        if ($active) {
            $this->RegisterVariableBoolean("WinterMode", "Winterbetrieb", ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON' => 'gear'], 200);
            $this->EnableAction("WinterMode");
            
            $targetOptions = [];
            for ($i = 2; $i <= 15; $i++) $targetOptions[] = ['Value' => $i, 'Caption' => $i . ' °C', 'IconActive' => true, 'IconValue' => 'temperature-half', 'Color' => 0xFFFFFF];
            $this->RegisterVariableInteger("TargetFrostTemperature", "Zieltemperatur Frostschutz", ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($targetOptions)], 201);
            $this->EnableAction("TargetFrostTemperature");
            
            $heaterIntervals = json_encode([
                ['IntervalMinValue' => 0, 'IntervalMaxValue' => 0, 'ConstantActive' => true, 'ConstantValue' => 'Aus', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Sleep', 'ColorActive' => false, 'ColorValue' => -1, 'ContentColorActive' => false, 'ContentColorValue' => -1],
                ['IntervalMinValue' => 1, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Heizt', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'fire', 'ColorActive' => true, 'ColorValue' => 0xFF6600, 'ContentColorActive' => false, 'ContentColorValue' => -1],
                ['IntervalMinValue' => 2, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Fehler', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => false, 'ContentColorValue' => -1]
            ]);
            $this->RegisterVariableInteger("HeaterStatus", "Status Heizung", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'info', 'INTERVALS_ACTIVE' => true, 'INTERVALS' => $heaterIntervals], 15);
            $this->RegisterVariableBoolean("AlarmHeaterDefect", "Alarm: Heizung defekt", ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 202);
            $this->EnableAction("AlarmHeaterDefect");
            $this->RegisterVariableBoolean("AlarmFrost", "Alarm: Kritischer Frost", ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 204);
            $this->EnableAction("AlarmFrost");
            
            IPS_SetVariableCustomProfile($this->GetIDForIdent('HeaterStatus'), '');
        } else {
            @$this->UnregisterVariable("WinterMode");
            @$this->UnregisterVariable("TargetFrostTemperature");
            @$this->UnregisterVariable("HeaterStatus");
            @$this->UnregisterVariable("AlarmHeaterDefect");
            @$this->UnregisterVariable("AlarmFrost");
            $this->StopTimer("HeaterDefectTimer");
        }
    }

    private function MaintainDehumidifierVariables(bool $active): void {
        if ($active) {
            $sliderPresentation = ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'ICON' => 'droplet', 'SUFFIX' => ' %', 'MIN' => 30, 'MAX' => 90, 'STEP' => 1, 'DECIMALPLACES' => 1];
            $this->RegisterVariableFloat("DehumidifierMaxHum", "Einschaltschwelle (Max %)", $sliderPresentation, 210);
            $this->EnableAction("DehumidifierMaxHum");
            $this->RegisterVariableFloat("DehumidifierMinHum", "Ausschaltschwelle (Min %)", $sliderPresentation, 211);
            $this->EnableAction("DehumidifierMinHum");
            
            $dehum = [
                ['IntervalMinValue' => 0, 'IntervalMaxValue' => 0, 'ConstantActive' => true, 'ConstantValue' => 'Aus', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Sleep', 'ColorActive' => false, 'ColorValue' => -1, 'ContentColorActive' => false, 'ContentColorValue' => -1],
                ['IntervalMinValue' => 1, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Aktiv', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'droplet', 'ColorActive' => true, 'ColorValue' => 0x0088FF, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
                ['IntervalMinValue' => 2, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Fenster offen', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'window-maximize', 'ColorActive' => true, 'ColorValue' => 0xFFCC00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
                ['IntervalMinValue' => 3, 'IntervalMaxValue' => 3, 'ConstantActive' => true, 'ConstantValue' => 'Tank voll!', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF]
            ];
            $this->RegisterVariableInteger("DehumidifierStatus", "Status Entfeuchter", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'droplet', 'INTERVALS_ACTIVE' => true, 'INTERVALS' => json_encode($dehum)], 13);
            $this->RegisterVariableBoolean("AlarmTankFull", "Alarm: Wassertank voll", ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 212);
            $this->EnableAction("AlarmTankFull");
            
            IPS_SetVariableCustomProfile($this->GetIDForIdent('DehumidifierStatus'), '');
        } else {
            @$this->UnregisterVariable("DehumidifierMaxHum");
            @$this->UnregisterVariable("DehumidifierMinHum");
            @$this->UnregisterVariable("DehumidifierStatus");
            @$this->UnregisterVariable("AlarmTankFull");
            $this->StopTimer("PowerCheckTimer");
        }
    }

    private function MaintainAirQualityVariables(bool $active): void {
        if ($active) {
            $this->RegisterVariableFloat("RadonShortTerm", "Radon Kurzzeit", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'radiation', 'SUFFIX' => ' Bq/m³', 'DECIMALPLACES' => 0], 6);
            $this->RegisterVariableFloat("RadonLongTerm", "Radon Langzeit", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'radiation', 'SUFFIX' => ' Bq/m³', 'DECIMALPLACES' => 0], 7);
            
            $radon = [
                ['IntervalMinValue' => 0, 'IntervalMaxValue' => 0, 'ConstantActive' => true, 'ConstantValue' => 'Gut', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'circle-check', 'ColorActive' => true, 'ColorValue' => 0x00CC00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
                ['IntervalMinValue' => 1, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Mittel', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'ColorActive' => true, 'ColorValue' => 0xFFA500, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
                ['IntervalMinValue' => 2, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Hoch', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'bell', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
                ['IntervalMinValue' => 3, 'IntervalMaxValue' => 3, 'ConstantActive' => true, 'ConstantValue' => 'Sehr hoch', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'bell', 'ColorActive' => true, 'ColorValue' => 0xCC0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF]
            ];
            $this->RegisterVariableInteger("RadonStatus", "Radon Status", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'radiation', 'INTERVALS_ACTIVE' => true, 'INTERVALS' => json_encode($radon)], 8);
            $this->RegisterVariableString("RadonRecommendation", "Radon Empfehlung", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'radiation'], 102);
            
            $this->RegisterVariableFloat("CO2Value", "CO₂-Konzentration", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'smog', 'SUFFIX' => ' ppm', 'DECIMALPLACES' => 0], 9);
            $co2 = [
                ['IntervalMinValue' => 0, 'IntervalMaxValue' => 0, 'ConstantActive' => true, 'ConstantValue' => 'Gut', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'circle-check', 'ColorActive' => true, 'ColorValue' => 0x00CC00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
                ['IntervalMinValue' => 1, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Mittel', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'ColorActive' => true, 'ColorValue' => 0xFFA500, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
                ['IntervalMinValue' => 2, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Hoch', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'bell', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF]
            ];
            $this->RegisterVariableInteger("CO2Status", "CO₂ Status", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'smog', 'INTERVALS_ACTIVE' => true, 'INTERVALS' => json_encode($co2)], 10);
            $this->RegisterVariableString("CO2Recommendation", "CO₂ Empfehlung", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'smog'], 103);
            
            $this->RegisterVariableFloat("VOCValue", "VOC-Konzentration", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'wind', 'SUFFIX' => ' µg/m³', 'DECIMALPLACES' => 0], 11);
            $this->RegisterVariableInteger("VOCStatus", "VOC Status", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'smog', 'INTERVALS_ACTIVE' => true, 'INTERVALS' => json_encode($co2)], 12);
            $this->RegisterVariableString("VOCRecommendation", "VOC Empfehlung", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'wind'], 104);
            
            IPS_SetVariableCustomProfile($this->GetIDForIdent('RadonStatus'), '');
            IPS_SetVariableCustomProfile($this->GetIDForIdent('CO2Status'), '');
            IPS_SetVariableCustomProfile($this->GetIDForIdent('VOCStatus'), '');
        } else {
            @$this->UnregisterVariable("RadonShortTerm");
            @$this->UnregisterVariable("RadonLongTerm");
            @$this->UnregisterVariable("RadonStatus");
            @$this->UnregisterVariable("RadonRecommendation");
            @$this->UnregisterVariable("CO2Value");
            @$this->UnregisterVariable("CO2Status");
            @$this->UnregisterVariable("CO2Recommendation");
            @$this->UnregisterVariable("VOCValue");
            @$this->UnregisterVariable("VOCStatus");
            @$this->UnregisterVariable("VOCRecommendation");
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void{
        switch ($Ident) {
            case "AlarmTankFull":
            case "AlarmWindowClose":
            case "AlarmHeaterDefect":
            case "AlarmFrost":
                if ($Value == false) {
                    $this->SetValue($Ident, false);
                    $this->UpdateClimate();
                }
                break;
            case "DehumidifierMaxHum":
            case "DehumidifierMinHum":
            case "WinterMode":
            case "TargetFrostTemperature":
                $this->SetValue($Ident, $Value);
                $this->UpdateClimate();
                break;
            default:
                throw new Exception("Invalid Ident");
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void{
        $powerIdDehum = (int)$this->GetBuffer('ResolvedDehumidifierPower');
        if ($powerIdDehum <= 0) $powerIdDehum = $this->ReadPropertyInteger("SensorDehumidifierPower");
        $powerIdHeat  = (int)$this->GetBuffer('ResolvedHeaterPower');
        if ($powerIdHeat <= 0) $powerIdHeat = $this->ReadPropertyInteger("SensorHeaterPower");
        $radonShortId = $this->ReadPropertyInteger("SensorRadonShortTerm");
        $radonLongId  = $this->ReadPropertyInteger("SensorRadonLongTerm");
        $co2Id        = $this->ReadPropertyInteger("SensorCO2");
        $vocId        = $this->ReadPropertyInteger("SensorVOC");
        
        if ($this->ReadPropertyBoolean("EnableDehumidifier") && $SenderID == $powerIdDehum) {
            $this->HandleDehumidifierPowerUpdate($Data[0]);
        } elseif ($this->ReadPropertyBoolean("EnableFrostProtection") && $SenderID == $powerIdHeat) {
            $this->HandleHeaterPowerUpdate($Data[0]);
        } elseif ($this->ReadPropertyBoolean("EnableAirQuality") && $radonShortId > 0 && $SenderID == $radonShortId) {
            $this->SetValueIfChanged("RadonShortTerm", (float) $Data[0]);
        } elseif ($this->ReadPropertyBoolean("EnableAirQuality") && $radonLongId > 0 && $SenderID == $radonLongId) {
            $this->SetValueIfChanged("RadonLongTerm", (float) $Data[0]);
        } elseif ($this->ReadPropertyBoolean("EnableAirQuality") && $co2Id > 0 && $SenderID == $co2Id) {
            $this->SetValueIfChanged("CO2Value", (float) $Data[0]);
        } elseif ($this->ReadPropertyBoolean("EnableAirQuality") && $vocId > 0 && $SenderID == $vocId) {
            $this->SetValueIfChanged("VOCValue", (float) $Data[0]);
        } else {
            $this->UpdateClimate();
        }
    }

    public function UpdateClimate(): void
    {
        $tempOut = $this->GetPropertyVarValue("SensorTempOutside");
        $humOut  = $this->GetPropertyVarValue("SensorHumOutside");
        $tempIn  = $this->GetPropertyVarValue("SensorTempInside");
        $humIn   = $this->GetPropertyVarValue("SensorHumInside");
        
        $windowOpen = $this->AnyWindowOpen();
        
        if ($tempOut !== null && $humOut !== null && $tempIn !== null && $humIn !== null) {
            $absOut = $this->CalculateAbsoluteHumidity($tempOut, $humOut);
            $dpOut  = $this->CalculateDewPoint($tempOut, $humOut);
            $absIn  = $this->CalculateAbsoluteHumidity($tempIn, $humIn);
            $dpIn   = $this->CalculateDewPoint($tempIn, $humIn);
            
            $this->SetValue("AbsHumOutside", $absOut);
            $this->SetValue("DewPointOutside", $dpOut);
            $this->SetValue("AbsHumInside", $absIn);
            $this->SetValue("DewPointInside", $dpIn);
            $this->SetValue("CurrentHumidity", $humIn);
            
            // Mold Risk Calculation
            $moldThreshold = $this->ReadPropertyFloat("MoldWarningThreshold");
            $risk = ($humIn - ($moldThreshold - 10)) * 5;
            $risk = max(0, min(100, $risk));
            $this->SetValue("MoldRiskIndex", (int)$risk);

            // Ventilation logic
            $threshold   = $this->ReadPropertyFloat("VentilationThreshold");
            $closeMargin = $this->ReadPropertyFloat("VentilationCloseMargin");
            $enableCooling = $this->ReadPropertyBoolean("EnableFreeCooling");
            $targetCooling = $this->ReadPropertyFloat("TargetCoolingTemp");
            
            $recommendation = false;
            $closeAlarm     = false;
            $details        = "Keine Aktion erforderlich.";
            
            $dryerOutside = ($absOut <= ($absIn - $threshold));
            $colderOutside = ($tempOut < $tempIn);
            
            $forceBadAir = 0;
            if ($this->ReadPropertyBoolean("EnableAirQuality") && $this->ReadPropertyBoolean("ForceVentilationOnBadAir")) {
                $r = @$this->GetValue("RadonStatus");
                $c = @$this->GetValue("CO2Status");
                $v = @$this->GetValue("VOCStatus");
                if ($r >= 2 || $c >= 2 || $v >= 2) {
                    $forceBadAir = 2;
                } elseif ($r >= 1 || $c >= 1 || $v >= 1) {
                    $forceBadAir = 1;
                }
            }
            
            if (!$windowOpen) {
                // Kann man Kühlen UND ist es draußen nicht feuchter? (Feuchtigkeit hat Vorrang!)
                $canCool = ($enableCooling && ($tempIn > $targetCooling) && $colderOutside && ($absOut <= $absIn));
                
                if ($forceBadAir === 2) {
                    $recommendation = true;
                    $details = "Lüften DRINGEND empfohlen! Luftqualität kritisch (Radon/CO2/VOC) - Außenklima ignoriert.";
                } elseif ($forceBadAir === 1 && $dryerOutside) {
                     $recommendation = true;
                     $details = "Lüften empfohlen! Luftqualität verschlechtert und Außen ist trockener.";
                } elseif ($dryerOutside && $canCool) {
                    $recommendation = true;
                    $details = sprintf("Lüften empfohlen! Kühlen & Trocknen möglich (Außen: %.1f°C, %.2f g/m³)", $tempOut, $absOut);
                } elseif ($dryerOutside) {
                    $recommendation = true;
                    $details = sprintf("Lüften empfohlen! Außen ist trockener (Außen: %.2f g/m³, Innen: %.2f g/m³)", $absOut, $absIn);
                } elseif ($canCool) {
                    $recommendation = true;
                    $details = sprintf("Lüften empfohlen zum Kühlen (Außen: %.1f°C, Innen: %.1f°C).", $tempOut, $tempIn);
                } else {
                    if ($forceBadAir === 1) {
                        $details = sprintf("Luftqualität ist schlechter, aber Außenfeuchte/Temp aktuell nicht ideal zum Lüften.");
                    } elseif ($enableCooling && $colderOutside && ($absOut > $absIn)) {
                        $details = sprintf("Nicht kühlen: Draußen ist es feuchter (Außen: %.2f g/m³, Innen: %.2f g/m³)", $absOut, $absIn);
                    } else {
                        $details = sprintf("Lüften lohnt nicht (Außen: %.2f g/m³, Innen: %.2f g/m³)", $absOut, $absIn);
                    }
                }
                $this->SetValueIfChanged("AlarmWindowClose", false);
            } else {
                $alarmReason = "";
                // Alarm: Feuchtigkeit
                if ($absOut >= ($absIn - $closeMargin)) {
                    $closeAlarm = true;
                    if ($absOut >= $absIn) {
                        $alarmReason = sprintf("Fenster SCHLIESSEN! Außen wird es feuchter (Außen: %.2f g/m³, Innen: %.2f g/m³)", $absOut, $absIn);
                    } else {
                        $alarmReason = sprintf("Achtung: Fenster bald schließen! Außenfeuchte nähert sich der Innenfeuchte.");
                    }
                }
                
                // Alarm: Hitze (falls man lüftet, aber es draußen wärmer wird als drinnen)
                if ($enableCooling && ($tempOut >= $tempIn)) {
                    $closeAlarm = true;
                    $alarmReason = sprintf("Fenster SCHLIESSEN! Außen wird es wärmer (Außen: %.1f°C, Innen: %.1f°C)", $tempOut, $tempIn);
                }

                if ($forceBadAir === 2) {
                    $closeAlarm = false;
                    $details = "Lüftung fortsetzen! Luftqualität ist kritisch.";
                    $this->SetValueIfChanged("AlarmWindowClose", false);
                } elseif ($closeAlarm) {
                    $details = $alarmReason;
                    $this->SetValueIfChanged("AlarmWindowClose", true);
                } else {
                    $details = sprintf("Lüften wirkt weiterhin positiv (Trocknen/Kühlen).");
                    $this->SetValueIfChanged("AlarmWindowClose", false);
                }
            }
            
            $this->SetValueIfChanged("VentilationRecommendation", $recommendation);
            $this->SetValueIfChanged("VentilationDetails", $details);
        }
        
        if ($this->ReadPropertyBoolean("EnableDehumidifier") && $humIn !== null) {
            $this->ControlDehumidifier($humIn, $windowOpen);
        }
        if ($this->ReadPropertyBoolean("EnableFrostProtection") && $tempIn !== null) {
            $this->ControlFrostProtection($tempIn, $windowOpen);
        }
        if ($this->ReadPropertyBoolean("EnableAirQuality")) {
            $this->UpdateAirQuality();
        }
    }
    
    private function ControlFrostProtection(float $tempIn, bool $windowOpen): void {
        $winterMode = $this->GetValue("WinterMode");
        if (!$winterMode) {
            $this->SetHeaterPlug(false, 0);
            return;
        }
        
        if ($windowOpen) {
            $this->SetHeaterPlug(false, 0);
            return;
        }
        
        $targetTemp = $this->GetValue("TargetFrostTemperature");
        $hysteresis = $this->ReadPropertyFloat("Hysteresis");
        $warningTemp = $this->ReadPropertyFloat("FrostWarningTemp");
        
        $plugId = $this->resolveSocketId($this->ReadPropertyString("ActuatorHeaterPlug"));
        $isHeating = ($plugId > 0 && IPS_VariableExists($plugId)) ? GetValue($plugId) : false;
        $newHeatingState = $isHeating;
        
        if ($tempIn <= ($targetTemp - $hysteresis)) {
            $newHeatingState = true;
        } elseif ($tempIn >= ($targetTemp + $hysteresis)) {
            $newHeatingState = false;
        }
        
        if ($tempIn <= $warningTemp) {
            $this->SetValueIfChanged("AlarmFrost", true);
            $this->SLogWarning("KRITISCHER FROST!", "Temperatur ist auf {$tempIn}°C gefallen (Warnschwelle: {$warningTemp}°C)");
        }
        
        $defectAlarm = $this->GetValue("AlarmHeaterDefect");
        $statusText = $defectAlarm ? 2 : ($newHeatingState ? 1 : 0);
        $this->SetHeaterPlug($newHeatingState, $statusText);
    }
    
    private function SetHeaterPlug(bool $state, int $statusText): void {
        $plugId = $this->resolveSocketId($this->ReadPropertyString("ActuatorHeaterPlug"));
        if ($plugId > 0 && IPS_VariableExists($plugId)) {
            if (GetValue($plugId) != $state) {
                @RequestAction($plugId, $state);
            }
        }
        $this->SetValueIfChanged("HeaterStatus", $statusText);
    }
    
    private function HandleHeaterPowerUpdate(float $power): void {
        $plugId = $this->resolveSocketId($this->ReadPropertyString("ActuatorHeaterPlug"));
        if ($plugId == 0 || !IPS_VariableExists($plugId)) return;
        
        $threshold = $this->ReadPropertyFloat("HeaterPowerThreshold");
        $defectTime = $this->ReadPropertyInteger("HeaterDefectTime");
        
        if (GetValue($plugId)) {
            if ($power < $threshold) {
                if ($this->GetTimerInterval("HeaterDefectTimer") == 0 && !$this->GetValue("AlarmHeaterDefect")) {
                    $this->SetTimerInterval("HeaterDefectTimer", $defectTime * 1000);
                }
            } else {
                $this->StopTimer("HeaterDefectTimer");
                if ($this->GetValue("AlarmHeaterDefect")) {
                    $this->SetValue("AlarmHeaterDefect", false);
                    $this->UpdateClimate();
                }
            }
        } else {
            $this->StopTimer("HeaterDefectTimer");
        }
    }
    
    public function CheckPowerThreshold(): void {
        $this->StopTimer("PowerCheckTimer");
        $this->SetValue("AlarmTankFull", true);
        $this->UpdateClimate();
    }
    
    public function TriggerHeaterDefectAlarm(): void {
        $this->StopTimer("HeaterDefectTimer");
        $this->SetValue("AlarmHeaterDefect", true);
        $this->UpdateClimate();
        $this->SLogWarning("Heizung defekt!", "Die Heizung ist eingeschaltet, zieht aber keinen Strom!");
    }

    private function ControlDehumidifier(float $humIn, bool $windowOpen): void {
        $plugId = $this->resolveSocketId($this->ReadPropertyString("ActuatorDehumidifierPlug"));
        
        $maxHum   = $this->GetValue("DehumidifierMaxHum");
        $minHum   = $this->GetValue("DehumidifierMinHum");
        $tankFull = $this->GetValue("AlarmTankFull");
        
        $plugStatus = ($plugId > 0 && IPS_VariableExists($plugId)) ? GetValue($plugId) : false;
        $newStatus  = $plugStatus;
        $statusText = 0; 
        
        if ($windowOpen) {
            $newStatus  = false;
            $statusText = 2;
        } else {
            if ($humIn >= $maxHum) $newStatus = true;
            elseif ($humIn <= $minHum) $newStatus = false;
            $statusText = $tankFull ? 3 : ($newStatus ? 1 : 0);
        }
        
        if ($plugId > 0 && IPS_VariableExists($plugId) && $plugStatus != $newStatus) {
            @RequestAction($plugId, $newStatus);
        }
        
        $this->SetValueIfChanged("DehumidifierStatus", $statusText);
        $this->SetValueIfChanged("AlarmTankFull", $tankFull);
    }
    
    private function HandleDehumidifierPowerUpdate(float $currentPower): void {
        $plugId = $this->resolveSocketId($this->ReadPropertyString("ActuatorDehumidifierPlug"));
        if ($plugId == 0 || !IPS_VariableExists($plugId)) return;
        
        $threshold  = $this->ReadPropertyFloat("DehumidifierPowerThreshold");
        $timeLimit  = $this->ReadPropertyInteger("DehumidifierPowerTime");
        
        if (GetValue($plugId)) {
            if ($currentPower < $threshold) {
                if ($this->GetTimerInterval("PowerCheckTimer") == 0 && !$this->GetValue("AlarmTankFull")) {
                    $this->SetTimerInterval("PowerCheckTimer", $timeLimit * 1000);
                }
            } else {
                $this->StopTimer("PowerCheckTimer");
                if ($this->GetValue("AlarmTankFull")) {
                    $this->SetValue("AlarmTankFull", false);
                    $this->UpdateClimate();
                }
            }
        } else {
            $this->StopTimer("PowerCheckTimer");
        }
    }
    
    private function UpdateAirQuality(): void {
        $radonLong = $this->GetValue("RadonLongTerm");
        $radonWarn = $this->ReadPropertyFloat("RadonWarningLevel");
        $radonAlarm = $this->ReadPropertyFloat("RadonAlarmLevel");
        
        $rStatus = 0;
        $rRec = "Alles im grünen Bereich.";
        if ($radonLong >= $radonAlarm) { 
            $rStatus = 3; 
            $rRec = "Dringend lüften! Alarm-Grenzwert überschritten."; 
        } elseif ($radonLong >= $radonWarn + ($radonAlarm - $radonWarn) / 2) { 
            $rStatus = 2; 
            $rRec = "Lüften dringend empfohlen."; 
        } elseif ($radonLong >= $radonWarn) { 
            $rStatus = 1; 
            $rRec = "Lüften empfohlen zur Radon-Reduktion."; 
        }
        
        $this->SetValueIfChanged("RadonStatus", $rStatus);
        $this->SetValueIfChanged("RadonRecommendation", $rRec);
        
        $co2 = $this->GetValue("CO2Value");
        $co2Warn = $this->ReadPropertyFloat("CO2WarningLevel");
        $co2Alarm = $this->ReadPropertyFloat("CO2AlarmLevel");
        
        $cStatus = 0; 
        $cRec = "Gute Luftqualität.";
        if ($co2 >= $co2Alarm) { 
            $cStatus = 2; 
            $cRec = "Dringend lüften! Sehr schlechte Luft."; 
        } elseif ($co2 >= $co2Warn) { 
            $cStatus = 1; 
            $cRec = "Lüften empfohlen (CO2-Wert erhöht)."; 
        }
        
        $this->SetValueIfChanged("CO2Status", $cStatus);
        $this->SetValueIfChanged("CO2Recommendation", $cRec);
        
        $voc = $this->GetValue("VOCValue");
        $vocWarn = $this->ReadPropertyFloat("VOCWarningLevel");
        $vocAlarm = $this->ReadPropertyFloat("VOCAlarmLevel");
        
        $vStatus = 0; 
        $vRec = "Gute Luftqualität.";
        if ($voc >= $vocAlarm) { 
            $vStatus = 2; 
            $vRec = "Dringend lüften! VOC-Belastung hoch."; 
        } elseif ($voc >= $vocWarn) { 
            $vStatus = 1; 
            $vRec = "Lüften empfohlen (VOC-Wert erhöht)."; 
        }
        
        $this->SetValueIfChanged("VOCStatus", $vStatus);
        $this->SetValueIfChanged("VOCRecommendation", $vRec);
    }

    public function GetConfigurationForm(): string {
        $regId = (int)$this->ReadPropertyInteger('SmartInventoryID');
        
        $elements = [];
        
        $elements[] = [
            'type' => 'ExpansionPanel',
            'caption' => 'Feature Toggles',
            'items' => [
                ['type' => 'CheckBox', 'name' => 'EnableFrostProtection', 'caption' => 'Frostschutz (Heizlüfter) aktivieren'],
                ['type' => 'CheckBox', 'name' => 'EnableDehumidifier', 'caption' => 'Luftentfeuchter aktivieren'],
                ['type' => 'CheckBox', 'name' => 'EnableAirQuality', 'caption' => 'Spezialsensoren (Radon, CO2, VOC) aktivieren']
            ]
        ];
        
        $elements[] = [
            'type' => 'ExpansionPanel',
            'caption' => 'Geräte-Quelle',
            'items' => [
                ['type' => 'SelectInstance', 'name' => 'RegistryID', 'caption' => 'SymconSmartTools Device Registry']
            ]
        ];
        
        $elements[] = [
            'type' => 'ExpansionPanel',
            'caption' => 'Basis Sensoren',
            'items' => [
                [
                    'type' => 'RowLayout',
                    'items' => [
                        ['type' => 'SelectVariable', 'name' => 'SensorTempOutside', 'caption' => 'Temperatur Außen'],
                        ['type' => 'SelectVariable', 'name' => 'SensorHumOutside', 'caption' => 'Feuchtigkeit Außen']
                    ]
                ],
                [
                    'type' => 'RowLayout',
                    'items' => [
                        ['type' => 'SelectVariable', 'name' => 'SensorTempInside', 'caption' => 'Temperatur Innen'],
                        ['type' => 'SelectVariable', 'name' => 'SensorHumInside', 'caption' => 'Feuchtigkeit Innen']
                    ]
                ]
            ]
        ];
        
        $frostItems = [];
        $sockets = $this->getRegistrySocketOptions($regId);
        if (count($sockets) > 1) {
            $frostItems[] = ['type' => 'Select', 'name' => 'ActuatorHeaterPlug', 'caption' => 'Schaltsteckdose Heizlüfter', 'options' => $sockets];
            $frostItems[] = ['type' => 'Label', 'caption' => 'Leistungsmessung wird automatisch aus Registry ermittelt.'];
        } else {
            $frostItems[] = ['type' => 'SelectVariable', 'name' => 'ActuatorHeaterPlug', 'caption' => 'Schaltsteckdose Heizlüfter'];
            $frostItems[] = ['type' => 'SelectVariable', 'name' => 'SensorHeaterPower', 'caption' => 'Leistungsmessung (Watt)'];
        }
        $frostItems[] = ['type' => 'NumberSpinner', 'name' => 'HeaterPowerThreshold', 'caption' => 'Leistungsschwelle (Watt)', 'digits' => 1];
        $frostItems[] = ['type' => 'NumberSpinner', 'name' => 'HeaterDefectTime', 'caption' => 'Defekt-Timer (Sek)'];
        $frostItems[] = ['type' => 'NumberSpinner', 'name' => 'Hysteresis', 'caption' => 'Schalt-Hysterese (°C)', 'digits' => 1];
        $frostItems[] = ['type' => 'NumberSpinner', 'name' => 'FrostWarningTemp', 'caption' => 'Kritische Frostwarnung ab (°C)', 'digits' => 1];
        
        $elements[] = [
            'type' => 'ExpansionPanel',
            'caption' => 'Frostschutz (Heizlüfter)',
            'visible' => 'EnableFrostProtection',
            'items' => $frostItems
        ];
        
        $dehumItems = [];
        if (count($sockets) > 1) {
            $dehumItems[] = ['type' => 'Select', 'name' => 'ActuatorDehumidifierPlug', 'caption' => 'Schaltsteckdose Entfeuchter', 'options' => $sockets];
            $dehumItems[] = ['type' => 'Label', 'caption' => 'Leistungsmessung wird automatisch aus Registry ermittelt.'];
        } else {
            $dehumItems[] = ['type' => 'SelectVariable', 'name' => 'ActuatorDehumidifierPlug', 'caption' => 'Schaltsteckdose Entfeuchter'];
            $dehumItems[] = ['type' => 'SelectVariable', 'name' => 'SensorDehumidifierPower', 'caption' => 'Leistungsmessung (Watt)'];
        }
        $dehumItems[] = ['type' => 'NumberSpinner', 'name' => 'DehumidifierPowerThreshold', 'caption' => 'Leistungsschwelle (Watt)', 'digits' => 1];
        $dehumItems[] = ['type' => 'NumberSpinner', 'name' => 'DehumidifierPowerTime', 'caption' => 'Tank Voll-Timer (Sek)'];
        
        $elements[] = [
            'type' => 'ExpansionPanel',
            'caption' => 'Luftentfeuchter',
            'visible' => 'EnableDehumidifier',
            'items' => $dehumItems
        ];
        
        $elements[] = [
            'type' => 'ExpansionPanel',
            'caption' => 'Luftqualität',
            'visible' => 'EnableAirQuality',
            'items' => [
                ['type' => 'CheckBox', 'name' => 'ForceVentilationOnBadAir', 'caption' => 'Schlechte Luft erzwingt Lüftungsempfehlung (ignoriert Außenfeuchte)'],
                ['type' => 'SelectVariable', 'name' => 'SensorRadonShortTerm', 'caption' => 'Radon Kurzzeit (Bq/m³)'],
                ['type' => 'SelectVariable', 'name' => 'SensorRadonLongTerm', 'caption' => 'Radon Langzeit (Bq/m³)'],
                ['type' => 'SelectVariable', 'name' => 'SensorCO2', 'caption' => 'CO2 (ppm)'],
                ['type' => 'SelectVariable', 'name' => 'SensorVOC', 'caption' => 'VOC (µg/m³)']
            ]
        ];
        
        $contacts = $this->getRegistryContactSensorOptions($regId);
        $elements[] = [
            'type' => 'List',
            'name' => 'SensorWindows',
            'caption' => 'Fenster-/Türkontakte',
            'add' => true,
            'delete' => true,
            'columns' => [
                [
                    'caption' => 'Sensor',
                    'name' => 'VariableID',
                    'width' => 'auto',
                    'add' => count($contacts) > 1 ? $contacts[0]['value'] : 0,
                    'edit' => count($contacts) > 1 ? ['type' => 'Select', 'options' => $contacts] : ['type' => 'SelectVariable']
                ],
                [
                    'caption' => 'Wert für Geschlossen',
                    'name' => 'ClosedValue',
                    'width' => '150px',
                    'add' => false,
                    'edit' => ['type' => 'ValidationTextBox']
                ]
            ]
        ];
        
        return json_encode([
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Sensor nicht konfiguriert']
            ],
            'elements' => $elements
        ]);
    }
}
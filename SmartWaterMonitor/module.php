<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SmartWaterMonitor extends IPSModuleStrict
{
    use DeviceAvailability_Trait;
    use SmartLog_Trait;

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();
        $this->DA_RegisterAvailability(900);

        // Properties
        $this->RegisterPropertyString('MQTTBaseTopic', 'watermeter');
        $this->RegisterPropertyInteger('MaxContinuousFlowMinutes', 45); // 45 minutes default
        $this->RegisterPropertyInteger('IrrigationVariableID', 0);
        $this->RegisterPropertyFloat('VolumeMultiplier', 1.0);
        $this->RegisterPropertyInteger('RegistryID', 0);
        
        $this->SetReceiveDataFilter('.*' . preg_quote($this->ReadPropertyString('MQTTBaseTopic')) . '.*');

        // Variables
        $onlineOptions = json_encode([
            ['Value' => false, 'Caption' => 'Offline', 'IconValue' => 'network-wired', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000],
            ['Value' => true, 'Caption' => 'Online', 'IconValue' => 'network-wired', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00]
        ]);
        $this->RegisterVariableBoolean("Online", "Gerätestatus", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'network-wired',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $onlineOptions
        ], 900);
        
        $leakOptions = json_encode([
            ['Value' => false, 'Caption' => 'OK', 'IconValue' => 'droplet', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00],
            ['Value' => true, 'Caption' => 'Leck erkannt!', 'IconValue' => 'droplet', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000]
        ]);
        $this->RegisterVariableBoolean("LeakAlarm", "Leckage-Alarm", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'droplet',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $leakOptions
        ], 101);
        
        $runningOptions = json_encode([
            ['Value' => false, 'Caption' => 'Kein Fluss', 'IconValue' => 'droplet', 'IconActive' => false,
             'ColorActive' => false, 'ColorDisplay' => -1, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
            ['Value' => true, 'Caption' => 'Laeuft', 'IconValue' => 'droplet', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x0088FF, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x0088FF]
        ]);
        $this->RegisterVariableBoolean("WaterRunning", "Wasser fließt", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'faucet-drip',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $runningOptions
        ], 1);
        $this->RegisterVariableFloat("FlowRate", "Aktueller Durchfluss", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' l/min',
            'ICON'         => 'gauge-high'
        ], 2);
        $this->RegisterVariableFloat("TotalConsumption", "Gesamtverbrauch", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' m³',
            'ICON'         => 'faucet-drip'
        ], 3);
        $this->RegisterVariableFloat("TotalConsumptionLiter", "Gesamtverbrauch (Liter)", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' l',
            'ICON'         => 'faucet-drip'
        ], 4);

        $this->RegisterVariableFloat("ConsumptionToday", "Verbrauch Heute", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' m³',
            'ICON'         => 'faucet-drip',
            'DIGITS'       => 3
        ], 10);
        $this->RegisterVariableFloat("CostToday", "Kosten Heute", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' €',
            'ICON'         => 'euro-sign',
            'DIGITS'       => 2
        ], 11);
        
                $this->RegisterVariableFloat("ConsumptionMonth", "Verbrauch dieser Monat", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' m³',
            'ICON'         => 'faucet-drip',
            'DIGITS'       => 3
        ], 12);
        $this->RegisterVariableFloat("CostMonth", "Kosten dieser Monat", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' €',
            'ICON'         => 'euro-sign',
            'DIGITS'       => 2
        ], 13);

        $wateringOptions = json_encode([
            [
                'Value' => false, 'Caption' => 'Nein',
                'IconActive' => false, 'IconValue' => '',
                'ColorActive' => false, 'ColorDisplay' => -1, 'ColorValue' => -1,
                'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1
            ],
            [
                'Value' => true, 'Caption' => 'Ja (Alarm gesperrt)',
                'IconActive' => true, 'IconValue' => 'droplet',
                'ColorActive' => true, 'ColorDisplay' => 0x0088FF, 'ColorValue' => 0x0088FF,
                'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1
            ]
        ]);
        $this->RegisterVariableBoolean("IrrigationActive", "Bewässerung aktiv", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'sprinkler',
            'OPTIONS' => $wateringOptions
        ], 14);

        // Variables are read-only

        // Attributes (internal state)
        $this->RegisterAttributeFloat('LastRawTotal', 0.0);
        $this->RegisterAttributeFloat('StartOfDayTotal', 0.0);
        $this->RegisterAttributeFloat('StartOfMonthTotal', 0.0);
        $this->RegisterAttributeString('LastUpdateDay', '');
        $this->RegisterAttributeString('LastUpdateMonth', '');

        // Timer for Leak Detection
        $this->RegisterTimer('LeakTimer', 0, 'WATER_LeakTimerTriggered($_IPS[\'TARGET\']);');
        $this->RegisterTimer('CostUpdateTimer', 15 * 60 * 1000, 'WATER_UpdateCosts($_IPS[\'TARGET\']);');
    }

    public function Destroy(): void
    {
        parent::Destroy();

    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();
        $this->DA_SetAvailable(true);
        $topic = $this->ReadPropertyString('MQTTBaseTopic');
        if ($topic == '') {
            $this->SetStatus(104);
            return;

        }

        // Register MQTT Filter
        $this->SetReceiveDataFilter('.*' . preg_quote($topic) . '.*');

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $irriVar = $this->ReadPropertyInteger('IrrigationVariableID');
        if ($irriVar > 0 && @IPS_VariableExists($irriVar)) {
            $this->RegisterMessage($irriVar, VM_UPDATE);
            $this->SetValueIfChanged('IrrigationActive', GetValue($irriVar));
        } else {
            $this->SetValueIfChanged('IrrigationActive', false);
        }
        
        // Initialer Lauf der Kostenberechnung
        $this->UpdateCosts();
    }

    public function LeakTimerTriggered(): void
    {
        $irriVar = $this->ReadPropertyInteger('IrrigationVariableID');
        if ($irriVar > 0 && @IPS_VariableExists($irriVar)) {
            if (GetValue($irriVar)) {
                // Bewässerung läuft, also keinen Alarm auslösen!
                $this->SLogInfo('Maximaler Dauerfluss erreicht, aber Bewässerung ist aktiv. Kein Alarm.');
                return;
            }
        }
        
        // Timer fired -> water running continuously for too long!
        $this->SetTimerInterval('LeakTimer', 0); // Stop timer
        $this->SetValueIfChanged('LeakAlarm', true);
        $this->SLogError('LECKAGE-ALARM! Wasser fließt ununterbrochen seit ' . $this->ReadPropertyInteger('MaxContinuousFlowMinutes') . ' Minuten!');
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        $irriVar = $this->ReadPropertyInteger('IrrigationVariableID');
        if ($SenderID === $irriVar && $Message === VM_UPDATE) {
            $this->SetValueIfChanged('IrrigationActive', $Data[0]);
        }
    }

    public function UpdateCosts(): void
    {
        $currentDate = date('Y-m-d');
        $currentMonth = date('Y-m');
        $total = $this->GetValue('TotalConsumption'); // in m³

        $lastUpdateDay = $this->ReadAttributeString('LastUpdateDay');
        $lastUpdateMonth = $this->ReadAttributeString('LastUpdateMonth');

        if ($lastUpdateDay !== $currentDate) {
            $this->WriteAttributeFloat('StartOfDayTotal', $total);
            $this->WriteAttributeString('LastUpdateDay', $currentDate);
            $this->SetValueIfChanged('ConsumptionToday', 0.0);
            $this->SetValueIfChanged('CostToday', 0.0);
        }

        if ($lastUpdateMonth !== $currentMonth) {
            $this->WriteAttributeFloat('StartOfMonthTotal', $total);
            $this->WriteAttributeString('LastUpdateMonth', $currentMonth);
            $this->SetValueIfChanged('ConsumptionMonth', 0.0);
            $this->SetValueIfChanged('CostMonth', 0.0);
        }

        $startOfDay = $this->ReadAttributeFloat('StartOfDayTotal');
        $startOfMonth = $this->ReadAttributeFloat('StartOfMonthTotal');

        $consToday = $total - $startOfDay;
        $consMonth = $total - $startOfMonth;

        // Absicherung falls TotalConsumption mal zurückgesetzt wurde
        if ($consToday < 0) $consToday = 0;
        if ($consMonth < 0) $consMonth = 0;

        $this->SetValueIfChanged('ConsumptionToday', $consToday);
        $this->SetValueIfChanged('ConsumptionMonth', $consMonth);

        // Hole Preise aus Registry
        $regId = $this->ReadPropertyInteger('RegistryID');
        $priceWater = 4.80;
        $basePriceWater = 0.0;
        if ($regId > 1 && @IPS_InstanceExists($regId)) {
            $readPrice = @IPS_GetProperty($regId, 'PriceWater');
            if ($readPrice !== false) $priceWater = $readPrice;
            $readBase = @IPS_GetProperty($regId, 'BasePriceWater');
            if ($readBase !== false) $basePriceWater = $readBase;
        }

        $dailyBase = $basePriceWater / 365.25;
        $monthlyBase = $basePriceWater / 12.0;

        $costToday = ($consToday * $priceWater) + $dailyBase;
        $costMonth = ($consMonth * $priceWater) + $monthlyBase;

        $this->SetValueIfChanged('CostToday', $costToday);
        $this->SetValueIfChanged('CostMonth', $costMonth);
    }

    public function ReceiveData(string $JSONString): string
    {
        $hash = md5($JSONString);
        if ($this->GetBuffer('LastPayloadHash') === $hash) {
            return "OK";
        }
        $this->SetBuffer('LastPayloadHash', $hash);

        try {
            $data = json_decode($JSONString);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) return 'NOK';
            
            if (!isset($data->Topic) || !isset($data->Payload)) {
                return "NOK";
            }
            $topic = $data->Topic;
            $payloadRaw = is_scalar($data->Payload) ? (string)$data->Payload : '';
            $payloadStr = $payloadRaw;
            if (ctype_xdigit($payloadRaw) && strlen($payloadRaw) % 2 === 0) {
                $payloadStr = hex2bin($payloadRaw);
            }
            
            $base = $this->ReadPropertyString('MQTTBaseTopic');

            // Online status (LWT)
            if ($topic === $base . '/status') {
                $isOnline = (strtolower($payloadStr) === 'online');
                $this->SetValueIfChanged('Online', $isOnline);
                return "OK";
            }

            // Sensor states
            if (strpos($topic, $base) !== false) {
                $value = floatval($payloadStr);
                
                // ESPHome sends 'nan' if a sensor is currently unavailable
                if (!is_finite($value)) {
                    return "OK";
                }
                
                $rawValue = $value;
                
                // Flow Rate
                if (strpos($topic, 'flow') !== false || strpos($topic, 'rate') !== false) {
                    $value = $rawValue;
                    $multiplier = $this->ReadPropertyFloat('VolumeMultiplier');
                    if ($multiplier > 0 && $multiplier != 1.0) {
                        $value = $value * $multiplier;
                    }
                    
                    $currentFlow = $this->GetValue('FlowRate');
                    
                    if ($value == 0 || $currentFlow == 0) {
                        $smoothedValue = $value;
                    } else {
                        // Extrem starke Glättung (Alpha = 0.05)
                        // 5% neuer Wert, 95% alter Wert.
                        // Die dynamische Sprungerkennung wurde entfernt, da das Signal
                        // anscheinend naturbedingt stark schwankt (z.B. 30 -> 45 -> 30).
                        $alpha = 0.05;
                        
                        $smoothedValue = ($value * $alpha) + ($currentFlow * (1.0 - $alpha));
                    }
                    
                    $smoothedValue = round($smoothedValue, 2);
                    $this->SetValueIfChanged('FlowRate', $smoothedValue);
                    
                    if ($smoothedValue > 0) {
                        // Water started running
                        if (!$this->GetValue('WaterRunning')) {
                            $this->SetValueIfChanged('WaterRunning', true);
                            
                            // Start Leak Timer if configured
                            $maxMinutes = $this->ReadPropertyInteger('MaxContinuousFlowMinutes');
                            if ($maxMinutes > 0) {
                                $this->SetTimerInterval('LeakTimer', $maxMinutes * 60 * 1000);
                            }
                        }
                    } else {
                        // Water stopped running
                        $this->SetValueIfChanged('WaterRunning', false);
                        $this->SetTimerInterval('LeakTimer', 0); // Stop timer
                        // Optional: Reset Leak Alarm automatically when water stops?
                        // Usually an alarm should be manually acknowledged, but let's reset it for convenience.
                        $this->SetValueIfChanged('LeakAlarm', false);
                    }
                }
                
                // Total Consumption (ESP sends Liters)
                elseif (strpos($topic, 'total') !== false) {
                    $lastRaw = $this->ReadAttributeFloat('LastRawTotal');
                    $deltaRaw = $rawValue - $lastRaw;
                    
                    // If delta is negative, the ESP likely rebooted and started from 0 again.
                    if ($deltaRaw < 0) {
                        $deltaRaw = $rawValue;
                    }
                    
                    $this->WriteAttributeFloat('LastRawTotal', $rawValue);
                    
                    // Add delta to our persistent Symcon variables
                    if ($deltaRaw > 0) {
                        $delta = $deltaRaw;
                        $multiplier = $this->ReadPropertyFloat('VolumeMultiplier');
                        if ($multiplier > 0 && $multiplier != 1.0) {
                            $delta = $delta * $multiplier;
                        }
                        
                        $currentLiters = $this->GetValue('TotalConsumptionLiter');
                        $newLiters = $currentLiters + $delta;
                        
                        $this->SetValueIfChanged('TotalConsumptionLiter', $newLiters);
                        $this->SetValueIfChanged('TotalConsumption', $newLiters / 1000.0);
                        
                        $this->UpdateCosts();
                    }
                }
            }
            return "OK";
        } catch (Throwable $e) {
            $this->SLogInfo('Error in ReceiveData: ' . $e->getMessage());
            return "NOK";
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'TotalConsumption':
                $this->SetValueIfChanged('TotalConsumption', $Value);
                $this->SetValueIfChanged('TotalConsumptionLiter', $Value * 1000.0);
                break;
            case 'TotalConsumptionLiter':
                $this->SetValueIfChanged('TotalConsumptionLiter', $Value);
                $this->SetValueIfChanged('TotalConsumption', $Value / 1000.0);
                break;
            default:
                throw new Exception("Invalid ident");
        }
    }

    public function GetConfigurationForm(): string
    {

        $registryOptions = [['label' => '(Bitte auswählen)', 'value' => 0]];
        $instances = @IPS_GetInstanceListByModuleID('{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}');
        if (is_array($instances)) {
            foreach ($instances as $instID) {
                $registryOptions[] = ['label' => IPS_GetName($instID), 'value' => $instID];
            }
        }

        $form = [
            'status' => [
                [ 'code' => 104, 'icon' => 'inactive', 'caption' => 'Sensor nicht konfiguriert' ]
            ],
            'elements' => [
                [
                    'type' => 'Label',
                    'caption' => 'Trage hier das MQTT Base Topic deines Wasserzählers ein, z.B. \'watermeter\'.'
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'MQTTBaseTopic',
                    'caption' => 'MQTT Base Topic'
                ],
                [
                    'type' => 'Label',
                    'caption' => 'Falls der Wasserzähler falsche Werte liefert, kannst du hier einen Multiplikator (Faktor) eintragen. Beispiel: Zähler zeigt 40 Liter statt 10 an -> Faktor 0.25 eintragen.'
                ],
                [
                    'type' => 'NumberSpinner',
                    'name' => 'VolumeMultiplier',
                    'caption' => 'Korrekturfaktor',
                    'minimum' => 0.001,
                    'maximum' => 1000.0,
                    'digits' => 4
                ],
                [
                    'type' => 'Label',
                    'caption' => 'Hier stellst du ein, nach wie vielen Minuten ununterbrochenem Wasserfluss ein Leckage-Alarm ausgelöst werden soll. (0 = Deaktiviert)'
                ],
                [
                    'type' => 'NumberSpinner',
                    'name' => 'MaxContinuousFlowMinutes',
                    'caption' => 'Leckage-Alarm (Minuten)',
                    'minimum' => 0,
                    'maximum' => 1440,
                    'suffix' => ' min'
                ],
                [
                    'type' => 'Label',
                    'caption' => 'Wähle hier die DeviceRegistry-Instanz aus, um den aktuellen Wasserpreis abzurufen.'
                ],
                [
                    'type' => 'Select',
                    'name' => 'RegistryID',
                    'caption' => 'Device Registry',
                    'options' => $registryOptions
                ],
                [
                    'type' => 'Label',
                    'caption' => 'Wähle hier die Variable aus, die den Bewässerungs-Status anzeigt. So verhindern wir Fehlalarme während du gießt.'
                ],
                [
                    'type' => 'SelectVariable',
                    'name' => 'IrrigationVariableID',
                    'caption' => 'Bewässerungs-Status'
                ]
            ],
            'actions' => [
                [
                    'type' => 'Label',
                    'caption' => 'Der Leckage-Alarm löst aus, wenn das Wasser ohne Pause länger fließt, als oben eingestellt.'
                ]
            ]
        ];

        return json_encode($form);
    }


    protected function SetValueIfChanged(string $ident, mixed $value): bool
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
            return true;
        }
        return false;
    }
}


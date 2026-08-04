<?php

declare(strict_types=1);

class SmartVentilationAdvisor extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Konfiguration: Quell-Variablen
        $this->RegisterPropertyInteger('SourceTempIndoor', 0);
        $this->RegisterPropertyInteger('SourceHumIndoor', 0);
        $this->RegisterPropertyInteger('SourceTempOutdoor', 0);
        $this->RegisterPropertyInteger('SourceHumOutdoor', 0);

        // Konfiguration: Grenzwerte (Dynamisch)
        $this->RegisterPropertyFloat('TargetTempMax', 23.0);
        $this->RegisterPropertyFloat('TargetHumMax', 60.0);

        // Profile anlegen
        if (!IPS_VariableProfileExists('SVA.AbsoluteHumidity')) {
            IPS_CreateVariableProfile('SVA.AbsoluteHumidity', 2);
            IPS_SetVariableProfileText('SVA.AbsoluteHumidity', '', ' g/m³');
            IPS_SetVariableProfileValues('SVA.AbsoluteHumidity', 0, 50, 0.1);
            IPS_SetVariableProfileDigits('SVA.AbsoluteHumidity', 1);
            IPS_SetVariableProfileIcon('SVA.AbsoluteHumidity', 'Drops');
        }

        if (!IPS_VariableProfileExists('SVA.MoldRisk')) {
            IPS_CreateVariableProfile('SVA.MoldRisk', 1);
            IPS_SetVariableProfileText('SVA.MoldRisk', '', ' %');
            IPS_SetVariableProfileValues('SVA.MoldRisk', 0, 100, 1);
            IPS_SetVariableProfileAssociation('SVA.MoldRisk', 0, '%d %%', 'Ok', 0x00CC00);
            IPS_SetVariableProfileAssociation('SVA.MoldRisk', 50, '%d %%', 'Warning', 0xFFAA00);
            IPS_SetVariableProfileAssociation('SVA.MoldRisk', 75, '%d %%', 'Alert', 0xFF0000);
        }

        // Variablen registrieren
        $this->RegisterVariableFloat('DewPointIndoor', 'Taupunkt (Innen)', '~Temperature', 10);
        $this->RegisterVariableFloat('AbsHumIndoor', 'Absolute Feuchte (Innen)', 'SVA.AbsoluteHumidity', 20);
        $this->RegisterVariableFloat('AbsHumOutdoor', 'Absolute Feuchte (Außen)', 'SVA.AbsoluteHumidity', 30);
        
        $this->RegisterVariableInteger('MoldRiskIndex', 'Schimmelrisiko', 'SVA.MoldRisk', 40);

        // Lüftungsempfehlung als Boolean (Read-only) mit CustomPresentation
        $this->RegisterVariableBoolean('VentilationNeeded', 'Lüftungsempfehlung', '', 50);
        $options = json_encode([
            ['Value' => false, 'Caption' => 'Fenster zu', 'IconActive' => true, 'IconValue' => 'Window', 'Color' => 0x00CC00],
            ['Value' => true,  'Caption' => 'Fenster auf (Lüften)', 'IconActive' => true, 'IconValue' => 'Window', 'Color' => 0x00AADD]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('VentilationNeeded'), json_encode(['OPTIONS' => $options]));

        // Grund der Empfehlung
        $this->RegisterVariableString('VentilationReason', 'Lüftungsgrund', '', 60);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('VentilationReason'), json_encode([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Information'
        ]));
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Nachrichten abbestellen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Nachrichten registrieren
        $this->RegisterSensorMessage('SourceTempIndoor');
        $this->RegisterSensorMessage('SourceHumIndoor');
        $this->RegisterSensorMessage('SourceTempOutdoor');
        $this->RegisterSensorMessage('SourceHumOutdoor');

        // Initial berechnen
        $this->Calculate();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message == VM_UPDATE) {
            $this->Calculate();
        }
    }

    private function RegisterSensorMessage(string $PropertyName): void
    {
        $id = $this->ReadPropertyInteger($PropertyName);
        if ($id > 0 && IPS_VariableExists($id)) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
    }

    public function Calculate(): void
    {
        $idTempIn = $this->ReadPropertyInteger('SourceTempIndoor');
        $idHumIn  = $this->ReadPropertyInteger('SourceHumIndoor');
        $idTempOut = $this->ReadPropertyInteger('SourceTempOutdoor');
        $idHumOut  = $this->ReadPropertyInteger('SourceHumOutdoor');

        if ($idTempIn == 0 || $idHumIn == 0 || $idTempOut == 0 || $idHumOut == 0) {
            $this->SetReason('Fehlende Sensoren', false);
            return;
        }

        $tempIn = GetValue($idTempIn);
        $humIn = GetValue($idHumIn);
        $tempOut = GetValue($idTempOut);
        $humOut = GetValue($idHumOut);

        $targetTempMax = $this->ReadPropertyFloat('TargetTempMax');
        $targetHumMax = $this->ReadPropertyFloat('TargetHumMax');

        // Berechnungen
        $absHumIn = $this->CalcAbsoluteHumidity((float)$tempIn, (float)$humIn);
        $absHumOut = $this->CalcAbsoluteHumidity((float)$tempOut, (float)$humOut);
        $dewPointIn = $this->CalcDewPoint((float)$tempIn, (float)$humIn);

        // Schimmelrisiko (Einfacher linearer Index bezogen auf TargetHumMax)
        // Bei TargetHumMax ist das Risiko 50%
        // Bei TargetHumMax + 10% ist das Risiko 100%
        // Bei TargetHumMax - 10% ist das Risiko 0%
        $risk = ($humIn - ($targetHumMax - 10)) * 5;
        $risk = max(0, min(100, $risk));

        $this->SetValue('AbsHumIndoor', $absHumIn);
        $this->SetValue('AbsHumOutdoor', $absHumOut);
        $this->SetValue('DewPointIndoor', $dewPointIn);
        $this->SetValue('MoldRiskIndex', (int)$risk);

        // Lüftungsempfehlung Logik
        $recommendVentilation = false;
        $reason = 'Kein Bedarf';

        // 1. Kühlen (Free Cooling)
        if ($tempIn > $targetTempMax && $tempOut < $tempIn) {
            $recommendVentilation = true;
            $reason = 'Kühlen (Draußen kälter)';
        }
        
        // 2. Trocknen (Schimmelschutz)
        // Hat höhere Prio bei der Anzeige
        if ($humIn > $targetHumMax) {
            if ($absHumOut < $absHumIn) {
                $recommendVentilation = true;
                $reason = 'Trocknen (Draußen trockener)';
            } else {
                $reason = 'Nicht lüften (Draußen feuchter)';
            }
        }

        // 3. Wenn draußen extrem warm und wir nicht kühlen wollen/können
        if (!$recommendVentilation && $tempOut > $tempIn) {
             $reason = 'Fenster zu (Draußen wärmer)';
        }

        $this->SetValue('VentilationNeeded', $recommendVentilation);
        $this->SetValue('VentilationReason', $reason);
    }

    private function SetReason(string $Reason, bool $State): void
    {
        $this->SetValue('VentilationNeeded', $State);
        $this->SetValue('VentilationReason', $Reason);
    }

    private function CalcAbsoluteHumidity(float $Temp, float $RH): float
    {
        if ($RH <= 0) return 0.0;
        // Formel für absolute Luftfeuchtigkeit in g/m³
        $mw = 18.016; // Molmasse Wasser
        $r = 8314.3; // Universelle Gaskonstante
        $af = (6.112 * exp((17.67 * $Temp) / ($Temp + 243.5)) * $RH * 2.1674) / (273.15 + $Temp);
        return round($af, 2);
    }

    private function CalcDewPoint(float $Temp, float $RH): float
    {
        if ($RH <= 0) return $Temp;
        // Magnus-Formel
        $a = 17.625;
        $b = 243.04;
        $alpha = log($RH / 100) + (($a * $Temp) / ($b + $Temp));
        $dp = ($b * $alpha) / ($a - $alpha);
        return round($dp, 2);
    }
}

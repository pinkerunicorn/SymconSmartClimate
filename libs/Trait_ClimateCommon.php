<?php

declare(strict_types=1);

/**
 * ClimateCommon — Gemeinsamer PHP-Trait für alle SmartClimateControl-Module.
 *
 * Enthält geteilte Hilfsfunktionen, die in BasementClimate, FireplaceSafety
 * und GardenHouseClimate identisch genutzt werden.
 *
 * Verwendung in einer Modul-Klasse:
 *   require_once __DIR__ . '/../libs/Trait_SmartLog.php';
 *   require_once __DIR__ . '/../libs/Trait_ClimateCommon.php';
 *   class MyClimate extends IPSModuleStrict {
 *       use SmartLog_Trait;
 *       use ClimateCommon_Trait;
 *       ...
 *   }
 */
if (!trait_exists('ClimateCommon_Trait')) {
trait ClimateCommon_Trait
{


    // ─────────────────────────────────────────────────────────────────
    // Variablen-Hilfsmethoden
    // ─────────────────────────────────────────────────────────────────

    /**
     * Setzt einen Variablenwert nur, wenn er sich geändert hat.
     * Verhindert unnötige Historiograph-Einträge.
     */
    protected function SetValueIfChanged(string $Ident, mixed $Value): void
    {
        if ($this->GetValue($Ident) !== $Value) {
            $this->SetValue($Ident, $Value);
        }
    }

    /**
     * Liest den aktuellen Wert einer Variable, deren ID in einer Integer-Property gespeichert ist.
     * Gibt null zurück, wenn die Property nicht konfiguriert oder die Variable nicht vorhanden ist.
     */
    protected function GetPropertyVarValue(string $PropertyName): mixed
    {
        $id = $this->ReadPropertyInteger($PropertyName);
        if ($id > 0 && IPS_VariableExists($id)) {
            return GetValue($id);
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────
    // Fenster- / Türsensor-Hilfsmethoden
    // ─────────────────────────────────────────────────────────────────

    /**
     * Prüft, ob ein einzelner Fenster-/Türsensor aktuell offen ist.
     * Unterstützt bool-, int-, float- und string-Sensorwerte.
     *
     * @param int    $variableId  IP-Symcon Variable-ID des Sensors
     * @param string $closedValue Wert, bei dem der Sensor "geschlossen" bedeutet (z.B. "false", "0", "true")
     * @return bool true = Fenster/Tür ist OFFEN
     */
    protected function IsWindowOpen(int $variableId, string $closedValue): bool
    {
        if (!IPS_VariableExists($variableId)) return false;
        $currentVal = GetValue($variableId);
        $isClosed = false;

        if (is_bool($currentVal)) {
            $normalized = strtolower(trim($closedValue));
            $targetBool = ($normalized === 'true' || $normalized === '1' || $normalized === 'wahr');
            $isClosed = ($currentVal === $targetBool);
        } elseif (is_int($currentVal) || is_float($currentVal)) {
            $isClosed = ($currentVal == (float)$closedValue);
        } else {
            $isClosed = ((string)$currentVal === (string)$closedValue);
        }

        return !$isClosed;
    }

    /**
     * Gibt true zurück, wenn mindestens eines der Fenster/Türen in der
     * JSON-Property (Standardname: 'SensorWindows') aktuell offen ist.
     *
     * @param string $propertyName Name der JSON-String-Property mit der Fensterliste
     */
    protected function AnyWindowOpen(string $propertyName = 'SensorWindows'): bool
    {
        $windows = json_decode($this->ReadPropertyString($propertyName), true) ?? [];
        foreach ($windows as $w) {
            $vid = (int)($w['VariableID'] ?? 0);
            $closedVal = (string)($w['ClosedValue'] ?? 'false');
            if ($vid > 0 && IPS_VariableExists($vid)) {
                if ($this->IsWindowOpen($vid, $closedVal)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────
    // MessageSink / Referenzen registrieren
    // ─────────────────────────────────────────────────────────────────

    /**
     * Hebt alle aktuell registrierten MessageSink-Listener auf.
     * Muss zu Beginn von ApplyChanges() aufgerufen werden, bevor neu registriert wird.
     */
    protected function UnregisterAllMessages(): void
    {
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
    }

    /**
     * Registriert VM_UPDATE-Nachrichten für alle Fenster-/Türsensoren
     * aus der JSON-Property (Standardname: 'SensorWindows').
     */
    protected function RegisterWindowMessages(string $propertyName = 'SensorWindows'): void
    {
        $windows = json_decode($this->ReadPropertyString($propertyName), true) ?? [];
        foreach ($windows as $w) {
            $vid = (int)($w['VariableID'] ?? 0);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
            }
        }
    }

    /**
     * Registriert IP-Symcon Referenzen für alle Fenster-/Türsensoren
     * aus der JSON-Property (Standardname: 'SensorWindows').
     */
    protected function RegisterWindowReferences(string $propertyName = 'SensorWindows'): void
    {
        $windows = json_decode($this->ReadPropertyString($propertyName), true) ?? [];
        foreach ($windows as $item) {
            $vid = (int)($item['VariableID'] ?? 0);
            if ($vid > 1 && @IPS_ObjectExists($vid)) {
                $this->RegisterReference($vid);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Presentations (Symcon 8+)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Erzeugt die OPTIONS für eine bool-Alarm-Variable (Symcon 8+).
     * Rot (oder eigene Farbe) bei true, grün bei false.
     * Rückgabe als JSON-String für inline Registration bei RegisterVariableBoolean.
     *
     * Verwendung:
     *   $this->RegisterVariableBoolean('Alarm', 'Alarm', [
     *       'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
     *       'ICON'         => 'Warning',
     *       'OPTIONS'      => $this->BuildAlarmOptions('Alarm!', 'OK'),
     *   ], 10);
     *
     * @param string $alarmCaption Text wenn Alarm aktiv (true)
     * @param string $okCaption    Text wenn kein Alarm (false), Standard: 'OK'
     * @param int    $alarmColor   Farbe für Alarm-Zustand, Standard: Rot 0xFF0000
     * @return string JSON-kodiertes OPTIONS-Array
     */
    protected function BuildAlarmOptions(
        string $alarmCaption,
        string $okCaption = 'OK',
        int    $alarmColor = 0xFF0000
    ): string {
        return json_encode([
            [
                'Value'               => false,
                'Caption'             => $okCaption,
                'IconValue'           => 'Ok',
                'IconActive'          => true,
                'ColorActive'         => true,
                'ColorDisplay'        => 0x00CC00,
                'ColorValue'          => 0x00CC00,
                'ContentColorActive'  => true,
                'ContentColorDisplay' => 0xFFFFFF,
                'ContentColorValue'   => 0xFFFFFF
            ],
            [
                'Value'               => true,
                'Caption'             => $alarmCaption,
                'IconValue'           => 'Warning',
                'IconActive'          => true,
                'ColorActive'         => true,
                'ColorDisplay'        => $alarmColor,
                'ColorValue'          => $alarmColor,
                'ContentColorActive'  => true,
                'ContentColorDisplay' => 0xFFFFFF,
                'ContentColorValue'   => 0xFFFFFF
            ]
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Timer-Hilfsmethoden
    // ─────────────────────────────────────────────────────────────────

    /**
     * Startet einen One-Shot-Timer, sofern er nicht bereits läuft.
     * Wenn der Timer-Intervall bereits > 0 ist, wird er NICHT neu gestartet.
     */
    protected function StartTimerOnce(string $name, int $seconds): void
    {
        if ($this->GetTimerInterval($name) == 0) {
            $this->SetTimerInterval($name, $seconds * 1000);
        }
    }

    /**
     * Stoppt einen Timer (setzt Intervall auf 0).
     */
    protected function StopTimer(string $name): void
    {
        $this->SetTimerInterval($name, 0);
    }

    protected function CalculateDewPoint(float $t, float $rh): float
    {
        $a = ($t < 0) ? 7.6 : 7.5;
        $b = ($t < 0) ? 240.7 : 237.3;
        $sdd = 6.1078 * pow(10, ($a * $t) / ($b + $t));
        $dd  = $sdd * ($rh / 100);
        $v   = log10($dd / 6.1078);
        return ($b * $v) / ($a - $v);
    }

    protected function CalculateAbsoluteHumidity(float $t, float $rh): float
    {
        $a = ($t < 0) ? 7.6 : 7.5;
        $b = ($t < 0) ? 240.7 : 237.3;
        $sdd = 6.1078 * pow(10, ($a * $t) / ($b + $t));
        $dd  = $sdd * ($rh / 100);
        return 100000 * 18.016 / 8314.3 * $dd / ($t + 273.15);
    }
}
}

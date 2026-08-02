<?php

declare(strict_types=1);

/**
 * SmartLawnAI_AI â€” Gemini KI-Integration via SmartGeminiIO.
 *
 * Nutzt GIO_Query() statt direkter curl-Aufrufe.
 * API-Key und Modell werden Ã¼ber SmartGeminiIO zentral verwaltet.
 */
trait SmartLawnAI_AI {

    /** GUID des SmartGeminiIO-Moduls zur Auto-Discovery */
    private const GEMINI_IO_GUID = '{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}';

    public function ProcessGeminiRetry(): void {
        $queueStr = $this->GetBuffer('GeminiRetryQueue');
        if (empty($queueStr)) {
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            return;
        }

        $queue = json_decode($queueStr, true);
        if (!is_array($queue) || empty($queue)) {
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            return;
        }

        // Hole das erste Element aus der Queue
        $item = array_shift($queue);
        $this->SetBuffer('GeminiRetryQueue', json_encode($queue));

        if (empty($queue)) {
            $this->SetTimerInterval('GeminiRetryTimer', 0);
        }

        $this->LogAndDebug('Weather', 'Starte Gemini Retry Versuch ' . ($item['retryCount'] + 1) . ' fÃ¼r Zone ' . $item['zoneID'], 0);

        $this->EvaluateEfficiencyWithGemini(
            $item['zoneID'],
            $item['startFeuchte'],
            $item['aktuelleFeuchte'],
            $item['dauer'],
            $item['vpd'],
            $item['lux'],
            $item['retryCount'] + 1
        );
    }

    public function EvaluateEfficiencyWithGemini(int $zoneID, float $startFeuchte, float $aktuelleFeuchte, float $dauer, float $vpd, float $lux, int $retryCount = 0): void {
        $zoneName = 'Zone ' . $zoneID;
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (is_array($zones)) {
            foreach ($zones as $z) {
                if ($z['SensorID'] == $zoneID && !empty($z['GroupName'])) {
                    $zoneName = $z['GroupName'];
                    break;
                }
            }
        }

        // SmartGeminiIO auto-discover
        $geminiInstances = IPS_GetInstanceListByModuleID(self::GEMINI_IO_GUID);
        if (empty($geminiInstances)) {
            $this->LogAndDebug('Weather', 'SmartGeminiIO Instanz nicht gefunden! Bitte eine Instanz erstellen.', 0);
            $this->AddLogEvent("{$zoneName}: Abschluss (ohne KI)", "Dauer: {$dauer} Min | VPD: " . number_format($vpd, 2) . " kPa | Feuchte: {$startFeuchte}% -> {$aktuelleFeuchte}%");
            return;
        }
        $geminiId = $geminiInstances[0];

        $userPrompt  = "Du bist ein Agrar-Analyst. Bewerte den folgenden BewÃ¤sserungs-Zyklus:\n";
        $userPrompt .= "- Zone ID: $zoneID\n";
        $userPrompt .= "- Dauer der BewÃ¤sserung: $dauer Minuten\n";
        $userPrompt .= "- Bodenfeuchte vor dem GieÃŸen: $startFeuchte %\n";
        $userPrompt .= "- Bodenfeuchte nach der Sickerpause: $aktuelleFeuchte %\n";
        $userPrompt .= "- Wetter: SÃ¤ttigungsdefizit (VPD) = $vpd kPa, Helligkeit = $lux Lux\n";
        $userPrompt .= "\nBerechne einen neuen 'efficiencyPercentPerMinute'-Multiplikator fÃ¼r diese Zone (wie viel Prozent Feuchte bringt 1 Minute GieÃŸen). Normaler Wert: 0.5 bis 3.0.";

        $systemInstruction = 'Du antwortest ausschlieÃŸlich im JSON-Format.';

        $responseSchema = json_encode([
            'type'       => 'OBJECT',
            'properties' => [
                'newEfficiencyMultiplier' => ['type' => 'NUMBER', 'description' => 'Der neu berechnete Effizienz-Faktor.'],
                'reasoning'              => ['type' => 'STRING', 'description' => 'Agronomische BegrÃ¼ndung fÃ¼r diesen Wert.']
            ],
            'required' => ['newEfficiencyMultiplier', 'reasoning']
        ]);

        $instanceId = $this->InstanceID;

        // Async via IPS_RunScriptText â€” GIO_Query blockiert, daher in Background
        $script = '<?php

declare(strict_types=1);

$result = GIO_Query(' . $geminiId . ',
                ' . var_export($userPrompt, true) . ',
                ' . var_export($systemInstruction, true) . ',
                ' . var_export($responseSchema, true) . ',
                0.1
            );
            SLAI_ProcessGeminiResult(' . $instanceId . ', $result, ' . $zoneID . ', ' . $startFeuchte . ', ' . $aktuelleFeuchte . ', ' . $dauer . ', ' . $vpd . ', ' . $lux . ', ' . $retryCount . ');
        ';
        IPS_RunScriptText($script);
    }

    /**
     * Verarbeitet das Ergebnis der Gemini-Effizienz-Analyse.
     * Wird aus dem Background-Script via IPS_RunScriptText aufgerufen.
     *
     * @param string $jsonText Bereits extrahierter JSON-Text von GIO_Query
     */
    public function ProcessGeminiResult(string $jsonText, int $zoneID, float $startFeuchte, float $aktuelleFeuchte, float $dauer, float $vpd, float $lux, int $retryCount): void {
        $zoneName = 'Zone ' . $zoneID;
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (is_array($zones)) {
            foreach ($zones as $z) {
                if ($z['SensorID'] == $zoneID && !empty($z['GroupName'])) {
                    $zoneName = $z['GroupName'];
                    break;
                }
            }
        }

        if (!empty($jsonText)) {
            $parsed = json_decode($jsonText, true);
            if (is_array($parsed) && isset($parsed['newEfficiencyMultiplier'])) {
                $neueEffizienz = (float)$parsed['newEfficiencyMultiplier'];
                $begruendung   = $parsed['reasoning'] ?? '';

                $this->SetZoneEffizienz($zoneID, $neueEffizienz);
                $this->SLogInfo('Gemini Effizienz-Lernen (Zone ' . $zoneID . '): Neuer Faktor = ' . $neueEffizienz . 'x', $begruendung);
                $this->AddLogEvent("{$zoneName}: KI-Lernen erfolgreich", "Neue Effizienz: {$neueEffizienz}x. Grund: {$begruendung}", '#9C27B0');
                return;
            }
        }

        // Fehlerfall: Retry
        if ($retryCount < 3) {
            $this->LogAndDebug('Weather', "Fehler beim Gemini Effizienz-Lernen fÃ¼r Zone $zoneID. Starte Retry in 5 Minuten (Versuch " . ($retryCount + 1) . ").", 0);

            $queueStr = $this->GetBuffer('GeminiRetryQueue');
            $queue    = $queueStr ? json_decode($queueStr, true) : [];
            if (!is_array($queue)) $queue = [];

            $queue[] = [
                'zoneID'          => $zoneID,
                'startFeuchte'    => $startFeuchte,
                'aktuelleFeuchte' => $aktuelleFeuchte,
                'dauer'           => $dauer,
                'vpd'             => $vpd,
                'lux'             => $lux,
                'retryCount'      => $retryCount
            ];

            $this->SetBuffer('GeminiRetryQueue', json_encode($queue));
            $this->SetTimerInterval('GeminiRetryTimer', 300000);
        } else {
            $this->LogAndDebug('Weather', "Gemini Effizienz-Lernen fÃ¼r Zone $zoneID nach 3 Versuchen endgÃ¼ltig fehlgeschlagen.", 0);
            $this->SLogError('Gemini Effizienz-Lernen fehlgeschlagen', 'Zone ' . $zoneID . ' endgÃ¼ltig fehlgeschlagen nach 3 Versuchen');
            $this->AddLogEvent("{$zoneName}: KI-Lernen fehlgeschlagen", 'SmartGeminiIO: Keine Antwort nach 3 Versuchen.', '#F44336');
        }
    }
}

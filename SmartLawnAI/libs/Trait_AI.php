<?php

declare(strict_types=1);

/**
 * SmartLawnAI_AI â€” Gemini KI-Integration via SmartGeminiIO.
 *
 * Nutzt GIO_Query() statt direkter curl-Aufrufe.
 * API-Key und Modell werden über SmartGeminiIO zentral verwaltet.
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

        $item = array_shift($queue);
        $this->SetBuffer('GeminiRetryQueue', json_encode($queue));

        $this->EvaluateEfficiencyWithGemini(
            $item['sensorID'],
            $item['startMoisture'],
            $item['endMoisture'],
            $item['durationMin'],
            $item['vpd'],
            $item['lux']
        );

        if (empty($queue)) {
            $this->SetTimerInterval('GeminiRetryTimer', 0);
        } else {
            $this->SetTimerInterval('GeminiRetryTimer', 60000);
        }
    }

    public function EvaluateEfficiencyWithGemini(int $sensorID, float $startMoisture, float $endMoisture, int $durationMin, float $vpd, float $lux): void {
        $geminiInstances = IPS_GetInstanceListByModuleID(self::GEMINI_IO_GUID);
        if (empty($geminiInstances)) {
            $this->addToGeminiRetryQueue($sensorID, $startMoisture, $endMoisture, $durationMin, $vpd, $lux);
            return;
        }
        $geminiId = $geminiInstances[0];

        $userPrompt  = "Du bist ein Agrar-Analyst. Bewerte den folgenden Bewässerungs-Zyklus:\n";
        $userPrompt .= "- Zone Sensor ID: $sensorID\n";
        $userPrompt .= "- Dauer der Bewässerung: $durationMin Minuten\n";
        $userPrompt .= "- Bodenfeuchte vor dem Gießen: $startMoisture %\n";
        $userPrompt .= "- Bodenfeuchte nach der Sickerpause: $endMoisture %\n";
        $userPrompt .= "- Wetter: Sättigungsdefizit (VPD) = $vpd kPa, Helligkeit = $lux Lux\n";
        $userPrompt .= "\nBerechne einen neuen 'efficiencyFactor' für diese Zone (Anpassung der Gießdauer). >1.0 bedeutet die Zone braucht mehr Wasser, <1.0 weniger.";

        $systemInstruction = 'Du antwortest ausschließlich im JSON-Format.';

        $responseSchema = json_encode([
            'type'       => 'OBJECT',
            'properties' => [
                'efficiencyFactor' => ['type' => 'NUMBER', 'description' => 'Der Effizienz-Faktor (0.5 bis 2.0).'],
                'reasoning'        => ['type' => 'STRING', 'description' => 'Agronomische Begründung für diesen Wert.']
            ],
            'required' => ['efficiencyFactor', 'reasoning']
        ]);

        $instanceId = $this->InstanceID;

        $script = '<?php

declare(strict_types=1);

try {
    $result = GIO_Query(' . $geminiId . ',
        ' . var_export($userPrompt, true) . ',
        ' . var_export($systemInstruction, true) . ',
        ' . var_export($responseSchema, true) . ',
        0.1
    );
} catch (Throwable $e) {
    $result = "";
}
SLAI_ProcessGeminiEfficiencyResult(' . $instanceId . ', ' . $sensorID . ', (string)$result);
';
        IPS_RunScriptText($script);
    }

    public function ProcessGeminiEfficiencyResult(int $sensorID, string $result): void {
        if (empty($result)) {
            $this->LogAndDebug('Weather', "Leeres Ergebnis beim Gemini Effizienz-Lernen für Sensor $sensorID.", 2);
            return;
        }

        $parsed = json_decode($result, true);
        if (is_array($parsed) && isset($parsed['efficiencyFactor'])) {
            $efficiencyFactor = (float)$parsed['efficiencyFactor'];
            $efficiencyFactor = max(0.5, min(2.0, $efficiencyFactor));
            $reasoning = $parsed['reasoning'] ?? '';

            $this->SetPersistentZoneEffizienz($sensorID, $efficiencyFactor);
            $this->AddLogEvent("Sensor {$sensorID}: KI-Lernen erfolgreich", "Neue Effizienz: {$efficiencyFactor}x. Grund: {$reasoning}", '#9C27B0');
            $this->SLogInfo('Gemini Effizienz-Lernen (Sensor ' . $sensorID . ')', 'Neuer Faktor = ' . $efficiencyFactor . 'x. ' . $reasoning);
        } else {
            $this->LogAndDebug('Weather', "Fehler beim Parsen der Gemini-Antwort für Sensor $sensorID: " . $result, 2);
        }
    }

    private function addToGeminiRetryQueue(int $sensorID, float $startMoisture, float $endMoisture, int $durationMin, float $vpd, float $lux): void {
        $queueStr = $this->GetBuffer('GeminiRetryQueue');
        $queue    = $queueStr ? json_decode($queueStr, true) : [];
        if (!is_array($queue)) {
            $queue = [];
        }

        $queue[] = [
            'sensorID'      => $sensorID,
            'startMoisture' => $startMoisture,
            'endMoisture'   => $endMoisture,
            'durationMin'   => $durationMin,
            'vpd'           => $vpd,
            'lux'           => $lux,
            'retryCount'    => 0
        ];

        if (count($queue) > 10) {
            array_shift($queue);
        }

        $this->SetBuffer('GeminiRetryQueue', json_encode($queue));
        $this->SetTimerInterval('GeminiRetryTimer', 60000);
    }
}

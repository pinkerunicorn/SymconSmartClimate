# Basement Climate (Smarte Kellerklima-Steuerung)

Dieses IP-Symcon Modul übernimmt die intelligente Überwachung und Steuerung des Kellerklimas, um Schimmelbildung zu vermeiden und eine optimale Feuchtigkeit zu gewährleisten.

## Funktionen

### 1. Lüftungsempfehlung & Fensterüberwachung
Das Modul berechnet kontinuierlich die **absolute Feuchtigkeit** (in g/m³) für den Keller (Innen) und den Außenbereich anhand der Temperatur- und relativen Feuchtigkeitswerte.
- **Lüftungsempfehlung:** Ist es draußen deutlich trockener als drinnen (konfigurierbarer Schwellwert), gibt das Modul eine Lüftungsempfehlung ab.
- **Proaktive Schließ-Warnung:** Sind die Kellerfenster zum Lüften geöffnet, überwacht das Modul die Feuchtigkeitsentwicklung. Sobald die Außenluft droht, feuchter zu werden als die Innenluft, wird ein Vor-Alarm und schließlich ein kritischer Schließ-Alarm (`AlarmWindowClose`) ausgelöst, damit die Fenster rechtzeitig geschlossen werden können und der Keller nicht feuchter wird.

### 2. Automatische Entfeuchter-Steuerung
Das Modul steuert eine Schaltsteckdose für einen Luftentfeuchter:
- Erreicht die Kellerfeuchtigkeit die **Einschaltschwelle** (z.B. 60%), wird der Entfeuchter aktiviert.
- Sinkt die Feuchtigkeit auf die **Ausschaltschwelle** (z.B. 55%), wird er wieder deaktiviert.
- **Fenster-Logik:** Ist ein Kellerfenster geöffnet, wird der Entfeuchter automatisch pausiert, um nicht unnötig die von außen hereinströmende Luft zu entfeuchten.

### 3. Tank-Voll-Erkennung
Wenn der Luftentfeuchter an einer messenden Steckdose betrieben wird, erkennt das Modul über die Leistungsaufnahme (Watt), ob der Wassertank voll ist.
- **Logik:** Wenn die Steckdose eingeschaltet ist, die Leistungsaufnahme aber über eine definierte Zeit (z.B. 60 Sekunden) unter einen Schwellwert (z.B. 10 Watt) fällt, geht das Modul davon aus, dass der Entfeuchter wegen eines vollen Tanks abgeschaltet hat. Es löst den Alarm `AlarmTankFull` aus.
- Wird der Tank geleert und der Entfeuchter beginnt wieder zu arbeiten (Leistungsaufnahme steigt), setzt sich der Alarm automatisch zurück.

### 4. Anti-Schimmel Heizungs-Logik
Das Modul kann bis zu zwei Heizkörper (Solltemperatur) steuern:
- Im Normalbetrieb wird die Heizung auf die definierte **Basis-Solltemperatur** geregelt (z.B. 18°C).
- **Anti-Schimmel-Automatik:** Steigt die relative Luftfeuchtigkeit im Keller auf kritische Werte (über 70%), wird die Solltemperatur der Heizkörper automatisch um 2°C angehoben. Da wärmere Luft mehr Feuchtigkeit aufnehmen kann, trocknet dies die Wände und wirkt der Schimmelbildung aktiv entgegen.

## Einrichtung
Die Konfiguration erfolgt vollständig über das Formular der Instanz in der IP-Symcon Verwaltungskonsole. Für alle Funktionen sind direkt im Formular erklärende Hilfetexte hinterlegt.

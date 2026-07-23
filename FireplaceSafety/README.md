# FireplaceSafety

Überwacht den Kaminofen und sorgt für Sicherheit beim Betrieb einer Dunstabzugshaube.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Überwacht die Temperatur-Differenz zwischen Kamin und Raum.
* Blockiert die Dunstabzugshaube, wenn der Kamin an ist und kein Fenster geöffnet ist (Sicherheitsschaltung).
* Ermittelt den optimalen Zeitpunkt zum Holz nachlegen anhand des Temperaturabfalls nach einem Peak.
* Warnt bei zu lange geöffneter Ofentür, wenn der Kamin an ist.
* Unterdrückt die "Nachlegen"-Meldung, wenn die maximale Raumtemperatur erreicht wurde.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0

### 3. Installation

* Über den Module Store das Modul `FireplaceSafety` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartClimate`

### 4. Konfiguration

* **SensorOvenTemp**: Temperatur Ofen / Abgasrohr.
* **SensorRoomTemp**: Temperatur Raum.
* **SensorOvenDoor**: Ofentür-Kontakt.
* **OvenDoorClosedValue**: Wert bei geschlossener Tür.
* **SensorWindows**: Liste von Fenster-Kontakten, die für die Zuluft geöffnet sein können.
* **OvenDeltaTemp**: Temperatur-Delta ab dem der Ofen als "An" gewertet wird.
* **PeakDropThreshold**: Temperaturabfall ab Peak, ab dem zum Nachlegen geraten wird.
* **MaxRoomTemp**: Raumtemperatur, ab der kein weiteres Nachlegen empfohlen wird.
* **DoorAlarmTime**: Zeit in Sekunden, nach der ein Alarm ausgelöst wird, wenn die Ofentür offen steht, während der Ofen an ist.
* **ActuatorHood**: Schaltsteckdose der Dunstabzugshaube, die bei Bedarf gesperrt oder freigegeben wird.

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| CurrentDeltaTemp | Aktuelle Temperatur-Differenz | Float | Die aktuelle Temperatur-Differenz zwischen Kamin und Raum. |
| CurrentDoorStatus | Status Ofentür | Boolean | Zeigt an, ob die Ofentür offen oder geschlossen ist. |
| OvenStatus | Status Kaminofen | Boolean | Zeigt an, ob der Ofen heizt. |
| HoodStatus | Status Dunstabzugshaube | Boolean | Zeigt an, ob die Dunstabzugshaube gesperrt oder freigegeben ist. |
| AlarmOvenDoor | Alarm Ofentür | Boolean | Alarm, der ausgelöst wird, wenn die Ofentür zu lange offen steht (quittierbar). |
| OvenPeakTemp | Letzte Spitzen-Temperatur | Float | Speichert die bisherige Höchsttemperatur in diesem Heizzyklus. |
| WoodRefillNeeded | Bitte Holz nachlegen | Boolean | Gibt an, ob Holz nachgelegt werden sollte. |

### 6. PHP-Befehlsreferenz

```php
FS_TriggerDoorAlarm(int $InstanceID);
```
Löst den Ofentür-Alarm manuell aus.

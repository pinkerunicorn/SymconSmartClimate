# GardenHouseClimate

Sorgt für ein konstantes Klima im Gartenhaus und schützt vor Frost, inklusive Überwachung der Heizung auf Defekte.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Winterbetrieb schaltbar (Frostschutz).
* Zieltemperatur mit Schalthysterese einstellbar.
* Erhöht die Zieltemperatur automatisch bei starkem Frost (-5 °C oder kälter) um 1 °C.
* Pausiert die Heizung bei geöffnetem Fenster/geöffneter Tür.
* Überwacht die Heizung auf Defekte (zu geringer Stromverbrauch bei eingeschalteter Steckdose) und warnt.
* Warnt bei zu lang geöffnetem Fenster im Winterbetrieb.
* Löst Alarm bei kritischem Frost aus.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0

### 3. Installation

* Über den Module Store das Modul `GardenHouseClimate` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartClimate`

### 4. Konfiguration

* **SensorTempInside**: Temperatur Gartenhaus.
* **SensorTempOutside**: Temperatur Außen.
* **SensorWindows**: Liste von Fenster-/Türkontakten im Gartenhaus (inklusive Wert für 'geschlossen').
* **ActuatorHeaterPlug**: Schaltsteckdose für die Heizung.
* **SensorHeaterPower**: Leistungsmessung der Heizung (Watt).
* **Hysteresis**: Schalthysterese in °C.
* **HeaterPowerThreshold**: Erwarteter Mindest-Verbrauch bei AN in Watt (Darunter wird auf Defekt geschlossen).
* **HeaterDefectTime**: Zeit in Sekunden bis zum Defekt-Alarm.
* **WindowOpenTime**: Zeit in Sekunden bis zum "Fenster-offen"-Alarm im Winter.
* **FrostWarningTemp**: Temperatur in °C für kritischen Frost-Alarm.

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| WinterMode | Winterbetrieb | Boolean | Schaltet den Frostschutz an oder aus. |
| TargetTemperature | Zieltemperatur Frostschutz | Float | Zieltemperatur für das Gartenhaus. |
| HeaterStatus | Status Heizung | Integer | Aktueller Status: 0=Aus, 1=Heizen, 2=Pausiert (Fenster offen). |
| AlarmHeaterDefect | Alarm: Heizung defekt | Boolean | Wird ausgelöst, wenn die Steckdose an ist, aber zu wenig Leistung bezogen wird. |
| AlarmFrost | Alarm: Kritischer Frost | Boolean | Wird ausgelöst, wenn die Innentemperatur auf die kritische Temperatur fällt. |
| AlarmWindowOpen | Alarm: Fenster offen (Winter) | Boolean | Wird ausgelöst, wenn das Fenster zu lange offen steht. |

### 6. PHP-Befehlsreferenz

```php
GHC_TriggerHeaterDefectAlarm(int $InstanceID);
```
Löst manuell den Defekt-Alarm aus (z.B. vom Timer aufgerufen).

```php
GHC_TriggerWindowOpenAlarm(int $InstanceID);
```
Löst manuell den Fenster-offen-Alarm aus (z.B. vom Timer aufgerufen).

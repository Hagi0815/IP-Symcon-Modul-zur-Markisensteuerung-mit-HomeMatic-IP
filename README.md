# Markisensteuerung mit HomeMatic IP für IP-Symcon

Dieses Modul steuert eine Markise abhängig von Regen, Wind und Tageszeit. Es unterstützt HomeMatic IP Aktoren wie HmIP-BROLL oder HmIP-FROLL sowie einfache Boolean-Aktoren.

## Funktionen
- Automatisches Ein-/Ausfahren der Markise je nach Sensorzustand
- Unterstützung von Level-Aktoren (BROLL/FROLL) und Boolean-Aktoren
- Konfigurierbare Betriebs-Zeiten (Start- und Endzeit)
- Konfigurierbarer Windgeschwindigkeits-Schwellenwert
- Manuelle Steuerung über WebFront (Toggle "Manuell ausfahren")
- Aktivierung/Deaktivierung der Automatik über WebFront
- Detaillierte Anzeige der letzten Aktion inkl. Begründung
- Zeitstempel der letzten Prüfung
- Vollständiges Logging in die IP-Symcon Konsole (Info, Warnung, Fehler)
- Timer-Prüfung alle 5 Minuten (IPS 5.x / 6.x / 8.x kompatibel)

## Eigenschaften (Konfiguration)

| Eigenschaft         | Typ     | Standard  | Beschreibung                              |
|---------------------|---------|-----------|-------------------------------------------|
| RainSensorID        | Integer | 0         | Objekt-ID der Regen-Boolean-Variable      |
| WindSensorID        | Integer | 0         | Objekt-ID der Windgeschwindigkeits-Variable |
| ActorID             | Integer | 0         | Objekt-ID des Aktor-Datenpunkts           |
| ActorType           | String  | `level`   | Aktortyp: `level` (BROLL/FROLL) oder `bool` |
| StartTime           | String  | `08:00`   | Früheste Uhrzeit zum Ausfahren            |
| EndTime             | String  | `20:00`   | Späteste Uhrzeit zum Ausfahren            |
| WindSpeedThreshold  | Float   | `10.0`    | Windgeschwindigkeit in km/h zum Einfahren |

## Variablen (WebFront)

| Variable       | Typ     | Beschreibung                          |
|----------------|---------|---------------------------------------|
| AutoActive     | Boolean | Automatik aktivieren/deaktivieren     |
| ManualDrive    | Boolean | Markise manuell aus-/einfahren        |
| LastAction     | String  | Letzte ausgeführte Aktion mit Grund   |
| LastCheck      | String  | Zeitstempel der letzten Prüfung       |

## Installation
1. Repository in IP-Symcon Modulsteuerung hinzufügen
2. Instanz „Markisensteuerung" erstellen
3. In der Konfiguration folgendes eintragen:
   - Regen-Sensor ID (Boolean-Variable, `true` = Regen)
   - Wind-Sensor ID (Float-Variable, Wert in km/h)
   - Aktor ID (LEVEL-Datenpunkt des BROLL/FROLL oder Boolean-Variable)
   - Aktortyp: `level` für BROLL/FROLL, `bool` für einfache Schalter
   - Start- und Endzeit im Format `HH:MM`
   - Windgeschwindigkeits-Schwellenwert in km/h
4. Änderungen übernehmen – der Timer startet automatisch

## Logik

Die Markise wird **eingefahren**, wenn mindestens eine der folgenden Bedingungen zutrifft:
- Regen-Sensor meldet Regen
- Windgeschwindigkeit überschreitet den konfigurierten Schwellenwert
- Aktuelle Uhrzeit liegt vor der Startzeit oder nach der Endzeit

Sind alle Bedingungen erfüllt (kein Regen, kein starker Wind, innerhalb der Betriebszeit), wird die Markise **ausgefahren**.

Die manuelle Steuerung über `ManualDrive` überschreibt den Automatikbetrieb direkt, ohne die Automatik zu deaktivieren.

## Kompatibilität
- IP-Symcon ab Version 5.0
- Getestet mit HmIP-BROLL und HmIP-FROLL
- Boolean-Aktoren werden ebenfalls unterstützt

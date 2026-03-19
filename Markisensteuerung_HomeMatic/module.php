<?php

/**
 * Markisensteuerung_HomeMatic
 *
 * Steuert eine Markise abhängig von Regen, Wind, Tageszeit
 * und zwei konfigurierbaren Zusatzsensoren.
 * Unterstützt HomeMatic IP Aktoren wie HmIP-BROLL oder HmIP-FROLL.
 *
 * @author  Christian Hagedorn
 * @version 1.6
 */
class Markisensteuerung_HomeMatic extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // --- Sensor-Eigenschaften ---
        $this->RegisterPropertyInteger('RainSensorID', 0);
        $this->RegisterPropertyInteger('WindSensorID', 0);

        // --- Zusatzsensor 1 ---
        $this->RegisterPropertyInteger('ExtraSensor1ID', 0);
        $this->RegisterPropertyString('ExtraSensor1Type', 'temperature');
        $this->RegisterPropertyInteger('ExtraSensor1ThresholdDefault', 35);

        // --- Zusatzsensor 2 ---
        $this->RegisterPropertyInteger('ExtraSensor2ID', 0);
        $this->RegisterPropertyString('ExtraSensor2Type', 'brightness');
        $this->RegisterPropertyInteger('ExtraSensor2ThresholdDefault', 60000);

        // --- Aktor-Eigenschaften ---
        $this->RegisterPropertyInteger('ActorID', 0);
        $this->RegisterPropertyString('ActorType', 'level');

        // --- Standardwerte für WebFront-Variablen ---
        // Uhrzeiten als Minuten seit Mitternacht (z.B. 8*60 = 480 = 08:00)
        $this->RegisterPropertyInteger('StartMinDefault', 480);  // 08:00
        $this->RegisterPropertyInteger('EndMinDefault', 1200);   // 20:00
        $this->RegisterPropertyInteger('WindThresholdDefault', 10);

        // --- Profile ZUERST anlegen ---
        $this->RegisterProfiles();

        // --- Steuerungsvariablen ---
        $this->RegisterVariableBoolean('AutoActive', 'Automatik aktiv', '~Switch', 10);
        $this->EnableAction('AutoActive');

        $this->RegisterVariableBoolean('ManualDrive', 'Manuell ausfahren', '~Switch', 20);
        $this->EnableAction('ManualDrive');

        // --- WebFront: Zeiten ---
        $this->RegisterVariableInteger('StartMin', 'Ausfahren ab Uhrzeit', 'Markise.TimeQuarter', 30);
        $this->EnableAction('StartMin');

        $this->RegisterVariableInteger('EndMin', 'Einfahren ab Uhrzeit', 'Markise.TimeQuarter', 40);
        $this->EnableAction('EndMin');

        // --- WebFront: Wind ---
        $this->RegisterVariableInteger('WindThreshold', 'Windgrenze (km/h)', 'Markise.WindThreshold', 50);
        $this->EnableAction('WindThreshold');

        // --- WebFront: Zusatzsensor 1 ---
        $this->RegisterVariableString('Extra1Type', 'Zusatz 1 – Typ', 'Markise.SensorType', 60);
        $this->EnableAction('Extra1Type');

        $this->RegisterVariableInteger('Extra1Threshold', 'Zusatz 1 – Schwellwert', 'Markise.Extra1Threshold', 70);
        $this->EnableAction('Extra1Threshold');

        // --- WebFront: Zusatzsensor 2 ---
        $this->RegisterVariableString('Extra2Type', 'Zusatz 2 – Typ', 'Markise.SensorType', 80);
        $this->EnableAction('Extra2Type');

        $this->RegisterVariableInteger('Extra2Threshold', 'Zusatz 2 – Schwellwert', 'Markise.Extra2Threshold', 90);
        $this->EnableAction('Extra2Threshold');

        // --- Statusvariablen ---
        $this->RegisterVariableString('LastAction', 'Letzte Aktion', '', 100);
        $this->RegisterVariableString('LastCheck', 'Letzte Prüfung', '', 110);

        // --- Timer ---
        $this->RegisterTimer('CheckTimer', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "Check", "");');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();

        // Standardwerte setzen beim ersten Start
        if ($this->GetValue('StartMin') == 0) {
            $this->SetValue('StartMin', $this->ReadPropertyInteger('StartMinDefault'));
        }
        if ($this->GetValue('EndMin') == 0) {
            $this->SetValue('EndMin', $this->ReadPropertyInteger('EndMinDefault'));
        }
        if ($this->GetValue('WindThreshold') == 0) {
            $this->SetValue('WindThreshold', $this->ReadPropertyInteger('WindThresholdDefault'));
        }
        if ($this->GetValue('Extra1Type') === '') {
            $this->SetValue('Extra1Type', $this->ReadPropertyString('ExtraSensor1Type'));
        }
        if ($this->GetValue('Extra1Threshold') == 0) {
            $this->SetValue('Extra1Threshold', $this->ReadPropertyInteger('ExtraSensor1ThresholdDefault'));
            $this->UpdateExtra1Profile();
        }
        if ($this->GetValue('Extra2Type') === '') {
            $this->SetValue('Extra2Type', $this->ReadPropertyString('ExtraSensor2Type'));
        }
        if ($this->GetValue('Extra2Threshold') == 0) {
            $this->SetValue('Extra2Threshold', $this->ReadPropertyInteger('ExtraSensor2ThresholdDefault'));
            $this->UpdateExtra2Profile();
        }

        $this->RegisterActionScripts();
        $this->SetTimerInterval('CheckTimer', 300000);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'AutoActive':
                $this->SetValue('AutoActive', $Value);
                $this->LogMessage('Automatik ' . ($Value ? 'aktiviert' : 'deaktiviert'), KL_MESSAGE);
                break;

            case 'ManualDrive':
                $this->SetValue('ManualDrive', $Value);
                $this->SetActorPosition($this->ReadPropertyInteger('ActorID'), $Value ? 100 : 0);
                $this->SetValue('LastAction', 'Manuell ' . ($Value ? 'ausgefahren' : 'eingefahren'));
                break;

            case 'StartMin':
                $this->SetValue('StartMin', (int) $Value);
                $this->LogMessage('Ausfahrzeit geändert auf ' . $this->MinToTime((int) $Value), KL_MESSAGE);
                break;

            case 'EndMin':
                $this->SetValue('EndMin', (int) $Value);
                $this->LogMessage('Einfahrzeit geändert auf ' . $this->MinToTime((int) $Value), KL_MESSAGE);
                break;

            case 'WindThreshold':
                $this->SetValue('WindThreshold', (int) $Value);
                $this->LogMessage('Windgrenze geändert auf ' . (int) $Value . ' km/h', KL_MESSAGE);
                break;

            case 'Extra1Type':
                $this->SetValue('Extra1Type', (string) $Value);
                $this->UpdateExtra1Profile();
                $this->LogMessage('Zusatz 1 Typ geändert auf ' . $Value, KL_MESSAGE);
                break;

            case 'Extra1Threshold':
                $this->SetValue('Extra1Threshold', (int) $Value);
                $this->LogMessage('Zusatz 1 Schwellwert geändert auf ' . (int) $Value, KL_MESSAGE);
                break;

            case 'Extra2Type':
                $this->SetValue('Extra2Type', (string) $Value);
                $this->UpdateExtra2Profile();
                $this->LogMessage('Zusatz 2 Typ geändert auf ' . $Value, KL_MESSAGE);
                break;

            case 'Extra2Threshold':
                $this->SetValue('Extra2Threshold', (int) $Value);
                $this->LogMessage('Zusatz 2 Schwellwert geändert auf ' . (int) $Value, KL_MESSAGE);
                break;

            case 'Check':
                $this->Check();
                break;
        }
    }

    public function Check()
    {
        if (!$this->GetValue('AutoActive')) {
            $this->SetValue('LastAction', 'Automatik deaktiviert – keine Aktion');
            return;
        }
        if ($this->GetValue('ManualDrive')) {
            $this->SetValue('LastAction', 'Manuelle Steuerung aktiv – übersprungen');
            return;
        }

        $rainSensorID  = $this->ReadPropertyInteger('RainSensorID');
        $windSensorID  = $this->ReadPropertyInteger('WindSensorID');
        $actorID       = $this->ReadPropertyInteger('ActorID');

        $windThreshold = $this->GetValue('WindThreshold');
        $startMin      = $this->GetValue('StartMin');
        $endMin        = $this->GetValue('EndMin');

        $rain = ($rainSensorID > 0 && IPS_VariableExists($rainSensorID))
            ? (bool) GetValue($rainSensorID) : false;

        $wind = ($windSensorID > 0 && IPS_VariableExists($windSensorID))
            ? (float) GetValue($windSensorID) : 0.0;

        // Aktuelle Zeit als Minuten seit Mitternacht
        $nowMin = (int) date('H') * 60 + (int) date('i');

        $retractReasons = [];

        if ($rain) {
            $retractReasons[] = 'Regen erkannt';
        }
        if ($wind > $windThreshold) {
            $retractReasons[] = sprintf('Wind %.1f > %d km/h', $wind, $windThreshold);
        }
        if ($nowMin < $startMin) {
            $retractReasons[] = 'Vor Ausfahrzeit (' . $this->MinToTime($startMin) . ')';
        }
        if ($nowMin >= $endMin) {
            $retractReasons[] = 'Ab Einfahrzeit (' . $this->MinToTime($endMin) . ')';
        }

        // Zusatzsensoren prüfen
        foreach ([1, 2] as $n) {
            $sensorID  = $this->ReadPropertyInteger('ExtraSensor' . $n . 'ID');
            $type      = $this->GetValue('Extra' . $n . 'Type');
            $threshold = $this->GetValue('Extra' . $n . 'Threshold');

            if ($sensorID > 0 && IPS_VariableExists($sensorID) && $type !== 'off') {
                if ($type === 'boolean') {
                    // Boolean: einfahren wenn Sensor true
                    if ((bool) GetValue($sensorID)) {
                        $retractReasons[] = $this->SensorTypeLabel($type) . ' aktiv';
                    }
                } else {
                    $val = (float) GetValue($sensorID);
                    if ($val >= $threshold) {
                        $label = $this->SensorTypeLabel($type);
                        $retractReasons[] = sprintf('%s %.1f >= %d', $label, $val, $threshold);
                    }
                }
            }
        }

        $timestamp = date('d.m.Y H:i:s');
        $this->SetValue('LastCheck', $timestamp);

        if (!empty($retractReasons)) {
            $reason = implode(', ', $retractReasons);
            $this->SetValue('LastAction', 'Eingefahren: ' . $reason);
            $this->LogMessage('Markise einfahren – ' . $reason, KL_MESSAGE);
            $this->SetActorPosition($actorID, 0);
        } else {
            $this->SetValue('LastAction', 'Ausgefahren: Alle Bedingungen erfüllt');
            $this->LogMessage('Markise ausfahren – alle Bedingungen OK', KL_MESSAGE);
            $this->SetActorPosition($actorID, 100);
        }
    }

    // -------------------------------------------------------------------------
    // Private Hilfsmethoden
    // -------------------------------------------------------------------------

    private function RegisterProfiles()
    {
        // Uhrzeitprofil: viertelstündlich, gespeichert als Minuten seit Mitternacht
        if (!IPS_VariableProfileExists('Markise.TimeQuarter')) {
            IPS_CreateVariableProfile('Markise.TimeQuarter', 1);
            IPS_SetVariableProfileIcon('Markise.TimeQuarter', 'Clock');
            for ($h = 0; $h <= 23; $h++) {
                foreach ([0, 15, 30, 45] as $m) {
                    $totalMin = $h * 60 + $m;
                    $label    = sprintf('%02d:%02d Uhr', $h, $m);
                    IPS_SetVariableProfileAssociation('Markise.TimeQuarter', $totalMin, $label, '', -1);
                }
            }
        }

        // Windprofil
        if (!IPS_VariableProfileExists('Markise.WindThreshold')) {
            IPS_CreateVariableProfile('Markise.WindThreshold', 1);
            IPS_SetVariableProfileIcon('Markise.WindThreshold', 'Wind');
            foreach ([5, 10, 15, 20, 25, 30, 40, 50, 60] as $v) {
                IPS_SetVariableProfileAssociation('Markise.WindThreshold', $v, $v . ' km/h', '', -1);
            }
        }

        // Sensortyp-Dropdown
        if (!IPS_VariableProfileExists('Markise.SensorType')) {
            IPS_CreateVariableProfile('Markise.SensorType', 3); // 3 = String
            IPS_SetVariableProfileIcon('Markise.SensorType', 'Information');
            IPS_SetVariableProfileAssociation('Markise.SensorType', 'off',         'Deaktiviert',       '', -1);
            IPS_SetVariableProfileAssociation('Markise.SensorType', 'temperature', 'Temperatur (°C)',    '', -1);
            IPS_SetVariableProfileAssociation('Markise.SensorType', 'brightness',  'Helligkeit (Lux)',  '', -1);
            IPS_SetVariableProfileAssociation('Markise.SensorType', 'uv',          'UV-Index',          '', -1);
            IPS_SetVariableProfileAssociation('Markise.SensorType', 'boolean',     'Boolean (true = einfahren)', '', -1);
        }

        // Schwellwert-Profile für Zusatzsensoren
        $this->EnsureExtra1Profile($this->GetValue('Extra1Type'));
        $this->EnsureExtra2Profile($this->GetValue('Extra2Type'));
    }

    /** Erstellt/aktualisiert das Schwellwert-Profil für Zusatzsensor 1 */
    private function UpdateExtra1Profile()
    {
        $type = $this->GetValue('Extra1Type');
        $this->EnsureExtra1Profile($type);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('Extra1Threshold'), $this->ThresholdProfileName($type));
    }

    /** Erstellt/aktualisiert das Schwellwert-Profil für Zusatzsensor 2 */
    private function UpdateExtra2Profile()
    {
        $type = $this->GetValue('Extra2Type');
        $this->EnsureExtra2Profile($type);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('Extra2Threshold'), $this->ThresholdProfileName($type));
    }

    private function EnsureExtra1Profile(string $type)
    {
        $this->EnsureThresholdProfile($type);
    }

    private function EnsureExtra2Profile(string $type)
    {
        $this->EnsureThresholdProfile($type);
    }

    /**
     * Legt ein typspezifisches Schwellwert-Profil an, falls noch nicht vorhanden.
     */
    private function EnsureThresholdProfile(string $type)
    {
        $name = $this->ThresholdProfileName($type);
        if (IPS_VariableProfileExists($name)) {
            return;
        }

        IPS_CreateVariableProfile($name, 1); // Integer

        switch ($type) {
            case 'temperature':
                IPS_SetVariableProfileIcon($name, 'Temperature');
                foreach (range(20, 50, 5) as $v) {
                    IPS_SetVariableProfileAssociation($name, $v, $v . ' °C', '', -1);
                }
                break;

            case 'brightness':
                IPS_SetVariableProfileIcon($name, 'Light');
                foreach ([1000, 5000, 10000, 20000, 40000, 60000, 80000, 100000] as $v) {
                    $label = $v >= 1000 ? ($v / 1000) . ' kLux' : $v . ' Lux';
                    IPS_SetVariableProfileAssociation($name, $v, $label, '', -1);
                }
                break;

            case 'uv':
                IPS_SetVariableProfileIcon($name, 'Sun');
                foreach (range(1, 11, 1) as $v) {
                    IPS_SetVariableProfileAssociation($name, $v, 'UV ' . $v, '', -1);
                }
                break;

            case 'boolean':
                // Boolean: kein Schwellwert nötig – Profil als Platzhalter
                IPS_SetVariableProfileIcon($name, 'Information');
                IPS_SetVariableProfileAssociation($name, 0, 'Bei true einfahren', '', -1);
                break;

            default:
                // 'off' – leeres Profil
                IPS_SetVariableProfileIcon($name, 'Information');
                IPS_SetVariableProfileAssociation($name, 0, 'Deaktiviert', '', -1);
                break;
        }
    }

    private function ThresholdProfileName(string $type): string
    {
        $map = [
            'temperature' => 'Markise.Threshold.Temperature',
            'brightness'  => 'Markise.Threshold.Brightness',
            'uv'          => 'Markise.Threshold.UV',
            'boolean'     => 'Markise.Threshold.Boolean',
            'off'         => 'Markise.Threshold.Off',
        ];
        return $map[$type] ?? 'Markise.Threshold.Off';
    }

    private function SensorTypeLabel(string $type): string
    {
        $map = [
            'temperature' => 'Temperatur',
            'brightness'  => 'Helligkeit',
            'uv'          => 'UV-Index',
            'boolean'     => 'Boolean-Sensor',
        ];
        return $map[$type] ?? $type;
    }

    private function MinToTime(int $minutes): string
    {
        return sprintf('%02d:%02d Uhr', intdiv($minutes, 60), $minutes % 60);
    }

    private function RegisterActionScripts()
    {
        $instanceID = $this->InstanceID;
        $parentID   = IPS_GetObject($instanceID)['ParentID'];

        $scripts = [
            'Markise – Ausfahrzeit setzen' => '<?php
// Ausfahrzeit setzen (Minuten seit Mitternacht, viertelstündlich)
// Beispiele: 480 = 08:00, 495 = 08:15, 510 = 08:30, 525 = 08:45
$minuten = 480;
IPS_RequestAction(' . $instanceID . ', "StartMin", $minuten);
',
            'Markise – Einfahrzeit setzen' => '<?php
// Einfahrzeit setzen (Minuten seit Mitternacht, viertelstündlich)
// Beispiele: 1200 = 20:00, 1215 = 20:15, 1170 = 19:30
$minuten = 1200;
IPS_RequestAction(' . $instanceID . ', "EndMin", $minuten);
',
            'Markise – Windgrenze setzen' => '<?php
// Windgrenze setzen (km/h)
// Erlaubte Werte: 5, 10, 15, 20, 25, 30, 40, 50, 60
$grenze = 10;
IPS_RequestAction(' . $instanceID . ', "WindThreshold", $grenze);
',
            'Markise – Zusatz 1 konfigurieren' => '<?php
// Zusatzsensor 1: Typ und Schwellwert setzen
// Typen: "off", "temperature", "brightness", "uv"
$typ       = "temperature";
$schwelle  = 35;
IPS_RequestAction(' . $instanceID . ', "Extra1Type", $typ);
IPS_RequestAction(' . $instanceID . ', "Extra1Threshold", $schwelle);
',
            'Markise – Zusatz 2 konfigurieren' => '<?php
// Zusatzsensor 2: Typ und Schwellwert setzen
// Typen: "off", "temperature", "brightness", "uv"
$typ       = "brightness";
$schwelle  = 60000;
IPS_RequestAction(' . $instanceID . ', "Extra2Type", $typ);
IPS_RequestAction(' . $instanceID . ', "Extra2Threshold", $schwelle);
',
        ];

        foreach ($scripts as $caption => $code) {
            $existingID = 0;
            foreach (IPS_GetChildrenIDs($parentID) as $childID) {
                if (IPS_GetObject($childID)['ObjectType'] === 3
                    && IPS_GetObject($childID)['ObjectName'] === $caption) {
                    $existingID = $childID;
                    break;
                }
            }
            if ($existingID === 0) {
                $scriptID = IPS_CreateScript(0);
                IPS_SetName($scriptID, $caption);
                IPS_SetParent($scriptID, $parentID);
                IPS_SetScriptContent($scriptID, $code);
            }
        }
    }

    private function SetActorPosition(int $actorID, int $position)
    {
        if ($actorID === 0) {
            $this->LogMessage('Kein Aktor konfiguriert.', KL_WARNING);
            return;
        }
        if (!IPS_VariableExists($actorID)) {
            $this->LogMessage('Aktor-Variable ID ' . $actorID . ' existiert nicht.', KL_ERROR);
            return;
        }
        $actorType = $this->ReadPropertyString('ActorType');
        try {
            if ($actorType === 'bool') {
                RequestAction($actorID, $position > 0);
            } else {
                $level      = $position / 100.0;
                $instanceID = IPS_GetParent($actorID);
                if ($instanceID > 0 && IPS_InstanceExists($instanceID)) {
                    HM_WriteValueFloat($instanceID, 'LEVEL', $level);
                } else {
                    RequestAction($actorID, $level);
                }
            }
        } catch (Exception $e) {
            $this->LogMessage('Fehler beim Schalten des Aktors: ' . $e->getMessage(), KL_ERROR);
        }
    }
}

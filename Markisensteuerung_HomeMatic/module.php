<?php

/**
 * Markisensteuerung_HomeMatic
 *
 * Steuert eine Markise abhängig von Regen, Wind und Tageszeit.
 * Unterstützt HomeMatic IP Aktoren wie HmIP-BROLL oder HmIP-FROLL.
 *
 * Ausfahrzeit morgens, Einfahrzeit abends und Windgrenze
 * sind als Auswahlprofile direkt im WebFront einstellbar.
 * Für jede Variable wird automatisch ein Aktionsskript angelegt.
 *
 * @author  Christian Hagedorn
 * @version 1.5
 */
class Markisensteuerung_HomeMatic extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // --- Sensor-Eigenschaften ---
        $this->RegisterPropertyInteger('RainSensorID', 0);
        $this->RegisterPropertyInteger('WindSensorID', 0);

        // --- Aktor-Eigenschaften ---
        $this->RegisterPropertyInteger('ActorID', 0);
        $this->RegisterPropertyString('ActorType', 'level');

        // --- Standardwerte für WebFront-Variablen ---
        $this->RegisterPropertyInteger('StartHourDefault', 8);
        $this->RegisterPropertyInteger('EndHourDefault', 20);
        $this->RegisterPropertyInteger('WindThresholdDefault', 10);

        // --- Profile ZUERST anlegen ---
        $this->RegisterProfiles();

        // --- Steuerungsvariablen ---
        $this->RegisterVariableBoolean('AutoActive', 'Automatik aktiv', '~Switch', 10);
        $this->EnableAction('AutoActive');

        $this->RegisterVariableBoolean('ManualDrive', 'Manuell ausfahren', '~Switch', 20);
        $this->EnableAction('ManualDrive');

        // --- WebFront-Auswahlvariablen ---
        $this->RegisterVariableInteger('StartHour', 'Ausfahren ab Uhrzeit', 'Markise.StartHour', 30);
        $this->EnableAction('StartHour');

        $this->RegisterVariableInteger('EndHour', 'Einfahren ab Uhrzeit', 'Markise.EndHour', 40);
        $this->EnableAction('EndHour');

        $this->RegisterVariableInteger('WindThreshold', 'Windgrenze (km/h)', 'Markise.WindThreshold', 50);
        $this->EnableAction('WindThreshold');

        // --- Statusvariablen ---
        $this->RegisterVariableString('LastAction', 'Letzte Aktion', '', 60);
        $this->RegisterVariableString('LastCheck', 'Letzte Prüfung', '', 70);

        // --- Timer ---
        $this->RegisterTimer('CheckTimer', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "Check", "");');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Profile sicherstellen
        $this->RegisterProfiles();

        // Standardwerte setzen beim ersten Start
        if ($this->GetValue('StartHour') == 0) {
            $this->SetValue('StartHour', $this->ReadPropertyInteger('StartHourDefault'));
        }
        if ($this->GetValue('EndHour') == 0) {
            $this->SetValue('EndHour', $this->ReadPropertyInteger('EndHourDefault'));
        }
        if ($this->GetValue('WindThreshold') == 0) {
            $this->SetValue('WindThreshold', $this->ReadPropertyInteger('WindThresholdDefault'));
        }

        // Aktionsskripte anlegen
        $this->RegisterActionScripts();

        $this->SetTimerInterval('CheckTimer', 300000);
    }

    /**
     * Wird aufgerufen wenn der Nutzer Variablen im WebFront ändert.
     */
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

            case 'StartHour':
                $this->SetValue('StartHour', (int) $Value);
                $this->LogMessage('Ausfahrzeit geändert auf ' . (int) $Value . ':00 Uhr', KL_MESSAGE);
                break;

            case 'EndHour':
                $this->SetValue('EndHour', (int) $Value);
                $this->LogMessage('Einfahrzeit geändert auf ' . (int) $Value . ':00 Uhr', KL_MESSAGE);
                break;

            case 'WindThreshold':
                $this->SetValue('WindThreshold', (int) $Value);
                $this->LogMessage('Windgrenze geändert auf ' . (int) $Value . ' km/h', KL_MESSAGE);
                break;

            case 'Check':
                $this->Check();
                break;
        }
    }

    /**
     * Prüft Sensoren und steuert die Markise.
     */
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

        $windThreshold = (int) $this->GetValue('WindThreshold');
        $startTime     = sprintf('%02d:00', $this->GetValue('StartHour'));
        $endTime       = sprintf('%02d:00', $this->GetValue('EndHour'));

        $rain = ($rainSensorID > 0 && IPS_VariableExists($rainSensorID))
            ? (bool) GetValue($rainSensorID) : false;

        $wind = ($windSensorID > 0 && IPS_VariableExists($windSensorID))
            ? (float) GetValue($windSensorID) : 0.0;

        $now = date('H:i');

        $retractReasons = [];
        if ($rain) {
            $retractReasons[] = 'Regen erkannt';
        }
        if ($wind > $windThreshold) {
            $retractReasons[] = sprintf('Wind %.1f km/h > Grenze %.1f km/h', $wind, $windThreshold);
        }
        if ($now < $startTime) {
            $retractReasons[] = 'Vor Ausfahrzeit (' . $startTime . ' Uhr)';
        }
        if ($now >= $endTime) {
            $retractReasons[] = 'Ab Einfahrzeit (' . $endTime . ' Uhr)';
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

    /**
     * Legt alle Variablenprofile als Auswahloptionen an.
     */
    private function RegisterProfiles()
    {
        // Profil: Ausfahrzeit morgens (5–12 Uhr)
        if (!IPS_VariableProfileExists('Markise.StartHour')) {
            IPS_CreateVariableProfile('Markise.StartHour', 1);
            IPS_SetVariableProfileIcon('Markise.StartHour', 'Sun');
            foreach ([5, 6, 7, 8, 9, 10, 11, 12] as $h) {
                IPS_SetVariableProfileAssociation('Markise.StartHour', $h, $h . ':00 Uhr', '', -1);
            }
        }

        // Profil: Einfahrzeit abends (15–22 Uhr)
        if (!IPS_VariableProfileExists('Markise.EndHour')) {
            IPS_CreateVariableProfile('Markise.EndHour', 1);
            IPS_SetVariableProfileIcon('Markise.EndHour', 'Moon');
            foreach ([15, 16, 17, 18, 19, 20, 21, 22] as $h) {
                IPS_SetVariableProfileAssociation('Markise.EndHour', $h, $h . ':00 Uhr', '', -1);
            }
        }

        // Profil: Windgrenze als Integer mit km/h Suffix
        if (!IPS_VariableProfileExists('Markise.WindThreshold')) {
            IPS_CreateVariableProfile('Markise.WindThreshold', 1); // 1 = Integer
            IPS_SetVariableProfileIcon('Markise.WindThreshold', 'Wind');
            IPS_SetVariableProfileText('Markise.WindThreshold', '', ' km/h');
            foreach ([5, 10, 15, 20, 25, 30, 40, 50, 60] as $v) {
                IPS_SetVariableProfileAssociation('Markise.WindThreshold', $v, $v . ' km/h', '', -1);
            }
        }
    }

    /**
     * Legt Aktionsskripte für die drei WebFront-Variablen an,
     * sofern noch nicht vorhanden. Die Skripte liegen als
     * Geschwister-Objekte direkt unter der Instanz.
     */
    private function RegisterActionScripts()
    {
        $instanceID = $this->InstanceID;
        $parentID   = IPS_GetObject($instanceID)['ParentID'];

        $scripts = [
            'Markise_Set_StartHour' => [
                'caption' => 'Markise – Ausfahrzeit setzen',
                'code'    => '<?php
// Ausfahrzeit für Markise setzen
// Erlaubte Werte: 5, 6, 7, 8, 9, 10, 11, 12
$stunde = 8; // gewünschte Stunde hier eintragen
IPS_RequestAction(' . $instanceID . ', "StartHour", $stunde);
',
            ],
            'Markise_Set_EndHour' => [
                'caption' => 'Markise – Einfahrzeit setzen',
                'code'    => '<?php
// Einfahrzeit für Markise setzen
// Erlaubte Werte: 15, 16, 17, 18, 19, 20, 21, 22
$stunde = 20; // gewünschte Stunde hier eintragen
IPS_RequestAction(' . $instanceID . ', "EndHour", $stunde);
',
            ],
            'Markise_Set_WindThreshold' => [
                'caption' => 'Markise – Windgrenze setzen',
                'code'    => '<?php
// Windgrenze für Markise setzen (km/h)
// Erlaubte Werte: 5, 10, 15, 20, 25, 30, 40, 50, 60
$grenze = 10; // gewünschten Wert hier eintragen
IPS_RequestAction(' . $instanceID . ', "WindThreshold", $grenze);
',
            ],
        ];

        foreach ($scripts as $name => $cfg) {
            // Prüfen ob ein Skript mit diesem Namen bereits unter dem Parent existiert
            $existingID = 0;
            foreach (IPS_GetChildrenIDs($parentID) as $childID) {
                if (IPS_GetObject($childID)['ObjectType'] === 3) { // 3 = Skript
                    if (IPS_GetObject($childID)['ObjectName'] === $cfg['caption']) {
                        $existingID = $childID;
                        break;
                    }
                }
            }

            if ($existingID === 0) {
                $scriptID = IPS_CreateScript(0); // 0 = PHP
                IPS_SetName($scriptID, $cfg['caption']);
                IPS_SetParent($scriptID, $parentID);
                IPS_SetScriptContent($scriptID, $cfg['code']);
            }
        }
    }

    /**
     * Setzt die Aktorposition (Level oder Boolean).
     */
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

<?php

/**
 * Markisensteuerung_HomeMatic
 *
 * Steuert eine Markise abhängig von Regen, Wind und Tageszeit.
 * Unterstützt HomeMatic IP Aktoren wie HmIP-BROLL oder HmIP-FROLL.
 *
 * Windgrenze, Ausfahrzeit morgens und Einfahrzeit abends
 * sind direkt im WebFront einstellbar.
 *
 * @author  Christian Hagedorn
 * @version 1.4
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
        $this->RegisterPropertyString('ActorType', 'level'); // 'level' oder 'bool'

        // --- Standardwerte für WebFront-Variablen ---
        $this->RegisterPropertyInteger('StartHourDefault', 8);
        $this->RegisterPropertyInteger('EndHourDefault', 20);
        $this->RegisterPropertyFloat('WindThresholdDefault', 10.0);

        // --- Profile ZUERST anlegen ---
        $this->RegisterProfiles();

        // --- Steuerungsvariablen ---
        $this->RegisterVariableBoolean('AutoActive', 'Automatik aktiv', '~Switch', 10);
        $this->EnableAction('AutoActive');

        $this->RegisterVariableBoolean('ManualDrive', 'Manuell ausfahren', '~Switch', 20);
        $this->EnableAction('ManualDrive');

        // --- Im WebFront einstellbare Variablen ---
        $this->RegisterVariableInteger('StartHour', 'Ausfahren ab Uhrzeit', 'Markise.Hour', 30);
        $this->EnableAction('StartHour');

        $this->RegisterVariableInteger('EndHour', 'Einfahren ab Uhrzeit', 'Markise.Hour', 40);
        $this->EnableAction('EndHour');

        $this->RegisterVariableFloat('WindThreshold', 'Windgrenze (km/h)', '', 50);
        $this->EnableAction('WindThreshold');

        // --- Statusvariablen ---
        $this->RegisterVariableString('LastAction', 'Letzte Aktion', '', 60);
        $this->RegisterVariableString('LastCheck', 'Letzte Prüfung', '', 70);

        // --- Timer (alle 5 Minuten) ---
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
        if ($this->GetValue('WindThreshold') == 0.0) {
            $this->SetValue('WindThreshold', $this->ReadPropertyFloat('WindThresholdDefault'));
        }

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
                $hour = max(0, min(23, (int) $Value));
                $this->SetValue('StartHour', $hour);
                $this->LogMessage('Ausfahrzeit geändert auf ' . $hour . ':00 Uhr', KL_MESSAGE);
                break;

            case 'EndHour':
                $hour = max(0, min(23, (int) $Value));
                $this->SetValue('EndHour', $hour);
                $this->LogMessage('Einfahrzeit geändert auf ' . $hour . ':00 Uhr', KL_MESSAGE);
                break;

            case 'WindThreshold':
                $value = max(0.0, min(200.0, (float) $Value));
                $this->SetValue('WindThreshold', $value);
                $this->LogMessage('Windgrenze geändert auf ' . $value . ' km/h', KL_MESSAGE);
                break;

            case 'Check':
                $this->Check();
                break;
        }
    }

    /**
     * Prüft Sensoren und steuert die Markise.
     * Wird vom Timer und vom „Jetzt prüfen"-Button aufgerufen.
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

        $rainSensorID = $this->ReadPropertyInteger('RainSensorID');
        $windSensorID = $this->ReadPropertyInteger('WindSensorID');
        $actorID      = $this->ReadPropertyInteger('ActorID');

        // Werte aus WebFront-Variablen lesen
        $windThreshold = $this->GetValue('WindThreshold');
        $startHour     = $this->GetValue('StartHour');
        $endHour       = $this->GetValue('EndHour');
        $startTime     = sprintf('%02d:00', $startHour);
        $endTime       = sprintf('%02d:00', $endHour);

        // Sensor-Werte lesen
        $rain = ($rainSensorID > 0 && IPS_VariableExists($rainSensorID))
            ? (bool) GetValue($rainSensorID)
            : false;

        $wind = ($windSensorID > 0 && IPS_VariableExists($windSensorID))
            ? (float) GetValue($windSensorID)
            : 0.0;

        $now = date('H:i');

        // Einfahrgründe sammeln
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
     * Legt alle benötigten Variablenprofile an.
     * Wird in Create() und ApplyChanges() aufgerufen.
     */
    private function RegisterProfiles()
    {
        if (!IPS_VariableProfileExists('Markise.Hour')) {
            IPS_CreateVariableProfile('Markise.Hour', 1); // 1 = Integer
            IPS_SetVariableProfileValues('Markise.Hour', 0, 23, 1);
            IPS_SetVariableProfileText('Markise.Hour', '', ':00 Uhr');
            IPS_SetVariableProfileIcon('Markise.Hour', 'Clock');
        }
    }

    /**
     * Setzt die Aktorposition (Level oder Boolean).
     *
     * @param int $actorID   Objekt-ID des Aktors
     * @param int $position  0 = einfahren, 100 = ausfahren
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

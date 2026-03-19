<?php

/**
 * Markisensteuerung_HomeMatic
 *
 * Steuert eine Markise abhängig von Regen, Wind und Tageszeit.
 * Unterstützt HomeMatic IP Aktoren wie HmIP-BROLL oder HmIP-FROLL.
 *
 * @author  Christian Hagedorn
 * @version 1.1
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

        // --- Zeit- und Schwellenwert-Eigenschaften ---
        $this->RegisterPropertyString('StartTime', '08:00');
        $this->RegisterPropertyString('EndTime', '20:00');
        $this->RegisterPropertyFloat('WindSpeedThreshold', 10.0);

        // --- Anzeige-Variablen ---
        $this->RegisterVariableBoolean('AutoActive', 'Automatik aktiv', '~Switch', 10);
        $this->EnableAction('AutoActive');

        $this->RegisterVariableBoolean('ManualDrive', 'Manuell ausfahren', '~Switch', 20);
        $this->EnableAction('ManualDrive');

        $this->RegisterVariableString('LastAction', 'Letzte Aktion', '', 30);
        $this->RegisterVariableString('LastCheck', 'Letzte Prüfung', '', 40);

        // --- Timer (IPS 5.x+ kompatibler Aufruf via RequestAction) ---
        $this->RegisterTimer('CheckTimer', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "Check", "");');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Timer aktivieren: 300.000 ms = 5 Minuten
        $this->SetTimerInterval('CheckTimer', 300000);
    }

    /**
     * Wird aufgerufen, wenn der Nutzer eine Aktionsvariable im WebFront bedient.
     */
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'AutoActive':
                $this->SetValue('AutoActive', $Value);
                $this->LogMessage(
                    'Automatik ' . ($Value ? 'aktiviert' : 'deaktiviert'),
                    KL_MESSAGE
                );
                break;

            case 'ManualDrive':
                $this->SetValue('ManualDrive', $Value);
                $this->SetActorPosition(
                    $this->ReadPropertyInteger('ActorID'),
                    $Value ? 100 : 0
                );
                $this->SetValue(
                    'LastAction',
                    'Manuell ' . ($Value ? 'ausgefahren' : 'eingefahren')
                );
                break;

            case 'Check':
                $this->Check();
                break;
        }
    }

    /**
     * Prüft Sensoren und steuert die Markise entsprechend.
     * Wird vom Timer und aus dem WebFront aufgerufen.
     */
    public function Check()
    {
        if (!$this->GetValue('AutoActive')) {
            $this->SetValue('LastAction', 'Automatik deaktiviert – keine Aktion');
            return;
        }

        $rainSensorID  = $this->ReadPropertyInteger('RainSensorID');
        $windSensorID  = $this->ReadPropertyInteger('WindSensorID');
        $actorID       = $this->ReadPropertyInteger('ActorID');
        $startTime     = $this->ReadPropertyString('StartTime');
        $endTime       = $this->ReadPropertyString('EndTime');
        $windThreshold = $this->ReadPropertyFloat('WindSpeedThreshold');

        // Sensor-Werte lesen (mit Existenzprüfung)
        $rain = ($rainSensorID > 0 && IPS_VariableExists($rainSensorID))
            ? (bool) GetValue($rainSensorID)
            : false;

        $wind = ($windSensorID > 0 && IPS_VariableExists($windSensorID))
            ? (float) GetValue($windSensorID)
            : 0.0;

        $now = date('H:i');

        // Gründe für Einfahren sammeln
        $retractReasons = [];
        if ($rain) {
            $retractReasons[] = 'Regen erkannt';
        }
        if ($wind > $windThreshold) {
            $retractReasons[] = sprintf('Wind %.1f km/h > Schwelle %.1f km/h', $wind, $windThreshold);
        }
        if ($now < $startTime) {
            $retractReasons[] = 'Vor Betriebszeit (' . $startTime . ')';
        }
        if ($now > $endTime) {
            $retractReasons[] = 'Nach Betriebszeit (' . $endTime . ')';
        }

        $timestamp = date('d.m.Y H:i:s');

        if (!empty($retractReasons)) {
            $reason = implode(', ', $retractReasons);
            $this->SetValue('LastAction', 'Eingefahren: ' . $reason);
            $this->SetValue('LastCheck', $timestamp);
            $this->LogMessage('Markise einfahren – ' . $reason, KL_MESSAGE);
            $this->SetActorPosition($actorID, 0);
        } else {
            $this->SetValue('LastAction', 'Ausgefahren: Bedingungen erfüllt');
            $this->SetValue('LastCheck', $timestamp);
            $this->LogMessage('Markise ausfahren – alle Bedingungen OK', KL_MESSAGE);
            $this->SetActorPosition($actorID, 100);
        }
    }

    /**
     * Setzt die Aktorposition.
     * Unterstützt HM Level-Aktoren (BROLL/FROLL) und einfache Boolean-Aktoren.
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
            $this->LogMessage(
                'Aktor-Variable ID ' . $actorID . ' existiert nicht.',
                KL_ERROR
            );
            return;
        }

        $actorType = $this->ReadPropertyString('ActorType');

        try {
            if ($actorType === 'bool') {
                // Boolean-Aktor (z.B. einfacher Relais-Schalter)
                RequestAction($actorID, $position > 0);
            } else {
                // Level-Aktor (HmIP-BROLL / HmIP-FROLL via LEVEL-Datenpunkt)
                $level      = $position / 100.0;
                $instanceID = IPS_GetParent($actorID);
                if ($instanceID > 0 && IPS_InstanceExists($instanceID)) {
                    HM_WriteValueFloat($instanceID, 'LEVEL', $level);
                } else {
                    // Fallback: direktes RequestAction auf die Variable
                    RequestAction($actorID, $level);
                }
            }
        } catch (Exception $e) {
            $this->LogMessage(
                'Fehler beim Schalten des Aktors: ' . $e->getMessage(),
                KL_ERROR
            );
        }
    }
}

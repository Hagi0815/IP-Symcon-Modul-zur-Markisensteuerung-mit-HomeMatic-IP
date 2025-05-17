<?php

class Markisensteuerung_HomeMatic extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger("RainSensorID", 0);
        $this->RegisterPropertyInteger("WindSensorID", 0);
        $this->RegisterPropertyInteger("ActorID", 0);
        $this->RegisterPropertyString("StartTime", "08:00");
        $this->RegisterPropertyString("EndTime", "20:00");
        $this->RegisterPropertyFloat("WindSpeedThreshold", 10.0);

        // Variables
        $this->RegisterVariableBoolean("AutoActive", "Automatik aktiv", "~Switch", 10);
        $this->RegisterVariableString("LastAction", "Letzte Aktion", "", 20);

        // Timer alle 5 Minuten
        $this->RegisterTimer("CheckTimer", 300000, 'Markisensteuerung_HomeMatic_Check($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval("CheckTimer", 300000);
    }

    public function Check()
    {
        if (!$this->GetValue("AutoActive")) {
            $this->SetValue("LastAction", "Automatik deaktiviert");
            return;
        }

        $rain = GetValue($this->ReadPropertyInteger("RainSensorID"));
        $wind = GetValue($this->ReadPropertyInteger("WindSensorID"));
        $actorID = $this->ReadPropertyInteger("ActorID");

        $startTime = $this->ReadPropertyString("StartTime");
        $endTime = $this->ReadPropertyString("EndTime");

        $windThreshold = $this->ReadPropertyFloat("WindSpeedThreshold");

        $now = date("H:i");

        if ($rain || $wind > $windThreshold || $now < $startTime || $now > $endTime) {
            // Markise einfahren
            $this->SetValue("LastAction", "Markise einfahren");
            $this->SetActorPosition($actorID, 0);
        } else {
            // Markise ausfahren
            $this->SetValue("LastAction", "Markise ausfahren");
            $this->SetActorPosition($actorID, 100);
        }
    }

    private function SetActorPosition($actorID, $position)
    {
        if ($actorID == 0) {
            return;
        }
        if (IPS_VariableExists($actorID)) {
            // Wenn Variable einen LEVEL hat
            HM_WriteValueFloat($actorID, "LEVEL", $position / 100);
        }
    }
}

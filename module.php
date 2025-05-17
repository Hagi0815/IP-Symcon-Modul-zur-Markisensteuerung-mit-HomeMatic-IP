<?php

declare(strict_types=1);

class Markisensteuerung_HomeMatic extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger("RegenSensorID", 0);
        $this->RegisterPropertyInteger("WindSensorID", 0);
        $this->RegisterPropertyInteger("AktorID", 0);
        $this->RegisterPropertyFloat("WindSchwelle", 30.0);
        $this->RegisterPropertyString("AusfahrzeitVon", "08:00");
        $this->RegisterPropertyString("AusfahrzeitBis", "20:00");
        $this->RegisterPropertyBoolean("AutomatikStandardwert", true);

        $this->RegisterVariableBoolean("AutomatikAktiv", "Automatik aktiv", "~Switch", 10);
        $this->EnableAction("AutomatikAktiv");

        $this->RegisterVariableBoolean("MarkiseAusgefahren", "Markise ausgefahren", "~Switch", 20);
        $this->EnableAction("MarkiseAusgefahren");

        $this->RegisterVariableString("LetzteAktion", "Letzte Aktion", "", 30);
        $this->SetValue("LetzteAktion", "Noch keine Aktion");

        $this->RegisterTimer("CheckConditions", 300000, "MSHM_CheckConditions($_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (!GetValue($this->GetIDForIdent("AutomatikAktiv"))) {
            SetValue($this->GetIDForIdent("AutomatikAktiv"), $this->ReadPropertyBoolean("AutomatikStandardwert"));
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "AutomatikAktiv":
                SetValue($this->GetIDForIdent($Ident), $Value);
                break;
            case "MarkiseAusgefahren":
                $aktorVarID = $this->GetAktorVariableID($this->ReadPropertyInteger("AktorID"));
                $this->SetAktor($aktorVarID, $Value ? 1.0 : 0.0);
                $this->LogAktion($Value ? "Manuell ausgefahren" : "Manuell eingefahren");
                SetValue($this->GetIDForIdent($Ident), $Value);
                break;
            default:
                throw new Exception("Invalid ident");
        }
    }

    public function CheckConditions()
    {
        if (!GetValue($this->GetIDForIdent("AutomatikAktiv"))) {
            IPS_LogMessage("Markisensteuerung", "Automatik deaktiviert – keine Steuerung durchgeführt.");
            return;
        }

        $regen = GetValueBoolean($this->ReadPropertyInteger("RegenSensorID"));
        $wind = GetValueFloat($this->ReadPropertyInteger("WindSensorID"));
        $aktorID = $this->ReadPropertyInteger("AktorID");
        $windSchwelle = $this->ReadPropertyFloat("WindSchwelle");

        $von = strtotime($this->ReadPropertyString("AusfahrzeitVon"));
        $bis = strtotime($this->ReadPropertyString("AusfahrzeitBis"));
        $jetzt = strtotime(date("H:i"));

        $aktorVarID = $this->GetAktorVariableID($aktorID);

        if ($regen || $wind > $windSchwelle) {
            $this->SetAktor($aktorVarID, 0.0);
            SetValue($this->GetIDForIdent("MarkiseAusgefahren"), false);
            $this->LogAktion("Automatisch eingefahren (Regen oder Wind > Schwelle)");
        } elseif (!$regen && $wind <= $windSchwelle && $jetzt >= $von && $jetzt <= $bis) {
            $this->SetAktor($aktorVarID, 1.0);
            SetValue($this->GetIDForIdent("MarkiseAusgefahren"), true);
            $this->LogAktion("Automatisch ausgefahren (Zeit + Wetter ok)");
        }
    }

    private function GetAktorVariableID(int $aktorID): int
    {
        $children = IPS_GetChildrenIDs($aktorID);
        foreach ($children as $childID) {
            $ident = @IPS_GetObject($childID)['ObjectIdent'];
            if ($ident == "LEVEL" || $ident == "STATE") {
                return $childID;
            }
        }
        throw new Exception("Keine passende Aktorvariable (LEVEL/STATE) gefunden.");
    }

    private function SetAktor(int $varID, float $wert)
    {
        $type = GetValueType($varID);
        if ($type === VARIABLETYPE_BOOLEAN) {
            RequestAction($varID, $wert >= 0.5);
        } elseif ($type === VARIABLETYPE_FLOAT || $type === VARIABLETYPE_INTEGER) {
            RequestAction($varID, $wert);
        } else {
            throw new Exception("Aktorvariable hat einen nicht unterstützten Typ.");
        }
    }

    private function LogAktion(string $grund)
    {
        $zeit = date("d.m.Y H:i:s");
        $eintrag = "$zeit – $grund";
        SetValue($this->GetIDForIdent("LetzteAktion"), $eintrag);
        IPS_LogMessage("Markisensteuerung", $eintrag);
    }
}

<?php

/*
 * Copyright (c) 2013, Michael Braun <michael-dev@fami-braun.de>.
 * All rights reserved.
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301  USA
 */

/**
 * Diese Klasse implementiert pain.008.003.02
 * für SEPA Lastschriften
 */
class SEPALastschrift {
  private $date; /* Y-m-d\TH:i:s\Z */
  private $msgid; /* ([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|'| ]){1,35} */
  private $txs = Array(); /* $type => Array */
  private $initator = "missing";
  private $sum = Array(); /* $type => cents */
  private $creditorName = "missing";
  private $creditorIBAN = "missing";
  private $creditorBIC = "missing";
  private $creditorID = "missing"; /* Gläubiger-ID */
  private $ccy = "EUR"; /* EUR */
  private $txTypes = Array("FRST","RCUR","OOFF","FNAL");

  /**
   * msgid ist ein Prefix, welches auch für sie Sammler (PmtInfId) als Prefix verwendet wird. Daher die Länge auf 29 beschränken!
   */
  public function __construct($date /*class DateTime*/, $msgid, $initiator, $creditorName, $creditorIBAN, $creditorBIC, $creditorID /* GläubigerID */, $ccy="EUR") {
    $this->date = $date;
    $this->msgid = $msgid;
    $this->initiator = $initiator;
    $this->creditorName = $creditorName;
    $this->creditorIBAN = $creditorIBAN;
    $this->creditorBIC = $creditorBIC;
    $this->creditorID = $creditorID;
    $this->ccy = $ccy;
    if (!preg_match("#^([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|'| ]){1,29}$#", $msgid))
      die("ungültige msgid");
  }

  /**
   * Lastschrift einfügen.
   * @param amount maximal 2 Bruchziffern, float
   * @param mandateSignatureDate Datum der Unterschrift auf dem Mandat; als class DateTime Object
   * @param sepatyp FRST RCUR OOFF FNAL */
  public function addLastschrift($id, $iban, $bic, $name, $mandate, $mandateSignatureDate /* class DateTime */, $amount /* float */, $subject, $type) {
    if (!preg_match("#^([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|'| ]){1,35}$#", $id))
      die("invalid id $id");
    if (!preg_match("#^([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|']){1,35}$#", $mandate))
      die("invalid mandate $mandate");
    if (strlen($subject) < 1 or strlen($subject) > 140)
     die("invalid subject length");
    if ($amount < 0.01 || $amount > 999999999.99) die("invalid amount $amount");
    $amount = (int) ($amount * 100);
    if (!in_array($type, $this->txTypes)) die("invalid type $type");
    $tx = Array("id" => $id, "IBAN" => $iban, "BIC" => $bic, "name" => $name, "mandate" => $mandate, "mandateSignatureDate" => $mandateSignatureDate, "amount" => $amount, "subject" => $subject);
    if (!isset($this->txs[$type])) $this->txs[$type] = Array();
    $this->txs[$type][] = $tx;
    if (!isset($this->sum[$type])) $this->sum[$type] = 0;
    $this->sum[$type] += $amount;
  }

  private static function formatCcy($cents) {
    return sprintf("%d.%02d", ($cents / 100), ($cents % 100));
  }

  private function addGrpHdr($xml) {
    $xml->startElement('GrpHdr');
     $xml->writeElement('MsgId', $this->msgid);
     $xml->writeElement('CreDtTm', (new DateTime("now"))->format('Y-m-d\TH:i:s\Z'));
     $ctn = 0;
     foreach ($this->txs as $v) {
       $ctn += count($v);
     }
     $xml->writeElement('NbOfTxs', $ctn);
     $sum = 0;
     foreach ($this->sum as $v) {
       $sum += $v;
     }
     $xml->writeElement('ControlSum', $this->formatCcy($sum));
     $xml->startElement('InitgPty');
      $xml->writeElement('Nm', $this->initiator);
     $xml->endElement(); /* InitgPty */
    $xml->endElement(); /* GrpHdr */
  }

  /** $type: FRST; RCUR; OOFF; FNAL */
  private function addPmtInf($xml, $txs, $sum, $type) {
    if (!preg_match("#^([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|'| ]){1,5}$#", $type))
      die("invalid type $type");

    $xml->startElement('PmtInf');
     $xml->writeElement('PmtInfId', $this->msgid.'-'.$type);
     $xml->writeElement('PmtMtd','DD');
     $xml->writeElement('NbOfTxs', count($txs));
     $xml->writeElement('ControlSum', $this->formatCcy($sum));
     $xml->startElement('PmtTpInf');
      $xml->startElement('SvcLvl');
       $xml->writeElement('Cd', 'SEPA');
      $xml->endElement(); /* SvcLvl */
      $xml->startElement('LclInstrm');
       $xml->writeElement('Cd', 'CORE');
      $xml->endElement(); /* LclInstrm */
      $xml->writeElement('SeqTp', $type);
     $xml->endElement(); /* PmtTpInf */
     $xml->writeElement('ReqdColltnDt', $this->date->format('Y-m-d'));
     $xml->startElement('Cdtr');
      $xml->writeElement('Nm', $this->creditorName);
     $xml->endElement(); /* Cdtr */
     $xml->startElement('CdtrAcct');
      $xml->startElement('Id');
       $xml->writeElement('IBAN', $this->creditorIBAN);
      $xml->endElement(); /* Id */
     $xml->endElement(); /* CdtrAcct */
     $xml->startElement('CdtrAgt');
      $xml->startElement('FinInstnId');
       $xml->writeElement('BIC', $this->creditorBIC);
      $xml->endElement(); /* FinInstnId */
     $xml->endElement(); /* CdtrAgt */
     $xml->writeElement('ChrgBr', 'SLEV');
     $xml->startElement('CdtrSchmeId');
      $xml->startElement('Id');
       $xml->startElement('PrvtId');
        $xml->startElement('Othr');
         $xml->writeElement('Id',$this->creditorID);
         $xml->startElement('SchmeNm');
          $xml->writeElement('Prtry','SEPA');
         $xml->endElement(); /* SchmeNm */
        $xml->endElement(); /* Othr */
       $xml->endElement(); /* PrvtId */
      $xml->endElement(); /* Id */
     $xml->endElement(); /* CdtrSchmeId */
     foreach ($txs as $tx) {
       $this->addTX($xml, $tx);
     }
    $xml->endElement(); /* PmtInf */
  }

  private function addTX($xml, $tx) {
    $xml->startElement('DrctDbtTxInf');
     $xml->startElement('PmtId');
      $xml->writeElement('EndToEndId',$tx["id"]);
     $xml->endElement(); /* PmtId */
     $xml->startElement('InstdAmt');
      $xml->writeAttribute("Ccy", $this->ccy);
      $xml->text($this->formatCcy($tx["amount"]));
     $xml->endElement(); /* InstdAmt */
     $xml->startElement('DrctDbtTx');
      $xml->startElement('MndtRltdInf');
       $xml->writeElement('MndtId', $tx["mandate"]);
       $xml->writeElement('DtOfSgntr',$tx["mandateSignatureDate"]->format('Y-m-d'));
       /* No Amendment aka Change-Tracing supported! */
      $xml->endElement(); /* MndtRltdInf */
     $xml->endElement(); /* DrctDbtTx */
     $xml->startElement('DbtrAgt');
      $xml->startElement('FinInstnId');
       $xml->writeElement('BIC',$tx["BIC"]);
      $xml->endElement(); /* FinInstnId */
     $xml->endElement(); /* DbtrAgt */
     $xml->startElement('Dbtr');
      $xml->writeElement('Nm',$tx["name"]);
     $xml->endElement(); /* Dbtr */
     $xml->startElement('DbtrAcct');
      $xml->startElement('Id');
       $xml->writeElement('IBAN',$tx["IBAN"]);
      $xml->endElement(); /* Id */
     $xml->endElement(); /* DbtrAcct */
     $xml->startElement('UltmtDbtr');
      $xml->writeElement('Nm',$tx["name"]); /* Zahlungspflichtiger, falls vom Kontoinhaber abweichend */
     $xml->endElement(); /* UltmtDbtr */
     $xml->startElement('RmtInf');
      $xml->writeElement('Ustrd',$tx["subject"]);
     $xml->endElement(); /* RmtInf */
    $xml->endElement(); /* DrctDbtTxInf */
  }

  public function toXMLString() {
    /** output */
   $xml = new XMLWriter;
   $xml->openMemory();
   $xml->startDocument('1.0', 'UTF-8');

   $xml->startElement('Document');
   $xml->writeAttribute('xmlns','urn:iso:std:iso:20022:tech:xsd:pain.008.003.02');
   $xml->writeAttributeNS('xsi','schemaLocation','http://www.w3.org/2001/XMLSchema-instance','urn:iso:std:iso:20022:tech:xsd:pain.008.003.02 pain.008.00.02.xsd');
    $xml->startElement('CstmrDrctDbtInitn');
     $this->addGrpHdr($xml);
     foreach ($this->txs as $type => $txs) {
       $this->addPmtInf($xml, $txs, $this->sum[$type], $type);
     }
    $xml->endElement(); /* CstmrDrctDbtInitn */
   $xml->endElement(); /* Document */

   $xml->endDocument();
   return $xml->outputMemory(TRUE);
  }
}
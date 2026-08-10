<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\Db\SepaBatch;

/**
 * Erzeugt eine SEPA-Lastschrift-Einreichungsdatei (pain.008.001.02, „SEPA
 * Core Direct Debit"). Deckt den in Deutschland üblichen Normalfall ab:
 * eine Fälligkeit pro Datei, Zahlungen gruppiert nach Sequenztyp (FRST/RCUR/
 * OOFF – die Spezifikation erlaubt SeqTp nur auf PmtInf-Ebene, nicht je
 * Transaktion, daher ein eigener PmtInf-Block je Sequenztyp).
 *
 * Nicht abgedeckt: B2B-Lastschriften, mehrere Fälligkeitstage in einer
 * Datei, Sammelbuchungs-Abwahl (BtchBookg immer „true"). Vor dem ersten
 * echten Einzug unbedingt mit dem Prüftool der Hausbank gegentesten – das
 * exakt erwartete Subformat (001.02 vs. 001.08/09) unterscheidet sich je
 * nach Bank.
 *
 * @phpstan-type Row array{
 *     endToEndId: string, amountCents: int, sequenceType: string,
 *     mandateReference: string, signedDate: string, debtorIban: string,
 *     debtorBic: ?string, debtorName: string, remittanceInfo: string,
 * }
 */
class PainXmlBuilder {

	/** @param Row[] $rows */
	public function build(SepaBatch $batch, array $rows): string {
		$doc = new \DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;

		$root = $doc->createElementNS('urn:iso:std:iso:20022:tech:xsd:pain.008.001.02', 'Document');
		$doc->appendChild($root);
		$init = $doc->createElement('CstmrDrctDbtInitn');
		$root->appendChild($init);

		$this->buildGroupHeader($doc, $init, $batch, $rows);

		foreach ($this->groupBySequenceType($rows) as $sequenceType => $groupRows) {
			$this->buildPaymentInfo($doc, $init, $batch, $sequenceType, $groupRows);
		}

		return $doc->saveXML();
	}

	/** @param Row[] $rows */
	private function buildGroupHeader(\DOMDocument $doc, \DOMElement $parent, SepaBatch $batch, array $rows): void {
		$grpHdr = $doc->createElement('GrpHdr');
		$parent->appendChild($grpHdr);
		$this->el($doc, $grpHdr, 'MsgId', $batch->getMessageId());
		$this->el($doc, $grpHdr, 'CreDtTm', (new \DateTime())->format('Y-m-d\TH:i:s'));
		$this->el($doc, $grpHdr, 'NbOfTxs', (string)count($rows));
		$this->el($doc, $grpHdr, 'CtrlSum', $this->formatAmount($this->sumCents($rows)));
		$initgPty = $doc->createElement('InitgPty');
		$grpHdr->appendChild($initgPty);
		$this->el($doc, $initgPty, 'Nm', $batch->getCreditorName());
	}

	/** @param Row[] $rows */
	private function buildPaymentInfo(\DOMDocument $doc, \DOMElement $parent, SepaBatch $batch, string $sequenceType, array $rows): void {
		$pmtInf = $doc->createElement('PmtInf');
		$parent->appendChild($pmtInf);
		$this->el($doc, $pmtInf, 'PmtInfId', $batch->getMessageId() . '-' . $sequenceType);
		$this->el($doc, $pmtInf, 'PmtMtd', 'DD');
		$this->el($doc, $pmtInf, 'BtchBookg', 'true');
		$this->el($doc, $pmtInf, 'NbOfTxs', (string)count($rows));
		$this->el($doc, $pmtInf, 'CtrlSum', $this->formatAmount($this->sumCents($rows)));

		$pmtTpInf = $doc->createElement('PmtTpInf');
		$pmtInf->appendChild($pmtTpInf);
		$svcLvl = $doc->createElement('SvcLvl');
		$pmtTpInf->appendChild($svcLvl);
		$this->el($doc, $svcLvl, 'Cd', 'SEPA');
		$lclInstrm = $doc->createElement('LclInstrm');
		$pmtTpInf->appendChild($lclInstrm);
		$this->el($doc, $lclInstrm, 'Cd', 'CORE');
		$this->el($doc, $pmtTpInf, 'SeqTp', $sequenceType);

		$this->el($doc, $pmtInf, 'ReqdColltnDt', $batch->getExecutionDate());

		$cdtr = $doc->createElement('Cdtr');
		$pmtInf->appendChild($cdtr);
		$this->el($doc, $cdtr, 'Nm', $batch->getCreditorName());

		$cdtrAcct = $doc->createElement('CdtrAcct');
		$pmtInf->appendChild($cdtrAcct);
		$cdtrAcctId = $doc->createElement('Id');
		$cdtrAcct->appendChild($cdtrAcctId);
		$this->el($doc, $cdtrAcctId, 'IBAN', $batch->getCreditorIban());

		$cdtrAgt = $doc->createElement('CdtrAgt');
		$pmtInf->appendChild($cdtrAgt);
		$cdtrFinInstn = $doc->createElement('FinInstnId');
		$cdtrAgt->appendChild($cdtrFinInstn);
		if ($batch->getCreditorBic() !== null) {
			$this->el($doc, $cdtrFinInstn, 'BIC', $batch->getCreditorBic());
		} else {
			$this->el($doc, $cdtrFinInstn, 'Othr', null)->appendChild($doc->createElement('Id', 'NOTPROVIDED'));
		}

		$cdtrSchmeId = $doc->createElement('CdtrSchmeId');
		$pmtInf->appendChild($cdtrSchmeId);
		$schmeId = $doc->createElement('Id');
		$cdtrSchmeId->appendChild($schmeId);
		$prvtId = $doc->createElement('PrvtId');
		$schmeId->appendChild($prvtId);
		$othr = $doc->createElement('Othr');
		$prvtId->appendChild($othr);
		$this->el($doc, $othr, 'Id', $batch->getCreditorId());
		$schmeNm = $doc->createElement('SchmeNm');
		$othr->appendChild($schmeNm);
		$this->el($doc, $schmeNm, 'Prtry', 'SEPA');

		foreach ($rows as $row) {
			$this->buildTransaction($doc, $pmtInf, $row);
		}
	}

	/** @param Row $row */
	private function buildTransaction(\DOMDocument $doc, \DOMElement $pmtInf, array $row): void {
		$txInf = $doc->createElement('DrctDbtTxInf');
		$pmtInf->appendChild($txInf);

		$pmtId = $doc->createElement('PmtId');
		$txInf->appendChild($pmtId);
		$this->el($doc, $pmtId, 'EndToEndId', $row['endToEndId']);

		$instdAmt = $doc->createElement('InstdAmt', $this->formatAmount($row['amountCents']));
		$instdAmt->setAttribute('Ccy', 'EUR');
		$txInf->appendChild($instdAmt);

		$ddTx = $doc->createElement('DrctDbtTx');
		$txInf->appendChild($ddTx);
		$mndtRltdInf = $doc->createElement('MndtRltdInf');
		$ddTx->appendChild($mndtRltdInf);
		$this->el($doc, $mndtRltdInf, 'MndtId', $row['mandateReference']);
		$this->el($doc, $mndtRltdInf, 'DtOfSgntr', $row['signedDate']);

		$dbtrAgt = $doc->createElement('DbtrAgt');
		$txInf->appendChild($dbtrAgt);
		$dbtrFinInstn = $doc->createElement('FinInstnId');
		$dbtrAgt->appendChild($dbtrFinInstn);
		if ($row['debtorBic'] !== null) {
			$this->el($doc, $dbtrFinInstn, 'BIC', $row['debtorBic']);
		} else {
			$this->el($doc, $dbtrFinInstn, 'Othr', null)->appendChild($doc->createElement('Id', 'NOTPROVIDED'));
		}

		$dbtr = $doc->createElement('Dbtr');
		$txInf->appendChild($dbtr);
		$this->el($doc, $dbtr, 'Nm', $row['debtorName']);

		$dbtrAcct = $doc->createElement('DbtrAcct');
		$txInf->appendChild($dbtrAcct);
		$dbtrAcctId = $doc->createElement('Id');
		$dbtrAcct->appendChild($dbtrAcctId);
		$this->el($doc, $dbtrAcctId, 'IBAN', $row['debtorIban']);

		$rmtInf = $doc->createElement('RmtInf');
		$txInf->appendChild($rmtInf);
		$this->el($doc, $rmtInf, 'Ustrd', mb_substr($row['remittanceInfo'], 0, 140));
	}

	/**
	 * @param Row[] $rows
	 * @return array<string, Row[]> gruppiert nach Sequenztyp, in fester Reihenfolge FRST/RCUR/OOFF
	 */
	private function groupBySequenceType(array $rows): array {
		$grouped = [];
		foreach (['FRST', 'RCUR', 'OOFF'] as $type) {
			$matching = array_values(array_filter($rows, static fn (array $r): bool => $r['sequenceType'] === $type));
			if ($matching !== []) {
				$grouped[$type] = $matching;
			}
		}
		return $grouped;
	}

	/** @param Row[] $rows */
	private function sumCents(array $rows): int {
		return array_sum(array_column($rows, 'amountCents'));
	}

	private function formatAmount(int $cents): string {
		return number_format($cents / 100, 2, '.', '');
	}

	/**
	 * Erzeugt das Element über createTextNode() statt createElement($name,
	 * $value): IBAN, Name und Verwendungszweck kommen aus Nutzereingaben.
	 * DOMDocument::createElement() interpretiert seinen zweiten Parameter als
	 * (potenziell) Markup – ein "&" darin wirft eine DOMException oder,
	 * schlimmer, ließe sich zur XML-Injektion missbrauchen. Ein Textknoten
	 * wird dagegen beim Serialisieren immer sicher escaped.
	 */
	private function el(\DOMDocument $doc, \DOMElement $parent, string $name, ?string $value): \DOMElement {
		$el = $doc->createElement($name);
		if ($value !== null) {
			$el->appendChild($doc->createTextNode($value));
		}
		$parent->appendChild($el);
		return $el;
	}
}

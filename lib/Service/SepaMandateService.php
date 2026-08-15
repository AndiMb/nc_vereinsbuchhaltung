<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\MembershipFeeMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\SepaBatchItemMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandate;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaReference;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * SEPA-Lastschriftmandate. Rein optionales Zusatzmodul (siehe Migration
 * 000124): ein Mandat gehört entweder zu einem Nextcloud-Konto dieser
 * Instanz (Mitglied mit eigenem Login) oder zu einem frei benannten Zahler
 * (member_label) – etwa bei einem Verband, der nur Beitragsanteile von
 * Untergliederungen einzieht und keine individuellen Mitglieder führt.
 */
class SepaMandateService {

	public function __construct(
		private SepaMandateMapper $mapper,
		private SepaBatchItemMapper $batchItemMapper,
		private MembershipFeeMapper $feeMapper,
		private OpenItemMapper $openItemMapper,
		private IbanValidator $ibanValidator,
		private MemberReferenceValidator $memberRef,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private IL10N $l10n,
	) {
	}

	/** @return SepaMandate[] */
	public function findAll(): array {
		return $this->mapper->findAll();
	}

	/**
	 * @param string|null $memberUid Nextcloud-Konto; exklusiv zu $memberLabel
	 * @param string|null $memberLabel Freitext-Zahler; exklusiv zu $memberUid
	 * @throws \InvalidArgumentException bei ungültigen Eingaben
	 */
	public function create(
		?string $memberUid,
		?string $memberLabel,
		string $iban,
		?string $bic,
		string $mandateType,
		string $signedDate,
		?string $email = null,
	): SepaMandate {
		[$memberUid, $memberLabel] = $this->memberRef->validate($memberUid, $memberLabel);

		$mandate = new SepaMandate();
		$mandate->setMemberUid($memberUid);
		$mandate->setMemberLabel($memberLabel);
		$mandate->setIban($this->requireIban($iban));
		$mandate->setBic($bic !== null && trim($bic) !== '' ? strtoupper(trim($bic)) : null);
		$mandate->setEmail($this->normalizeEmail($email));
		$mandate->setMandateType($this->validateType($mandateType));
		$mandate->setSignedDate($this->validateDate($signedDate));
		$mandate->setStatus('active');
		$mandate->setMandateReference($this->generateReference());
		$mandate->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));

		$mandate = $this->mapper->insert($mandate);
		$this->audit->log('SEPA-Mandat angelegt', 'sepa_mandate', $mandate->getId(), [
			'zahler' => $mandate->displayName(),
			'referenz' => $mandate->getMandateReference(),
		]);
		return $mandate;
	}

	/**
	 * @throws DoesNotExistException wenn es das Mandat nicht (mehr) gibt
	 */
	public function update(int $id, string $iban, ?string $bic, string $mandateType, string $signedDate, ?string $email = null): SepaMandate {
		$mandate = $this->mapper->find($id);
		$mandate->setIban($this->requireIban($iban));
		$mandate->setBic($bic !== null && trim($bic) !== '' ? strtoupper(trim($bic)) : null);
		$mandate->setEmail($this->normalizeEmail($email));
		$mandate->setMandateType($this->validateType($mandateType));
		$mandate->setSignedDate($this->validateDate($signedDate));
		$mandate = $this->mapper->update($mandate);
		$this->audit->log('SEPA-Mandat geändert', 'sepa_mandate', $mandate->getId(), [
			'zahler' => $mandate->displayName(),
			'referenz' => $mandate->getMandateReference(),
		]);
		return $mandate;
	}

	/**
	 * Widerruft ein Mandat, statt es zu löschen: bereits erzeugte SEPA-Sammel-
	 * einzüge (Phase 3) referenzieren die Mandatsreferenz, die dafür weiter
	 * auflösbar bleiben muss.
	 *
	 * @throws DoesNotExistException wenn es das Mandat nicht (mehr) gibt
	 */
	public function revoke(int $id): SepaMandate {
		$mandate = $this->mapper->find($id);
		$mandate->setStatus('revoked');
		$mandate = $this->mapper->update($mandate);
		$this->audit->log('SEPA-Mandat widerrufen', 'sepa_mandate', $mandate->getId(), [
			'zahler' => $mandate->displayName(),
			'referenz' => $mandate->getMandateReference(),
		]);
		return $mandate;
	}

	/**
	 * Wechselt die Bankverbindung eines Zahlers: widerruft das alte Mandat,
	 * legt ein neues für denselben Zahler an und hängt um, was sich sonst
	 * still verlöre.
	 *
	 * Ohne diese Methode war der einzige Weg „widerrufen, neu anlegen" –
	 * zwei für sich richtige Schritte, die aber eine Lücke hinterließen: noch
	 * offene Posten (bereits fällige, aber noch nicht eingezogene Beiträge)
	 * zeigten weiter auf das widerrufene Mandat. {@see SepaBatchService::previewEligible()}
	 * verlangt ein aktives Mandat und ließ sie deshalb kommentarlos aus jeder
	 * künftigen Vorschau herausfallen – eine Forderung, die nie wieder
	 * eingezogen wird, ohne dass es irgendwo auffällt. Ein aktiver Beitrag
	 * mit Verweis auf das alte Mandat hätte ab der nächsten Fälligkeit
	 * denselben Fehler wiederholt.
	 *
	 * Das neue Mandat beginnt bewusst als „noch nicht benutzt": eine neue
	 * Bankverbindung ist SEPA-rechtlich eine neue Einzugsermächtigung, der
	 * erste Einzug darüber läuft daher zu Recht wieder als FRST statt RCUR.
	 *
	 * @throws DoesNotExistException wenn es das alte Mandat nicht (mehr) gibt
	 * @throws \InvalidArgumentException wenn das alte Mandat nicht aktiv ist
	 */
	public function changeBankAccount(
		int $oldMandateId,
		string $iban,
		?string $bic,
		string $mandateType,
		string $signedDate,
		?string $email = null,
	): SepaMandate {
		$old = $this->mapper->find($oldMandateId);
		if ($old->getStatus() !== 'active') {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein aktives Mandat lässt sich auf eine neue Bankverbindung umstellen.'));
		}

		return $this->transaction->run(function () use ($old, $iban, $bic, $mandateType, $signedDate, $email): SepaMandate {
			$new = $this->create($old->getMemberUid(), $old->getMemberLabel(), $iban, $bic, $mandateType, $signedDate, $email);
			$this->revoke((int)$old->getId());

			// Beiträge: unabhängig vom Aktiv-Status umhängen. Ein gerade
			// pausierter Beitrag soll bei Wiederaufnahme die neue Bank-
			// verbindung nutzen, nicht die widerrufene von vorhin.
			$beitraege = 0;
			foreach ($this->feeMapper->findByMandate((int)$old->getId()) as $fee) {
				$fee->setMandateId($new->getId());
				$this->feeMapper->update($fee);
				$beitraege++;
			}

			// Offene Posten: nur die noch tatsächlich offenen. Bereits
			// bezahlte oder stornierte sind abgeschlossene Historie – für sie
			// ändert eine neue Bankverbindung nichts mehr.
			$posten = 0;
			foreach ($this->openItemMapper->findByMandate((int)$old->getId()) as $item) {
				if ($item->getStatus() === 'open') {
					$item->setMandateId($new->getId());
					$this->openItemMapper->update($item);
					$posten++;
				}
			}

			$this->audit->log('SEPA-Bankverbindung gewechselt', 'sepa_mandate', $new->getId(), [
				'zahler' => $new->displayName(),
				'alte_referenz' => $old->getMandateReference(),
				'neue_referenz' => $new->getMandateReference(),
				'beitraege_umgehaengt' => $beitraege,
				'offene_posten_umgehaengt' => $posten,
			]);

			return $new;
		});
	}

	/**
	 * Löscht ein Mandat – aber nur, solange nichts darauf verweist.
	 *
	 * Ein benutztes Mandat zu löschen zerstört mehr, als es aufräumt: die
	 * pain.008-Datei eines bereits erzeugten Einzugs lässt sich danach nicht
	 * mehr erzeugen (der Nachweis, mit welchem Mandat eingereicht wurde, ist
	 * weg), und ein offener Posten mit verwaister mandate_id verschwindet
	 * stillschweigend aus dem SEPA-Export – niemand erführe, dass dieser
	 * Beitrag nie wieder eingezogen wird. Für „soll nicht mehr benutzt werden"
	 * ist {@see revoke()} da; das ist auch der Weg, den die Oberfläche anbietet.
	 *
	 * @throws DoesNotExistException wenn es das Mandat nicht (mehr) gibt
	 * @throws \InvalidArgumentException wenn noch etwas auf das Mandat verweist
	 */
	public function delete(int $id): void {
		$mandate = $this->mapper->find($id);

		$einzuege = count($this->batchItemMapper->findByMandate($id));
		$beitraege = count($this->feeMapper->findByMandate($id));
		$posten = count($this->openItemMapper->findByMandate($id));
		if ($einzuege > 0 || $beitraege > 0 || $posten > 0) {
			throw new \InvalidArgumentException($this->l10n->t(
				'Dieses Mandat wird noch verwendet (%1$s Sammeleinzug-Zeilen, %2$s Beiträge, %3$s offene Posten) und kann nicht gelöscht werden. Widerrufen Sie es stattdessen – dann wird es für neue Einzüge nicht mehr benutzt, bleibt aber nachvollziehbar.',
				[(string)$einzuege, (string)$beitraege, (string)$posten]
			));
		}

		$this->mapper->delete($mandate);
		$this->audit->log('SEPA-Mandat gelöscht', 'sepa_mandate', $id, [
			'zahler' => $mandate->displayName(),
			'referenz' => $mandate->getMandateReference(),
		]);
	}

	/**
	 * Verweise auf ein Mandat, damit die Oberfläche das Löschen gar nicht erst
	 * anbieten muss, wo es ohnehin abgelehnt würde.
	 *
	 * @return array{batchItems:int, fees:int, openItems:int}
	 */
	public function usage(int $id): array {
		return [
			'batchItems' => count($this->batchItemMapper->findByMandate($id)),
			'fees' => count($this->feeMapper->findByMandate($id)),
			'openItems' => count($this->openItemMapper->findByMandate($id)),
		];
	}

	private function requireIban(string $iban): string {
		$normalized = $this->ibanValidator->validate($iban);
		if ($normalized === null) {
			throw new \InvalidArgumentException($this->l10n->t('Die IBAN ist Pflicht.'));
		}
		return $normalized;
	}

	/**
	 * Adresse für die SEPA-Vorankündigung. Optional, aber für die meisten
	 * Zahler der einzige Weg: wer kein Nextcloud-Konto auf dieser Instanz hat
	 * – in einem Chor oder einem Verein mit 200 Mitgliedern also fast jeder –,
	 * konnte vorher gar nicht angekündigt werden (siehe Migration 000130).
	 */
	private function normalizeEmail(?string $email): ?string {
		$email = trim((string)$email);
		if ($email === '') {
			return null;
		}
		if (!EmailValidator::isValid($email)) {
			throw new \InvalidArgumentException($this->l10n->t('Die E-Mail-Adresse ist ungültig: %s', [$email]));
		}
		return $email;
	}

	private function validateType(string $mandateType): string {
		if (!in_array($mandateType, SepaMandate::TYPES, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültige Mandatsart: %s', [$mandateType]));
		}
		return $mandateType;
	}

	/**
	 * Neben dem Format muss der Tag den Kalender überstehen: das Datum wandert
	 * als DtOfSgntr in die pain.008-Datei, wo der Typ xs:date gilt. Ein
	 * "2026-02-31" macht dort nicht eine Zeile ungültig, sondern die ganze
	 * Einreichung – und das merkt man erst bei der Bank.
	 */
	private function validateDate(string $date): string {
		$date = trim($date);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültiges Unterschriftsdatum (erwartet JJJJ-MM-TT).'));
		}
		if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException($this->l10n->t('Diesen Tag gibt es nicht: %s', [$date]));
		}
		return $date;
	}

	/**
	 * Erzeugt eine eindeutige Mandatsreferenz (Format siehe
	 * {@see SepaReference}). Kollisionen sind bei sechs Zufalls-Hexstellen
	 * praktisch ausgeschlossen; die Schleife ist nur ein Sicherheitsnetz,
	 * kein erwarteter Normalfall.
	 */
	private function generateReference(): string {
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$candidate = SepaReference::mandate();
			if ($this->mapper->findByReference($candidate) === null) {
				return $candidate;
			}
		}
		throw new \RuntimeException('Konnte keine eindeutige Mandatsreferenz erzeugen.');
	}
}

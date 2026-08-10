<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\SepaMandate;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUserManager;

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
		private IbanValidator $ibanValidator,
		private IUserManager $userManager,
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
	): SepaMandate {
		[$memberUid, $memberLabel] = $this->validateMember($memberUid, $memberLabel);

		$mandate = new SepaMandate();
		$mandate->setMemberUid($memberUid);
		$mandate->setMemberLabel($memberLabel);
		$mandate->setIban($this->requireIban($iban));
		$mandate->setBic($bic !== null && trim($bic) !== '' ? strtoupper(trim($bic)) : null);
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
	public function update(int $id, string $iban, ?string $bic, string $mandateType, string $signedDate): SepaMandate {
		$mandate = $this->mapper->find($id);
		$mandate->setIban($this->requireIban($iban));
		$mandate->setBic($bic !== null && trim($bic) !== '' ? strtoupper(trim($bic)) : null);
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
	 * @throws DoesNotExistException wenn es das Mandat nicht (mehr) gibt
	 */
	public function delete(int $id): void {
		$mandate = $this->mapper->find($id);
		$this->mapper->delete($mandate);
		$this->audit->log('SEPA-Mandat gelöscht', 'sepa_mandate', $id, [
			'zahler' => $mandate->displayName(),
			'referenz' => $mandate->getMandateReference(),
		]);
	}

	/**
	 * @return array{0: ?string, 1: ?string} [memberUid, memberLabel] normalisiert
	 */
	private function validateMember(?string $memberUid, ?string $memberLabel): array {
		$memberUid = $memberUid !== null && trim($memberUid) !== '' ? trim($memberUid) : null;
		$memberLabel = $memberLabel !== null && trim($memberLabel) !== '' ? mb_substr(trim($memberLabel), 0, 255) : null;

		if ($memberUid !== null && $memberLabel !== null) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte entweder einen Nextcloud-Nutzer oder einen freien Zahlernamen angeben, nicht beides.'));
		}
		if ($memberUid === null && $memberLabel === null) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte einen Nextcloud-Nutzer oder einen freien Zahlernamen angeben.'));
		}
		if ($memberUid !== null && !$this->userManager->userExists($memberUid)) {
			throw new \InvalidArgumentException($this->l10n->t('Diesen Nextcloud-Nutzer gibt es nicht: %s', [$memberUid]));
		}
		return [$memberUid, $memberLabel];
	}

	private function requireIban(string $iban): string {
		$normalized = $this->ibanValidator->validate($iban);
		if ($normalized === null) {
			throw new \InvalidArgumentException($this->l10n->t('Die IBAN ist Pflicht.'));
		}
		return $normalized;
	}

	private function validateType(string $mandateType): string {
		if (!in_array($mandateType, SepaMandate::TYPES, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültige Mandatsart: %s', [$mandateType]));
		}
		return $mandateType;
	}

	private function validateDate(string $date): string {
		$date = trim($date);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültiges Unterschriftsdatum (erwartet JJJJ-MM-TT).'));
		}
		return $date;
	}

	/**
	 * Erzeugt eine eindeutige Mandatsreferenz (max. 35 Zeichen, SEPA-konform:
	 * nur Buchstaben, Ziffern und Bindestrich). Kollisionen sind bei sechs
	 * Zufalls-Hexstellen praktisch ausgeschlossen; die Schleife ist nur ein
	 * Sicherheitsnetz, kein erwarteter Normalfall.
	 */
	private function generateReference(): string {
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$candidate = 'M' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
			if ($this->mapper->findByReference($candidate) === null) {
				return $candidate;
			}
		}
		throw new \RuntimeException('Konnte keine eindeutige Mandatsreferenz erzeugen.');
	}
}

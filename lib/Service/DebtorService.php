<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Debtor;
use OCA\Vereinsbuchhaltung\Db\DebtorMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\InvoicePlanMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Rechnungsempfänger: Stammdaten mit Adresse, Leitweg-ID und Kundennummer,
 * optional mit einem Mitglied verknüpft.
 *
 * Eine festgeschriebene Rechnung trägt einen Schnappschuss der Anschrift
 * (siehe {@see Debtor::toSnapshot()}), spätere Änderungen und auch das Löschen
 * eines Empfängers berühren sie nicht. Entwürfe zeigen dagegen noch auf den
 * Datensatz; solange einer davon einen Empfänger braucht, lässt er sich nicht
 * löschen.
 */
class DebtorService {

	public function __construct(
		private DebtorMapper $mapper,
		private MemberMapper $memberMapper,
		private InvoiceMapper $invoiceMapper,
		private ?IL10N $l10n = null,
		private ?InvoicePlanMapper $plans = null,
	) {
	}

	/** @return Debtor[] */
	public function search(?string $query): array {
		return $this->mapper->search($query);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Debtor {
		return $this->mapper->find($id);
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws \InvalidArgumentException bei fehlenden oder ungültigen Angaben
	 */
	public function create(array $data): Debtor {
		$debtor = new Debtor();
		$debtor->setCreatedAt((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
		$this->apply($debtor, $data);
		return $this->mapper->insert($debtor);
	}

	/**
	 * @param array<string, mixed> $data nur die gesendeten Felder werden geändert
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException
	 */
	public function update(int $id, array $data): Debtor {
		$debtor = $this->mapper->find($id);
		$this->apply($debtor, $data);
		return $this->mapper->update($debtor);
	}

	/**
	 * Der Empfänger zu einem Mitglied: vorhandener Datensatz oder ein neuer mit
	 * der Adresse aus der Mitgliederakte. Ein vorhandener Datensatz bleibt
	 * unverändert, damit eine Hand-Korrektur der Rechnungsanschrift nicht
	 * beim nächsten Aufruf überschrieben wird.
	 *
	 * @throws DoesNotExistException wenn es das Mitglied nicht gibt
	 * @throws \InvalidArgumentException wenn das Mitglied anonymisiert ist
	 */
	public function forMember(int $memberId): Debtor {
		$existing = $this->mapper->findByMember($memberId);
		if ($existing !== null) {
			return $existing;
		}
		$member = $this->memberMapper->find($memberId);
		if ($member->isRedacted()) {
			throw new \InvalidArgumentException($this->msg('Das Mitglied ist anonymisiert und kann kein Rechnungsempfänger sein.'));
		}
		return $this->create([
			'kind' => $member->getMemberType() === Member::TYPE_ORGANIZATION ? Debtor::KIND_ORGANISATION : Debtor::KIND_PERSON,
			'name' => $member->displayName(),
			'street' => $member->getStreet(),
			'postalCode' => $member->getPostalCode(),
			'city' => $member->getCity(),
			'country' => $member->getCountry() ?: 'DE',
			'email' => $member->getEmail(),
			'customerNo' => $member->getMemberNumber(),
			'memberId' => $memberId,
		]);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException wenn noch ein Entwurf den Empfänger braucht
	 */
	public function delete(int $id): void {
		$debtor = $this->mapper->find($id);
		if ($this->invoiceMapper->findFiltered(Invoice::STATUS_DRAFT, null, $id) !== []) {
			throw new \InvalidArgumentException($this->msg('Der Empfänger wird noch in einem Rechnungsentwurf verwendet.'));
		}
		if ($this->plans !== null && $this->plans->countByDebtor($id) > 0) {
			throw new \InvalidArgumentException($this->msg('Der Empfänger wird noch in einem Plan für wiederkehrende Rechnungen verwendet.'));
		}
		$this->mapper->delete($debtor);
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws \InvalidArgumentException
	 */
	private function apply(Debtor $debtor, array $data): void {
		$text = static fn (string $key, int $max): string => mb_substr(trim((string)($data[$key] ?? '')), 0, $max);

		if (array_key_exists('kind', $data)) {
			$kind = (string)$data['kind'];
			if (!in_array($kind, Debtor::KINDS, true)) {
				throw new \InvalidArgumentException($this->msg('Unbekannte Art des Empfängers.'));
			}
			$debtor->setKind($kind);
		}

		if (array_key_exists('name', $data)) {
			$name = $text('name', 255);
			if ($name === '') {
				throw new \InvalidArgumentException($this->msg('Der Name des Empfängers ist Pflicht.'));
			}
			$debtor->setName($name);
		} elseif ($debtor->getName() === null) {
			throw new \InvalidArgumentException($this->msg('Der Name des Empfängers ist Pflicht.'));
		}

		$optional = [
			'name2' => [255, 'setName2'],
			'street' => [255, 'setStreet'],
			'postalCode' => [16, 'setPostalCode'],
			'city' => [128, 'setCity'],
			'customerNo' => [64, 'setCustomerNo'],
			'note' => [2000, 'setNote'],
		];
		foreach ($optional as $key => [$max, $setter]) {
			if (array_key_exists($key, $data)) {
				$value = $text($key, $max);
				$debtor->$setter($value === '' ? null : $value);
			}
		}

		if (array_key_exists('country', $data)) {
			$country = strtoupper($text('country', 8));
			if ($country === '') {
				$country = 'DE';
			}
			if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
				throw new \InvalidArgumentException($this->msg('Das Land wird als zweistelliger Ländercode angegeben, z. B. DE.'));
			}
			$debtor->setCountry($country);
		}

		if (array_key_exists('email', $data)) {
			$email = $text('email', 255);
			if ($email !== '' && !EmailValidator::isValid($email)) {
				throw new \InvalidArgumentException($this->msg('Ungültige E-Mail-Adresse.'));
			}
			$debtor->setEmail($email === '' ? null : $email);
		}

		if (array_key_exists('vatId', $data)) {
			$vatId = strtoupper(str_replace(' ', '', $text('vatId', 32)));
			if ($vatId !== '' && preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/', $vatId) !== 1) {
				throw new \InvalidArgumentException($this->msg('Die USt-IdNr. sieht nicht gültig aus (erwartet wird z. B. DE123456789).'));
			}
			$debtor->setVatId($vatId === '' ? null : $vatId);
		}

		if (array_key_exists('leitwegId', $data)) {
			$leitweg = $text('leitwegId', 64);
			// Die Leitweg-ID ist 5 bis 46 Zeichen lang: Ziffern, Buchstaben und
			// Bindestriche, z. B. 991-01234-44 (Grobwähler-, Feinwähler-, Prüfziffer).
			if ($leitweg !== '' && preg_match('/^[0-9A-Za-z\-]{5,46}$/', $leitweg) !== 1) {
				throw new \InvalidArgumentException($this->msg('Die Leitweg-ID sieht nicht gültig aus (z. B. 991-01234-44).'));
			}
			$debtor->setLeitwegId($leitweg === '' ? null : $leitweg);
		}

		if (array_key_exists('memberId', $data)) {
			$memberId = $data['memberId'] !== null && $data['memberId'] !== '' ? (int)$data['memberId'] : null;
			if ($memberId !== null && $this->memberMapper->findOrNull($memberId) === null) {
				throw new \InvalidArgumentException($this->msg('Das verknüpfte Mitglied existiert nicht.'));
			}
			$debtor->setMemberId($memberId);
		}
	}

	/** @param list<int|string> $params */
	private function msg(string $text, array $params = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $params) : vsprintf($text, $params);
	}
}

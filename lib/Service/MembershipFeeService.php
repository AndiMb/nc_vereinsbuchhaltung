<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\MembershipFee;
use OCA\Vereinsbuchhaltung\Db\MembershipFeeMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Mitgliedsbeiträge mit Zahlungsfrequenz (optionales Zusatzmodul, siehe
 * Migration 000125). Erzeugt bei Fälligkeit offene Posten über den
 * bestehenden {@see OpenItemService} – es gibt keine eigene Forderungs-
 * verwaltung, ein fälliger Beitrag ist einfach ein weiterer offener Posten.
 */
class MembershipFeeService {

	/** Siehe WatchFolderService::ACTOR für dieselbe Idee (Audit-Log ohne Sitzung). */
	public const ACTOR = 'automatisch (Beitragsfälligkeit)';

	public function __construct(
		private MembershipFeeMapper $mapper,
		private SepaMandateMapper $mandateMapper,
		private AccountMapper $accountMapper,
		private OpenItemService $openItems,
		private MemberReferenceValidator $memberRef,
		private AuditService $audit,
		private IL10N $l10n,
	) {
	}

	/** @return MembershipFee[] */
	public function findAll(): array {
		return $this->mapper->findAll();
	}

	public function create(
		?string $memberUid,
		?string $memberLabel,
		int $amountCents,
		string $frequency,
		string $startDate,
		?int $accountId,
		?int $mandateId,
	): MembershipFee {
		[$memberUid, $memberLabel] = $this->memberRef->validate($memberUid, $memberLabel);

		$fee = new MembershipFee();
		$fee->setMemberUid($memberUid);
		$fee->setMemberLabel($memberLabel);
		$fee->setAmountCents($this->requirePositiveAmount($amountCents));
		$fee->setFrequency($this->validateFrequency($frequency));
		$fee->setStartDate($this->validateDate($startDate));
		$fee->setNextDueDate($fee->getStartDate());
		$fee->setAccountId($this->resolveAccountId($accountId));
		$fee->setMandateId($this->resolveMandateId($mandateId, $memberUid, $memberLabel));
		$fee->setActive(true);
		$fee->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));

		$fee = $this->mapper->insert($fee);
		$this->audit->log('Mitgliedsbeitrag angelegt', 'membership_fee', $fee->getId(), [
			'zahler' => $fee->displayName(),
			'betrag' => $fee->getAmountCents() / 100,
			'frequenz' => $fee->getFrequency(),
		]);
		return $fee;
	}

	/**
	 * @param string|null $nextDueDate korrigierte nächste Fälligkeit; ohne
	 *        Angabe bleibt die bisherige stehen. Korrigierbar, weil ein
	 *        vertipptes Startdatum sonst nur durch Löschen und Neuanlegen aus
	 *        der Welt zu schaffen wäre – und damit auch die Historie.
	 * @throws DoesNotExistException wenn es den Beitrag nicht (mehr) gibt
	 */
	public function update(
		int $id,
		int $amountCents,
		string $frequency,
		?int $accountId,
		?int $mandateId,
		bool $active,
		?string $nextDueDate = null,
	): MembershipFee {
		$fee = $this->mapper->find($id);
		$fee->setAmountCents($this->requirePositiveAmount($amountCents));
		$fee->setFrequency($this->validateFrequency($frequency));
		$fee->setAccountId($this->resolveAccountId($accountId));
		$fee->setMandateId($this->resolveMandateId($mandateId, $fee->getMemberUid(), $fee->getMemberLabel()));
		$fee->setActive($active);
		if ($nextDueDate !== null && $nextDueDate !== '') {
			$fee->setNextDueDate($this->validateDate($nextDueDate));
		}
		$fee = $this->mapper->update($fee);
		$this->audit->log('Mitgliedsbeitrag geändert', 'membership_fee', $fee->getId(), [
			'zahler' => $fee->displayName(),
			'betrag' => $fee->getAmountCents() / 100,
			'frequenz' => $fee->getFrequency(),
			'faelligkeit' => $fee->getNextDueDate(),
			'aktiv' => $fee->getActive(),
		]);
		return $fee;
	}

	/**
	 * @throws DoesNotExistException wenn es den Beitrag nicht (mehr) gibt
	 */
	public function delete(int $id): void {
		$fee = $this->mapper->find($id);
		$this->mapper->delete($fee);
		$this->audit->log('Mitgliedsbeitrag gelöscht', 'membership_fee', $id, [
			'zahler' => $fee->displayName(),
		]);
	}

	/**
	 * Erzeugt für jeden fälligen aktiven Beitrag einen offenen Posten und
	 * schreibt die nächste Fälligkeit fort (siehe Kommentar unten zum
	 * Nachhol-Verhalten). Vom {@see \OCA\Vereinsbuchhaltung\BackgroundJob\MembershipFeeDueJob}
	 * täglich aufgerufen.
	 *
	 * @return int Anzahl erzeugter offener Posten
	 */
	public function generateDueOpenItems(?string $today = null): int {
		$today ??= (new \DateTime())->format('Y-m-d');
		$count = 0;
		foreach ($this->mapper->findDueBy($today) as $fee) {
			$this->openItems->create(
				$this->memberRef->displayName($fee->getMemberUid(), $fee->getMemberLabel()),
				$this->l10n->t('Mitgliedsbeitrag (%s)', [$this->frequencyLabel($fee->getFrequency())]),
				$fee->getAmountCents(),
				$fee->getNextDueDate(),
				$fee->getAccountId(),
				$fee->getMandateId(),
			);
			// Pro Lauf genau ein Posten je Beitrag, auch wenn next_due_date weit
			// in der Vergangenheit liegt (z. B. Beitrag erst Monate nach dem
			// eigentlichen Start angelegt): ein Nachholen mehrerer Perioden auf
			// einen Schlag erzeugte sonst unerwartet viele offene Posten auf
			// einmal. Liegt next_due_date weiterhin in der Vergangenheit, holt
			// der nächste Tageslauf die nächste Periode nach.
			// Startdatum als Stichtag: sonst verschöbe ein kurzer Monat die
			// Fälligkeit dauerhaft (aus dem 31. würde über den Februar der 28.).
			$fee->setNextDueDate(BillingPeriod::next($fee->getNextDueDate(), $fee->getFrequency(), $fee->getStartDate()));
			$this->mapper->update($fee);
			$count++;
		}
		if ($count > 0) {
			$this->audit->log('Beitragsfälligkeiten erzeugt', 'membership_fee', null, ['anzahl' => $count], self::ACTOR);
		}
		return $count;
	}

	private function frequencyLabel(string $frequency): string {
		return match ($frequency) {
			'monthly' => $this->l10n->t('monatlich'),
			'quarterly' => $this->l10n->t('vierteljährlich'),
			'semiannual' => $this->l10n->t('halbjährlich'),
			'yearly' => $this->l10n->t('jährlich'),
			default => $frequency,
		};
	}

	private function requirePositiveAmount(int $amountCents): int {
		if ($amountCents <= 0) {
			throw new \InvalidArgumentException($this->l10n->t('Betrag muss größer als 0 sein.'));
		}
		return $amountCents;
	}

	private function validateFrequency(string $frequency): string {
		if (!isset(BillingPeriod::FREQUENCY_MONTHS[$frequency])) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültige Zahlungsfrequenz: %s', [$frequency]));
		}
		return $frequency;
	}

	/**
	 * Neben dem Format muss der Tag den Kalender überstehen: mit einem
	 * unmöglichen Datum bricht {@see BillingPeriod::next()} ab, und eine
	 * Fälligkeit, die niemand mehr fortschreiben kann, bliebe für immer stehen.
	 */
	private function validateDate(string $date): string {
		$date = trim($date);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültiges Datum (erwartet JJJJ-MM-TT).'));
		}
		if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException($this->l10n->t('Diesen Tag gibt es nicht: %s', [$date]));
		}
		return $date;
	}

	private function resolveAccountId(?int $accountId): ?int {
		if ($accountId === null || $accountId <= 0) {
			return null;
		}
		try {
			return $this->accountMapper->find($accountId, Application::BOOK)->getId();
		} catch (DoesNotExistException) {
			throw new \InvalidArgumentException($this->l10n->t('Das gewählte Ertragskonto wurde nicht gefunden.'));
		}
	}

	/**
	 * Ein verknüpftes Mandat muss zum selben Zahler gehören und aktiv sein –
	 * sonst könnte ein Beitrag versehentlich über das Konto einer anderen
	 * Person eingezogen werden.
	 */
	private function resolveMandateId(?int $mandateId, ?string $memberUid, ?string $memberLabel): ?int {
		if ($mandateId === null || $mandateId <= 0) {
			return null;
		}
		try {
			$mandate = $this->mandateMapper->find($mandateId);
		} catch (DoesNotExistException) {
			throw new \InvalidArgumentException($this->l10n->t('Das gewählte SEPA-Mandat wurde nicht gefunden.'));
		}
		if ($mandate->getStatus() !== 'active') {
			throw new \InvalidArgumentException($this->l10n->t('Das gewählte SEPA-Mandat ist widerrufen.'));
		}
		if ($mandate->getMemberUid() !== $memberUid || $mandate->getMemberLabel() !== $memberLabel) {
			throw new \InvalidArgumentException($this->l10n->t('Das gewählte SEPA-Mandat gehört zu einem anderen Zahler.'));
		}
		return $mandate->getId();
	}
}

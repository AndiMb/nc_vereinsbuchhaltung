<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\CostCenter;

use OCA\Vereinsbuchhaltung\Db\BookingCostCenter;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterBudgetMapper;
use OCA\Vereinsbuchhaltung\Db\LineAllocationMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Stammdaten der Kostenstellen an der Buchungszeile (Issue #7).
 *
 * Eine Kostenstelle ist hier eine Abteilung, Mannschaft oder ein Projekt,
 * dem einzelne Beträge zugeordnet werden. Die Auswertungsgruppen, die ganze
 * Konten zusammenfassen und im Code noch `CostCenter` heißen, pflegt
 * {@see \OCA\Vereinsbuchhaltung\Service\CostCenterService}.
 *
 * Lesen geht immer; geschrieben wird nur bei eingeschaltetem Modul.
 */
class BookingCostCenterService {

	/** Länge der Spalte vbh_booking_cc.code (siehe Migration 000177). */
	private const CODE_MAX = 8;

	public function __construct(
		private BookingCostCenterMapper $mapper,
		private LineAllocationMapper $allocations,
		private CostCenterSettings $settings,
		private AuditService $audit,
		private IL10N $l10n,
		// Vorgaben aus Stammdaten (Regeln, …), die auf eine Kostenstelle zeigen.
		private ?CostCenterDefaults $defaults = null,
		// Budget je Kostenstelle (Issue #335): geht mit der Kostenstelle.
		private ?CostCenterBudgetMapper $budgets = null,
	) {
	}

	/**
	 * @return BookingCostCenter[]
	 */
	public function all(string $userId): array {
		return $this->mapper->findAll($userId);
	}

	public function create(string $userId, string $code, string $name): BookingCostCenter {
		$this->assertEnabled();
		$code = $this->validateCode($code);
		$name = $this->validateName($name);
		if ($this->mapper->findByCode($userId, $code) !== null) {
			throw new \InvalidArgumentException($this->l10n->t('Es gibt bereits eine Kostenstelle mit dem Kürzel „%s".', [$code]));
		}
		$cc = new BookingCostCenter();
		$cc->setUserId($userId);
		$cc->setCode($code);
		$cc->setName($name);
		$cc->setActive(true);
		$cc = $this->mapper->insert($cc);
		$this->audit->log('Kostenstelle angelegt', 'booking_cost_center', $cc->getId(), [
			'code' => $code,
			'name' => $name,
		]);
		return $cc;
	}

	/**
	 * @throws DoesNotExistException wenn es die Kostenstelle nicht (mehr) gibt
	 */
	public function update(int $id, string $userId, string $code, string $name, bool $active): BookingCostCenter {
		$this->assertEnabled();
		$code = $this->validateCode($code);
		$name = $this->validateName($name);
		$cc = $this->mapper->find($id, $userId);
		$other = $this->mapper->findByCode($userId, $code);
		if ($other !== null && $other->getId() !== $cc->getId()) {
			throw new \InvalidArgumentException($this->l10n->t('Es gibt bereits eine Kostenstelle mit dem Kürzel „%s".', [$code]));
		}
		$cc->setCode($code);
		$cc->setName($name);
		$cc->setActive($active);
		$cc = $this->mapper->update($cc);
		$this->audit->log('Kostenstelle geändert', 'booking_cost_center', $cc->getId(), [
			'code' => $code,
			'name' => $name,
			'aktiv' => $active,
		]);
		return $cc;
	}

	/**
	 * Löscht eine Kostenstelle, der noch kein Betrag zugeordnet ist.
	 *
	 * Eine verwendete Kostenstelle bleibt: ihre Beträge stünden sonst ohne
	 * Namen im Bericht. Sie lässt sich stattdessen deaktivieren.
	 *
	 * @throws DoesNotExistException wenn es die Kostenstelle nicht (mehr) gibt
	 */
	public function delete(int $id, string $userId): void {
		$this->assertEnabled();
		$cc = $this->mapper->find($id, $userId);
		if ($this->allocations->countByCostCenter($cc->getId()) > 0) {
			throw new \InvalidArgumentException($this->l10n->t('Der Kostenstelle „%s" sind Buchungen zugeordnet. Sie lässt sich deaktivieren, aber nicht löschen.', [$cc->getCode()]));
		}
		$inUse = $this->usageText($cc->getId());
		if ($inUse !== '') {
			throw new \InvalidArgumentException($this->l10n->t('Die Kostenstelle „%1$s" ist noch als Vorgabe eingetragen (%2$s). Sie lässt sich deaktivieren, aber nicht löschen.', [$cc->getCode(), $inUse]));
		}
		// Planwerte sind keine Buchungen und halten die Kostenstelle nicht fest.
		$this->budgets?->deleteByCostCenter((int)$cc->getId());
		$this->mapper->delete($cc);
		$this->audit->log('Kostenstelle gelöscht', 'booking_cost_center', $id, [
			'code' => $cc->getCode(),
			'name' => $cc->getName(),
		]);
	}

	/**
	 * Wo die Kostenstelle noch als Vorgabe eingetragen ist, als Aufzählung
	 * für die Meldung; leer, wenn nirgends.
	 */
	private function usageText(int $costCenterId): string {
		$labels = [
			'rules' => $this->l10n->t('Importregeln'),
			'invoiceLines' => $this->l10n->t('Rechnungspositionen'),
			'contributionGroups' => $this->l10n->t('Beitragsgruppen'),
		];
		$parts = [];
		foreach ($this->defaults?->usage($costCenterId) ?? [] as $kind => $count) {
			if ($count > 0) {
				$parts[] = ($labels[$kind] ?? $kind) . ': ' . $count;
			}
		}
		return implode(', ', $parts);
	}

	private function assertEnabled(): void {
		if (!$this->settings->isEnabled()) {
			throw new \InvalidArgumentException($this->l10n->t('Kostenstellen sind in den Einstellungen ausgeschaltet.'));
		}
	}

	private function validateCode(string $code): string {
		$code = trim($code);
		if ($code === '') {
			throw new \InvalidArgumentException($this->l10n->t('Das Kürzel der Kostenstelle ist Pflicht.'));
		}
		if (mb_strlen($code) > self::CODE_MAX) {
			throw new \InvalidArgumentException($this->l10n->t('Das Kürzel darf höchstens %d Zeichen lang sein.', [self::CODE_MAX]));
		}
		return $code;
	}

	private function validateName(string $name): string {
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException($this->l10n->t('Der Name der Kostenstelle ist Pflicht.'));
		}
		return mb_substr($name, 0, 255);
	}
}

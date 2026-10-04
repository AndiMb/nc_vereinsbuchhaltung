<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;

/**
 * Die Lauf-Bündelung als reine Abfrage (Spec §2.2/§3.5 „Lastschriftlauf
 * (Debit Batch) … Existiert nicht vor der Freigabe – davor ist der Lauf eine
 * Abfrage", Issue #70). „Ein Lauf bündelt Forderungen aller Turnusse, deren
 * Periode auf denselben Termin zeigt, plus manuelle Einzelforderungen und
 * Prorata-Erstforderungen mit diesem Termin."
 *
 * Weil {@see ClaimGenerationService} jede Forderung – ob Turnus, manuell
 * (Issue #68 {@see ClaimService::createManual()}) oder Prorata-Erstforderung –
 * gleich als Zeile mit `due_date` in `vbh_open_items` anlegt, ist das Bündeln
 * nichts weiter als ein Filter auf dieses eine Feld – siehe
 * {@see OpenItemMapper::findClaimsDueOn()}.
 *
 * Erweiterung durch Issue #71 (die tatsächliche Freigabe, Snapshot + pain.008,
 * `DebitBatch`/`DebitItem`, baut auf dieser Abfrage auf): eine Forderung, die
 * bereits in einem lebenden Lauf steckt (`freigegeben`/`eingereicht`), gehört
 * nicht mehr in eine neue Vorschau – „eine Forderung hat höchstens einen
 * Einzugsposten" (Spec §2.2). {@see DebitBatchService::release()} ruft
 * {@see preview()} bei der Freigabe selbst noch einmal frisch auf ("erneut
 * bei Freigabe geprüft"), diese Ausschluss-Regel ist deshalb zugleich der
 * Schutz gegen einen doppelten Einzug derselben Forderung.
 */
class DebitRunQueryService {

	public function __construct(
		private OpenItemMapper $openItems,
		private DirectDebitEligibilityResolver $eligibility,
		private DebitItemMapper $debitItems,
	) {
	}

	/**
	 * Alle lastschriftfähigen, noch nicht in einem lebenden Lauf gebündelten
	 * Forderungen mit Fälligkeit `$dueDate` – reine Vorschau, kein
	 * Lauf-Datensatz.
	 *
	 * @return OpenItem[]
	 */
	public function preview(string $dueDate): array {
		$alreadyBatched = $this->debitItems->findOpenItemIdsInLiveBatches();
		return array_values(array_filter(
			$this->openItems->findClaimsDueOn($dueDate),
			fn (OpenItem $item): bool => !in_array($item->getId(), $alreadyBatched, true) && $this->eligibility->isEligible($item),
		));
	}

	/** @return array{count:int, sumCents:int} */
	public function summary(string $dueDate): array {
		return self::summarize($this->preview($dueDate));
	}

	/**
	 * {@see summary()} für ALLE Termine auf einmal – die Datengrundlage des
	 * Zeitstrahls im Einzug-Unterreiter (Issue #102), der je Termin „N
	 * Forderungen, Summe" zeigt. Dieselbe Auswahlregel wie {@see preview()}
	 * (offen, nicht storniert, nicht in einem lebenden Lauf, einzugsfähig),
	 * nur in EINEM Durchgang über die Forderungen statt einer Abfrage je Termin.
	 *
	 * @return array<string, array{count:int, sumCents:int}> nur Termine mit mindestens einer Forderung, Schlüssel = Fälligkeitsdatum
	 */
	public function summariesByDueDate(): array {
		$alreadyBatched = $this->debitItems->findOpenItemIdsInLiveBatches();
		/** @var array<string, OpenItem[]> $byDate */
		$byDate = [];
		foreach ($this->openItems->findClaims() as $item) {
			if ($item->getDueDate() === null
				|| $item->getStatus() !== 'open'
				|| $item->getCancelledAt() !== null
				|| in_array($item->getId(), $alreadyBatched, true)
				|| !$this->eligibility->isEligible($item)) {
				continue;
			}
			$byDate[$item->getDueDate()][] = $item;
		}
		return array_map(self::summarize(...), $byDate);
	}

	/**
	 * @param OpenItem[] $items
	 * @return array{count:int, sumCents:int}
	 */
	private static function summarize(array $items): array {
		return [
			'count' => count($items),
			'sumCents' => array_sum(array_map(static fn (OpenItem $i) => $i->getAmountCents(), $items)),
		];
	}
}

<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Exception\PeriodClosedException;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Festschreibung an den Konto-Stammdaten.
 *
 * Der Jahresabschluss schützt die Buchungssätze eines Geschäftsjahres – nicht
 * aber die Konten, aus denen der Bericht gerechnet wird. Ein Wechsel der
 * Kontoart dreht über Account::isCreditNature() das Vorzeichen, ein Wechsel
 * des Geldkonto-Kennzeichens verschiebt das Konto über isStockAccount()
 * zwischen Vermögensübersicht und Einnahmen-/Ausgaben-Rechnung, Sphäre,
 * Rücklagen-Art und Auswertungsgruppe bestimmen die Zeile der jeweiligen
 * Übersicht. All das ginge rückwirkend in den Bericht eines festgeschriebenen
 * Zeitraums ein, ohne dass eine einzige Buchung angefasst wurde.
 *
 * Gesperrt wird nur, wenn das Konto in einem abgeschlossenen Zeitraum
 * tatsächlich bebucht ist. Ein unbenutztes oder erst später bebuchtes Konto
 * bleibt frei änderbar.
 *
 * Eigene Klasse, weil die Prüfung an mehreren Stellen gebraucht wird: am
 * einzelnen Konto (AccountService) und bei der Zuordnung zu Auswertungsgruppen
 * (CostCenterService, #300) – Letzterer kann AccountService nicht nutzen, der
 * hängt selbst von ihm ab.
 */
class AccountEvaluationLock {

	public function __construct(
		private JournalLineMapper $lineMapper,
		private PeriodService $periods,
		private IAppConfig $config,
		private IL10N $l10n,
	) {
	}

	/**
	 * Zählt die aus der Kontonummer abgeleitete Kostenstelle (zweite
	 * Zahlengruppe) für die Auswertung? Nur im Modus 'group' (#299); in den
	 * anderen Modi ist die Nummer reine Beschriftung.
	 */
	public function costCodeIsEvaluated(): bool {
		$mode = $this->config->getValueString(Application::APP_ID, 'cost_center_mode', 'group');
		return !in_array($mode, ReportService::MODES, true) || $mode === 'group';
	}

	/**
	 * @param string[] $changed Feldnamen (siehe AccountService::EVALUATION_FIELDS)
	 * @throws PeriodClosedException wenn das Konto einen abgeschlossenen Zeitraum berührt
	 */
	public function assertOpen(string $userId, int $accountId, string $label, array $changed): void {
		$closedIds = array_values(array_intersect(
			$this->lineMapper->findPeriodIdsForAccount($userId, $accountId),
			$this->periods->closedIds($userId),
		));
		if ($closedIds === []) {
			return;
		}
		// In der Meldung steht die Bezeichnung, nicht die ID: „2025/26" sagt
		// dem Kassenwart etwas, eine Perioden-ID nichts.
		$closed = array_map(fn (int $id): string => $this->periods->find($userId, $id)->getLabel(), $closedIds);
		throw new PeriodClosedException($this->l10n->t(
			'Das Konto "%s" ist in %s bebucht – %s dort abgeschlossen. %s lässt sich deshalb nicht mehr ändern: '
			. 'die Auswertungen dieses Zeitraums würden sich nachträglich verschieben. '
			. 'Wer die Änderung braucht, eröffnet den Zeitraum wieder und schließt ihn danach erneut ab.',
			[
				$label,
				count($closed) === 1 ? $closed[0] : implode(', ', $closed),
				count($closed) === 1 ? $this->l10n->t('dieses Geschäftsjahr ist') : $this->l10n->t('diese Geschäftsjahre sind'),
				count($changed) === 1
					? $this->fieldLabel($changed[0])
					: implode(' ' . $this->l10n->t('und') . ' ', array_map($this->fieldLabel(...), $changed)),
			],
		));
	}

	/** Feldname → Bezeichnung für Meldungen und Protokoll. */
	public function fieldLabel(string $field): string {
		return match ($field) {
			'type' => $this->l10n->t('Kontoart'),
			'isBank' => $this->l10n->t('Geldkonto-Kennzeichen'),
			'sphere' => $this->l10n->t('Sphäre'),
			'reserveKind' => $this->l10n->t('Rücklagen-Art'),
			'costCenterId' => $this->l10n->t('Auswertungsgruppe'),
			'costCode' => $this->l10n->t('Auswertungsgruppe in der Kontonummer'),
			default => $field,
		};
	}
}

<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\DunningNotice;
use OCA\Vereinsbuchhaltung\Db\DunningNoticeMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Vorstands-Eskalation nach der dritten Mahnstufe (Spec §3.6/§7 „danach
 * Aufgabe 'Vorstand entscheiden lassen', keine weitere Automatik", Issue
 * #73) – abgeleitete Abfrage ohne eigene Persistenz, gleiches Muster wie
 * {@see ContributionCycleTaskService}: „Störfälle blockieren nie, niemand
 * quittiert sie" (Spec §7). Die Aufgabe verschwindet von selbst, sobald die
 * Forderung einen Erledigungsvermerk (`paid`/`waived`) oder eine Stundung
 * bekommt – beides schließt sie über {@see ClaimStateResolver}/das
 * Stundungs-Feld aus der Kandidatenliste aus.
 *
 * Auslöser: Stufe 2 („Mahnung") wurde versendet UND seitdem ist erneut der
 * konfigurierte Mahnabstand verstrichen ({@see DunningSettings::intervalDays()})
 * – „+ Mahnabstand, kündigt Eskalation an" (Spec §3.6 Mahnstufen-Tabelle,
 * Stufe 2 selbst kündigt die Eskalation im Mail-Text nur an, siehe
 * {@see DunningLadderService}).
 */
final class DunningTaskService {

	public function __construct(
		private OpenItemMapper $openItems,
		private DunningNoticeMapper $notices,
		private MemberMapper $members,
		private DunningSettings $settings,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/** @return list<array{severity:string,message:string,objectType:string,objectId:int}> */
	public function findBoardEscalationTasks(?string $today = null): array {
		$today ??= $this->today();
		$intervalDays = $this->settings->intervalDays();

		$tasks = [];
		foreach ($this->openItems->findClaims() as $item) {
			if (ClaimStateResolver::resolveForItem($item) !== ClaimStateResolver::STATE_OPEN) {
				continue;
			}
			// Waehrend einer aktiven Stundung eskaliert nichts weiter (dieselbe
			// Zusatzbedingung wie bei der Stufe-1/2-Ableitung selbst).
			if ($item->getDeferredUntil() !== null && $item->getDeferredUntil() >= $today) {
				continue;
			}
			$dunningNotice = $this->notices->findByOpenItemAndStage((int)$item->getId(), DunningNotice::STAGE_DUNNING);
			if ($dunningNotice === null) {
				continue;
			}
			$deadline = (new \DateTimeImmutable($dunningNotice->getSentAt()))->modify('+' . $intervalDays . ' days')->format('Y-m-d');
			if ($today < $deadline) {
				continue;
			}
			$name = $this->members->displayNameOr($item->getMemberId(), $this->l10n->t('unbekanntes Mitglied'));
			$amount = number_format($item->getAmountCents() / 100, 2, ',', '.');
			$tasks[] = [
				'severity' => Task::SEVERITY_ACTION_REQUIRED,
				'message' => $this->l10n->t('Mahnstufe an Vorstand eskaliert: %1$s, %2$s € (%3$s).', [$name, $amount, (string)($item->getDescription() ?? $this->l10n->t('Beitrag'))]),
				'objectType' => 'claim',
				'objectId' => (int)$item->getId(),
			];
		}
		return $tasks;
	}

	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}
}

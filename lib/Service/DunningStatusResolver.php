<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\DunningNotice;
use OCA\Vereinsbuchhaltung\Db\OpenItem;

/**
 * Der Mahnstand einer Forderung als reine Ableitung (Spec §2.2 „Mahnversand …
 * Einzige Mahnwesen-Persistenz – alles andere ist Ableitung", Issue #104):
 * welche Stufe erreicht ist, wann sie versandt wurde und wann die nächste
 * fällig wird. Rein lesend und ohne Seiteneffekte – versandt wird weiterhin
 * nur von {@see DunningLadderService}.
 *
 * Die Regeln spiegeln die des Versands und der Eskalations-Aufgabe
 * ({@see DunningLadderService::dueEscalationsByMember()},
 * {@see DunningTaskService::findBoardEscalationTasks()}), damit die Anzeige
 * nichts verspricht, was der tägliche Lauf nicht hält:
 * - Stufe 1 und 2 sowie die Eskalation bemessen sich am `sent_at` der zuletzt
 *   erreichten Stufe plus Mahnabstand;
 * - eine **aktive Stundung** (`deferredUntil` ≥ heute) pausiert genau diese
 *   Übergänge – der Lauf versendet frühestens am Tag NACH ihrem Ende, und die
 *   Uhr läuft von der zuletzt erreichten Stufe weiter (kein Reset);
 * - Stufe 0 (Zahlungsaufforderung) hat kein Stundungs-Gate (Spec: „ja,
 *   gatefrei"), ihr Termin kommt fertig vom Aufrufer ({@see resolve()},
 *   `$paymentRequestDueOn`), weil er von der Lastschrift-Eignung abhängt;
 * - nur eine **offene** Forderung bekommt noch eine nächste Stufe; eine
 *   erledigte oder stornierte behält ihren erreichten Stand als Historie.
 *
 * Die Stufen heißen hier wie im Backend: 0 Zahlungsaufforderung, 1
 * Zahlungserinnerung, 2 Mahnung; {@see NEXT_ESCALATION} steht für den Schritt
 * danach (Aufgabe „Vorstand entscheiden lassen", keine weitere Automatik).
 */
final class DunningStatusResolver {

	/** „Nächster Schritt" nach der Mahnung: die Eskalation an den Vorstand (Spec §3.6). */
	public const NEXT_ESCALATION = 3;

	private function __construct() {
		// Reine Ableitungslogik, keine Instanz nötig – wie ClaimStateResolver.
	}

	/**
	 * @param DunningNotice[] $notices die bisher versandten Stufen genau dieser Forderung
	 * @param int $intervalDays Mahnabstand ({@see DunningSettings::intervalDays()})
	 * @param string $today Stichtag (JJJJ-MM-TT)
	 * @param string|null $paymentRequestDueOn wann die Zahlungsaufforderung (Stufe 0) versandt würde,
	 *                                         wenn noch keine versandt ist – null, wenn keine
	 *                                         automatische zu erwarten ist (Lastschrift-Forderungen
	 *                                         bekommen sie erst nach einer Rücklastschrift/einem Widerruf)
	 * @return array{
	 *     stage: int|null,
	 *     escalated: bool,
	 *     notices: list<array{stage:int, sentAt:string}>,
	 *     nextStage: int|null,
	 *     nextDueOn: string|null
	 * } `stage` = höchste erreichte Stufe (0–2) oder null; `escalated` = die Aufgabe „an Vorstand
	 *   eskaliert" besteht (Stufe 2 + Mahnabstand verstrichen, nicht gestundet); `nextStage` = 0–2
	 *   oder {@see NEXT_ESCALATION}, null wenn nichts mehr kommt
	 */
	public static function resolve(OpenItem $claim, array $notices, int $intervalDays, string $today, ?string $paymentRequestDueOn = null): array {
		/** @var array<int,DunningNotice> $byStage */
		$byStage = [];
		foreach ($notices as $notice) {
			$byStage[$notice->getStage()] = $notice;
		}
		ksort($byStage);
		$list = array_values(array_map(
			static fn (DunningNotice $n): array => ['stage' => $n->getStage(), 'sentAt' => $n->getSentAt()],
			$byStage,
		));
		$reached = $byStage === [] ? null : array_key_last($byStage);
		$status = ['stage' => $reached, 'escalated' => false, 'notices' => $list, 'nextStage' => null, 'nextDueOn' => null];

		if (ClaimStateResolver::resolveForItem($claim) !== ClaimStateResolver::STATE_OPEN) {
			return $status;
		}

		if ($reached === null) {
			if ($paymentRequestDueOn !== null) {
				$status['nextStage'] = DunningNotice::STAGE_PAYMENT_REQUEST;
				$status['nextDueOn'] = $paymentRequestDueOn;
			}
			return $status;
		}

		$deadline = (new \DateTimeImmutable($byStage[$reached]->getSentAt()))->modify('+' . $intervalDays . ' days')->format('Y-m-d');
		$dueOn = $deadline;
		$deferredUntil = $claim->getDeferredUntil();
		if ($deferredUntil !== null && $deferredUntil >= $today) {
			$afterDeferral = (new \DateTimeImmutable($deferredUntil))->modify('+1 day')->format('Y-m-d');
			$dueOn = max($deadline, $afterDeferral);
		}

		if ($reached === DunningNotice::STAGE_DUNNING) {
			// Nach der Mahnung kommt keine Automatik mehr, nur noch die Aufgabe.
			if ($today >= $dueOn) {
				$status['escalated'] = true;
				return $status;
			}
			$status['nextStage'] = self::NEXT_ESCALATION;
			$status['nextDueOn'] = $dueOn;
			return $status;
		}

		$status['nextStage'] = $reached + 1;
		$status['nextDueOn'] = $dueOn;
		return $status;
	}
}

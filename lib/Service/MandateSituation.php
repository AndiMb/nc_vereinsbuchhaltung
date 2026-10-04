<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Mandate;

/**
 * Mandatslage eines Mitglieds – die eine Antwort auf „warum ist dieses
 * Mitglied nicht einzugsfähig?", gebraucht von den Aufgaben-Diensten
 * ({@see MandateTaskService}, {@see ContributionCycleTaskService}): beide
 * müssen sich einig sein, welcher Fall bei wem vorliegt, sonst stünde für
 * dasselbe Problem eine Aufgabe doppelt in der Liste oder gar keine.
 *
 * Reine Ableitung aus den Mandaten EINES Mitglieds, ohne Datenbankzugriff.
 * Es gibt höchstens ein lebendes Mandat je Mitglied (Spec §2.2, vom
 * {@see MandateService} durchgesetzt); die Rangfolge unten greift nur bei
 * einem Datenfehler und lässt dann das „beste" Mandat gewinnen, damit ein
 * Mitglied mit einem einzugsfähigen Mandat nie als Störfall erscheint.
 */
final class MandateSituation {

	/** Es wurde nie ein Mandat angelegt. */
	public const NONE = 'none';
	/** Ein aktives Mandat – einzugsfähig, kein Störfall. */
	public const COLLECTIBLE = 'collectible';
	/** Gesperrt (manuell oder nach Rücklastschrift) – Klärung offen. */
	public const SUSPENDED = 'suspended';
	/** Entwurf auf Papier – die Unterschrift fehlt. */
	public const DRAFT_PAPER = 'draft_paper';
	/** Entwurf mit elektronischem Weg – wartet auf die Zustimmung des Mitglieds. */
	public const DRAFT_ELECTRONIC = 'draft_electronic';
	/** Es gibt nur noch erloschene Mandate (widerrufen, verfallen, beendet, ersetzt). */
	public const ENDED = 'ended';

	private function __construct() {
		// Nur statische Ableitungen, keine Instanz nötig.
	}

	/**
	 * @param Mandate[] $mandates alle Mandate EINES Mitglieds
	 */
	public static function of(array $mandates): string {
		if ($mandates === []) {
			return self::NONE;
		}
		$situation = self::ENDED;
		$rank = [self::ENDED => 0, self::DRAFT_ELECTRONIC => 1, self::DRAFT_PAPER => 2, self::SUSPENDED => 3, self::COLLECTIBLE => 4];
		foreach ($mandates as $mandate) {
			$candidate = match ($mandate->getStatus()) {
				Mandate::STATUS_ACTIVE => self::COLLECTIBLE,
				Mandate::STATUS_SUSPENDED => self::SUSPENDED,
				Mandate::STATUS_DRAFT => $mandate->isElectronic() ? self::DRAFT_ELECTRONIC : self::DRAFT_PAPER,
				default => self::ENDED,
			};
			if ($rank[$candidate] > $rank[$situation]) {
				$situation = $candidate;
			}
		}
		return $situation;
	}

	/**
	 * Die Lage aller Mitglieder, die mindestens ein Mandat haben – eine
	 * Gruppierung statt einer Abfrage je Mitglied.
	 *
	 * @param Mandate[] $mandates Mandate beliebig vieler Mitglieder
	 * @return array<int,string> Mitglieds-ID => Lage (Mitglieder ohne Mandat fehlen, ihre Lage ist {@see NONE})
	 */
	public static function byMember(array $mandates): array {
		/** @var array<int,list<Mandate>> $grouped */
		$grouped = [];
		foreach ($mandates as $mandate) {
			$grouped[$mandate->getMemberId()][] = $mandate;
		}
		return array_map(self::of(...), $grouped);
	}

	/**
	 * Ob für diese Lage eine eigene Aufgabe existiert, die die Ursache genauer
	 * benennt als das allgemeine „Lastschrift gewollt, aber kein einzugsfähiges
	 * Mandat" ({@see MandateTaskService}: Entwurf auf Papier, gesperrt,
	 * erloschen). Beim elektronischen Entwurf nur dann, wenn ein Link
	 * verschickt wurde – das weiß diese Ableitung nicht, deshalb bleibt die
	 * allgemeine Aufgabe dort bestehen.
	 */
	public static function hasOwnTask(string $situation): bool {
		return in_array($situation, [self::SUSPENDED, self::DRAFT_PAPER, self::ENDED], true);
	}
}

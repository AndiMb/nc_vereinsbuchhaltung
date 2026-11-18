<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Backup;

use OCP\IConfig;

/**
 * Herkunft einer Sicherung: stammt sie von dieser Nextcloud? (Review
 * 2026-11-18, Sicherheit I-2)
 *
 * Rollen und Mitglied-Verknüpfungen hängen an Nextcloud-Benutzer- und
 * Gruppennamen. Auf einer anderen Instanz können dieselben Namen anderen
 * Menschen gehören – dort darf eine bestätigte Verknüpfung (#295) nicht
 * einfach weitergelten, und wer eine Rolle bekommt, muss im Bericht stehen.
 *
 * Erkannt wird die Instanz an Nextclouds `instanceid` aus der config.php.
 * In die Sicherung kommt nur ein Abdruck davon: die Kennung selbst steckt
 * in Cookie-Namen und gehört nicht in eine Datei, die weitergegeben wird.
 */
final class BackupInstanceMarker {

	/** Der Abdruck in der Sicherung ist der dieser Nextcloud. */
	public const ORIGIN_SAME = 'same';
	/** Die Sicherung trägt den Abdruck einer anderen Nextcloud – auch einer neu installierten. */
	public const ORIGIN_FOREIGN = 'foreign';
	/** Kein Abdruck in der Sicherung, oder diese Nextcloud hat keine Kennung zum Vergleich. */
	public const ORIGIN_UNKNOWN = 'unknown';

	public function __construct(
		private IConfig $config,
	) {
	}

	/** Abdruck dieser Instanz für das Manifest; leer, wenn Nextcloud keine Kennung hat. */
	public function current(): string {
		$id = trim($this->config->getSystemValueString('instanceid', ''));
		return $id === '' ? '' : hash('sha256', 'vereinsbuchhaltung-backup|' . $id);
	}

	/**
	 * Woher stammt eine Sicherung mit diesem Abdruck?
	 *
	 * Fremd ist sie nur, wenn sie nachweislich von einer anderen Nextcloud
	 * kommt. Fehlt der Abdruck (oder hat diese Nextcloud keine Kennung), ist
	 * die Herkunft unbekannt: das Einspielen behält dann bestätigte
	 * Verknüpfungen, warnt aber im Bericht.
	 *
	 * Kein Schutz gegen Fälschung: die Kennung steckt im Namen des
	 * Sitzungs-Cookies, wer eine Sicherung präpariert, kann „dieselbe
	 * Nextcloud“ vortäuschen. Der Abdruck verhindert nur, dass eine echte
	 * Sicherung einer anderen Instanz unbemerkt Namen verknüpft, die hier
	 * anderen Menschen gehören.
	 *
	 * @return self::ORIGIN_*
	 */
	public function origin(mixed $marker): string {
		if (!is_string($marker) || $marker === '') {
			return self::ORIGIN_UNKNOWN;
		}
		$own = $this->current();
		if ($own === '') {
			return self::ORIGIN_UNKNOWN;
		}
		return hash_equals($own, $marker) ? self::ORIGIN_SAME : self::ORIGIN_FOREIGN;
	}
}

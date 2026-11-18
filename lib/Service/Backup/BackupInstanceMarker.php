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
	 * Stammt eine Sicherung mit diesem Abdruck von hier? Ohne Abdruck (ältere
	 * Sicherungen) oder ohne eigene Kennung ist die Herkunft unbekannt und
	 * gilt als fremd – das ist die vorsichtige Seite.
	 */
	public function isThisInstance(mixed $marker): bool {
		$own = $this->current();
		return $own !== '' && is_string($marker) && hash_equals($own, $marker);
	}
}

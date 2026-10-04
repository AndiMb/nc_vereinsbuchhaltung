<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\IL10N;

/**
 * Die Regeln für einen Ordnerpfad in den Einstellungen (Belegablage,
 * Wachordner, Nachweis-Ordner der Mandate, XML-Ablage der Einzugsdateien).
 *
 * Vorher stand die Prüfung als private Methode in SettingsController. Seit
 * die XML-Ablage (DebitBatchController) denselben Pfad-Typ annimmt, würde ein
 * zweiter Wortlaut der Regeln früher oder später auseinanderlaufen – eine
 * Ablage, die der eine Endpunkt annimmt und der andere ablehnt. Hier steht
 * die eine Fassung.
 *
 * Geprüft wird nur, ob der Pfad als Nextcloud-Ordnerpfad taugt. Ob der Ordner
 * existiert, ist Sache des Aufrufers: der Wächter-Ordner muss existieren, die
 * Nachweis- und XML-Ordner legt die App bei Bedarf selbst an.
 */
class FolderPathValidator {

	public const MAX_LENGTH = 200;

	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * @param string $pathLabel wie der Pfad in Meldungen heißen soll
	 * @return string|null Fehlermeldung oder null, wenn der Pfad in Ordnung ist
	 *                     (ein leerer Pfad gilt als in Ordnung: der Aufrufer setzt dann seinen Standardpfad)
	 */
	public function validate(string $path, string $pathLabel): ?string {
		$normalized = trim(str_replace('\\', '/', $path), '/');
		if ($normalized === '') {
			return null; // leer -> Standardpfad, wird vom Aufrufer gesetzt
		}
		foreach (explode('/', $normalized) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return $this->l10n->t('Ungültiger %s: "." und ".." sind nicht erlaubt.', [$pathLabel]);
			}
		}
		// Nextcloud verbietet diese Zeichen in Dateinamen; ein Pfad damit wäre
		// nicht anlegbar und der Fehler erst beim ersten Beleg-Upload sichtbar.
		if (preg_match('/[\\\\:*?"<>|]/', $normalized) === 1) {
			return $this->l10n->t('Ungültiger %s: enthält unzulässige Zeichen.', [$pathLabel]);
		}
		if (mb_strlen($normalized) > self::MAX_LENGTH) {
			return $this->l10n->t('Der %s ist zu lang (max. 200 Zeichen).', [$pathLabel]);
		}
		return null;
	}
}

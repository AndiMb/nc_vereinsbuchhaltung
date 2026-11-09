<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\IL10N;

/**
 * Ordner im Home eines anderen Nutzers wählt nur, wer als Nextcloud-
 * Administrator ohnehin an fremde Homes herankommt.
 *
 * Die App legt in den konfigurierten Ordnern Dateien im Home eines echten
 * Nextcloud-Nutzers an – mit den Rechten der App, nicht denen des Handelnden.
 * Ein Verwalter der App muss kein Nextcloud-Administrator sein und darf so
 * nicht an die Dateien anderer Nutzer kommen. Das gilt für die Belegablage und
 * den Wachordner (Nutzer und Pfad), aber ebenso für die Unterordner im Home
 * des Belegablage-Nutzers: Mandats-Nachweise, Rechnungen und die SEPA-XML-
 * Dateien (#307).
 *
 * Geprüft wird nur eine tatsächliche Änderung: ein unverändert gespeicherter
 * Wert öffnet keinen fremden Ordner, der nicht schon offen war, und die
 * Einstellungsseite sendet immer den vollständigen Feldsatz.
 */
class ForeignHomeGuard {

	public function __construct(
		private PermissionService $permissions,
		private AttachmentStorageService $attachmentStorage,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return string|null Fehlermeldung, wenn der Handelnde keinen Ordner im Home von `$user` wählen darf
	 */
	public function error(string $user): ?string {
		if ($user === ''
			|| $this->permissions->isServerAdmin()
			|| $user === ($this->permissions->describeCurrent()['uid'] ?? null)) {
			return null;
		}
		return $this->l10n->t('Nur Nextcloud-Administratoren dürfen einen Ordner im Home eines anderen Nutzers wählen.');
	}

	/**
	 * Für einen Unterordner im Home des Belegablage-Nutzers: ändert sich der
	 * Pfad, muss der Handelnde dort Ordner wählen dürfen.
	 *
	 * @param string $stored gespeicherter Pfad
	 * @param string $requested gewünschter Pfad (wird wie beim Speichern normalisiert)
	 * @param string|null $storageUser Belegablage-Nutzer, falls derselbe Request ihn ändert; sonst der gespeicherte
	 */
	public function folderChangeError(string $stored, string $requested, ?string $storageUser = null): ?string {
		if (self::normalize($stored) === self::normalize($requested)) {
			return null;
		}
		return $this->error($storageUser ?? $this->attachmentStorage->storageUser());
	}

	private static function normalize(string $path): string {
		return trim(str_replace('\\', '/', trim($path)), '/');
	}
}

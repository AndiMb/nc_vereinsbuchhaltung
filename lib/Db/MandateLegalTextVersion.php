<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Versionierter Mandats-Rechtstext (Spec §2.2 „MandateLegalTextVersion"/§3.11
 * „Mandats-Rechtstext", Issue #67): geschützter DK-Pflichtblock + freier,
 * admin-editierbarer Rahmen, in EINEM Textkörper (`body`) gespeichert – siehe
 * {@see \OCA\Vereinsbuchhaltung\Service\MandateLegalTextService} für den
 * Aufbau (Marker-Trennung, damit sich der Rahmen für ein Bearbeitungsformular
 * wieder herauslösen lässt).
 *
 * Eigene Tabelle, bewusst NICHT geteilt mit Mail-Templates (die sind
 * Code-Fixtexte, dieser Text ist DB-versioniert und teils admin-editierbar,
 * Spec §3.11). Jede neue Version ist entweder `verwalter`-getrieben (Rahmen
 * geändert) oder `system`-getrieben (App-Update ändert den Pflichtblock).
 *
 * Eine Version wird bei der ERSTEN ANZEIGE des Einmal-Links fixiert (siehe
 * {@see \OCA\Vereinsbuchhaltung\Service\MandateActivationService::view()}),
 * nicht erst beim Klick auf „Zustimmen" – sonst könnte sich der Text
 * zwischen dem Lesen und der Zustimmung ändern, ohne dass das Mitglied ihn
 * je gesehen hat. Keine Rückwirkung auf bestehende Mandate (Spec §2.2).
 *
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class MandateLegalTextVersion extends Entity implements \JsonSerializable {
	protected $createdBy;
	protected $body;
	protected $createdAt;

	public const CREATED_BY_VERWALTER = 'verwalter';
	public const CREATED_BY_SYSTEM = 'system';
	public const CREATED_BY_VALUES = [self::CREATED_BY_VERWALTER, self::CREATED_BY_SYSTEM];

	/**
	 * Ersetzt den `{{creditor_name}}`-Platzhalter (Spec §2.2) durch den
	 * tatsächlichen Vereinsnamen – derselbe gerenderte Text für PDF-Formular
	 * und elektronische Zustimmungsseite (Spec §3.11 „nur die Hülle
	 * unterscheidet sich").
	 */
	public function render(string $creditorName): string {
		return str_replace('{{creditor_name}}', $creditorName, $this->body);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'createdBy' => $this->createdBy,
			'body' => $this->body,
			'createdAt' => $this->createdAt,
		];
	}
}

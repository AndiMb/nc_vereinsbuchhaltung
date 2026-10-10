<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Meldungstexte für Klassen, die einen `IL10N` nur optional bekommen.
 *
 * Die kleinen Einstellungs- und Rechenklassen (zum Beispiel
 * {@see DunningSettings}) werden in vielen Tests von Hand gebaut und brauchen
 * sonst nirgends eine Übersetzung – ihre einzige Textausgabe ist die Meldung,
 * wenn ein Wert außerhalb des erlaubten Bereichs liegt, und die erreicht über
 * die Controller die Oberfläche. Nextcloud reicht den `IL10N` im Betrieb von
 * allein durch (ein Parameter mit Vorgabewert wird trotzdem aufgelöst, wenn der
 * Dienst existiert); wer die Klasse ohne ihn baut, bekommt den deutschen
 * Quelltext. Dasselbe Muster wie bei den Kontoauszug-Parsern.
 *
 * Die verwendende Klasse legt `private ?IL10N $l10n = null` selbst an.
 *
 * @property \OCP\IL10N|null $l10n
 */
trait OptionalL10n {

	/** @param list<int|string> $params Werte für die Platzhalter (`%1$d`, `%s`) */
	private function msg(string $text, array $params = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $params) : vsprintf($text, $params);
	}
}

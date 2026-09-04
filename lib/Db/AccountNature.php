<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

/**
 * Was eine Auswertung von einem Konto wissen muss.
 *
 * Alle Rechenregeln in {@see \OCA\Vereinsbuchhaltung\Service\LedgerAggregator}
 * hängen an genau diesen Fragen. Sie hier zu bündeln hat einen ganz
 * praktischen Grund: {@see Account} erbt von OCP\AppFramework\Db\Entity und ist
 * damit ohne laufende Nextcloud-Instanz nicht ladbar. Die Rechenregeln wären
 * ohne diese Schnittstelle also erneut nur gegen eine echte Instanz prüfbar –
 * und genau das war der Zustand, aus dem sie kommen.
 *
 * Es stehen nur echte Methoden darin. Die Entity-Getter (getNumber(), getType()
 * …) entstehen über __call und können eine Schnittstelle nicht erfüllen; sie
 * werden für die Rechnung auch nicht gebraucht – wer beschriften will, hat das
 * Konto ohnehin in der Hand.
 */
interface AccountNature {

	/** Datenbank-ID, Schlüssel in den Summenkarten der Mapper. */
	public function getId(): int;

	/** Haben-Natur (Einnahmen-Seite): income/liability/equity. */
	public function isCreditNature(): bool;

	/** Bestandskonto: schreibt seinen Saldo über die Jahresgrenze fort. */
	public function isStockAccount(): bool;

	/** Geht mit seiner Jahresbewegung ins Ergebnis ein. */
	public function isResultRelevant(): bool;

	/** Kommt im Finanzplan vor (echte Einnahmen-/Ausgabenkonten). */
	public function isBudgetable(): bool;

	/**
	 * Zählt in den Geldbestand, den die Kopfzeile als eine Zahl zeigt.
	 *
	 * Als einzige Frage hier keine Rechenregel, sondern eine Anzeigefrage: sie
	 * verändert keine Auswertung, sondern nur, welche Geldkonten in der einen
	 * Zahl über der Oberfläche zusammengefasst werden.
	 */
	public function countsInCashTotal(): bool;
}

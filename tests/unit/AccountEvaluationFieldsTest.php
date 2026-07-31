<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\AccountService;
use PHPUnit\Framework\TestCase;

/**
 * Testet die Auswahl der auswertungsrelevanten Konto-Felder.
 *
 * An dieser Auswahl hängt die Festschreibung der Stammdaten: nur eine Änderung
 * an einem dieser Felder darf ein Konto sperren, das in einem abgeschlossenen
 * Geschäftsjahr bebucht ist. Zu eng gefasst, ließe sich der Kassenbericht eines
 * festgeschriebenen Jahres nachträglich verschieben; zu weit gefasst, wäre nach
 * dem ersten Jahresabschluss nicht einmal mehr ein Tippfehler im Kontonamen zu
 * korrigieren.
 */
class AccountEvaluationFieldsTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function snapshot(array $overrides = []): array {
		return $overrides + [
			'type' => 'expense',
			'isBank' => false,
			'sphere' => 'ideell',
			'reserveKind' => null,
			'costCenterId' => null,
		];
	}

	public function testUnveraendertesKontoMeldetNichts(): void {
		$this->assertSame([], AccountService::changedEvaluationFields($this->snapshot(), $this->snapshot()));
	}

	public function testKontoartWechsel(): void {
		$this->assertSame(
			['type'],
			AccountService::changedEvaluationFields($this->snapshot(), $this->snapshot(['type' => 'income'])),
			'Die Kontoart dreht über isCreditNature() das Vorzeichen im Bericht',
		);
	}

	public function testGeldkontoKennzeichenWechsel(): void {
		$this->assertSame(
			['isBank'],
			AccountService::changedEvaluationFields($this->snapshot(), $this->snapshot(['isBank' => true])),
			'isBank verschiebt das Konto zwischen Vermögensübersicht und Einnahmen-/Ausgaben-Rechnung',
		);
	}

	public function testSphaereRuecklageUndKostenstelle(): void {
		$this->assertSame(['sphere'], AccountService::changedEvaluationFields($this->snapshot(), $this->snapshot(['sphere' => 'wirtschaftlich'])));
		$this->assertSame(['reserveKind'], AccountService::changedEvaluationFields($this->snapshot(), $this->snapshot(['reserveKind' => 'frei'])));
		$this->assertSame(['costCenterId'], AccountService::changedEvaluationFields($this->snapshot(), $this->snapshot(['costCenterId' => 3])));
	}

	public function testMehrereFelderAufEinmal(): void {
		$this->assertSame(
			['type', 'isBank'],
			AccountService::changedEvaluationFields($this->snapshot(), $this->snapshot(['type' => 'asset', 'isBank' => true])),
		);
	}

	public function testSphaereEntfernenZaehltAlsAenderung(): void {
		$this->assertSame(
			['sphere'],
			AccountService::changedEvaluationFields($this->snapshot(), $this->snapshot(['sphere' => null])),
		);
	}

	/**
	 * Beschriftung und Einordnung ändern keine Beträge – sie dürfen auch nach
	 * dem Jahresabschluss noch korrigierbar bleiben.
	 */
	public function testNummerNameAktivUndUeberkontoSindNichtRelevant(): void {
		$before = $this->snapshot();
		$after = $this->snapshot();
		// Felder, die gar nicht Teil des Snapshots sind, dürfen nichts auslösen.
		$after['number'] = '5999';
		$after['name'] = 'Neuer Name';
		$after['active'] = false;
		$after['parentId'] = 7;
		$this->assertSame([], AccountService::changedEvaluationFields($before, $after));
	}
}

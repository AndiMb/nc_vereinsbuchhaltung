<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetail;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\InvoiceDunning;
use OCA\Vereinsbuchhaltung\Db\InvoicePayment;
use OCA\Vereinsbuchhaltung\Db\InvoicePlan;
use OCA\Vereinsbuchhaltung\Db\LineAllocation;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pflichtspalten ohne Datenbank-Vorgabe müssen in jedem INSERT stehen.
 *
 * Der Setter von {@see Entity} merkt ein Feld nur dann zum Schreiben vor, wenn
 * sich der Wert ändert. Hat die Eigenschaft eine PHP-Vorgabe und setzt der Code
 * genau diesen Wert, fehlte die Spalte im INSERT – und die Datenbank lehnte die
 * Zeile ab. So brach eine beitragsfreie Zuweisung (Betrag 0) den gesamten
 * täglichen Forderungslauf ab.
 */
class EntityDefaultValueInsertTest extends TestCase {

	/** @return array<string, array{0: class-string<Entity>, 1: string, 2: mixed}> */
	public static function pflichtfelderMitVorgabe(): array {
		return [
			'Forderung ohne Betrag' => [OpenItem::class, 'amountCents', 0],
			'Umsatz ohne Betrag' => [BankTransaction::class, 'amountCents', 0],
			'SEPA-Einzelposten ohne Betrag' => [BankTxSepaDetail::class, 'amountCents', 0],
			'Lastschriftposten ohne Betrag' => [DebitItem::class, 'amountCents', 0],
			'Mahnung ohne Restbetrag' => [InvoiceDunning::class, 'remainingCents', 0],
			'Zahlung ohne Betrag' => [InvoicePayment::class, 'amountCents', 0],
			'Wiederkehrende Rechnung ohne Positionen' => [InvoicePlan::class, 'lines', '[]'],
			'Kostenstellen-Anteil ohne Betrag' => [LineAllocation::class, 'amountCents', 0],
		];
	}

	/** @param class-string<Entity> $class */
	#[DataProvider('pflichtfelderMitVorgabe')]
	public function testVorgabewertStehtImInsert(string $class, string $field, mixed $default): void {
		$entity = new $class();
		$entity->{'set' . ucfirst($field)}($default);

		$this->assertArrayHasKey($field, $entity->getUpdatedFields());
		$this->assertSame($default, $entity->{'get' . ucfirst($field)}());
	}

	/** Aus der Datenbank gelesen gilt nichts als geändert – ein UPDATE schreibt nur, was der Code setzt. */
	#[DataProvider('pflichtfelderMitVorgabe')]
	public function testGeleseneZeileGiltNichtAlsGeaendert(string $class): void {
		$this->assertSame([], $class::fromRow(['id' => 7])->getUpdatedFields());
	}
}

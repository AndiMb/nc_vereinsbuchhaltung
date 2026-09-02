<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Kennzeichen "zählt in den Geldbestand der Kopfzeile" je Geldkonto.
 *
 * Die Kopfzeile zeigte bis 0.30.0 nur das erste Geldkonto nach Kontonummer –
 * bei Kasse (1000) und Bankkonto (1200) also ausgerechnet die kleinere Zahl
 * (Issue #31). Sie summiert jetzt alle Geldkonten; damit ein Konto, das in
 * dieser Alltagszahl nichts zu suchen hat (etwa ein Festgeldkonto oder ein
 * durchlaufendes Zahlungsdienstleister-Konto), sich herausnehmen lässt,
 * bekommt jedes Konto dieses Kennzeichen.
 *
 * Vorgabe true: der bestehende Bestand soll nach dem Update sofort die
 * vollständige Summe zeigen, ohne dass jemand erst Konten anhaken muss.
 * Das Kennzeichen ist reine Anzeige – Kassenbericht, Vermögensübersicht und
 * Saldenliste rechnen unverändert mit allen Geldkonten (siehe
 * LedgerAggregator::wealth()).
 */
class Version000132Date20260902000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_accounts')) {
			return null;
		}

		$table = $schema->getTable('vbh_accounts');
		if ($table->hasColumn('count_in_total')) {
			return null;
		}

		$table->addColumn('count_in_total', 'boolean', [
			'notnull' => true,
			'default' => true,
		]);

		return $schema;
	}
}

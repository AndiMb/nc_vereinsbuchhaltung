<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Beitragsgruppen & Zuweisungen (Issue #68, Spec §2.2/§3.3): legt
 * `vbh_contribution_groups`, `vbh_assignments` und `vbh_assignment_events` neu
 * an und erweitert die bestehende, geteilte `vbh_open_items` additiv um die
 * Forderungs-Felder aus dem Claim-Modell (Spec §2.2 „Forderung (Claim)", §4
 * Migrationsübersicht).
 *
 * Bewusst additiv an `vbh_open_items`: das ist die Kern-Buchhaltungstabelle
 * mit echten Nutzern (Spec §1.2 Umbau-Härte), es werden nur neue, nullable
 * Spalten ergänzt - keine bestehende Spalte wird umbenannt, verändert oder
 * entfernt. `RowNormalizer::computeHash()` arbeitet ausschließlich auf
 * `vbh_bank_tx` und ist von dieser Migration nicht betroffen.
 *
 * `vbh_membership_fees` (das heutige, flache Beitragsmodell) wird durch
 * `ContributionGroup`/`Assignment` fachlich abgelöst, aber bewusst NICHT
 * angefasst oder entfernt: das ist laut Spec ein harter Umbau ohne
 * Migrationspflicht, ein Rückbau der alten Tabelle/UI ist nicht Teil dieses
 * Tickets (siehe PR-Beschreibung #68).
 *
 * Versionsnummer 000141: Nach dem Rebase auf den inzwischen fertigen
 * #65-Stand (PR #80, belegt bereits 000138/000139) und Rücksprache mit dem
 * parallel gestapelten #66 (Mandats-Lifecycle, PR #82, belegt 000138-000140
 * auf seinem eigenen, ebenfalls rebasten Branch) ist 000141 die nächste
 * freie Nummer über beide Stapel-Branches hinweg.
 */
class Version000141Date20260916130000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_contribution_groups')) {
			$table = $schema->createTable('vbh_contribution_groups');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('min_monthly_amount_cents', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('default_monthly_amount_cents', Types::INTEGER, ['notnull' => true]);
			// Teilmenge von {1,2,3,4,6,12} als sortierte Komma-Liste, z.B. "1,3,12"
			// (siehe ContributionGroup::encodeIntervals()) - kein eigenes n:m-Feld
			// noetig, weil die Menge winzig und nie einzeln abgefragt wird.
			$table->addColumn('allowed_intervals', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('default_interval', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('is_active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['is_active'], 'vbh_cgroup_active');
		}

		if (!$schema->hasTable('vbh_assignments')) {
			$table = $schema->createTable('vbh_assignments');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('member_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('group_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('interval_months', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('monthly_amount_cents', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('min_monthly_amount_override_cents', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('override_reason', Types::TEXT, ['notnull' => false]);
			// Werte: direct_debit|ueberweisung - siehe Assignment::PAYMENT_METHODS
			// (Enum-Sprachentscheidung: Spec §13.1 nennt dieses Paar als Beispiel
			// fuer "englisch wo Technik, deutsch wo Fachbegriff" explizit).
			$table->addColumn('payment_method', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'direct_debit']);
			$table->addColumn('valid_from', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('valid_to', Types::STRING, ['notnull' => false, 'length' => 10]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['member_id'], 'vbh_assign_member');
			// Zusammengesetzt statt zwei Einzelindizes: die Ueberlappungspruefung
			// (AssignmentService::assertNoOverlap()) filtert immer nach beiden.
			$table->addIndex(['group_id', 'member_id'], 'vbh_assign_group_member');
		}

		if (!$schema->hasTable('vbh_assignment_events')) {
			$table = $schema->createTable('vbh_assignment_events');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('assignment_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Werte: amount_changed|interval_changed|min_amount_override_set|
			// group_changed|assignment_started|assignment_ended - technische
			// Ereignis-Schluessel, bewusst englisch (siehe AssignmentEvent::TYPES).
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('actor_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('on_behalf_note', Types::TEXT, ['notnull' => false]);
			// Freiform-JSON mit alten/neuen Werten fuer die Audit-Anzeige (z.B.
			// {"from":800,"to":1000}) - kein eigenes Spaltenpaar je Ereignistyp,
			// weil jeder Typ andere Werte traegt.
			$table->addColumn('details', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['assignment_id'], 'vbh_assignevt_assign');
		}

		if ($schema->hasTable('vbh_open_items')) {
			$table = $schema->getTable('vbh_open_items');

			if (!$table->hasColumn('member_id')) {
				$table->addColumn('member_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
				$table->addIndex(['member_id'], 'vbh_openitem_member');
			}
			// Werte: beitrag|gebuehr, NULL fuer alle bestehenden/fremden offenen
			// Posten ohne Beitragsbezug (siehe OpenItem::TYPES) - die bestehende
			// `status`-Spalte (open|paid|cancelled) bleibt unveraendert und
			// bekommt nur den vierten, ebenfalls englischen Wert `waived` dazu
			// (Bestandskonvention: die drei vorhandenen Werte sind aelter als die
			// T26-Eindeutschungsregel und werden nicht rueckwirkend geaendert).
			if (!$table->hasColumn('type')) {
				$table->addColumn('type', Types::STRING, ['notnull' => false, 'length' => 16]);
			}
			if (!$table->hasColumn('assignment_id')) {
				$table->addColumn('assignment_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
				$table->addIndex(['assignment_id'], 'vbh_openitem_assign');
			}
			if (!$table->hasColumn('period_start')) {
				$table->addColumn('period_start', Types::STRING, ['notnull' => false, 'length' => 10]);
			}
			if (!$table->hasColumn('period_end')) {
				$table->addColumn('period_end', Types::STRING, ['notnull' => false, 'length' => 10]);
			}
			// Sperrgrenze fuer Aenderungen (Spec §3.3/§3.4) - wird erst in Ticket
			// #70 gesetzt; hier nur die Spalte, damit #70 additiv bleibt. Solange
			// nichts sie setzt, ist EffectivityRuleService::isLocked() immer false.
			if (!$table->hasColumn('prenotified_at')) {
				$table->addColumn('prenotified_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			}
			// Stundung: genau eine aktive je Forderung (ClaimService::defer()
			// gewaehrleistet das durch Ueberschreiben, keine eigene Tabelle noetig).
			if (!$table->hasColumn('deferred_until')) {
				$table->addColumn('deferred_until', Types::STRING, ['notnull' => false, 'length' => 10]);
			}
			if (!$table->hasColumn('deferred_reason')) {
				$table->addColumn('deferred_reason', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('deferred_by')) {
				$table->addColumn('deferred_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			}
			if (!$table->hasColumn('deferred_at')) {
				$table->addColumn('deferred_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			}
			// Erledigungsvermerk (paid/waived - der Status selbst traegt schon,
			// welche der beiden Arten es war, siehe OpenItem::STATUSES).
			if (!$table->hasColumn('settled_at')) {
				$table->addColumn('settled_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			}
			if (!$table->hasColumn('settled_by')) {
				$table->addColumn('settled_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			}
			if (!$table->hasColumn('settlement_note')) {
				$table->addColumn('settlement_note', Types::TEXT, ['notnull' => false]);
			}
			// Storno (Pflicht-Begruendung, nur vor Einreichung - die Einreichung
			// selbst existiert erst ab Ticket #70, siehe ClaimService::cancel()).
			if (!$table->hasColumn('cancelled_at')) {
				$table->addColumn('cancelled_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			}
			if (!$table->hasColumn('cancelled_reason')) {
				$table->addColumn('cancelled_reason', Types::TEXT, ['notnull' => false]);
			}
		}

		return $schema;
	}
}

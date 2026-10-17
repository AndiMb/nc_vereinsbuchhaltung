<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

use OCP\IL10N;

/**
 * Die mitgelieferten Vorlagen des Gliederungsberichts.
 *
 * Sie stehen im Code, nicht in der Datenbank: so sind ihre Texte in jeder
 * Sprache übersetzt und eine verbesserte Fassung erreicht alle Vereine mit
 * dem nächsten Update. Ändern lässt sich nur eine Kopie.
 *
 * Beide setzen voraus, dass die Konten eine Sphäre haben; Projekte sind die
 * Auswertungsgruppen des Zweckbetriebs. Ohne Sphären landet alles unter
 * „Ohne Sphäre“, und der Bericht sagt das.
 */
class BuiltinReportTemplates {

	public const PREFIX = 'builtin:';
	public const GRANTOR = 'builtin:zuwendungsgeber';
	public const AREAS = 'builtin:bereiche';

	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * @return list<array{id:string, name:string, builtin:true, definition:array<string, mixed>}>
	 */
	public function all(): array {
		return [
			[
				'id' => self::GRANTOR,
				'name' => $this->l10n->t('Haushaltsplan nach Titelgruppen (Zuwendungsgeber)'),
				'builtin' => true,
				'definition' => ReportDefinition::normalize($this->grantor()),
			],
			[
				'id' => self::AREAS,
				'name' => $this->l10n->t('Ergebnis je Bereich und Projekt'),
				'builtin' => true,
				'definition' => ReportDefinition::normalize($this->areas()),
			],
		];
	}

	/** @return array{id:string, name:string, builtin:true, definition:array<string, mixed>}|null */
	public function find(string $id): ?array {
		foreach ($this->all() as $template) {
			if ($template['id'] === $id) {
				return $template;
			}
		}
		return null;
	}

	/**
	 * Einnahmen, dann Ausgaben; darin zuerst der ideelle Bereich nach
	 * Kontenhierarchie, dann die Projekte je Titelgruppe mit Summe. Am Ende
	 * eine Zusammenfassung nach Hauptgruppen (oberste Kontoebene).
	 *
	 * @return array<string, mixed>
	 */
	private function grantor(): array {
		$side = fn (string $type, string $title, string $footer): array => [
			'title' => $title,
			'filter' => ['types' => [$type]],
			'sections' => [
				$this->tree($this->l10n->t('Ideeller Bereich'), 'ideell', true, false),
				[
					'title' => $this->l10n->t('Titelgruppen / Projekte'),
					'filter' => ['spheres' => ['zweckbetrieb']],
					'hideEmpty' => true,
					'levels' => [
						['by' => 'group', 'footer' => ['label' => $this->l10n->t('Summe der Titelgruppe')]],
						['by' => 'account', 'depth' => 'plan'],
					],
				],
				$this->tree($this->l10n->t('Vermögensverwaltung'), 'vermoegensverwaltung', true, true),
				$this->tree($this->l10n->t('Wirtschaftlicher Geschäftsbetrieb'), 'wirtschaftlich', true, true),
				$this->tree($this->l10n->t('Ohne Sphäre'), ReportDefinition::SPHERE_NONE, true, true),
			],
			'footer' => ['label' => $footer],
		];

		return [
			'version' => ReportDefinition::VERSION,
			'columns' => [
				['source' => 'plan', 'periodOffset' => -1],
				['source' => 'plan', 'periodOffset' => 0],
			],
			'showPlanNotes' => true,
			'sections' => [
				$side('income', $this->l10n->t('Einnahmen'), $this->l10n->t('Gesamteinnahmen')),
				$side('expense', $this->l10n->t('Ausgaben'), $this->l10n->t('Gesamtausgaben')),
				[
					'title' => $this->l10n->t('Zusammenfassung nach Hauptgruppen'),
					'summary' => true,
					'levels' => [
						['by' => 'type', 'footer' => ['label' => $this->l10n->t('Summe')]],
						['by' => 'account', 'depth' => '0', 'ancestors' => true],
					],
				],
			],
			'grandTotal' => ['label' => $this->l10n->t('Differenz')],
		];
	}

	/**
	 * Ideeller Bereich mit Einnahmen, Ausgaben und Ergebnis; danach je
	 * Projekt Einnahmen, Ausgaben und Differenz – so ist zu sehen, was der
	 * Verein für seine Projekte aus eigenen Mitteln aufbringt.
	 *
	 * @return array<string, mixed>
	 */
	private function areas(): array {
		$byType = fn (string $title, string $sphere, string $result, bool $hide): array => [
			'title' => $title,
			'filter' => ['spheres' => [$sphere]],
			'hideEmpty' => $hide,
			'levels' => [
				['by' => 'type', 'footer' => ['label' => $this->l10n->t('Summe')], 'hideEmpty' => false],
				['by' => 'account', 'depth' => '0'],
			],
			'footer' => ['label' => $result],
		];

		return [
			'version' => ReportDefinition::VERSION,
			'columns' => [
				['source' => 'plan', 'periodOffset' => -1],
				['source' => 'actual', 'periodOffset' => -1],
				['source' => 'plan', 'periodOffset' => 0],
				['source' => 'actual', 'periodOffset' => 0],
			],
			'showPlanNotes' => false,
			'sections' => [
				$byType($this->l10n->t('Ideeller Bereich'), 'ideell', $this->l10n->t('Ergebnis ideeller Bereich'), false),
				[
					'title' => $this->l10n->t('Zweckbetrieb'),
					'filter' => ['spheres' => ['zweckbetrieb']],
					'levels' => [
						['by' => 'group', 'footer' => ['label' => $this->l10n->t('Differenz')]],
						['by' => 'type', 'hideEmpty' => false],
					],
					'footer' => ['label' => $this->l10n->t('Ergebnis Zweckbetrieb')],
				],
				$byType($this->l10n->t('Vermögensverwaltung'), 'vermoegensverwaltung', $this->l10n->t('Ergebnis Vermögensverwaltung'), true),
				$byType($this->l10n->t('Wirtschaftlicher Geschäftsbetrieb'), 'wirtschaftlich', $this->l10n->t('Ergebnis wirtschaftlicher Geschäftsbetrieb'), true),
				$byType($this->l10n->t('Ohne Sphäre'), ReportDefinition::SPHERE_NONE, $this->l10n->t('Ergebnis ohne Sphäre'), true),
			],
			'grandTotal' => ['label' => $this->l10n->t('Saldo')],
		];
	}

	/**
	 * Ein Bereich als Kontenbaum bis zur Planungsebene.
	 *
	 * @return array<string, mixed>
	 */
	private function tree(string $title, string $sphere, bool $ancestors, bool $hideEmpty): array {
		return [
			'title' => $title,
			'filter' => ['spheres' => [$sphere]],
			'hideEmpty' => $hideEmpty,
			'levels' => [
				['by' => 'account', 'depth' => 'plan', 'ancestors' => $ancestors],
			],
		];
	}
}

<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion;
use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersionMapper;
use OCP\IL10N;

/**
 * Versionsverwaltung des Mandats-Rechtstexts (Spec §2.2 „MandateLegalTextVersion"/
 * §3.11 „Mandats-Rechtstext", §8 Compliance-Anhang, Issue #67).
 *
 * `MandateLegalTextVersion::body` ist laut Spec EIN Textkörper - "Pflichtblock
 * + Rahmen, vollständiger Text". Damit sich trotzdem nur der admin-editierbare
 * Rahmen in einem Bearbeitungsformular vorbefüllen lässt, ohne eine zweite
 * Spalte einzuführen, trennt diese Klasse die beiden Teile über einen festen
 * Marker innerhalb des einen `body`-Feldes (siehe {@see self::RAHMEN_MARKER}).
 * Nach außen (DB-Schema, Entity) bleibt es das eine Feld, das die Spec
 * vorsieht.
 *
 * Der Pflichtblock selbst ist Code, kein DB-Inhalt (er ist nach DK-Mustertext
 * vorgeschrieben, Spec §8 - eine Vereinsverwaltung darf ihn nicht ändern).
 * Ändert ein App-Update {@see self::defaultPflichtblock()}, erkennt
 * {@see self::current()} das automatisch (der gespeicherte Pflichtblock
 * passt nicht mehr zum Code) und legt eine neue, `system`-getriebene Version
 * an, die den bisherigen (admin-editierten) Rahmen unverändert übernimmt -
 * "system-getrieben (App-Update ändert Pflichtblock)" aus Spec §2.2.
 */
class MandateLegalTextService {

	/**
	 * Trennt Pflichtblock und Rahmen innerhalb von `body`. Ein HTML-Kommentar,
	 * der weder im Fließtext noch im gerenderten PDF/Zustimmungsformular
	 * sichtbar auftaucht, wenn `body` als vorformatierter Text ausgegeben wird
	 * (siehe {@see MandateFormRenderer}) - dort wird der Marker vor dem
	 * Escapen durch einen Absatzwechsel ersetzt, Pflichtblock und Rahmen
	 * erscheinen als getrennte Absätze, der Marker selbst nie.
	 */
	public const RAHMEN_MARKER = "\n<!-- vbh:rahmen -->\n";

	public function __construct(
		private MandateLegalTextVersionMapper $mapper,
		private IL10N $l10n,
	) {
	}

	/**
	 * Der DK-Pflichtblock (Spec §8 „Mandats-Pflichttext"): Überschrift,
	 * Ermächtigung, Weisung ans eigene Kreditinstitut, Acht-Wochen-Hinweis.
	 * Eigene, freie Formulierung dieser App (kein Zitat eines fremden
	 * Formulars) - inhaltlich deckt sie die vier von der Spec verlangten
	 * Bestandteile ab. `{{creditor_name}}` wird erst bei der Anzeige ersetzt
	 * (Spec §2.2), damit ein Vereinsnamens-Wechsel nicht rückwirkend jede
	 * Version umschreibt.
	 *
	 * Bewusst OHNE {@see IL10N::t()}: der Mandats-Rechtstext ist laut Issue
	 * #67 ausdrücklich NICHT Teil des l10n-Wegs dieser App (Quelldeutsch +
	 * `en.json`-Zielbundle, Spec §1.4) - er ist rechtsverbindlicher, in der
	 * DB versionierter Inhalt, kein UI-Text, und bleibt deshalb immer Deutsch.
	 */
	public function defaultPflichtblock(): string {
		return "SEPA-Lastschriftmandat\n\n"
			. 'Ich ermächtige {{creditor_name}}, Zahlungen von meinem Konto mittels SEPA-Lastschrift einzuziehen. '
			. "Zugleich weise ich mein Kreditinstitut an, die von {{creditor_name}} auf mein Konto gezogenen Lastschriften einzulösen.\n\n"
			. 'Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen. '
			. 'Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.';
	}

	private function composeBody(string $rahmen): string {
		return $this->defaultPflichtblock() . self::RAHMEN_MARKER . $rahmen;
	}

	/** Rahmen-Teil eines gespeicherten Textkörpers - für das Admin-Bearbeitungsformular. */
	public function extractRahmen(string $body): string {
		$pos = strpos($body, self::RAHMEN_MARKER);
		if ($pos === false) {
			// Datensatz aus einer Zeit vor diesem Marker (sollte es nach diesem
			// Ticket nicht mehr geben) - lieber ein leerer Rahmen als ein
			// Formular, das den ganzen Pflichtblock als "Rahmen" anzeigt.
			return '';
		}
		return substr($body, $pos + strlen(self::RAHMEN_MARKER));
	}

	/** Ob der gespeicherte Pflichtblock (noch) mit dem aktuellen Code übereinstimmt. */
	private function pflichtblockMatches(string $body): bool {
		return str_starts_with($body, $this->defaultPflichtblock() . self::RAHMEN_MARKER);
	}

	/**
	 * Die "aktuelle" Version im Sinne von Spec §2.2 - wird für JEDE neue
	 * Fixierung verwendet (neuer Einmal-Link, neues Formular-PDF ohne bereits
	 * fixierte Version). Legt beim allerersten Aufruf eine System-Version mit
	 * leerem Rahmen an; erkennt einen zwischenzeitlich per App-Update
	 * geänderten Pflichtblock und schreibt dann automatisch fort (siehe
	 * Klassendoc).
	 */
	public function current(): MandateLegalTextVersion {
		$latest = $this->mapper->findLatest();
		if ($latest === null) {
			return $this->createVersion('', MandateLegalTextVersion::CREATED_BY_SYSTEM);
		}
		if (!$this->pflichtblockMatches($latest->getBody())) {
			return $this->createVersion($this->extractRahmen($latest->getBody()), MandateLegalTextVersion::CREATED_BY_SYSTEM);
		}
		return $latest;
	}

	/** Der admin-editierbare Rahmen der aktuellen Version - Vorbefüllung des Bearbeitungsformulars. */
	public function currentRahmen(): string {
		return $this->extractRahmen($this->current()->getBody());
	}

	public function find(int $id): ?MandateLegalTextVersion {
		return $this->mapper->findOrNull($id);
	}

	/** @return MandateLegalTextVersion[] neueste zuerst */
	public function history(): array {
		return $this->mapper->findAll();
	}

	/**
	 * Neue, `verwalter`-getriebene Version mit geändertem Rahmen (Spec §2.2).
	 * Erzeugt IMMER eine neue Zeile statt eines Updates - bereits fixierte
	 * Mandate zeigen weiterhin ihre eigene, unveränderte Version ("keine
	 * Rückwirkung").
	 *
	 * @throws \InvalidArgumentException bei unbekanntem $createdBy
	 */
	public function createVersion(string $rahmen, string $createdBy = MandateLegalTextVersion::CREATED_BY_VERWALTER): MandateLegalTextVersion {
		if (!in_array($createdBy, MandateLegalTextVersion::CREATED_BY_VALUES, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültiger Ersteller: %s', [$createdBy]));
		}
		$version = new MandateLegalTextVersion();
		$version->setCreatedBy($createdBy);
		$version->setBody($this->composeBody($rahmen));
		$version->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		return $this->mapper->insert($version);
	}
}

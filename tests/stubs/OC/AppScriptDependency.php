<?php

declare(strict_types=1);

namespace OC;

/**
 * Lückenfüller für die Unit-Tests, kein Bestandteil der App.
 *
 * `OCP\Util::addScript()` legt für jede App, die ein Skript anmeldet, eine
 * solche Abhängigkeit an; das Stub-Paket nextcloud/ocp liefert die
 * serverinterne Klasse nicht mit. Signaturgleich zum Server
 * (lib/private/AppScriptDependency.php), soweit addScript() sie braucht.
 * Gebraucht vom PageControllerTest.
 */
class AppScriptDependency {
	/** @var list<string> */
	private array $deps;

	/** @param list<string> $deps */
	public function __construct(
		private string $id,
		array $deps = [],
	) {
		$this->deps = $deps;
	}

	public function getId(): string {
		return $this->id;
	}

	/** @return list<string> */
	public function getDeps(): array {
		return $this->deps;
	}

	public function addDep(string $dep): void {
		if (!in_array($dep, $this->deps, true)) {
			$this->deps[] = $dep;
		}
	}
}

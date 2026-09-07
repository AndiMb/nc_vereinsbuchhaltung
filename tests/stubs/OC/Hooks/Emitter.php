<?php

declare(strict_types=1);

namespace OC\Hooks;

/**
 * Lückenfüller für die Unit-Tests, kein Bestandteil der App.
 *
 * OCP\Files\IRootFolder erbt von dieser serverinternen Schnittstelle. Das
 * Stub-Paket nextcloud/ocp liefert aber nur den öffentlichen OCP-Namensraum
 * aus, weshalb sich IRootFolder ohne laufende Nextcloud-Instanz nicht laden
 * und damit auch nicht mocken lässt. Diese Datei stellt genau die eine fehlende
 * Schnittstelle bereit – signaturgleich zum Server (lib/private/Hooks/Emitter.php),
 * damit ein Mock dieselben Methoden anbietet wie im Betrieb.
 *
 * Wird ausschließlich vom Autoloader in tests/bootstrap.php eingebunden und
 * landet nie in einem Release: der Release-Tarball enthält tests/ nicht.
 *
 * @deprecated im Server seit 18.0.0 – bleibt hier, solange IRootFolder davon erbt.
 */
interface Emitter {
	/**
	 * @param string $scope
	 * @param string $method
	 * @return void
	 */
	public function listen($scope, $method, callable $callback);

	/**
	 * @param string $scope
	 * @param string $method
	 * @return void
	 */
	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}

<?php

declare(strict_types=1);

/**
 * Lückenfüller für die Unit-Tests, kein Bestandteil der App.
 *
 * `OCP\Util::addScript()` und `OCP\Util::addTranslations()` holen sich die
 * Sprache über `\OC::$server`, den Dienstcontainer des Servers. Das Stub-Paket
 * nextcloud/ocp liefert die Klasse `\OC` nicht mit; ohne sie bräche jeder Test
 * ab, der eine Seite samt Skript registriert (PageControllerTest). Ein Test
 * setzt `\OC::$server` auf einen gemockten Container.
 *
 * Wird nur von Tests eingebunden, die sie brauchen, und landet nie in einem
 * Release: der Release-Tarball enthält tests/ nicht.
 */
class OC {
	/** @var \Psr\Container\ContainerInterface */
	public static $server;
}

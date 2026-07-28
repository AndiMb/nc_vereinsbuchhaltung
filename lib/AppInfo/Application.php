<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\AppInfo;

use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Middleware\PermissionMiddleware;
use OCA\Vereinsbuchhaltung\Middleware\RevisionMiddleware;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IDBConnection;

class Application extends App implements IBootstrap {
	public const APP_ID = 'vereinsbuchhaltung';

	/** Gemeinsamer Datenschlüssel: alle berechtigten Nutzer teilen sich einen Buchhaltungsbestand. */
	public const BOOK = '__verein__';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerMiddleware(PermissionMiddleware::class);
		$context->registerMiddleware(RevisionMiddleware::class);

		// Ausdrücklich als geteilter Dienst: der TransactionRunner zählt die
		// Verschachtelungstiefe und sammelt Nach-Commit-Aufgaben in
		// Instanzfeldern. Bekäme jeder Service seine eigene Instanz, öffnete
		// jede Ebene eine eigene Transaktion – genau das soll er verhindern.
		$context->registerService(TransactionRunner::class, static function ($c): TransactionRunner {
			return new TransactionRunner($c->get(IDBConnection::class));
		}, true);
	}

	public function boot(IBootContext $context): void {
	}
}

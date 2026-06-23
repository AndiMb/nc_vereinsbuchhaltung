<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\AppInfo;

use OCA\Vereinsbuchhaltung\Middleware\PermissionMiddleware;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'vereinsbuchhaltung';

	/** Gemeinsamer Datenschlüssel: alle berechtigten Nutzer teilen sich einen Buchhaltungsbestand. */
	public const BOOK = '__verein__';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerMiddleware(PermissionMiddleware::class);
	}

	public function boot(IBootContext $context): void {
	}
}

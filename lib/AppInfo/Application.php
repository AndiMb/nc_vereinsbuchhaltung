<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\AppInfo;

use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Listener\MemberAccountDeletionListener;
use OCA\Vereinsbuchhaltung\Listener\UserDeletedListener;
use OCA\Vereinsbuchhaltung\Middleware\PermissionMiddleware;
use OCA\Vereinsbuchhaltung\Middleware\RevisionMiddleware;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IDBConnection;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'vereinsbuchhaltung';

	/** Gemeinsamer Datenschlüssel: alle berechtigten Nutzer teilen sich einen Buchhaltungsbestand. */
	public const BOOK = '__verein__';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		self::loadComposerAutoloader();

		$context->registerMiddleware(PermissionMiddleware::class);
		$context->registerMiddleware(RevisionMiddleware::class);

		// Belegablage und Wachordner zeigen auf einen Nextcloud-Nutzer. Wird der
		// gelöscht, räumt der Listener die Einstellungen mit ab, damit keine
		// Namen stehen bleiben, hinter denen niemand mehr steht.
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);

		// Mitglied-Verknüpfung: rettet vor der Löschung die Mailadresse ins
		// Mitglied (Spec §2.2/§3.1) und löst die Verknüpfung, sobald das
		// Konto weg ist.
		$context->registerEventListener(BeforeUserDeletedEvent::class, MemberAccountDeletionListener::class);

		// Ausdrücklich als geteilter Dienst: der TransactionRunner zählt die
		// Verschachtelungstiefe und sammelt Nach-Commit-Aufgaben in
		// Instanzfeldern. Bekäme jeder Service seine eigene Instanz, öffnete
		// jede Ebene eine eigene Transaktion – genau das soll er verhindern.
		$context->registerService(TransactionRunner::class, static function ($c): TransactionRunner {
			return new TransactionRunner($c->get(IDBConnection::class));
		}, true);

		// Ebenfalls ausdrücklich geteilt: der PeriodService hält die
		// Geschäftsjahre des laufenden Requests im Speicher und legt fehlende
		// bei Bedarf an. Bekäme jeder Service seine eigene Instanz, sähe der
		// eine die Periode nicht, die der andere gerade angelegt hat – und
		// jede Instanz läse die Liste erneut aus der Datenbank.
		$context->registerService(PeriodService::class, static function ($c): PeriodService {
			return new PeriodService(
				$c->get(\OCA\Vereinsbuchhaltung\Db\PeriodMapper::class),
				$c->get(\OCA\Vereinsbuchhaltung\Db\JournalMapper::class),
				$c->get(\OCA\Vereinsbuchhaltung\Db\BudgetMapper::class),
				$c->get(\OCA\Vereinsbuchhaltung\Db\BudgetSnapshotMapper::class),
				$c->get(\OCA\Vereinsbuchhaltung\Service\EntryNumberService::class),
				$c->get(TransactionRunner::class),
				$c->get(\OCA\Vereinsbuchhaltung\Service\AuditService::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\OCP\IL10N::class),
			);
		}, true);

		// Ebenfalls ausdrücklich geteilt: die PermissionMiddleware befüllt den
		// ActorContextService einmal pro Request (Kanal Self-Service vs.
		// Admin-Akte, Spec §3.9 „Personalunion"). Injizierte Controller/
		// Services in #66/#68 müssen denselben Stand sehen wie die Middleware
		// ihn gesetzt hat, nicht eine frische, unbefüllte Instanz.
		$context->registerService(ActorContextService::class, static function ($c): ActorContextService {
			return new ActorContextService($c->get(\OCP\IUserSession::class));
		}, true);

		// Der Wachordner-Job wird NICHT hier registriert, sondern über
		// <background-jobs> in appinfo/info.xml. Einen registerBackgroundJob()
		// gibt es am IRegistrationContext nicht; der Aufruf lief in einen
		// "Call to undefined method", den Nextcloud abfängt – mit der Folge,
		// dass die restliche Registrierung der App abbrach.
	}

	public function boot(IBootContext $context): void {
	}

	/**
	 * Lädt den Composer-Autoloader der App-Abhängigkeiten (`chillerlan/php-qrcode`
	 * für den GiroCode-Anhang der Mahnmails, Issue #73).
	 *
	 * Nextcloud lädt je App nur `composer/autoload.php` oder, wenn es das nicht
	 * gibt, registriert es `lib/` als PSR-4-Wurzel (`OC_App::registerAutoloading()`,
	 * NC 31–34) – ein `vendor/autoload.php` fasst der Server NIE an. Ohne dieses
	 * `require_once` (so empfiehlt es auch das Entwicklerhandbuch, Abschnitt
	 * „Dependency management“) war die Bibliothek in der laufenden Instanz nicht
	 * ladbar: `generatePng()` warf „Class … not found“, der Mahnversand fing den
	 * Fehler lautlos ab, und kein Mahnmail trug je einen GiroCode (Issue #120).
	 *
	 * Bewusst nicht als `composer/autoload.php`: dann ließe Nextcloud die
	 * PSR-4-Registrierung von `lib/` aus, und ein Checkout ohne `vendor/` (lokale
	 * Entwicklung, `composer install` vergessen) fände nicht einmal mehr die
	 * eigenen Klassen. So bleibt eine fehlende `vendor/` folgenlos – es fehlen
	 * dann nur die GiroCodes, und das steht im Log.
	 */
	private static function loadComposerAutoloader(): void {
		$autoloader = __DIR__ . '/../../vendor/autoload.php';
		if (is_file($autoloader)) {
			require_once $autoloader;
		}
	}
}

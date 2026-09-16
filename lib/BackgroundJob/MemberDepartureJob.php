<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\BackgroundJob;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\AssignmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Austritts-Hook für Zuweisungen (Issue #68 AK 8, Spec §3.1): „Austritt …
 * setzt `valid_to` aller offenen Zuweisungen." Ticket #65 hat zum Zeitpunkt
 * dieses Tickets noch keinen MemberService, der `left_at` setzen und dabei
 * synchron einen Hook auslösen könnte (nur Entity+Mapper+Migration) – dieser
 * Tageslauf ist deshalb bewusst der primäre, eigenständige Mechanismus statt
 * ein Event-Listener auf ein noch nicht existierendes Ereignis.
 *
 * Idempotent über {@see AssignmentService::onMemberLeft()}: läuft jeden Tag
 * über alle ausgetretenen Mitglieder, findet aber nur noch etwas zu tun,
 * solange eine Zuweisung tatsächlich noch offen ist.
 */
class MemberDepartureJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private MemberMapper $memberMapper,
		private AssignmentService $assignments,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$today = $this->time->getDateTime()->format('Y-m-d');
		try {
			$closed = 0;
			foreach ($this->memberMapper->findLeftOnOrBefore($today) as $member) {
				$closed += $this->assignments->onMemberLeft($member->getId(), (string)$member->getLeftAt());
			}
		} catch (\Throwable $e) {
			$this->logger->error('Austritts-Lauf (Zuweisungen) abgebrochen', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return;
		}
		if ($closed > 0) {
			$this->logger->info('Austritts-Lauf: {count} Zuweisung(en) beendet', [
				'app' => Application::APP_ID,
				'count' => $closed,
			]);
		}
	}
}

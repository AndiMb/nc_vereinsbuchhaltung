<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\BackgroundJob;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\ClaimStateResolver;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Austritts-Mandatsende (Spec §2.2 „Widerruf/Austritt"/§7, Issue #70): läuft
 * NEBEN {@see MemberDepartureJob} (Issue #68, beendet nur die Zuweisungen),
 * nicht an dessen Stelle – erst wenn keine Forderung des ausgetretenen
 * Mitglieds mehr offen ist ({@see ClaimStateResolver}), endet auch das
 * Mandat selbst ({@see MandateService::endDueToDeparture()}).
 *
 * Eigener Job statt Erweiterung von {@see MemberDepartureJob}: andere
 * Zuständigkeit (Mandat statt Zuweisung), anderer Fachdienst
 * ({@see MandateService} statt `AssignmentService`) – dieselbe Trennung wie
 * zwischen {@see MandateExpiryJob} (36-Monats-Verfall) und
 * {@see MemberDepartureJob} bereits besteht.
 *
 * Idempotent: läuft jeden Tag über alle ausgetretenen Mitglieder, findet aber
 * nur noch etwas zu tun, solange ein aktives Mandat und keine offene
 * Forderung mehr vorliegen.
 */
class MandateDepartureJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private MemberMapper $memberMapper,
		private MandateMapper $mandateMapper,
		private OpenItemMapper $openItemMapper,
		private MandateService $mandateService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$today = $this->time->getDateTime()->format('Y-m-d');
		try {
			$ended = 0;
			foreach ($this->memberMapper->findLeftOnOrBefore($today) as $member) {
				$mandate = $this->mandateMapper->findLiveByMember($member->getId())[0] ?? null;
				if ($mandate === null || $mandate->getStatus() !== Mandate::STATUS_ACTIVE) {
					continue;
				}
				if ($this->hasOpenClaim($member->getId())) {
					continue;
				}
				$this->mandateService->endDueToDeparture($mandate->getId());
				$ended++;
			}
		} catch (\Throwable $e) {
			$this->logger->error('Austritts-Lauf (Mandatsende) abgebrochen', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return;
		}
		if ($ended > 0) {
			$this->logger->info('Austritts-Lauf: {count} Mandat(e) beendet', [
				'app' => Application::APP_ID,
				'count' => $ended,
			]);
		}
	}

	private function hasOpenClaim(int $memberId): bool {
		foreach ($this->openItemMapper->findByMember($memberId) as $item) {
			if ($item->isClaim() && ClaimStateResolver::resolveForItem($item) === ClaimStateResolver::STATE_OPEN) {
				return true;
			}
		}
		return false;
	}
}

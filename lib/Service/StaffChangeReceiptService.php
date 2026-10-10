<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Quittungsmail an das Mitglied, wenn der **Vorstand** seinen Beitrag ändert (Betrag, Turnus oder
 * Beitragsgruppe): dasselbe Skelett wie die Quittung der Selbständerung
 * ({@see SelfServiceReceiptMailService}, „Quittungsmail an das Mitglied, immer"), nur nennt der Text den
 * Vorstand als Urheber. Das Mitglied erfährt so von jeder Änderung seines Beitrags, auch wenn es sie
 * nicht selbst vorgenommen hat.
 *
 * Eine Mail, die nicht rausgeht (keine Adresse, Mailserver nicht erreichbar), darf die Änderung nicht
 * zurückrollen: sie ist dann schon gespeichert. Der Aufrufer bekommt deshalb nur den Ausgang gemeldet
 * (siehe die Konstanten) und zeigt ihn dem Vorstand an.
 */
class StaffChangeReceiptService {

	/** Die Mail ist raus. */
	public const SENT = 'sent';
	/** Das Mitglied hat weder eine eigene Adresse noch ein Nextcloud-Konto mit Adresse. */
	public const NO_EMAIL = 'no_email';
	/** Der Versand scheiterte (Mailserver); die Änderung ist trotzdem gespeichert. */
	public const FAILED = 'failed';
	/** Nichts hat sich geändert, also gibt es nichts zu bestätigen. */
	public const NOT_NEEDED = 'not_needed';

	public function __construct(
		private SelfServiceReceiptMailService $receiptMail,
		private MemberMapper $members,
		private ContributionGroupMapper $groups,
		private IUserManager $userManager,
		private IConfig $config,
		private IFactory $l10nFactory,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array{amountCents:int, intervalMonths:int, groupId:int} $before der Stand vor der Änderung
	 * @param array{effectiveFrom:string, firstDueDate:string} $preview Ergebnis von AssignmentService::previewChange() für dieselbe Änderung
	 * @return string eine der Konstanten SENT, NO_EMAIL, FAILED, NOT_NEEDED
	 */
	public function send(Assignment $after, array $before, array $preview): string {
		$member = $this->members->find($after->getMemberId());
		// Die Mail folgt der Sprache des Mitglieds (Du-/Sie-Fassung, Englisch), nicht der des Vorstands,
		// die die Änderung gerade vornimmt.
		$l = $this->l10nFor($member);
		$what = $this->describeChanges($after, $before, $l);
		if ($what === []) {
			return self::NOT_NEEDED;
		}

		$recipient = $this->resolveRecipient($member);
		if ($recipient === null) {
			return self::NO_EMAIL;
		}

		try {
			$this->receiptMail->sendReceipt(
				$member,
				$recipient,
				$l->t('Ihr Beitrag wurde geändert'),
				implode(' ', $what),
				$preview['effectiveFrom'],
				// Beitragsfrei: nichts wird eingezogen, also gibt es auch keinen betroffenen Einzug.
				$after->getMonthlyAmountCents() === 0 ? null : $preview['firstDueDate'],
				null,
				$l,
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Quittungsmail zur Beitragsänderung konnte nicht verschickt werden', ['exception' => $e, 'assignmentId' => $after->getId()]);
			return self::FAILED;
		}
		return self::SENT;
	}

	/**
	 * Ein Satz je tatsächlich geänderter Größe.
	 *
	 * @param array{amountCents:int, intervalMonths:int, groupId:int} $before
	 * @return list<string>
	 */
	private function describeChanges(Assignment $after, array $before, IL10N $l): array {
		$what = [];
		if ($after->getGroupId() !== $before['groupId']) {
			$what[] = $l->t('Der Vorstand hat Ihre Beitragsgruppe von „%1$s" auf „%2$s" gewechselt.', [
				$this->groups->find($before['groupId'])->getName(),
				$this->groups->find($after->getGroupId())->getName(),
			]);
		}
		if ($after->getMonthlyAmountCents() !== $before['amountCents']) {
			$what[] = $l->t('Der Vorstand hat Ihren Monatsbeitrag von %1$s € auf %2$s € geändert.', [
				$this->euro($before['amountCents']),
				$this->euro($after->getMonthlyAmountCents()),
			]);
		}
		if ($after->getIntervalMonths() !== $before['intervalMonths']) {
			$what[] = $l->t('Der Vorstand hat Ihren Zahlungsturnus von %1$s auf %2$s geändert.', [
				$this->intervalName($before['intervalMonths'], $l),
				$this->intervalName($after->getIntervalMonths(), $l),
			]);
		}
		return $what;
	}

	/** Empfänger: vorrangig die Mailadresse des Mitglieds, ersatzweise die seines verknüpften Nextcloud-Kontos. */
	private function resolveRecipient(Member $member): ?string {
		if ($member->getEmail() !== null && $member->getEmail() !== '') {
			return $member->getEmail();
		}
		$user = $member->getNcUserId() !== null ? $this->userManager->get($member->getNcUserId()) : null;
		$accountEmail = $user?->getEMailAddress();
		return ($user !== null && $accountEmail !== null && $accountEmail !== '') ? $accountEmail : null;
	}

	private function intervalName(int $months, IL10N $l): string {
		return match ($months) {
			1 => $l->t('monatlich'),
			3 => $l->t('vierteljährlich'),
			6 => $l->t('halbjährlich'),
			12 => $l->t('jährlich'),
			default => $l->t('alle %d Monate', [$months]),
		};
	}

	/** Sprache des Mitglieds: die seines Nextcloud-Kontos, sonst die Vorgabe der Instanz, sonst die der laufenden Anfrage. */
	private function l10nFor(Member $member): IL10N {
		$uid = $member->getNcUserId();
		$language = $uid !== null ? (string)$this->config->getUserValue($uid, 'core', 'lang', '') : '';
		if ($language === '') {
			$language = (string)$this->config->getSystemValue('default_language', '');
		}
		return $language !== '' ? $this->l10nFactory->get(Application::APP_ID, $language) : $this->l10n;
	}

	private function euro(int $cents): string {
		return number_format($cents / 100, 2, ',', '.');
	}
}

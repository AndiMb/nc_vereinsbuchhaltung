<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Listener;

use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Service\TaskService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use OCP\User\Events\BeforeUserDeletedEvent;

/**
 * Reagiert auf eine NC-Kontolöschung für ein verknüpftes Mitglied (Spec
 * §2.2/§3.1): „NC-Konto-Löschung leert nur nc_user_id; Mitglied + Historie
 * bleiben." Zusätzlich rettet sie die NC-Mailadresse nach `member.email`,
 * aber nur, solange dieses Feld noch leer ist – sonst wäre nach der
 * Kontolöschung auch die einzige Kontaktmöglichkeit weg. Eine Aufgabe
 * entsteht nur, wenn dabei tatsächlich eine Adresse gerettet wurde; das
 * bloße Lösen der Verknüpfung ist kein Störfall (analog zum bestehenden
 * {@see UserDeletedListener}, der andere Einstellungen ohne Aufgabe abräumt).
 *
 * Bewusst `BeforeUserDeletedEvent`, nicht das andere Zwecke abräumende
 * {@see UserDeletedListener} auf `UserDeletedEvent`: die Mailadresse muss
 * gelesen werden, solange das Konto noch existiert.
 *
 * @template-implements IEventListener<BeforeUserDeletedEvent>
 */
class MemberAccountDeletionListener implements IEventListener {

	public function __construct(
		private MemberMapper $members,
		private TaskService $tasks,
		private IL10N $l10n,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeUserDeletedEvent)) {
			return;
		}
		$user = $event->getUser();
		$member = $this->members->findByNcUserId($user->getUID());
		if ($member === null) {
			return;
		}

		$rescuedEmail = null;
		if (($member->getEmail() === null || $member->getEmail() === '')
			&& $user->getEMailAddress() !== null && $user->getEMailAddress() !== '') {
			$rescuedEmail = $user->getEMailAddress();
			$member->setEmail($rescuedEmail);
		}
		$member->setNcUserId(null);
		$this->members->update($member);

		if ($rescuedEmail !== null) {
			$this->tasks->create(
				Task::SEVERITY_HINT,
				$this->l10n->t('NC-Konto von %s wurde gelöscht — Adresse übernommen, bitte prüfen', [$member->displayName()]),
				'member',
				(int)$member->getId(),
			);
		}
	}
}

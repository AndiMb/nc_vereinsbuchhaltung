<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Db\ReimbursementEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

/**
 * Die JSON-Gestalt eines Auslagenantrags - eine für das Mitglied, eine für die
 * Verwaltung (docs/reimbursement-design.md §3 und §5).
 *
 * Das Mitglied sieht Status mit Fortschritt, Zeitstempel, Ablehnungsgrund und
 * Auszahlungstermin, aber nie die Namen der Freigeber und keine Dubletten-
 * hinweise. Die Verwaltung sieht die volle Historie mit Namen; die IBAN
 * erscheint dort voll nur für Buchhalter und Verwalter, für Revisoren maskiert.
 *
 * Für Listen vorher {@see preload()} aufrufen: die Freigeber aller Anträge
 * kommen dann in einer Abfrage statt einer je Zeile.
 */
class ReimbursementPresenter {

	/** @var array<string, string> Anzeigenamen, je Nutzerkennung einmal aufgelöst */
	private array $userNames = [];
	/** @var array<int, string> */
	private array $memberNames = [];
	/** @var list<string>|null der Kreis der Freigeber, einmal je Anfrage ermittelt */
	private ?array $approverPool = null;
	/** @var array<int, list<string>> Freigeber je Antrag, durch preload() gefüllt */
	private array $approversById = [];

	public function __construct(
		private ReimbursementService $service,
		private ReimbursementSettings $settings,
		private MemberMapper $members,
		private IUserManager $users,
	) {
	}

	/**
	 * Holt die Freigeber aller Anträge einer Liste auf einmal.
	 *
	 * @param list<Reimbursement> $reimbursements
	 */
	public function preload(array $reimbursements): void {
		$ids = array_map(static fn (Reimbursement $r): int => $r->getId(), $reimbursements);
		$found = $this->service->approverUidsFor($ids);
		foreach ($ids as $id) {
			$this->approversById[$id] = $found[$id] ?? [];
		}
	}

	/**
	 * @param bool $detail mit voller IBAN, Dateien und Historie (Einzelansicht)
	 * @return array<string,mixed>
	 */
	public function forMember(Reimbursement $reimbursement, bool $detail = false): array {
		$data = $this->base($reimbursement) + [
			'canWithdraw' => $reimbursement->getStatus() === Reimbursement::STATUS_SUBMITTED,
		];
		unset($data['submittedBy'], $data['openItemId'], $data['journalId']);

		if ($detail) {
			$data['iban'] = $reimbursement->getIban();
			$data['files'] = $this->files($reimbursement);
			$data['history'] = [];
			foreach ($this->service->history($reimbursement->getId()) as $event) {
				if ($event->getAction() !== ReimbursementEvent::ACTION_DUPLICATE_HINT) {
					$data['history'][] = $event->toArray(false);
				}
			}
		}
		return $data;
	}

	/**
	 * @param string $actorUid der angemeldete Nutzer, für „kann ich freigeben?“
	 * @param bool $fullIban Buchhalter und Verwalter sehen die ganze IBAN, Revisoren nur maskiert
	 * @param bool $detail mit Dateien und Historie (Einzelansicht)
	 * @return array<string,mixed>
	 */
	public function forStaff(Reimbursement $reimbursement, string $actorUid, bool $fullIban, bool $detail = false): array {
		$approvers = $this->approversOf($reimbursement);
		$openForDecision = $reimbursement->isOpenForDecision();
		$mayDecide = $openForDecision
			&& $actorUid !== $reimbursement->getSubmittedBy()
			&& in_array($actorUid, $this->approverPool ??= $this->settings->approverUids(), true);

		$data = $this->base($reimbursement) + [
			'memberName' => $this->memberName($reimbursement->getMemberId()),
			'ownRequest' => $actorUid === $reimbursement->getSubmittedBy(),
			'openForDecision' => $openForDecision,
			'alreadyApproved' => in_array($actorUid, $approvers, true),
			'canApprove' => $mayDecide && !in_array($actorUid, $approvers, true),
			'canReject' => $mayDecide,
			'canPay' => $reimbursement->getStatus() === Reimbursement::STATUS_APPROVED,
		];
		if ($fullIban) {
			$data['iban'] = $reimbursement->getIban();
		}

		if ($detail) {
			$data['files'] = $this->files($reimbursement);
			$data['history'] = [];
			$data['duplicateHint'] = false;
			foreach ($this->service->history($reimbursement->getId()) as $event) {
				$entry = $event->toArray(true);
				$entry['actorName'] = $this->userName($event->getActorUid());
				$data['history'][] = $entry;
				if ($event->getAction() === ReimbursementEvent::ACTION_DUPLICATE_HINT) {
					$data['duplicateHint'] = true;
				}
			}
		}
		return $data;
	}

	/** @return array<string,mixed> */
	private function base(Reimbursement $reimbursement): array {
		return $reimbursement->jsonSerialize() + [
			'progress' => $this->service->progress($reimbursement, $this->approversOf($reimbursement)),
			'ibanMasked' => Mandate::maskIban($reimbursement->getIban()),
		];
	}

	/** @return list<array<string,mixed>> */
	private function files(Reimbursement $reimbursement): array {
		return array_map(static fn ($file) => $file->jsonSerialize(), $this->service->files($reimbursement->getId()));
	}

	/** @return list<string> */
	private function approversOf(Reimbursement $reimbursement): array {
		return $this->approversById[$reimbursement->getId()] ??= $this->service->approverUids($reimbursement->getId());
	}

	private function userName(string $uid): string {
		return $this->userNames[$uid] ??= ($this->users->get($uid)?->getDisplayName() ?? $uid);
	}

	private function memberName(int $memberId): string {
		if (!isset($this->memberNames[$memberId])) {
			try {
				$this->memberNames[$memberId] = $this->members->find($memberId)->displayName();
			} catch (DoesNotExistException) {
				$this->memberNames[$memberId] = '';
			}
		}
		return $this->memberNames[$memberId];
	}
}

<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Activity\SelfServiceMandateRevocationSetting;
use OCA\Vereinsbuchhaltung\Activity\SelfServiceSetting;
use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCP\Activity\IManager as IActivityManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Orchestriert den Mandats-Aktionskatalog des Self-Service (Spec §3.4,
 * Issue #75) auf {@see MandateService} (#66/#67): löst für JEDE Aktion die
 * `member_id` bereits VOR dem Aufruf hier (SelfController, aus dem
 * {@see ActorContextService}) und danach das betroffene Mandat SERVERSEITIG
 * über {@see MandateService::findLiveByMember()} auf - keine dieser Methoden
 * nimmt eine Mandats-ID entgegen. Ein Mitglied hat laut Spec §2.2 „höchstens
 * EIN LEBENDES Mandat" - es gibt für den Self-Service deshalb schlicht nichts
 * zu identifizieren, das eine fremde ID preisgeben könnte (IDOR-Schutz, siehe
 * SelfController-Klassendoc).
 *
 * Jede schreibende Aktion verkabelt danach dieselben drei Seiten­effekte:
 * Zustandswechsel (MandateService), `OCP\Activity`-Eintrag (Spec §3.4 „jede
 * Änderung") und Quittungsmail (Spec §3.4 „immer", {@see SelfServiceReceiptMailer}) -
 * deshalb ein eigener Dienst statt vier Mal derselben Verkabelung im
 * Controller.
 *
 * Legaltext-Version wird bewusst NICHT vom Client durchgereicht (anders als
 * beim E-Mail-Einmal-Link, dessen Anzeige/Zustimmung tagelang auseinander-
 * liegen können, Spec §2.2 „Version fixiert bei Anzeige"): hier liegen
 * Vorschau und Bestätigung im selben, synchronen Self-Service-Dialog - die
 * jeweils aktuelle Version bei der Bestätigung selbst zu holen ist dafür
 * gleichwertig (und im seltenen Fall einer zwischenzeitlichen Textänderung
 * sogar die rechtlich sauberere Wahl), ohne eine Versions-ID als
 * client-kontrolliertes Eingabefeld einzuführen.
 */
class SelfServiceMandateService {

	/** Subjekt-Konstanten für {@see \OCA\Vereinsbuchhaltung\Activity\SelfServiceProvider}. */
	public const SUBJECT_GRANTED = 'mandate_granted';
	public const SUBJECT_IBAN_CHANGED = 'mandate_iban_changed';
	public const SUBJECT_HOLDER_CHANGED = 'mandate_holder_changed';
	public const SUBJECT_REVOKED = 'mandate_revoked';

	public function __construct(
		private MandateService $mandateService,
		private MandateLegalTextService $legalTextService,
		private MandateFormRenderer $formRenderer,
		private MemberMapper $memberMapper,
		private OpenItemService $openItemService,
		private SelfServiceReceiptMailer $receiptMailer,
		private ActorContextService $actorContext,
		private IActivityManager $activityManager,
		private IRequest $request,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	// --- Lesen ---------------------------------------------------------------

	public function currentMandate(int $memberId): ?Mandate {
		return $this->mandateService->findLiveByMember($memberId);
	}

	public function openClaimsTotalCents(int $memberId): int {
		return $this->openItemService->openClaimsTotalCents($memberId);
	}

	/**
	 * Für die Vorschau vor Erteilung/Kontoinhaberwechsel (Spec §3.4
	 * Pflicht-UI „Vorschau vor jedem Speichern") - derselbe Textkörper wie
	 * Mandatsformular-PDF und öffentliche Zustimmungsseite (Spec §2.2/§3.11,
	 * siehe {@see MandateFormRenderer}).
	 *
	 * @return array{versionId: int, html: string}
	 */
	public function legalTextPreview(): array {
		$legalText = $this->legalTextService->current();
		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		return [
			'versionId' => (int)$legalText->getId(),
			'html' => $this->formRenderer->renderLegalText($legalText, $clubName),
		];
	}

	// --- Schreiben -------------------------------------------------------------

	/**
	 * Mandat erfassen + elektronisch erteilen in einem Schritt (Spec §3.4).
	 *
	 * @throws \InvalidArgumentException bei ungültigen Eingaben oder bereits
	 *                                   bestehendem lebenden Mandat
	 */
	public function grant(int $memberId, string $iban, ?string $bic, ?string $accountHolder): Mandate {
		$member = $this->memberMapper->find($memberId);
		$mandate = $this->mandateService->grantElectronicSelfService(
			$memberId,
			$iban,
			$bic,
			$accountHolder,
			(int)$this->legalTextService->current()->getId(),
			$this->request->getRemoteAddress(),
			(string)$this->request->getHeader('User-Agent'),
			$this->consentActor(),
		);
		$this->afterChange(
			$member,
			$mandate,
			self::SUBJECT_GRANTED,
			$this->l10n->t('Sie haben ein SEPA-Lastschriftmandat elektronisch erteilt (Referenz %s).', [$mandate->getMandateReference()]),
			$this->l10n->t('Wirksam ab sofort.'),
		);
		return $mandate;
	}

	/**
	 * Einen bereits bestehenden elektronischen Entwurf (von der Verwaltung
	 * angelegt, Issue #67) direkt in der Self-Service-Sitzung bestätigen -
	 * die schnellere Alternative zum E-Mail-Einmal-Link
	 * ({@see \OCA\Vereinsbuchhaltung\Controller\SelfController::requestMandateActivationLink()}),
	 * die bereits angemeldeten Mitgliedern einen unnötigen Mail-Umweg erspart.
	 *
	 * @throws \InvalidArgumentException wenn kein passender Entwurf vorliegt
	 */
	public function confirmDraft(int $memberId): Mandate {
		$member = $this->memberMapper->find($memberId);
		$mandate = $this->mandateService->findLiveByMember($memberId);
		if ($mandate === null || !$mandate->isElectronic() || $mandate->getStatus() !== Mandate::STATUS_DRAFT) {
			throw new \InvalidArgumentException($this->l10n->t('Für Sie liegt aktuell kein elektronischer Mandats-Entwurf vor, der eine Bestätigung braucht.'));
		}
		$mandate = $this->mandateService->activateElectronic(
			(int)$mandate->getId(),
			(int)$this->legalTextService->current()->getId(),
			(new \DateTime())->format('Y-m-d H:i:s'),
			$this->request->getRemoteAddress(),
			(string)$this->request->getHeader('User-Agent'),
			$this->consentActor(),
		);
		$this->afterChange(
			$member,
			$mandate,
			self::SUBJECT_GRANTED,
			$this->l10n->t('Sie haben Ihr SEPA-Lastschriftmandat elektronisch bestätigt (Referenz %s).', [$mandate->getMandateReference()]),
			$this->l10n->t('Wirksam ab sofort.'),
		);
		return $mandate;
	}

	/**
	 * IBAN ändern (gleicher Kontoinhaber) - kein Sperrfenster nötig (Spec
	 * §3.4), reitet als Amendment am bestehenden Mandat mit.
	 *
	 * @throws \InvalidArgumentException wenn kein lebendes Mandat vorliegt,
	 *                                   es nicht aktiv ist, oder sich nichts ändert
	 */
	public function changeIban(int $memberId, string $iban, ?string $bic): Mandate {
		$member = $this->memberMapper->find($memberId);
		$mandate = $this->requireLiveMandate($memberId);
		$mandate = $this->mandateService->amendBankDetails((int)$mandate->getId(), $iban, $bic);
		$this->afterChange(
			$member,
			$mandate,
			self::SUBJECT_IBAN_CHANGED,
			$this->l10n->t('Sie haben die Bankverbindung Ihres SEPA-Lastschriftmandats geändert (Referenz %s).', [$mandate->getMandateReference()]),
			$this->l10n->t('Wirksam ab sofort, sofern für die laufende Periode noch keine Vorankündigung verschickt wurde.'),
		);
		return $mandate;
	}

	/**
	 * Kontoinhaberwechsel: erzwingt ein neues Mandat (Spec §3.4/§2.2), hier
	 * elektronisch mit sofortiger Selbst-Aktivierung.
	 *
	 * @throws \InvalidArgumentException wenn kein lebendes Mandat vorliegt,
	 *                                   es nicht aktiv ist, oder der neue Kontoinhaber leer ist
	 */
	public function replaceForNewHolder(int $memberId, string $iban, ?string $bic, string $newAccountHolder): Mandate {
		$member = $this->memberMapper->find($memberId);
		$old = $this->requireLiveMandate($memberId);
		$new = $this->mandateService->replaceElectronicSelfService(
			(int)$old->getId(),
			$iban,
			$bic,
			$newAccountHolder,
			(int)$this->legalTextService->current()->getId(),
			$this->request->getRemoteAddress(),
			(string)$this->request->getHeader('User-Agent'),
			$this->consentActor(),
		);
		$this->afterChange(
			$member,
			$new,
			self::SUBJECT_HOLDER_CHANGED,
			$this->l10n->t('Der Kontoinhaber Ihres SEPA-Lastschriftmandats hat gewechselt – ein neues Mandat wurde elektronisch erteilt (Referenz %1$s, ersetzt %2$s).', [$new->getMandateReference(), $old->getMandateReference()]),
			$this->l10n->t('Wirksam ab sofort.'),
		);
		return $new;
	}

	/**
	 * Widerruf: terminal, „ein Recht" (Spec §3.4) - keine Zweitfaktor-
	 * Bestätigung, die Reibung liegt allein im Dialog davor (SelfController-
	 * Client), nicht in dieser Methode.
	 *
	 * @throws \InvalidArgumentException wenn kein lebendes Mandat vorliegt
	 *                                   oder es weder aktiv noch ausgesetzt ist
	 */
	public function revoke(int $memberId): Mandate {
		$member = $this->memberMapper->find($memberId);
		$mandate = $this->requireLiveMandate($memberId);
		$mandate = $this->mandateService->revoke((int)$mandate->getId());
		$this->afterChange(
			$member,
			$mandate,
			self::SUBJECT_REVOKED,
			$this->l10n->t('Sie haben Ihr SEPA-Lastschriftmandat widerrufen (Referenz %s).', [$mandate->getMandateReference()]),
			$this->l10n->t('Endgültig – ein Widerruf lässt sich nicht rückgängig machen.'),
		);
		return $mandate;
	}

	// --- Hilfsmethoden -----------------------------------------------------------

	/** @throws \InvalidArgumentException wenn das Mitglied kein lebendes Mandat hat */
	private function requireLiveMandate(int $memberId): Mandate {
		$mandate = $this->mandateService->findLiveByMember($memberId);
		if ($mandate === null) {
			throw new \InvalidArgumentException($this->l10n->t('Für Sie liegt aktuell kein Mandat vor.'));
		}
		return $mandate;
	}

	/**
	 * Identität für `consent_actor` (Spec §8 Beweispaket) im authentifizierten
	 * Self-Service: die NC-Konto-Uid der Sitzung - anders als beim anonymen
	 * Einmal-Link gibt es hier keine "an diese Adresse verschickt"-Spur,
	 * dafür eine stärkere: eine bereits angemeldete Sitzung.
	 */
	private function consentActor(): string {
		return $this->actorContext->actorUid() ?? $this->l10n->t('unbekannt');
	}

	private function afterChange(Member $member, Mandate $mandate, string $subject, string $whatText, string $effectiveText): void {
		$this->publishActivity($member, $subject, $whatText);
		$this->receiptMailer->send($member, $this->subjectLabel($subject), $whatText, $effectiveText, null, null);
	}

	/**
	 * OCP\Activity-Eintrag (Spec §3.4 „jede Änderung"). Der Widerruf bekommt
	 * einen EIGENEN Settings-Typ ({@see SelfServiceMandateRevocationSetting}),
	 * weil `ISetting::isDefaultEnabledMail()` nur pro Typ, nicht pro Ereignis
	 * konfigurierbar ist - genau der Unterschied, den Spec §3.4 verlangt
	 * ("Mail-Voreinstellung: an bei Widerruf, aus sonst").
	 */
	private function publishActivity(Member $member, string $subject, string $whatText): void {
		$ncUserId = $member->getNcUserId();
		if ($ncUserId === null) {
			// Praktisch unerreichbar (Self-Service verlangt eine Kontoverknüpfung),
			// zur Sicherheit trotzdem kein Fataler Fehler - eine Aktivitäts-
			// meldung ohne Empfänger wäre ohnehin wirkungslos.
			return;
		}
		try {
			$event = $this->activityManager->generateEvent();
			$event->setApp(Application::APP_ID)
				->setType($subject === self::SUBJECT_REVOKED ? SelfServiceMandateRevocationSetting::TYPE : SelfServiceSetting::TYPE)
				->setAffectedUser($ncUserId)
				->setAuthor($ncUserId)
				->setSubject($subject)
				->setParsedSubject($whatText)
				->setObject('mandate', (int)$member->getId(), $whatText);
			$this->activityManager->publish($event);
		} catch (\Throwable) {
			// Wie AuditService::log(): das Protokoll darf die eigentliche,
			// bereits vollzogene Aktion nicht rückwirkend scheitern lassen.
		}
	}

	private function subjectLabel(string $subject): string {
		return match ($subject) {
			self::SUBJECT_GRANTED => $this->l10n->t('SEPA-Lastschriftmandat erteilt'),
			self::SUBJECT_IBAN_CHANGED => $this->l10n->t('Bankverbindung geändert'),
			self::SUBJECT_HOLDER_CHANGED => $this->l10n->t('Kontoinhaber gewechselt'),
			self::SUBJECT_REVOKED => $this->l10n->t('SEPA-Lastschriftmandat widerrufen'),
			default => $this->l10n->t('Änderung an Ihrem SEPA-Lastschriftmandat'),
		};
	}
}

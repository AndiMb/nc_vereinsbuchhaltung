<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateActivationToken;
use OCA\Vereinsbuchhaltung\Db\MandateActivationTokenMapper;
use OCA\Vereinsbuchhaltung\Db\MandateEvent;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Exception\ConsumedActivationTokenException;
use OCA\Vereinsbuchhaltung\Exception\ExpiredActivationTokenException;
use OCA\Vereinsbuchhaltung\Exception\InvalidActivationTokenException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Security\ISecureRandom;

/**
 * Elektronische Mandatserteilung per E-Mail-Einmal-Link (Spec §2.2/§8, Issue
 * #67) - der Baustein, der {@see MandateService} (Zustandsmaschine) und den
 * öffentlichen, login-losen {@see \OCA\Vereinsbuchhaltung\Controller\MandateConsentController}
 * verbindet. Funktioniert bewusst unabhängig von einem NC-Konto: die
 * Kernanforderung aus Issue #67 ist, dass auch ein Mitglied OHNE
 * Kontoverknüpfung ein Mandat elektronisch erteilen kann.
 *
 * Sicherheitsmodell des Tokens (siehe {@see MandateActivationToken}):
 * Selector/Validator-Muster, nur der SHA-256-Hash des Validators liegt in
 * der DB, der Klartext existiert nur in der versendeten Mail. Zeitlich
 * begrenzt (14 Tage) UND einmal verwendbar. Der Token landet in der URL (das
 * ist sein Zweck als Bearer-Credential, wie jeder Passwort-Reset- oder
 * Freigabe-Link) - niemals IBAN oder andere Bankdaten.
 */
class MandateActivationService {

	/** Alphabet ohne Sonderzeichen: URL-sicher ohne Prozent-Encoding. */
	private const SELECTOR_LENGTH = 16;
	private const VALIDATOR_LENGTH = 64;

	public function __construct(
		private MandateActivationTokenMapper $tokenMapper,
		private MandateMapper $mandateMapper,
		private MemberMapper $memberMapper,
		private MandateService $mandateService,
		private MandateLegalTextService $legalTextService,
		private ISecureRandom $random,
		private IUserManager $userManager,
		private IMailer $mailer,
		private IURLGenerator $urlGenerator,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	// --- Versand -----------------------------------------------------------

	/**
	 * Erzeugt einen neuen Einmal-Link für ein elektronisches Mandat und
	 * verschickt ihn. Ein vorheriger, noch nicht verbrauchter Link desselben
	 * Mandats wird dabei gelöscht (immer nur EIN gültiger Link je Mandat -
	 * verhindert Verwirrung durch mehrere gleichzeitig kursierende Mails und
	 * setzt die "Link-Alter"-Uhr der Aufgabe aus Issue #67 bei einem
	 * Neuversand bewusst zurück).
	 *
	 * @param string|null $requestedByUid uid der/des Mitarbeitenden, die/der
	 *                                    den Versand ausgelöst hat (Admin-Akte); `null` = Selbstbedienungs-
	 *                                    Anfrage über den Self-Service-Kanal (Spec: "teils über den
	 *                                    Self-Service-Kanal, teils über einen login-losen Einmal-Link").
	 * @return array{token: MandateActivationToken, url: string, email: string}
	 * @throws DoesNotExistException wenn es das Mandat nicht gibt
	 * @throws \InvalidArgumentException wenn das Mandat nicht elektronisch/im
	 *                                   Entwurf ist oder keine Mailadresse ermittelbar ist
	 */
	public function issueLink(int $mandateId, ?string $requestedByUid = null): array {
		$mandate = $this->mandateMapper->find($mandateId);
		if (!$mandate->isElectronic()) {
			throw new \InvalidArgumentException($this->l10n->t('Ein Einmal-Link lässt sich nur für elektronische Mandate versenden.'));
		}
		if ($mandate->getStatus() !== Mandate::STATUS_DRAFT) {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein Mandat im Entwurf braucht einen Aktivierungslink.'));
		}
		$member = $this->memberMapper->find($mandate->getMemberId());
		$email = $this->resolveEmail($member);
		if ($email === null) {
			throw new \InvalidArgumentException($this->l10n->t('Für dieses Mitglied ist keine Mailadresse hinterlegt – ohne Mailadresse lässt sich kein Einmal-Link verschicken.'));
		}

		$this->tokenMapper->deleteOutstandingByMandate($mandateId);

		$selector = $this->random->generate(self::SELECTOR_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
		$validator = $this->random->generate(self::VALIDATOR_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);

		$token = new MandateActivationToken();
		$token->setMandateId($mandateId);
		$token->setSelector($selector);
		$token->setValidatorHash($this->hash($validator));
		$token->setEmail($email);
		$token->setRequestedBy($requestedByUid);
		$token->setCreatedAt($this->now());
		$token->setExpiresAt($this->now(new \DateInterval('P' . MandateActivationToken::VALIDITY_DAYS . 'D')));
		$token = $this->tokenMapper->insert($token);

		$url = $this->urlGenerator->linkToRouteAbsolute('vereinsbuchhaltung.mandateConsent.show', ['token' => $this->tokenString($selector, $validator)]);
		$this->sendMail($member, $email, $mandate, $url);

		$actorType = $requestedByUid !== null ? MandateEvent::ACTOR_STAFF : MandateEvent::ACTOR_MEMBER;
		$this->mandateService->logActivationLinkSent($mandate, $email, $actorType);

		return ['token' => $token, 'url' => $url, 'email' => $email];
	}

	/**
	 * Mailadresse für den Einmal-Link (Spec §2.2: "an NC-Konto-Mailadresse
	 * oder bestätigte Mitglieds-Mailadresse"). Vorrang hat die vom Verein
	 * gepflegte Mitglieds-Mailadresse - sie ist die für DIESES Mitglied
	 * bestimmte Kontaktadresse; das NC-Konto ist nur der technische Zugang
	 * (dieselbe Priorität wie {@see SepaNotificationService::resolveRecipient()}
	 * zwischen Mandat und Konto anlegt, hier ohne die dort zusätzliche
	 * Mandats-Mailadresse, die es am neuen {@see Mandate} nicht gibt).
	 */
	private function resolveEmail(Member $member): ?string {
		if ($member->getEmail() !== null && $member->getEmail() !== '') {
			return $member->getEmail();
		}
		$ncUserId = $member->getNcUserId();
		if ($ncUserId === null) {
			return null;
		}
		$user = $this->userManager->get($ncUserId);
		$accountEmail = $user?->getEMailAddress();
		return ($user !== null && $accountEmail !== null && $accountEmail !== '') ? $accountEmail : null;
	}

	private function sendMail(Member $member, string $email, Mandate $mandate, string $url): void {
		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '') ?: $this->l10n->t('Ihr Verein');

		$template = $this->mailer->createEMailTemplate('vereinsbuchhaltung.mandateActivationLink');
		$template->setSubject($this->l10n->t('Bitte bestätigen Sie Ihr SEPA-Lastschriftmandat'));
		$template->addHeader();
		$template->addHeading($this->l10n->t('SEPA-Lastschriftmandat bestätigen'));
		$template->addBodyText($this->l10n->t(
			'%1$s bittet Sie, das SEPA-Lastschriftmandat mit der Referenz %2$s elektronisch zu bestätigen.',
			[$clubName, $mandate->getMandateReference()]
		));
		$template->addBodyText($this->l10n->t('Mit einem Klick auf die Schaltfläche sehen Sie den vollständigen Mandatstext und können zustimmen.'));
		$template->addBodyButton($this->l10n->t('Jetzt bestätigen'), $url);
		$template->addBodyText($this->l10n->t('Dieser Link ist %d Tage gültig und nur einmal verwendbar.', [MandateActivationToken::VALIDITY_DAYS]));
		$template->addFooter();

		$message = $this->mailer->createMessage();
		$message->setTo([$email => $member->displayName()]);
		$message->setSubject($template->renderSubject());
		$message->useTemplate($template);
		$this->mailer->send($message);
	}

	// --- Anzeige/Zustimmung --------------------------------------------------

	/**
	 * Löst den Klartext-Token auf und prüft Gültigkeit (Format, Selector,
	 * Validator-Hash, Ablauf) - NICHT, ob er bereits verbraucht ist (das
	 * entscheiden {@see view()}/{@see consent()} je nach Kontext
	 * unterschiedlich, siehe dortige Doku).
	 *
	 * @throws InvalidActivationTokenException
	 * @throws ExpiredActivationTokenException
	 */
	private function resolve(string $rawToken): MandateActivationToken {
		$parts = explode('.', $rawToken, 2);
		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
			throw new InvalidActivationTokenException($this->l10n->t('Dieser Link ist ungültig.'));
		}
		[$selector, $validator] = $parts;
		$token = $this->tokenMapper->findBySelector($selector);
		if ($token === null || !hash_equals($token->getValidatorHash(), $this->hash($validator))) {
			// Bewusst dieselbe Fehlermeldung wie bei unbekanntem Selector: ob der
			// Selector existierte, aber der Validator falsch war, darf von außen
			// nicht unterscheidbar sein (Timing/Informationsleck).
			throw new InvalidActivationTokenException($this->l10n->t('Dieser Link ist ungültig.'));
		}
		if ($token->isExpired(new \DateTimeImmutable())) {
			throw new ExpiredActivationTokenException($this->l10n->t('Dieser Link ist abgelaufen. Bitte fordern Sie einen neuen an.'));
		}
		return $token;
	}

	/**
	 * Für die GET-Anzeige der Zustimmungsseite. Fixiert bei der ERSTEN Anzeige
	 * die geltende {@see MandateLegalTextVersion} (Spec §2.2: "Version wird
	 * bei Anzeige fixiert, nicht bei signed_at") - jede weitere Anzeige
	 * desselben Links zeigt danach immer dieselbe, einmal fixierte Version,
	 * auch wenn der Rahmen inzwischen erneut geändert wurde.
	 *
	 * Ein BEREITS VERBRAUCHTER Link ist hier kein Fehler, sondern ein
	 * normaler Anzeigezustand ("Sie haben bereits zugestimmt") - ein
	 * Mitglied, das seine Bestätigungsmail zweimal öffnet, soll keine
	 * Fehlerseite sehen.
	 *
	 * @return array{status: 'pending'|'consumed', mandate: Mandate, legalText: \OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion, token: MandateActivationToken}
	 * @throws InvalidActivationTokenException
	 * @throws ExpiredActivationTokenException
	 * @throws DoesNotExistException wenn das Mandat zum (gültigen) Token nicht mehr existiert
	 */
	public function view(string $rawToken): array {
		$token = $this->resolve($rawToken);
		$mandate = $this->mandateMapper->find($token->getMandateId());

		if ($token->isConsumed()) {
			$legalText = $this->legalTextService->find((int)$mandate->getMandateTextVersion()) ?? $this->legalTextService->current();
			return ['status' => 'consumed', 'mandate' => $mandate, 'legalText' => $legalText, 'token' => $token];
		}

		if ($token->getLegalTextVersionId() === null) {
			$legalText = $this->legalTextService->current();
			$token->setLegalTextVersionId((int)$legalText->getId());
			$token->setFirstViewedAt($token->getFirstViewedAt() ?? $this->now());
			$token = $this->tokenMapper->update($token);
		} else {
			$legalText = $this->legalTextService->find($token->getLegalTextVersionId()) ?? $this->legalTextService->current();
		}

		return ['status' => 'pending', 'mandate' => $mandate, 'legalText' => $legalText, 'token' => $token];
	}

	/**
	 * Die Zustimmung selbst - aktiviert das Mandat (Spec §2.2: "aktiviert
	 * sich bei Zustimmung selbst, kein manuelles Gate mehr"). `consent_actor`
	 * ist die Mailadresse, an die dieser konkrete Link verschickt wurde
	 * (`$token->getEmail()`, nicht die evtl. seither geänderte
	 * Mitglieds-Mailadresse) - das Beweispaket muss beschreiben, WOHIN
	 * tatsächlich zugestellt wurde.
	 *
	 * @throws InvalidActivationTokenException
	 * @throws ExpiredActivationTokenException
	 * @throws ConsumedActivationTokenException
	 * @throws DoesNotExistException wenn das Mandat zum (gültigen) Token nicht mehr existiert
	 */
	public function consent(string $rawToken, string $ip, string $userAgent): Mandate {
		$token = $this->resolve($rawToken);
		if ($token->isConsumed()) {
			throw new ConsumedActivationTokenException($this->l10n->t('Diesem Mandat wurde bereits zugestimmt.'));
		}

		// Verteidigungslinie: normalerweise ist die Version durch die
		// vorangegangene GET-Anzeige längst fixiert (view()); ein direkter
		// POST ohne vorherige Anzeige (z.B. ein Test-Client) fixiert sie hier
		// nachträglich, statt mit einem internen Fehler abzubrechen.
		$legalTextVersionId = $token->getLegalTextVersionId();
		if ($legalTextVersionId === null) {
			$legalTextVersionId = (int)$this->legalTextService->current()->getId();
		}

		$now = $this->now();
		$mandate = $this->mandateService->activateElectronic($token->getMandateId(), $legalTextVersionId, $now, $ip, $userAgent, $token->getEmail());

		$token->setConsumedAt($now);
		if ($token->getLegalTextVersionId() === null) {
			$token->setLegalTextVersionId($legalTextVersionId);
		}
		$this->tokenMapper->update($token);

		return $mandate;
	}

	// --- Aufgabe: "Mandat-Entwurf elektronisch, Link ≥14 Tage alt" ------------

	/**
	 * Abgeleitete Aufgaben-Abfrage für {@see \OCA\Vereinsbuchhaltung\Controller\TaskController}
	 * (Issue #67): ein elektronischer Entwurf mit ausstehendem Link ist keine
	 * Störung, solange der Link noch gültig ist (bloßer Hinweis - das
	 * Mitglied hatte schlicht noch keine Gelegenheit) - erst wenn der Link
	 * abgelaufen ist (Spec-Formulierung "≥14 Tage alt", deckungsgleich mit
	 * {@see MandateActivationToken::VALIDITY_DAYS}), wird daraus
	 * Handlungsbedarf: erneut versenden oder auf den Papier-Weg wechseln.
	 *
	 * Bewusst eine reine Abfrage ohne eigene Persistenz (wie
	 * {@see MandateService::findDueForExpiryWarning()}) statt eines
	 * {@see \OCA\Vereinsbuchhaltung\Db\Task}-Datensatzes: der Zustand lässt
	 * sich jederzeit aus Mandat + Token neu ableiten, siehe Klassendoc von
	 * {@see \OCA\Vereinsbuchhaltung\Db\Task}.
	 *
	 * @return list<array{severity: string, message: string, objectType: string, objectId: int}>
	 */
	public function findStaleElectronicDraftTasks(?string $today = null): array {
		$now = new \DateTimeImmutable($today ?? 'now');
		$tasks = [];
		foreach ($this->tokenMapper->findOldestOutstandingByElectronicDraftMandates() as $mandateId => $token) {
			$mandate = $this->mandateMapper->findOrNull($mandateId);
			if ($mandate === null) {
				continue;
			}
			$member = $this->memberMapper->findOrNull($mandate->getMemberId());
			$name = $member?->displayName() ?? $this->l10n->t('unbekanntes Mitglied');
			$ageDays = (int)floor(($now->getTimestamp() - (new \DateTimeImmutable($token->getCreatedAt()))->getTimestamp()) / 86400);

			if ($token->isExpired($now)) {
				$tasks[] = [
					'severity' => Task::SEVERITY_ACTION_REQUIRED,
					'message' => $this->l10n->t('Elektronischer Erteilungslink für %1$s (Mandat %2$s) ist seit %3$d Tagen abgelaufen, ohne dass zugestimmt wurde – erneut versenden oder auf den Papier-Weg wechseln.', [$name, $mandate->getMandateReference(), $ageDays]),
					'objectType' => 'mandate',
					'objectId' => $mandateId,
				];
			} else {
				$tasks[] = [
					'severity' => Task::SEVERITY_HINT,
					'message' => $this->l10n->t('Elektronischer Erteilungslink für %1$s (Mandat %2$s) wartet seit %3$d Tagen auf Zustimmung.', [$name, $mandate->getMandateReference(), $ageDays]),
					'objectType' => 'mandate',
					'objectId' => $mandateId,
				];
			}
		}
		return $tasks;
	}

	// --- Hilfsmethoden -----------------------------------------------------------

	private function tokenString(string $selector, string $validator): string {
		return $selector . '.' . $validator;
	}

	private function hash(string $validator): string {
		return hash('sha256', $validator);
	}

	private function now(?\DateInterval $add = null): string {
		$date = new \DateTime();
		if ($add !== null) {
			$date->add($add);
		}
		return $date->format('Y-m-d H:i:s');
	}
}

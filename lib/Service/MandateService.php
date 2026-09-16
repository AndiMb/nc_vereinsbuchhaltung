<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateAmendment;
use OCA\Vereinsbuchhaltung\Db\MandateAmendmentMapper;
use OCA\Vereinsbuchhaltung\Db\MandateEvent;
use OCA\Vereinsbuchhaltung\Db\MandateEventMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Voller Mandats-Lebenszyklus (Papier-Weg) – Spec §2.2 „Mandat (Mandate)"/
 * §3.2 „Mandats-Lifecycle", Issue #66. Orchestriert
 * {@see MandateStateMachine} (erlaubte Übergänge), {@see MandateExpiryCalculator}
 * (36-Monats-Verfall) und {@see MandateReferenceGenerator}
 * (`<Präfix>-<lfd.Nr.>`); schreibt bei jeder Änderung sowohl den allgemeinen
 * {@see AuditService} als auch die mandatseigene, feingranularere Historie
 * ({@see MandateEvent}, mit `actor_type` – Spec §3.9).
 *
 * Elektronische Aktivierung (Selbst-Aktivierung bei Zustimmung über den
 * Einmal-Link, Issue #67) ist {@see activateElectronic()} - orchestriert vom
 * eigenen {@see MandateActivationService} (Token-Lebenszyklus, Mailversand),
 * der `actor_type: member` protokolliert. Jeder andere Aufruf hier bleibt
 * `actor_type: staff`, mit Ausnahme des automatischen Verfalls-Crons
 * (`actor_type: system`).
 */
class MandateService {

	public const SETTING_REFERENCE_PREFIX = 'mandate_reference_prefix';

	public function __construct(
		private MandateMapper $mapper,
		private MandateAmendmentMapper $amendmentMapper,
		private MandateEventMapper $eventMapper,
		private MemberMapper $memberMapper,
		private MandateStateMachine $stateMachine,
		private MandateExpiryCalculator $expiryCalculator,
		private MandateReferenceGenerator $referenceGenerator,
		private MandateDocumentService $documents,
		private IbanValidator $ibanValidator,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private IUserSession $userSession,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	// --- Lesen ---------------------------------------------------------------

	/** @return Mandate[] */
	public function findAll(): array {
		return $this->mapper->findAll();
	}

	/** @throws DoesNotExistException wenn es das Mandat nicht (mehr) gibt */
	public function find(int $id): Mandate {
		return $this->mapper->find($id);
	}

	/** @return Mandate[] gesamte Historie eines Mitglieds, neueste zuerst */
	public function findByMember(int $memberId): array {
		return $this->mapper->findByMember($memberId);
	}

	/** Das eine lebende Mandat eines Mitglieds, falls es eines gibt. */
	public function findLiveByMember(int $memberId): ?Mandate {
		$live = $this->mapper->findLiveByMember($memberId);
		return $live[0] ?? null;
	}

	/** @return MandateEvent[] neueste zuerst */
	public function history(int $mandateId): array {
		return $this->eventMapper->findByMandate($mandateId);
	}

	/** @return MandateAmendment[] neueste zuerst */
	public function amendments(int $mandateId): array {
		return $this->amendmentMapper->findByMandate($mandateId);
	}

	// --- Anlegen (Papier-Weg) --------------------------------------------------

	/**
	 * Legt einen Mandats-Entwurf an. `account_holder` wird, falls leer, mit
	 * dem Anzeigenamen des Mitglieds vorbefüllt (Spec §2.2: „Pflicht,
	 * vorbefüllt = Anzeigename"). Aktiviert ist der Entwurf noch nicht – dafür
	 * {@see activatePaper()}.
	 *
	 * @throws DoesNotExistException wenn es das Mitglied nicht gibt
	 * @throws \InvalidArgumentException bei ungültigen Eingaben oder bereits
	 *                                   bestehendem lebenden Mandat
	 */
	public function createPaper(
		int $memberId,
		string $iban,
		?string $bic,
		?string $accountHolder,
		?string $signedAt = null,
		?string $mandateReference = null,
	): Mandate {
		return $this->createDraft($memberId, $iban, $bic, $accountHolder, Mandate::SIGNATURE_PAPER, $signedAt, $mandateReference);
	}

	/**
	 * Legt einen elektronischen Mandats-Entwurf an (Issue #67): fachlich
	 * dasselbe wie {@see createPaper()} (Entwurf, `member_id` + Bankdaten),
	 * nur `signature_type: elektronisch` und ohne `signed_at` - das setzt erst
	 * {@see activateElectronic()} bei der Zustimmung selbst, denn beim
	 * elektronischen Weg IST die Zustimmung die Unterschrift, keine separat
	 * einzugebende Angabe. Der Versand des Einmal-Links ist ein eigener
	 * Schritt ({@see MandateActivationService::issueLink()}), nicht Teil
	 * dieser Methode - so lässt sich ein Entwurf anlegen, ohne sofort eine
	 * Mailadresse parat haben zu müssen.
	 *
	 * @throws DoesNotExistException wenn es das Mitglied nicht gibt
	 * @throws \InvalidArgumentException bei ungültigen Eingaben oder bereits
	 *                                   bestehendem lebenden Mandat
	 */
	public function createElectronic(
		int $memberId,
		string $iban,
		?string $bic,
		?string $accountHolder,
		?string $mandateReference = null,
	): Mandate {
		return $this->createDraft($memberId, $iban, $bic, $accountHolder, Mandate::SIGNATURE_ELECTRONIC, null, $mandateReference);
	}

	private function createDraft(
		int $memberId,
		string $iban,
		?string $bic,
		?string $accountHolder,
		string $signatureType,
		?string $signedAt,
		?string $mandateReference,
	): Mandate {
		$member = $this->memberMapper->find($memberId);
		$this->stateMachine->assertNoLiveMandate($this->mapper->findLiveByMember($memberId));

		$holder = trim((string)$accountHolder);
		if ($holder === '') {
			$holder = $member->displayName();
		}
		if ($holder === '') {
			throw new \InvalidArgumentException($this->l10n->t('Der Kontoinhaber ist Pflicht.'));
		}

		return $this->transaction->run(function () use ($memberId, $iban, $bic, $holder, $signatureType, $signedAt, $mandateReference): Mandate {
			$mandate = new Mandate();
			$mandate->setMemberId($memberId);
			$mandate->setMandateReference($mandateReference !== null && trim($mandateReference) !== '' ? trim($mandateReference) : $this->generateReference());
			$mandate->setIban($this->requireIban($iban));
			$mandate->setBic($this->normalizeBic($bic));
			$mandate->setAccountHolder($holder);
			$mandate->setSignatureType($signatureType);
			$mandate->setSignedAt($this->normalizeDate($signedAt));
			$mandate->setStatus(Mandate::STATUS_DRAFT);
			$mandate->setCreatedAt($this->now());
			$mandate = $this->mapper->insert($mandate);

			$this->logCreated($mandate, $signatureType === Mandate::SIGNATURE_ELECTRONIC
				? $this->l10n->t('Mandats-Entwurf angelegt (elektronisch)')
				: null);
			return $mandate;
		});
	}

	/**
	 * Selbst-Aktivierung bei Zustimmung über den Einmal-Link (Spec §2.2, Issue
	 * #67): KEIN manuelles Gate - `assertCanActivateElectronic()` verlangt nur
	 * Entwurf + `signature_type: elektronisch`, die Zustimmung selbst ersetzt
	 * die Unterschrift. Aufrufer ist ausschließlich
	 * {@see MandateActivationService::consent()}, NIE ein Controller direkt -
	 * das Beweispaket (Version/IP/User-Agent/Identität) muss vollständig
	 * vorliegen, bevor irgendetwas aktiviert wird.
	 *
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException wenn der Übergang nicht erlaubt ist
	 */
	public function activateElectronic(
		int $id,
		int $mandateTextVersionId,
		string $consentAt,
		string $consentIp,
		string $consentUserAgent,
		string $consentActor,
	): Mandate {
		$mandate = $this->mapper->find($id);
		$this->stateMachine->assertCanActivateElectronic($mandate);

		$mandate->setMandateTextVersion($mandateTextVersionId);
		$mandate->setConsentAt($consentAt);
		$mandate->setConsentIp($consentIp);
		$mandate->setConsentUserAgent($consentUserAgent);
		$mandate->setConsentActor($consentActor);
		// Die Zustimmung IST die Unterschrift (Spec §2.2) - signed_at wird erst
		// hier, mit dem Zustimmungsdatum, gesetzt. Das lässt das Mandat auch in
		// {@see MandateMapper::findCandidatesForExpiry()} (verlangt signed_at)
		// und damit im 36-Monats-Verfall-Cron ankommen wie jedes andere Mandat.
		$mandate->setSignedAt(substr($consentAt, 0, 10));
		$mandate->setStatus(Mandate::STATUS_ACTIVE);
		$mandate->setActivatedAt($this->now());
		$mandate = $this->mapper->update($mandate);

		$this->audit->log('SEPA-Mandat elektronisch aktiviert (Einmal-Link)', 'mandate', $mandate->getId(), ['referenz' => $mandate->getMandateReference()]);
		$this->logEvent($mandate, $this->l10n->t('Mandat elektronisch aktiviert (Zustimmung per E-Mail-Link an %s)', [$consentActor]), MandateEvent::ACTOR_MEMBER);
		return $mandate;
	}

	/**
	 * Manuelles Aktivierungs-Gate durch `buchhalter` (Spec §2.2): verlangt
	 * `signed_at` – wird es hier mitgegeben, ergänzt es einen Entwurf, der
	 * beim Anlegen noch kein Unterschriftsdatum hatte.
	 *
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException wenn der Übergang nicht erlaubt ist
	 */
	public function activatePaper(int $id, ?string $signedAt = null): Mandate {
		$mandate = $this->mapper->find($id);
		if ($signedAt !== null && trim($signedAt) !== '') {
			$mandate->setSignedAt($this->normalizeDate($signedAt));
		}
		$this->stateMachine->assertCanActivatePaper($mandate);

		$mandate->setStatus(Mandate::STATUS_ACTIVE);
		$mandate->setActivatedAt($this->now());
		$mandate = $this->mapper->update($mandate);

		$this->audit->log('SEPA-Mandat aktiviert', 'mandate', $mandate->getId(), ['referenz' => $mandate->getMandateReference()]);
		$this->logEvent($mandate, $this->l10n->t('Mandat aktiviert (Papier, unterschrieben am %s)', [(string)$mandate->getSignedAt()]), MandateEvent::ACTOR_STAFF);
		return $mandate;
	}

	/**
	 * Sperre: manueller Auslöser mit Pflicht-Notiz (Spec §2.2). Beendet
	 * nichts – offene Forderungen bleiben offen, nur ein künftiger Einzug
	 * über dieses Mandat ist ausgeschlossen (`isCollectible()` wird false).
	 *
	 * @throws \InvalidArgumentException wenn die Notiz fehlt oder der Übergang nicht erlaubt ist
	 */
	public function suspend(int $id, string $note, string $origin = Mandate::SUSPENSION_MANUAL): Mandate {
		if (trim($note) === '') {
			throw new \InvalidArgumentException($this->l10n->t('Für eine Sperre ist eine Notiz Pflicht.'));
		}
		if (!in_array($origin, Mandate::SUSPENSION_ORIGINS, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültiger Sperr-Ursprung: %s', [$origin]));
		}
		// Rücklastschrift als Auslöser kommt erst mit #73 (Rücklastschrift-
		// Fachlogik) – ohne die zugehörige Erkennung wäre eine hier manuell
		// gewählte "ruecklastschrift"-Sperre ein irreführender Datensatz.
		if ($origin === Mandate::SUSPENSION_RETURNED_DEBIT) {
			throw new \InvalidArgumentException($this->l10n->t('Die automatische Sperre bei Rücklastschrift ist noch nicht verfügbar.'));
		}

		$mandate = $this->mapper->find($id);
		$this->stateMachine->assertCanSuspend($mandate);

		$mandate->setStatus(Mandate::STATUS_SUSPENDED);
		$mandate->setSuspendedAt($this->now());
		$mandate->setSuspendedBy($this->currentUid());
		$mandate->setSuspensionOrigin($origin);
		$mandate->setSuspensionNote($note);
		$mandate = $this->mapper->update($mandate);

		$this->audit->log('SEPA-Mandat gesperrt', 'mandate', $mandate->getId(), ['referenz' => $mandate->getMandateReference(), 'notiz' => $note]);
		$this->logEvent($mandate, $this->l10n->t('Mandat gesperrt: %s', [$note]), MandateEvent::ACTOR_STAFF);
		return $mandate;
	}

	/**
	 * Entsperren: nur manuell, keine Auto-Entsperrung (Spec §2.2) – dass
	 * dieser Aufruf überhaupt stattfindet, ist bereits der manuelle Akt.
	 *
	 * @throws \InvalidArgumentException wenn der Übergang nicht erlaubt ist
	 */
	public function resume(int $id): Mandate {
		$mandate = $this->mapper->find($id);
		$this->stateMachine->assertCanResume($mandate);

		$mandate->setStatus(Mandate::STATUS_ACTIVE);
		$mandate->setSuspendedAt(null);
		$mandate->setSuspendedBy(null);
		$mandate->setSuspensionOrigin(null);
		$mandate->setSuspensionNote(null);
		$mandate = $this->mapper->update($mandate);

		$this->audit->log('SEPA-Mandat entsperrt', 'mandate', $mandate->getId(), ['referenz' => $mandate->getMandateReference()]);
		$this->logEvent($mandate, $this->l10n->t('Mandat entsperrt'), MandateEvent::ACTOR_STAFF);
		return $mandate;
	}

	/**
	 * Widerruf: terminal, nie reaktivierbar (Spec §2.2).
	 *
	 * @throws \InvalidArgumentException wenn der Übergang nicht erlaubt ist
	 */
	public function revoke(int $id): Mandate {
		$mandate = $this->mapper->find($id);
		$this->stateMachine->assertCanRevoke($mandate);
		$this->end($mandate, Mandate::END_REASON_REVOKED);

		$this->audit->log('SEPA-Mandat widerrufen', 'mandate', $mandate->getId(), ['referenz' => $mandate->getMandateReference()]);
		$this->logEvent($mandate, $this->l10n->t('Mandat widerrufen'), MandateEvent::ACTOR_STAFF);
		return $mandate;
	}

	/**
	 * Reine Namenskorrektur derselben Person (Heirat, Tippfehler): stille
	 * Korrektur, kein Amendment, kein neues Mandat (Spec §2.2). Ob es sich um
	 * dieselbe Person handelt, ist eine fachliche Einschätzung der/des
	 * Buchhalter:in – nicht automatisch aus dem Namensvergleich ableitbar
	 * (ein Tippfehler UND ein echter Kontoinhaberwechsel ändern beide den
	 * Text). Für den Kontoinhaberwechsel gibt es deshalb den eigenen,
	 * bewusst anders benannten Weg {@see replaceMandate()}.
	 *
	 * @throws \InvalidArgumentException wenn der neue Name leer ist
	 */
	public function correctAccountHolderName(int $id, string $newName): Mandate {
		$newName = trim($newName);
		if ($newName === '') {
			throw new \InvalidArgumentException($this->l10n->t('Der Kontoinhaber ist Pflicht.'));
		}
		$mandate = $this->mapper->find($id);
		$oldName = $mandate->getAccountHolder();
		if ($oldName === $newName) {
			return $mandate;
		}
		$mandate->setAccountHolder($newName);
		$mandate = $this->mapper->update($mandate);

		$this->logEvent($mandate, $this->l10n->t('Kontoinhaber korrigiert: "%1$s" → "%2$s"', [$oldName, $newName]), MandateEvent::ACTOR_STAFF);
		return $mandate;
	}

	/**
	 * IBAN/BIC-Wechsel bei *gleichem* Kontoinhaber: dasselbe Mandat bleibt
	 * bestehen, keine neue Unterschrift – stattdessen ein
	 * `MandateAmendment(type: account)` mit den alten Werten (Spec §2.2,
	 * Compliance-Anhang §8: `AmdmntInd=true` + `OrgnlDbtrAcct=SMNDA`).
	 *
	 * @throws \InvalidArgumentException wenn sich nichts ändert oder der Übergang nicht erlaubt ist
	 */
	public function amendBankDetails(int $id, string $iban, ?string $bic): Mandate {
		$mandate = $this->mapper->find($id);
		$this->stateMachine->assertCanAmend($mandate);

		$newIban = $this->requireIban($iban);
		$newBic = $this->normalizeBic($bic);
		if ($newIban === $mandate->getIban() && $newBic === $mandate->getBic()) {
			throw new \InvalidArgumentException($this->l10n->t('IBAN und BIC sind unverändert – dafür ist kein Amendment nötig.'));
		}

		return $this->transaction->run(function () use ($mandate, $newIban, $newBic): Mandate {
			$amendment = new MandateAmendment();
			$amendment->setMandateId((int)$mandate->getId());
			$amendment->setType(MandateAmendment::TYPE_ACCOUNT);
			$amendment->setOldIban($mandate->getIban());
			$amendment->setOldBic($mandate->getBic());
			$amendment->setOldAccountHolder($mandate->getAccountHolder());
			$amendment->setStatus(MandateAmendment::STATUS_OPEN);
			$amendment->setCreatedAt($this->now());
			$this->amendmentMapper->insert($amendment);

			$mandate->setIban($newIban);
			$mandate->setBic($newBic);
			$mandate = $this->mapper->update($mandate);

			$this->audit->log('SEPA-Mandat: Bankverbindung per Amendment geändert', 'mandate', $mandate->getId(), ['referenz' => $mandate->getMandateReference()]);
			$this->logEvent($mandate, $this->l10n->t('Bankverbindung geändert (Amendment, alte IBAN %s)', [(string)$amendment->getOldIban()]), MandateEvent::ACTOR_STAFF);
			return $mandate;
		});
	}

	/**
	 * Kontoinhaberwechsel: neues Mandat, das alte `ended`/`replaced` (Spec
	 * §2.2) – SEPA ließe ein Amendment technisch zu, das Mandat ist aber die
	 * Erlaubnis *des Kontoinhabers*, nicht der IBAN. Das neue Mandat startet
	 * als Entwurf und braucht eine eigene Aktivierung samt Unterschrift.
	 *
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException wenn der Übergang nicht erlaubt ist
	 */
	public function replaceMandate(int $oldId, string $iban, ?string $bic, string $newAccountHolder, ?string $signedAt = null): Mandate {
		$old = $this->mapper->find($oldId);
		$this->stateMachine->assertCanReplace($old);
		$newAccountHolder = trim($newAccountHolder);
		if ($newAccountHolder === '') {
			throw new \InvalidArgumentException($this->l10n->t('Der Kontoinhaber ist Pflicht.'));
		}

		return $this->transaction->run(function () use ($old, $iban, $bic, $newAccountHolder, $signedAt): Mandate {
			$this->end($old, Mandate::END_REASON_REPLACED);

			$new = new Mandate();
			$new->setMemberId($old->getMemberId());
			$new->setMandateReference($this->generateReference());
			$new->setIban($this->requireIban($iban));
			$new->setBic($this->normalizeBic($bic));
			$new->setAccountHolder($newAccountHolder);
			$new->setSignatureType(Mandate::SIGNATURE_PAPER);
			$new->setSignedAt($this->normalizeDate($signedAt));
			$new->setStatus(Mandate::STATUS_DRAFT);
			$new->setCreatedAt($this->now());
			$new = $this->mapper->insert($new);

			$this->audit->log('SEPA-Mandat ersetzt (Kontoinhaberwechsel)', 'mandate', $new->getId(), [
				'alte_referenz' => $old->getMandateReference(),
				'neue_referenz' => $new->getMandateReference(),
			]);
			$this->logEvent($old, $this->l10n->t('Mandat ersetzt durch %s (Kontoinhaberwechsel)', [$new->getMandateReference()]), MandateEvent::ACTOR_STAFF);
			$this->logCreated($new, $this->l10n->t('Ersetzt Mandat %s (Kontoinhaberwechsel)', [$old->getMandateReference()]));
			return $new;
		});
	}

	/** Manuelles Zurücksetzen eines Amendments auf `open` (Spec §2.2) – die automatische Rücklastschrift-Auslösung folgt erst mit #73. */
	public function reopenAmendment(int $amendmentId): MandateAmendment {
		$amendment = $this->amendmentMapper->find($amendmentId);
		$amendment->setStatus(MandateAmendment::STATUS_OPEN);
		return $this->amendmentMapper->update($amendment);
	}

	/**
	 * Protokolliert den Versand eines elektronischen Aktivierungslinks
	 * (Issue #67) in der Mandats-Historie – aufgerufen von
	 * {@see MandateActivationService::issueLink()}, die selbst keinen
	 * direkten Zugriff auf {@see MandateEventMapper} hat (dieselbe
	 * Kapselung wie bei jeder anderen Zustandsänderung: nur
	 * {@see MandateService} schreibt in `vbh_mandate_events`).
	 */
	public function logActivationLinkSent(Mandate $mandate, string $email, string $actorType): void {
		$this->logEvent($mandate, $this->l10n->t('Elektronischer Aktivierungslink versendet an %s', [$email]), $actorType);
	}

	/** Nachweis-Upload; setzt `document_file_id` (Spec §3.2). */
	public function uploadDocument(int $id, string $fileName, string $content): Mandate {
		$mandate = $this->mapper->find($id);
		$fileId = $this->documents->store($mandate, $fileName, $content);
		$mandate->setDocumentFileId($fileId);
		$mandate = $this->mapper->update($mandate);
		$this->logEvent($mandate, $this->l10n->t('Nachweis-Dokument hinterlegt'), MandateEvent::ACTOR_STAFF);
		return $mandate;
	}

	// --- Cron: 36-Monats-Verfall -----------------------------------------------

	/**
	 * Täglicher Übergang zu `ended`/`expired` (Spec §2.2/§7/§8). Die
	 * 180-Tage-Vorwarnung ist laut Spec §7 eine abgeleitete Abfrage ohne
	 * eigene Persistenz – {@see findDueForExpiryWarning()} liefert die
	 * Kandidaten dafür, ohne dass dieser Lauf selbst etwas schreibt.
	 *
	 * @param string|null $today Stichtag, sonst heute (für Tests)
	 * @return array{expired:int} Anzahl der in diesem Lauf beendeten Mandate
	 */
	public function expireDueMandates(?string $today = null): array {
		$todayDate = new \DateTimeImmutable($today ?? 'today');
		$expired = 0;
		foreach ($this->mapper->findCandidatesForExpiry() as $mandate) {
			if (!$this->expiryCalculator->isDueForExpiry($mandate, $todayDate)) {
				continue;
			}
			$this->end($mandate, Mandate::END_REASON_EXPIRED);
			$this->audit->log('SEPA-Mandat automatisch verfallen (36-Monats-Frist)', 'mandate', $mandate->getId(), [
				'referenz' => $mandate->getMandateReference(),
			], actor: 'system');
			$this->logEvent($mandate, $this->l10n->t('Mandat automatisch verfallen (36-Monats-Frist)'), MandateEvent::ACTOR_SYSTEM);
			$expired++;
		}
		return ['expired' => $expired];
	}

	/**
	 * Kandidaten für die 180-Tage-Vorwarnung (Spec §7) – keine eigene
	 * Persistenz, nur eine Abfrage für eine künftige, modulübergreifende
	 * Aufgabenliste.
	 *
	 * @return Mandate[]
	 */
	public function findDueForExpiryWarning(?string $today = null): array {
		$todayDate = new \DateTimeImmutable($today ?? 'today');
		return array_values(array_filter(
			$this->mapper->findCandidatesForExpiry(),
			fn (Mandate $m): bool => $this->expiryCalculator->needsExpiryWarning($m, $todayDate),
		));
	}

	// --- Referenz --------------------------------------------------------------

	public function generateReference(): string {
		$prefix = trim($this->config->getAppValue(Application::APP_ID, self::SETTING_REFERENCE_PREFIX, MandateReferenceGenerator::DEFAULT_PREFIX));
		$prefix = $prefix !== '' ? $prefix : MandateReferenceGenerator::DEFAULT_PREFIX;
		$existing = $this->mapper->findReferencesWithPrefix($prefix);
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$candidate = $this->referenceGenerator->next($prefix, $existing);
			if ($this->mapper->findByReference($candidate) === null) {
				return $candidate;
			}
			$existing[] = $candidate;
		}
		throw new \RuntimeException('Konnte keine eindeutige Mandatsreferenz erzeugen.');
	}

	// --- Hilfsmethoden -----------------------------------------------------------

	private function end(Mandate $mandate, string $endReason): void {
		$mandate->setStatus(Mandate::STATUS_ENDED);
		$mandate->setEndedAt($this->now());
		$mandate->setEndReason($endReason);
		$this->mapper->update($mandate);
	}

	private function logCreated(Mandate $mandate, ?string $message = null): void {
		$this->audit->log('SEPA-Mandat angelegt (Papier)', 'mandate', $mandate->getId(), [
			'referenz' => $mandate->getMandateReference(),
			'mitgliedId' => $mandate->getMemberId(),
		]);
		$this->logEvent($mandate, $message ?? $this->l10n->t('Mandats-Entwurf angelegt (Papier)'), MandateEvent::ACTOR_STAFF);
	}

	private function logEvent(Mandate $mandate, string $message, string $actorType, ?string $onBehalfNote = null): void {
		$event = new MandateEvent();
		$event->setMandateId((int)$mandate->getId());
		$event->setActorType($actorType);
		$event->setActorUid($actorType === MandateEvent::ACTOR_STAFF ? $this->currentUid() : null);
		$event->setOnBehalfNote($onBehalfNote);
		$event->setMessage($message);
		$event->setCreatedAt($this->now());
		$this->eventMapper->insert($event);
	}

	private function currentUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	private function requireIban(string $iban): string {
		$normalized = $this->ibanValidator->validate($iban);
		if ($normalized === null) {
			throw new \InvalidArgumentException($this->l10n->t('Die IBAN ist Pflicht.'));
		}
		return $normalized;
	}

	private function normalizeBic(?string $bic): ?string {
		$bic = trim((string)$bic);
		return $bic !== '' ? strtoupper($bic) : null;
	}

	/**
	 * @throws \InvalidArgumentException bei einem ungültigen oder nicht existierenden Datum
	 */
	private function normalizeDate(?string $date): ?string {
		$date = trim((string)$date);
		if ($date === '') {
			return null;
		}
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültiges Datum (erwartet JJJJ-MM-TT).'));
		}
		if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException($this->l10n->t('Diesen Tag gibt es nicht: %s', [$date]));
		}
		return $date;
	}

	private function now(): string {
		return (new \DateTime())->format('Y-m-d H:i:s');
	}
}

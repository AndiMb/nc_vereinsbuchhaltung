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
 * der `actor_type: member` protokolliert. Jeder andere Aufruf hier las bisher
 * (#66) `actor_type: staff` fest verdrahtet; seit Issue #75 ({@see
 * grantElectronicSelfService()}/{@see replaceElectronicSelfService()} sowie
 * die direkt wiederverwendeten {@see revoke()}/{@see amendBankDetails()})
 * liest jede Zustandsänderung stattdessen den Kanal aus dem geteilten
 * {@see ActorContextService} (`staff` per Default, `member` für den
 * Self-Service-Kanal - siehe dortige Klassendoku und
 * PermissionMiddleware::authorizeSelfService()). Das war genau der
 * Erweiterungspunkt, für den #74 diesen Dienst eingeführt hat. Der
 * automatische Verfalls-/Austritts-Cron bleibt fest auf `actor_type: system`
 * verdrahtet (läuft nicht über die Middleware, setzt den Kanal nirgends).
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
		private ActorContextService $actorContext,
		private IUserSession $userSession,
		private IConfig $config,
		private DunningLadderService $dunningLadder,
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
		// "ruecklastschrift" ist seit Issue #73 tatsaechlich auslösbar, aber
		// ausschliesslich automatisch ueber {@see suspendDueToReturnedDebit()} -
		// dort protokolliert die Historie korrekt actor_type "system" und setzt
		// returned_debit_id. Ein Mensch, der ueber diese generische Methode
		// (UI-Aktion "Mandat sperren") den Ursprung "ruecklastschrift" waehlte,
		// wuerde eine Sperre vortaeuschen, die tatsaechlich manuell war.
		if ($origin === Mandate::SUSPENSION_RETURNED_DEBIT) {
			throw new \InvalidArgumentException($this->l10n->t('Der Sperr-Ursprung "Rücklastschrift" wird nur automatisch gesetzt.'));
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
	 * Automatische Sperre nach Rücklastschrift (Spec §3.6, Issue #73) – der
	 * Gegenpart zum manuellen {@see suspend()}: kein Pflicht-Grund-Parameter
	 * vom Aufrufer nötig (der Grund IST die Rücklastschrift-Klasse), kein
	 * `actor_type: staff`, sondern `system`, und `returned_debit_id` wird
	 * gesetzt (Spec §2.2 Mandat-Feldkatalog, bislang ungenutzt seit #66).
	 *
	 * Bewusst idempotent statt werfend: {@see \OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportConfirmationService::finalizeReturn()}
	 * ruft diese Methode aus einer bereits laufenden Buchungstransaktion auf –
	 * ein Mandat, das zum Zeitpunkt der Rücklastschrift längst nicht mehr
	 * `aktiv` ist (z. B. zwischenzeitlich manuell gesperrt oder widerrufen),
	 * bleibt unangetastet liegen, statt die gesamte Verbuchung des
	 * Bankumsatzes an einem für die Rücklastschrift selbst irrelevanten
	 * Mandatszustand scheitern zu lassen.
	 */
	public function suspendDueToReturnedDebit(int $id, int $returnedDebitId, string $note): ?Mandate {
		$mandate = $this->mapper->find($id);
		if ($mandate->getStatus() !== Mandate::STATUS_ACTIVE) {
			return null;
		}

		$mandate->setStatus(Mandate::STATUS_SUSPENDED);
		$mandate->setSuspendedAt($this->now());
		$mandate->setSuspensionOrigin(Mandate::SUSPENSION_RETURNED_DEBIT);
		$mandate->setSuspensionNote($note);
		$mandate->setReturnedDebitId($returnedDebitId);
		$mandate = $this->mapper->update($mandate);

		$this->audit->log('SEPA-Mandat automatisch gesperrt (Rücklastschrift)', 'mandate', $mandate->getId(), [
			'referenz' => $mandate->getMandateReference(),
			'grund' => $note,
		], actor: 'system');
		$this->logEvent($mandate, $this->l10n->t('Mandat automatisch gesperrt: %s', [$note]), MandateEvent::ACTOR_SYSTEM);
		return $mandate;
	}

	/**
	 * Widerruf: terminal, nie reaktivierbar (Spec §2.2). Von zwei Kanälen
	 * aufrufbar - der Admin-Akte (`buchhalter`) UND, seit Issue #75, direkt
	 * vom Self-Service ({@see \OCA\Vereinsbuchhaltung\Service\SelfServiceMandateService::revoke()},
	 * „Widerruf ist ein Recht" laut Spec §3.4) - der tatsächliche Kanal kommt
	 * aus dem {@see ActorContextService}, nicht aus einem Methodenparameter.
	 *
	 * @throws \InvalidArgumentException wenn der Übergang nicht erlaubt ist
	 */
	public function revoke(int $id): Mandate {
		$mandate = $this->mapper->find($id);
		$this->stateMachine->assertCanRevoke($mandate);
		$this->end($mandate, Mandate::END_REASON_REVOKED);

		$this->audit->log('SEPA-Mandat widerrufen', 'mandate', $mandate->getId(), ['referenz' => $mandate->getMandateReference()]);
		$this->logEvent($mandate, $this->l10n->t('Mandat widerrufen'), $this->actorContext->actorType());
		$this->notifyRevocationDunning($mandate);
		return $mandate;
	}

	/**
	 * Mahnstufe 0 „Zahlungsaufforderung" sofort bei Widerruf mit offenen
	 * Forderungen (Spec §3.6, Issue #73) – best effort: ein Mailversand-Fehler
	 * darf den bereits vollzogenen Widerruf nicht rückwirkend als
	 * fehlgeschlagen erscheinen lassen (dasselbe Muster wie
	 * {@see \OCA\Vereinsbuchhaltung\Service\DebitBatchService::storeXmlSafely()}
	 * für die optionale XML-Ablage).
	 */
	private function notifyRevocationDunning(Mandate $mandate): void {
		try {
			$this->dunningLadder->onMandateRevoked($mandate->getMemberId());
		} catch (\Throwable $e) {
			$this->audit->log('Mahnwesen: Zahlungsaufforderung nach Widerruf fehlgeschlagen', 'mandate', $mandate->getId(), ['fehler' => $e->getMessage()]);
		}
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
	 * Compliance-Anhang §8: `AmdmntInd=true` + `OrgnlDbtrAcct=SMNDA`). Seit
	 * Issue #75 auch direkt vom Self-Service aufrufbar (Spec §3.4 „IBAN
	 * ändern (gleicher Kontoinhaber)", kein Sperrfenster nötig) - siehe
	 * {@see \OCA\Vereinsbuchhaltung\Service\SelfServiceMandateService::changeIban()}.
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
			$this->logEvent($mandate, $this->l10n->t('Bankverbindung geändert (Amendment, alte IBAN %s)', [(string)$amendment->getOldIban()]), $this->actorContext->actorType());
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

	// --- Self-Service-Aktionskatalog (Issue #75) --------------------------------
	// Beide Methoden werden ausschließlich von
	// {@see \OCA\Vereinsbuchhaltung\Service\SelfServiceMandateService} aufgerufen,
	// die die member_id/mandate_id serverseitig auflöst (IDOR-Schutz - siehe
	// dortige Klassendoku); hier unten zählt nur noch die reine Zustandslogik.

	/**
	 * Self-Service: Mandat erfassen + elektronisch erteilen in EINEM Schritt
	 * (Spec §3.4 „Mandat erfassen + elektronisch erteilen", Issue #75).
	 * Anders als der E-Mail-Einmal-Link-Weg (#67, gedacht für Mitglieder OHNE
	 * NC-Konto) ist die/der Zustimmende hier bereits über die eigene,
	 * angemeldete Self-Service-Sitzung authentifiziert - ein zusätzlicher
	 * Bestätigungslink wäre ein überflüssiger Umweg. `consentActor` ist
	 * deshalb NICHT die Mailadresse (wie beim Einmal-Link), sondern die
	 * NC-Konto-Uid der Sitzung.
	 *
	 * In einer Transaktion: schlägt die Aktivierung nach erfolgreicher Anlage
	 * fehl, bliebe sonst ein unbestätigter Entwurf zurück, der laut
	 * {@see MandateStateMachine::assertNoLiveMandate()} jeden weiteren Versuch
	 * blockiert - das Mitglied säße ohne erkennbaren Ausweg fest.
	 *
	 * @throws DoesNotExistException wenn es das Mitglied nicht gibt
	 * @throws \InvalidArgumentException bei ungültigen Eingaben oder bereits
	 *                                   bestehendem lebenden Mandat
	 */
	public function grantElectronicSelfService(
		int $memberId,
		string $iban,
		?string $bic,
		?string $accountHolder,
		int $mandateTextVersionId,
		string $consentIp,
		string $consentUserAgent,
		string $consentActor,
	): Mandate {
		return $this->transaction->run(function () use ($memberId, $iban, $bic, $accountHolder, $mandateTextVersionId, $consentIp, $consentUserAgent, $consentActor): Mandate {
			$mandate = $this->createElectronic($memberId, $iban, $bic, $accountHolder);
			return $this->activateElectronic((int)$mandate->getId(), $mandateTextVersionId, $this->now(), $consentIp, $consentUserAgent, $consentActor);
		});
	}

	/**
	 * Kontoinhaberwechsel im Self-Service (Spec §3.4 „Kontoinhaber wechseln
	 * (erzwingt neues Mandat)", Issue #75): fachlich wie {@see replaceMandate()}
	 * (altes Mandat `ended`/`replaced`, ein komplett neues Mandat statt eines
	 * Amendments - der Kontoinhaber, nicht nur die IBAN, wechselt), aber
	 * elektronisch mit sofortiger Selbst-Aktivierung statt Papier-Entwurf, aus
	 * demselben Grund wie {@see grantElectronicSelfService()}: die/der
	 * Zustimmende ist bereits angemeldet.
	 *
	 * @throws DoesNotExistException wenn es das alte Mandat nicht gibt
	 * @throws \InvalidArgumentException wenn der Übergang nicht erlaubt ist
	 *                                   oder der neue Kontoinhaber leer ist
	 */
	public function replaceElectronicSelfService(
		int $oldId,
		string $iban,
		?string $bic,
		string $newAccountHolder,
		int $mandateTextVersionId,
		string $consentIp,
		string $consentUserAgent,
		string $consentActor,
	): Mandate {
		$old = $this->mapper->find($oldId);
		$this->stateMachine->assertCanReplace($old);
		$newAccountHolder = trim($newAccountHolder);
		if ($newAccountHolder === '') {
			throw new \InvalidArgumentException($this->l10n->t('Der Kontoinhaber ist Pflicht.'));
		}

		return $this->transaction->run(function () use ($old, $iban, $bic, $newAccountHolder, $mandateTextVersionId, $consentIp, $consentUserAgent, $consentActor): Mandate {
			$this->end($old, Mandate::END_REASON_REPLACED);

			$new = new Mandate();
			$new->setMemberId($old->getMemberId());
			$new->setMandateReference($this->generateReference());
			$new->setIban($this->requireIban($iban));
			$new->setBic($this->normalizeBic($bic));
			$new->setAccountHolder($newAccountHolder);
			$new->setSignatureType(Mandate::SIGNATURE_ELECTRONIC);
			$new->setStatus(Mandate::STATUS_DRAFT);
			$new->setCreatedAt($this->now());
			$new = $this->mapper->insert($new);

			$this->audit->log('SEPA-Mandat ersetzt (Kontoinhaberwechsel, Self-Service)', 'mandate', $new->getId(), [
				'alte_referenz' => $old->getMandateReference(),
				'neue_referenz' => $new->getMandateReference(),
			]);
			$this->logEvent($old, $this->l10n->t('Mandat ersetzt durch %s (Kontoinhaberwechsel, Self-Service)', [$new->getMandateReference()]), $this->actorContext->actorType());

			// Aktiviert im selben Zug (siehe Klassendoc grantElectronicSelfService())
			// - protokolliert selbst als ACTOR_MEMBER, siehe activateElectronic().
			return $this->activateElectronic((int)$new->getId(), $mandateTextVersionId, $this->now(), $consentIp, $consentUserAgent, $consentActor);
		});
	}

	/** Manuelles Zurücksetzen eines Amendments auf `open` (Spec §2.2) – die automatische Rücklastschrift-Auslösung folgt erst mit #73. */
	public function reopenAmendment(int $amendmentId): MandateAmendment {
		$amendment = $this->amendmentMapper->find($amendmentId);
		$amendment->setStatus(MandateAmendment::STATUS_OPEN);
		return $this->amendmentMapper->update($amendment);
	}

	/**
	 * Gegenstück zu {@see reopenAmendment()} (Issue #71): ein Amendment gilt
	 * erst als `transmitted`, sobald der Einzugsposten, der es in die
	 * pain.008-Datei getragen hat, tatsächlich bei der Bank *eingereicht*
	 * wurde – „steckt in keinem eingereichten Einzugsposten" (Spec §2.2,
	 * `MandateAmendment`-Klassendoc). Vor der Freigabe ist es `open`, bei der
	 * Freigabe selbst entscheidet nur der Snapshot in {@see \OCA\Vereinsbuchhaltung\Db\DebitItem}
	 * über `amendment_indicator`/`original_debtor_account` – der Statuswechsel
	 * hier passiert erst mit {@see \OCA\Vereinsbuchhaltung\Service\DebitBatchService::submit()}.
	 */
	public function markAmendmentTransmitted(int $amendmentId, int $debitItemId): MandateAmendment {
		$amendment = $this->amendmentMapper->find($amendmentId);
		$amendment->setStatus(MandateAmendment::STATUS_TRANSMITTED);
		$amendment->setDebitItemId($debitItemId);
		return $this->amendmentMapper->update($amendment);
	}

	/**
	 * Einreichung markiert (Issue #71, Schritt 2 „Datei ist bei der Bank
	 * eingereicht"): setzt `last_presented_due_date`, Grundlage der
	 * 36-Monats-Verfallsfrist ({@see MandateExpiryCalculator}). Ein Mandat kann
	 * über mehrere, unabhängig fällige Forderungen (z. B. Beitrag + separate
	 * manuelle Forderung) in mehr als einem Lauf zugleich stecken – nur der
	 * *spätere* Termin zählt als „zuletzt vorgelegt", ein früherer Aufruf
	 * (Läufe werden nicht notwendig in Terminreihenfolge eingereicht) darf den
	 * bereits gemerkten späteren Termin nicht wieder zurückdrehen.
	 */
	public function markPresented(int $id, string $dueDate): Mandate {
		$mandate = $this->mapper->find($id);
		if ($mandate->getLastPresentedDueDate() === null || $dueDate > $mandate->getLastPresentedDueDate()) {
			$mandate->setLastPresentedDueDate($dueDate);
			$mandate = $this->mapper->update($mandate);
		}
		return $mandate;
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

	// --- Cron: Austritts-Mandatsende -------------------------------------------

	/**
	 * Automatisches Mandatsende bei Austritt (Spec §2.2 „Widerruf/Austritt":
	 * „Austritt beendet das Mandat automatisch per Cron, erst wenn keine
	 * Forderung mehr offen ist", Issue #70). Der Aufrufer
	 * ({@see \OCA\Vereinsbuchhaltung\BackgroundJob\MandateDepartureJob}) prüft
	 * bereits "Mitglied ausgetreten" und "keine Forderung mehr offen"
	 * ({@see ClaimStateResolver}) – diese Methode prüft nur noch den
	 * Mandatszustand selbst.
	 *
	 * Bewusst nur `aktiv`: ein Entwurf oder eine Sperre eines ausgetretenen
	 * Mitglieds bleibt unangetastet liegen (die Spec beschreibt für diese
	 * Fälle kein automatisches Aufräumen) – wer das braucht, nutzt die
	 * bestehenden manuellen Wege.
	 *
	 * @throws \InvalidArgumentException wenn das Mandat nicht aktiv ist
	 */
	public function endDueToDeparture(int $id): Mandate {
		$mandate = $this->mapper->find($id);
		if ($mandate->getStatus() !== Mandate::STATUS_ACTIVE) {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein aktives Mandat endet automatisch bei Austritt.'));
		}
		$this->end($mandate, Mandate::END_REASON_TERMINATED);

		$this->audit->log('SEPA-Mandat automatisch beendet (Austritt, keine Forderung mehr offen)', 'mandate', $mandate->getId(), [
			'referenz' => $mandate->getMandateReference(),
		], actor: 'system');
		$this->logEvent($mandate, $this->l10n->t('Mandat automatisch beendet (Austritt, keine Forderung mehr offen)'), MandateEvent::ACTOR_SYSTEM);
		return $mandate;
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

	/**
	 * Gemeinsames Anlegen-Protokoll für Papier- UND elektronischen Entwurf
	 * (Spec §2.2 „createDraft()") - die Audit-Aktionsbezeichnung folgt seit
	 * Issue #75 dem tatsächlichen `signature_type` (vorher stand hier
	 * unabhängig vom Weg immer "(Papier)", was einen im Self-Service
	 * angelegten elektronischen Entwurf falsch beschriftet hätte); die
	 * MandateEvent-Nachricht selbst unterschied das schon vorher richtig
	 * über den optionalen `$message`-Parameter (siehe createDraft()).
	 */
	private function logCreated(Mandate $mandate, ?string $message = null): void {
		$electronic = $mandate->getSignatureType() === Mandate::SIGNATURE_ELECTRONIC;
		$this->audit->log($electronic ? 'SEPA-Mandat angelegt (elektronisch)' : 'SEPA-Mandat angelegt (Papier)', 'mandate', $mandate->getId(), [
			'referenz' => $mandate->getMandateReference(),
			'mitgliedId' => $mandate->getMemberId(),
		]);
		$this->logEvent($mandate, $message ?? $this->l10n->t('Mandats-Entwurf angelegt (Papier)'), $this->actorContext->actorType());
	}

	private function logEvent(Mandate $mandate, string $message, string $actorType, ?string $onBehalfNote = null): void {
		$event = new MandateEvent();
		$event->setMandateId((int)$mandate->getId());
		$event->setActorType($actorType);
		// NC-Uid nur bei einer echten Sitzung (staff ODER member) - der
		// anonyme Einmal-Link-Konsens (activateElectronic() über
		// MandateActivationService::consent(), #[PublicPage], keine Session)
		// bleibt korrekt uid-los, weil IUserSession::getUser() dort ohnehin
		// null liefert.
		$event->setActorUid(in_array($actorType, [MandateEvent::ACTOR_STAFF, MandateEvent::ACTOR_MEMBER], true) ? $this->currentUid() : null);
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

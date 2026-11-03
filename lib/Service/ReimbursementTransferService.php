<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Überweisungsdaten einer freigegebenen Auslage samt GiroCode: wer die
 * Erstattung in der Banking-App anweist, scannt den Code oder kopiert die
 * Felder. Gebucht wird erst mit dem Kontoumsatz ({@see ReimbursementMatchingService},
 * docs/reimbursement-design.md §7).
 *
 * Der Verwendungszweck entsteht hier einmal und steht identisch im GiroCode und
 * in der Anzeige: „<Auslagennummer> - <Zweck>“, auf die 140 Zeichen gekürzt,
 * die eine SEPA-Überweisung zulässt.
 */
class ReimbursementTransferService {

	/** Länge des unstrukturierten Verwendungszwecks einer SEPA-Überweisung. */
	public const MAX_REMITTANCE_LENGTH = 140;

	public function __construct(
		private ReimbursementService $service,
		private MemberMapper $members,
		private EpcQrCodeGenerator $qrCode,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return array{
	 *     id: int,
	 *     number: ?string,
	 *     recipient: string,
	 *     iban: string,
	 *     amountCents: int,
	 *     remittance: string,
	 *     qrPng: ?string
	 * } `qrPng` ist ein PNG als Base64 (ohne Data-URI-Präfix); null, wenn sich der Code nicht erzeugen ließ
	 * @throws DoesNotExistException wenn es den Antrag nicht gibt
	 * @throws \InvalidArgumentException wenn der Antrag nicht (mehr) zu überweisen ist
	 */
	public function prepare(int $id): array {
		$reimbursement = $this->service->find($id);
		if ($reimbursement->getStatus() !== Reimbursement::STATUS_APPROVED) {
			throw new \InvalidArgumentException($this->l10n->t('Nur eine freigegebene Auslage lässt sich überweisen.'));
		}
		$iban = trim($reimbursement->getIban());
		if ($iban === '') {
			// Nach der Anonymisierung des Mitglieds ist die Kontoverbindung leer.
			throw new \InvalidArgumentException($this->l10n->t('Zu diesem Antrag ist keine IBAN gespeichert.'));
		}

		$recipient = $this->recipientName($reimbursement);
		$remittance = self::remittance($reimbursement->getId(), $reimbursement->getNumber(), $reimbursement->getPurpose());

		return [
			'id' => $reimbursement->getId(),
			'number' => $reimbursement->getNumber(),
			'recipient' => $recipient,
			'iban' => $iban,
			'amountCents' => $reimbursement->getAmountCents(),
			'remittance' => $remittance,
			'qrPng' => $this->qrPng($reimbursement, $recipient, $iban, $remittance),
		];
	}

	/**
	 * „AUSL-2026-0012 - Material Sommerfest“; Zeilenumbrüche und Mehrfach-
	 * Leerzeichen im Zweck werden zu einem Leerzeichen (ein Umbruch im
	 * GiroCode-Text würde dessen Zeilenformat zerstören). Anträge ohne Nummer
	 * (sollte es nach der Migration nicht geben) fallen auf die Antragsnummer zurück.
	 */
	public static function remittance(int $id, ?string $number, string $purpose): string {
		$purpose = trim((string)preg_replace('/\s+/u', ' ', $purpose));
		$text = ($number ?? 'Auslage Nr. ' . $id) . ($purpose !== '' ? ' - ' . $purpose : '');
		return rtrim(mb_substr($text, 0, self::MAX_REMITTANCE_LENGTH));
	}

	/** Kontoinhaber des Antrags, ohne Angabe der Name des Mitglieds. */
	private function recipientName(Reimbursement $reimbursement): string {
		$holder = trim((string)$reimbursement->getAccountHolder());
		if ($holder !== '') {
			return $holder;
		}
		try {
			return $this->members->find($reimbursement->getMemberId())->displayName();
		} catch (DoesNotExistException) {
			return '';
		}
	}

	private function qrPng(Reimbursement $reimbursement, string $recipient, string $iban, string $remittance): ?string {
		try {
			return base64_encode($this->qrCode->generatePng($recipient, $iban, null, $reimbursement->getAmountCents(), $remittance));
		} catch (\Throwable $e) {
			// Ohne gd oder Bibliothek bleiben die Felder zum Abtippen.
			$this->logger->warning('Auslage {id}: GiroCode konnte nicht erzeugt werden', [
				'app' => Application::APP_ID,
				'id' => $reimbursement->getId(),
				'exception' => $e,
			]);
			return null;
		}
	}
}

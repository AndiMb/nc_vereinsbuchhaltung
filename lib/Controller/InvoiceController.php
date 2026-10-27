<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Exception\ConflictException;
use OCA\Vereinsbuchhaltung\Exception\InvoiceNotIssuableException;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceException;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceFormat;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDunningService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceMailService;
use OCA\Vereinsbuchhaltung\Service\InvoiceMatchingService;
use OCA\Vereinsbuchhaltung\Service\InvoicePaymentService;
use OCA\Vereinsbuchhaltung\Service\InvoiceService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCA\Vereinsbuchhaltung\Service\NumberRangeService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Rechnungen (Epic #168). Lesen alle Rollen, Entwürfe anlegen und ändern ab
 * `buchhalter`, festschreiben, versenden, stornieren und die Nummernkreise
 * ändern nur `verwalter`: eine festgeschriebene Rechnung ist ein
 * Steuerdokument und nicht mehr änderbar.
 */
class InvoiceController extends Controller {

	use BookContext;

	/** Kopffelder, die der Client senden darf. */
	private const FIELDS = [
		'debtorId', 'issueDate', 'deliveryDate', 'periodStart', 'periodEnd', 'dueDate',
		'taxMode', 'exemptionText', 'buyerReference', 'orderReference', 'paymentTerms',
		'noteHead', 'noteFoot', 'lines',
	];

	public function __construct(
		IRequest $request,
		private InvoiceService $service,
		private InvoiceSettings $settings,
		private NumberRangeService $numberRanges,
		private InvoicePaymentService $paymentService,
		private InvoiceMatchingService $matching,
		private InvoiceDocumentService $documents,
		private InvoiceMailService $mail,
		private EInvoiceService $eInvoice,
		private InvoiceDunningService $dunning,
		private BankTransactionMapper $txMapper,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(?string $status = null, ?int $year = null, ?int $debtorId = null): DataResponse {
		return new DataResponse($this->dunning->annotate($this->service->list($status, $year, $debtorId)));
	}

	/** Auswahlwerte und Voreinstellungen für den Editor. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function meta(): DataResponse {
		$all = $this->settings->all();
		return new DataResponse([
			'unitCodes' => InvoiceService::UNIT_CODES,
			'taxModes' => InvoiceSettings::AVAILABLE_TAX_MODES,
			'taxMode' => $this->settings->taxMode(),
			'smallBusinessNotice' => $this->l10n->t(InvoiceSettings::SMALL_BUSINESS_NOTICE),
			'defaultDueDays' => $this->settings->defaultDueDays(),
			'missingIssuerFields' => array_keys($this->settings->missingIssuerFields()),
			'active' => $this->settings->isActive(),
			// Vorgaben für einen neuen Entwurf; der Server übernimmt dieselben Texte beim Anlegen.
			// Steuer bei Regelbesteuerung: vorgeschlagene Sätze je Sphäre und die Umsatzsteuerkonten.
			'rates' => $this->settings->defaultRates(),
			'vatAccounts' => ['1900' => $this->settings->vatAccountId(1900), '700' => $this->settings->vatAccountId(700)],
			'defaults' => [
				'paymentTerms' => (string)$all[InvoiceSettings::SETTING_PAYMENT_TERMS],
				'noteHead' => (string)$all[InvoiceSettings::SETTING_NOTE_HEAD],
				'noteFoot' => (string)$all[InvoiceSettings::SETTING_NOTE_FOOT],
				'exemptionText' => (string)$all[InvoiceSettings::SETTING_EXEMPTION_TEXT],
			],
		]);
	}

	/**
	 * Zuordnungsvorschläge zu Rechnungen für alle unzugeordneten Zahlungseingänge,
	 * als Objekt Umsatz-ID => Vorschläge (stärkster zuerst).
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function suggestions(): DataResponse {
		$txs = $this->txMapper->findFiltered($this->userId(), 'unassigned', 1000, 0);
		return new DataResponse((object)$this->matching->suggestForMany($txs));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function show(int $id): DataResponse {
		try {
			return new DataResponse($this->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function create(): DataResponse {
		try {
			$invoice = $this->service->createDraft($this->payload());
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
		return new DataResponse($this->detail((int)$invoice->getId()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function update(int $id): DataResponse {
		try {
			$this->service->updateDraft($id, $this->payload());
			return new DataResponse($this->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->deleteDraft($id);
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function duplicate(int $id): DataResponse {
		try {
			$copy = $this->service->duplicate($id);
			return new DataResponse($this->detail((int)$copy->getId()), Http::STATUS_CREATED);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** Festschreiben: vergibt die Nummer und legt den offenen Posten an. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function issue(int $id, ?string $issueDate = null): DataResponse {
		try {
			$this->service->issue($id, $issueDate);
			$pdfError = null;
			try {
				$this->documents->ensurePdf($id);
			} catch (\Throwable $e) {
				// Das Festschreiben ist gelungen und bleibt es; das PDF lässt sich
				// über den Download jederzeit nachholen.
				$pdfError = $e->getMessage();
			}
			// Die E-Rechnung ist wie das PDF ein Zusatz: scheitert sie, bleibt die Rechnung
			// festgeschrieben, und sie lässt sich nachholen.
			try {
				$this->eInvoice->generateSuggested($id);
			} catch (\Throwable) {
			}
			return new DataResponse($this->detail($id) + ['pdfError' => $pdfError]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (ConflictException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (InvoiceNotIssuableException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'problems' => $e->getProblems()], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** Storniert eine festgeschriebene Rechnung mit einer Stornorechnung. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function cancel(int $id, string $reason = ''): DataResponse {
		try {
			$credit = $this->service->cancel($id, $reason);
			return new DataResponse([
				'invoice' => $this->detail($id),
				'credit' => $this->detail((int)$credit->getId()),
			]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** Ordnet einen Zahlungseingang der Rechnung zu und bucht ihn. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function pay(int $id, int $bankTxId): DataResponse {
		try {
			$tx = $this->txMapper->find($bankTxId, $this->userId());
			$this->paymentService->payFromBankTransaction($tx, $id);
			return new DataResponse($this->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** Barzahlung: bucht Geldkonto an Erlöskonten, ohne Bankumsatz. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function payManually(int $id, string $date, int $accountId, ?int $amountCents = null): DataResponse {
		try {
			$this->paymentService->payManually($id, $date, $accountId, $amountCents);
			return new DataResponse($this->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** Nimmt eine Zahlung zurück (löst die Zuordnung bzw. löscht die Barbuchung). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function revertPayment(int $id, int $paymentId): DataResponse {
		try {
			$this->paymentService->revertPayment($id, $paymentId);
			return new DataResponse($this->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/**
	 * PDF der Rechnung: das gespeicherte bei einer festgeschriebenen, die
	 * Vorschau mit Wasserzeichen bei einem Entwurf.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function pdf(int $id, bool $download = false): DataDownloadResponse|DataResponse {
		try {
			$pdf = $this->documents->pdfContent($id);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return $this->file($pdf['content'], $pdf['fileName'], 'application/pdf', $download);
	}

	/**
	 * Erzeugt die E-Rechnung im gewünschten Format (zugferd, xrechnung_cii,
	 * xrechnung_ubl); ein vorhandenes Dokument bleibt unverändert. Fehlen Angaben,
	 * antwortet der Server mit der Liste der Lücken.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function eInvoice(int $id, string $format): DataResponse {
		try {
			$this->eInvoice->generate($id, $format);
			return new DataResponse($this->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (EInvoiceException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'problems' => $e->getProblems()], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Ein gespeichertes Dokument der Rechnung (PDF oder XML der E-Rechnung).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function document(int $id, int $documentId): DataDownloadResponse|DataResponse {
		try {
			$doc = $this->documents->documentContent($id, $documentId);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
		$type = str_starts_with($doc['kind'], 'xrechnung') ? 'application/xml' : 'application/pdf';
		return $this->file($doc['content'], $doc['fileName'], $type, true);
	}

	/** Vorgaben für den Versanddialog: Empfängeradresse, Betreff und Text. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function mailDefaults(int $id): DataResponse {
		try {
			return new DataResponse($this->mail->defaults($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	/** Verschickt die Rechnung per E-Mail mit dem PDF im Anhang. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function send(int $id, string $to, ?string $subject = null, ?string $body = null, array $documentIds = [], bool $copyBoard = false): DataResponse {
		try {
			$this->mail->send($id, $to, $subject, $body, array_values(array_map('intval', $documentIds)), $copyBoard);
			return new DataResponse($this->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/** Vorgaben für den Mahndialog: Stufe, Empfängeradresse, Betreff und Text. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function dunningDefaults(int $id, ?int $level = null): DataResponse {
		try {
			return new DataResponse($this->dunning->defaults($id, $level));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** Mahnt die Rechnung: Mahnschreiben als PDF mit GiroCode, per E-Mail. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function dun(int $id, string $to, ?string $subject = null, ?string $body = null, ?int $level = null, ?bool $copyBoard = null): DataResponse {
		try {
			$this->dunning->send($id, $to, $subject, $body, $level, false, null, $copyBoard);
			return new DataResponse($this->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function numberRanges(): DataResponse {
		return new DataResponse($this->numberRanges->all());
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function updateNumberRange(string $kind, string $prefixPattern, string $resetMode, ?int $nextNo = null): DataResponse {
		try {
			return new DataResponse($this->numberRanges->update($kind, $prefixPattern, $resetMode, $nextNo));
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/**
	 * Detail der Rechnung mit dem Prüfstand ihrer Dokumente.
	 *
	 * @return array<string, mixed>
	 * @throws DoesNotExistException
	 */
	private function detail(int $id): array {
		$detail = $this->service->detail($id);
		$detail['documents'] = $this->documents->listWithIntegrity($id);
		$detail['dunning'] = $this->dunning->forDetail($detail);
		if ($detail['status'] !== Invoice::STATUS_DRAFT) {
			$detail['eInvoice'] = [
				'suggested' => EInvoiceFormat::suggestFor($detail['debtor']['kind'] ?? null),
				'missing' => $this->eInvoice->missing($id),
			];
		}
		return $detail;
	}

	/** @return array<string, mixed> */
	private function payload(): array {
		return array_intersect_key($this->request->getParams(), array_flip(self::FIELDS));
	}

	/**
	 * Liefert eine Datei inline oder als Download aus. PDFs können aktiven
	 * Inhalt enthalten, deshalb darf die Antwort im eigenen Ursprung nichts
	 * ausführen (wie AttachmentController::inline()).
	 */
	private function file(string $content, string $fileName, string $contentType, bool $download): DataDownloadResponse {
		$safe = str_replace(['"', "\r", "\n", '\\'], '_', $fileName);
		$response = new DataDownloadResponse($content, $safe, $contentType);
		$response->addHeader('Content-Disposition', ($download ? 'attachment' : 'inline') . '; filename="' . $safe . '"');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->setContentSecurityPolicy(new EmptyContentSecurityPolicy());
		return $response;
	}

	private function bad(\InvalidArgumentException $e): DataResponse {
		return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
	}

	private function notFound(): DataResponse {
		return new DataResponse(['message' => $this->l10n->t('Rechnung nicht gefunden')], Http::STATUS_NOT_FOUND);
	}
}

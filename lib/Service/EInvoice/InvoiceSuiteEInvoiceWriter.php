<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\EInvoice;

use horstoeko\invoicesuite\documents\dto\InvoiceSuiteAddressDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteAllowanceChargeDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteCommunicationDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteContactDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteDateRangeDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteDocumentHeaderDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteDocumentPositionDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteIdDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteNoteDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteOrganisationDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuitePartyDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuitePaymentMeanDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuitePaymentTermDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuitePriceGrossDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuitePriceNetDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteProductDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteQuantityDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteReferenceDocumentDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteReferenceDocumentExtDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteSummationDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuitesummationLineDTO;
use horstoeko\invoicesuite\documents\dto\InvoiceSuiteTaxDTO;
use horstoeko\invoicesuite\InvoiceSuiteDocumentBuilder;
use horstoeko\invoicesuite\InvoiceSuitePdfDocumentBuilder;
use horstoeko\invoicesuite\InvoiceSuitePdfDocumentReader;
use horstoeko\invoicesuite\InvoiceSuiteSettings;
use horstoeko\invoicesuite\validators\InvoiceSuiteXsdDocumentValidator;
use OCA\Vereinsbuchhaltung\Db\InvoiceLine;
use OCA\Vereinsbuchhaltung\Service\InvoiceCalculator;
use OCP\IL10N;
use OCP\ITempManager;

/**
 * {@see EInvoiceWriter} mit horstoeko/invoicesuite (MIT).
 *
 * Die Rechnung wird einmal als neutrales Datenmodell (DTO) aufgebaut und von
 * der Bibliothek in das gewählte Format übersetzt (CII für ZUGFeRD und
 * XRechnung, UBL für die XRechnung als UBL). Eine Stornorechnung geht als
 * Dokumenttyp 381 hinaus; EN 16931 kennt dort nur positive Beträge, die App
 * speichert sie negativ und dreht das Vorzeichen hier um.
 *
 * Steuer: Kleinunternehmer und steuerbefreite Rechnungen laufen als Kategorie
 * E mit Satz 0 und dem Befreiungsgrund (BT-120); bei Regelbesteuerung folgt die
 * Aufschlüsselung (BG-23) der Berechnung in {@see InvoiceCalculator}, also
 * derselben, die das PDF druckt.
 *
 * Die mPDF-Abhängigkeit der Bibliothek (GPL-2.0-only) wird nicht installiert
 * (siehe composer.json, `replace`); sie dient nur dem Visualizer, den wir
 * nicht nutzen.
 */
class InvoiceSuiteEInvoiceWriter implements EInvoiceWriter {

	private const PROVIDERS = [
		EInvoiceFormat::ZUGFERD => 'zffxcomfort',
		EInvoiceFormat::XRECHNUNG_CII => 'xrechnungcii',
	];

	public function __construct(
		private ?IL10N $l10n = null,
		private ?ITempManager $tempManager = null,
	) {
	}

	/**
	 * Nextcloud sperrt beim Start jedes Laden externer Entitäten durch libxml
	 * (lib/base.php). Die Schemas der E-Rechnung bestehen aber aus mehreren
	 * Dateien, die einander einbinden, und auch die Metadaten für das PDF liegen
	 * als XML bei der Bibliothek. Für die Dauer eines Aufrufs erlaubt ein eigener
	 * Lader deshalb genau die Dateien unter dem Verzeichnis der Bibliothek und
	 * sonst nichts. Danach gilt in Nextcloud wieder die Sperre.
	 *
	 * @template T
	 * @param callable(): T $run
	 * @return T
	 */
	private function withSchemaLoader(callable $run): mixed {
		$root = realpath(dirname((string)(new \ReflectionClass(InvoiceSuiteXsdDocumentValidator::class))->getFileName(), 2));
		libxml_set_external_entity_loader(static function (?string $public, string $system) use ($root): ?string {
			$path = realpath(str_starts_with($system, 'file://') ? substr($system, 7) : $system);
			if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
				return null;
			}
			return $path;
		});
		try {
			return $run();
		} finally {
			// Außerhalb von Nextcloud (Tests, Kommandozeile) gilt wieder der Standardlader.
			libxml_set_external_entity_loader(class_exists(\OC::class, false) ? static fn (): ?string => null : null);
		}
	}

	/**
	 * Die Bibliothek legt Zwischenspeicher für die Serialisierung standardmäßig im
	 * eigenen Verzeichnis unter vendor/ an. Das ist in einer Nextcloud meist nicht
	 * beschreibbar (Apps gehören dem Installationsnutzer); deshalb liegt er im
	 * temporären Verzeichnis von Nextcloud. Der Zwischenspeicher darf jederzeit
	 * verschwinden, er wird dann neu aufgebaut.
	 */
	private function useWritableCache(): void {
		// Die Bibliothek sucht ihre Format-Anbieter sonst in allen Klassen aller
		// Composer-Autoloader – in einer Nextcloud also auch in deren eigenen
		// Klassen, von denen manche beim Laden abstürzen (fehlende Symfony-
		// Traits, ein nicht abfangbarer Fatal Error). Die Anbieter liegen alle
		// im eigenen Namensraum der Bibliothek.
		InvoiceSuiteSettings::setDiscoveryNamespaces(['horstoeko\\invoicesuite']);
		$base = $this->tempManager !== null ? $this->tempManager->getTempBaseDir() : sys_get_temp_dir();
		$dir = rtrim($base, '/') . '/vbh-invoicesuite-' . substr(hash('sha256', __DIR__), 0, 12);
		if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
			return;
		}
		InvoiceSuiteSettings::setSerializerCacheDirectory($dir);
	}

	public function buildXml(EInvoiceData $data, string $format): string {
		$this->assertFormat($format);
		$this->useWritableCache();
		$problems = $this->missing($data, $format);
		if ($problems !== []) {
			throw new EInvoiceException($this->msg('Für dieses Format fehlen noch Angaben.'), $problems);
		}
		try {
			return $this->withSchemaLoader(function () use ($data, $format): string {
				$builder = InvoiceSuiteDocumentBuilder::createByProviderUniqueId($this->provider($data, $format));
				$builder->createFromDTO($this->header($data, $format));
				return $builder->getContent();
			});
		} catch (EInvoiceException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new EInvoiceException($this->msg('Die E-Rechnung konnte nicht erzeugt werden: %s', [$e->getMessage()]), [], $e);
		}
	}

	public function embedIntoPdf(string $xml, string $pdf, string $format): string {
		if (!EInvoiceFormat::isHybridPdf($format) && $format !== EInvoiceFormat::XRECHNUNG_CII) {
			throw new EInvoiceException($this->msg('Dieses Format lässt sich nicht in ein PDF einbetten.'));
		}
		$this->useWritableCache();
		try {
			return $this->withSchemaLoader(static fn (): string => InvoiceSuitePdfDocumentBuilder::createFromDocumentContentAndPdfContent($xml, $pdf)
				->setAdditionalCreatorTool('Vereinsbuchhaltung für Nextcloud')
				->setDocumentRelationshipTypeToAlternative()
				->generatePdfDocumentAndGetContent());
		} catch (\Throwable $e) {
			throw new EInvoiceException($this->msg('Das XML konnte nicht in das PDF eingebettet werden: %s', [$e->getMessage()]), [], $e);
		}
	}

	public function validate(string $xml, string $format): array {
		$this->assertFormat($format);
		$this->useWritableCache();
		try {
			$validator = $this->withSchemaLoader(static fn () => InvoiceSuiteXsdDocumentValidator::createFromContent($xml)->validate());
		} catch (\Throwable $e) {
			return [$this->msg('Die Schemaprüfung konnte nicht laufen: %s', [$e->getMessage()])];
		}
		$messages = [];
		foreach (array_merge($validator->getErrorMessagesInMessageBag(), $validator->getInternalErrorMessagesInMessageBag()) as $item) {
			$messages[] = $item->getMessageContent();
		}
		return $messages;
	}

	public function extractXml(string $pdf): string {
		$this->useWritableCache();
		try {
			$attachment = $this->withSchemaLoader(static fn () => InvoiceSuitePdfDocumentReader::createFromContent($pdf)->getInvoiceDocumentAttachment());
		} catch (\Throwable $e) {
			throw new EInvoiceException($this->msg('Das PDF konnte nicht gelesen werden: %s', [$e->getMessage()]), [], $e);
		}
		if ($attachment === null) {
			throw new EInvoiceException($this->msg('Im PDF ist kein XML eingebettet.'));
		}
		return $attachment->getAttachmentContent();
	}

	// ------------------------------------------------------------------

	/**
	 * Pflichtangaben, die das Format über die der Rechnung hinaus verlangt.
	 *
	 * @return list<string>
	 */
	public function missing(EInvoiceData $data, string $format): array {
		$problems = [];
		$issuer = $data->issuer;
		if (($issuer['vatId'] ?? '') === '' && ($issuer['taxNumber'] ?? '') === '') {
			$problems[] = $this->msg('Steuernummer oder USt-IdNr. des Vereins');
		}
		if (EInvoiceFormat::isXRechnung($format)) {
			// BR-DE-15: Käuferreferenz (bei Behörden die Leitweg-ID).
			if ($this->buyerReference($data) === '') {
				$problems[] = $this->msg('Käuferreferenz oder Leitweg-ID des Empfängers');
			}
			// BR-DE-2, BR-DE-5 bis BR-DE-7: Ansprechpartner des Verkäufers mit Telefon und E-Mail.
			if (($issuer['phone'] ?? '') === '') {
				$problems[] = $this->msg('Telefonnummer des Vereins (Einstellungen)');
			}
			if (($issuer['email'] ?? '') === '') {
				$problems[] = $this->msg('E-Mail-Adresse des Vereins (Einstellungen)');
			}
			// BR-DE-1: Zahlungsangaben.
			if ($data->bank === null || $data->bank['iban'] === '') {
				$problems[] = $this->msg('Bankverbindung für die Überweisung (Konto für Zahlungsangaben)');
			}
			if (($data->debtor['email'] ?? '') === '') {
				$problems[] = $this->msg('E-Mail-Adresse des Empfängers (elektronische Adresse)');
			}
		}
		if (($data->debtor['street'] ?? '') === '' || ($data->debtor['city'] ?? '') === '' || ($data->debtor['postalCode'] ?? '') === '') {
			$problems[] = $this->msg('Vollständige Anschrift des Empfängers');
		}
		return $problems;
	}

	private function provider(EInvoiceData $data, string $format): string {
		if ($format === EInvoiceFormat::XRECHNUNG_UBL) {
			return $data->invoice->isCredit() ? 'xrechnungublcreditnote' : 'xrechnungublinvoice';
		}
		return self::PROVIDERS[$format];
	}

	private function header(EInvoiceData $data, string $format): InvoiceSuiteDocumentHeaderDTO {
		$invoice = $data->invoice;
		$sign = $invoice->isCredit() ? -1 : 1;
		$issueDate = $this->date((string)$invoice->getIssueDate());

		$header = (new InvoiceSuiteDocumentHeaderDTO())
			->setNumber((string)$invoice->getNumber())
			->setType($invoice->isCredit() ? '381' : '380')
			->setDate($issueDate)
			->setCurrency($invoice->getCurrency());

		foreach ([$invoice->getNoteHead(), $invoice->getNoteFoot()] as $note) {
			if ($note !== null && trim($note) !== '') {
				$header->addNote(new InvoiceSuiteNoteDTO(trim($note)));
			}
		}
		if ($invoice->getDeliveryDate() !== null) {
			$header->addSupplyChainEvent($this->date($invoice->getDeliveryDate()));
		}
		if ($invoice->getPeriodStart() !== null && $invoice->getPeriodEnd() !== null) {
			$header->addBillingPeriod((new InvoiceSuiteDateRangeDTO())
				->setStartDate($this->date($invoice->getPeriodStart()))
				->setEndDate($this->date($invoice->getPeriodEnd())));
		}
		$buyerReference = $this->buyerReference($data);
		if ($buyerReference !== '') {
			$header->addBuyerReference((new InvoiceSuiteIdDTO())->setId($buyerReference));
		}
		if ($invoice->getOrderReference() !== null && trim($invoice->getOrderReference()) !== '') {
			$header->addBuyerOrderReference((new InvoiceSuiteReferenceDocumentDTO())->setReferenceNumber(trim($invoice->getOrderReference())));
		}
		if ($invoice->isCredit() && $data->precedingNumber !== null) {
			$reference = (new InvoiceSuiteReferenceDocumentExtDTO())->setReferenceNumber($data->precedingNumber);
			if ($data->precedingDate !== null) {
				$reference->setReferenceDate($this->date($data->precedingDate));
			}
			$header->addInvoiceReference($reference);
		}

		$header->setSellerParty($this->sellerParty($data));
		$header->setBuyerParty($this->buyerParty($data));

		if ($data->bank !== null && $data->bank['iban'] !== '') {
			$header->addPaymentMean((new InvoiceSuitePaymentMeanDTO())
				->setTypeCode('58')
				->setPayeeIban(str_replace(' ', '', $data->bank['iban']))
				->setPayeeBic($data->bank['bic'] !== '' ? $data->bank['bic'] : null)
				->setPayeeAccountName($data->bank['holder']));
			$header->addPaymentReference((new InvoiceSuiteIdDTO())->setId((string)$invoice->getNumber()));
		}
		$term = (new InvoiceSuitePaymentTermDTO());
		$termText = trim((string)$invoice->getPaymentTerms());
		if ($termText !== '') {
			$term->setDescription($termText);
		}
		if (!$invoice->isCredit() && $invoice->getDueDate() !== null) {
			$term->setDueDate($this->date($invoice->getDueDate()));
		}
		if ($termText !== '' || (!$invoice->isCredit() && $invoice->getDueDate() !== null)) {
			$header->addPaymentTerm($term);
		}

		// Aufschlüsselung der Steuer und Summen: dieselbe Rechnung wie im PDF.
		$totals = InvoiceCalculator::totals(array_map(static fn (InvoiceLine $l): array => [
			'lineNetCents' => $l->getLineNetCents(),
			'taxRateBp' => $l->getTaxRateBp(),
			'taxCategory' => $l->getTaxCategory(),
		], $data->lines), $invoice->getTaxMode());
		foreach ($totals['taxBreakdown'] as $group) {
			$tax = (new InvoiceSuiteTaxDTO())
				->setCategory($group['category'])
				->setType('VAT')
				->setBasisAmount($this->money($group['baseCents'] * $sign))
				->setAmount($this->money($group['taxCents'] * $sign))
				->setPercent($group['rateBp'] / 100);
			if ($group['category'] === InvoiceLine::TAX_EXEMPT) {
				$reason = trim((string)$invoice->getExemptionText());
				if ($reason !== '') {
					$tax->setExemptionReason($reason);
				}
			}
			$header->addTax($tax);
		}
		$header->addSummation((new InvoiceSuiteSummationDTO())
			->setNetAmount($this->money($totals['netCents'] * $sign))
			->setChargeTotalAmount(0.0)
			->setDiscountTotalAmount(0.0)
			->setTaxBasisAmount($this->money($totals['netCents'] * $sign))
			->setTaxTotalAmount($this->money($totals['taxCents'] * $sign))
			->setGrossAmount($this->money($totals['grossCents'] * $sign))
			->setPrepaidAmount(0.0)
			->setDueAmount($this->money($totals['grossCents'] * $sign)));

		foreach ($data->lines as $line) {
			$header->addPosition($this->position($line, $sign));
		}
		return $header;
	}

	private function position(InvoiceLine $line, int $sign): InvoiceSuiteDocumentPositionDTO {
		$unitPrice = $line->getUnitPriceCents() / 100;
		$quantity = abs($line->getQuantityE4()) / 10000;
		// Bei negativem Preis (Abzugsposition) kehrt sich das Vorzeichen mit der Menge um.
		$net = $line->getLineNetCents() * $sign;
		$description = $line->getDescription();

		$product = (new InvoiceSuiteProductDTO())->setName($line->getName());
		if ($description !== null && trim($description) !== '') {
			$product->setDescription(trim($description));
		}
		$position = (new InvoiceSuiteDocumentPositionDTO())
			->setLineId((string)$line->getPosition())
			->setProduct($product)
			->setGrossPrice(new InvoiceSuitePriceGrossDTO(amount: $unitPrice))
			->setNetPrice(new InvoiceSuitePriceNetDTO(amount: $unitPrice))
			->setQuantityBilled((new InvoiceSuiteQuantityDTO())->setQuantity($quantity)->setQuantityUnit($line->getUnitCode()))
			->addTax((new InvoiceSuiteTaxDTO())
				->setCategory($line->getTaxCategory())
				->setType('VAT')
				->setPercent($line->getTaxRateBp() / 100))
			->setSummation((new InvoiceSuitesummationLineDTO())->setNetAmount($this->money($net)));
		if ($line->getDiscountBp() > 0) {
			// Rabatt auf die Zeile (BG-27): Menge × Preis minus Rabatt ergibt den Zeilenbetrag.
			$base = InvoiceCalculator::roundDiv(abs($line->getQuantityE4()) * $line->getUnitPriceCents(), 10000);
			$discount = $base - abs($line->getLineNetCents());
			$position->addAllowanceCharge((new InvoiceSuiteAllowanceChargeDTO())
				->setChargeIndicator(false)
				->setAmount($this->money($discount))
				->setBaseAmount($this->money($base))
				->setPercent($line->getDiscountBp() / 100)
				->setReason($this->msg('Rabatt')));
		}
		return $position;
	}

	private function sellerParty(EInvoiceData $data): InvoiceSuitePartyDTO {
		$issuer = $data->issuer;
		$party = (new InvoiceSuitePartyDTO())
			->addName((string)($issuer['name'] ?? ''))
			->addLegalOrganisation((new InvoiceSuiteOrganisationDTO())->setName((string)($issuer['name'] ?? '')))
			->addAddress($this->address((string)($issuer['street'] ?? ''), (string)($issuer['postalCode'] ?? ''), (string)($issuer['city'] ?? ''), (string)($issuer['country'] ?? 'DE')));
		// BR-CO-26: Kennung des Verkäufers (BT-29), Registrierungskennung (BT-30) oder USt-IdNr.
		// (BT-31) muss da sein. Ein Verein hat oft keine USt-IdNr.: dann dient die Nummer im
		// Vereinsregister, ersatzweise die Steuernummer als Kennung.
		$sellerId = trim((string)($issuer['registerNo'] ?? ''));
		if ($sellerId === '') {
			$sellerId = trim((string)($issuer['taxNumber'] ?? ''));
		}
		if ($sellerId !== '') {
			$party->addId((new InvoiceSuiteIdDTO())->setId($sellerId));
		}
		if (($issuer['vatId'] ?? '') !== '') {
			$party->addTaxRegistration((new InvoiceSuiteIdDTO())->setId((string)$issuer['vatId'])->setIdType('VA'));
		}
		if (($issuer['taxNumber'] ?? '') !== '') {
			$party->addTaxRegistration((new InvoiceSuiteIdDTO())->setId((string)$issuer['taxNumber'])->setIdType('FC'));
		}
		$contactName = trim(explode(',', (string)($issuer['representatives'] ?? ''))[0]);
		$contact = (new InvoiceSuiteContactDTO())->setPersonName($contactName !== '' ? $contactName : (string)($issuer['name'] ?? ''));
		if (($issuer['phone'] ?? '') !== '') {
			$contact->setPhoneNumber((string)$issuer['phone']);
		}
		if (($issuer['email'] ?? '') !== '') {
			$contact->setEmailAddress((string)$issuer['email']);
			$party->addCommunication((new InvoiceSuiteCommunicationDTO())->setId((string)$issuer['email'])->setIdType('EM'));
		}
		return $party->addContact($contact);
	}

	private function buyerParty(EInvoiceData $data): InvoiceSuitePartyDTO {
		$debtor = $data->debtor;
		$party = (new InvoiceSuitePartyDTO())
			->addName((string)($debtor['name'] ?? ''))
			->addAddress($this->address((string)($debtor['street'] ?? ''), (string)($debtor['postalCode'] ?? ''), (string)($debtor['city'] ?? ''), (string)($debtor['country'] ?? 'DE')));
		if (($debtor['customerNo'] ?? '') !== '') {
			$party->addId((new InvoiceSuiteIdDTO())->setId((string)$debtor['customerNo']));
		}
		if (($debtor['vatId'] ?? '') !== '') {
			$party->addTaxRegistration((new InvoiceSuiteIdDTO())->setId((string)$debtor['vatId'])->setIdType('VA'));
		}
		if (($debtor['email'] ?? '') !== '') {
			$party->addCommunication((new InvoiceSuiteCommunicationDTO())->setId((string)$debtor['email'])->setIdType('EM'));
		}
		return $party;
	}

	private function address(string $street, string $postcode, string $city, string $country): InvoiceSuiteAddressDTO {
		return (new InvoiceSuiteAddressDTO())
			->setAddressLine1($street !== '' ? $street : null)
			->setPostcode($postcode !== '' ? $postcode : null)
			->setCity($city !== '' ? $city : null)
			->setCountry($country !== '' ? strtoupper($country) : 'DE');
	}

	/** Käuferreferenz (BT-10): die ausdrücklich gesetzte, sonst die Leitweg-ID des Empfängers. */
	private function buyerReference(EInvoiceData $data): string {
		$reference = trim((string)$data->invoice->getBuyerReference());
		if ($reference !== '') {
			return $reference;
		}
		return trim((string)($data->debtor['leitwegId'] ?? ''));
	}

	private function date(string $iso): \DateTime {
		$date = \DateTime::createFromFormat('!Y-m-d', substr($iso, 0, 10));
		if ($date === false) {
			throw new EInvoiceException($this->msg('Ungültiges Datum: %s', [$iso]));
		}
		return $date;
	}

	private function money(int $cents): float {
		return round($cents / 100, 2);
	}

	private function assertFormat(string $format): void {
		if (!EInvoiceFormat::isValid($format)) {
			throw new EInvoiceException($this->msg('Unbekanntes Format der E-Rechnung.'));
		}
	}

	/** @param list<int|string> $params */
	private function msg(string $text, array $params = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $params) : vsprintf($text, $params);
	}
}

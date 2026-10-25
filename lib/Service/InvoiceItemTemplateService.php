<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceItemTemplate;
use OCA\Vereinsbuchhaltung\Db\InvoiceItemTemplateMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceLine;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Positionsvorlagen für Rechnungen (Epic #168, Stufe 3): wiederkehrende
 * Leistungen wie „Hallenmiete" oder „Kursgebühr" mit Einheit, Preis,
 * Steuer und Erlöskonto. Der Editor übernimmt eine Vorlage als Kopie in
 * eine Position; spätere Änderungen an der Vorlage berühren bestehende
 * Rechnungen und Entwürfe nicht.
 */
class InvoiceItemTemplateService {

	public function __construct(
		private InvoiceItemTemplateMapper $mapper,
		private AccountMapper $accounts,
		private IUserSession $userSession,
		private ?IL10N $l10n = null,
	) {
	}

	/** @return InvoiceItemTemplate[] */
	public function list(): array {
		return $this->mapper->findAll();
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws \InvalidArgumentException
	 */
	public function create(array $data): InvoiceItemTemplate {
		$template = new InvoiceItemTemplate();
		$template->setCreatedAt((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
		$template->setCreatedBy($this->userSession->getUser()?->getUID() ?? 'system');
		$this->apply($template, $data);
		return $this->mapper->insert($template);
	}

	/**
	 * @param array<string, mixed> $data nur die gesendeten Felder werden geändert
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException
	 */
	public function update(int $id, array $data): InvoiceItemTemplate {
		$template = $this->mapper->find($id);
		$this->apply($template, $data);
		return $this->mapper->update($template);
	}

	/** @throws DoesNotExistException */
	public function delete(int $id): void {
		$this->mapper->delete($this->mapper->find($id));
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws \InvalidArgumentException
	 */
	private function apply(InvoiceItemTemplate $template, array $data): void {
		if (array_key_exists('name', $data) || $template->getId() === null) {
			$name = mb_substr(trim((string)($data['name'] ?? '')), 0, 255);
			if ($name === '') {
				throw new \InvalidArgumentException($this->msg('Bitte eine Bezeichnung angeben.'));
			}
			$template->setName($name);
		}
		if (array_key_exists('description', $data)) {
			$description = mb_substr(trim((string)$data['description']), 0, 2000);
			$template->setDescription($description === '' ? null : $description);
		}
		if (array_key_exists('unitCode', $data)) {
			$unit = (string)$data['unitCode'];
			if (!in_array($unit, InvoiceService::UNIT_CODES, true)) {
				throw new \InvalidArgumentException($this->msg('Unbekannte Einheit.'));
			}
			$template->setUnitCode($unit);
		}
		if (array_key_exists('unitPriceCents', $data)) {
			$price = (int)$data['unitPriceCents'];
			if ($price < 0 || $price > InvoiceCalculator::MAX_UNIT_PRICE_CENTS) {
				throw new \InvalidArgumentException($this->msg('Der Einzelpreis liegt außerhalb des erlaubten Bereichs.'));
			}
			$template->setUnitPriceCents($price);
		}
		if (array_key_exists('taxCategory', $data) || array_key_exists('taxRateBp', $data)) {
			$category = strtoupper(trim((string)($data['taxCategory'] ?? $template->getTaxCategory())));
			if (!in_array($category, [InvoiceLine::TAX_STANDARD, InvoiceLine::TAX_EXEMPT, InvoiceLine::TAX_ZERO], true)) {
				throw new \InvalidArgumentException($this->msg('Diese Steuerkategorie ist nicht möglich.'));
			}
			$rate = $category === InvoiceLine::TAX_STANDARD ? (int)($data['taxRateBp'] ?? $template->getTaxRateBp()) : 0;
			if ($category === InvoiceLine::TAX_STANDARD && ($rate < 1 || $rate > 2500)) {
				throw new \InvalidArgumentException($this->msg('Der Steuersatz muss zwischen 0,01 und 25 Prozent liegen.'));
			}
			$template->setTaxCategory($category);
			$template->setTaxRateBp($rate);
		}
		if (array_key_exists('accountId', $data)) {
			$accountId = $data['accountId'] !== null && $data['accountId'] !== '' ? (int)$data['accountId'] : null;
			if ($accountId !== null) {
				try {
					$account = $this->accounts->find($accountId, Application::BOOK);
				} catch (DoesNotExistException) {
					throw new \InvalidArgumentException($this->msg('Das Erlöskonto existiert nicht.'));
				}
				if ($account->getType() !== 'income' || $account->getIsBank() || !$account->getActive()) {
					throw new \InvalidArgumentException($this->msg('Als Erlöskonto ist nur ein aktives Einnahmenkonto möglich.'));
				}
			}
			$template->setAccountId($accountId);
		}
	}

	/** @param list<int|string> $params */
	private function msg(string $text, array $params = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $params) : vsprintf($text, $params);
	}
}

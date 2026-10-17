<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCP\IL10N;

/**
 * Ordnet ein Konto seiner Kostenstelle („Auswertungsgruppe") zu – in allen
 * drei Modi von {@see ReportService::costCenterMode()}.
 *
 * Bis zum Gliederungsbericht stand diese Zuordnung mitten in der Schleife von
 * ReportService::costCenterReport(). Seit ein zweiter Bericht nach
 * Kostenstellen gruppiert, gibt es sie nur noch hier, damit beide Berichte
 * dieselben Gruppen bilden.
 */
final class CostCenterResolver {

	/**
	 * `$names` sind die Namen je Kostenstellen-Code – PHP macht aus numerischen
	 * Codes wie „51“ int-Schlüssel. `$defined` sind die angelegten
	 * Kostenstellen je ID (Modus 'manual').
	 *
	 * @param string $mode 'group', 'account' oder 'manual'
	 * @param array<array-key, string> $names
	 * @param array<int, array{code:string, name:string}> $defined
	 */
	public function __construct(
		private string $mode,
		private array $names,
		private array $defined,
		private IL10N $l10n,
	) {
	}

	public function mode(): string {
		return $this->mode;
	}

	/**
	 * Gruppe eines Kontos. `key` ist innerhalb eines Modus eindeutig; ''
	 * steht für „ohne Kostenstelle".
	 *
	 * @return array{key:string, code:?string, name:string}
	 */
	public function resolve(Account $account): array {
		if ($this->mode === 'account') {
			return [
				'key' => 'acc-' . $account->getId(),
				'code' => $account->getNumber(),
				'name' => mb_substr($account->getName(), 0, 255),
			];
		}
		if ($this->mode === 'manual') {
			$id = $account->getCostCenterId();
			$key = ReportService::manualGroupKey($id, $this->defined);
			$code = $key !== null && $id !== null ? $this->defined[$id]['code'] : null;
			return ['key' => $key ?? '', 'code' => $code, 'name' => $this->name($code)];
		}
		$code = ReportService::costCode($account->getNumber());
		return ['key' => $code ?? '', 'code' => $code, 'name' => $this->name($code)];
	}

	/**
	 * Alle angelegten Kostenstellen als Gruppen (Modus 'manual'), damit auch
	 * eine Kostenstelle ohne zugeordnetes Konto im Bericht erscheint.
	 *
	 * @return list<array{key:string, code:string, name:string}>
	 */
	public function definedGroups(): array {
		if ($this->mode !== 'manual') {
			return [];
		}
		$out = [];
		foreach ($this->defined as $id => $cc) {
			$out[] = [
				'key' => (string)ReportService::manualGroupKey($id, $this->defined),
				'code' => $cc['code'],
				'name' => $cc['name'],
			];
		}
		return $out;
	}

	/** Anzeigename einer Kostenstelle; ohne Code die Sammelgruppe. */
	public function name(?string $code): string {
		if ($code === null) {
			return $this->l10n->t('(ohne Auswertungsgruppe)');
		}
		if (isset($this->names[$code])) {
			return $this->names[$code];
		}
		return match ($code) {
			'01' => $this->l10n->t('Ideeller Bereich'),
			'11' => $this->l10n->t('Verbandszeitung'),
			default => $this->l10n->t('Auswertungsgruppe %s', [$code]),
		};
	}
}

<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Service\RecipientL10n;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die Sprache eines Mitglieds-Mails (Issue #106, Spec §1.4/§3.11): Du für ein
 * verknüpftes NC-Konto auf informellem Deutsch (`de`), Sie in allen anderen
 * deutschen Fällen – insbesondere für Mitglieder OHNE Konto –, Englisch für ein
 * englisches Konto. Geprüft wird, welche Sprache bei der Factory angefragt
 * wird; dass `de` wirklich die Du-Fassung liefert, steht im Test des
 * Übersetzungsbündels (L10nCatalogTest) und im Mailtest der Vorabinfo.
 */
class RecipientL10nTest extends TestCase {

	private IFactory&MockObject $factory;
	private IUserManager&MockObject $userManager;
	/** @var array<string, IL10N> je angefragter Sprache ein eigener Mock */
	private array $l10nByLanguage = [];
	/** @var list<string> */
	private array $requested = [];

	protected function setUp(): void {
		$this->l10nByLanguage = [];
		$this->requested = [];
		$this->factory = $this->createMock(IFactory::class);
		$this->userManager = $this->createMock(IUserManager::class);
		// Die Sprachen mit Bündel in l10n/: en immer, de (Du) und de_DE (Sie, leer).
		$this->factory->method('languageExists')->willReturnCallback(
			static fn (?string $app, string $lang): bool => in_array($lang, ['en', 'de', 'de_DE'], true),
		);
		$this->factory->method('get')->willReturnCallback(function (string $app, ?string $lang = null): IL10N {
			$this->assertSame(Application::APP_ID, $app);
			$this->requested[] = (string)$lang;
			return $this->l10nByLanguage[(string)$lang] ??= $this->createMock(IL10N::class);
		});
	}

	private function service(): RecipientL10n {
		return new RecipientL10n($this->factory, $this->userManager);
	}

	private function accountWithLanguage(string $uid, string $language): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with($uid)->willReturn($user);
		$this->factory->method('getUserLanguage')->with($user)->willReturn($language);
	}

	private function member(?string $ncUserId): Member {
		$member = new Member();
		$member->setNcUserId($ncUserId);
		return $member;
	}

	public function testKontoAufInformellemDeutschBekommtDuDieDeJson(): void {
		$this->accountWithLanguage('anna', 'de');

		$l = $this->service()->forMember($this->member('anna'));

		$this->assertSame(['de'], $this->requested);
		$this->assertSame($this->l10nByLanguage['de'], $l);
	}

	public function testKontoAufFoermlichemDeutschBekommtSie(): void {
		$this->accountWithLanguage('anna', 'de_DE');

		$this->service()->forMember($this->member('anna'));

		$this->assertSame(['de_DE'], $this->requested);
	}

	public function testMitgliedOhneKontoBekommtImmerSie(): void {
		// Auch wenn die Standardsprache der Instanz informelles Deutsch ist (die Factory
		// würde sie bei einer Anfrage ohne Sprache liefern): ohne Konto gilt Sie.
		$this->factory->method('getUserLanguage')->willReturn('de');

		$this->service()->forMember($this->member(null));
		$this->service()->forMember($this->member(''));

		$this->assertSame(['de_DE', 'de_DE'], $this->requested);
	}

	public function testVerknuepfungAufGeloeschtesKontoBekommtSie(): void {
		$this->userManager->method('get')->with('weg')->willReturn(null);

		$this->service()->forMember($this->member('weg'));

		$this->assertSame(['de_DE'], $this->requested);
	}

	public function testEnglischesKontoBekommtEnglisch(): void {
		$this->accountWithLanguage('anna', 'en');

		$this->service()->forMember($this->member('anna'));

		$this->assertSame(['en'], $this->requested);
	}

	public function testRegionaleVarianteFaelltAufDieHauptspracheZurueck(): void {
		$this->accountWithLanguage('anna', 'en_GB');

		$this->service()->forMember($this->member('anna'));

		$this->assertSame(['en'], $this->requested);
	}

	/** @return array<string, array{string}> */
	public static function sprachenOhneBuendel(): array {
		return [
			'Französisch' => ['fr'],
			// de_AT/de_CH dürfen NICHT auf de (Du) fallen: ein unbekanntes Konto wird gesiezt.
			'Österreichisches Deutsch' => ['de_AT'],
			'Schweizer Deutsch' => ['de_CH'],
		];
	}

	/** @dataProvider sprachenOhneBuendel */
	public function testSpracheOhneBuendelFaelltAufDeutschInSieFormZurueck(string $language): void {
		$this->accountWithLanguage('anna', $language);

		$this->service()->forMember($this->member('anna'));

		$this->assertSame(['de_DE'], $this->requested);
	}

	public function testFormalLiefertDieSieFassungUnabhaengigVomRequest(): void {
		$l = $this->service()->formal();

		$this->assertSame(['de_DE'], $this->requested);
		$this->assertSame($this->l10nByLanguage['de_DE'], $l);
	}

	public function testLanguageOfNenntDenSprachcodeOhneEineFactoryAnfrage(): void {
		$this->accountWithLanguage('anna', 'de');

		$this->assertSame('de', $this->service()->languageOf('anna'));
		$this->assertSame('de_DE', $this->service()->languageOf(null));
		$this->assertSame([], $this->requested);
	}
}

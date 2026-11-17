<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\TravelDetails;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reiseangaben einer Reisekostenabrechnung (Issue #353, Stufe 1). */
class TravelDetailsTest extends TestCase {

	private IL10N $l10n;

	protected function setUp(): void {
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
	}

	/** @return array<string, mixed> */
	private static function complete(): array {
		return [
			'destination' => '  Landesturnier Kassel ',
			'start' => '2026-11-07T07:30',
			'end' => '2026-11-07T19:15',
			'items' => [
				['type' => 'travel', 'transport' => 'car', 'route' => 'Vereinsheim – Kassel und zurück', 'distanceKm' => 184.25, 'amountCents' => 5520],
				['type' => 'parking', 'note' => 'Parkhaus Messe', 'amountCents' => 800],
			],
		];
	}

	public function testVollstaendigeAngabenErgebenSummeUndDatum(): void {
		$travel = TravelDetails::fromInput(self::complete(), $this->l10n, true, '2026-11-17');

		$this->assertSame(6320, $travel->totalCents());
		$this->assertSame('2026-11-07', $travel->startDate());
		$this->assertSame('Landesturnier Kassel', $travel->destination);
		$this->assertSame(184.3, $travel->items[0]['distanceKm']);
		$this->assertNull($travel->items[1]['transport'], 'Parkgebühren haben kein Verkehrsmittel');
		$this->assertSame($travel->toArray(), TravelDetails::decode($travel->toJson()));
	}

	/** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}> */
	public static function unvollstaendig(): array {
		return [
			'ohne Ziel' => [static fn (array $d): array => ['destination' => ''] + $d, 'Reiseziel'],
			'ohne Ende' => [static fn (array $d): array => ['end' => null] + $d, 'Beginn und Ende'],
			'ohne Position' => [static fn (array $d): array => ['items' => []] + $d, 'mindestens eine Position'],
			'Position ohne Betrag' => [static function (array $d): array {
				$d['items'][1]['amountCents'] = null;
				return $d;
			}, 'Betrag größer als 0'],
			'Fahrt ohne Strecke' => [static function (array $d): array {
				$d['items'][0]['route'] = ' ';
				return $d;
			}, 'Verkehrsmittel und Strecke'],
			'Beginn in der Zukunft' => [static fn (array $d): array => ['start' => '2026-11-20T08:00', 'end' => '2026-11-20T09:00'] + $d, 'Zukunft'],
		];
	}

	/** @param callable(array<string, mixed>): array<string, mixed> $change */
	#[DataProvider('unvollstaendig')]
	public function testEinreichenVerlangtAllePflichtangaben(callable $change, string $message): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		TravelDetails::fromInput($change(self::complete()), $this->l10n, true, '2026-11-17');
	}

	public function testEinEntwurfDarfUnvollstaendigSein(): void {
		$draft = TravelDetails::fromInput(['destination' => 'Kassel', 'items' => [['type' => 'travel', 'transport' => 'public']]], $this->l10n, false);

		$this->assertSame(0, $draft->totalCents());
		$this->assertNull($draft->startDate());
		$this->assertNull($draft->items[0]['amountCents']);
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function ungueltig(): array {
		return [
			'Ende vor Beginn' => [['start' => '2026-11-07T10:00', 'end' => '2026-11-07T09:00', 'items' => []]],
			'kaputtes Datum' => [['start' => '07.11.2026 10:00', 'items' => []]],
			'unbekannte Art' => [['items' => [['type' => 'hotel', 'amountCents' => 100]]]],
			'unbekanntes Verkehrsmittel' => [['items' => [['type' => 'travel', 'transport' => 'rocket']]]],
			'negativer Betrag' => [['items' => [['type' => 'parking', 'amountCents' => -5]]]],
			'Betrag als Text' => [['items' => [['type' => 'parking', 'amountCents' => '12,50']]]],
			'Entfernung null' => [['items' => [['type' => 'travel', 'distanceKm' => 0]]]],
			'keine Liste' => [['items' => ['a' => ['type' => 'parking']]]],
		];
	}

	/** @param array<string, mixed> $input */
	#[DataProvider('ungueltig')]
	public function testUngueltigeAngabenScheiternAuchImEntwurf(array $input): void {
		$this->expectException(\InvalidArgumentException::class);
		TravelDetails::fromInput($input, $this->l10n, false);
	}

	public function testHoechstensZwanzigPositionen(): void {
		$items = array_fill(0, TravelDetails::MAX_ITEMS + 1, ['type' => 'parking', 'amountCents' => 100]);
		$this->expectException(\InvalidArgumentException::class);
		TravelDetails::fromInput(['items' => $items], $this->l10n, false);
	}

	public function testAnonymisierungLoeschtStreckenUndNotizenNichtDieBetraege(): void {
		$json = TravelDetails::fromInput(self::complete(), $this->l10n, true, '2026-11-17')->toJson();

		$redacted = TravelDetails::decode(TravelDetails::redactJson($json));

		$this->assertNotNull($redacted);
		$this->assertNull($redacted['items'][0]['route']);
		$this->assertNull($redacted['items'][1]['note']);
		$this->assertSame(5520, $redacted['items'][0]['amountCents']);
		$this->assertSame('car', $redacted['items'][0]['transport']);
		$this->assertSame('Landesturnier Kassel', $redacted['destination']);
	}
}

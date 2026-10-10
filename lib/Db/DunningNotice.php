<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Mahnversand (Spec §2.2 „Mahnversand (Dunning Notice)", §3.6 §5, Issue #73):
 * „eine Zeile je (Forderung, Stufe) mit Versandzeitpunkt + Batch-Referenz der
 * Mail" – EINZIGE Mahnwesen-Persistenz, alles andere (welche Stufe eine
 * Forderung als nächstes erreicht, ob sie aktuell gestundet ist, ...) ist
 * abgeleitete Abfrage über {@see \OCA\Vereinsbuchhaltung\Service\DunningLadderService}.
 *
 * `openItemId` verweist auf die Forderung ({@see OpenItem}, dort als "Claim"
 * im Sinne von Issue #68 geführt). Der Unique-Index auf
 * (`open_item_id`,`stage`) verhindert einen doppelten Versand derselben Stufe
 * strukturell – wichtig, weil {@see \OCA\Vereinsbuchhaltung\Service\DunningLadderService::runDaily()}
 * idempotent bei mehrfachem Aufruf am selben Tag bleiben muss (gleiches
 * Muster wie der Posten-Guard bei {@see ReturnedDebit}).
 *
 * `mailBatchReference` gruppiert mehrere Zeilen, die in EINER gebündelten
 * Mail (Spec „gebündelt je Mitglied") verschickt wurden – ein Mitglied mit
 * zwei gleichzeitig fälligen Forderungen bekommt eine Mail, aber zwei
 * `DunningNotice`-Zeilen mit identischer Referenz.
 *
 * @method int getOpenItemId()
 * @method void setOpenItemId(int $openItemId)
 * @method int getStage()
 * @method void setStage(int $stage)
 * @method string getSentAt()
 * @method void setSentAt(string $sentAt)
 * @method string getMailBatchReference()
 * @method void setMailBatchReference(string $mailBatchReference)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class DunningNotice extends Entity implements \JsonSerializable {
	protected $openItemId;
	protected $stage;
	protected $sentAt;
	protected $mailBatchReference;
	protected $createdAt;

	/** Stufe 0 „Zahlungsaufforderung" – sofort bei Rücklastschrift/Widerruf, mit Vorabinfo-Vorlauf bei Überweiser-Fälligkeit (Spec §3.6). */
	public const STAGE_PAYMENT_REQUEST = 0;
	/** Stufe 1 „Zahlungserinnerung" – nach konfigurierbarem Mahnabstand (Default 14 Tage). */
	public const STAGE_REMINDER = 1;
	/** Stufe 2 „Mahnung" – + Mahnabstand, kündigt die Vorstands-Eskalation an; danach keine weitere Automatik. */
	public const STAGE_DUNNING = 2;
	public const STAGES = [self::STAGE_PAYMENT_REQUEST, self::STAGE_REMINDER, self::STAGE_DUNNING];

	public function __construct() {
		$this->addType('openItemId', 'integer');
		$this->addType('stage', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'openItemId' => $this->openItemId,
			'stage' => $this->stage,
			'sentAt' => $this->sentAt,
			'mailBatchReference' => $this->mailBatchReference,
			'createdAt' => $this->createdAt,
		];
	}
}

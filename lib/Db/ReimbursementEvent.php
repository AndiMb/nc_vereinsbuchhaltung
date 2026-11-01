<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ein Eintrag der Historie eines Auslagenantrags. Nur anfügen: weder der
 * Code noch ein Mapper ändert oder löscht ein Ereignis. Jedes Ereignis trägt
 * den Hash seines Vorgängers und seinen eigenen (Hash-Kette); wer ein
 * Ereignis nachträglich ändert, bricht die Kette ab dieser Stelle.
 *
 * `data` ist ein JSON-Text mit den Einzelheiten der Aktion (z. B. die
 * Zählung „2 von 3“ bei einer Freigabe).
 *
 * @method int getReimbursementId()
 * @method void setReimbursementId(int $reimbursementId)
 * @method int getSeq()
 * @method void setSeq(int $seq)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string getActorUid()
 * @method void setActorUid(string $actorUid)
 * @method string getActorRole()
 * @method void setActorRole(string $actorRole)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getComment()
 * @method void setComment(?string $comment)
 * @method string|null getData()
 * @method void setData(?string $data)
 * @method string|null getPrevHash()
 * @method void setPrevHash(?string $prevHash)
 * @method string getHash()
 * @method void setHash(string $hash)
 */
class ReimbursementEvent extends Entity implements \JsonSerializable {
	protected $reimbursementId;
	protected $seq;
	protected $action;
	protected $actorUid;
	protected $actorRole;
	protected $createdAt;
	protected $comment;
	protected $data;
	protected $prevHash;
	protected $hash;

	public const ACTION_SUBMITTED = 'submitted';
	public const ACTION_APPROVED = 'approved';
	public const ACTION_REJECTED = 'rejected';
	public const ACTION_WITHDRAWN = 'withdrawn';
	public const ACTION_PAID = 'paid';
	public const ACTION_RECEIPT_ADDED = 'receipt_added';
	public const ACTION_RECEIPT_REMOVED = 'receipt_removed';
	public const ACTION_DUPLICATE_HINT = 'duplicate_hint';

	/** Wer gehandelt hat: das Mitglied selbst oder jemand aus der Verwaltung. */
	public const ROLE_MEMBER = 'member';
	public const ROLE_STAFF = 'staff';

	public function __construct() {
		$this->addType('reimbursementId', 'integer');
		$this->addType('seq', 'integer');
	}

	/** @return array<string,mixed> */
	public function decodedData(): array {
		if ($this->data === null || $this->data === '') {
			return [];
		}
		$decoded = json_decode($this->data, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param bool $withActor false für die Mitgliedersicht: dort steht nur
	 *                        „durch einen Verwalter“, nie der Name und keine
	 *                        Folgenummer (Design §5)
	 */
	public function toArray(bool $withActor): array {
		$out = [
			'action' => $this->action,
			'createdAt' => $this->createdAt,
			'comment' => $this->comment,
			'data' => $this->decodedData(),
		];
		if ($withActor) {
			$out['id'] = $this->id;
			$out['seq'] = $this->seq;
			$out['actorUid'] = $this->actorUid;
			$out['actorRole'] = $this->actorRole;
			$out['hash'] = $this->hash;
			$out['prevHash'] = $this->prevHash;
		}
		return $out;
	}

	public function jsonSerialize(): array {
		return $this->toArray(true);
	}
}

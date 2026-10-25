<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper, der einzelne Spalten verschlüsselt ablegt (siehe {@see FieldCipher}).
 *
 * Verschlüsselt wird beim Schreiben, entschlüsselt beim Lesen; die Entity
 * hält immer den Klartext, `getIban()` liefert die IBAN wie bisher. Nach
 * einem verschlüsselten Wert filtern kann SQL dafür nicht mehr – das
 * Chiffrat ist bei jedem Schreiben ein anderes –, solche Vergleiche laufen
 * nach dem Laden in PHP.
 *
 * @template T of Entity
 * @extends QBMapper<T>
 */
abstract class EncryptingMapper extends QBMapper {

	/**
	 * @param class-string<T>|null $entityClass
	 */
	public function __construct(
		IDBConnection $db,
		string $tableName,
		?string $entityClass,
		protected FieldCipher $cipher,
	) {
		parent::__construct($db, $tableName, $entityClass);
	}

	/** @return list<string> Properties der Entity, die verschlüsselt liegen */
	abstract protected function encryptedProperties(): array;

	/**
	 * Klartext aus der Zeit vor der Migration reicht {@see FieldCipher::decrypt()}
	 * unverändert durch.
	 *
	 * @return T
	 */
	protected function mapRowToEntity(array $row): Entity {
		$entity = parent::mapRowToEntity($row);
		foreach ($this->encryptedProperties() as $property) {
			$value = $this->read($entity, $property);
			if (is_string($value)) {
				$this->write($entity, $property, $this->cipher->decrypt($value));
			}
		}
		$entity->resetUpdatedFields();
		return $entity;
	}

	public function insert(Entity $entity): Entity {
		return $this->withCiphertext($entity, fn (): Entity => parent::insert($entity));
	}

	public function update(Entity $entity): Entity {
		return $this->withCiphertext($entity, fn (): Entity => parent::update($entity));
	}

	/**
	 * Setzt die zu schreibenden Werte für die Dauer des Schreibens auf ihr
	 * Chiffrat. Unveränderte Felder schreibt der QBMapper ohnehin nicht.
	 *
	 * @param \Closure(): Entity $store
	 */
	private function withCiphertext(Entity $entity, \Closure $store): Entity {
		$plain = [];
		$updated = $entity->getUpdatedFields();
		foreach ($this->encryptedProperties() as $property) {
			$value = $this->read($entity, $property);
			if (isset($updated[$property]) && is_string($value) && $value !== '') {
				$plain[$property] = $value;
				$this->write($entity, $property, $this->cipher->encrypt($value));
			}
		}
		try {
			return $store();
		} finally {
			foreach ($plain as $property => $value) {
				$this->write($entity, $property, $value);
			}
		}
	}

	private function read(Entity $entity, string $property): mixed {
		return $entity->{'get' . ucfirst($property)}();
	}

	private function write(Entity $entity, string $property, string $value): void {
		$entity->{'set' . ucfirst($property)}($value);
	}
}

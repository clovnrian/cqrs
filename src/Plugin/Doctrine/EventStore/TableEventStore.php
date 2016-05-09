<?php

namespace CQRS\Plugin\Doctrine\EventStore;

use CQRS\Domain\Message\DomainEventMessageInterface;
use CQRS\Domain\Message\EventMessageInterface;
use CQRS\Domain\Message\GenericDomainEventMessage;
use CQRS\Domain\Message\GenericEventMessage;
use CQRS\Domain\Message\Metadata;
use CQRS\Domain\Message\Timestamp;
use CQRS\EventStore\EventStoreInterface;
use CQRS\Exception\OutOfBoundsException;
use CQRS\Serializer\SerializerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Generator;
use PDO;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class TableEventStore implements EventStoreInterface
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var string
     */
    private $table = 'cqrs_event';

    public function __construct(SerializerInterface $serializer, Connection $connection, string $table = null)
    {
        $this->serializer = $serializer;
        $this->connection = $connection;

        if (null !== $table) {
            $this->table = $table;
        }
    }

    public function store(EventMessageInterface $event)
    {
        $data = $this->toArray($event);
        $this->connection->insert($this->table, $data);
    }

    public function read(int $offset = null, int $limit = 10): array
    {
        if ($offset === null) {
            $offset = (((int) (($this->getLastRowId() - 1) / $limit)) * $limit) + 1;
        }

        $sql = 'SELECT * FROM ' . $this->table
            . ' WHERE id >= ?'
            . ' ORDER BY id ASC'
            . ' LIMIT ?';

        $events = [];

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(1, $offset, Type::INTEGER);
        $stmt->bindValue(2, $limit, Type::INTEGER);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $events[$row['id']] = $this->fromArray($row);
        }

        return $events;
    }

    public function iterate(UuidInterface $previousEventId = null): Generator
    {
        $id = $previousEventId ? $this->getRowIdByEventId($previousEventId) : 0;

        $sql = 'SELECT * FROM ' . $this->table
            . ' WHERE id > ?'
            . ' ORDER BY id ASC'
            . ' LIMIT ?';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(1, $id, Type::INTEGER);
        $stmt->bindValue(2, 100, Type::INTEGER);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $this->fromArray($row);
        }
    }

    private function toArray(EventMessageInterface $event): array
    {
        $data = [
            'event_id' => (string)$event->getId(),
            'event_date' => $event->getTimestamp()
                ->format('Y-m-d H:i:s'),
            'event_date_u' => $event->getTimestamp()
                ->format('u'),
            'payload_type' => $event->getPayloadType(),
            'payload' => $this->serializer->serialize($event->getPayload()),
            'metadata' => $this->serializer->serialize($event->getMetadata()),
        ];

        if ($event instanceof DomainEventMessageInterface) {
            $aggregateId = $event->getAggregateId();
            if (!is_int($aggregateId)) {
                $aggregateId = (string) $aggregateId;
            }

            $data = array_merge($data, [
                'aggregate_type' => $event->getAggregateType(),
                'aggregate_id' => $aggregateId,
                'sequence_number' => $event->getSequenceNumber(),
            ]);
        }

        return $data;
    }

    /**
     * @param array $data
     * @return GenericDomainEventMessage|GenericEventMessage
     */
    public function fromArray(array $data): GenericEventMessage
    {
        $payload = $this->serializer->deserialize($data['payload'], $data['payload_type']);
        /** @var Metadata $metadata */
        $metadata = $this->serializer->deserialize($data['metadata'], Metadata::class);
        $id = Uuid::fromString($data['event_id']);
        $timestamp = new Timestamp("{$data['event_date']}.{$data['event_date_u']}");

        if (array_key_exists('aggregate_type', $data)) {
            return new GenericDomainEventMessage(
                $data['aggregate_type'],
                $data['aggregate_id'],
                $data['sequence_number'],
                $payload,
                $metadata,
                $id,
                $timestamp
            );
        }

        return new GenericEventMessage($payload, $metadata, $id, $timestamp);
    }

    private function getLastRowId(): int
    {
        $sql = 'SELECT MAX(id) FROM ' . $this->table;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function getRowIdByEventId(UuidInterface $eventId): int
    {
        static $lastEventId, $lastRowId;

        if ($eventId->equals($lastEventId)) {
            return $lastRowId;
        }

        $sql = "SELECT id FROM {$this->table} WHERE event_id = ? LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(1, (string) $eventId, Type::STRING);
        $stmt->execute();

        $rowId = $stmt->fetchColumn();
        if (false === $rowId) {
            throw new OutOfBoundsException(sprintf('Record for event %s not found', $eventId));
        }

        $lastEventId = $eventId;
        $lastRowId = (int) $rowId;
        return $lastRowId;
    }
}

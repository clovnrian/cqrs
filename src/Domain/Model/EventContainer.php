<?php

namespace CQRS\Domain\Model;

use Countable;
use CQRS\Domain\Message\GenericDomainEventMessage;
use CQRS\Domain\Message\Metadata;
use CQRS\EventHandling\EventInterface;
use CQRS\Exception\RuntimeException;

/**
 * Container for events related to a single aggregate. The container will wrap registered event (payload) and metadata
 * in an GenericDomainEventMessage and automatically assign the aggregate identifier and the next sequence number.
 */
class EventContainer implements Countable
{
    /**
     * @var GenericDomainEventMessage[]
     */
    private $events = [];

    /**
     * @var string
     */
    private $aggregateType;

    /**
     * @var mixed
     */
    private $aggregateId;

    /**
     * @var int
     */
    private $lastSequenceNumber;

    /**
     * @var int
     */
    private $lastCommittedSequenceNumber;

    /**
     * Initialize an EventContainer for an aggregate with the given aggregateIdentifier. This identifier will be
     * attached to all incoming events.
     *
     * @param string $aggregateType
     * @param mixed $aggregateId
     */
    public function __construct($aggregateType, $aggregateId)
    {
        $this->aggregateType = $aggregateType;
        $this->aggregateId   = $aggregateId;
    }

    /**
     * Add an event to this container.
     *
     * @param EventInterface $payload
     * @param Metadata|array $metadata
     * @return GenericDomainEventMessage
     */
    public function addEvent(EventInterface $payload, $metadata = null)
    {
        $event = new GenericDomainEventMessage(
            $this->aggregateType,
            $this->aggregateId,
            $this->newSequenceNumber(),
            $payload,
            $metadata
        );

        $this->lastSequenceNumber = $event->getSequenceNumber();
        $this->events[] = $event;
        return $event;
    }

    /**
     * @return GenericDomainEventMessage[]
     */
    public function getEvents()
    {
        return $this->events;
    }

    /**
     * Clears the events in this container. The sequence number is not modified by this call.
     */
    public function commit()
    {
        $this->lastCommittedSequenceNumber = $this->getLastSequenceNumber();
        $this->events = [];
    }

    /**
     * Returns the number of events currently inside this container.
     *
     * @return int
     */
    public function count()
    {
        return count($this->events);
    }

    /**
     * Sets the first sequence number that should be assigned to an incoming event.
     *
     * @param int $lastKnownSequenceNumber
     */
    public function initializeSequenceNumber($lastKnownSequenceNumber)
    {
        if (!empty($this->events)) {
            throw new RuntimeException('Cannot set first sequence number if events have already been added');
        }
        $this->lastCommittedSequenceNumber = $lastKnownSequenceNumber;
    }

    /**
     * Returns the sequence number of the last committed event, or null if no events have been committed.
     *
     * @return int
     */
    public function getLastSequenceNumber()
    {
        if (empty($this->events)) {
            return $this->lastCommittedSequenceNumber;
        }
        if ($this->lastSequenceNumber === null) {
            $event = end($this->events);
            $this->lastSequenceNumber = $event->getSequenceNumber();
        }
        return $this->lastSequenceNumber;
    }

    /**
     * @return int
     */
    private function newSequenceNumber()
    {
        $currentSequenceNumber = $this->getLastSequenceNumber();
        if ($currentSequenceNumber === null) {
            return 0;
        }
        return $currentSequenceNumber + 1;
    }
}
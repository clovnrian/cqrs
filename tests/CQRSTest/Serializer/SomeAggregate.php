<?php

namespace CQRSTest\Serializer;

use CQRS\Domain\Model\AbstractAggregateRoot;

class SomeAggregate extends AbstractAggregateRoot
{
    public function getId(): int
    {
        return 4;
    }
}

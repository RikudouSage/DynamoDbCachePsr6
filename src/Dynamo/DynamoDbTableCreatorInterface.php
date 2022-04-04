<?php

namespace Rikudou\DynamoDbCache\Dynamo;

interface DynamoDbTableCreatorInterface
{
    public const MODE_PROVISIONED = 'PROVISIONED';
    public const MODE_PAY_PER_REQUEST = 'PAY_PER_REQUEST';

    public function exists(): bool;

    public function create(string $mode = self::MODE_PAY_PER_REQUEST, bool $throw = true): bool;

    public function createIfNotExists(string $mode = self::MODE_PAY_PER_REQUEST, bool $throw = true): bool;
}

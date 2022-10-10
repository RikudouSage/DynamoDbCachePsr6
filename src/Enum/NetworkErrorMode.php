<?php

namespace Rikudou\DynamoDbCache\Enum;

use ReflectionClass;

final class NetworkErrorMode
{
    public const DEFAULT = self::WARNING;

    public const IGNORE = 0;
    public const WARNING = 1;
    public const THROW = 2;

    /**
     * @return int[]
     */
    public static function cases(): array
    {
        $reflection = new ReflectionClass(self::class);

        return array_values($reflection->getConstants());
    }
}

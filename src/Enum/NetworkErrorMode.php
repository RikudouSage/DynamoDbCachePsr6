<?php

namespace Rikudou\DynamoDbCache\Enum;

final class NetworkErrorMode
{
    public const DEFAULT = self::WARNING;

    public const IGNORE = 0;
    public const WARNING = 1;
    public const THROW = 2;
}

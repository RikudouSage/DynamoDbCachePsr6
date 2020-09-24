<?php

namespace Rikudou\DynamoDbCache\Exception;

use Exception;
use Psr\Cache\InvalidArgumentException as PsrException;

final class InvalidArgumentException extends Exception implements PsrException
{
}

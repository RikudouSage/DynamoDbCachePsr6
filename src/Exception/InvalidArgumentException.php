<?php

namespace Rikudou\DynamoDbCache\Exception;

use Exception;
use Psr\Cache\InvalidArgumentException as PsrException;
use Psr\SimpleCache\InvalidArgumentException as SimplePsrException;

final class InvalidArgumentException extends Exception implements PsrException, SimplePsrException
{
}

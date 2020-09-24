[![Tests](https://github.com/RikudouSage/DynamoDbCachePsr6/workflows/Tests/badge.svg)](https://github.com/RikudouSage/DynamoDbCachePsr6/actions?query=workflow%3ATests)
[![Coverage Status](https://coveralls.io/repos/github/RikudouSage/DynamoDbCachePsr6/badge.svg?branch=master)](https://coveralls.io/github/RikudouSage/DynamoDbCachePsr6?branch=master)

Library for storing cache in DynamoDB implementing the PSR-6 interface.
See also [Symfony bundle](https://github.com/RikudouSage/DynamoDbCachePsr6Bundle) of this library.

## Installation

`composer require rikudou/psr6-dynamo-db`

## Usage

The usage is pretty straight-forward, you just define the details in constructor and then use it as any other
PSR-6 implementation:

```php
<?php

use Rikudou\DynamoDbCache\DynamoDbCache;
use Aws\DynamoDb\DynamoDbClient;

$cache = new DynamoDbCache('dynamoTableName', new DynamoDbClient([]));

// with custom field names
$cache = new DynamoDbCache('dynamoTableName', new DynamoDbClient([]), 'customPrimaryKeyField', 'customTtlField', 'customValueField');
```

The default values for fields are:
- primary key - `id` (string)
- ttl field - `ttl` (number)
- value field - `value` (string)

You must create the DynamoDB table before using this library.

## Basic example:

```php
<?php
use Aws\DynamoDb\DynamoDbClient;
use Rikudou\DynamoDbCache\DynamoDbCache;

function get(string $key): string {
    $dynamoDbClient = new DynamoDbClient([
        'region' => 'eu-central-1',
        'version' => 'latest'
    ]);
    $cache = new DynamoDbCache('cache', $dynamoDbClient); // the default field names are used - id, ttl and value
    
    $item = $cache->getItem($key);
    if ($item->isHit()) {
        return $item->get();    
    }

    // do something to fetch the item
    $result = '...';

    $item->set($result);
    $item->expiresAfter(3600); // expire after one hour
    if (!$cache->save($item)) {
        throw new RuntimeException('Could not save cache');
    }

    return $result;
}
```
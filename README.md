[![Tests](https://github.com/RikudouSage/DynamoDbCachePsr6/workflows/Tests/badge.svg)](https://github.com/RikudouSage/DynamoDbCachePsr6/actions?query=workflow%3ATests)
[![Coverage Status](https://coveralls.io/repos/github/RikudouSage/DynamoDbCachePsr6/badge.svg?branch=master)](https://coveralls.io/github/RikudouSage/DynamoDbCachePsr6?branch=master)

Library for storing cache in DynamoDB implementing the PSR-6 and PSR-16 interfaces.
See also [Symfony bundle](https://github.com/RikudouSage/DynamoDbCachePsr6Bundle) of this library.

## Installation

`composer require rikudou/psr6-dynamo-db`

## Usage

The usage is pretty straight-forward, you just define the details in constructor and then use it as any other
PSR-6 or PSR-16 implementation:

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

function get(string $key): string
{
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

## Example using the PSR-16 interface:

```php
<?php

use Aws\DynamoDb\DynamoDbClient;
use Rikudou\DynamoDbCache\DynamoDbCache;

function get(string $key): string
{
    $dynamoDbClient = new DynamoDbClient([
        'region' => 'eu-central-1',
        'version' => 'latest'
    ]);
    $cache = new DynamoDbCache('cache', $dynamoDbClient); // the default field names are used - id, ttl and value

    $value = $cache->get($key);
    if ($value !== null) {
        return $value;
    }
    
    // do something to fetch the item
    $result = '...';

    if (!$cache->set($key, $result, 3600)) {
       throw new RuntimeException('Could not save cache');     
    }
    
    return $result;
}
```

## Converters

This implementation supports all instances of `\Psr\Cache\CacheItemInterface` with the use of converters which
convert the object to `\Rikudou\DynamoDbCache\DynamoCacheItem`. Note that some information may be lost in the
conversion, notably expiration date.

You can write your own converter for your specific class which includes support for expiration date like this:

```php
<?php

use Rikudou\DynamoDbCache\Converter\CacheItemConverterInterface;
use Psr\Cache\CacheItemInterface;
use Rikudou\DynamoDbCache\DynamoCacheItem;

class MyCacheItemConverter implements CacheItemConverterInterface
{
    /**
     * If this methods returns true, the converter will be used
     */
    public function supports(CacheItemInterface $cacheItem): bool
    {
        return $cacheItem instanceof MyCacheItem;
    }
    
    public function convert(CacheItemInterface $cacheItem): DynamoCacheItem
    {
        assert($cacheItem instanceof MyCacheItem);
        return new DynamoCacheItem(
            $cacheItem->getKey(),
            $cacheItem->isHit(),
            $cacheItem->get(),
            $cacheItem->getExpirationDate() // this is a custom method from the hypothetical MyCacheItem
        );
    }
}
```

You then need to register it in the converter and assign the converter to the cache:

```php
<?php

use Rikudou\DynamoDbCache\Converter\CacheItemConverterRegistry;
use Rikudou\DynamoDbCache\DynamoDbCache;
use Aws\DynamoDb\DynamoDbClient;

// you don't need to add the default one as well, it will be added automatically if it's missing
$converter = new CacheItemConverterRegistry(new MyCacheItemConverter());
$dynamoClient = new DynamoDbClient([]);
$cache = new DynamoDbCache(
    'myTable',
    $dynamoClient,
    'id',
    'ttl',
    'value',
    null,
    $converter
);

$myOldCache = new MyCacheImplementation();
$cacheItem = $myOldCache->getItem('test'); // this is now an instance of MyCacheItem

// your custom converter will get used to convert it to DynamoCacheItem
// if you didn't supply your own converter, the \Rikudou\DynamoDbCache\Converter\DefaultCacheItemConverter
// would be used and the information about expiration date would be lost
$cache->save($cacheItem);
```

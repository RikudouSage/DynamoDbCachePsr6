[![Tests](https://github.com/RikudouSage/DynamoDbCachePsr6/actions/workflows/test.yaml/badge.svg)](https://github.com/RikudouSage/DynamoDbCachePsr6/actions/workflows/test.yaml)
[![Coverage Status](https://coveralls.io/repos/github/RikudouSage/DynamoDbCachePsr6/badge.svg?branch=master)](https://coveralls.io/github/RikudouSage/DynamoDbCachePsr6?branch=master)
[![Download](https://img.shields.io/packagist/dt/rikudou/psr6-dynamo-db.svg)](https://packagist.org/packages/rikudou/psr6-dynamo-db)

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
use Rikudou\DynamoDbCache\DynamoDbCacheBuilder;
use Aws\DynamoDb\DynamoDbClient;

$cache = new DynamoDbCache('dynamoTableName', new DynamoDbClient([]));

// with custom field names
$cache = new DynamoDbCache('dynamoTableName', new DynamoDbClient([]), 'customPrimaryKeyField', 'customTtlField', 'customValueField');

// using builder
$cache = DynamoDbCacheBuilder::create('dynamoTableName', new DynamoDbClient([]))
    ->withPrimaryField('customPrimaryKeyField')
    ->withTtlField('customTtlField')
    ->withValueField('customValueField')
    ->build();
```

It's recommended to use the builder for creating new instances. The builder is immutable and every method
returns a new instance.

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

## Prefixing

You can automatically prefix all keys in DynamoDB by using the prefix configuration like this:

```php
<?php

use Rikudou\DynamoDbCache\DynamoDbCacheBuilder;
use Aws\DynamoDb\DynamoDbClient;

$cache = DynamoDbCacheBuilder::create('myTable', new DynamoDbClient([]))
    ->withPrefix('myCustomPrefix#')
    ->build();

$item = $cache->getItem('key1'); // fetches an item with key myCustomPrefix#key1
$key = $item->getKey(); // $key holds the full key including prefix, myCustomPrefix#key1
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
use Rikudou\Clock\Clock;
use Rikudou\DynamoDbCache\Encoder\SerializeItemEncoder;

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
            $cacheItem->getExpirationDate(), // this is a custom method from the hypothetical MyCacheItem
            new Clock(),
            new SerializeItemEncoder()
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
use Rikudou\DynamoDbCache\DynamoDbCacheBuilder;

// you don't need to add the default one as well, it will be added automatically if it's missing
$converter = new CacheItemConverterRegistry(new MyCacheItemConverter());
$dynamoClient = new DynamoDbClient([]);
$cache = DynamoDbCacheBuilder::create('myTable', $dynamoClient)
    ->withConverterRegistry($converter)
    ->build();

$myOldCache = new MyCacheImplementation();
$cacheItem = $myOldCache->getItem('test'); // this is now an instance of MyCacheItem

// your custom converter will get used to convert it to DynamoCacheItem
// if you didn't supply your own converter, the \Rikudou\DynamoDbCache\Converter\DefaultCacheItemConverter
// would be used and the information about expiration date would be lost
$cache->save($cacheItem);
```

## Encoders

By default the values are serialized using php serializer. If you want to share the cache with apps
in other languages (or different php app that doesn't have the same classes), you can either use the
`\Rikudou\DynamoDbCache\Encoder\JsonItemEncoder` or write your own.

> Note: The JsonItemEncoder is lossy when it comes to objects, if you need to store object information
> this encoder might not be for you. If you on the other hand only store scalar data and/or arrays
> the JsonItemEncoder is enough.

### Example using `JsonItemEncoder`

```php
<?php
use Rikudou\DynamoDbCache\DynamoDbCache;
use Rikudou\DynamoDbCache\DynamoDbCacheBuilder;
use Aws\DynamoDb\DynamoDbClient;
use Rikudou\DynamoDbCache\Encoder\JsonItemEncoder;

$encoder = new JsonItemEncoder(); // with default flags and depth
$encoder = new JsonItemEncoder(JSON_PRETTY_PRINT, JSON_THROW_ON_ERROR, 100); // with custom encode and decode flags and depth

$cache = DynamoDbCacheBuilder::create('myTable', new DynamoDbClient([]))
    ->withEncoder($encoder)
    ->build();
```

Your values will now be saved json encoded in DynamoDB.

Writing your own encoder is easy, you just need to implement the
`\Rikudou\DynamoDbCache\Encoder\CacheItemEncoderInterface` interface:

```php
<?php

use Rikudou\DynamoDbCache\Encoder\CacheItemEncoderInterface;

class MyEncoder implements CacheItemEncoderInterface
{
    /**
     * @param mixed $input
     * @return string
     */
    public function encode($input) : string
    {
        // TODO: Implement encode() method.
    }

    /**
     * @param string $input
     * @return mixed
     */
    public function decode(string $input)
    {
        // TODO: Implement decode() method.
    }
}
```

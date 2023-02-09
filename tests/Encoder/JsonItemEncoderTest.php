<?php

namespace Rikudou\Tests\DynamoDbCache\Encoder;

use JsonSerializable;
use PHPUnit\Framework\TestCase;
use Rikudou\DynamoDbCache\Encoder\JsonItemEncoder;
use RuntimeException;
use stdClass;

final class JsonItemEncoderTest extends TestCase
{
    /**
     * @var JsonItemEncoder
     */
    private $instance;

    protected function setUp(): void
    {
        $this->instance = new JsonItemEncoder();
    }

    public function testEncode()
    {
        self::assertEquals('1', $this->instance->encode(1));
        self::assertEquals('"string"', $this->instance->encode('string'));
        self::assertEquals('[1,2,3]', $this->instance->encode([1, 2, 3]));
        self::assertEquals('true', $this->instance->encode(true));
        self::assertEquals('false', $this->instance->encode(false));
        self::assertEquals('null', $this->instance->encode(null));
        self::assertEquals('{"key1":"value1","key2":2}', $this->instance->encode([
            'key1' => 'value1',
            'key2' => 2,
        ]));
        self::assertEquals('{}', $this->instance->encode(new stdClass()));
        self::assertEquals(
            '{"key1":"value1","key2":2,"key3":false,"key4":null}',
            $this->instance->encode($this->getObjectWithPublicProperties())
        );
        self::assertEquals(
            '{"key1":"value1","key2":"value2"}',
            $this->instance->encode($this->getJsonSerializableObject())
        );
        $this->expectException(RuntimeException::class);
        $this->instance->encode(fopen('php://temp', 'w'));
    }

    public function testDecode()
    {
        self::assertEquals(1, $this->instance->decode('1'));
        self::assertEquals('string', $this->instance->decode('"string"'));
        self::assertEquals([1, 2, 3], $this->instance->decode('[1,2,3]'));
        self::assertTrue($this->instance->decode('true'));
        self::assertFalse($this->instance->decode('false'));
        self::assertNull($this->instance->decode('null'));
        self::assertEquals([
            'key1' => 'value1',
            'key2' => 2,
        ], $this->instance->decode('{"key1":"value1","key2":2}'));
        // here the conversion starts to be lossy
        self::assertEquals([], $this->instance->decode('{}'));
        self::assertEquals([
            'key1' => 'value1',
            'key2' => 2,
            'key3' => false,
            'key4' => null,
        ], $this->instance->decode('{"key1":"value1","key2":2,"key3":false,"key4":null}'));
        self::assertEquals([
            'key1' => 'value1',
            'key2' => 'value2',
        ], $this->instance->decode('{"key1":"value1","key2":"value2"}'));
    }

    private function getObjectWithPublicProperties()
    {
        return new class {
            public $key1 = 'value1';

            public $key2 = 2;

            public $key3 = false;

            public $key4;
        };
    }

    private function getJsonSerializableObject()
    {
        return new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return [
                    'key1' => 'value1',
                    'key2' => 'value2',
                ];
            }
        };
    }
}

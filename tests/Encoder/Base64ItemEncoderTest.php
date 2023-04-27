<?php

namespace Rikudou\Tests\DynamoDbCache\Encoder;

use Rikudou\DynamoDbCache\Encoder\Base64ItemEncoder;
use PHPUnit\Framework\TestCase;
use Rikudou\DynamoDbCache\Encoder\CacheItemEncoderInterface;
use Rikudou\DynamoDbCache\Encoder\JsonItemEncoder;
use Rikudou\DynamoDbCache\Encoder\SerializeItemEncoder;
use stdClass;

class TestClass {
    public string $test = '1';
    private int $test2 = 10;
}

class Base64ItemEncoderTest extends TestCase
{
    /**
     * @dataProvider encodeItems
     */
    public function testEncode(mixed $item)
    {
        /** @var CacheItemEncoderInterface[] $encoders */
        $encoders = [new JsonItemEncoder(), new SerializeItemEncoder()];
        foreach ($encoders as $encoder) {
            $base64encoder = new Base64ItemEncoder($encoder);

            self::assertSame(
                $encoder->encode($item),
                base64_decode($base64encoder->encode($item)),
            );

            $encodedOriginal = $encoder->encode($item);
            $encodedBase64 = $base64encoder->encode($item);

            self::assertEquals($encoder->decode($encodedOriginal), $base64encoder->decode($encodedBase64));
        }
    }

    public function encodeItems(): iterable
    {
        yield [1];
        yield ['test'];
        yield [3.5];
        yield [['test']];
        yield [new stdClass()];
        yield [new TestClass()];
    }
}

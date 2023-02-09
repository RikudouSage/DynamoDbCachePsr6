<?php

namespace Rikudou\DynamoDbCache\Encoder;

final class SerializeItemEncoder implements CacheItemEncoderInterface
{
    public function encode(mixed $input): string
    {
        return serialize($input);
    }

    public function decode(string $input): mixed
    {
        return unserialize($input);
    }
}

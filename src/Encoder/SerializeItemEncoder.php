<?php

namespace Rikudou\DynamoDbCache\Encoder;

final class SerializeItemEncoder implements CacheItemEncoderInterface
{
    public function encode($input): string
    {
        return serialize($input);
    }

    public function decode(string $input)
    {
        return unserialize($input);
    }
}

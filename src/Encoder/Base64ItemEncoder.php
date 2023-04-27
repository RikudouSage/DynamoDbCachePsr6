<?php

namespace Rikudou\DynamoDbCache\Encoder;

final class Base64ItemEncoder implements CacheItemEncoderInterface
{
    public function __construct(
        private CacheItemEncoderInterface $encoder,
    ) {
    }

    public function encode(mixed $input): string
    {
        return base64_encode($this->encoder->encode($input));
    }

    public function decode(string $input): mixed
    {
        return $this->encoder->decode(base64_decode($input));
    }
}

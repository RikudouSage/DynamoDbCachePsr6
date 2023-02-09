<?php

namespace Rikudou\DynamoDbCache\Encoder;

use RuntimeException;

final class JsonItemEncoder implements CacheItemEncoderInterface
{
    public function __construct(
        private int $encodeFlags = 0,
        private int $decodeFlags = 0,
        private int $depth = 512
    ) {
    }

    public function encode(mixed $input): string
    {
        // this is not a default implementation and thus ext-json is
        // not in required extensions, users that use this encoder should check
        // for themselves if the json extension is loaded

        $json = json_encode($input, $this->encodeFlags, $this->depth);
        if ($json === false) {
            throw new RuntimeException('JSON Error: ' . json_last_error_msg());
        }

        return $json;
    }

    public function decode(string $input): mixed
    {
        return json_decode($input, true, $this->depth, $this->decodeFlags);
    }
}

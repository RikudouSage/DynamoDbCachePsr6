<?php

namespace Rikudou\DynamoDbCache\Encoder;

use RuntimeException;

final class JsonItemEncoder implements CacheItemEncoderInterface
{
    /**
     * @var int
     */
    private $flags;

    /**
     * @var int
     */
    private $depth;

    public function __construct(int $flags = 0, int $depth = 512)
    {
        $this->flags = $flags;
        $this->depth = $depth;
    }

    public function encode($input): string
    {
        // this is not a default implementation and thus ext-json is
        // not in required extensions, users that use this encoder should check
        // for themselves if the json extension is loaded

        /** @noinspection PhpComposerExtensionStubsInspection */
        $json = json_encode($input, $this->flags, $this->depth);
        if ($json === false) {
            /** @noinspection PhpComposerExtensionStubsInspection */
            throw new RuntimeException('JSON Error: ' . json_last_error_msg());
        }

        return $json;
    }

    public function decode(string $input)
    {
        /** @noinspection PhpComposerExtensionStubsInspection */
        return json_decode($input, true, $this->depth, $this->flags);
    }
}

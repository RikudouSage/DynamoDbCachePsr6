<?php

namespace Rikudou\DynamoDbCache\Encoder;

interface CacheItemEncoderInterface
{
    /**
     * @param mixed $input
     *
     * @return string
     */
    public function encode($input): string;

    /**
     * @param string $input
     *
     * @return mixed
     */
    public function decode(string $input);
}

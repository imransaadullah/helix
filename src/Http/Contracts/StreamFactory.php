<?php

namespace Helix\Http\Contracts;

use Helix\Http\Stream;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

class StreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        return Stream::createFromString($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return Stream::createFromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return Stream::createFromResource($resource);
    }
}
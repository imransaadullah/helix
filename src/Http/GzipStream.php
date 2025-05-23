<?php

namespace Helix\Http\Decorator;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

class GzipStream implements StreamInterface
{
    private StreamInterface $stream;
    private string $mode;
    private $gzipResource;
    private bool $closed = false;

    public function __construct(StreamInterface $stream, string $mode = 'compress')
    {
        if (!in_array($mode, ['compress', 'decompress'], true)) {
            throw new \InvalidArgumentException("Mode must be 'compress' or 'decompress'");
        }

        $this->stream = $stream;
        $this->mode = $mode;

        $this->gzipResource = gzopen('php://temp', $this->mode === 'compress' ? 'w+b' : 'r+b');

        if ($this->gzipResource === false) {
            throw new RuntimeException('Failed to initialize gzip stream');
        }

        // Load the source stream into gzip resource depending on mode
        if ($this->mode === 'compress') {
            $this->stream->rewind();
            while (!$this->stream->eof()) {
                gzwrite($this->gzipResource, $this->stream->read(8192));
            }
            gzrewind($this->gzipResource);
        } else {
            // Read raw compressed data from the stream and feed into gzip temp stream
            $this->stream->rewind();
            while (!$this->stream->eof()) {
                fwrite($this->gzipResource, $this->stream->read(8192));
            }
            rewind($this->gzipResource);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        if (is_resource($this->gzipResource)) {
            gzclose($this->gzipResource);
        }

        if ($this->stream instanceof StreamInterface) {
            $this->stream->close();
        }

        $this->closed = true;
    }

    public function detach()
    {
        $this->close();
        return null;
    }

    public function getSize(): ?int
    {
        return null; // Not predictable with gzip stream
    }

    public function tell(): int
    {
        $pos = gztell($this->gzipResource);
        if ($pos === false) {
            throw new RuntimeException('Unable to determine stream position');
        }
        return $pos;
    }

    public function eof(): bool
    {
        return gzeof($this->gzipResource);
    }

    public function isSeekable(): bool
    {
        return false; // gzip streams aren't reliably seekable
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new RuntimeException('Gzip streams are not seekable');
    }

    public function rewind(): void
    {
        if ($this->mode === 'compress') {
            throw new RuntimeException('Cannot rewind compressed gzip stream');
        }
        gzrewind($this->gzipResource);
    }

    public function isWritable(): bool
    {
        return $this->mode === 'compress' && !$this->closed;
    }

    public function write($string): int
    {
        if (!$this->isWritable()) {
            throw new RuntimeException('Stream is not writable');
        }

        $bytes = gzwrite($this->gzipResource, $string);
        if ($bytes === false) {
            throw new RuntimeException('Failed to write to gzip stream');
        }

        return $bytes;
    }

    public function isReadable(): bool
    {
        return $this->mode === 'decompress' && !$this->closed;
    }

    public function read($length): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream is not readable');
        }

        $data = gzread($this->gzipResource, $length);
        if ($data === false) {
            throw new RuntimeException('Failed to read from gzip stream');
        }

        return $data;
    }

    public function getContents(): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream is not readable');
        }

        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
    }

    public function getMetadata($key = null)
    {
        $metadata = [
            'mode' => $this->mode,
            'seekable' => $this->isSeekable(),
            'eof' => $this->eof(),
            'gzip' => true,
        ];

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    public function __toString(): string
    {
        try {
            if ($this->isReadable()) {
                $this->rewind();
                return $this->getContents();
            }
        } catch (\Throwable $e) {
            // Consider logging the error if needed
        }

        return '';
    }
}

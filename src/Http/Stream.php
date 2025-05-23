<?php

namespace Helix\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use InvalidArgumentException;

class Stream implements StreamInterface
{
    private const READABLE_MODES = ['r', 'r+', 'w+', 'a+', 'x+', 'c+'];
    private const WRITABLE_MODES = ['r+', 'w', 'w+', 'a', 'a+', 'x', 'x+', 'c', 'c+'];
    private const MEMORY_THRESHOLD = 2097152; // 2MB

    private $stream;
    private ?int $size;
    private bool $seekable;
    private bool $readable;
    private bool $writable;
    private array $metadata;
    private bool $closed = false;
    private bool $immutable = false;

    public function __construct($stream, array $options = [])
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Stream must be a valid resource');
        }

        $this->stream = $stream;
        $this->size = $options['size'] ?? null;
        $this->immutable = $options['immutable'] ?? false;

        $this->refreshMetadata();
    }

    public static function createFromString(string $content = '', bool $immutable = false): self
    {
        $threshold = strlen($content) > self::MEMORY_THRESHOLD
            ? self::MEMORY_THRESHOLD
            : null;

        $stream = self::createTempStream($threshold);

        if ($content !== '') {
            fwrite($stream, $content);
            rewind($stream);
        }

        return new self($stream, ['size' => strlen($content), 'immutable' => $immutable]);
    }

    public static function createFromFile(string $filename, string $mode = 'r', bool $immutable = false): self
    {
        $stream = @fopen($filename, $mode);
        if ($stream === false) {
            throw new RuntimeException("Unable to open file: {$filename}");
        }

        $size = null;
        if (strpos($mode, 'r') !== false) {
            $stats = fstat($stream);
            $size = $stats['size'] ?? null;
        }

        return new self($stream, ['size' => $size, 'immutable' => $immutable]);
    }

    public static function createFromResource($resource, bool $immutable = false): self
    {
        return new self($resource, ['immutable' => $immutable]);
    }

    protected static function createTempStream(?int $maxMemory = null)
    {
        if ($maxMemory !== null) {
            return fopen("php://temp/maxmemory:{$maxMemory}", 'r+');
        }
        return fopen('php://temp', 'r+');
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }
            return $this->getContents();
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function __clone()
    {
        if (is_resource($this->stream) && $this->isSeekable()) {
            $this->rewind();
            $contents = $this->getContents();
            $copy = self::createFromString($contents);
            $this->stream = $copy->toResource();
            $this->refreshMetadata();
        }
    }

    public function close(): void
    {
        if ($this->closed) return;

        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->detach();
        $this->closed = true;
    }

    public function detach()
    {
        if ($this->closed) return null;

        $this->closed = true;
        $stream = $this->stream;
        $this->stream = null;
        $this->size = null;
        $this->metadata = [];
        $this->readable = false;
        $this->writable = false;
        $this->seekable = false;

        return $stream;
    }

    public function getSize(): ?int
    {
        if ($this->closed) return null;

        if ($this->size !== null) return $this->size;

        $stats = @fstat($this->stream);
        if (is_array($stats) && isset($stats['size'])) {
            $this->size = $stats['size'];
        }

        return $this->size;
    }

    public function tell(): int
    {
        $this->assertNotClosed();
        $result = ftell($this->stream);
        if ($result === false) {
            throw new RuntimeException('Unable to determine stream position');
        }
        return $result;
    }

    public function eof(): bool
    {
        $this->assertNotClosed();
        return feof($this->stream);
    }

    public function isSeekable(): bool
    {
        return !$this->closed && $this->seekable;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->assertNotClosed();
        if (!$this->seekable) {
            throw new RuntimeException('Stream is not seekable');
        }

        if (fseek($this->stream, $offset, $whence) === -1) {
            throw new RuntimeException("Unable to seek to offset $offset");
        }

        $this->refreshMetadata();
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return !$this->closed && $this->writable && !$this->immutable;
    }

    // public function write($string): int
    // {
    //     $this->assertNotClosed();
    //     if (!$this->isWritable()) {
    //         throw new RuntimeException('Stream is not writable');
    //     }

    //     $this->size = null;
    //     $result = fwrite($this->stream, $string);
    //     if ($result === false) {
    //         throw new RuntimeException('Unable to write to stream');
    //     }

    //     $this->refreshMetadata();
    //     return $result;
    // }

    /**
     * Writes data to the stream.
     *
     * @param string $string
     * @return int Number of bytes written.
     * @throws RuntimeException If the stream is not writable or writing fails.
     */
    public function write(string $string): int
    {
        if (!$this->writable) {
            throw new RuntimeException('Stream is not writable.');
        }

        $this->size = null; // Invalidate cached size

        $bytes = @fwrite($this->stream, $string);
        if ($bytes === false) {
            throw new RuntimeException('Unable to write to stream.');
        }

        // Refresh stream size to avoid a redundant fstat() later
        $stats = @fstat($this->stream);
        if ($stats && isset($stats['size'])) {
            $this->size = $stats['size'];
        }

        return $bytes;
    }


    public function isReadable(): bool
    {
        return !$this->closed && $this->readable;
    }

    public function read($length): string
    {
        $this->assertNotClosed();
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream is not readable');
        }

        $result = fread($this->stream, $length);
        if ($result === false) {
            throw new RuntimeException('Unable to read from stream');
        }

        return $result;
    }

    public function getContents(): string
    {
        $this->assertNotClosed();
        if (!$this->readable) {
            throw new RuntimeException('Stream is not readable');
        }

        $contents = stream_get_contents($this->stream);
        if ($contents === false) {
            throw new RuntimeException('Unable to read stream contents');
        }

        return $contents;
    }

    public function getMetadata($key = null)
    {
        if ($this->closed) {
            return $key ? null : [];
        }

        return $key === null ? $this->metadata : ($this->metadata[$key] ?? null);
    }

    public function copyTo(StreamInterface $dest, int $bufferSize = 8192): int
    {
        $this->assertNotClosed();
        if (!$this->readable) {
            throw new RuntimeException('Cannot copy from non-readable stream');
        }

        if (!$dest->isWritable()) {
            throw new RuntimeException('Cannot copy to non-writable stream');
        }

        $bytesCopied = 0;

        if ($this->isSeekable()) {
            $this->rewind();
        }

        while (!$this->eof()) {
            $data = $this->read($bufferSize);
            $dest->write($data);
            $bytesCopied += strlen($data);
        }

        return $bytesCopied;
    }

    public function pipe(StreamInterface $dest, ?int $length = null, int $bufferSize = 8192): int
    {
        $this->assertNotClosed();
        if (!$this->readable || !$dest->isWritable()) {
            throw new RuntimeException('Pipe failed: stream is not readable or destination is not writable');
        }

        if ($this->isSeekable()) {
            $this->rewind();
        }

        $bytesCopied = 0;
        while (!$this->eof()) {
            $remaining = $length !== null ? $length - $bytesCopied : $bufferSize;
            if ($remaining <= 0) break;

            $chunkSize = $length !== null ? min($bufferSize, $remaining) : $bufferSize;
            $data = $this->read($chunkSize);
            $dest->write($data);
            $bytesCopied += strlen($data);
        }

        return $bytesCopied;
    }

    public function getLine(int $length = 1024, string $ending = "\n"): string
    {
        $this->assertNotClosed();
        if (!$this->readable) {
            throw new RuntimeException('Stream is not readable');
        }

        $line = stream_get_line($this->stream, $length, $ending);
        if ($line === false) {
            throw new RuntimeException('Unable to read line from stream');
        }

        return $line;
    }

    public function toResource()
    {
        $this->assertNotClosed();
        return $this->stream;
    }

    /* Private */

    private function assertNotClosed(): void
    {
        if ($this->closed || !is_resource($this->stream)) {
            throw new RuntimeException('Stream is closed');
        }
    }

    private function isReadableMode(string $mode): bool
    {
        return (bool) preg_match('/[r+]/', $mode);
    }

    private function isWritableMode(string $mode): bool
    {
        return (bool) preg_match('/[waxc+]/', $mode);
    }


    private function refreshMetadata(): void
    {
        $this->metadata = stream_get_meta_data($this->stream);
        $this->seekable = $this->metadata['seekable'];
        // $this->readable = in_array($this->metadata['mode'], self::READABLE_MODES);
        // $this->writable = in_array($this->metadata['mode'], self::WRITABLE_MODES);
        $this->readable = $this->isReadableMode($this->metadata['mode'] ?? '');
        $this->writable = $this->isWritableMode($this->metadata['mode'] ?? '');
    }
}

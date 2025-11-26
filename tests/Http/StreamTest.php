<?php

namespace Helix\Tests\Http;

use Helix\Http\Stream;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StreamTest extends TestCase
{
    public function testCanCreateStreamFromString(): void
    {
        $stream = Stream::createFromString('test content');
        $this->assertInstanceOf(Stream::class, $stream);
        $this->assertSame('test content', (string)$stream);
    }

    public function testCanCreateStreamFromEmptyString(): void
    {
        $stream = Stream::createFromString('');
        $this->assertInstanceOf(Stream::class, $stream);
        $this->assertSame('', (string)$stream);
    }

    public function testCanCreateStreamFromFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'helix_test_');
        file_put_contents($tempFile, 'file content');
        
        $stream = Stream::createFromFile($tempFile);
        $this->assertInstanceOf(Stream::class, $stream);
        $this->assertSame('file content', (string)$stream);
        
        unlink($tempFile);
    }

    public function testCreateStreamFromFileThrowsExceptionForMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        Stream::createFromFile('/nonexistent/file.txt');
    }

    public function testCanCreateStreamFromResource(): void
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, 'resource content');
        rewind($resource);
        
        $stream = Stream::createFromResource($resource);
        $this->assertInstanceOf(Stream::class, $stream);
        $this->assertSame('resource content', (string)$stream);
        
        fclose($resource);
    }

    public function testStreamConstructorThrowsExceptionForInvalidResource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Stream('not a resource');
    }

    public function testGetSize(): void
    {
        $content = 'test content';
        $stream = Stream::createFromString($content);
        $this->assertSame(strlen($content), $stream->getSize());
    }

    public function testTell(): void
    {
        $stream = Stream::createFromString('test');
        $this->assertSame(0, $stream->tell());
        
        $stream->read(2);
        $this->assertSame(2, $stream->tell());
    }

    public function testEof(): void
    {
        $stream = Stream::createFromString('test');
        $this->assertFalse($stream->eof());
        
        $stream->read(10);
        $this->assertTrue($stream->eof());
    }

    public function testIsSeekable(): void
    {
        $stream = Stream::createFromString('test');
        $this->assertTrue($stream->isSeekable());
    }

    public function testSeek(): void
    {
        $stream = Stream::createFromString('test content');
        $stream->seek(5);
        $this->assertSame(5, $stream->tell());
        $this->assertSame('content', $stream->getContents());
    }

    public function testSeekThrowsExceptionForNonSeekableStream(): void
    {
        $resource = fopen('php://stdin', 'r');
        if ($resource === false) {
            $this->markTestSkipped('Cannot open stdin');
        }
        
        $stream = new Stream($resource);
        if ($stream->isSeekable()) {
            $this->markTestSkipped('Stream is seekable');
        }
        
        $this->expectException(RuntimeException::class);
        $stream->seek(0);
        
        fclose($resource);
    }

    public function testRewind(): void
    {
        $stream = Stream::createFromString('test');
        $stream->read(2);
        $stream->rewind();
        $this->assertSame(0, $stream->tell());
        $this->assertSame('test', $stream->getContents());
    }

    public function testIsWritable(): void
    {
        $stream = Stream::createFromString('test');
        $this->assertTrue($stream->isWritable());
    }

    public function testWrite(): void
    {
        $stream = Stream::createFromString('');
        $bytesWritten = $stream->write('new content');
        $this->assertSame(11, $bytesWritten); // 'new content' is 11 bytes
        $stream->rewind();
        $this->assertSame('new content', (string)$stream);
    }

    public function testWriteThrowsExceptionForNonWritableStream(): void
    {
        $resource = fopen('php://stdin', 'r');
        if ($resource === false) {
            $this->markTestSkipped('Cannot open stdin');
        }
        
        $stream = new Stream($resource);
        if ($stream->isWritable()) {
            $this->markTestSkipped('Stream is writable');
        }
        
        $this->expectException(RuntimeException::class);
        $stream->write('test');
        
        fclose($resource);
    }

    public function testIsReadable(): void
    {
        $stream = Stream::createFromString('test');
        $this->assertTrue($stream->isReadable());
    }

    public function testRead(): void
    {
        $stream = Stream::createFromString('test content');
        $this->assertSame('test', $stream->read(4));
        $this->assertSame(' cont', $stream->read(5));
    }

    public function testReadThrowsExceptionForNonReadableStream(): void
    {
        $resource = fopen('php://stdout', 'w');
        if ($resource === false) {
            $this->markTestSkipped('Cannot open stdout');
        }
        
        $stream = new Stream($resource);
        if ($stream->isReadable()) {
            $this->markTestSkipped('Stream is readable');
        }
        
        $this->expectException(RuntimeException::class);
        $stream->read(10);
        
        fclose($resource);
    }

    public function testGetContents(): void
    {
        $stream = Stream::createFromString('test content');
        $this->assertSame('test content', $stream->getContents());
    }

    public function testGetContentsFromCurrentPosition(): void
    {
        $stream = Stream::createFromString('test content');
        $stream->read(5);
        $this->assertSame('content', $stream->getContents());
    }

    public function testGetMetadata(): void
    {
        $stream = Stream::createFromString('test');
        $metadata = $stream->getMetadata();
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('mode', $metadata);
    }

    public function testGetMetadataWithKey(): void
    {
        $stream = Stream::createFromString('test');
        $mode = $stream->getMetadata('mode');
        $this->assertIsString($mode);
    }

    public function testClose(): void
    {
        $stream = Stream::createFromString('test');
        $stream->close();
        
        $this->expectException(RuntimeException::class);
        $stream->read(1);
    }

    public function testDetach(): void
    {
        $stream = Stream::createFromString('test');
        $resource = $stream->detach();
        
        $this->assertIsResource($resource);
        $this->expectException(RuntimeException::class);
        $stream->read(1);
        
        fclose($resource);
    }

    public function testToString(): void
    {
        $stream = Stream::createFromString('test content');
        $this->assertSame('test content', (string)$stream);
    }

    public function testCopyTo(): void
    {
        $source = Stream::createFromString('source content');
        $dest = Stream::createFromString('');
        
        $bytesCopied = $source->copyTo($dest);
        
        $this->assertSame(strlen('source content'), $bytesCopied);
        $dest->rewind();
        $this->assertSame('source content', (string)$dest);
    }

    public function testPipe(): void
    {
        $source = Stream::createFromString('source content');
        $dest = Stream::createFromString('');
        
        $bytesCopied = $source->pipe($dest);
        
        $this->assertSame(strlen('source content'), $bytesCopied);
        $dest->rewind();
        $this->assertSame('source content', (string)$dest);
    }

    public function testPipeWithLengthLimit(): void
    {
        $source = Stream::createFromString('source content');
        $dest = Stream::createFromString('');
        
        $bytesCopied = $source->pipe($dest, 5);
        
        $this->assertSame(5, $bytesCopied);
        $dest->rewind();
        $this->assertSame('sourc', (string)$dest);
    }

    public function testGetLine(): void
    {
        $stream = Stream::createFromString("line1\nline2\nline3");
        $this->assertSame('line1', $stream->getLine());
        $this->assertSame('line2', $stream->getLine());
    }

    public function testGetLineWithCustomEnding(): void
    {
        $stream = Stream::createFromString('line1|line2|line3');
        $this->assertSame('line1', $stream->getLine(1024, '|'));
    }

    public function testToResource(): void
    {
        $stream = Stream::createFromString('test');
        $resource = $stream->toResource();
        $this->assertIsResource($resource);
    }

    public function testGetSizeReturnsNullAfterClose(): void
    {
        $stream = Stream::createFromString('test');
        $stream->close();
        $this->assertNull($stream->getSize());
    }

    public function testGetMetadataReturnsEmptyArrayAfterClose(): void
    {
        $stream = Stream::createFromString('test');
        $stream->close();
        $this->assertSame([], $stream->getMetadata());
    }
}

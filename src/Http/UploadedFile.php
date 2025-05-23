<?php

namespace Helix\Http;

use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class UploadedFile implements UploadedFileInterface
{
    private ?string $file;
    private ?int $size;
    private int $error;
    private ?string $clientFilename;
    private ?string $clientMediaType;
    private bool $moved = false;
    private ?StreamInterface $stream = null;

    public function __construct(
        ?string $file,
        ?int $size,
        int $error,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ) {
        if ($error === UPLOAD_ERR_OK && !is_string($file)) {
            throw new \InvalidArgumentException('Invalid file provided for uploaded file');
        }

        if (!in_array($error, array_keys(self::UPLOAD_ERRORS), true)) {
            throw new \InvalidArgumentException('Invalid error status for UploadedFile');
        }

        $this->file = $file;
        $this->size = $size;
        $this->error = $error;
        $this->clientFilename = $clientFilename;
        $this->clientMediaType = $clientMediaType;
    }

    private const UPLOAD_ERRORS = [
        UPLOAD_ERR_OK => 'There is no error, the file uploaded with success',
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
    ];

    public function getStream(): StreamInterface
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(sprintf(
                'Cannot retrieve stream due to upload error: %s',
                self::UPLOAD_ERRORS[$this->error]
            ));
        }

        if ($this->moved) {
            throw new RuntimeException('Cannot retrieve stream after it has already been moved');
        }

        if ($this->stream === null) {
            if ($this->file === null) {
                throw new RuntimeException('No file or stream available');
            }

            if (!is_readable($this->file)) {
                throw new RuntimeException(sprintf('File %s is not readable', $this->file));
            }

            $this->stream = new Stream(fopen($this->file, 'r'));
        }

        return $this->stream;
    }

    public function moveTo($targetPath): void
    {
        if ($this->moved) {
            throw new RuntimeException('Cannot move file; already moved');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(sprintf(
                'Cannot move file due to upload error: %s',
                self::UPLOAD_ERRORS[$this->error]
            ));
        }

        if (!is_string($targetPath) || empty($targetPath)) {
            throw new \InvalidArgumentException('Invalid path provided for move operation');
        }

        $targetDirectory = dirname($targetPath);
        if (!is_dir($targetDirectory)) {
            throw new RuntimeException(sprintf(
                'The target directory "%s" does not exist',
                $targetDirectory
            ));
        }

        if (!is_writable($targetDirectory)) {
            throw new RuntimeException(sprintf(
                'The target directory "%s" is not writable',
                $targetDirectory
            ));
        }

        $sapi = PHP_SAPI;
        if (empty($sapi) || strpos($sapi, 'cli') === 0 || !$this->file) {
            // Non-SAPI environment or CLI, use rename
            $this->writeFile($targetPath);
        } else {
            // SAPI environment, use move_uploaded_file for security
            if (!move_uploaded_file($this->file, $targetPath)) {
                throw new RuntimeException(sprintf(
                    'Error moving uploaded file %s to %s',
                    $this->file,
                    $targetPath
                ));
            }
        }

        $this->moved = true;
    }

    private function writeFile(string $path): void
    {
        $handle = fopen($path, 'wb+');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to write to target path %s', $path));
        }

        $stream = $this->getStream();
        $stream->rewind();

        while (!$stream->eof()) {
            fwrite($handle, $stream->read(4096));
        }

        fclose($handle);
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}
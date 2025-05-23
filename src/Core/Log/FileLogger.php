<?php
namespace Helix\Core\Log;

use RuntimeException;

/**
 * Basic file logger implementation.
 */
class FileLogger implements LoggerInterface
{
    private string $logFile;
    private bool $useLocking;

    public function __construct(string $logFile = 'logs/app.log', bool $useLocking = false)
    {
        $this->logFile = $logFile;
        $this->useLocking = $useLocking;

        // Ensure directory exists
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $message = $this->formatMessage($level, $message, $context);
        $this->writeToFile($message);
    }

    // Convenience methods for each log level
    public function emergency(string $message, array $context = []): void
    {
        $this->log('EMERGENCY', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('ALERT', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('NOTICE', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    private function formatMessage(string $level, string $message, array $context): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context) : '';

        return sprintf(
            "[%s] %s: %s %s\n",
            $timestamp,
            strtoupper($level),
            $message,
            $contextStr
        );
    }

    private function writeToFile(string $message): void
    {
        $flags = FILE_APPEND;
        if ($this->useLocking) {
            $flags |= LOCK_EX;
        }

        if (file_put_contents($this->logFile, $message, $flags) === false) {
            throw new RuntimeException("Failed to write to log file: {$this->logFile}");
        }
    }
}
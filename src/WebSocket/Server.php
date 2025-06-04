<?php
namespace Helix\WebSocket;

use Helix\Core\Log\LoggerInterface as LogLoggerInterface;

class Server
{
    private $socket;
    private array $connections = [];
    private array $handlers = [];
    private bool $running = false;

    public function __construct(
        private LogLoggerInterface $logger,
        private string $host = '0.0.0.0',
        private int $port = 8080,
        private int $maxConnections = 1000
    ) {}

    public function start(): void
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($this->socket, $this->host, $this->port);
        socket_listen($this->socket);

        $this->running = true;
        $this->logger->info("WebSocket server started on ws://{$this->host}:{$this->port}");

        while ($this->running) {
            $this->handleConnections();
            usleep(10000); // 10ms delay to reduce CPU usage
        }
    }

    private function handleConnections(): void
    {
        $read = array_merge([$this->socket], $this->connections);
        $write = $except = null;

        if (socket_select($read, $write, $except, 0) > 0) {
            foreach ($read as $socket) {
                if ($socket === $this->socket) {
                    $this->acceptNewConnection();
                } else {
                    $this->handleClientData($socket);
                }
            }
        }
    }

    private function acceptNewConnection(): void
    {
        if (count($this->connections) >= $this->maxConnections) {
            return;
        }

        $client = socket_accept($this->socket);
        $headers = socket_read($client, 1024);
        
        if ($this->handshake($client, $headers)) {
            $this->connections[] = $client;
            $this->logger->info("New WebSocket connection");
        }
    }

    private function handshake($socket, string $headers): bool
    {
        if (preg_match('/Sec-WebSocket-Key: (.*)\r\n/', $headers, $matches)) {
            $key = base64_encode(sha1(trim($matches[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            
            $response = "HTTP/1.1 101 Switching Protocols\r\n";
            $response .= "Upgrade: websocket\r\n";
            $response .= "Connection: Upgrade\r\n";
            $response .= "Sec-WebSocket-Accept: $key\r\n\r\n";
            
            socket_write($socket, $response, strlen($response));
            return true;
        }
        
        return false;
    }

    private function handleClientData($socket): void
    {
        $data = socket_read($socket, 1024, PHP_BINARY_READ);
        
        if ($data === false || strlen($data) < 2) {
            $this->closeConnection($socket);
            return;
        }

        $decoded = $this->decodeFrame($data);
        $message = json_decode($decoded, true);
        
        if ($message && isset($message['action'])) {
            $this->handleMessage($socket, $message);
        }
    }

    private function decodeFrame(string $data): string
    {
        // Basic WebSocket frame decoding
        $length = ord($data[1]) & 127;
        $maskStart = 2;
        
        if ($length === 126) {
            $maskStart = 4;
        } elseif ($length === 127) {
            $maskStart = 10;
        }
        
        $masks = substr($data, $maskStart, 4);
        $payload = substr($data, $maskStart + 4);
        $decoded = '';
        
        for ($i = 0; $i < strlen($payload); $i++) {
            $decoded .= $payload[$i] ^ $masks[$i % 4];
        }
        
        return $decoded;
    }

    private function encodeFrame(string $data): string
    {
        $length = strlen($data);
        $header = chr(0x81); // FIN + text frame
        
        if ($length <= 125) {
            $header .= chr($length);
        } elseif ($length <= 65535) {
            $header .= chr(126) . pack('n', $length);
        } else {
            $header .= chr(127) . pack('J', $length);
        }
        
        return $header . $data;
    }

    public function registerHandler(string $action, callable $handler): void
    {
        $this->handlers[$action] = $handler;
    }

    private function handleMessage($socket, array $message): void
    {
        if (isset($this->handlers[$message['action']])) {
            $this->handlers[$message['action']]($socket, $message);
        }
    }

    public function send($socket, array $data): void
    {
        $encoded = $this->encodeFrame(json_encode($data));
        socket_write($socket, $encoded, strlen($encoded));
    }

    public function broadcast(array $data, array $exclude = []): void
    {
        $encoded = $this->encodeFrame(json_encode($data));
        
        foreach ($this->connections as $socket) {
            if (!in_array($socket, $exclude, true)) {
                socket_write($socket, $encoded, strlen($encoded));
            }
        }
    }

    private function closeConnection($socket): void
    {
        $index = array_search($socket, $this->connections);
        if ($index !== false) {
            socket_close($socket);
            unset($this->connections[$index]);
            $this->logger->info("WebSocket connection closed");
        }
    }

    public function stop(): void
    {
        $this->running = false;
        foreach ($this->connections as $socket) {
            socket_close($socket);
        }
        socket_close($this->socket);
    }
}

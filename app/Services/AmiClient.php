<?php

namespace App\Services;

use RuntimeException;

class AmiClient
{
    /** @var resource|null */
    private $socket = null;

    /** @var array<string, string> */
    private array $buffer = [];

    private string $partialLine = '';

    private int $frameBytes = 0;

    /** @var list<array<string, string>> */
    private array $pendingFrames = [];

    /** @param array{host: string, port: int, username: string, password: string, timeout: int} $configuration */
    public function connect(array $configuration): void
    {
        $this->disconnect();
        foreach ($configuration as $value) {
            if (is_string($value) && strpbrk($value, "\r\n") !== false) {
                throw new RuntimeException('Invalid AMI configuration.');
            }
        }
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $configuration['host'], $configuration['port']),
            $errorCode,
            $errorMessage,
            $configuration['timeout'],
            STREAM_CLIENT_CONNECT,
            stream_context_create(['socket' => ['so_keepalive' => true]]),
        );

        if ($socket === false) {
            throw new RuntimeException("AMI connection failed ({$errorCode}): {$errorMessage}");
        }

        $this->socket = $socket;
        stream_set_blocking($socket, false);
        $this->readFrame(1);
        $this->sendAction([
            'Action' => 'Login',
            'Username' => $configuration['username'],
            'Secret' => $configuration['password'],
            'Events' => 'on',
        ]);
        $response = $this->readResponse();

        if (($response['Response'] ?? null) !== 'Success') {
            throw new RuntimeException($response['Message'] ?? 'AMI authentication failed.');
        }
    }

    /** @return array<string, string>|null */
    public function readFrame(int $timeoutSeconds = 1): ?array
    {
        if (! is_resource($this->socket)) {
            return null;
        }

        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $line = fgets($this->socket, 8192);

            if ($line === false) {
                if (feof($this->socket)) {
                    throw new RuntimeException('AMI connection closed by the server.');
                }
                usleep(10000);

                continue;
            }

            $this->frameBytes += strlen($line);
            if ($this->frameBytes > 262144) {
                throw new RuntimeException('AMI frame exceeds the size limit.');
            }
            $this->partialLine .= $line;
            if (! str_ends_with($line, "\n")) {
                continue;
            }
            $line = rtrim($this->partialLine, "\r\n");
            $this->partialLine = '';

            if ($line === '') {
                $frame = $this->buffer;
                $this->buffer = [];
                $this->frameBytes = 0;

                if ($frame !== []) {
                    return $frame;
                }

                continue;
            }

            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $this->buffer[trim($key)] = trim($value);
            }
        }

        if (feof($this->socket)) {
            throw new RuntimeException('AMI connection closed by the server.');
        }

        return null;
    }

    /** @return array<string, string>|null */
    public function readResponse(): ?array
    {
        while (true) {
            $frame = $this->readFrame(5);

            if ($frame === null) {
                return null;
            }

            if (isset($frame['Event'])) {
                if (count($this->pendingFrames) >= 10000) {
                    throw new RuntimeException('AMI event buffer limit exceeded during authentication.');
                }

                $this->pendingFrames[] = $frame;

                continue;
            }

            return $frame;
        }
    }

    /** @return array<string, string>|null */
    public function nextFrame(): ?array
    {
        if ($this->pendingFrames !== []) {
            return array_shift($this->pendingFrames);
        }

        $frame = $this->readFrame();

        return $frame;
    }

    /** @param array<string, string> $action */
    public function sendAction(array $action): void
    {
        if (! is_resource($this->socket)) {
            throw new RuntimeException('AMI connection is not open.');
        }

        if (! in_array($action['Action'] ?? '', ['Login', 'Ping', 'Logoff'], true)) {
            throw new RuntimeException('Only read-only AMI actions are allowed.');
        }
        foreach ($action as $key => $value) {
            if (strpbrk($key.$value, "\r\n") !== false) {
                throw new RuntimeException('Invalid AMI action header.');
            }
        }
        $payload = collect($action)
            ->map(fn (string $value, string $key): string => "{$key}: {$value}")
            ->implode("\r\n")."\r\n\r\n";

        $offset = 0;
        $deadline = microtime(true) + 5;
        while ($offset < strlen($payload)) {
            $written = fwrite($this->socket, substr($payload, $offset));
            if ($written === false || microtime(true) >= $deadline) {
                throw new RuntimeException('Unable to write to the AMI connection.');
            }
            $offset += $written;
            if ($written === 0) {
                usleep(10000);
            }
        }
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
        $this->buffer = [];
        $this->pendingFrames = [];
        $this->partialLine = '';
        $this->frameBytes = 0;
    }
}

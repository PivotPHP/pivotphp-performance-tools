<?php

declare(strict_types=1);

namespace PivotPHP\PerformanceTools\Json\Pool;

/**
 * High-performance JSON buffer with memory optimization
 *
 * Provides efficient buffer management for JSON operations with
 * automatic expansion and reuse capabilities.
 *
 * @package PivotPHP\Core\Json\Pool
 * @since 1.1.1
 */
class JsonBuffer
{
    private string $buffer = '';
    private int $capacity;
    private int $position = 0;
    private bool $finalized = false;
    /** @var resource|null */
    private $stream = null;
    private bool $useStream = false;
    private const STREAM_THRESHOLD = 8192; // 8KB threshold for stream usage

    public function __construct(int $initialCapacity = 4096)
    {
        $this->capacity = $initialCapacity;
        $this->buffer = '';
        $this->useStream = $initialCapacity > self::STREAM_THRESHOLD;

        if ($this->useStream) {
            $stream = fopen('php://memory', 'r+');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open memory stream');
            }
            $this->stream = $stream;
        }
    }

    /**
     * Append string data to buffer
     */
    public function append(string $data): void
    {
        $dataLength = strlen($data);
        $requiredLength = $this->position + $dataLength;

        if ($requiredLength > $this->capacity) {
            $this->expand($requiredLength);
        }

        if ($this->useStream && $this->stream !== null) {
            // Use stream for large buffers to avoid string reallocation
            $bytesWritten = @fwrite($this->stream, $data);
            if ($bytesWritten === false) {
                throw new \RuntimeException('Failed to write to stream buffer');
            }
        } else {
            // Use string concatenation for small buffers
            $this->buffer .= $data;
        }

        $this->position += $dataLength;
    }

    /**
     * Append JSON-encoded value to buffer
     */
    public function appendJson(mixed $value, int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): void
    {
        $json = json_encode($value, $flags);
        if ($json === false) {
            throw new \InvalidArgumentException('Failed to encode value as JSON: ' . json_last_error_msg());
        }

        $this->append($json);
    }

    /**
     * Finalize buffer and return complete JSON string
     */
    public function finalize(): string
    {
        if (!$this->finalized) {
            if ($this->useStream && $this->stream !== null) {
                // Read all content from stream
                if (@rewind($this->stream) === false) {
                    throw new \RuntimeException('Failed to rewind stream');
                }
                $content = @stream_get_contents($this->stream);
                if ($content === false) {
                    throw new \RuntimeException('Failed to read from stream');
                }
                $this->buffer = $content;
            }
            $this->finalized = true;
        }

        return $this->buffer;
    }

    /**
     * Reset buffer for reuse
     */
    public function reset(): void
    {
        $this->position = 0;
        $this->finalized = false;
        $this->buffer = '';

        if ($this->useStream && $this->stream !== null) {
            // Reset stream to beginning and truncate
            if (@rewind($this->stream) === false) {
                error_log('Failed to rewind stream during reset');
            }
            if (@ftruncate($this->stream, 0) === false) {
                error_log('Failed to truncate stream during reset');
            }
        }
    }

    /**
     * Clean up resources
     */
    public function __destruct()
    {
        if ($this->stream !== null) {
            fclose($this->stream);
        }
    }

    /**
     * Get buffer capacity
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * Get current buffer size (used bytes)
     */
    public function getSize(): int
    {
        return $this->position;
    }

    /**
     * Get current buffer utilization percentage
     */
    public function getUtilization(): float
    {
        return $this->capacity > 0 ? ($this->position / $this->capacity) * 100 : 0;
    }

    /**
     * Check if buffer has available space
     */
    public function hasSpace(int $requiredBytes): bool
    {
        return ($this->position + $requiredBytes) <= $this->capacity;
    }

    /**
     * Get remaining available space in bytes
     */
    public function getRemainingSpace(): int
    {
        return $this->capacity - $this->position;
    }

    /**
     * Expand buffer capacity when needed
     */
    private function expand(int $requiredCapacity): void
    {
        $newCapacity = max($this->capacity * 2, $requiredCapacity);

        // Check if we need to migrate from string to stream
        if (!$this->useStream && $newCapacity > self::STREAM_THRESHOLD) {
            $this->useStream = true;
            $stream = fopen('php://memory', 'r+');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open memory stream for expansion');
            }
            $this->stream = $stream;

            // Copy existing buffer content to stream
            if (!empty($this->buffer)) {
                $bytesWritten = fwrite($this->stream, $this->buffer);
                if ($bytesWritten === false) {
                    throw new \RuntimeException('Failed to write to stream during migration');
                }
                $this->buffer = ''; // Clear string buffer to save memory
            }
        }

        $this->capacity = $newCapacity;
    }
}

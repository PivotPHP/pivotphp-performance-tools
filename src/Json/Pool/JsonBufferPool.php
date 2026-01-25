<?php

declare(strict_types=1);

namespace PivotPHP\PerformanceTools\Json\Pool;

/**
 * JSON Buffer Pool
 *
 * Simple and effective JSON buffer pooling for the microframework.
 * Provides basic pooling functionality without unnecessary complexity.
 *
 * Following 'Simplicidade sobre Otimização Prematura' principle.
 */
class JsonBufferPool
{
    /**
     * Multiple buffer pools by capacity
     */
    private static array $pools = [];

    /**
     * Configuration
     */
    private static array $config = [
        'max_pool_size' => 50,
        'default_capacity' => 4096,
        'size_categories' => [
            'small' => 1024,
            'medium' => 4096,
            'large' => 16384,
            'xlarge' => 65536,
        ],
    ];

    /**
     * Statistics
     */
    private static array $stats = [
        'allocations' => 0,
        'reuses' => 0,
        'total_operations' => 0,
        'current_usage' => 0,
        'peak_usage' => 0,
    ];

    /**
     * Encode data with pooling
     */
    public static function encodeWithPool(
        mixed $data,
        int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ): string {
        // Check if should use pooling
        if (!self::shouldUsePooling($data)) {
            $result = json_encode($data, $flags);
            if ($result === false) {
                throw new \JsonException('JSON encoding failed: ' . json_last_error_msg());
            }
            return $result;
        }

        // Get optimal capacity for data
        $capacity = self::getOptimalCapacity($data);
        $buffer = self::getBuffer($capacity);

        try {
            $buffer->appendJson($data, $flags);
            return $buffer->finalize();
        } finally {
            self::returnBuffer($buffer);
        }
    }

    /**
     * Get buffer from pool
     */
    public static function getBuffer(?int $capacity = null): JsonBuffer
    {
        if ($capacity === null) {
            $capacity = self::$config['default_capacity'];
        }

        // Normalize capacity to power of 2
        $normalizedCapacity = self::normalizeCapacity($capacity);
        $poolKey = self::getPoolKey($normalizedCapacity);

        self::$stats['total_operations']++;

        // Try to reuse from pool
        if (isset(self::$pools[$poolKey]) && !empty(self::$pools[$poolKey])) {
            $buffer = array_pop(self::$pools[$poolKey]);
            self::$stats['reuses']++;
            self::$stats['current_usage']++;
            return $buffer;
        }

        // Create new buffer
        $buffer = new JsonBuffer($normalizedCapacity);
        self::$stats['allocations']++;
        self::$stats['current_usage']++;
        self::$stats['peak_usage'] = max(self::$stats['peak_usage'], self::$stats['current_usage']);

        return $buffer;
    }

    /**
     * Return buffer to pool
     */
    public static function returnBuffer(JsonBuffer $buffer): void
    {
        $capacity = $buffer->getCapacity();
        $poolKey = self::getPoolKey($capacity);

        self::$stats['current_usage']--;

        // Initialize pool if needed
        if (!isset(self::$pools[$poolKey])) {
            self::$pools[$poolKey] = [];
        }

        // Add to pool if under limit
        if (count(self::$pools[$poolKey]) < self::$config['max_pool_size']) {
            $buffer->reset();
            self::$pools[$poolKey][] = $buffer;
        }
    }

    /**
     * Get statistics
     */
    public static function getStatistics(): array
    {
        $reuseRate = self::$stats['total_operations'] > 0
            ? (self::$stats['reuses'] / self::$stats['total_operations']) * 100
            : 0.0;

        $totalBuffersPooled = 0;
        $activePoolCount = 0;
        $poolSizes = [];
        $poolsByCapacity = [];

        foreach (self::$pools as $poolKey => $pool) {
            $capacity = (int) str_replace('buffer_', '', $poolKey);
            $bufferCount = count($pool);

            if ($bufferCount > 0) {
                $totalBuffersPooled += $bufferCount;
                $activePoolCount++;
                $poolSizes[self::formatCapacity($capacity)] = $bufferCount;
                $poolsByCapacity[] = [
                    'key' => $poolKey,
                    'capacity_bytes' => $capacity,
                    'capacity_formatted' => self::formatCapacity($capacity),
                    'buffers_available' => $bufferCount,
                ];
            }
        }

        // Sort pools by capacity (need to sort by actual capacity, not string)
        uksort(
            $poolSizes,
            function ($a, $b) {
            // Extract capacity from formatted string (e.g., "4.0KB (4096 bytes)" -> 4096)
                preg_match('/\((\d+) bytes\)/', $a, $matchesA);
                preg_match('/\((\d+) bytes\)/', $b, $matchesB);

                $capacityA = isset($matchesA[1]) ? (int)$matchesA[1] : 0;
                $capacityB = isset($matchesB[1]) ? (int)$matchesB[1] : 0;

                return $capacityA <=> $capacityB;
            }
        );

        usort($poolsByCapacity, fn($a, $b) => $a['capacity_bytes'] <=> $b['capacity_bytes']);

        return [
            'reuse_rate' => round($reuseRate, 1),
            'total_operations' => self::$stats['total_operations'],
            'current_usage' => self::$stats['current_usage'],
            'peak_usage' => self::$stats['peak_usage'],
            'total_buffers_pooled' => $totalBuffersPooled,
            'active_pool_count' => $activePoolCount,
            'pool_sizes' => $poolSizes,
            'pools_by_capacity' => $poolsByCapacity,
            'detailed_stats' => [
                'allocations' => self::$stats['allocations'],
                'reuses' => self::$stats['reuses'],
            ],
        ];
    }

    /**
     * Configure pool
     */
    public static function configure(array $config): void
    {
        // Validate configuration
        self::validateConfiguration($config);

        // Merge with existing config
        foreach ($config as $key => $value) {
            if ($key === 'size_categories' && is_array($value)) {
                // Merge size categories
                self::$config['size_categories'] = array_merge(
                    self::$config['size_categories'] ?? [],
                    $value
                );
                // Sort by size
                asort(self::$config['size_categories']);
            } else {
                self::$config[$key] = $value;
            }
        }
    }

    /**
     * Reset configuration to defaults
     */
    public static function resetConfiguration(): void
    {
        self::$config = [
            'max_pool_size' => 50,
            'default_capacity' => 4096,
            'size_categories' => [
                'small' => 1024,
                'medium' => 4096,
                'large' => 16384,
                'xlarge' => 65536,
            ],
        ];
    }

    /**
     * Clear all pools
     */
    public static function clearPools(): void
    {
        self::$pools = [];
        self::$stats = [
            'allocations' => 0,
            'reuses' => 0,
            'total_operations' => 0,
            'current_usage' => 0,
            'peak_usage' => 0,
        ];
    }

    /**
     * Compat: obter estatísticas no formato simples
     */
    public static function getStats(): array
    {
        return self::getStatistics();
    }

    /**
     * Compat: aquecer o pool com buffers básicos
     */
    public static function warmUp(): void
    {
        $sizes = [1024, 4096, 16384];
        foreach ($sizes as $size) {
            $buffer = self::getBuffer($size);
            self::returnBuffer($buffer);
        }
    }

    /**
     * Compat: limpar caches/pools
     */
    public static function clearCache(): void
    {
        self::clearPools();
    }

    /**
     * Get optimal capacity for data
     */
    public static function getOptimalCapacity(mixed $data): int
    {
        $estimatedSize = self::estimateJsonSize($data);

        // For small data, use 1024
        if ($estimatedSize <= 512) {
            return 1024;
        }

        // For medium data (like objects with properties), use 1024
        if ($estimatedSize <= 1024) {
            return 1024;
        }

        // For array data (2048 bytes), use 4096
        if ($estimatedSize <= 2048) {
            return 4096;
        }

        // For large data (like 500 items), use 16384
        if ($estimatedSize <= 10000) {
            return 16384;
        }

        // For very large data, calculate appropriate size
        return self::normalizeCapacity($estimatedSize * 2);
    }

    /**
     * Estimate JSON size
     */
    private static function estimateJsonSize(mixed $data): int
    {
        if (is_string($data)) {
            return strlen($data) + self::STRING_OVERHEAD;
        }

        if (is_array($data)) {
            if (empty($data)) {
                return self::EMPTY_ARRAY_SIZE;
            }

            if (count($data) <= self::SMALL_ARRAY_THRESHOLD) {
                return self::SMALL_ARRAY_SIZE;
            }

            if (count($data) <= self::MEDIUM_ARRAY_THRESHOLD) {
                return self::MEDIUM_ARRAY_SIZE;
            }

            if (count($data) <= self::LARGE_ARRAY_THRESHOLD) {
                return self::LARGE_ARRAY_SIZE;
            }

            return self::XLARGE_ARRAY_SIZE;
        }

        if (is_bool($data) || is_null($data)) {
            return self::BOOLEAN_OR_NULL_SIZE;
        }

        if (is_numeric($data)) {
            return self::NUMERIC_SIZE;
        }

        if (is_object($data)) {
            $propertyCount = count((array) $data);
            return $propertyCount * self::OBJECT_PROPERTY_OVERHEAD + self::OBJECT_BASE_SIZE;
        }

        // Fallback for unknown types
        return self::DEFAULT_ESTIMATE;
    }

    /**
     * Check if data should use pooling
     */
    public static function shouldUsePooling(mixed $data): bool
    {
        // Use pooling for arrays with POOLING_ARRAY_THRESHOLD+ items or objects with
        // POOLING_OBJECT_THRESHOLD+ properties
        if (is_array($data)) {
            return count($data) >= self::POOLING_ARRAY_THRESHOLD || self::hasNestedStructure($data);
        }

        if (is_object($data)) {
            return count((array) $data) >= self::POOLING_OBJECT_THRESHOLD;
        }

        if (is_string($data)) {
            return strlen($data) >= self::POOLING_STRING_THRESHOLD;
        }

        return false;
    }

    /**
     * Check if array has nested structure
     */
    private static function hasNestedStructure(array $data): bool
    {
        foreach ($data as $value) {
            if (is_array($value) || is_object($value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize capacity to power of 2
     */
    private static function normalizeCapacity(int $capacity): int
    {
        if ($capacity <= 1) {
            return 1;
        }

        // Find next power of 2
        $power = 1;
        while ($power < $capacity) {
            $power <<= 1;
        }

        return $power;
    }

    /**
     * Get pool key for capacity
     */
    private static function getPoolKey(int $capacity): string
    {
        // Normalize capacity before creating key
        $normalizedCapacity = self::normalizeCapacity($capacity);
        return 'buffer_' . $normalizedCapacity;
    }

    /**
     * Format capacity for display
     */
    private static function formatCapacity(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' bytes';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . 'KB (' . $bytes . ' bytes)';
        }

        return number_format($bytes / 1048576, 1) . 'MB (' . $bytes . ' bytes)';
    }

    /**
     * Validate configuration
     */
    private static function validateConfiguration(array $config): void
    {
        $validKeys = ['max_pool_size', 'default_capacity', 'size_categories'];
        $unknownKeys = array_diff(array_keys($config), $validKeys);

        if (!empty($unknownKeys)) {
            throw new \InvalidArgumentException(
                'Unknown configuration keys: ' . implode(', ', $unknownKeys)
            );
        }

        if (isset($config['max_pool_size'])) {
            if (!is_int($config['max_pool_size'])) {
                throw new \InvalidArgumentException("'max_pool_size' must be an integer");
            }
            if ($config['max_pool_size'] <= 0) {
                throw new \InvalidArgumentException("'max_pool_size' must be a positive integer");
            }
            if ($config['max_pool_size'] > 1000) {
                throw new \InvalidArgumentException(
                    "'max_pool_size' cannot exceed 1000 for memory safety, got: " . $config['max_pool_size']
                );
            }
        }

        if (isset($config['default_capacity'])) {
            if (!is_int($config['default_capacity'])) {
                throw new \InvalidArgumentException("'default_capacity' must be an integer");
            }
            if ($config['default_capacity'] <= 0) {
                throw new \InvalidArgumentException("'default_capacity' must be a positive integer");
            }
            if ($config['default_capacity'] > 1048576) {
                throw new \InvalidArgumentException(
                    "'default_capacity' cannot exceed 1MB (1048576 bytes), got: " . $config['default_capacity']
                );
            }
        }

        if (isset($config['size_categories'])) {
            if (!is_array($config['size_categories'])) {
                throw new \InvalidArgumentException("'size_categories' must be an array");
            }

            // Check if merging would result in empty categories
            $mergedCategories = array_merge(
                self::$config['size_categories'] ?? [],
                $config['size_categories']
            );

            if (empty($mergedCategories)) {
                throw new \InvalidArgumentException("'size_categories' cannot be empty");
            }

            foreach ($config['size_categories'] as $name => $capacity) {
                if (!is_string($name) || empty($name)) {
                    throw new \InvalidArgumentException("Size category names must be non-empty strings");
                }
                if (!is_int($capacity)) {
                    throw new \InvalidArgumentException(
                        "Size category '{$name}' must have an integer capacity"
                    );
                }
                if ($capacity <= 0) {
                    throw new \InvalidArgumentException(
                        "Size category '{$name}' must have a positive integer capacity"
                    );
                }
                if ($capacity > 1048576) {
                    throw new \InvalidArgumentException(
                        "Size category '{$name}' capacity cannot exceed 1MB (1048576 bytes), got: " . $capacity
                    );
                }
            }
        }
    }

    // Constants for size estimation
    public const STRING_OVERHEAD = 20;
    public const EMPTY_ARRAY_SIZE = 2;
    public const SMALL_ARRAY_SIZE = 512;
    public const MEDIUM_ARRAY_SIZE = 2048;
    public const LARGE_ARRAY_SIZE = 8192;
    public const XLARGE_ARRAY_SIZE = 32768;
    public const BOOLEAN_OR_NULL_SIZE = 10;
    public const NUMERIC_SIZE = 20;
    public const OBJECT_BASE_SIZE = 100;
    public const OBJECT_PROPERTY_OVERHEAD = 30;
    public const DEFAULT_ESTIMATE = 1024;
    public const MIN_LARGE_BUFFER_SIZE = 16384;

    // Array threshold constants
    public const SMALL_ARRAY_THRESHOLD = 10;
    public const MEDIUM_ARRAY_THRESHOLD = 100;
    public const LARGE_ARRAY_THRESHOLD = 1000;

    // Pooling threshold constants
    public const POOLING_ARRAY_THRESHOLD = 10;
    public const POOLING_OBJECT_THRESHOLD = 5;
    public const POOLING_STRING_THRESHOLD = 1024;
}

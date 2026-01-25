<?php

declare(strict_types=1);

namespace PivotPHP\PerformanceTools\Utils;

/**
 * Serialization Cache
 *
 * Simple and effective serialization caching for the microframework.
 * Provides basic caching functionality without unnecessary complexity.
 *
 * Following 'Simplicidade sobre Otimização Prematura' principle.
 */
class SerializationCache
{
    /**
     * Cache storage
     */
    private static array $cache = [];

    /**
     * Size cache for optimized lookups
     */
    private static array $sizeCache = [];

    /**
     * Hash cache for large arrays
     */
    private static array $hashCache = [];

    /**
     * Statistics
     */
    private static array $stats = [
        'cache_hits' => 0,
        'cache_misses' => 0,
    ];

    /**
     * Maximum cache size
     */
    private static int $maxCacheSize = 1000;

    /**
     * Cache an item
     */
    public static function set(string $key, mixed $value): void
    {
        self::$cache[$key] = serialize($value);
        self::evictIfNeeded();
    }

    /**
     * Get cached item
     */
    public static function get(string $key): mixed
    {
        if (!isset(self::$cache[$key])) {
            return null;
        }

        return unserialize(self::$cache[$key]);
    }

    /**
     * Check if item exists in cache
     */
    public static function has(string $key): bool
    {
        return isset(self::$cache[$key]);
    }

    /**
     * Remove item from cache
     */
    public static function remove(string $key): void
    {
        unset(self::$cache[$key]);
        unset(self::$sizeCache[$key]);
        unset(self::$hashCache[$key]);
    }

    /**
     * Clear all cache
     */
    public static function clear(): void
    {
        self::$cache = [];
        self::$sizeCache = [];
        self::$hashCache = [];
        self::$stats = [
            'cache_hits' => 0,
            'cache_misses' => 0,
        ];
    }

    /**
     * Get cache size
     */
    public static function size(): int
    {
        return count(self::$cache);
    }

    /**
     * Get all cache keys
     */
    public static function keys(): array
    {
        return array_keys(self::$cache);
    }

    /**
     * Get serialized size of cached item or data
     */
    public static function getSerializedSize(mixed $keyOrData, ?string $key = null): int
    {
        // If called with data and key (legacy usage)
        if ($key !== null) {
            // Use the provided key with data hash for consistency
            $dataHash = md5(serialize($keyOrData));
            $cacheKey = $key . '_' . $dataHash;
            $data = $keyOrData;
        } else {
            // Generate cache key for data
            $cacheKey = self::generateCacheKey($keyOrData);
            $data = $keyOrData;
        }

        // Check size cache first
        if (isset(self::$sizeCache[$cacheKey])) {
            self::$stats['cache_hits']++;
            return self::$sizeCache[$cacheKey];
        }

        // Cache miss - calculate size and cache both size and data
        self::$stats['cache_misses']++;
        $serializedData = serialize($data);
        $size = strlen($serializedData);

        // Cache the size and the serialized data
        self::$sizeCache[$cacheKey] = $size;
        self::$cache[$cacheKey] = $serializedData;

        // Evict if needed
        self::evictIfNeeded();

        return $size;
    }

    /**
     * Clear cache (alias for clear)
     */
    public static function clearCache(): void
    {
        self::clear();
    }

    /**
     * Get total serialized size of all cached items or calculate for provided data
     */
    public static function getTotalSerializedSize(mixed $data = null, ?array $keys = null): int
    {
        // If data is provided with custom keys, calculate total size for that data
        if ($data !== null && $keys !== null) {
            if (is_array($data)) {
                $total = 0;
                foreach ($data as $index => $item) {
                    $key = $keys[$index] ?? "auto_key_{$index}";
                    $total += self::getSerializedSize($item, $key);
                }
                return $total;
            } else {
                $key = $keys[0] ?? "auto_key_0";
                return self::getSerializedSize($data, $key);
            }
        }

        // If data is provided, calculate total size for that data
        if ($data !== null) {
            if (is_array($data)) {
                $total = 0;
                foreach ($data as $item) {
                    $total += strlen(serialize($item));
                }
                return $total;
            } else {
                return strlen(serialize($data));
            }
        }

        // Otherwise, return total size of all cached items
        $total = 0;
        foreach (self::$cache as $cachedData) {
            $total += strlen($cachedData);
        }
        return $total;
    }

    /**
     * Get serialized data for a key
     */
    public static function getSerializedData(mixed $keyOrData): ?string
    {
        // Handle both string keys and data objects
        if (is_string($keyOrData)) {
            return self::$cache[$keyOrData] ?? null;
        }

        // For data objects, generate cache key and serialize if not in cache
        $cacheKey = self::generateCacheKey($keyOrData);
        if (!isset(self::$cache[$cacheKey])) {
            // Cache miss - serialize and cache the data
            self::$stats['cache_misses']++;
            $serialized = serialize($keyOrData);
            self::$cache[$cacheKey] = $serialized;
            self::evictIfNeeded();
            return $serialized;
        }

        // Cache hit
        self::$stats['cache_hits']++;
        return self::$cache[$cacheKey];
    }

    /**
     * Set maximum cache size
     */
    public static function setMaxCacheSize(int $size): void
    {
        self::$maxCacheSize = $size;
        self::evictIfNeeded();
    }

    /**
     * Get cache statistics
     */
    public static function getStats(): array
    {
        $totalOps = (int) self::$stats['cache_hits'] + (int) self::$stats['cache_misses'];
        $hitRate = $totalOps > 0 ? ((float)self::$stats['cache_hits'] / $totalOps) * 100 : 0.0;

        return [
            'size' => self::size(),
            'total_serialized_size' => self::getTotalSerializedSize(),
            'keys' => self::keys(),
            'cache_entries' => self::size(),
            'size_cache_entries' => count(self::$sizeCache),
            'hash_cache_entries' => count(self::$hashCache),
            'cache_hits' => self::$stats['cache_hits'],
            'cache_misses' => self::$stats['cache_misses'],
            'hit_rate_percent' => round($hitRate, 2),
            'memory_usage' => self::formatBytes(self::getTotalSerializedSize()),
        ];
    }

    /**
     * Generate cache key for data
     */
    private static function generateCacheKey(mixed $data): string
    {
        // For objects, include object ID to differentiate instances
        if (is_object($data)) {
            $objectId = spl_object_hash($data);
            $serialized = serialize($data);
            $hash = md5($objectId . $serialized);
        } else {
            // For non-objects, use serialization hash to ensure uniqueness
            $serialized = serialize($data);
            $hash = md5($serialized);
        }

        // For large arrays, track in hash cache
        if (is_array($data) && count($data) > 100) {
            self::$hashCache[$hash] = true;
        }

        return $hash;
    }

    /**
     * Evict cache entries if over limit
     */
    private static function evictIfNeeded(): void
    {
        if (count(self::$cache) > self::$maxCacheSize) {
            // Simple FIFO eviction
            $key = array_key_first(self::$cache);
            if ($key === null) {
                return; // No key to evict
            }
            if (is_string($key)) {
                self::remove($key);
            }
        }
    }

    /**
     * Format bytes to human readable string
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return round($bytes / 1048576, 2) . ' MB';
        }
    }
}

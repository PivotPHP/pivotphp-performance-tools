<?php

declare(strict_types=1);

namespace PivotPHP\PerformanceTools\Http\Psr7\Pool;

/**
 * Header Object Pool for Performance Optimization
 *
 * Reduces header processing overhead by reusing normalized header objects
 * and caching common header name/value combinations.
 *
 * @package PivotPHP\Core\Http\Psr7\Pool
 * @since 2.1.1
 */
class HeaderPool
{
    /**
     * Pool of reusable header combinations
     *
     * @var array<string, array<string>>
     */
    private static array $headerPool = [];

    /**
     * Cache of normalized header names
     *
     * @var array<string, string>
     */
    private static array $normalizedNames = [];

    /**
     * Cache of validated header values
     *
     * @var array<string, array<string>>
     */
    private static array $validatedValues = [];

    /**
     * Maximum pool size to prevent memory leaks
     */
    private const MAX_POOL_SIZE = 1000;

    /**
     * Common headers that should always be pooled
     */
    private const COMMON_HEADERS = [
        'content-type' => true,
        'content-length' => true,
        'authorization' => true,
        'accept' => true,
        'user-agent' => true,
        'host' => true,
        'connection' => true,
        'cache-control' => true,
        'accept-encoding' => true,
        'accept-language' => true,
    ];

    /**
     * Access times for LRU eviction
     *
     * @var array<string, float>
     */
    private static array $accessTimes = [];

    /**
     * Usage frequency counter
     *
     * @var array<string, int>
     */
    private static array $usageFrequency = [];

    /**
     * Performance metrics
     *
     * @var array<string, int>
     */
    private static array $metrics = [
        'hits' => 0,
        'misses' => 0,
        'evictions' => 0,
        'smart_evictions' => 0
    ];

    /**
     * Get normalized header name from cache with LRU tracking
     */
    public static function getNormalizedName(string $name): string
    {
        if (isset(self::$normalizedNames[$name])) {
            // Cache hit - update access time and frequency
            self::$accessTimes[$name] = microtime(true);
            self::$usageFrequency[$name] = (self::$usageFrequency[$name] ?? 0) + 1;
            self::$metrics['hits']++;
            return self::$normalizedNames[$name];
        }

        // Cache miss
        self::$metrics['misses']++;

        if (count(self::$normalizedNames) >= self::MAX_POOL_SIZE) {
            self::smartEviction('normalized_names');
        }

        $normalized = strtolower($name);
        self::$normalizedNames[$name] = $normalized;
        self::$accessTimes[$name] = microtime(true);
        self::$usageFrequency[$name] = 1;

        return $normalized;
    }

    /**
     * Get header values from pool or create new
     *
     * @param string $name
     * @param string|array<string> $value
     * @return array<string>
     */
    public static function getHeaderValues(string $name, $value): array
    {
        $normalized = self::getNormalizedName($name);
        $valueArray = is_array($value) ? $value : [$value];
        $key = $normalized . ':' . serialize($valueArray);

        if (!isset(self::$headerPool[$key])) {
            if (count(self::$headerPool) >= self::MAX_POOL_SIZE) {
                self::clearOldEntries();
            }
            self::$headerPool[$key] = $valueArray;
        }

        // Update access time for LRU
        self::$accessTimes[$key] = microtime(true);

        return self::$headerPool[$key];
    }

    /**
     * Get validated header values (with strict validation)
     *
     * @param string $name
     * @param string|array<string> $value
     * @return array<string>
     * @throws \InvalidArgumentException
     */
    public static function getValidatedHeaderValues(string $name, $value): array
    {
        $key = $name . ':' . serialize($value);

        if (!isset(self::$validatedValues[$key])) {
            if (count(self::$validatedValues) >= self::MAX_POOL_SIZE) {
                self::clearValidatedCache();
            }

            // Validate header name
            if (!preg_match('/^[a-zA-Z0-9\'`#$%&*+.^_|~!-]+$/', $name)) {
                throw new \InvalidArgumentException("Invalid header name: {$name}");
            }

            // Normalize and validate values
            $valueArray = is_array($value) ? $value : [$value];

            if (empty($valueArray)) {
                throw new \InvalidArgumentException('Header value cannot be empty');
            }

            $validated = array_map(
                function ($v) {
                    $v = (string) $v;

                    if (preg_match('/[^\x09\x0A\x0D\x20-\x7E\x80-\xFE]/', $v) > 0) {
                        throw new \InvalidArgumentException('Header value contains invalid characters');
                    }

                    return trim($v, " \t");
                },
                $valueArray
            );

            self::$validatedValues[$key] = $validated;
        }

        return self::$validatedValues[$key];
    }

    /**
     * Check if header should be pooled based on commonality
     */
    public static function shouldPool(string $name): bool
    {
        $normalized = strtolower($name);
        return isset(self::COMMON_HEADERS[$normalized]);
    }

    /**
     * Clear old entries to prevent memory leaks
     */
    private static function clearOldEntries(): void
    {
        // Keep only common headers and recent entries
        $toKeep = [];
        foreach (self::$headerPool as $key => $value) {
            $headerName = explode(':', $key)[0];
            if (isset(self::COMMON_HEADERS[$headerName])) {
                $toKeep[$key] = $value;
            }
        }

        // Keep only half of the entries to make room
        self::$headerPool = array_slice($toKeep, 0, (int) (self::MAX_POOL_SIZE / 2), true);

        // Same for normalized names
        $commonNames = [];
        foreach (self::$normalizedNames as $original => $normalized) {
            if (isset(self::COMMON_HEADERS[$normalized])) {
                $commonNames[$original] = $normalized;
            }
        }
        self::$normalizedNames = array_slice($commonNames, 0, (int) (self::MAX_POOL_SIZE / 2), true);
    }

    /**
     * Clear validated values cache
     */
    private static function clearValidatedCache(): void
    {
        self::$validatedValues = array_slice(
            self::$validatedValues,
            0,
            (int) (self::MAX_POOL_SIZE / 2),
            true
        );
    }

    /**
     * Smart eviction using LRU and frequency analysis
     */
    private static function smartEviction(string $type): void
    {
        $evictionCount = (int) (self::MAX_POOL_SIZE * 0.2); // Remove 20%

        if ($type === 'normalized_names') {
            $candidates = self::getLRUCandidates(self::$normalizedNames);
        } else {
            $candidates = self::getLRUCandidates(self::$headerPool);
        }

        $toEvict = array_slice($candidates, 0, $evictionCount);

        foreach ($toEvict as $key) {
            if ($type === 'normalized_names') {
                unset(self::$normalizedNames[$key]);
            } else {
                unset(self::$headerPool[$key]);
            }
            unset(self::$accessTimes[$key], self::$usageFrequency[$key]);
        }

        self::$metrics['smart_evictions'] += count($toEvict);
        self::$metrics['evictions']++;
    }

    /**
     * Get LRU candidates sorted by access time and frequency
     */
    private static function getLRUCandidates(array $pool): array
    {
        $scores = [];
        $currentTime = microtime(true);

        foreach (array_keys($pool) as $key) {
            $accessTime = self::$accessTimes[$key] ?? 0;
            $frequency = self::$usageFrequency[$key] ?? 1;

            // Score based on recency and frequency (lower = more likely to evict)
            $timeSinceAccess = $currentTime - $accessTime;
            $score = $timeSinceAccess / max($frequency, 1);

            // Protect common headers
            if (isset(self::COMMON_HEADERS[strtolower($key)])) {
                $score *= 0.1; // Much less likely to evict
            }

            $scores[$key] = $score;
        }

        // Sort by score (highest first = oldest/least frequent)
        arsort($scores);

        return array_keys($scores);
    }

    /**
     * Get detailed performance metrics
     */
    public static function getDetailedMetrics(): array
    {
        $totalRequests = self::$metrics['hits'] + self::$metrics['misses'];
        $hitRate = $totalRequests > 0 ? (self::$metrics['hits'] / $totalRequests) * 100 : 0;

        // Calculate most/least used headers
        arsort(self::$usageFrequency);
        $mostUsed = array_slice(self::$usageFrequency, 0, 5, true);

        asort(self::$usageFrequency);
        $leastUsed = array_slice(self::$usageFrequency, 0, 5, true);

        return [
            'cache_hit_rate' => round($hitRate, 2),
            'total_hits' => self::$metrics['hits'],
            'total_misses' => self::$metrics['misses'],
            'evictions_performed' => self::$metrics['evictions'],
            'items_evicted' => self::$metrics['smart_evictions'],
            'current_pool_sizes' => [
                'header_pool' => count(self::$headerPool),
                'normalized_names' => count(self::$normalizedNames),
                'validated_values' => count(self::$validatedValues)
            ],
            'most_used_headers' => $mostUsed,
            'least_used_headers' => $leastUsed,
            'memory_efficiency' => self::calculateMemoryEfficiency()
        ];
    }

    /**
     * Calculate memory efficiency metrics
     */
    private static function calculateMemoryEfficiency(): array
    {
        $totalItems = count(self::$headerPool) + count(self::$normalizedNames) + count(self::$validatedValues);
        $totalTracking = count(self::$accessTimes) + count(self::$usageFrequency);

        return [
            'total_cached_items' => $totalItems,
            'tracking_overhead_items' => $totalTracking,
            'efficiency_ratio' => $totalTracking > 0 ? round($totalItems / $totalTracking, 2) : 0,
            'estimated_memory_saved' => self::estimateMemorySaved()
        ];
    }

    /**
     * Estimate memory saved through caching
     */
    private static function estimateMemorySaved(): string
    {
        $cacheHits = self::$metrics['hits'];
        $avgHeaderSize = 64; // bytes per header operation
        $savedBytes = $cacheHits * $avgHeaderSize;

        if ($savedBytes < 1024) {
            return $savedBytes . ' B';
        } elseif ($savedBytes < 1048576) {
            return round($savedBytes / 1024, 2) . ' KB';
        } else {
            return round($savedBytes / 1048576, 2) . ' MB';
        }
    }

    /**
     * Get pool statistics for monitoring
     *
     * @return array<string, int>
     */
    public static function getStats(): array
    {
        return [
            'header_pool_size' => count(self::$headerPool),
            'normalized_names_size' => count(self::$normalizedNames),
            'validated_values_size' => count(self::$validatedValues),
            'max_pool_size' => self::MAX_POOL_SIZE,
            'hits' => self::$metrics['hits'],
            'misses' => self::$metrics['misses'],
            'evictions' => self::$metrics['evictions'],
            'smart_evictions' => self::$metrics['smart_evictions'],
        ];
    }

    /**
     * Clear all pools (useful for testing)
     */
    public static function clearAll(): void
    {
        self::$headerPool = [];
        self::$normalizedNames = [];
        self::$validatedValues = [];
        self::$accessTimes = [];
        self::$usageFrequency = [];
        self::$metrics = [
            'hits' => 0,
            'misses' => 0,
            'evictions' => 0,
            'smart_evictions' => 0
        ];
    }

    /**
     * Warm up pool with common headers
     */
    public static function warmUp(): void
    {
        $commonCombinations = [
            ['Content-Type', 'application/json'],
            ['Content-Type', 'text/html; charset=utf-8'],
            ['Content-Type', 'text/plain'],
            ['Content-Type', 'application/x-www-form-urlencoded'],
            ['Authorization', 'Bearer token'],
            ['Accept', 'application/json'],
            ['Accept', '*/*'],
            ['User-Agent', 'Mozilla/5.0'],
            ['Connection', 'keep-alive'],
            ['Connection', 'close'],
            ['Cache-Control', 'no-cache'],
            ['Cache-Control', 'public, max-age=3600'],
        ];

        foreach ($commonCombinations as [$name, $value]) {
            self::getHeaderValues($name, $value);
            self::getNormalizedName($name);
        }
    }
}

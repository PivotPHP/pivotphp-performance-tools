<?php

declare(strict_types=1);

namespace PivotPHP\PerformanceTools\Http\Psr7\Pool;

use PivotPHP\Core\Http\Psr7\Stream;

/**
 * Enhanced Stream Pool with Size-Based Optimization
 *
 * Optimizes stream pooling by categorizing streams by buffer size
 * and implementing LRU eviction for better memory management.
 *
 * @package PivotPHP\Core\Http\Psr7\Pool
 * @since 2.2.0
 */
class EnhancedStreamPool
{
    /**
     * Stream pools by size category
     */
    private static array $streamPools = [
        'small' => [],   // < 1KB
        'medium' => [],  // 1KB - 10KB
        'large' => [],   // 10KB - 100KB
        'xlarge' => []   // > 100KB
    ];

    /**
     * Access timestamps for LRU eviction
     */
    private static array $accessTimes = [];

    /**
     * Stream usage statistics
     */
    private static array $stats = [
        'hits' => ['small' => 0, 'medium' => 0, 'large' => 0, 'xlarge' => 0],
        'misses' => ['small' => 0, 'medium' => 0, 'large' => 0, 'xlarge' => 0],
        'created' => ['small' => 0, 'medium' => 0, 'large' => 0, 'xlarge' => 0],
        'evicted' => ['small' => 0, 'medium' => 0, 'large' => 0, 'xlarge' => 0]
    ];

    /**
     * Maximum pools sizes per category
     */
    private const MAX_POOL_SIZES = [
        'small' => 100,
        'medium' => 50,
        'large' => 20,
        'xlarge' => 5
    ];

    /**
     * Size thresholds in bytes
     */
    private const SIZE_THRESHOLDS = [
        'small' => 1024,        // 1KB
        'medium' => 10240,      // 10KB
        'large' => 102400,      // 100KB
    ];

    /**
     * Get or create a stream from the appropriate pool
     */
    public static function getStream(int $expectedSize = 0): Stream
    {
        $category = self::getSizeCategory($expectedSize);
        $streamId = self::generateStreamId($category);

        // Try to get from pool
        if (!empty(self::$streamPools[$category])) {
            $stream = array_pop(self::$streamPools[$category]);
            self::updateAccessTime($streamId);
            self::$stats['hits'][$category]++;

            // Reset stream position
            $stream->rewind();
            return $stream;
        }

        // Create new stream
        self::$stats['misses'][$category]++;
        self::$stats['created'][$category]++;

        return self::createOptimizedStream($expectedSize, $category);
    }

    /**
     * Return a stream to the appropriate pool
     */
    public static function returnStream(Stream $stream): void
    {
        $size = $stream->getSize() ?? 0;
        $category = self::getSizeCategory($size);

        // Check if pool has space
        if (count(self::$streamPools[$category]) >= self::MAX_POOL_SIZES[$category]) {
            self::evictLRU($category);
        }

        // Reset stream for reuse
        $stream->rewind();

        // Store in pool
        $streamId = self::generateStreamId($category);
        self::$streamPools[$category][] = $stream;
        self::updateAccessTime($streamId);
    }

    /**
     * Alias para compatibilidade com StreamPoolInterface
     */
    public static function releaseStream(Stream $stream): void
    {
        self::returnStream($stream);
    }

    /**
     * Determine size category based on expected or actual size
     */
    private static function getSizeCategory(int $size): string
    {
        if ($size <= self::SIZE_THRESHOLDS['small']) {
            return 'small';
        } elseif ($size <= self::SIZE_THRESHOLDS['medium']) {
            return 'medium';
        } elseif ($size <= self::SIZE_THRESHOLDS['large']) {
            return 'large';
        } else {
            return 'xlarge';
        }
    }

    /**
     * Create optimized stream based on expected size
     */
    private static function createOptimizedStream(int $expectedSize, string $category): Stream
    {
        // Use appropriate stream type based on size
        switch ($category) {
            case 'small':
                // Use memory stream for small data
                $resource = fopen('php://memory', 'r+');
                break;

            case 'medium':
                // Use temp stream with smaller buffer
                $resource = fopen('php://temp/maxmemory:' . self::SIZE_THRESHOLDS['small'], 'r+');
                break;

            case 'large':
                // Use temp stream with medium buffer
                $resource = fopen('php://temp/maxmemory:' . self::SIZE_THRESHOLDS['medium'], 'r+');
                break;

            case 'xlarge':
            default:
                // Use file-based temp for large data
                $resource = fopen('php://temp', 'r+');
                break;
        }

        if ($resource === false) {
            throw new \RuntimeException('Unable to create stream resource');
        }

        return new Stream($resource);
    }

    /**
     * Evict least recently used stream from category
     */
    private static function evictLRU(string $category): void
    {
        if (empty(self::$streamPools[$category])) {
            return;
        }

        // Find oldest access time for this category
        $categoryAccessTimes = array_filter(
            self::$accessTimes,
            function ($key) use ($category) {
                return strpos($key, $category . '_') === 0;
            },
            ARRAY_FILTER_USE_KEY
        );

        if (!empty($categoryAccessTimes)) {
            asort($categoryAccessTimes);
            $oldestKey = array_key_first($categoryAccessTimes);
            unset(self::$accessTimes[$oldestKey]);
        }

        // Remove oldest stream from pool
        array_shift(self::$streamPools[$category]);
        self::$stats['evicted'][$category]++;
    }

    /**
     * Generate unique stream ID for tracking
     */
    private static function generateStreamId(string $category): string
    {
        return $category . '_' . uniqid();
    }

    /**
     * Update access time for LRU tracking
     */
    private static function updateAccessTime(string $streamId): void
    {
        self::$accessTimes[$streamId] = microtime(true);
    }

    /**
     * Get pool statistics
     */
    public static function getStats(): array
    {
        $totalHits = array_sum(self::$stats['hits']);
        $totalMisses = array_sum(self::$stats['misses']);
        $totalRequests = $totalHits + $totalMisses;

        $hitRate = $totalRequests > 0 ? ($totalHits / $totalRequests) * 100 : 0;

        return [
            'pool_sizes' => array_map('count', self::$streamPools),
            'max_pool_sizes' => self::MAX_POOL_SIZES,
            'hit_rate' => round($hitRate, 2),
            'total_hits' => $totalHits,
            'total_misses' => $totalMisses,
            'statistics_by_category' => self::$stats,
            'access_times_tracked' => count(self::$accessTimes),
            'memory_usage' => self::calculatePoolMemory()
        ];
    }

    /**
     * Pré-aquecimento opcional do pool para categorias comuns
     */
    public static function warmUp(): void
    {
        // Pequeno conjunto para reduzir custo de criação inicial
        $preloadSizes = [256, 2048, 8192, 32768];
        foreach ($preloadSizes as $size) {
            $stream = self::createOptimizedStream($size, self::getSizeCategory($size));
            self::returnStream($stream);
        }
    }

    /**
     * Calculate approximate memory usage of pools
     */
    private static function calculatePoolMemory(): string
    {
        $totalMemory = 0;

        foreach (self::$streamPools as $category => $streams) {
            $categoryMemory = count($streams) * self::estimateStreamMemory($category);
            $totalMemory += $categoryMemory;
        }

        return \PivotPHP\Core\Utils\Utils::formatBytes($totalMemory);
    }

    /**
     * Estimate memory usage per stream by category
     */
    private static function estimateStreamMemory(string $category): int
    {
        switch ($category) {
            case 'small':
                return 2048; // ~2KB overhead + small buffer
            case 'medium':
                return 12288; // ~12KB overhead + medium buffer
            case 'large':
                return 106496; // ~104KB overhead + large buffer
            case 'xlarge':
                return 524288; // ~512KB estimated for large streams
            default:
                return 2048;
        }
    }

    /**
     * Clear all pools
     */
    public static function clearAll(): void
    {
        self::$streamPools = [
            'small' => [],
            'medium' => [],
            'large' => [],
            'xlarge' => []
        ];
        self::$accessTimes = [];

        // Reset stats
        self::$stats = [
            'hits' => ['small' => 0, 'medium' => 0, 'large' => 0, 'xlarge' => 0],
            'misses' => ['small' => 0, 'medium' => 0, 'large' => 0, 'xlarge' => 0],
            'created' => ['small' => 0, 'medium' => 0, 'large' => 0, 'xlarge' => 0],
            'evicted' => ['small' => 0, 'medium' => 0, 'large' => 0, 'xlarge' => 0]
        ];
    }

    /**
     * Optimize pools by cleaning up unused streams
     */
    public static function optimize(): void
    {
        $currentTime = microtime(true);
        $maxAge = 300; // 5 minutes

        // Remove streams that haven't been accessed recently
        foreach (self::$accessTimes as $streamId => $accessTime) {
            if (($currentTime - $accessTime) > $maxAge) {
                unset(self::$accessTimes[$streamId]);

                // Find and remove the corresponding stream from pools
                $category = explode('_', $streamId)[0];
                if (isset(self::$streamPools[$category]) && !empty(self::$streamPools[$category])) {
                    array_shift(self::$streamPools[$category]);
                    self::$stats['evicted'][$category]++;
                }
            }
        }
    }
}

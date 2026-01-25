<?php

declare(strict_types=1);

namespace PivotPHP\PerformanceTools\Http\Psr7\Pool;

use PivotPHP\Core\Contracts\Psr7\OperationsCacheInterface;
use PivotPHP\Core\Contracts\Psr7\ResponsePoolInterface;
use PivotPHP\Core\Contracts\Psr7\HeaderPoolInterface;
use PivotPHP\Core\Http\Psr7\Cache\OperationsCache;
use PivotPHP\Core\Http\Psr7\Adapters\OperationsCacheAdapter;
use PivotPHP\Core\Http\Psr7\Adapters\ResponsePoolAdapter;
use PivotPHP\Core\Http\Psr7\Adapters\HeaderPoolAdapter;

/**
 * Pool Manager for coordinating all object pools and caches
 *
 * @package PivotPHP\Core\Http\Psr7\Pool
 * @since 2.1.1
 */
class PoolManager
{
    /**
     * Whether pools have been initialized
     */
    private static bool $initialized = false;

    /**
     * Operations cache (injeção)
     */
    private static ?OperationsCacheInterface $operationsCache = null;

    /**
     * Response pool (injeção)
     */
    private static ?ResponsePoolInterface $responsePool = null;

    /**
     * Header pool (injeção)
     */
    private static ?HeaderPoolInterface $headerPool = null;

    /**
     * Pool configuration
     */
    private static array $config = [
        'auto_warm_up' => true,
        'enable_response_pool' => true,
        'enable_header_pool' => true,
        'enable_operations_cache' => true,
        'max_memory_usage' => 50 * 1024 * 1024, // 50MB
    ];

    /**
     * Performance monitoring data
     */
    private static array $stats = [
        'pool_hits' => 0,
        'pool_misses' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
    ];

    /**
     * Initialize all pools and caches
     */
    public static function initialize(array $config = []): void
    {
        if (self::$initialized) {
            return;
        }

        self::$config = array_merge(self::$config, $config);

        if (self::$config['auto_warm_up']) {
            self::warmUpAllPools();
        }

        self::$initialized = true;
    }

    /**
     * Warm up all pools with common objects
     */
    public static function warmUpAllPools(): void
    {
        if (self::$config['enable_response_pool']) {
            self::getResponsePool()->warmUp();
        }

        if (self::$config['enable_header_pool']) {
            self::getHeaderPool()->warmUp();
        }

        if (self::$config['enable_operations_cache']) {
            self::getOperationsCache()->warmUp();
        }
    }

    /**
     * Get comprehensive statistics from all pools
     */
    public static function getStats(): array
    {
        $stats = [
            'initialized' => self::$initialized,
            'config' => self::$config,
            'performance' => self::$stats,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];

        if (self::$config['enable_response_pool']) {
            $stats['response_pool'] = self::getResponsePool()->getStats();
        }

        if (self::$config['enable_header_pool']) {
            $stats['header_pool'] = self::getHeaderPool()->getStats();
        }

        if (self::$config['enable_operations_cache']) {
            $stats['operations_cache'] = self::getOperationsCache()->getStats();
        }

        return $stats;
    }

    /**
     * Clear all pools and caches
     */
    public static function clearAll(): void
    {
        self::getResponsePool()->clearAll();
        self::getHeaderPool()->clearAll();
        self::getOperationsCache()->clearAll();

        self::$stats = [
            'pool_hits' => 0,
            'pool_misses' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
        ];
    }

    /**
     * Perform garbage collection on all pools
     */
    public static function garbageCollect(): array
    {
        $results = [
            'memory_before' => memory_get_usage(true),
            'objects_collected' => 0,
        ];

        // Force PHP garbage collection
        $collected = gc_collect_cycles();
        $results['objects_collected'] += $collected;

        // Pool-specific garbage collection
        if (self::$config['enable_response_pool']) {
            $results['response_objects_collected'] = self::getResponsePool()->garbageCollect();
        }

        $results['memory_after'] = memory_get_usage(true);
        $results['memory_freed'] = $results['memory_before'] - $results['memory_after'];

        return $results;
    }

    /**
     * Check if memory usage is within limits
     */
    public static function checkMemoryUsage(): bool
    {
        $currentUsage = memory_get_usage(true);
        return $currentUsage <= self::$config['max_memory_usage'];
    }

    /**
     * Auto-manage pools based on memory usage
     */
    public static function autoManage(): void
    {
        if (!self::checkMemoryUsage()) {
            // Memory usage too high, perform cleanup
            self::garbageCollect();

            // If still high, clear some caches
            if (self::checkMemoryUsage() === false) {
                self::getOperationsCache()->clearAll();
                self::getHeaderPool()->clearAll();
            }
        }
    }

    /**
     * Setter para operations cache (injeção)
     */
    public static function setOperationsCache(OperationsCacheInterface $cache): void
    {
        self::$operationsCache = $cache;
    }

    /**
     * Getter com fallback para adapter
     */
    private static function getOperationsCache(): OperationsCacheInterface
    {
        return self::$operationsCache ?? new OperationsCacheAdapter();
    }

    /**
     * Getter para response pool com fallback
     */
    private static function getResponsePool(): ResponsePoolInterface
    {
        return self::$responsePool ?? new ResponsePoolAdapter();
    }

    /**
     * Getter para header pool com fallback
     */
    private static function getHeaderPool(): HeaderPoolInterface
    {
        return self::$headerPool ?? new HeaderPoolAdapter();
    }

    /**
     * Setter para response pool (injeção)
     */
    public static function setResponsePool(ResponsePoolInterface $pool): void
    {
        self::$responsePool = $pool;
    }

    /**
     * Setter para header pool (injeção)
     */
    public static function setHeaderPool(HeaderPoolInterface $pool): void
    {
        self::$headerPool = $pool;
    }

    /**
     * Record pool hit
     */
    public static function recordPoolHit(): void
    {
        self::$stats['pool_hits']++;
    }

    /**
     * Record pool miss
     */
    public static function recordPoolMiss(): void
    {
        self::$stats['pool_misses']++;
    }

    /**
     * Record cache hit
     */
    public static function recordCacheHit(): void
    {
        self::$stats['cache_hits']++;
    }

    /**
     * Record cache miss
     */
    public static function recordCacheMiss(): void
    {
        self::$stats['cache_misses']++;
    }

    /**
     * Get pool efficiency metrics
     */
    public static function getEfficiencyMetrics(): array
    {
        $totalHits = is_numeric(self::$stats['pool_hits']) ? self::$stats['pool_hits'] : 0;
        $totalHits += is_numeric(self::$stats['cache_hits']) ? self::$stats['cache_hits'] : 0;

        $totalMisses = is_numeric(self::$stats['pool_misses']) ? self::$stats['pool_misses'] : 0;
        $totalMisses += is_numeric(self::$stats['cache_misses']) ? self::$stats['cache_misses'] : 0;
        $totalRequests = $totalHits + $totalMisses;

        $hitRatio = $totalRequests > 0 ? (float)($totalHits / $totalRequests) * 100 : 0;

        return [
            'hit_ratio' => round($hitRatio, 2),
            'total_requests' => $totalRequests,
            'total_hits' => $totalHits,
            'total_misses' => $totalMisses,
            'pool_hit_ratio' => self::calculateRatio(self::$stats['pool_hits'], self::$stats['pool_misses']),
            'cache_hit_ratio' => self::calculateRatio(self::$stats['cache_hits'], self::$stats['cache_misses']),
        ];
    }

    /**
     * Calculate hit ratio percentage
     */
    private static function calculateRatio(int $hits, int $misses): float
    {
        $total = $hits + $misses;
        return $total > 0 ? round(($hits / $total) * 100, 2) : 0.0;
    }

    /**
     * Get configuration
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * Update configuration
     */
    public static function updateConfig(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * Check if pools are initialized
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Reset initialization state (for testing)
     */
    public static function reset(): void
    {
        self::$initialized = false;
        self::clearAll();
    }
}

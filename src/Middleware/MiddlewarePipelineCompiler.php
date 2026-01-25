<?php

declare(strict_types=1);

namespace PivotPHP\PerformanceTools\Middleware;

use PivotPHP\Core\Http\Request;
use PivotPHP\Core\Http\Response;
use PivotPHP\Core\Utils\Utils;

/**
 * Advanced Middleware Pipeline Compiler
 *
 * Pre-compiles middleware stacks into optimized execution paths
 * to eliminate runtime overhead and improve performance.
 *
 * @package PivotPHP\Core\Middleware
 * @since 2.2.0
 */
class MiddlewarePipelineCompiler
{
    /**
     * Compiled pipeline cache
     *
     * @var array<string, callable>
     */
    private static array $compiledPipelines = [];

    /**
     * Pipeline templates by pattern
     *
     * @var array<string, array>
     */
    private static array $pipelineTemplates = [];

    /**
     * Common middleware combinations with variants
     *
     * @var array<string, array<string>>
     */
    private const COMMON_PATTERNS = [
        'api_auth' => ['cors', 'auth', 'json'],
        'web_basic' => ['cors', 'session', 'csrf'],
        'api_public' => ['cors', 'rate_limit'],
        'admin' => ['cors', 'auth', 'admin_check', 'csrf'],
        'static' => ['cors', 'cache_headers'],
        'api_full' => ['cors', 'auth', 'json', 'validation', 'logging'],
        'web_secure' => ['cors', 'security', 'auth', 'csrf', 'session'],
        'microservice' => ['cors', 'auth', 'json', 'rate_limit'],
        'public_api' => ['cors', 'rate_limit', 'cache', 'json'],
        'admin_secure' => ['cors', 'security', 'auth', 'admin_check', 'csrf', 'logging'],
        'development' => ['cors', 'logging', 'json'],
        'production' => ['cors', 'security', 'rate_limit', 'cache'],
    ];

    /**
     * Dynamic pattern learning cache
     *
     * @var array<string, int>
     */
    private static array $dynamicPatterns = [];

    /**
     * Pattern usage frequency for intelligent caching
     *
     * @var array<string, array>
     */
    private static array $patternUsage = [];

    /**
     * Pipeline compilation statistics
     *
     * @var array<string, int>
     */
    private static array $stats = [
        'pipelines_compiled' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'optimizations_applied' => 0,
        'redundancies_removed' => 0,
        'patterns_learned' => 0,
        'intelligent_matches' => 0,
        'gc_cycles' => 0,
        'memory_reclaimed' => 0
    ];

    /**
     * Compile middleware pipeline into optimized execution path
     */
    public static function compilePipeline(array $middlewares, ?string $cacheKey = null): callable
    {
        // Check for memory pressure before compilation
        self::checkMemoryPressure();

        $cacheKey = $cacheKey ?? self::generateCacheKey($middlewares);

        // Check if already compiled
        if (isset(self::$compiledPipelines[$cacheKey])) {
            self::$stats['cache_hits']++;
            return self::$compiledPipelines[$cacheKey];
        }

        self::$stats['cache_misses']++;

        // Analyze and optimize middleware stack
        $optimizedMiddlewares = self::optimizeMiddlewareStack($middlewares);

        // Detect common patterns with intelligent learning
        $pattern = self::detectPattern($optimizedMiddlewares);

        if ($pattern && isset(self::$pipelineTemplates[$pattern])) {
            // Use pre-compiled template
            $pipeline = self::instantiateTemplate($pattern, $optimizedMiddlewares);
        } else {
            // Compile new pipeline
            $pipeline = self::compileNewPipeline($optimizedMiddlewares, $pattern);
        }

        // Cache compiled pipeline
        self::$compiledPipelines[$cacheKey] = $pipeline;
        self::$stats['pipelines_compiled']++;

        return $pipeline;
    }

    /**
     * Optimize middleware stack by removing redundancies and reordering
     */
    private static function optimizeMiddlewareStack(array $middlewares): array
    {
        $optimized = [];
        $seen = [];

        // Remove duplicates while preserving order
        foreach ($middlewares as $middleware) {
            $hash = self::getMiddlewareHash($middleware);

            if (!isset($seen[$hash])) {
                $optimized[] = $middleware;
                $seen[$hash] = true;
            } else {
                self::$stats['redundancies_removed']++;
            }
        }

        // Reorder for optimal execution
        $optimized = self::reorderMiddlewares($optimized);

        return $optimized;
    }

    /**
     * Reorder middlewares for optimal execution
     */
    private static function reorderMiddlewares(array $middlewares): array
    {
        $priorityMap = [
            'cors' => 100,
            'security' => 90,
            'auth' => 80,
            'rate_limit' => 70,
            'cache' => 60,
            'json' => 50,
            'validation' => 40,
            'logging' => 30,
            'custom' => 20
        ];

        usort(
            $middlewares,
            function ($a, $b) use ($priorityMap) {
                $priorityA = self::getMiddlewarePriority($a, $priorityMap);
                $priorityB = self::getMiddlewarePriority($b, $priorityMap);

                return $priorityB <=> $priorityA; // Higher priority first
            }
        );

        if (count($middlewares) !== count(array_unique(array_map([self::class, 'getMiddlewareHash'], $middlewares)))) {
            self::$stats['optimizations_applied']++;
        }

        return $middlewares;
    }

    /**
     * Get middleware priority for reordering
     *
     * @param mixed $middleware
     */
    private static function getMiddlewarePriority($middleware, array $priorityMap): int
    {
        $type = self::detectMiddlewareType($middleware);
        return $priorityMap[$type] ?? $priorityMap['custom'];
    }

    /**
     * Detect middleware type for optimization
     *
     * @param mixed $middleware
     */
    private static function detectMiddlewareType($middleware): string
    {
        if (is_string($middleware)) {
            $lower = strtolower($middleware);

            if (strpos($lower, 'cors') !== false) {
                return 'cors';
            }
            if (strpos($lower, 'auth') !== false) {
                return 'auth';
            }
            if (strpos($lower, 'security') !== false) {
                return 'security';
            }
            if (strpos($lower, 'rate') !== false) {
                return 'rate_limit';
            }
            if (strpos($lower, 'cache') !== false) {
                return 'cache';
            }
            if (strpos($lower, 'json') !== false) {
                return 'json';
            }
            if (strpos($lower, 'valid') !== false) {
                return 'validation';
            }
            if (strpos($lower, 'log') !== false) {
                return 'logging';
            }
        }

        return 'custom';
    }

    /**
     * Detect common middleware patterns with intelligent learning
     */
    private static function detectPattern(array $middlewares): ?string
    {
        $types = array_map([self::class, 'detectMiddlewareType'], $middlewares);
        $pattern = implode('_', $types);

        // First check exact matches in common patterns
        foreach (self::COMMON_PATTERNS as $patternName => $patternTypes) {
            if (self::arraysMatch($types, $patternTypes)) {
                self::recordPatternUsage($patternName);
                return $patternName;
            }
        }

        // Check learned dynamic patterns
        $dynamicPattern = self::checkDynamicPatterns($types);
        if ($dynamicPattern) {
            self::$stats['intelligent_matches']++;
            return $dynamicPattern;
        }

        // Check for partial matches with higher threshold for frequently used patterns
        foreach (self::COMMON_PATTERNS as $patternName => $patternTypes) {
            $threshold = self::getAdaptiveThreshold($patternName);
            if (self::arraysPartialMatch($types, $patternTypes, $threshold)) {
                self::recordPatternUsage($patternName . '_variant');
                return $patternName . '_variant';
            }
        }

        // Learn new pattern if it appears frequently
        self::learnNewPattern($types);

        return null;
    }

    /**
     * Record pattern usage for intelligent caching
     */
    private static function recordPatternUsage(string $pattern): void
    {
        if (!isset(self::$patternUsage[$pattern])) {
            self::$patternUsage[$pattern] = [
                'count' => 0,
                'last_used' => time(),
                'avg_performance' => 0,
                'cache_efficiency' => 0
            ];
        }

        self::$patternUsage[$pattern]['count']++;
        self::$patternUsage[$pattern]['last_used'] = time();
    }

    /**
     * Check against learned dynamic patterns
     */
    private static function checkDynamicPatterns(array $types): ?string
    {
        $signature = md5(implode('|', $types));

        if (isset(self::$dynamicPatterns[$signature]) && self::$dynamicPatterns[$signature] >= 3) {
            return 'dynamic_' . substr($signature, 0, 8);
        }

        return null;
    }

    /**
     * Learn new patterns from usage
     */
    private static function learnNewPattern(array $types): void
    {
        if (count($types) >= 3 && count($types) <= 8) { // Only learn reasonable patterns
            $signature = md5(implode('|', $types));

            if (!isset(self::$dynamicPatterns[$signature])) {
                self::$dynamicPatterns[$signature] = 0;
            }

            self::$dynamicPatterns[$signature]++;

            if (self::$dynamicPatterns[$signature] === 3) {
                self::$stats['patterns_learned']++;
            }
        }
    }

    /**
     * Get adaptive threshold based on pattern popularity
     */
    private static function getAdaptiveThreshold(string $pattern): float
    {
        $baseThreshold = 0.8;

        if (isset(self::$patternUsage[$pattern])) {
            $usage = self::$patternUsage[$pattern];
            $popularityBonus = min(0.15, $usage['count'] * 0.01);
            return max(0.65, $baseThreshold - $popularityBonus);
        }

        return $baseThreshold;
    }

    /**
     * Check if arrays match exactly
     */
    private static function arraysMatch(array $a, array $b): bool
    {
        return count($a) === count($b) && array_diff($a, $b) === array_diff($b, $a);
    }

    /**
     * Check if arrays partially match (threshold-based)
     */
    private static function arraysPartialMatch(array $a, array $b, float $threshold): bool
    {
        $intersection = array_intersect($a, $b);
        $union = array_unique(array_merge($a, $b));

        return count($union) > 0 && (count($intersection) / count($union)) >= $threshold;
    }

    /**
     * Compile new optimized pipeline
     */
    private static function compileNewPipeline(array $middlewares, ?string $pattern): callable
    {
        // For small pipelines, use simple execution
        if (count($middlewares) <= 2) {
            return self::compileSimplePipeline($middlewares);
        }

        // For larger pipelines, create optimized nested structure
        return self::compileOptimizedPipeline($middlewares, $pattern);
    }

    /**
     * Compile simple pipeline for small middleware stacks
     */
    private static function compileSimplePipeline(array $middlewares): callable
    {
        return function (Request $request, Response $response, callable $next) use ($middlewares) {
            $index = 0;

            $runner = function ($req, $res) use (&$runner, &$index, $middlewares, $next) {
                if ($index >= count($middlewares)) {
                    return $next($req, $res);
                }

                $middleware = $middlewares[$index++];
                return $middleware($req, $res, $runner);
            };

            return $runner($request, $response);
        };
    }

    /**
     * Compile optimized pipeline with performance enhancements
     */
    private static function compileOptimizedPipeline(array $middlewares, ?string $pattern): callable
    {
        // Pre-calculate middleware execution order
        $executionPlan = self::createExecutionPlan($middlewares);

        return function (Request $request, Response $response, callable $next) use ($executionPlan, $pattern) {
            // Fast path for common patterns
            if ($pattern && method_exists(self::class, 'execute' . ucfirst($pattern) . 'Pattern')) {
                $method = 'execute' . ucfirst($pattern) . 'Pattern';
                return self::$method($request, $response, $next, $executionPlan);
            }

            // Standard optimized execution
            return self::executeOptimizedPlan($request, $response, $next, $executionPlan);
        };
    }

    /**
     * Create execution plan for middleware stack
     */
    private static function createExecutionPlan(array $middlewares): array
    {
        $plan = [];

        foreach ($middlewares as $index => $middleware) {
            $plan[] = [
                'middleware' => $middleware,
                'index' => $index,
                'type' => self::detectMiddlewareType($middleware),
                'can_cache' => self::canCacheMiddleware($middleware),
                'is_terminal' => self::isTerminalMiddleware($middleware)
            ];
        }

        return $plan;
    }

    /**
     * Execute optimized middleware plan
     */
    private static function executeOptimizedPlan(
        Request $request,
        Response $response,
        callable $next,
        array $plan
    ): mixed {
        $index = 0;

        $runner = function ($req, $res) use (&$runner, &$index, $plan, $next) {
            if ($index >= count($plan)) {
                return $next($req, $res);
            }

            $step = $plan[$index++];
            $middleware = $step['middleware'];

            // Apply middleware-specific optimizations
            if ($step['can_cache'] && self::hasCachedResult($step, $req)) {
                return self::getCachedResult($step, $req, $res, $runner);
            }

            return $middleware($req, $res, $runner);
        };

        return $runner($request, $response);
    }

    /**
     * Check if middleware can be cached
     *
     * @param mixed $middleware
     */
    private static function canCacheMiddleware($middleware): bool
    {
        $type = self::detectMiddlewareType($middleware);

        // CORS, security headers, etc. can be cached
        return in_array($type, ['cors', 'security', 'cache']);
    }

    /**
     * Check if middleware is terminal (doesn't call next)
     *
     * @param mixed $middleware
     */
    private static function isTerminalMiddleware($middleware): bool
    {
        $type = self::detectMiddlewareType($middleware);

        // Auth failures, rate limiting can be terminal
        return in_array($type, ['auth', 'rate_limit']);
    }

    /**
     * Check for cached middleware result
     */
    private static function hasCachedResult(array $step, Request $request): bool
    {
        // Simple implementation - could be enhanced
        return false;
    }

    /**
     * Get cached middleware result
     */
    private static function getCachedResult(
        array $step,
        Request $request,
        Response $response,
        callable $next
    ): mixed {
        // Placeholder for cached execution
        return $next($request, $response);
    }

    /**
     * Generate cache key for middleware stack
     */
    private static function generateCacheKey(array $middlewares): string
    {
        $hashes = array_map([self::class, 'getMiddlewareHash'], $middlewares);
        return 'pipeline_' . md5(implode('|', $hashes));
    }

    /**
     * Get hash for middleware (for deduplication)
     *
     * @param mixed $middleware
     */
    private static function getMiddlewareHash($middleware): string
    {
        if (is_string($middleware)) {
            return $middleware;
        } elseif (is_array($middleware)) {
            return serialize($middleware);
        } elseif (is_object($middleware)) {
            return get_class($middleware) . spl_object_hash($middleware);
        } elseif (is_callable($middleware)) {
            return 'callable_' . md5(serialize($middleware));
        } else {
            return 'unknown_' . md5(serialize($middleware));
        }
    }

    /**
     * Pre-compile common middleware patterns
     */
    public static function preCompileCommonPatterns(): void
    {
        foreach (self::COMMON_PATTERNS as $patternName => $middlewareTypes) {
            // Create dummy middlewares for pattern
            $middlewares = array_map(
                function ($type) {
                    return function ($req, $res, $next) {
                        // Dummy middleware
                        return $next($req, $res);
                    };
                },
                $middlewareTypes
            );

            /** @var array<callable> $template */
            $template = self::compileNewPipeline($middlewares, $patternName);
            self::$pipelineTemplates[$patternName] = $template;
        }
    }

    /**
     * Instantiate template with actual middlewares
     */
    private static function instantiateTemplate(string $pattern, array $middlewares): callable
    {
        // For now, compile normally - could be enhanced with actual template instantiation
        return self::compileNewPipeline($middlewares, $pattern);
    }

    /**
     * Get compilation statistics
     */
    public static function getStats(): array
    {
        $totalRequests = self::$stats['cache_hits'] + self::$stats['cache_misses'];
        $hitRate = $totalRequests > 0 ? (self::$stats['cache_hits'] / $totalRequests) * 100 : 0;

        // Calculate pattern efficiency with defensive check
        $totalPatterns = count(self::COMMON_PATTERNS) + count(self::$dynamicPatterns);
        $activePatterns = count(
            array_filter(
                self::$patternUsage,
                function ($usage) {
                    return isset($usage['count']) && $usage['count'] > 0;
                }
            )
        );

        // Use ternary to avoid division by zero (though totalPatterns is always > 0)
        $patternEfficiency = round((float)($activePatterns / $totalPatterns) * 100, 2);

        return [
            'compiled_pipelines' => count(self::$compiledPipelines),
            'pipeline_templates' => count(self::$pipelineTemplates),
            'cache_hit_rate' => round($hitRate, 2),
            'optimizations_applied' => self::$stats['optimizations_applied'],
            'redundancies_removed' => self::$stats['redundancies_removed'],
            'patterns_learned' => self::$stats['patterns_learned'],
            'intelligent_matches' => self::$stats['intelligent_matches'],
            'gc_cycles' => self::$stats['gc_cycles'],
            'memory_reclaimed' => Utils::formatBytes(self::$stats['memory_reclaimed']),
            'pattern_efficiency' => $patternEfficiency,
            'dynamic_patterns' => count(self::$dynamicPatterns),
            'memory_usage' => self::calculateMemoryUsage(),
            'detailed_stats' => self::$stats,
            'pattern_usage' => self::$patternUsage
        ];
    }

    /**
     * Calculate memory usage of compiled pipelines
     */
    private static function calculateMemoryUsage(): string
    {
        $baseSize = 0;

        // Count size based on number of compiled pipelines
        $baseSize += count(self::$compiledPipelines) * 1024; // Estimate 1KB per pipeline
        $baseSize += count(self::$pipelineTemplates) * 512;   // Estimate 512B per template

        // Add size of basic data structures that can be serialized safely
        $safeData = [
            'templates' => array_keys(self::$pipelineTemplates),
            'pipelines' => array_keys(self::$compiledPipelines),
            'stats' => self::$stats
        ];

        $baseSize += strlen(serialize($safeData));

        return Utils::formatBytes($baseSize);
    }

    /**
     * Clear all compiled pipelines
     */
    public static function clearAll(): void
    {
        self::$compiledPipelines = [];
        self::$pipelineTemplates = [];

        self::$stats = [
            'pipelines_compiled' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'optimizations_applied' => 0,
            'redundancies_removed' => 0,
            'patterns_learned' => 0,
            'intelligent_matches' => 0,
            'gc_cycles' => 0,
            'memory_reclaimed' => 0
        ];
    }

    /**
     * Warm up common patterns
     */
    public static function warmUp(): void
    {
        self::preCompileCommonPatterns();
    }

    /**
     * Compat: obter pipeline compilado pelo cache key
     */
    public static function getCompiledPipeline(string $cacheKey): ?callable
    {
        return self::$compiledPipelines[$cacheKey] ?? null;
    }

    /**
     * Compat: limpar cache mantendo semântica histórica
     */
    public static function clearCache(): void
    {
        self::clearAll();
    }

    /**
     * Intelligent garbage collection for pipeline cache
     */
    public static function performIntelligentGC(): array
    {
        $initialCount = count(self::$compiledPipelines);
        $initialMemory = memory_get_usage();
        $gcStats = [
            'pipelines_before' => $initialCount,
            'pipelines_removed' => 0,
            'memory_freed' => 0,
            'patterns_optimized' => 0
        ];

        if ($initialCount < 50) {
            return $gcStats; // Don't GC if cache is small
        }

        $currentTime = time();
        $removalCandidates = [];

        // Analyze pattern usage and identify removal candidates
        foreach (self::$patternUsage as $pattern => $usage) {
            $timeSinceLastUse = $currentTime - $usage['last_used'];
            $usageFrequency = $usage['count'];

            // Calculate removal score (higher = more likely to remove)
            $removalScore = 0;

            if ($timeSinceLastUse > 3600) { // 1 hour
                $removalScore += 30;
            } elseif ($timeSinceLastUse > 1800) { // 30 minutes
                $removalScore += 15;
            }

            if ($usageFrequency < 5) {
                $removalScore += 20;
            } elseif ($usageFrequency < 2) {
                $removalScore += 40;
            }

            if ($removalScore > 35) {
                $removalCandidates[] = $pattern;
            }
        }

        // Remove pipelines for low-value patterns
        $removedCount = 0;
        foreach (self::$compiledPipelines as $key => $pipeline) {
            foreach ($removalCandidates as $candidate) {
                if (strpos($key, $candidate) !== false) {
                    unset(self::$compiledPipelines[$key]);
                    $removedCount++;
                    break;
                }
            }
        }

        // Clean up unused dynamic patterns
        foreach (self::$dynamicPatterns as $signature => $count) {
            if ($count < 2 && isset(self::$patternUsage['dynamic_' . substr($signature, 0, 8)])) {
                $usage = self::$patternUsage['dynamic_' . substr($signature, 0, 8)];
                if (($currentTime - $usage['last_used']) > 1800) { // 30 minutes
                    unset(self::$dynamicPatterns[$signature]);
                    unset(self::$patternUsage['dynamic_' . substr($signature, 0, 8)]);
                    $gcStats['patterns_optimized']++;
                }
            }
        }

        $finalMemory = memory_get_usage();
        $gcStats['pipelines_removed'] = $removedCount;
        $gcStats['memory_freed'] = max(0, $initialMemory - $finalMemory);

        self::$stats['gc_cycles']++;
        self::$stats['memory_reclaimed'] += $gcStats['memory_freed'];

        return $gcStats;
    }

    /**
     * Auto-trigger garbage collection based on memory pressure
     */
    private static function checkMemoryPressure(): void
    {
        $currentMemory = memory_get_usage();
        $memoryLimit = self::getMemoryLimit();

        if ($memoryLimit > 0 && ($currentMemory / $memoryLimit) > 0.8) {
            self::performIntelligentGC();
        } elseif (count(self::$compiledPipelines) > 200) {
            self::performIntelligentGC();
        }
    }

    /**
     * Get PHP memory limit in bytes
     */
    private static function getMemoryLimit(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return 0; // No limit
        }

        $value = (int) $memoryLimit;
        $unit = strtolower(substr($memoryLimit, -1));

        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }
}

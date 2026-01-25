<?php

declare(strict_types=1);

namespace PivotPHP\PerformanceTools\Http\Psr7\Cache;

/**
 * Operations Cache for Performance Optimization
 *
 * Caches frequently used operations like route patterns,
 * header validations, and JSON encoding.
 *
 * @package PivotPHP\Core\Http\Psr7\Cache
 * @since 2.1.1
 */
class OperationsCache
{
    /**
     * Cache for compiled regex patterns
     *
     * @var array<string, string>
     */
    private static array $compiledPatterns = [];

    /**
     * Cache for JSON encoding results
     *
     * @var array<string, string>
     */
    private static array $jsonCache = [];

    /**
     * Cache for route parameter extractions
     *
     * @var array<string, array>
     */
    private static array $parameterCache = [];

    /**
     * Cache for header name validations
     *
     * @var array<string, bool>
     */
    private static array $headerValidationCache = [];

    /**
     * Cache for MIME type lookups
     *
     * @var array<string, string>
     */
    private static array $mimeTypeCache = [];

    /**
     * Maximum cache size per type
     */
    private const MAX_CACHE_SIZE = 500;

    /**
     * Common MIME types for fast lookup
     */
    private const COMMON_MIME_TYPES = [
        'json' => 'application/json',
        'html' => 'text/html; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml'
    ];

    /**
     * Get or compile route pattern
     */
    public static function getCompiledPattern(string $pattern): string
    {
        if (isset(self::$compiledPatterns[$pattern])) {
            return self::$compiledPatterns[$pattern];
        }

        if (count(self::$compiledPatterns) >= self::MAX_CACHE_SIZE) {
            self::evictOldEntries('patterns');
        }

        // Convert route pattern to regex
        $compiled = self::compileRoutePattern($pattern);
        self::$compiledPatterns[$pattern] = $compiled;

        return $compiled;
    }

    /**
     * Get cached JSON or encode and cache
     */
    public static function getCachedJson(array $data, ?string $key = null): string
    {
        // Generate cache key if not provided
        if ($key === null) {
            $key = md5(serialize($data));
        }

        if (isset(self::$jsonCache[$key])) {
            return self::$jsonCache[$key];
        }

        if (count(self::$jsonCache) >= self::MAX_CACHE_SIZE) {
            self::evictOldEntries('json');
        }

        $json = json_encode($data);
        if ($json === false) {
            throw new \InvalidArgumentException('Unable to encode data as JSON');
        }

        self::$jsonCache[$key] = $json;
        return $json;
    }

    /**
     * Get cached route parameters
     */
    public static function getCachedParameters(string $pattern, string $path): ?array
    {
        $key = $pattern . '|' . $path;

        if (isset(self::$parameterCache[$key])) {
            return self::$parameterCache[$key];
        }

        if (count(self::$parameterCache) >= self::MAX_CACHE_SIZE) {
            self::evictOldEntries('parameters');
        }

        $params = self::extractParameters($pattern, $path);
        if (is_array($params)) {
            self::$parameterCache[$key] = $params;
        }

        return $params;
    }

    /**
     * Compat helper: fetch cached parameters by cache key
     */
    public static function getCachedParameter(string $cacheKey): mixed
    {
        return self::$parameterCache[$cacheKey] ?? null;
    }

    /**
     * Check if header name is valid (cached)
     */
    public static function isValidHeaderName(string $name): bool
    {
        if (isset(self::$headerValidationCache[$name])) {
            return self::$headerValidationCache[$name];
        }

        if (count(self::$headerValidationCache) >= self::MAX_CACHE_SIZE) {
            self::evictOldEntries('headers');
        }

        $isValid = preg_match('/^[a-zA-Z0-9\'`#$%&*+.^_|~!-]+$/', $name) === 1;
        self::$headerValidationCache[$name] = $isValid;

        return $isValid;
    }

    /**
     * Get MIME type by extension (cached)
     */
    public static function getMimeType(string $extension): string
    {
        $extension = strtolower($extension);

        if (isset(self::COMMON_MIME_TYPES[$extension])) {
            return self::COMMON_MIME_TYPES[$extension];
        }

        if (isset(self::$mimeTypeCache[$extension])) {
            return self::$mimeTypeCache[$extension];
        }

        // Fallback to system lookup
        $mimeType = self::systemMimeLookup($extension);
        self::$mimeTypeCache[$extension] = $mimeType;

        return $mimeType;
    }

    /**
     * Warm up cache with common patterns
     */
    public static function warmUp(): void
    {
        // Common route patterns
        $commonPatterns = [
            '/api/users/{id}',
            '/api/users/{id}/posts',
            '/api/posts/{id}',
            '/admin/users/{id}',
            '/products/{category}/{id}',
            '/files/{*path}'
        ];

        foreach ($commonPatterns as $pattern) {
            self::getCompiledPattern($pattern);
        }

        // Common JSON responses
        $commonResponses = [
            ['success' => true],
            ['success' => false],
            ['error' => 'Not found'],
            ['error' => 'Unauthorized'],
            ['error' => 'Internal server error']
        ];

        foreach ($commonResponses as $response) {
            self::getCachedJson($response);
        }

        // Common header names
        $commonHeaders = [
            'Content-Type', 'Authorization', 'Accept', 'User-Agent',
            'Host', 'Connection', 'Cache-Control', 'Accept-Encoding'
        ];

        foreach ($commonHeaders as $header) {
            self::isValidHeaderName($header);
        }
    }

    /**
     * Get cache statistics
     */
    public static function getStats(): array
    {
        return [
            'compiled_patterns' => count(self::$compiledPatterns),
            'json_cache' => count(self::$jsonCache),
            'parameter_cache' => count(self::$parameterCache),
            'header_validation_cache' => count(self::$headerValidationCache),
            'mime_type_cache' => count(self::$mimeTypeCache),
            'max_cache_size' => self::MAX_CACHE_SIZE
        ];
    }

    /**
     * Clear all caches
     */
    public static function clearAll(): void
    {
        self::$compiledPatterns = [];
        self::$jsonCache = [];
        self::$parameterCache = [];
        self::$headerValidationCache = [];
        self::$mimeTypeCache = [];
    }

    /**
     * Compile route pattern to regex
     */
    private static function compileRoutePattern(string $routePattern): string
    {
        // Escape special regex characters except our markers
        $pattern = preg_quote($routePattern, '/');

        // Convert {param} to named capture groups
        $pattern = preg_replace('/\\\{([^}]+)\\\}/', '(?P<$1>[^/]+)', $pattern);
        if ($pattern === null) {
            return '/^' . preg_quote($routePattern, '/') . '$/';
        }

        // Convert {*param} to greedy capture groups
        $pattern = preg_replace('/\\\{\\\*([^}]+)\\\}/', '(?P<$1>.*)', $pattern);
        if ($pattern === null) {
            return '/^' . preg_quote($routePattern, '/') . '$/';
        }

        return '/^' . $pattern . '$/';
    }

    /**
     * Extract parameters from path using pattern
     */
    private static function extractParameters(string $pattern, string $path): ?array
    {
        $regex = self::getCompiledPattern($pattern);

        if (preg_match($regex, $path, $matches)) {
            // Remove numeric keys, keep only named captures
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return null;
    }

    /**
     * System MIME type lookup
     */
    private static function systemMimeLookup(string $extension): string
    {
        // Fallback for unknown extensions
        return 'application/octet-stream';
    }

    /**
     * Evict old entries from cache
     */
    private static function evictOldEntries(string $type): void
    {
        $newSize = self::MAX_CACHE_SIZE / 2;

        switch ($type) {
            case 'patterns':
                self::$compiledPatterns = array_slice(self::$compiledPatterns, -$newSize, null, true);
                break;
            case 'json':
                self::$jsonCache = array_slice(self::$jsonCache, -$newSize, null, true);
                break;
            case 'parameters':
                self::$parameterCache = array_slice(self::$parameterCache, -$newSize, null, true);
                break;
            case 'headers':
                self::$headerValidationCache = array_slice(self::$headerValidationCache, -$newSize, null, true);
                break;
        }
    }
}

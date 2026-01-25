<?php

declare(strict_types=1);

namespace PivotPHP\PerformanceTools\Http\Psr7\Pool;

use PivotPHP\Core\Http\Psr7\Response;
use PivotPHP\Core\Http\Psr7\Stream;

/**
 * Response Object Pool for Performance Optimization
 *
 * Reduces object allocation overhead by reusing Response objects
 * for common HTTP responses.
 *
 * @package PivotPHP\Core\Http\Psr7\Pool
 * @since 2.1.1
 */
class ResponsePool
{
    /**
     * Pool of available response objects
     *
     * @var array<string, array<Response>>
     */
    private static array $pool = [];

    /**
     * Pool of available stream objects
     *
     * @var Stream[]
     */
    private static array $streamPool = [];

    /**
     * Maximum pool size per status code
     */
    private const MAX_POOL_SIZE = 50;

    /**
     * Maximum stream pool size
     */
    private const MAX_STREAM_POOL_SIZE = 100;

    /**
     * Track active objects to prevent memory leaks
     *
     * @var Response[]
     */
    private static array $activeObjects = [];    /**
                                                  * Get a response object from pool or create new
                                                  */
    public static function getResponse(int $status = 200): Response
    {
        $poolKey = "status_{$status}";

        if (isset(self::$pool[$poolKey]) && !empty(self::$pool[$poolKey])) {
            $response = array_pop(self::$pool[$poolKey]);
            if ($response instanceof Response) {
                $response->reset($status);
                // Track the reused response object for GC
                self::$activeObjects[spl_object_id($response)] = $response;
                return $response;
            }
        }

        // Create new response with simple stream
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create stream');
        }
        $streamObj = new Stream($stream);
        $response = new Response($status, [], $streamObj);

        // Track the new response object for GC
        self::$activeObjects[spl_object_id($response)] = $response;

        return $response;
    }

    /**
     * Get a stream object from pool or create new
     */
    public static function getStream(string $content = ''): Stream
    {
        if (!empty(self::$streamPool)) {
            $stream = array_pop(self::$streamPool);
            $stream->rewind();
            $stream->write($content);
            $stream->rewind();
            return $stream;
        }

        return Stream::createFromString($content);
    }

    /**
     * Return response object to pool
     */
    public static function releaseResponse(Response $response): void
    {
        $objectId = spl_object_id($response);
        unset(self::$activeObjects[$objectId]);

        $status = $response->getStatusCode();
        $poolKey = "status_{$status}";

        if (!isset(self::$pool[$poolKey])) {
            self::$pool[$poolKey] = [];
        }

        if (count(self::$pool[$poolKey]) < self::MAX_POOL_SIZE) {
            // Reset response state before pooling
            $response->clearHeaders();
            $stream = $response->getBody();

            // Pool the stream if it's reusable
            if ($stream instanceof Stream && $stream->isReusable()) {
                self::releaseStream($stream);
            }

            self::$pool[$poolKey][] = $response;
        }
    }

    /**
     * Return stream object to pool
     */
    public static function releaseStream(Stream $stream): void
    {
        if (count(self::$streamPool) < self::MAX_STREAM_POOL_SIZE) {
            $stream->rewind();
            $stream->truncate(0);
            self::$streamPool[] = $stream;
        }
    }

    /**
     * Get optimized JSON response
     */
    public static function getJsonResponse(array $data, int $status = 200): Response
    {
        $json = json_encode($data);
        if ($json === false) {
            throw new \InvalidArgumentException('Unable to encode data as JSON');
        }

        // Simple, fast approach
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create stream');
        }
        fwrite($stream, $json);
        rewind($stream);

        $response = new Response(
            $status,
            [
                'Content-Type' => 'application/json'
            ],
            new Stream($stream)
        );

        // Track the new response object for GC
        self::$activeObjects[spl_object_id($response)] = $response;

        return $response;
    }

    /**
     * Get optimized text response
     */
    public static function getTextResponse(string $text, int $status = 200): Response
    {
        $response = self::getResponse($status);
        $response = $response->withHeader('Content-Type', 'text/plain; charset=utf-8');

        $stream = self::getStream($text);
        $finalResponse = $response->withBody($stream);

        // Update tracking for the final response object
        if ($finalResponse !== $response) {
            self::$activeObjects[spl_object_id($finalResponse)] = $finalResponse;
        }

        return $finalResponse;
    }

    /**
     * Get optimized HTML response
     */
    public static function getHtmlResponse(string $html, int $status = 200): Response
    {
        $response = self::getResponse($status);
        $response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');

        $stream = self::getStream($html);
        $finalResponse = $response->withBody($stream);

        // Update tracking for the final response object
        if ($finalResponse !== $response) {
            self::$activeObjects[spl_object_id($finalResponse)] = $finalResponse;
        }

        return $finalResponse;
    }

    /**
     * Warm up pool with common responses
     */
    public static function warmUp(): void
    {
        $commonStatuses = [200, 201, 400, 401, 403, 404, 422, 500];

        foreach ($commonStatuses as $status) {
            for ($i = 0; $i < 5; $i++) {
                $response = new Response($status, [], self::getStream());
                $poolKey = "status_{$status}";

                if (!isset(self::$pool[$poolKey])) {
                    self::$pool[$poolKey] = [];
                }

                self::$pool[$poolKey][] = $response;
                // Note: We don't track warmup responses in activeObjects as they're pre-pooled
                // and not actively used until retrieved via getResponse()
            }
        }

        // Warm up stream pool
        for ($i = 0; $i < 20; $i++) {
            self::$streamPool[] = Stream::createFromString('');
        }

        // Warm up header pool
        HeaderPool::warmUp();
    }

    /**
     * Get pool statistics
     *
     * @return array<string, mixed>
     */
    public static function getStats(): array
    {
        $responseStats = [];
        foreach (self::$pool as $key => $responses) {
            $responseStats[$key] = count($responses);
        }

        return [
            'response_pools' => $responseStats,
            'stream_pool_size' => count(self::$streamPool),
            'active_objects' => count(self::$activeObjects),
            'total_pooled_responses' => array_sum($responseStats),
            'header_pool_stats' => HeaderPool::getStats()
        ];
    }

    /**
     * Clear all pools
     */
    public static function clearAll(): void
    {
        self::$pool = [];
        self::$streamPool = [];
        self::$activeObjects = [];
    }

    /**
     * Get active objects (for monitoring)
     *
     * @return Response[]
     */
    public static function getActiveObjects(): array
    {
        return self::$activeObjects;
    }

    /**
     * Force garbage collection of inactive objects
     */
    public static function garbageCollect(): int
    {
        $collected = 0;
        $alive = [];

        foreach (self::$activeObjects as $objectId => $response) {
            if (self::isObjectAlive($response)) {
                $alive[$objectId] = $response;
            } else {
                $collected++;
            }
        }

        self::$activeObjects = $alive;
        return $collected;
    }

    /**
     * Check if object is still referenced elsewhere
     */
    private static function isObjectAlive(Response $response): bool
    {
        // Simple heuristic: if object is only referenced by our pool, it's dead
        $refCount = 0;

        // Count references in our tracking
        foreach (self::$activeObjects as $tracked) {
            if ($tracked === $response) {
                $refCount++;
            }
        }

        // If only our tracking holds a reference, consider it dead
        return $refCount > 1;
    }
}

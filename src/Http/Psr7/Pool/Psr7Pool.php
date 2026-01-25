<?php

declare(strict_types=1);

namespace PivotPHP\PerformanceTools\Http\Psr7\Pool;

use PivotPHP\Core\Http\Psr7\ServerRequest;
use PivotPHP\Core\Http\Psr7\Response;
use PivotPHP\Core\Http\Psr7\Uri;
use PivotPHP\Core\Http\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Pool de objetos PSR-7 para reutilização eficiente
 *
 * Implementa object pooling para reduzir criação/destruição de objetos PSR-7,
 * mantendo compatibilidade com imutabilidade PSR-7.
 *
 * @package PivotPHP\Core\Http\Pool
 * @since 2.1.1
 */
class Psr7Pool
{
    /**
     * Pool de objetos ServerRequest
     *
     * @var array<ServerRequestInterface>
     */
    private static array $requestPool = [];

    /**
     * Pool de objetos Response
     *
     * @var array<ResponseInterface>
     */
    private static array $responsePool = [];

    /**
     * Pool de objetos Uri
     *
     * @var array<UriInterface>
     */
    private static array $uriPool = [];

    /**
     * Pool de objetos Stream
     *
     * @var array<StreamInterface>
     */
    private static array $streamPool = [];

    /**
     * Tamanho máximo de cada pool
     */
    private const MAX_POOL_SIZE = 50;

    /**
     * Estatísticas de uso
     *
     * @var array<string, int>
     */
    private static array $stats = [
        'requests_created' => 0,
        'requests_reused' => 0,
        'responses_created' => 0,
        'responses_reused' => 0,
        'uris_created' => 0,
        'uris_reused' => 0,
        'streams_created' => 0,
        'streams_reused' => 0,
    ];

    /**
     * Obtém ServerRequest do pool ou cria novo
     */
    public static function getServerRequest(
        string $method,
        UriInterface $uri,
        StreamInterface $body,
        array $headers = [],
        string $version = '1.1',
        array $serverParams = []
    ): ServerRequestInterface {
        if (!empty(self::$requestPool)) {
            $request = array_pop(self::$requestPool);
            self::$stats['requests_reused']++;

            // Resetar para novo uso mantendo imutabilidade
            return self::resetServerRequest($request, $method, $uri, $body, $headers, $version, $serverParams);
        }

        self::$stats['requests_created']++;
        return new ServerRequest($method, $uri, $body, $headers, $version, $serverParams);
    }

    /**
     * Obtém Response do pool ou cria novo
     */
    public static function getResponse(
        int $statusCode = 200,
        array $headers = [],
        ?StreamInterface $body = null,
        string $version = '1.1',
        string $reasonPhrase = ''
    ): ResponseInterface {
        if (!empty(self::$responsePool)) {
            $response = array_pop(self::$responsePool);
            self::$stats['responses_reused']++;

            // Resetar para novo uso mantendo imutabilidade
            return self::resetResponse($response, $statusCode, $headers, $body, $version, $reasonPhrase);
        }

        self::$stats['responses_created']++;
        return new Response($statusCode, $headers, $body, $version, $reasonPhrase);
    }

    /**
     * Obtém Uri do pool ou cria novo
     */
    public static function getUri(string $uri = ''): UriInterface
    {
        if (!empty(self::$uriPool)) {
            $uriObj = array_pop(self::$uriPool);
            self::$stats['uris_reused']++;

            // Como Uri é imutável, precisamos criar novo com dados
            return self::resetUri($uriObj, $uri);
        }

        self::$stats['uris_created']++;
        return new Uri($uri);
    }

    /**
     * Obtém Stream do pool ou cria novo
     */
    public static function getStream(string $content = ''): StreamInterface
    {
        if (!empty(self::$streamPool)) {
            $stream = array_pop(self::$streamPool);
            self::$stats['streams_reused']++;

            // Resetar stream para novo conteúdo
            return self::resetStream($stream, $content);
        }

        self::$stats['streams_created']++;
        return Stream::createFromString($content);
    }

    /**
     * Borrow request from pool (alias for getServerRequest)
     */
    public static function borrowRequest(): ServerRequestInterface
    {
        $uri = self::getUri('');
        $body = self::getStream('');
        return self::getServerRequest('GET', $uri, $body);
    }

    /**
     * Borrow response from pool (alias for getResponse)
     */
    public static function borrowResponse(): ResponseInterface
    {
        return self::getResponse();
    }

    /**
     * Borrow URI from pool (alias for getUri)
     */
    public static function borrowUri(): UriInterface
    {
        return self::getUri('');
    }

    /**
     * Borrow stream from pool (alias for getStream)
     */
    public static function borrowStream(): StreamInterface
    {
        return self::getStream('');
    }

    /**
     * Retorna ServerRequest para o pool
     */
    public static function returnServerRequest(ServerRequestInterface $request): void
    {
        if (count(self::$requestPool) < self::MAX_POOL_SIZE) {
            self::$requestPool[] = $request;
        }
    }

    /**
     * Retorna Response para o pool
     */
    public static function returnResponse(ResponseInterface $response): void
    {
        if (count(self::$responsePool) < self::MAX_POOL_SIZE) {
            self::$responsePool[] = $response;
        }
    }

    /**
     * Retorna Uri para o pool
     */
    public static function returnUri(UriInterface $uri): void
    {
        if (count(self::$uriPool) < self::MAX_POOL_SIZE) {
            self::$uriPool[] = $uri;
        }
    }

    /**
     * Retorna Stream para o pool
     */
    public static function returnStream(StreamInterface $stream): void
    {
        if (count(self::$streamPool) < self::MAX_POOL_SIZE) {
            // Verificar se o stream pode ser reutilizado
            $canReuse = $stream->isSeekable() && $stream->isWritable();
            if ($canReuse) {
                self::$streamPool[] = $stream;
            }
        }
    }

    /**
     * Reseta ServerRequest para novo uso
     */
    private static function resetServerRequest(
        ServerRequestInterface $request,
        string $method,
        UriInterface $uri,
        StreamInterface $body,
        array $headers,
        string $version,
        array $serverParams
    ): ServerRequestInterface {
        return $request
            ->withMethod($method)
            ->withUri($uri)
            ->withBody($body)
            ->withProtocolVersion($version);
    }

    /**
     * Reseta Response para novo uso
     */
    private static function resetResponse(
        ResponseInterface $response,
        int $statusCode,
        array $headers,
        ?StreamInterface $body,
        string $version,
        string $reasonPhrase
    ): ResponseInterface {
        $response = $response
            ->withStatus($statusCode, $reasonPhrase)
            ->withProtocolVersion($version);

        if ($body !== null) {
            $response = $response->withBody($body);
        }

        // Resetar headers
        foreach ($response->getHeaders() as $name => $values) {
            $response = $response->withoutHeader((string)$name);
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Reseta Uri para novo uso
     */
    private static function resetUri(UriInterface $uri, string $uriString): UriInterface
    {
        $parts = parse_url($uriString);

        if ($parts === false) {
            return new Uri($uriString);
        }

        $uri = $uri->withScheme($parts['scheme'] ?? '')
                   ->withHost($parts['host'] ?? '')
                   ->withPort(isset($parts['port']) ? (int)$parts['port'] : null)
                   ->withPath($parts['path'] ?? '')
                   ->withQuery($parts['query'] ?? '')
                   ->withFragment($parts['fragment'] ?? '');

        if (isset($parts['user'])) {
            $uri = $uri->withUserInfo($parts['user'], $parts['pass'] ?? null);
        }

        return $uri;
    }

    /**
     * Reseta Stream para novo uso
     */
    private static function resetStream(StreamInterface $stream, string $content): StreamInterface
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        if ($stream->isWritable() && $stream->isSeekable()) {
            $stream->rewind();
            $stream->write($content);
            $stream->rewind();
            return $stream;
        }

        // Se não conseguir resetar, criar novo
        return Stream::createFromString($content);
    }

    /**
     * Obtém estatísticas do pool
     */
    public static function getStats(): array
    {
        return [
            'pool_sizes' => [
                'requests' => count(self::$requestPool),
                'responses' => count(self::$responsePool),
                'uris' => count(self::$uriPool),
                'streams' => count(self::$streamPool),
            ],
            'efficiency' => [
                'request_reuse_rate' => self::calculateReuseRate('requests'),
                'response_reuse_rate' => self::calculateReuseRate('responses'),
                'uri_reuse_rate' => self::calculateReuseRate('uris'),
                'stream_reuse_rate' => self::calculateReuseRate('streams'),
            ],
            'usage' => self::$stats,
        ];
    }

    /**
     * Calcula taxa de reutilização
     */
    private static function calculateReuseRate(string $type): float
    {
        $created = self::$stats[$type . '_created'];
        $reused = self::$stats[$type . '_reused'];
        $total = $created + $reused;

        return $total > 0 ? ($reused / $total) * 100 : 0;
    }

    /**
     * Clear pools (alias for clearAll)
     */
    public static function clearPools(): void
    {
        self::clearAll();
    }

    /**
     * Limpa todos os pools
     */
    public static function clearAll(): void
    {
        self::$requestPool = [];
        self::$responsePool = [];
        self::$uriPool = [];
        self::$streamPool = [];
        self::$stats = [
            'requests_created' => 0,
            'requests_reused' => 0,
            'responses_created' => 0,
            'responses_reused' => 0,
            'uris_created' => 0,
            'uris_reused' => 0,
            'streams_created' => 0,
            'streams_reused' => 0,
        ];
    }

    /**
     * Pré-aquece os pools com objetos comuns
     */
    public static function warmUp(): void
    {
        // Pré-criar alguns objetos para o pool
        for ($i = 0; $i < 5; $i++) {
            self::returnServerRequest(
                self::getServerRequest('GET', self::getUri('/'), self::getStream(''))
            );
            self::returnResponse(
                self::getResponse(200, ['Content-Type' => 'application/json'], self::getStream('{}'))
            );
            self::returnUri(self::getUri('/'));
            self::returnStream(self::getStream(''));
        }
    }
}

<?php

declare(strict_types=1);

namespace PivotPHP\PerformanceTools;

use PivotPHP\PerformanceTools\Http\Psr7\Pool\PoolManager;
use PivotPHP\PerformanceTools\Middleware\MiddlewarePipelineCompiler;
use PivotPHP\PerformanceTools\Json\Pool\JsonBufferPool;
use PivotPHP\PerformanceTools\Http\Psr7\Cache\OperationsCache;
use PivotPHP\PerformanceTools\Utils\SerializationCache;

/**
 * Performance Tools Loader
 *
 * Centraliza a ativação dos componentes de performance.
 * Este arquivo pode ser usado para carregar alias ao Core se necessário.
 */
class PerformanceTools
{
    /**
     * Ativar todos os componentes de performance
     */
    public static function enable(): void
    {
        // Aquece pools automaticamente
        PoolManager::initialize([
            'auto_warm_up' => true,
            'enable_response_pool' => true,
            'enable_header_pool' => true,
            'enable_operations_cache' => true,
        ]);

        // Pré-compila pipelines comuns
        MiddlewarePipelineCompiler::warmUp();
    }

    /**
     * Obter informações sobre componentes carregados
     */
    public static function getStatus(): array
    {
        return [
            'pools' => PoolManager::getStats(),
            'compiler' => MiddlewarePipelineCompiler::getStats(),
            'json_buffer' => JsonBufferPool::getStats(),
        ];
    }

    /**
     * Limpar todos os caches e pools
     */
    public static function clearAll(): void
    {
        PoolManager::clearAll();
        MiddlewarePipelineCompiler::clearAll();
        JsonBufferPool::clearPools();
        OperationsCache::clearAll();
        SerializationCache::clearCache();
    }
}

# PivotPHP Performance Tools

> [!WARNING]
> **This project is discontinued and the repository is archived (2026-10-09).**
>
> `pivotphp/performance-tools` was never released (no tagged version) and has no test
> suite. The PivotPHP ecosystem is focusing on the correctness of `pivotphp/core` before
> adding optimization layers, so this package will not be maintained.
>
> - Do not add it as a dependency of new projects.
> - Use [`pivotphp/core`](https://github.com/PivotPHP/pivotphp-core) directly.
> - The code remains available for reference only.

High-performance pooling, caching, and optimization tools for PivotPHP.

## Features

- **Object Pooling**: Efficient PSR-7 object reuse (Requests, Responses, URIs, Streams)
- **JSON Buffering**: Optimized JSON encoding with size-based buffer pooling
- **Header Pooling**: Efficient header validation and normalization with caching
- **Response Pooling**: Status-code indexed response objects with lazy initialization
- **Stream Pooling**: Size-categorized stream pooling with LRU eviction
- **Operations Caching**: Compiled regex patterns, JSON encoded data, MIME type lookups
- **Serialization Caching**: Automatic serialization caching for middleware pipelines
- **Pipeline Compilation**: Advanced middleware pipeline compiler with pattern learning

## Installation

```bash
composer require pivotphp/performance-tools
```

## License

MIT - see [LICENSE](LICENSE) for details.

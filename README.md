# PivotPHP Performance Tools

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

## Documentation

See [docs/](docs/) for detailed documentation.

## License

MIT

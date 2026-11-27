# ADR-002: Protocol-Agnostic Design

## Status

Accepted — V4.0.0; amended in 4.1 (see Amendment)

## Date

2026-03-01

## Context

ARFA 1.3 Principle P4 requires protocol independence. The router must serve HTTP, gRPC, WebSocket, CLI, and custom protocols without coupling to any specific request/response abstraction.

### The PSR-7 Coupling Problem

Most PHP routers are tightly coupled to PSR-7 `ServerRequestInterface`:

| Library | Contract Signature | PSR-7 Required |
|---|---|---|
| Symfony Routing | `match(string $pathinfo)` → array | No (string-based) |
| FastRoute | `dispatch(string $method, string $uri)` → array | No (string-based) |
| Laravel Router | `dispatch(Request $request)` → Response | Yes (Illuminate) |
| League\Route | `dispatch(ServerRequestInterface)` → ResponseInterface | Yes (PSR-7) |
| **KaririCode** | `match(string $method, string $uri)` → MatchResult | **No** |

Symfony and FastRoute also accept raw strings, but they return untyped arrays (`[status, handler, params]`) rather than typed value objects. KaririCode returns a `final readonly MatchResult` (ARFA P1).

### Multi-Protocol Use Cases

```
Protocol        method          uri
────────────    ──────────      ────────────────────────
HTTP            GET             /api/v1/users/42
gRPC            UNARY           /user.UserService/GetUser
WebSocket       SUBSCRIBE       /channels/notifications
CLI             RUN             /migrate/up
GraphQL         QUERY           /graphql/users
MQTT            PUBLISH         /devices/sensor-01/telemetry
```

Every protocol can be decomposed into a `(method, uri)` pair. The router's contract must accept these raw primitives.

## Decision

### Router Contract

The `Router` contract accepts `(string $method, string $uri)` instead of any request object:

```php
interface Router
{
    public function match(string $method, string $uri): MatchResult;
}
```

### Middleware Contract

The `Middleware` contract uses `array<string, mixed> $context` instead of PSR-15's `ServerRequestInterface`:

```php
interface Middleware
{
    public function handle(array $context, callable $next): mixed;
}
```

Protocol adapters in the Integration layer translate protocol-specific objects to these primitives. PSR-7/PSR-15 adapters are provided but not required.

### Protocol Adapter Pattern

```
┌─────────────────┐      ┌─────────────────┐      ┌───────────┐
│  HTTP Request    │ ───▶ │  HttpAdapter     │ ───▶ │           │
│  (PSR-7)        │      │  →(method, uri)  │      │           │
├─────────────────┤      ├─────────────────┤      │  Router   │
│  gRPC Request   │ ───▶ │  GrpcAdapter     │ ───▶ │  .match() │
│  (Protobuf)     │      │  →(method, uri)  │      │           │
├─────────────────┤      ├─────────────────┤      │           │
│  CLI Command    │ ───▶ │  CliAdapter      │ ───▶ │           │
│  (argv)         │      │  →(method, uri)  │      └───────────┘
└─────────────────┘      └─────────────────┘
```

### Context Array Convention

The `array $context` in the middleware pipeline carries protocol-decoded data:

```php
// HTTP context
['method' => 'GET', 'uri' => '/users/42', 'headers' => [...], 'body' => '...']

// gRPC context
['method' => 'UNARY', 'uri' => '/user.UserService/GetUser', 'message' => $proto]

// CLI context
['method' => 'RUN', 'uri' => '/migrate/up', 'args' => ['--force'], 'options' => [...]]
```

## Consequences

### Positive

- gRPC service/method pairs map directly to `method + uri`.
- WebSocket frame routing uses channel + action as URI.
- CLI commands use the same routing engine (command name as URI).
- No PSR-7 dependency in `require` — only in `suggest`.
- MQTT, AMQP, and custom protocols work without core changes.
- The same middleware stack can serve all protocols (cross-cutting concerns like auth, logging, rate-limiting).

### Negative

- Developers familiar with PSR-15 middleware must use an adapter or rewrite.
- The `array $context` contract is less type-safe than PSR-7 interfaces — mitigated by documenting required keys per protocol.
- Protocol adapters add one layer of indirection — negligible overhead (one function call per request).

### Trade-off

Type safety vs. universality. PSR-7 gives compile-time guarantees for HTTP-specific properties (`getUri()`, `getBody()`). The array context gives runtime flexibility for any protocol. For the KaririCode ecosystem targeting multi-protocol applications, universality wins.

## Protocol Adapter Examples

### HTTP (PSR-7)

```php
final class HttpAdapter
{
    public function __construct(private readonly Router $router) {}

    public function dispatch(ServerRequestInterface $request): MatchResult
    {
        return $this->router->match(
            $request->getMethod(),
            $request->getUri()->getPath(),
        );
    }
}
```

### gRPC

```php
final class GrpcAdapter
{
    public function dispatch(string $serviceName, string $methodName): MatchResult
    {
        return $this->router->match('UNARY', "/{$serviceName}/{$methodName}");
    }
}
```

### CLI

```php
final class CliAdapter
{
    public function dispatch(string $commandName): MatchResult
    {
        return $this->router->match('RUN', "/{$commandName}");
    }
}
```

## References

- ARFA 1.3 — P4 (Protocol Agnosticism): "Components MUST NOT couple to protocol-specific abstractions at the contract level."
- PSR-7: HTTP Message Interfaces — https://www.php-fig.org/psr/psr-7/
- PSR-15: HTTP Server Request Handlers — https://www.php-fig.org/psr/psr-15/

## Amendment (4.1, 2026-11-27)

- Methods are plain strings in every API (`RouteEntry::$methods`, `RouteRegistry::route()`, `#[Route(method: 'SUBSCRIBE')]`), stored uppercase and checked against the RFC 9110 token grammar. `Enum\HttpMethod` is deprecated and kept only for 4.0 attribute code.
- `Contract\RequestNormalizer` and `Core\NormalizedRequest` give protocol packages a standard way to hand the router a method and a path; each protocol implements the normalizer in its own package (kariricode/http, grpc, websocket, graphql, sse), so the router never depends on them.
- `RequestNormalizer::canNormalize()` and `normalize()` take `object`, and `NormalizedRequest::$original` is an `object`: the router cannot name a protocol's request type. This is the one accepted deviation from the rule against `object` parameters; the conformance suite enforces the list.


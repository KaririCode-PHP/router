# GAP-PROTOCOL: Router V4.0 Protocol Agnosticism Analysis

## Status: VIOLATION — ARFA 1.3 P4

**Date:** 2026-03-01
**Scope:** KaririCode\Router V4.0 vs V3.1 + ecosystem components
**Cross-reference:** ADR-002, ARFA 1.3 §7 (Protocol Abstraction), SPEC-001
**Ecosystem chats analyzed:** 15+ (HTTP, gRPC, GraphQL, WebSocket, SSE, CLI, Contract, Framework)

---

## 1. Executive Summary

Router V4.0 **claims** protocol-agnostic design in ADR-002 and the Router contract PHPDoc,
but the implementation is **structurally coupled to HTTP** through the `HttpMethod` enum.
The coupling occurs at registration time — route entries physically cannot hold non-HTTP
methods — making the V4.0 Router unusable by the 5 non-HTTP ecosystem components
(gRPC, GraphQL, WebSocket, SSE, CLI) that V3.1 already supported.

### Severity Matrix

| Layer | V4.0 Status | V3.1 Status | Impact |
|---|---|---|---|
| `Router::match(string, string)` | ✅ Protocol-agnostic | ✅ Protocol-agnostic | OK |
| `RouteMatcher::match(string, string)` | ✅ Protocol-agnostic | ✅ Protocol-agnostic | OK |
| `Middleware::handle(array, callable)` | ✅ Protocol-agnostic | ✅ Protocol-agnostic | OK |
| **`RouteEntry.methods`** | ❌ `list<HttpMethod>` | ✅ `list<string>` via Protocol | **CRITICAL** |
| **`RouteRegistry::get/post/put/...`** | ❌ HTTP-only shortcuts | ✅ `register(Protocol, ...)` | **CRITICAL** |
| **`RouteGroup::map()`** | ❌ `list<HttpMethod>` | ✅ Protocol-aware | **CRITICAL** |
| **`Route` attribute** | ❌ `HttpMethod` typed | ✅ Protocol-aware | **HIGH** |
| **Protocol enum** | ❌ Missing entirely | ✅ HTTP/GRPC/GRAPHQL/WS/CLI/SSE | **CRITICAL** |
| **Protocol adapters** | ❌ Missing entirely | ✅ 6 adapters in `src/Adapter/` | **HIGH** |
| **GenericRequest/Response** | ❌ Missing entirely | ✅ Protocol-agnostic DTOs | **HIGH** |

---

## 2. The Contradiction

### What ADR-002 Says

ADR-002 correctly identifies the design principle:

> "Every protocol can be decomposed into a (method, uri) pair.
>  The router's contract must accept these raw primitives."

And provides examples:

```
Protocol        method          uri
────────────    ──────────      ────────────────────────
HTTP            GET             /api/v1/users/42
gRPC            UNARY           /user.UserService/GetUser
WebSocket       SUBSCRIBE       /channels/notifications
CLI             RUN             /migrate/up
GraphQL         QUERY           /graphql/users
```

### What the Code Actually Does

```php
// RouteEntry.php — L23-24
/** @param list<HttpMethod> $methods */
public function __construct(
    public array $methods,  // ← ONLY HttpMethod enum values accepted
    ...
)

// HttpMethod.php — L30-42
public static function fromString(string $method): self
{
    return match (strtoupper(trim($method))) {
        'GET' => self::Get,
        'POST' => self::Post,
        // ...7 HTTP methods only...
        default => throw new \InvalidArgumentException(
            "Unknown HTTP method: {$method}"  // ← gRPC "GetUser" REJECTED
        ),
    };
}
```

**The match path works with raw strings** (matchers use `strtoupper($method)` to index).
**But the registration path is locked to HTTP** (you cannot construct a `RouteEntry`
with `$methods = ["GetUser"]` or `$methods = ["SUBSCRIBE"]`).

---

## 3. Ecosystem Impact Assessment

### 3.1 KaririCode\gRPC V3.1 (38 files, ~9.8k LOC)

**V3.1 Integration (WORKED):**

```php
// gRPC → Router V3.1 via GrpcAdapter
$adapter = new GrpcAdapter();
$genericRequest = $adapter->adaptRequest($grpcContext);
// → GenericRequest(protocol: Protocol::GRPC, method: "GetUser", path: "UserService")

$match = $router->match($genericRequest);
```

**V4.0 Integration (BROKEN):**

```php
// gRPC → Router V4.0 — FAILS at registration
$registry = new RouteRegistry();

// How do you register this route?
$registry->addRoute(new RouteEntry(
    methods: [???],  // HttpMethod has no "GetUser" case
    pattern: '/user.UserService/GetUser',
    handler: [UserService::class, 'getUser'],
));
// → IMPOSSIBLE. HttpMethod::fromString("GetUser") throws InvalidArgumentException
```

Even though `$registry->match("GetUser", "/user.UserService/GetUser")` would
work at the matcher level (string comparison), the route can never be registered.

### 3.2 KaririCode\GraphQL V3.1 (Schema-first + Code-first)

**V3.1:** Routes registered with `method: "query"`, `method: "mutation"`, `method: "subscription"`
**V4.0:** Cannot register. `HttpMethod` has no `Query`/`Mutation`/`Subscription` cases.

### 3.3 KaririCode\WebSocket V3.1 (45 files, RFC 6455)

**V3.1 adapter methods:**
- `adaptConnection()` → `GenericRequest(method: "CONNECT", ...)`
- `adaptMessage()` → `GenericRequest(method: "MESSAGE", ...)`
- `adaptDisconnect()` → `GenericRequest(method: "CLOSE", ...)`

**V4.0:** Cannot register `CONNECT`/`MESSAGE`/`CLOSE` routes.

### 3.4 KaririCode\SSE V3.1 (Server-Sent Events)

**V3.1:** Uses `Protocol::SSE` with HTTP GET transport but SSE-specific middleware.
**V4.0:** Can partially work (SSE uses HTTP GET), but loses protocol discrimination.

### 3.5 KaririCode\CLI V3.1 (Console, 46 files)

**V3.1:** CLI commands registered as `method: "RUN"`, `path: "/migrate/up"`.
**V4.0:** Cannot register. `HttpMethod` has no `Run` case.

### 3.6 KaririCode\Contract V3.1 (Central interface package)

The Contract package defines:

```php
namespace KaririCode\Contract\Protocol;

interface Request {
    public function getMethod(): string;    // Any protocol method
    public function getUri(): string;
    public function getProtocol(): string;  // "http", "grpc", "websocket"...
}

interface ProtocolAdapter {
    public function adapt(mixed $input, string $targetProtocol): mixed;
}
```

Router V4.0 does not consume these interfaces. The `Contract\Router\Middleware`
uses `mixed $request` (good), but `RouteEntry` ignores the protocol layer entirely.

---

## 4. Full Coupling Chain (10 files affected)

### Level 0 — Root coupling

| File | Coupling Point | Lines |
|---|---|---|
| `Enum/HttpMethod.php` | Enum with 7 HTTP-only cases | 72 |

### Level 1 — Direct consumers

| File | Coupling Point | Lines |
|---|---|---|
| `Core/RouteEntry.php` | `public array $methods` typed as `list<HttpMethod>` | L23-24 |
| `Core/RouteEntry.php` | `acceptsMethod(HttpMethod $method)` | L60 |

### Level 2 — Transitive consumers

| File | Coupling Point |
|---|---|
| `Core/RouteRegistry.php` | `get()`, `post()`, `put()`, `patch()`, `delete()` use `HttpMethod::*` |
| `Core/RouteGroup.php` | Same 6 shortcut methods + `any()` using all `HttpMethod` cases |
| `Attribute/Route.php` | `$method` typed as `HttpMethod\|list<HttpMethod>` |
| `Core/AttributeRouteLoader.php` | Reads `Route` attribute, passes `HttpMethod` to `RouteEntry` |
| `Matcher/StaticMatcher.php` | `$method->label()` in `add()` (converts to string at index time) |
| `Matcher/RegexMatcher.php` | `$method->label()` in `add()` (converts to string at index time) |
| `Matcher/CachedMatcher.php` | Imports `HttpMethod` (unused in match path but present) |

### Match-time path (NOT coupled — works with raw strings)

```
RouteRegistry::match(string $method, string $uri)
  → CompositeRouteMatcher::match(string $method, string $uri)
    → StaticMatcher::match(string $method, string $uri)
      → $this->map[$uri][strtoupper($method)]        // ✅ raw string
    → RegexMatcher::match(string $method, string $uri)
      → $this->compiled[strtoupper($method)]          // ✅ raw string
```

### Registration-time path (COUPLED — requires HttpMethod)

```
RouteRegistry::get($pattern, $handler)
  → register([HttpMethod::Get, HttpMethod::Head], ...)
    → new RouteEntry(methods: [HttpMethod::Get, ...])  // ❌ enum-locked
      → StaticMatcher::add(RouteEntry)
        → $method->label()                              // ❌ requires enum
      → RegexMatcher::add(RouteEntry)
        → $method->label()                              // ❌ requires enum
```

---

## 5. V3.1 Architecture That Was Lost

Router V3.1 had these structures that V4.0 removed:

### 5.1 Protocol Enum

```php
// V3.1 — src/Route/Protocol.php
enum Protocol
{
    case HTTP;
    case GRPC;
    case GRAPHQL;
    case WEBSOCKET;
    case CLI;
    case SSE;
}
```

### 5.2 GenericRequest / GenericResponse

```php
// V3.1 — src/Core/GenericRequest.php
final readonly class GenericRequest
{
    public function __construct(
        public Protocol $protocol,
        public string $method,      // ← ANY string: "GET", "GetUser", "query", "CONNECT"
        public string $path,
        public array $headers = [],
        public mixed $body = null,
        public array $attributes = [],
    ) {}
}
```

### 5.3 Protocol Adapters (6 files)

```
V3.1: src/Adapter/
├── HttpAdapter.php         PSR-7 ServerRequest → GenericRequest
├── GrpcAdapter.php         gRPC context → GenericRequest
├── GraphQlAdapter.php      GraphQL query → GenericRequest
├── WebSocketAdapter.php    WebSocket frame → GenericRequest
├── CliAdapter.php          CLI argv → GenericRequest
└── SseAdapter.php          SSE event → GenericRequest
```

### 5.4 Protocol-Aware Route Registration

```php
// V3.1 — multi-protocol registration
$router->register(
    Protocol::GRPC,
    '/myapp.UserService/GetUser',
    [UserService::class, 'getUser']
);

$router->register(
    Protocol::WEBSOCKET,
    '/ws/chat',
    [ChatHandler::class, 'handle']
);

// V4.0 — only HTTP
$registry->get('/users/{id}', [UserController::class, 'show']);
// No way to register gRPC/WebSocket/GraphQL routes
```

---

## 6. Solution Architecture

### Approach: Hybrid (Preserve V4.0 Simplicity + Restore Protocol Support)

V4.0's `match(string, string)` contract is **already correct**. The fix targets
only the registration path — `RouteEntry.methods` must accept any string, not just `HttpMethod`.

### 6.1 Change `RouteEntry.methods` to `list<string>` (CRITICAL)

```php
// BEFORE (V4.0) — HTTP-coupled
final readonly class RouteEntry
{
    /** @param list<HttpMethod> $methods */
    public function __construct(
        public array $methods,
        ...
    )

    public function acceptsMethod(HttpMethod $method): bool
    {
        return in_array($method, $this->methods, true);
    }
}

// AFTER — Protocol-agnostic
final readonly class RouteEntry
{
    /** @param list<string> $methods Uppercase method strings (protocol-independent) */
    public function __construct(
        public array $methods,
        ...
    )

    public function acceptsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }
}
```

### 6.2 Update Matchers (Minimal — already string-based internally)

```php
// StaticMatcher::add() — BEFORE
foreach ($route->methods as $method) {
    $this->map[$route->pattern][$method->label()] = $route;
}

// AFTER — methods are already strings
foreach ($route->methods as $method) {
    $this->map[$route->pattern][$method] = $route;
}

// RegexMatcher::add() — same change
foreach ($route->methods as $method) {
    $this->routesByMethod[$method][] = $route;
}
```

### 6.3 Keep HTTP Shortcuts (Backward Compatible)

```php
// RouteRegistry — shortcuts remain, but use strings internally
public function get(string $pattern, ...): self
{
    return $this->register(['GET', 'HEAD'], $pattern, $handler, $name, $middleware);
}

// NEW — generic registration for any protocol
public function route(array $methods, string $pattern, ...): self
{
    $normalizedMethods = array_map('strtoupper', $methods);
    return $this->register($normalizedMethods, $pattern, ...);
}
```

### 6.4 Deprecate HttpMethod Enum (Non-Breaking)

```php
// HttpMethod stays but is no longer used in RouteEntry
// Add utility methods for HTTP consumers
enum HttpMethod
{
    case Get; case Post; // ...existing cases...

    // NEW — returns the string used in RouteEntry
    public function value(): string { return $this->label(); }

    // Existing constants become convenience for HTTP-only users
}
```

### 6.5 Add Protocol Adapters (Optional — in `src/Adapter/`)

```php
src/Adapter/
├── HttpAdapter.php         // PSR-7 → match(string, string)
├── GrpcAdapter.php         // gRPC context → match(string, string)
├── GraphQlAdapter.php      // GraphQL operation → match(string, string)
├── WebSocketAdapter.php    // WebSocket frame → match(string, string)
├── CliAdapter.php          // CLI args → match(string, string)
└── SseAdapter.php          // SSE → match(string, string)
```

Each adapter is ~30-50 lines. They normalize protocol-specific input to `(method, uri)`.

### 6.6 Add Protocol Enum (Optional — for adapter type safety)

```php
// src/Enum/Protocol.php — restores V3.1 capability
enum Protocol
{
    case Http;
    case Grpc;
    case GraphQl;
    case WebSocket;
    case Cli;
    case Sse;
}
```

---

## 7. Files Requiring Changes

### Breaking Changes (3 files)

| File | Change | Risk |
|---|---|---|
| `Core/RouteEntry.php` | `list<HttpMethod>` → `list<string>` | Low — callers pass strings |
| `Matcher/StaticMatcher.php` | Remove `$method->label()` → use `$method` directly | None |
| `Matcher/RegexMatcher.php` | Same | None |

### Non-Breaking Changes (4 files)

| File | Change |
|---|---|
| `Core/RouteRegistry.php` | Shortcut methods pass strings; add `route()` method |
| `Core/RouteGroup.php` | Same — `map()` accepts `list<string>` |
| `Attribute/Route.php` | Accept `string\|list<string>` alongside `HttpMethod` |
| `Core/AttributeRouteLoader.php` | Convert `HttpMethod` to string when building `RouteEntry` |

### New Files (7-8 files)

| File | Purpose |
|---|---|
| `Enum/Protocol.php` | Protocol enum (optional) |
| `Adapter/HttpAdapter.php` | PSR-7 bridge |
| `Adapter/GrpcAdapter.php` | gRPC bridge |
| `Adapter/GraphQlAdapter.php` | GraphQL bridge |
| `Adapter/WebSocketAdapter.php` | WebSocket bridge |
| `Adapter/CliAdapter.php` | CLI bridge |
| `Adapter/SseAdapter.php` | SSE bridge |

### Documentation Updates

| File | Change |
|---|---|
| `docs/adr/ADR-002-protocol-agnostic-design.md` | Add "Implementation Gap" section, resolution |
| `docs/spec/SPEC-001-router-architecture.md` | Add "Multi-Protocol Routing" section |
| `README.md` | Add protocol adapter examples |

---

## 8. Validation

After fix, these must all work:

```php
// HTTP (existing — no change)
$registry->get('/users/{id}', [UserController::class, 'show']);
$registry->match('GET', '/users/42');

// gRPC (currently impossible)
$registry->route(['UNARY'], '/user.UserService/GetUser', [UserService::class, 'getUser']);
$registry->match('UNARY', '/user.UserService/GetUser');

// GraphQL (currently impossible)
$registry->route(['QUERY'], '/graphql/users', [UserResolver::class, 'resolve']);
$registry->match('QUERY', '/graphql/users');

// WebSocket (currently impossible)
$registry->route(['SUBSCRIBE'], '/channels/notifications', [NotificationHandler::class, 'handle']);
$registry->match('SUBSCRIBE', '/channels/notifications');

// CLI (currently impossible)
$registry->route(['RUN'], '/migrate/up', [MigrateCommand::class, 'execute']);
$registry->match('RUN', '/migrate/up');
```

---

## 9. Priority

| Action | Priority | Effort | Impact |
|---|---|---|---|
| `RouteEntry.methods` → `list<string>` | **P0 CRITICAL** | 1h | Unblocks all protocols |
| Matcher `label()` removal | **P0 CRITICAL** | 30min | Required by above |
| `RouteRegistry::route()` method | **P0 HIGH** | 30min | Registration API |
| Protocol adapters | **P1 HIGH** | 4h | Ecosystem integration |
| Protocol enum | **P2 MEDIUM** | 1h | Type safety for adapters |
| Update ADR-002 / SPEC-001 | **P2 MEDIUM** | 2h | Documentation accuracy |
| HttpMethod deprecation path | **P3 LOW** | 1h | Backward compatibility |

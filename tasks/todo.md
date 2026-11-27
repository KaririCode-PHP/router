# KaririCode Router V4.1 — Protocol-Agnostic Architecture

## Princípio

```
Router::match(string $method, string $uri) → MatchResult
```

Strings são o contrato. O Router não sabe que protocolos existem.
Ele não lista protocolos, não os detecta, não os adapta.

Cada pacote de protocolo **depende** do Router e implementa
`RequestNormalizer` no **seu** namespace. O Router nunca é modificado.

```
kariricode/http        ──depends-on──►  kariricode/router
kariricode/grpc        ──depends-on──►  kariricode/router
kariricode/websocket   ──depends-on──►  kariricode/router
kariricode/graphql     ──depends-on──►  kariricode/router
kariricode/sse         ──depends-on──►  kariricode/router
kariricode/console     ──depends-on──►  kariricode/router
(qualquer futuro)      ──depends-on──►  kariricode/router
```

Nenhuma seta aponta do Router para fora. Dependência 100% unidirecional.

---

## O que o Router V4.1 entrega

### Core protocol-agnostic (feito nesta sessão)
- [x] RouteEntry.methods: list<string> — aceita qualquer method string
- [x] RouteRegistry::route() — register com qualquer protocol method
- [x] StaticMatcher / RegexMatcher — operam com strings puras
- [x] HttpMethod enum → string-backed + @deprecated

### Contrato para normalizers (feito nesta sessão)
- [x] Contract/RequestNormalizer.php — canNormalize() + normalize()
- [x] Core/NormalizedRequest.php — readonly DPO (method, uri, original)

### Zero normalizers concretos no pacote Router
O Router não contém HttpNormalizer, CliNormalizer, GrpcNormalizer, etc.
Cada pacote de protocolo fornece o seu.

---

## O que cada pacote de protocolo entrega (no SEU namespace)

| Pacote | Classe | Namespace |
|--------|--------|-----------|
| kariricode/http | HttpNormalizer | KaririCode\Http\Integration\ |
| kariricode/grpc | GrpcNormalizer | KaririCode\Grpc\Integration\ |
| kariricode/websocket | WebSocketNormalizer | KaririCode\WebSocket\Integration\ |
| kariricode/graphql | GraphQlNormalizer | KaririCode\GraphQL\Integration\ |
| kariricode/sse | SseNormalizer | KaririCode\SSE\Integration\ |
| kariricode/console | ConsoleNormalizer | KaririCode\Console\Integration\ |

Cada um: `implements KaririCode\Router\Contract\RequestNormalizer`

---

## Validação OCP

Novo protocolo MQTT:

```php
// kariricode/mqtt package (novo)
namespace KaririCode\Mqtt\Integration;

use KaririCode\Router\Contract\RequestNormalizer;
use KaririCode\Router\Core\NormalizedRequest;

final class MqttNormalizer implements RequestNormalizer { ... }
```

Arquivos modificados no Router: **zero**.
Arquivos modificados em qualquer pacote existente: **zero**.

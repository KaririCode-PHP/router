# ADR-007: Exceptions on the kariricode/exception families, codes in block 5000–5099

## Status

Accepted — 4.1

## Date

2026-11-27

## Context

The 4.0 router threw five exceptions under its own `RouterException extends \RuntimeException`. None implemented a family of kariricode/contract, so an edge that maps failures by family (Spec V4.3.3 §8.4) could not tell a missing route from a broken cache. Codes were plain integers from 5100–5159, and several failures shared one: `RouteCompilationException` used 5140 for an invalid pattern, a duplicate parameter, a Closure that cannot be cached and a failed cache write alike. Messages repeated what the client sent (`No route matches GET /users/john@example.com`), which errata E-047 forbids, because exception messages reach logs.

Two more facts shaped the decision:

- The ecosystem registry of error-code blocks (`CODE-BLOCKS.md` of kariricode/exception, ADR-002 of that package) found that kariricode/dataflow had already released codes in 5100–5139. By rule 5(a) the block stays with dataflow, and the router moves by a constant offset to the nearest free block of the same size: 5000–5099, codes −100, prefix RO.
- The 4.0 pattern compiler never raised its own exceptions. `{id:\d+}`, `{user-id}` or a stray brace became literal text, and the route silently never matched; two routes in one chunk with the same parameter name made PCRE refuse the combined expression at match time.

## Decision

- Every router exception extends one family base of kariricode/exception 2.0 and is built by named constructors that pass a case of `Exception\RouterCode`:

| Exception | Family (base) | Codes |
|---|---|---|
| `RouteNotFoundException` | NotFound | 5010 no route for the path; 5021 no route with that name |
| `MethodNotAllowedException` | NotFound | 5011, with `allowedMethods` for an Allow header |
| `UrlGenerationException` | Validation | 5020 missing parameter; 5022 value breaks its type |
| `RouteCompilationException` | Configuration | 5000–5006 route definitions; 5040–5042 cache table and key |
| `RouteFileException` | Configuration | 5050–5052 route files |
| `RouteCacheException` | Runtime | 5045 cache directory; 5046 cache write |

- `RouterCode` keeps the 4.0 layout under the offset: definitions 5000–5009, matching 5010–5019, URL generation 5020–5029, middleware 5030–5039 (free), cache 5040–5049, files and bridges 5050–5059.
- MethodNotAllowed stays in the NotFound family. An edge that knows the class answers 405 with the Allow header; one that does not answers 404, which is still true for that method. Protocol (400) would claim the request was malformed, which it is not.
- Messages state the rule. They name routes, parameters, files and cache keys, which the application chose, through `quote()`, and never the method, path or values the client sent.
- Route definitions fail when they are defined: `RoutePattern` rejects malformed placeholders, unknown types and repeated names; `RouteEntry` rejects empty method lists, methods that are not RFC 9110 tokens and empty handlers; `RouteRegistry::addRoute()` rejects a pattern without a leading "/", a second route for the same method and pattern, and a second route with the same name.
- The registry row for the router moves from proposed to claimed with this change, and to released with the first tag.

## Codes before and after

| 4.0 code | Failure | 4.1 code |
|---|---|---|
| 5100 | `RouterException` default | none: the base class is gone |
| 5110 | route not found | 5010 |
| 5111 | method not allowed | 5011 |
| 5120 | missing parameter | 5020 |
| 5121 | unknown route name | 5021 (now `RouteNotFoundException::named()`) |
| 5122 | constraint violation | 5022 |
| 5140 | every compilation and cache failure | 5000–5006, 5040–5046, by failure |

4.0 was never released, so no deployed code depends on the old numbers; the table is here for anyone who read the 4.0 README.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Keep `RouterException` and implement the contract interfaces directly | possible (kariricode/console does it), but every component would repeat the code table, message neutralisation and quoting that kariricode/exception already tests |
| Keep 5100–5159 | collides with released kariricode/dataflow codes; the registry rule gives the block to dataflow |
| MethodNotAllowed as Protocol | an edge without router knowledge would answer 400 for a well-formed request |
| Keep the silent literal fallback for unknown placeholders | a typo in a route becomes a permanent 404 that nothing reports |

## Consequences

- An edge maps every router failure by family without importing router classes.
- A log line `[RO_5011] …` names the package and the failure, and carries no client data.
- Route mistakes surface at boot or in the first test that registers the routes.
- kariricode/exception ^2.0 joins the runtime; through it, kariricode/contract.
- Code that built the 4.0 exceptions with `new` (tests of kariricode/http, for example) switches to the named constructors.

## References

- KaririCode Spec V4.3.3 §8.1, §8.4, §8.5; errata E-036 and E-047.
- kariricode/exception 2.0: family bases, `ErrorCode`, `CODE-BLOCKS.md` (rules 4 and 5).
- RFC 9110 §15.5.5 (404), §15.5.6 (405), §10.2.1 (Allow), §5.6.2 (token).

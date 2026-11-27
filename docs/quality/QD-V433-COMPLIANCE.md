# QD-V433 — Quality Compliance Report

| Field | Value |
|---|---|
| Component | `kariricode/router`, 4.1 (unreleased) |
| Specification | KaririCode Specification V4.3.3 · ARFA 1.3 Level 1 · OWASP ASVS 5.0 Level 1 |
| Date | 2026-11-27 |
| Status | **PASS**: every §29.1 gate green against kariricode/exception 2.0.0 and kariricode/contract 2.12.0 from Packagist, toolchain through kcode (ADR-008); CI runs the same commands |

## 1. Automated gates (§29.1)

Measured on 2026-11-27 with PHP 8.4.28 and pcov, against the runtime in `composer.lock`. The toolchain is the one `devkit.php` pins (PHPUnit 11.5.56, PHPStan 2.3.7, Psalm 6.19.1, PHP-CS-Fixer 3.95.27, Infection 0.35.5), run with the root configs.

| Gate | Command | Result |
|---|---|---|
| Code style | `composer cs-check` | PER-CS; 0 of 81 files to fix |
| Static analysis | `composer phpstan` | level max over `src`, `tests` and `tools`; 0 errors, no baseline |
| Type analysis | `composer psalm` | errorLevel 1; 0 errors, no baseline |
| Complexity | `composer complexity` | 177 methods; 4 accepted parameter-count breaches (§3); cyclomatic ≤ 10, nesting ≤ 3, cognitive ≤ 15 everywhere |
| Unit tests | `composer test-unit` | 182 tests, 624 assertions |
| Integration tests | `composer test-integration` | 9 tests, 30 assertions (real filesystem, end-to-end cache) |
| Conformance | `composer test-conformance` | 19 tests, 777 assertions |
| Coverage | `phpunit --coverage-text` (pcov) | classes 33/33, methods 162/162, lines 547/547 (100%) |
| Mutation | `composer mutation` | 721 mutants: 692 killed, 1 errored, 2 syntax errors, 26 escaped; MSI 96.4%, covered MSI 96.4% (minimum 80%) |
| Security | `composer security` | `composer audit` of the runtime and of the toolchain: no advisories; secret scan: clean |
| SBOM | `composer sbom` | CycloneDX 1.5: 2 runtime components; the toolchain as excluded components in CI |

The 26 escaped mutants are equivalent or unobservable in tests: expression statements whose value is discarded (`is_dir($d) || mkdir(...)`), the file mode and the error code of an internal ErrorException, `opcache_invalidate()` with OPcache off in the CLI, a shortcut in `RoutePattern::matchAgainst()` for static patterns, the in-memory memoisation of `RegexMatcher`, `isset()` on a `false` sentinel, and two `&&` guards whose second operand can only be null when the first is.

## 2. Production-readiness gates (§29.3)

| Gate | Status |
|---|---|
| G1 automated checks | Pass |
| G2 documentation (§17) | Pass, enforced by `SpecConformanceTest::docblocksMeetTheFiveCriteria` for all 40 types |
| G3 no P0 in manual review | Pass: the V4.0 review's bugs are fixed (§4) |
| G4 `arfa` and `asvs` declared | Pass (`extra.kariricode`, with `spec` 4.3.3) |
| G5 antipatterns (§18) | Pass; accepted deviations in §3 |
| G6 security gate (§24) | Pass: audit, secret scan, E-047 messages, control characters rejected by the built-in types. Open: ASVS requirement map, signed tags |

## 3. Accepted deviations

`SpecConformanceTest` and `tools/complexity-baseline.json` enforce this list exactly.

| Rule | Deviation | Reason |
|---|---|---|
| §13 parameters ≤ 4 | `RouteEntry::__construct` (9) | the route value object; every field is independent and set by named argument. A Parameter Object would wrap RouteEntry itself |
| §13 parameters ≤ 4 | `Attribute\Route::__construct` (7) | an attribute receives its arguments only through the constructor |
| §13 parameters ≤ 4 | `RouteRegistry::route` (7), `RouteGroup::map` (7) | named-argument builders over RouteEntry's fields; `addRoute(new RouteEntry(...))` is the Parameter Object form |
| `object` parameters | `RequestNormalizer::canNormalize()`, `normalize()`, `NormalizedRequest::__construct()` | the router cannot name a protocol's request type (ADR-002, Amendment) |
| §13 public methods ≤ 10 (soft) | `RouteRegistry` (12), `RouteGroup` (11) | the HTTP shortcuts; reported, not failing |

## 4. Code review V4.0, resolved

| Item | Resolution |
|---|---|
| BUG-001 CachedMatcher no-op | already rewritten in the tarball; 4.1 adds stores, format and checksum (ADR-003, Amendment) |
| BUG-002 CacheBridge dead | replaced by `Integration\SimpleCacheStore` (ADR-009) |
| BUG-003 mark disambiguation | worse than reported: no parameterized route matched; branch reset and MARK verbs (ADR-001, Amendment) |
| BUG-004 name prefix duplication | a test artefact; behaviour documented in `RouteGroup` |
| BUG-005 `{path:any}` encoded "/" | fixed in `RouteUrlGenerator` |
| ARCH-001 host and scheme unused | documented as carried, not matched; ADR-005 retargeted to 4.2 |
| ARCH-002/003/004/005 optional parameters, custom constraints, trailing slash, fallback | ADR-004 and ADR-006, proposed for 4.2; invalid syntax fails today, so the additions will not change existing patterns |
| ARCH-006 stdClass events | `Event\RouteMatched` and `Event\RouteMissed` through PSR-14 |
| Untested bridges and cached matcher | every class has unit tests; the file store and the cache round trip have integration tests |

Found during this import and fixed: `RouteEntry` declared a `callable` property, so the package could not load; two routes sharing a parameter name broke the combined expression; a trailing newline matched; static routes lost their defaults; definitions with typos silently became literal text.

## 5. Open items

1. Release: done. The repository became public and 4.1.0 was tagged on 2026-11-27 (unsigned, see item on signed tags); the registry row moved to released in kariricode/exception 2.0.1.
2. Signed tags (§32): releases are created from the GitHub UI, unsigned, for now.
3. The ASVS 5.0 requirement map.
4. ADR-004, ADR-005 and ADR-006 (4.2).

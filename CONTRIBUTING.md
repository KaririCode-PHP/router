# Contributing

## Gates

```bash
composer install     # from composer.lock: kariricode/exception and kariricode/contract
kcode init           # install the toolchain pinned in devkit.php into .kcode/
composer check       # cs-check, phpstan (max), psalm (1), complexity, the three suites, mutation
composer security    # audit of the runtime and the toolchain, secret scan
composer sbom        # CycloneDX 1.5 SBOM
```

[KaririCode Devkit](https://github.com/KaririCode-PHP/devkit) (`kcode`) installs the tools and runs this repository's configs (ADR-008). Install 1.2.1 and check it against its published SHA-256, as the README shows. Mutation testing needs pcov or Xdebug.

## Code rules (KaririCode Spec V4.3.3)

- **Files.** `declare(strict_types=1)`, one type per file, PSR-4, PER Coding Style.
- **Type docblocks.** Each states the domain role, its preconditions, thread-safety and side effects, a usage example, references and an `@see` link (§17). The conformance suite checks the markers.
- **Prohibited.** Zero-argument getters, setters, `toArray()`/`fromArray()`, magic methods, static state, `object` parameters beyond the accepted list (ADR-002), error suppression, and imports of KaririCode packages other than kariricode/exception. PSR interfaces may appear only in `src/Integration`.
- **Complexity.** Cyclomatic ≤ 10, cognitive ≤ 15, nesting ≤ 3, parameters ≤ 4 (§13). `tools/complexity-baseline.json` lists the accepted breaches with their reasons; it may only shrink.
- **Exceptions.** A failure is a final class on a kariricode/exception family base, built by a named constructor with a `RouterCode` case. Messages name routes, parameters, files or keys through `quote()` and never repeat the client's method, path or values (ADR-007). A new code takes the next free number in its range of `RouterCode`.
- **Tests.** Every bug fix ships with a test that fails without it. Unit tests use the fixtures in `tests/Fixture` (in-memory store, PSR-14 and PSR-16 doubles); filesystem behaviour belongs in `tests/Integration`.

## Decisions and releases

- **ADRs** go in `docs/adr/`, with Status, Date, Context, Decision, Alternatives Considered, Consequences and References.
- **Commits** follow Conventional Commits.
- **Releases** follow Semantic Versioning. Tags should be signed (`git tag -s`); releases created from the GitHub UI are not, for now.

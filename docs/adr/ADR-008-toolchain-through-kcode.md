# ADR-008: Quality toolchain through KaririCode Devkit, with the root configs

## Status

Accepted — 4.1

## Date

2026-11-27

## Context

The 4.0 package required `kariricode/devkit ^4.0` in `require-dev`, a version that does not exist, and shipped only `phpunit.xml.dist`: no static analysis, style, mutation or conformance configuration, and no CI. The rest of the ecosystem installs its toolchain with KaririCode Devkit (`kcode`), and Devkit 1.2.1 runs a component's committed configs (`'root_configs' => true`), so the Spec V4.3.3 gates (§29.1) can be kept at full strength: kariricode/contract, kariricode/exception, kariricode/property-inspector, kariricode/class-discovery and kariricode/processor-pipeline made the same move, each in an ADR of its own.

## Decision

- No `require-dev`. `devkit.php` pins the tools exactly: PHPUnit 11.5.56, PHPStan 2.3.7 with tomasvotruba/cognitive-complexity 1.2.0, Psalm 6.19.1, PHP-CS-Fixer 3.95.27 and Infection 0.35.5. kcode installs them into `.kcode/vendor/`.
- psr/event-dispatcher 1.0.0 and psr/simple-cache 3.0.0 are listed there too. They are not tools: the tests and the analysers need them for the two optional classes in `src/Integration`, which stay `suggest` dependencies. `tests/bootstrap.php` loads the project's autoloader first and the toolchain's second; PHPStan scans both packages and Psalm reads them as extra files.
- The root configs are what kcode runs: `phpunit.xml.dist` (unit, integration and conformance suites), `phpstan.neon` (level max), `psalm.xml` (errorLevel 1), `.php-cs-fixer.dist.php` (PER-CS), `infection.json5` (MSI and covered MSI ≥ 80).
- `tools/complexity.php` measures the §13 budgets on the AST; `tools/complexity-baseline.json` lists the four accepted parameter-count breaches with their reasons and can only shrink. `tools/secret-scan.php` and `tools/sbom.php` complete the security gate and the CycloneDX SBOM.
- `composer check` runs style, PHPStan, Psalm, complexity, the three suites and mutation; `composer security` audits the runtime and the toolchain and scans for secrets.
- `composer.lock` is committed and holds the runtime only: kariricode/exception 2.0.0 and kariricode/contract 2.12.0.
- CI downloads kcode 1.2.1 by version and checks its SHA-256 before `kcode init`; actions are pinned by commit SHA. A second job runs the suites on PHP 8.5 with the lowest dependencies the constraints allow.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Tools in `require-dev` | the ecosystem installs them through kcode; two models for one ecosystem |
| kcode with its generated configs | PHPStan level 9, Psalm level 3, no mutation or conformance gate: weaker than §29.1 |
| Unpinned tools | a gate result could not be reproduced from the commit that produced it (§32) |
| psr/* packages in `require` | every user would install two interface packages for classes most never load |

## Consequences

- One toolchain model across the ecosystem, with every §29.1 gate in CI.
- Contributors install kcode once (README, Development).
- Transitive versions of the toolchain resolve at install time until Devkit commits a `devkit.lock`.

## References

- KaririCode Spec V4.3.3 §13, §19.4, §29.1, §29.2, §32.
- KaririCode Devkit 1.2.1 (`root_configs`).

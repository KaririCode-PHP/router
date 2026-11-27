<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Conformance;

use KaririCode\Contract\Exception\Configuration;
use KaririCode\Contract\Exception\ContainerFailure;
use KaririCode\Contract\Exception\ExceptionInterface;
use KaririCode\Contract\Exception\NotFound;
use KaririCode\Contract\Exception\Protocol;
use KaririCode\Contract\Exception\Runtime;
use KaririCode\Contract\Exception\Validation;
use KaririCode\Exception\ConfigurationException;
use KaririCode\Exception\NotFoundException;
use KaririCode\Exception\RuntimeException;
use KaririCode\Exception\ValidationException;
use KaririCode\Router\Exception\RouterCode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * KaririCode Spec V4.3.3 conformance of src/ (§29.2): every rule runs over
 * every type. The accepted deviations are listed here and in the quality
 * report (docs/quality/QD-V433-COMPLIANCE.md §3), and the rules fail when
 * the list and the code drift apart.
 */
#[CoversNothing]
final class SpecConformanceTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../src';

    private const string ROOT = __DIR__ . '/../..';

    private const array FAMILIES = [
        Configuration::class,
        ContainerFailure::class,
        NotFound::class,
        Protocol::class,
        Runtime::class,
        Validation::class,
    ];

    private const array FAMILY_BASES = [
        ConfigurationException::class,
        NotFoundException::class,
        RuntimeException::class,
        ValidationException::class,
    ];

    /** Accepted deviation (ADR-002): the router cannot name a protocol's request type. */
    private const array OBJECT_PARAMETERS = [
        'KaririCode\Router\Contract\RequestNormalizer::canNormalize',
        'KaririCode\Router\Contract\RequestNormalizer::normalize',
        'KaririCode\Router\Core\NormalizedRequest::__construct',
    ];

    #[Test]
    public function eachFileDeclaresStrictTypesItsNamespaceAndOneTypeNamedAfterIt(): void
    {
        $found = [];

        foreach (self::files() as $relative => $file) {
            $source = (string) file_get_contents($file);
            $namespace = 'KaririCode\Router' . (dirname($relative) === '.' ? '' : '\\' . str_replace('/', '\\', dirname($relative)));
            $declared = preg_match_all('/^(?:final |abstract |readonly )*(?:class|interface|trait|enum) (\w+)/m', $source, $names);

            if (!str_contains($source, "declare(strict_types=1);\n\nnamespace {$namespace};")) {
                $found[] = $relative . ': strict types and namespace ' . $namespace;
            }

            if ($declared !== 1 || $names[1][0] !== basename($file, '.php')) {
                $found[] = $relative . ': one type named after the file';
            }
        }

        self::assertSame([], $found);
        self::assertCount(40, self::types(), 'src/ holds the 40 types of SPEC-001 §2');
    }

    #[Test]
    public function everyClassIsFinal(): void
    {
        foreach (self::types() as $type) {
            if (!$type->isInterface() && !$type->isTrait() && !$type->isEnum()) {
                self::assertTrue($type->isFinal(), $type->getName() . ' is final');
                self::assertFalse($type->isAbstract(), $type->getName());
            }
        }
    }

    #[Test]
    public function namesFollowSection16AndAvoidVagueNouns(): void
    {
        $found = [];

        foreach (self::types() as $type) {
            $name = $type->getShortName();

            if (preg_match('/Interface$|(?:Manager|Helper|Utils?)(?=[A-Z]|$)/', $name) === 1) {
                $found[] = $name . ': forbidden suffix (§16.3, §18)';
            }

            if ($type->isSubclassOf(\Throwable::class) !== str_ends_with($name, 'Exception')) {
                $found[] = $name . ': only exceptions end in Exception (§16.3)';
            }

            if (str_starts_with($type->getNamespaceName(), 'KaririCode\Router\Event') && preg_match('/(?:ed|ne|wn)$/', $name) !== 1) {
                $found[] = $name . ': events are past participles (§16.3)';
            }
        }

        self::assertSame([], $found);
    }

    #[Test]
    public function noZeroArgumentGettersSettersSerializersOrMagicMethods(): void
    {
        $found = [];

        foreach (self::ownMethods() as $name => $method) {
            $short = $method->getName();

            if (preg_match('/^get(?:[A-Z_]|$)/', $short) === 1 && $method->getNumberOfParameters() === 0) {
                $found[] = $name . ': zero-argument getter (§18)';
            }

            if (preg_match('/^set[A-Z]/', $short) === 1) {
                $found[] = $name . ': setter (§18)';
            }

            if (in_array(strtolower($short), ['toarray', 'fromarray', 'jsonserialize'], true)) {
                $found[] = $name . ': serializes itself (§18)';
            }

            if (str_starts_with($short, '__') && $short !== '__construct') {
                $found[] = $name . ': magic method (§29.2)';
            }
        }

        foreach (self::types() as $type) {
            if ($type->implementsInterface(\JsonSerializable::class)) {
                $found[] = $type->getName() . ': JsonSerializable (§18)';
            }
        }

        self::assertSame([], $found);
    }

    #[Test]
    public function stateIsWriteProtectedAndNeverStatic(): void
    {
        $found = [];

        foreach (self::types() as $type) {
            foreach ($type->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $type->getName()) {
                    continue;
                }

                if ($property->isStatic()) {
                    $found[] = $type->getName() . '::$' . $property->getName() . ': static state (§18, §29.2)';
                }

                if ($property->isPublic() && !self::isWriteProtected($property)) {
                    $found[] = $type->getName() . '::$' . $property->getName() . ': public and writable';
                }

                if ($property->isReadOnly() && $property->hasHooks()) {
                    $found[] = $type->getName() . '::$' . $property->getName() . ': hook on a readonly property (E-040)';
                }
            }
        }

        self::assertSame([], $found);
    }

    #[Test]
    public function constantsAreTyped(): void
    {
        $found = [];

        foreach (self::types() as $type) {
            foreach ($type->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() === $type->getName() && !$constant->isEnumCase() && !$constant->hasType()) {
                    $found[] = $type->getName() . '::' . $constant->getName();
                }
            }
        }

        self::assertSame([], $found);
    }

    #[Test]
    public function inheritanceStaysWithinTwoLevelsOfUserClasses(): void
    {
        foreach (self::types() as $type) {
            $depth = 0;

            for ($parent = $type->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
                $depth += $parent->isInternal() ? 0 : 1;
            }

            self::assertLessThanOrEqual(1, $depth, $type->getName() . ': a component class ends at depth 2 (§18)');
        }
    }

    #[Test]
    public function everyExceptionExtendsAFamilyBaseAndCarriesARouterCode(): void
    {
        foreach (self::types() as $type) {
            if (!$type->isSubclassOf(\Throwable::class)) {
                continue;
            }

            self::assertTrue($type->implementsInterface(ExceptionInterface::class), $type->getName());
            self::assertCount(1, array_intersect(self::FAMILIES, $type->getInterfaceNames()), $type->getName() . ': one family');
            self::assertContains($type->getParentClass() === false ? null : $type->getParentClass()->getName(), self::FAMILY_BASES, $type->getName() . ': extends a kariricode/exception base');
        }
    }

    #[Test]
    public function concreteExceptionsAreBuiltByNamedConstructorsThatUseRouterCode(): void
    {
        foreach (self::types() as $type) {
            if (!$type->isSubclassOf(\Throwable::class)) {
                continue;
            }

            $factories = 0;
            $source = (string) file_get_contents((string) $type->getFileName());

            foreach ($type->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $type->getName()) {
                    continue;
                }

                self::assertTrue($method->isStatic(), $type->getName() . '::' . $method->getName() . ' is a named constructor');
                // PHP 8.5 reports a self return type by the resolved class name.
                self::assertContains((string) $method->getReturnType(), ['self', $type->getName()]);
                ++$factories;
            }

            self::assertGreaterThan(0, $factories, $type->getName());
            self::assertSame(0, preg_match('/new self\(\s*+(?!RouterCode::)/', $source), $type->getName() . ': every code is a RouterCode case');
            self::assertFalse(self::interpolates($source), $type->getName() . ': no interpolated values; identifiers go through quote() (E-047)');
        }
    }

    #[Test]
    public function docblocksMeetTheFiveCriteria(): void
    {
        $markers = [
            'preconditions (C2)' => 'Preconditions',
            'thread-safety (C2)' => 'Thread-safety:',
            'side effects (C3)' => 'Side effects:',
            'example (C4)' => 'Example',
            'references (C5)' => 'References:',
            'link (C5)' => '@see https://',
        ];
        $missing = [];

        foreach (self::types() as $type) {
            $docblock = (string) $type->getDocComment();

            foreach ($markers as $criterion => $marker) {
                if (!str_contains($docblock, $marker)) {
                    $missing[] = $type->getShortName() . ': ' . $criterion;
                }
            }

            if (preg_match('/@(?:package|author|license|copyright|version)\b/', $docblock) === 1) {
                $missing[] = $type->getShortName() . ': authorship tag';
            }

            if (preg_match('/\A\/\*\*\s+\*\s+Represents\b/', $docblock) === 1) {
                $missing[] = $type->getShortName() . ': C1 states the domain role, not "Represents"';
            }
        }

        self::assertSame([], $missing);
    }

    #[Test]
    public function adrAndSpecReferencesResolve(): void
    {
        $missing = [];
        $adrs = array_map(static fn(string $file): string => substr(basename($file), 0, 7), glob(self::ROOT . '/docs/adr/ADR-*.md') ?: []);

        foreach (self::files() as $relative => $file) {
            preg_match_all('/\b(ADR-\d{3})\b/', (string) file_get_contents($file), $references);

            foreach (array_unique($references[1]) as $adr) {
                if (!in_array($adr, $adrs, true)) {
                    $missing[] = $relative . ': ' . $adr;
                }
            }

            if (str_contains((string) file_get_contents($file), 'SPEC-001') && !is_file(self::ROOT . '/docs/spec/SPEC-001-router-architecture.md')) {
                $missing[] = $relative . ': SPEC-001';
            }
        }

        self::assertSame([], $missing, 'all ADR/SPEC references intact (§29.2)');
    }

    #[Test]
    public function objectParametersAreTheAcceptedDeviationOnly(): void
    {
        $found = [];

        foreach (self::ownMethods() as $name => $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && $type->getName() === 'object') {
                    $found[] = $name;
                }
            }
        }

        self::assertSame(self::OBJECT_PARAMETERS, array_values(array_unique($found)));
    }

    #[Test]
    public function sourceAvoidsSuppressionAndDebugConstructs(): void
    {
        $found = [];

        foreach (self::files() as $relative => $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if ($token === '@') {
                    $found[] = $relative . ': error suppression';
                }

                if (is_array($token) && in_array($token[0], [T_EVAL, T_EXIT, T_PRINT], true)) {
                    $found[] = $relative . ': ' . $token[1];
                }

                if (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), ['var_dump', 'print_r', 'var_export', 'error_log'], true) && $relative !== 'Cache/PhpFileStore.php') {
                    $found[] = $relative . ': ' . $token[1];
                }
            }
        }

        self::assertSame([], $found);
    }

    #[Test]
    public function theRuntimeDependsOnPhpAndTheIntegratorPackagesOnly(): void
    {
        /** @var array{require: array<string, string>, suggest: array<string, string>, extra: array{kariricode: array{arfa: array{version: string, level: int}, asvs: array{version: string, level: int}, spec: string}}} $composer */
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['php' => '^8.4', 'kariricode/exception' => '^2.0'], $composer['require'], 'Spec V4.3.3 §31: coupling through the integrator packages');
        self::assertSame(['psr/event-dispatcher', 'psr/simple-cache'], array_keys($composer['suggest']));
        self::assertSame(['version' => '1.3', 'level' => 1], $composer['extra']['kariricode']['arfa'], '§29.3 G4');
        self::assertSame(['version' => '5.0', 'level' => 1], $composer['extra']['kariricode']['asvs'], '§29.3 G4');
        self::assertSame('4.3.3', $composer['extra']['kariricode']['spec'], 'E-044');
    }

    #[Test]
    public function sourceImportsNoOtherKaririCodePackageAndPsrOnlyInIntegration(): void
    {
        $found = [];

        foreach (self::files() as $relative => $file) {
            preg_match_all('/^use ((?:KaririCode|Psr)\\\\[A-Za-z]+)\\\\/m', (string) file_get_contents($file), $imports);

            foreach ($imports[1] as $namespace) {
                $allowed = in_array($namespace, ['KaririCode\Router', 'KaririCode\Exception'], true)
                    || (str_starts_with($namespace, 'Psr\\') && str_starts_with($relative, 'Integration/'));

                if (!$allowed) {
                    $found[] = $relative . ': ' . $namespace;
                }
            }
        }

        self::assertSame([], $found, 'no circular dependency is possible (§31); PSR interfaces stay optional');
    }

    #[Test]
    public function theCodeEnumIsTheOnlyEnumThatCarriesErrorCodes(): void
    {
        $codeEnums = array_filter(self::types(), static fn(ReflectionClass $type): bool => $type->isEnum() && $type->implementsInterface(\KaririCode\Exception\ErrorCode::class));

        self::assertSame([RouterCode::class], array_values(array_map(static fn(ReflectionClass $type): string => $type->getName(), $codeEnums)));
    }

    /**
     * @return array<string, string> path relative to src/ => absolute path
     */
    private static function files(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCE, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen(self::SOURCE) + 1)] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return list<ReflectionClass<object>>
     */
    private static function types(): array
    {
        $types = [];

        foreach (array_keys(self::files()) as $relative) {
            $name = 'KaririCode\Router\\' . str_replace('/', '\\', substr($relative, 0, -4));
            self::assertTrue(class_exists($name) || interface_exists($name) || enum_exists($name), $name);

            /** @var class-string $name */
            $types[] = new ReflectionClass($name);
        }

        return $types;
    }

    /**
     * @return array<string, ReflectionMethod>
     */
    private static function ownMethods(): array
    {
        $methods = [];

        foreach (self::types() as $type) {
            foreach ($type->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() === $type->getName()) {
                    $methods[$type->getName() . '::' . $method->getName()] = $method;
                }
            }
        }

        return $methods;
    }

    /** True when the source builds a string by interpolating a variable. */
    private static function interpolates(string $source): bool
    {
        foreach (token_get_all($source) as $token) {
            if ($token === '"' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_START_HEREDOC], true))) {
                return true;
            }
        }

        return false;
    }

    /** readonly, private(set) or protected(set): §18 accepts all three. */
    private static function isWriteProtected(ReflectionProperty $property): bool
    {
        return $property->isReadOnly() || $property->isPrivateSet() || $property->isProtectedSet();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Core;

use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;

final class ArchitectureTest extends TestCase
{
    public function test_core_dependencies_are_limited_to_the_allowed_direction_and_native_php(): void
    {
        $root = dirname(__DIR__, 2).'/app';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $files = 0;

        foreach (['Domain', 'Application'] as $layer) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$layer)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $files++;
                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                $tree = (new NodeTraverser(new NameResolver))->traverse($parser->parse($source) ?? []);

                foreach ($finder->findInstanceOf($tree, Name::class) as $name) {
                    self::assertTrue($this->allowed($name->toString(), $layer), $file->getPathname().': forbidden dependency '.$name->toString());
                }
            }
        }

        self::assertGreaterThan(0, $files, 'The architecture gate must inspect real core source files.');
    }

    private function allowed(string $name, string $layer): bool
    {
        if (str_starts_with($name, 'App\\Domain\\')
            || ($layer === 'Application' && str_starts_with($name, 'App\\Application\\'))) {
            return true;
        }

        if (in_array(strtolower($name), ['self', 'static', 'parent', 'true', 'false', 'null'], true)) {
            return true;
        }

        if (class_exists($name, false) || interface_exists($name, false)) {
            return (new ReflectionClass($name))->isInternal();
        }

        if (function_exists($name)) {
            return (new ReflectionFunction($name))->isInternal();
        }

        return in_array($name, ['PHP_INT_SIZE', 'PHP_INT_MIN', 'PHP_INT_MAX', 'JSON_UNESCAPED_UNICODE', 'JSON_UNESCAPED_SLASHES', 'JSON_THROW_ON_ERROR', 'FILTER_VALIDATE_EMAIL'], true);
    }

    public function test_repository_boundaries_expose_only_scalars_enums_and_readonly_data(): void
    {
        $root = dirname(__DIR__, 2).'/app/Application';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/Contracts/')) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $contract = new ReflectionClass('App\\Application\\'.str_replace('/', '\\', $relative));
            self::assertTrue($contract->isInterface());
            foreach ($contract->getMethods() as $method) {
                $types = [$method->getReturnType(), ...array_map(fn ($parameter) => $parameter->getType(), $method->getParameters())];

                foreach ($types as $type) {
                    self::assertInstanceOf(ReflectionNamedType::class, $type);

                    if (! $type->isBuiltin()) {
                        $class = new ReflectionClass($type->getName());
                        self::assertTrue($class->isEnum() || ($class->isReadOnly() && str_ends_with($class->getShortName(), 'Data')));
                    }
                }
            }
        }
    }
}

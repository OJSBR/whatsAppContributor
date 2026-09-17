<?php

/**
 * @file plugins/generic/whatsAppContributor/tests/PluginTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PluginTest
 *
 * @brief The plugin classes compiled against the PKP classes of the
 *        installation: an override whose return type differs from its parent is
 *        a fatal error that php -l does not catch.
 */

namespace APP\plugins\generic\whatsAppContributor\tests;

use PKP\tests\PKPTestCase;
use ReflectionClass;
use ReflectionNamedType;

class PluginTest extends PKPTestCase
{
    /** @return string[] */
    protected function classes(): array
    {
        return [
            \APP\plugins\generic\whatsAppContributor\WhatsAppContributorPlugin::class,
            \APP\plugins\generic\whatsAppContributor\WhatsAppSettingsForm::class,
        ];
    }

    public function testEveryClassLoadsAgainstThisPkpVersion(): void
    {
        foreach ($this->classes() as $class) {
            $this->assertTrue(class_exists($class), "{$class} does not load.");
        }
    }

    public function testEveryImportedClassExistsInThisInstallation(): void
    {
        $files = array_merge(
            glob(dirname(__DIR__) . '/*.php') ?: [],
            glob(dirname(__DIR__) . '/classes/*.php') ?: [],
            glob(dirname(__DIR__) . '/classes/*/*.php') ?: []
        );
        $this->assertNotEmpty($files, 'no source file was found to read');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source, $matches);
            foreach ($matches[1] as $imported) {
                // A name that does not resolve is a fatal error the moment that
                // code path runs, and nothing short of running it would show it:
                // php -l and class_exists() on the plugin itself both pass.
                $this->assertTrue(
                    class_exists($imported) || interface_exists($imported) || trait_exists($imported) || function_exists($imported),
                    sprintf('%s imports %s, which does not exist here.', basename($file), $imported)
                );
            }
        }
    }

    public function testOverriddenMethodsDeclareCompatibleReturnTypes(): void
    {
        foreach ($this->classes() as $class) {
            $reflection = new ReflectionClass($class);
            $parent = $reflection->getParentClass();
            $this->assertTrue($parent !== false, "{$class} extends nothing.");
            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName() || !$parent->hasMethod($method->getName())) {
                    continue;
                }
                $parentType = $parent->getMethod($method->getName())->getReturnType();
                if ($parentType === null) {
                    continue;
                }
                $type = $method->getReturnType();
                $covariant = $type instanceof ReflectionNamedType && $parentType instanceof ReflectionNamedType
                    && !$type->isBuiltin() && !$parentType->isBuiltin() && is_a($type->getName(), $parentType->getName(), true);
                $this->assertTrue(
                    $type !== null && ((string) $type === (string) $parentType || $covariant || ($type instanceof ReflectionNamedType && '?' . $type->getName() === (string) $parentType)),
                    sprintf('%s::%s() must declare a return type compatible with %s.', $reflection->getShortName(), $method->getName(), $parentType)
                );
            }
        }
    }

    public function testTheRegistryFindsThePlugin(): void
    {
        // PKP looks for APP\plugins\<category>\<dir>\<Dir>Plugin first and only then for index.php:
        // a main class named otherwise without index.php is never loaded, and nothing is logged.
        $root = dirname(__DIR__);
        $product = basename($root);
        $category = basename(dirname($root));
        if (is_file($root . '/index.php')) {
            $this->assertStringContainsString('return new ', (string) file_get_contents($root . '/index.php'));
            return;
        }
        $class = implode(chr(92), ['APP', 'plugins', $category, $product, ucfirst($product) . 'Plugin']);
        $this->assertTrue(class_exists($class), "Without index.php the main class must be {$class}.");
    }

    /**
     * Every hook of an old-style form is registered under the name the core
     * really fires.
     *
     * Form.php does not write those names the same way: the constructor and the
     * display lowercase the class name and keep the method as it is, while
     * readUserVars(), execute(), initData(), validate() and getLocaleFieldNames()
     * lowercase the whole name. A name in the wrong case raises nothing at all:
     * the hook is simply never called, and the plugin quietly does nothing. The
     * expected spelling is read from the installation itself, so the test follows
     * the core rather than a copy of it.
     */
    public function testLegacyFormHooksUseTheNameTheCoreFires(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/WhatsAppContributorPlugin.php');
        preg_match_all('/Hook::add\(\'([a-z]+form::[A-Za-z]+)\'/', $source, $registered);
        $this->assertNotEmpty($registered[1], 'the plugin registers hooks of the old forms');

        $form = (string) file_get_contents(BASE_SYS_DIR . '/lib/pkp/classes/form/Form.php');
        // Hook::call(strtolower(end($classNameParts) . '::method')) — all lower case.
        // (3.4 spells the same thing strtolower_codesafe().)
        preg_match_all('/Hook::call\(strtolower(?:_codesafe)?\(end\(\$classNameParts\) \. \'::([A-Za-z]+)\'\)/', $form, $whole);
        // Hook::call(strtolower(end($classNameParts)) . '::Method') — the method keeps its case.
        preg_match_all('/Hook::call\(strtolower(?:_codesafe)?\(end\(\$classNameParts\)\) \. \'::([A-Za-z]+)\'/', $form, $partial);
        $this->assertNotEmpty($whole[1], 'Form.php still fires hooks lowercased whole');

        $expected = [];
        foreach ($whole[1] as $method) {
            $expected[strtolower($method)] = strtolower($method);
        }
        foreach ($partial[1] as $method) {
            $expected[strtolower($method)] = $method;
        }

        foreach ($registered[1] as $name) {
            [$class, $method] = explode('::', $name);
            $this->assertArrayHasKey(strtolower($method), $expected, "Form.php fires no hook {$method}.");
            $this->assertSame(
                $class . '::' . $expected[strtolower($method)],
                $name,
                "The core fires {$class}::{$expected[strtolower($method)]}; a hook registered as {$name} never runs."
            );
        }
    }

    public function testNoInheritedPropertyIsRedeclaredWithAType(): void
    {
        // A typed redeclaration of an untyped parent property ($pluginPath...) is fatal.
        $this->assertNotEmpty($this->classes());
        foreach ($this->classes() as $class) {
            $reflection = new ReflectionClass($class);
            $parent = $reflection->getParentClass();
            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $reflection->getName() || !$parent->hasProperty($property->getName())) {
                    continue;
                }
                $this->assertSame((string) $parent->getProperty($property->getName())->getType(), (string) $property->getType(), "{$class}::\${$property->getName()}");
            }
        }
    }
}

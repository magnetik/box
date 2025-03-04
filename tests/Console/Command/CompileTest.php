<?php

declare(strict_types=1);

/*
 * This file is part of the box project.
 *
 * (c) Kevin Herrera <kevin@herrera.io>
 *     Théo Fidry <theo.fidry@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace KevinGH\Box\Console\Command;

use function array_merge;
use function array_unique;
use function chdir;
use DirectoryIterator;
use function exec;
use function extension_loaded;
use function file_get_contents;
use Generator;
use function get_loaded_extensions;
use function implode;
use InvalidArgumentException;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use const JSON_PRETTY_PRINT;
use KevinGH\Box\Compactor\Php;
use KevinGH\Box\Composer\ComposerOrchestrator;
use KevinGH\Box\Console\DisplayNormalizer;
use function KevinGH\Box\FileSystem\chmod;
use function KevinGH\Box\FileSystem\dump_file;
use function KevinGH\Box\FileSystem\file_contents;
use function KevinGH\Box\FileSystem\mirror;
use function KevinGH\Box\FileSystem\remove;
use function KevinGH\Box\FileSystem\rename;
use function KevinGH\Box\FileSystem\touch;
use function KevinGH\Box\format_size;
use function KevinGH\Box\get_box_version;
use function KevinGH\Box\memory_to_bytes;
use KevinGH\Box\Test\CommandTestCase;
use KevinGH\Box\Test\RequiresPharReadonlyOff;
use function mt_getrandmax;
use Phar;
use PharFileInfo;
use const PHP_VERSION;
use function phpversion;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function random_int;
use function realpath;
use function sort;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\PhpExecutableFinder;
use Traversable;

/**
 * @covers \KevinGH\Box\Console\Command\Compile
 * @covers \KevinGH\Box\Console\MessageRenderer
 *
 * @runTestsInSeparateProcesses This is necessary as instantiating a PHAR in memory may load/autoload some stuff which
 *                              can create undesirable side-effects.
 */
class CompileTest extends CommandTestCase
{
    use RequiresPharReadonlyOff;

    private const FIXTURES_DIR = __DIR__.'/../../../fixtures/build';

    private static $runComposer2 = false;

    /**
     * {@inheritdoc}
     */
    public static function setUpBeforeClass(): void
    {
        self::$runComposer2 = version_compare(ComposerOrchestrator::getVersion(), '2', '>=');
    }

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->markAsSkippedIfPharReadonlyIsOn();

        parent::setUp();

        $this->commandTester = new CommandTester($this->application->get($this->getCommand()->getName()));

        remove(self::FIXTURES_DIR.'/dir010/index.phar');
    }

    /**
     * {@inheritdoc}
     */
    protected function getCommand(): Command
    {
        return new Compile();
    }

    public function test_it_can_build_a_PHAR_file(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        dump_file('composer.json', '{}');
        dump_file('composer.lock', '{}');
        dump_file('vendor/composer/installed.json', '{}');

        $shebang = sprintf('#!%s', (new PhpExecutableFinder())->find());

        $numberOfFiles = 45;
        if (self::$runComposer2) {
            // From Composer 2 there are more files
            $numberOfFiles += 2;
        }

        dump_file(
            'box.json',
            json_encode(
                [
                    'alias' => 'alias-test.phar',
                    'banner' => 'custom banner',
                    'chmod' => '0700',
                    'compactors' => [Php::class],
                    'directories' => ['a'],
                    'files' => ['test.php', 'vendor/composer/installed.json'],
                    'finder' => [['in' => 'one']],
                    'finder-bin' => [['in' => 'two']],
                    'algorithm' => 'OPENSSL',
                    'key' => 'private.key',
                    'key-pass' => true,
                    'main' => 'run.php',
                    'map' => [
                        ['a/deep/test/directory' => 'sub'],
                    ],
                    'metadata' => ['rand' => $rand = random_int(0, mt_getrandmax())],
                    'output' => 'test.phar',
                    'shebang' => $shebang,
                ]
            )
        );

        $this->commandTester->setInputs(['test']);    // Set input for the passphrase
        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test.phar"

? Removing the existing PHAR "/path/to/tmp/test.phar"
? Registering compactors
  + KevinGH\Box\Compactor\Php
? Mapping paths
  - a/deep/test/directory > sub
? Adding main file: /path/to/tmp/run.php
? Adding requirements checker
? Adding binary files
    > 1 file(s)
? Auto-discover files? No
? Exclude dev files? Yes
? Adding files
    > 6 file(s)
? Generating new stub
  - Using shebang line: $shebang
  - Using banner:
    > custom banner
? Setting metadata
  - array (
  'rand' => $rand,
)
? Dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Signing using a private key

 Private key passphrase:
 >

? Setting file permissions to 0700
* Done.

No recommendation found.
No warning found.

 // PHAR: $numberOfFiles files (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual, 'Expected logs to be identical');

        $this->assertSame(
            'Hello, world!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );

        $phar = new Phar('test.phar');

        $this->assertSame('OpenSSL', $phar->getSignature()['hash_type']);

        // Check PHAR content
        $actualStub = $this->normalizeDisplay($phar->getStub());
        $expectedStub = <<<PHP
$shebang
<?php

/*
 * custom banner
 */

Phar::mapPhar('alias-test.phar');

require 'phar://alias-test.phar/.box/bin/check-requirements.php';

require 'phar://alias-test.phar/run.php';

__HALT_COMPILER(); ?>

PHP;

        $this->assertSame($expectedStub, $actualStub);

        $this->assertSame(
            ['rand' => $rand],
            $phar->getMetadata(),
            'Expected PHAR metadata to be set'
        );

        $expectedFiles = [
            '/.box/',
            '/.box/.requirements.php',
            '/.box/bin/',
            '/.box/bin/check-requirements.php',
            '/.box/src/',
            '/.box/src/Checker.php',
            '/.box/src/IO.php',
            '/.box/src/IsExtensionFulfilled.php',
            '/.box/src/IsFulfilled.php',
            '/.box/src/IsPhpVersionFulfilled.php',
            '/.box/src/Printer.php',
            '/.box/src/Requirement.php',
            '/.box/src/RequirementCollection.php',
            '/.box/src/Terminal.php',
            '/.box/vendor/',
            '/.box/vendor/autoload.php',
            '/.box/vendor/composer/',
            '/.box/vendor/composer/ClassLoader.php',
            '/.box/vendor/composer/InstalledVersions.php',
            '/.box/vendor/composer/LICENSE',
            '/.box/vendor/composer/autoload_classmap.php',
            '/.box/vendor/composer/autoload_namespaces.php',
            '/.box/vendor/composer/autoload_psr4.php',
            '/.box/vendor/composer/autoload_real.php',
            '/.box/vendor/composer/autoload_static.php',
            '/.box/vendor/composer/platform_check.php',
            '/.box/vendor/composer/semver/',
            '/.box/vendor/composer/semver/LICENSE',
            '/.box/vendor/composer/semver/src/',
            '/.box/vendor/composer/semver/src/Comparator.php',
            '/.box/vendor/composer/semver/src/CompilingMatcher.php',
            '/.box/vendor/composer/semver/src/Constraint/',
            '/.box/vendor/composer/semver/src/Constraint/Bound.php',
            '/.box/vendor/composer/semver/src/Constraint/Constraint.php',
            '/.box/vendor/composer/semver/src/Constraint/ConstraintInterface.php',
            '/.box/vendor/composer/semver/src/Constraint/MatchAllConstraint.php',
            '/.box/vendor/composer/semver/src/Constraint/MatchNoneConstraint.php',
            '/.box/vendor/composer/semver/src/Constraint/MultiConstraint.php',
            '/.box/vendor/composer/semver/src/Interval.php',
            '/.box/vendor/composer/semver/src/Intervals.php',
            '/.box/vendor/composer/semver/src/Semver.php',
            '/.box/vendor/composer/semver/src/VersionParser.php',
            '/one/',
            '/one/test.php',
            '/run.php',
            '/sub/',
            '/sub/test.php',
            '/test.php',
            '/two/',
            '/two/test.png',
            '/vendor/',
            '/vendor/autoload.php',
            '/vendor/composer/',
            '/vendor/composer/ClassLoader.php',
            '/vendor/composer/LICENSE',
            '/vendor/composer/autoload_classmap.php',
            '/vendor/composer/autoload_namespaces.php',
            '/vendor/composer/autoload_psr4.php',
            '/vendor/composer/autoload_real.php',
            '/vendor/composer/autoload_static.php',
        ];

        if (!self::$runComposer2) {
            $expectedFiles = array_values(array_filter($expectedFiles, static function ($file): bool {
                return '/.box/vendor/composer/platform_check.php' !== $file
                && '/.box/vendor/composer/InstalledVersions.php' !== $file;
            }));
        }

        $actualFiles = $this->retrievePharFiles($phar);

        $this->assertSame($expectedFiles, $actualFiles);
    }

    public function test_it_can_build_a_PHAR_from_a_different_directory(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        $shebang = sprintf('#!%s', (new PhpExecutableFinder())->find());

        dump_file(
            'box.json',
            json_encode(
                [
                    'alias' => 'alias-test.phar',
                    'banner' => 'custom banner',
                    'chmod' => '0755',
                    'compactors' => [Php::class],
                    'directories' => ['a'],
                    'files' => ['test.php'],
                    'finder' => [['in' => 'one']],
                    'finder-bin' => [['in' => 'two']],
                    'algorithm' => 'OPENSSL',
                    'key' => 'private.key',
                    'key-pass' => true,
                    'main' => 'run.php',
                    'map' => [
                        ['a/deep/test/directory' => 'sub'],
                    ],
                    'metadata' => ['rand' => random_int(0, mt_getrandmax())],
                    'output' => 'test.phar',
                    'shebang' => $shebang,
                ]
            )
        );

        chdir($this->cwd);

        $this->commandTester->setInputs(['test']);    // Set input for the passphrase
        $this->commandTester->execute(
            [
                'command' => 'compile',
                '--working-dir' => $this->tmp,
            ],
            ['interactive' => true]
        );

        $this->assertSame(
            'Hello, world!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );
    }

    public function test_it_can_build_a_PHAR_without_any_configuration(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        rename('run.php', 'index.php');

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $version = get_box_version();

        $numberOfFiles = 49;
        if (self::$runComposer2) {
            // From Composer 2 there are more files
            $numberOfFiles += 2;
        }

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading without a configuration file.

🔨  Building the PHAR "/path/to/tmp/index.phar"

? No compactor to register
? Adding main file: /path/to/tmp/index.php
? Adding requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? Yes
? Adding files
    > 9 file(s)
? Generating new stub
  - Using shebang line: #!/usr/bin/env php
  - Using banner:
    > Generated by Humbug Box $version.
    >
    > @link https://github.com/humbug/box
? Dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: $numberOfFiles files (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual, 'Expected logs to be identical');

        $this->assertSame(
            'Hello, world!',
            exec('php index.phar'),
            'Expected PHAR to be executable'
        );

        $phar = new Phar('index.phar');

        $this->assertSame('SHA-1', $phar->getSignature()['hash_type']);

        // Check PHAR content
        $actualStub = preg_replace(
            '/box-auto-generated-alias-[\da-zA-Z]{12}\.phar/',
            'box-auto-generated-alias-__uniqid__.phar',
            $this->normalizeDisplay($phar->getStub())
        );

        $expectedStub = <<<PHP
#!/usr/bin/env php
<?php

/*
 * Generated by Humbug Box $version.
 *
 * @link https://github.com/humbug/box
 */

Phar::mapPhar('box-auto-generated-alias-__uniqid__.phar');

require 'phar://box-auto-generated-alias-__uniqid__.phar/.box/bin/check-requirements.php';

require 'phar://box-auto-generated-alias-__uniqid__.phar/index.php';

__HALT_COMPILER(); ?>

PHP;

        $this->assertSame($expectedStub, $actualStub);

        $this->assertNull(
            $phar->getMetadata(),
            'Expected PHAR metadata to be set'
        );

        $expectedFiles = [
            '/.box/',
            '/.box/.requirements.php',
            '/.box/bin/',
            '/.box/bin/check-requirements.php',
            '/.box/src/',
            '/.box/src/Checker.php',
            '/.box/src/IO.php',
            '/.box/src/IsExtensionFulfilled.php',
            '/.box/src/IsFulfilled.php',
            '/.box/src/IsPhpVersionFulfilled.php',
            '/.box/src/Printer.php',
            '/.box/src/Requirement.php',
            '/.box/src/RequirementCollection.php',
            '/.box/src/Terminal.php',
            '/.box/vendor/',
            '/.box/vendor/autoload.php',
            '/.box/vendor/composer/',
            '/.box/vendor/composer/ClassLoader.php',
            '/.box/vendor/composer/InstalledVersions.php',
            '/.box/vendor/composer/LICENSE',
            '/.box/vendor/composer/autoload_classmap.php',
            '/.box/vendor/composer/autoload_namespaces.php',
            '/.box/vendor/composer/autoload_psr4.php',
            '/.box/vendor/composer/autoload_real.php',
            '/.box/vendor/composer/autoload_static.php',
            '/.box/vendor/composer/platform_check.php',
            '/.box/vendor/composer/semver/',
            '/.box/vendor/composer/semver/LICENSE',
            '/.box/vendor/composer/semver/src/',
            '/.box/vendor/composer/semver/src/Comparator.php',
            '/.box/vendor/composer/semver/src/CompilingMatcher.php',
            '/.box/vendor/composer/semver/src/Constraint/',
            '/.box/vendor/composer/semver/src/Constraint/Bound.php',
            '/.box/vendor/composer/semver/src/Constraint/Constraint.php',
            '/.box/vendor/composer/semver/src/Constraint/ConstraintInterface.php',
            '/.box/vendor/composer/semver/src/Constraint/MatchAllConstraint.php',
            '/.box/vendor/composer/semver/src/Constraint/MatchNoneConstraint.php',
            '/.box/vendor/composer/semver/src/Constraint/MultiConstraint.php',
            '/.box/vendor/composer/semver/src/Interval.php',
            '/.box/vendor/composer/semver/src/Intervals.php',
            '/.box/vendor/composer/semver/src/Semver.php',
            '/.box/vendor/composer/semver/src/VersionParser.php',
            '/binary',
            '/bootstrap.php',
            '/index.php',
            '/one/',
            '/one/test.php',
            '/private.key',
            '/test.phar',
            '/test.phar.pubkey',
            '/test.php',
            '/two/',
            '/two/test.png',
            '/vendor/',
            '/vendor/autoload.php',
            '/vendor/composer/',
            '/vendor/composer/ClassLoader.php',
            '/vendor/composer/LICENSE',
            '/vendor/composer/autoload_classmap.php',
            '/vendor/composer/autoload_namespaces.php',
            '/vendor/composer/autoload_psr4.php',
            '/vendor/composer/autoload_real.php',
            '/vendor/composer/autoload_static.php',
        ];

        if (!self::$runComposer2) {
            $expectedFiles = array_values(array_filter($expectedFiles, static function ($file): bool {
                return '/.box/vendor/composer/platform_check.php' !== $file
                && '/.box/vendor/composer/InstalledVersions.php' !== $file;
            }));
        }

        $actualFiles = $this->retrievePharFiles($phar);

        $this->assertSame($expectedFiles, $actualFiles);

        unset($phar);
        Phar::unlinkArchive('index.phar');
        // Executes the compilation again

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $this->assertSame(
            'Hello, world!',
            exec('php index.phar'),
            'Expected PHAR to be executable'
        );
    }

    public function test_it_can_build_a_PHAR_with_complete_mapping(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        dump_file(
            'box.json',
            json_encode(
                [
                    'alias' => 'alias-test.phar',
                    'check-requirements' => false,
                    'chmod' => '0754',
                    'compactors' => [Php::class],
                    'directories' => ['a'],
                    'files' => ['test.php'],
                    'finder' => [['in' => 'one']],
                    'finder-bin' => [['in' => 'two']],
                    'main' => 'run.php',
                    'map' => [
                        ['a/deep/test/directory' => 'sub'],
                    ],
                    'metadata' => ['rand' => $rand = random_int(0, mt_getrandmax())],
                    'output' => 'test.phar',
                ]
            )
        );

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $version = get_box_version();

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test.phar"

? Removing the existing PHAR "/path/to/tmp/test.phar"
? Registering compactors
  + KevinGH\Box\Compactor\Php
? Mapping paths
  - a/deep/test/directory > sub
? Adding main file: /path/to/tmp/run.php
? Skip requirements checker
? Adding binary files
    > 1 file(s)
? Auto-discover files? No
? Exclude dev files? Yes
? Adding files
    > 4 file(s)
? Generating new stub
  - Using shebang line: #!/usr/bin/env php
  - Using banner:
    > Generated by Humbug Box $version.
    >
    > @link https://github.com/humbug/box
? Setting metadata
  - array (
  'rand' => $rand,
)
? Dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0754
* Done.

No recommendation found.
No warning found.

 // PHAR: 13 files (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual, 'Expected logs to be identical');

        $this->assertSame(
            'Hello, world!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );

        $this->assertSame(
            'Hello, world!',
            exec('cp test.phar test; php test'),
            'Expected PHAR can be renamed'
        );

        $phar = new Phar('test.phar');

        // Check PHAR content
        $actualStub = $this->normalizeDisplay($phar->getStub());
        $expectedStub = <<<PHP
#!/usr/bin/env php
<?php

/*
 * Generated by Humbug Box $version.
 *
 * @link https://github.com/humbug/box
 */

Phar::mapPhar('alias-test.phar');

require 'phar://alias-test.phar/run.php';

__HALT_COMPILER(); ?>

PHP;

        $this->assertSame($expectedStub, $actualStub);

        $this->assertSame(
            ['rand' => $rand],
            $phar->getMetadata(),
            'Expected PHAR metadata to be set'
        );

        $expectedFiles = [
            '/one/',
            '/one/test.php',
            '/run.php',
            '/sub/',
            '/sub/test.php',
            '/test.php',
            '/two/',
            '/two/test.png',
            '/vendor/',
            '/vendor/autoload.php',
            '/vendor/composer/',
            '/vendor/composer/ClassLoader.php',
            '/vendor/composer/LICENSE',
            '/vendor/composer/autoload_classmap.php',
            '/vendor/composer/autoload_namespaces.php',
            '/vendor/composer/autoload_psr4.php',
            '/vendor/composer/autoload_real.php',
            '/vendor/composer/autoload_static.php',
        ];

        $actualFiles = $this->retrievePharFiles($phar);

        $this->assertSame($expectedFiles, $actualFiles);
    }

    public function test_it_can_build_a_PHAR_with_complete_mapping_without_an_alias(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        dump_file(
            'box.json',
            json_encode(
                [
                    'check-requirements' => false,
                    'chmod' => '0754',
                    'compactors' => [Php::class],
                    'directories' => ['a'],
                    'files' => ['test.php'],
                    'finder' => [['in' => 'one']],
                    'finder-bin' => [['in' => 'two']],
                    'main' => 'run.php',
                    'map' => [
                        ['a/deep/test/directory' => 'sub'],
                    ],
                    'metadata' => ['rand' => random_int(0, mt_getrandmax())],
                    'output' => 'test.phar',
                ]
            )
        );

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $this->assertSame(
            'Hello, world!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );

        $this->assertSame(
            'Hello, world!',
            exec('cp test.phar test; php test'),
            'Expected PHAR can be renamed'
        );

        $phar = new Phar('test.phar');

        // Check PHAR content
        $actualStub = preg_replace(
            '/box-auto-generated-alias-[\da-zA-Z]{12}\.phar/',
            'box-auto-generated-alias-__uniqid__.phar',
            $this->normalizeDisplay($phar->getStub())
        );

        $version = get_box_version();

        $expectedStub = <<<PHP
#!/usr/bin/env php
<?php

/*
 * Generated by Humbug Box $version.
 *
 * @link https://github.com/humbug/box
 */

Phar::mapPhar('box-auto-generated-alias-__uniqid__.phar');

require 'phar://box-auto-generated-alias-__uniqid__.phar/run.php';

__HALT_COMPILER(); ?>

PHP;

        $this->assertSame($expectedStub, $actualStub);
    }

    public function test_it_can_build_a_PHAR_file_in_verbose_mode(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        $shebang = sprintf('#!%s', (new PhpExecutableFinder())->find());

        $numberOfClasses = 0;
        $numberOfFiles = 45;
        if (self::$runComposer2) {
            // From Composer 2 there are more files
            ++$numberOfClasses;
            $numberOfFiles += 2;
        }

        dump_file(
            'box.json',
            json_encode(
                [
                    'alias' => 'test.phar',
                    'banner' => 'custom banner',
                    'chmod' => '0754',
                    'compactors' => [Php::class],
                    'directories' => ['a'],
                    'files' => ['test.php'],
                    'finder' => [['in' => 'one']],
                    'finder-bin' => [['in' => 'two']],
                    'algorithm' => 'OPENSSL',
                    'key' => 'private.key',
                    'key-pass' => true,
                    'main' => 'run.php',
                    'map' => [
                        ['a/deep/test/directory' => 'sub'],
                    ],
                    'metadata' => ['rand' => $rand = random_int(0, mt_getrandmax())],
                    'output' => 'test.phar',
                    'shebang' => $shebang,
                ]
            )
        );

        $this->commandTester->setInputs(['test']);    // Set input for the passphrase
        $this->commandTester->execute(
            ['command' => 'compile'],
            [
                'interactive' => true,
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ]
        );

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test.phar"

? Removing the existing PHAR "/path/to/tmp/test.phar"
? Registering compactors
  + KevinGH\Box\Compactor\Php
? Mapping paths
  - a/deep/test/directory > sub
? Adding main file: /path/to/tmp/run.php
? Adding requirements checker
? Adding binary files
    > 1 file(s)
? Auto-discover files? No
? Exclude dev files? Yes
? Adding files
    > 4 file(s)
? Generating new stub
  - Using shebang line: $shebang
  - Using banner:
    > custom banner
? Setting metadata
  - array (
  'rand' => $rand,
)
? Dumping the Composer autoloader
    > '/usr/local/bin/composer' 'dump-autoload' '--classmap-authoritative' '--no-dev'
Generating optimized autoload files (authoritative)
Generated optimized autoload files (authoritative) containing $numberOfClasses classes

? Removing the Composer dump artefacts
? No compression
? Signing using a private key

 Private key passphrase:
 >

? Setting file permissions to 0754
* Done.

No recommendation found.
No warning found.

 // PHAR: $numberOfFiles files (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $actual = preg_replace(
            '/(\/.*?composer)/',
            '/usr/local/bin/composer',
            $actual
        );

        $this->assertSame($expected, $actual);
    }

    public function test_it_can_build_a_PHAR_file_in_very_verbose_mode(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        $shebang = sprintf('#!%s', (new PhpExecutableFinder())->find());

        $numberOfClasses = 0;
        $numberOfFiles = 45;
        if (self::$runComposer2) {
            // From Composer 2 there are more files
            ++$numberOfClasses;
            $numberOfFiles += 2;
        }

        dump_file(
            'box.json',
            json_encode(
                [
                    'alias' => 'test.phar',
                    'banner' => [
                        'multiline',
                        'custom banner',
                    ],
                    'chmod' => '0754',
                    'compactors' => [Php::class],
                    'directories' => ['a'],
                    'files' => ['test.php'],
                    'finder' => [['in' => 'one']],
                    'finder-bin' => [['in' => 'two']],
                    'algorithm' => 'OPENSSL',
                    'key' => 'private.key',
                    'key-pass' => true,
                    'main' => 'run.php',
                    'map' => [
                        ['a/deep/test/directory' => 'sub'],
                    ],
                    'metadata' => ['rand' => $rand = random_int(0, mt_getrandmax())],
                    'output' => 'test.phar',
                    'shebang' => $shebang,
                ]
            )
        );

        $this->commandTester->setInputs(['test']);    // Set input for the passphrase
        $this->commandTester->execute(
            ['command' => 'compile'],
            [
                'interactive' => true,
                'verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE,
            ]
        );

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test.phar"

? Removing the existing PHAR "/path/to/tmp/test.phar"
? Registering compactors
  + KevinGH\Box\Compactor\Php
? Mapping paths
  - a/deep/test/directory > sub
? Adding main file: /path/to/tmp/run.php
? Adding requirements checker
? Adding binary files
    > 1 file(s)
? Auto-discover files? No
? Exclude dev files? Yes
? Adding files
    > 4 file(s)
? Generating new stub
  - Using shebang line: #!__PHP_EXECUTABLE__
  - Using banner:
    > multiline
    > custom banner
? Setting metadata
  - array (
  'rand' => $rand,
)
? Dumping the Composer autoloader
    > '/usr/local/bin/composer' 'dump-autoload' '--classmap-authoritative' '--no-dev' '-v'
Generating optimized autoload files (authoritative)
Generated optimized autoload files (authoritative) containing $numberOfClasses classes

? Removing the Composer dump artefacts
? No compression
? Signing using a private key

 Private key passphrase:
 >

? Setting file permissions to 0754
* Done.

No recommendation found.
No warning found.

 // PHAR: $numberOfFiles files (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $expected = str_replace(
            '__PHP_EXECUTABLE__',
            (new PhpExecutableFinder())->find(),
            $expected
        );

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $actual = preg_replace(
            '/(\/.*?composer)/',
            '/usr/local/bin/composer',
            $actual
        );

        $this->assertSame($expected, $actual);
    }

    public function test_it_can_build_a_PHAR_file_in_debug_mode(): void
    {
        dump_file(
            'index.php',
            $indexContents = <<<'PHP'
<?php

declare(strict_types=1);

echo 'Yo';

PHP
        );
        dump_file(
            'box.json',
            <<<'JSON'
{
    "alias": "index.phar",
    "banner": ""
}
JSON
        );

        $this->assertDirectoryNotExists('.box_dump');

        $this->commandTester->execute(
            [
                'command' => 'compile',
                '--debug' => null,
            ],
            [
                'interactive' => true,
                'verbosity' => OutputInterface::VERBOSITY_DEBUG,
            ]
        );

        if (extension_loaded('xdebug')) {
            $xdebugVersion = sprintf(
                '(%s)',
                phpversion('xdebug')
            );

            $xdebugLog = "[debug] The xdebug extension is loaded $xdebugVersion
[debug] No restart (BOX_ALLOW_XDEBUG=1)";
        } else {
            $xdebugLog = '[debug] The xdebug extension is not loaded';
        }

        $memoryLog = sprintf(
            '[debug] Current memory limit: "%s"',
            format_size(memory_to_bytes(trim(ini_get('memory_limit'))), 0)
        );

        $expected = <<<OUTPUT
$memoryLog
[debug] Checking BOX_ALLOW_XDEBUG
$xdebugLog
[debug] Disabled parallel processing

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/index.phar"

? No compactor to register
? Adding main file: /path/to/tmp/index.php
? Skip requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > No file found
? Generating new stub
  - Using shebang line: #!/usr/bin/env php
  - Using banner:
    >
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: 1 file (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual);

        $this->assertDirectoryExists('.box_dump');

        $expectedFiles = [
            '.box_dump/.box_configuration',
            '.box_dump/index.php',
        ];

        $actualFiles = $this->normalizePaths(
            iterator_to_array(
                Finder::create()->files()->in('.box_dump')->ignoreDotFiles(false),
                true
            )
        );

        $this->assertSame($expectedFiles, $actualFiles);

        $expectedDumpedConfig = <<<'EOF'
//
// Processed content of the configuration file "/path/to/box.json" dumped for debugging purposes
//
// PHP Version: 10.0.0
// PHP extensions: Core,date
// OS: Darwin / 17.7.0
// Command: bin/phpunit
// Box: 3.x-dev@27df576
// Time: 2018-05-24T20:59:15+00:00
//

KevinGH\Box\Configuration\Configuration {#140
  -file: "box.json"
  -fileMode: "0755"
  -alias: "index.phar"
  -basePath: "/path/to"
  -composerJson: KevinGH\Box\Composer\ComposerFile {#140
    -path: null
    -contents: []
  }
  -composerLock: KevinGH\Box\Composer\ComposerFile {#140
    -path: null
    -contents: []
  }
  -files: []
  -binaryFiles: []
  -autodiscoveredFiles: true
  -dumpAutoload: false
  -excludeComposerFiles: true
  -excludeDevFiles: false
  -compactors: []
  -compressionAlgorithm: "NONE"
  -mainScriptPath: "index.php"
  -mainScriptContents: """
    <?php\n
    \n
    declare(strict_types=1);\n
    \n
    echo 'Yo';\n
    """
  -fileMapper: KevinGH\Box\MapFile {#140
    -basePath: "/path/to"
    -map: []
  }
  -metadata: null
  -tmpOutputPath: "index.phar"
  -outputPath: "index.phar"
  -privateKeyPassphrase: null
  -privateKeyPath: null
  -promptForPrivateKey: false
  -processedReplacements: []
  -shebang: "#!/usr/bin/env php"
  -signingAlgorithm: "SHA1"
  -stubBannerContents: ""
  -stubBannerPath: null
  -stubPath: null
  -isInterceptFileFuncs: false
  -isStubGenerated: true
  -checkRequirements: false
  -warnings: []
  -recommendations: []
}

EOF;

        $actualDumpedConfig = str_replace(
            $this->tmp,
            '/path/to',
            file_contents('.box_dump/.box_configuration')
        );

        // Replace objects IDs
        $actualDumpedConfig = preg_replace(
            '/ \{#\d{3,}/',
            ' {#140',
            $actualDumpedConfig
        );

        // Replace the expected PHP version
        $actualDumpedConfig = str_replace(
            sprintf(
                'PHP Version: %s',
                PHP_VERSION
            ),
            'PHP Version: 10.0.0',
            $actualDumpedConfig
        );

        // Replace the expected PHP extensions
        $actualDumpedConfig = str_replace(
            sprintf(
                'PHP extensions: %s',
                implode(',', get_loaded_extensions())
            ),
            'PHP extensions: Core,date',
            $actualDumpedConfig
        );

        // Replace the expected OS version
        $actualDumpedConfig = str_replace(
            sprintf(
                'OS: %s / %s',
                PHP_OS,
                php_uname('r')
            ),
            'OS: Darwin / 17.7.0',
            $actualDumpedConfig
        );

        // Replace the expected command
        $actualDumpedConfig = str_replace(
            sprintf(
                'Command: %s',
                implode(' ', $GLOBALS['argv'])
            ),
            'Command: bin/phpunit',
            $actualDumpedConfig
        );

        // Replace the expected Box version
        $actualDumpedConfig = str_replace(
            sprintf(
                'Box: %s',
                get_box_version()
            ),
            'Box: 3.x-dev@27df576',
            $actualDumpedConfig
        );

        // Replace the expected time
        $actualDumpedConfig = preg_replace(
            '/Time: \d{4,}-\d{2,}-\d{2,}T\d{2,}:\d{2,}:\d{2,}\+\d{2,}:\d{2,}/',
            'Time: 2018-05-24T20:59:15+00:00',
            $actualDumpedConfig
        );

        $this->assertSame($expectedDumpedConfig, $actualDumpedConfig);

        // Checks one of the dumped file from the PHAR to ensure the encoding of the extracted file is correct
        $this->assertSame(
            file_get_contents('.box_dump/index.php'),
            $indexContents
        );
    }

    public function test_it_can_build_a_PHAR_file_in_quiet_mode(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        $shebang = sprintf('#!%s', (new PhpExecutableFinder())->find());

        dump_file(
            'box.json',
            json_encode(
                [
                    'alias' => 'test.phar',
                    'banner' => 'custom banner',
                    'chmod' => '0755',
                    'compactors' => [Php::class],
                    'directories' => ['a'],
                    'files' => ['test.php'],
                    'finder' => [['in' => 'one']],
                    'finder-bin' => [['in' => 'two']],
                    'algorithm' => 'OPENSSL',
                    'key' => 'private.key',
                    'key-pass' => true,
                    'main' => 'run.php',
                    'map' => [
                        ['a/deep/test/directory' => 'sub'],
                    ],
                    'metadata' => ['rand' => $rand = random_int(0, mt_getrandmax())],
                    'output' => 'test.phar',
                    'shebang' => $shebang,
                ]
            )
        );

        $this->commandTester->setInputs(['test']);
        $this->commandTester->execute(
            ['command' => 'compile'],
            [
                'interactive' => true,
                'verbosity' => OutputInterface::VERBOSITY_QUIET,
            ]
        );

        $expected = '';

        $actual = $this->commandTester->getDisplay(true);

        $this->assertSame($expected, $actual, 'Expected output logs to be identical');

        $this->assertSame(
            'Hello, world!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );

        // Check PHAR content
        $pharContents = file_get_contents('test.phar');
        $shebang = preg_quote($shebang, '/');

        $this->assertRegExp("/$shebang/", $pharContents);
        $this->assertRegExp('/custom banner/', $pharContents);

        $phar = new Phar('test.phar');

        $this->assertSame(['rand' => $rand], $phar->getMetadata());
    }

    public function test_it_can_build_a_PHAR_file_using_the_PHAR_default_stub(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        $shebang = sprintf('#!%s', (new PhpExecutableFinder())->find());

        dump_file(
            'box.json',
            json_encode(
                [
                    'alias' => 'alias-test.phar',
                    'banner' => 'custom banner',
                    'chmod' => '0755',
                    'compactors' => [Php::class],
                    'directories' => ['a'],
                    'files' => ['test.php'],
                    'finder' => [['in' => 'one']],
                    'finder-bin' => [['in' => 'two']],
                    'key' => 'private.key',
                    'key-pass' => true,
                    'main' => 'run.php',
                    'map' => [
                        ['a/deep/test/directory' => 'sub'],
                    ],
                    'metadata' => ['rand' => random_int(0, mt_getrandmax())],
                    'output' => 'test.phar',
                    'shebang' => $shebang,
                    'stub' => false,
                ]
            )
        );

        $this->commandTester->setInputs(['test']);    // Set input for the passphrase
        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $this->assertSame(
            'Hello, world!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );
    }

    public function test_it_can_build_a_PHAR_file_using_a_custom_stub(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        $shebang = sprintf('#!%s', (new PhpExecutableFinder())->find());

        dump_file(
            'custom_stub',
            $stub = <<<'PHP'
#!/usr/bin/php
<?php

//
// This is a custom stub: shebang & custom banner are not applied
//

if (class_exists('Phar')) {
    Phar::mapPhar('alias-test.phar');
    require 'phar://' . __FILE__ . '/run.php';
}

__HALT_COMPILER(); ?>

PHP
        );

        dump_file(
            'box.json',
            json_encode(
                [
                    'alias' => 'alias-test.phar',
                    'banner' => 'custom banner',
                    'chmod' => '0755',
                    'compactors' => [Php::class],
                    'directories' => ['a'],
                    'files' => ['test.php'],
                    'finder' => [['in' => 'one']],
                    'finder-bin' => [['in' => 'two']],
                    'algorithm' => 'OPENSSL',
                    'key' => 'private.key',
                    'key-pass' => true,
                    'main' => 'run.php',
                    'map' => [
                        ['a/deep/test/directory' => 'sub'],
                    ],
                    'metadata' => ['rand' => random_int(0, mt_getrandmax())],
                    'output' => 'test.phar',
                    'shebang' => $shebang,
                    'stub' => 'custom_stub',
                ]
            )
        );

        $this->commandTester->setInputs(['test']);    // Set input for the passphrase
        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $this->assertSame(
            'Hello, world!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );

        $phar = new Phar('test.phar');

        $actualStub = $this->normalizeDisplay($phar->getStub());

        $this->assertSame($stub, $actualStub);
    }

    public function test_it_can_build_a_PHAR_file_using_the_default_stub(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        dump_file(
            'box.json',
            json_encode(
                [
                    'directories' => ['a'],
                    'files' => ['test.php'],
                    'finder' => [['in' => 'one']],
                    'finder-bin' => [['in' => 'two']],
                    'main' => 'run.php',
                    'map' => [
                        ['a/deep/test/directory' => 'sub'],
                    ],
                    'output' => 'test.phar',
                ]
            )
        );

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $this->assertSame(
            'Hello, world!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );
    }

    public function test_it_cannot_build_a_PHAR_using_unreadable_files(): void
    {
        touch('index.php');
        touch('unreadable-file.php');
        chmod('unreadable-file.php', 0000);

        dump_file(
            'box.json',
            json_encode(
                [
                    'files' => ['unreadable-file.php'],
                ]
            )
        );

        try {
            $this->commandTester->execute(
                ['command' => 'compile'],
                [
                    'interactive' => false,
                    'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
                ]
            );

            $this->fail('Expected exception to be thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertRegExp(
                '/^Path ".+?" was expected to be readable\.$/',
                $exception->getMessage()
            );
        }
    }

    public function test_it_can_build_a_PHAR_overwriting_an_existing_one_in_verbose_mode(): void
    {
        mirror(self::FIXTURES_DIR.'/dir002', $this->tmp);

        $this->commandTester->execute(
            ['command' => 'compile'],
            [
                'interactive' => false,
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ]
        );

        $version = get_box_version();

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test.phar"

? Removing the existing PHAR "/path/to/tmp/test.phar"
? Setting replacement values
  + @name@: world
? No compactor to register
? Adding main file: /path/to/tmp/test.php
? Skip requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > No file found
? Generating new stub
  - Using shebang line: #!/usr/bin/env php
  - Using banner:
    > Generated by Humbug Box $version.
    >
    > @link https://github.com/humbug/box
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: 1 file (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual);

        $this->assertSame(
            'Hello, world!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );
    }

    public function test_it_can_dump_the_autoloader(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        rename('run.php', 'index.php');

        $this->assertFileNotExists($this->tmp.'/vendor/autoload.php');

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $output = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertRegExp(
            '/\? Dumping the Composer autoloader/',
            $output,
            'Expected the autoloader to be dumped'
        );

        $composerFiles = [
            'vendor/autoload.php',
            'vendor/composer/',
            'vendor/composer/ClassLoader.php',
            'vendor/composer/LICENSE',
            'vendor/composer/autoload_classmap.php',
            'vendor/composer/autoload_namespaces.php',
            'vendor/composer/autoload_psr4.php',
            'vendor/composer/autoload_real.php',
            'vendor/composer/autoload_static.php',
        ];

        foreach ($composerFiles as $composerFile) {
            $this->assertFileExists('phar://index.phar/'.$composerFile);
        }
    }

    public function test_it_can_build_a_PHAR_without_dumping_the_autoloader(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        rename('run.php', 'index.php');

        $this->assertFileNotExists($this->tmp.'/vendor/autoload.php');

        dump_file(
            'box.json',
            json_encode(
                [
                    'dump-autoload' => false,
                ]
            )
        );

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $output = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertRegExp(
            '/\? Skipping dumping the Composer autoloader/',
            $output,
            'Did not expect the autoloader to be dumped'
        );

        $composerFiles = [
            'vendor/autoload.php',
            'vendor/composer/',
            'vendor/composer/ClassLoader.php',
            'vendor/composer/LICENSE',
            'vendor/composer/autoload_classmap.php',
            'vendor/composer/autoload_namespaces.php',
            'vendor/composer/autoload_psr4.php',
            'vendor/composer/autoload_real.php',
            'vendor/composer/autoload_static.php',
        ];

        foreach ($composerFiles as $composerFile) {
            $this->assertFileNotExists('phar://index.phar/'.$composerFile);
        }
    }

    public function test_it_can_dump_the_autoloader_and_exclude_the_composer_files(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        rename('run.php', 'index.php');

        $this->assertFileNotExists($this->tmp.'/vendor/autoload.php');

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $output = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertRegExp(
            '/\? Removing the Composer dump artefacts/',
            $output,
            'Expected the composer files to be removed'
        );

        $composerFiles = [
            'vendor/autoload.php',
            'vendor/composer/',
            'vendor/composer/ClassLoader.php',
            'vendor/composer/LICENSE',
            'vendor/composer/autoload_classmap.php',
            'vendor/composer/autoload_namespaces.php',
            'vendor/composer/autoload_psr4.php',
            'vendor/composer/autoload_real.php',
            'vendor/composer/autoload_static.php',
        ];

        foreach ($composerFiles as $composerFile) {
            $this->assertFileExists('phar://index.phar/'.$composerFile);
        }

        $removedComposerFiles = [
            'composer.json',
            'composer.lock',
            'vendor/composer/installed.json',
        ];

        foreach ($removedComposerFiles as $composerFile) {
            $this->assertFileNotExists('phar://index.phar/'.$composerFile);
        }
    }

    public function test_it_can_dump_the_autoloader_and_keep_the_composer_files(): void
    {
        mirror(self::FIXTURES_DIR.'/dir000', $this->tmp);

        rename('run.php', 'index.php');

        dump_file(
            'box.json',
            json_encode(['exclude-composer-files' => false])
        );

        $this->assertFileNotExists($this->tmp.'/vendor/autoload.php');

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $output = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertRegExp(
            '/\? Keep the Composer dump artefacts/',
            $output,
            'Expected the composer files to be kept'
        );

        $composerFiles = [
            'vendor/autoload.php',
            'vendor/composer/',
            'vendor/composer/ClassLoader.php',
            'vendor/composer/LICENSE',
            'vendor/composer/autoload_classmap.php',
            'vendor/composer/autoload_namespaces.php',
            'vendor/composer/autoload_psr4.php',
            'vendor/composer/autoload_real.php',
            'vendor/composer/autoload_static.php',
        ];

        foreach ($composerFiles as $composerFile) {
            $this->assertFileExists('phar://index.phar/'.$composerFile);
        }

        $removedComposerFiles = [
            'composer.json',
            // The following two files do not exists since there is no dependency, check BoxTest for a more complete
            // test regarding this feature
            //'composer.lock',
            //'vendor/composer/installed.json',
        ];

        foreach ($removedComposerFiles as $composerFile) {
            $this->assertFileExists('phar://index.phar/'.$composerFile);
        }
    }

    public function test_it_can_build_a_PHAR_with_a_custom_banner(): void
    {
        mirror(self::FIXTURES_DIR.'/dir003', $this->tmp);

        $this->commandTester->execute(
            [
                'command' => 'compile',
            ],
            [
                'interactive' => false,
                'verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE,
            ]
        );

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test.phar"

? No compactor to register
? Adding main file: /path/to/tmp/test.php
? Skip requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > No file found
? Generating new stub
  - Using shebang line: #!/usr/bin/env php
  - Using custom banner from file: /path/to/tmp/banner
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: 1 file (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual);

        $this->assertSame(
            'Hello!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );
    }

    public function test_it_can_build_a_PHAR_with_a_stub_file(): void
    {
        mirror(self::FIXTURES_DIR.'/dir004', $this->tmp);

        $this->commandTester->execute(
            ['command' => 'compile'],
            [
                'interactive' => false,
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ]
        );

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test.phar"

? No compactor to register
? Adding main file: /path/to/tmp/test.php
? Skip requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > No file found
? Using stub file: /path/to/tmp/stub.php
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: 1 file (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual);

        $this->assertSame(
            'Hello!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );
    }

    public function test_it_can_build_a_PHAR_with_the_default_stub_file(): void
    {
        mirror(self::FIXTURES_DIR.'/dir005', $this->tmp);

        $this->commandTester->execute(
            ['command' => 'compile'],
            [
                'interactive' => false,
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ]
        );

        $version = get_box_version();

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test.phar"

? No compactor to register
? Adding main file: /path/to/tmp/index.php
? Skip requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > 1 file(s)
? Generating new stub
  - Using shebang line: #!/usr/bin/env php
  - Using banner:
    > Generated by Humbug Box $version.
    >
    > @link https://github.com/humbug/box
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: 2 files (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual);
    }

    public function test_it_can_build_a_PHAR_without_a_main_script(): void
    {
        mirror(self::FIXTURES_DIR.'/dir004', $this->tmp);

        dump_file(
            'box.json',
            <<<'JSON'
{
    "files-bin": ["test.php"],
    "stub": "stub.php",
    "main": false
}
JSON
        );

        $this->commandTester->execute(
            ['command' => 'compile'],
            [
                'interactive' => false,
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ]
        );

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test.phar"

? No compactor to register
? No main script path configured
? Skip requirements checker
? Adding binary files
    > 1 file(s)
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > No file found
? Using stub file: /path/to/tmp/stub.php
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: 1 file (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual);

        $this->assertSame(
            'Hello!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );
    }

    public function test_it_can_build_an_empty_PHAR(): void
    {
        mirror(self::FIXTURES_DIR.'/dir004', $this->tmp);

        dump_file(
            'box.json',
            <<<'JSON'
{
    "stub": "stub.php",
    "main": false
}
JSON
        );

        $this->commandTester->execute(
            ['command' => 'compile'],
            [
                'interactive' => false,
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ]
        );

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test.phar"

? No compactor to register
? No main script path configured
? Skip requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > No file found
? Using stub file: /path/to/tmp/stub.php
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: 1 file (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual);

        $this->assertSame(
            'Hello!',
            exec('php test.phar'),
            'Expected PHAR to be executable'
        );

        $expectedFiles = [
            '/.box_empty',
        ];

        $actualFiles = $this->retrievePharFiles(new Phar('test.phar'));

        $this->assertSame($expectedFiles, $actualFiles);
    }

    public function test_it_can_build_a_PHAR_with_compressed_code(): void
    {
        mirror(self::FIXTURES_DIR.'/dir006', $this->tmp);

        $this->commandTester->execute(
            ['command' => 'compile'],
            [
                'interactive' => false,
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ]
        );

        $version = get_box_version();

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test.phar"

? No compactor to register
? Adding main file: /path/to/tmp/test.php
? Skip requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > No file found
? Generating new stub
  - Using shebang line: #!/usr/bin/env php
  - Using banner:
    > Generated by Humbug Box $version.
    >
    > @link https://github.com/humbug/box
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? Compressing with the algorithm "GZ"
    > Warning: the extension "zlib" will now be required to execute the PHAR
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: 1 file (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual);

        $builtPhar = new Phar('test.phar');

        $this->assertFalse($builtPhar->isCompressed()); // This is a bug, see https://github.com/humbug/box/issues/20
        $this->assertTrue($builtPhar['test.php']->isCompressed());

        $this->assertSame(
            'Hello!',
            exec('php test.phar'),
            'Expected the PHAR to be executable'
        );
    }

    public function test_it_can_build_a_PHAR_in_a_non_existent_directory(): void
    {
        mirror(self::FIXTURES_DIR.'/dir007', $this->tmp);

        $this->commandTester->execute(
            ['command' => 'compile'],
            [
                'interactive' => false,
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ]
        );

        $version = get_box_version();

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/foo/bar/test.phar"

? No compactor to register
? Adding main file: /path/to/tmp/test.php
? Skip requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > No file found
? Generating new stub
  - Using shebang line: #!/usr/bin/env php
  - Using banner:
    > Generated by Humbug Box $version.
    >
    > @link https://github.com/humbug/box
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: 1 file (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual);

        $this->assertSame(
            'Hello!',
            exec('php foo/bar/test.phar'),
            'Expected the PHAR to be executable'
        );
    }

    /**
     * @dataProvider provideAliasConfig
     */
    public function test_it_configures_the_PHAR_alias(bool $stub): void
    {
        mirror(self::FIXTURES_DIR.'/dir008', $this->tmp);

        dump_file(
            'box.json',
            json_encode(
                [
                    'alias' => $alias = 'alias-test.phar',
                    'main' => 'index.php',
                    'stub' => $stub,
                    'blacklist' => ['box.json'],
                ]
            )
        );

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $this->assertSame(
            0,
            $this->commandTester->getStatusCode(),
            sprintf(
                'Expected the command to successfully run. Got: %s',
                $this->normalizeDisplay($this->commandTester->getDisplay(true))
            )
        );

        $this->assertSame(
            '',
            exec('php index.phar'),
            'Expected PHAR to be executable'
        );

        $phar = new Phar('index.phar');

        // Check the stub content
        $actualStub = DisplayNormalizer::removeTrailingSpaces($phar->getStub());
        $defaultStub = DisplayNormalizer::removeTrailingSpaces(file_get_contents(self::FIXTURES_DIR.'/../default_stub.php'));

        if ($stub) {
            $this->assertSame($phar->getPath(), $phar->getAlias());

            $this->assertNotRegExp(
                '/Phar::webPhar\(.*\);/',
                $actualStub
            );
            $this->assertRegExp(
                '/Phar::mapPhar\(\'alias-test\.phar\'\);/',
                $actualStub
            );
        } else {
            $this->assertSame($alias, $phar->getAlias());

            $this->assertSame($defaultStub, $actualStub);

            // No alias is found: I find it weird but well, that's the default stub so there is not much that can
            // be done here. Maybe there is a valid reason I'm not aware of.
            $this->assertNotRegExp(
                '/alias-test\.phar/',
                $actualStub
            );
        }

        $expectedFiles = [
            '/index.php',
        ];

        $actualFiles = $this->retrievePharFiles($phar);

        $this->assertSame($expectedFiles, $actualFiles);
    }

    public function test_it_can_build_a_PHAR_file_without_a_shebang_line(): void
    {
        mirror(self::FIXTURES_DIR.'/dir006', $this->tmp);

        $boxRawConfig = json_decode(file_get_contents('box.json'), true, 512, JSON_PRETTY_PRINT);
        $boxRawConfig['shebang'] = false;
        dump_file('box.json', json_encode($boxRawConfig, JSON_PRETTY_PRINT));

        $this->commandTester->execute(
            ['command' => 'compile'],
            [
                'interactive' => false,
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ]
        );

        $version = get_box_version();

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test.phar"

? No compactor to register
? Adding main file: /path/to/tmp/test.php
? Skip requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > No file found
? Generating new stub
  - No shebang line
  - Using banner:
    > Generated by Humbug Box $version.
    >
    > @link https://github.com/humbug/box
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? Compressing with the algorithm "GZ"
    > Warning: the extension "zlib" will now be required to execute the PHAR
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: 1 file (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual);

        $builtPhar = new Phar('test.phar');

        $this->assertFalse($builtPhar->isCompressed()); // This is a bug, see https://github.com/humbug/box/issues/20
        $this->assertTrue($builtPhar['test.php']->isCompressed());

        $this->assertSame(
            'Hello!',
            exec('php test.phar'),
            'Expected the PHAR to be executable'
        );
    }

    public function test_it_can_build_a_PHAR_with_an_output_which_does_not_have_a_PHAR_extension(): void
    {
        mirror(self::FIXTURES_DIR.'/dir004', $this->tmp);

        dump_file(
            'box.json',
            json_encode(
                array_merge(
                    json_decode(
                        file_get_contents('box.json'),
                        true
                    ),
                    ['output' => 'test']
                )
            )
        );

        $this->commandTester->execute(
            ['command' => 'compile'],
            [
                'interactive' => false,
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ]
        );

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/test"

? No compactor to register
? Adding main file: /path/to/tmp/test.php
? Skip requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > No file found
? Using stub file: /path/to/tmp/stub.php
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: 1 file (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual);

        $this->assertSame(
            'Hello!',
            exec('php test'),
            'Expected PHAR to be executable'
        );
    }

    public function test_it_can_build_a_PHAR_ignoring_the_configuration(): void
    {
        mirror(self::FIXTURES_DIR.'/dir009', $this->tmp);

        $this->commandTester->execute(
            [
                'command' => 'compile',
                '--no-config' => null,
            ],
            ['interactive' => true]
        );

        $this->assertSame(
            'Index',
            exec('php index.phar'),
            'Expected PHAR to be executable'
        );
    }

    public function test_it_ignores_the_config_given_when_the_no_config_setting_is_set(): void
    {
        mirror(self::FIXTURES_DIR.'/dir009', $this->tmp);

        $this->commandTester->execute(
            [
                'command' => 'compile',
                '--config' => 'box.json',
                '--no-config' => null,
            ],
            ['interactive' => true]
        );

        $this->assertSame(
            'Index',
            exec('php index.phar'),
            'Expected PHAR to be executable'
        );
    }

    public function test_it_can_build_a_PHAR_with_a_PHPScoper_config(): void
    {
        mirror(self::FIXTURES_DIR.'/dir010', $this->tmp);

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $this->assertSame(
            'Index',
            exec('php index.phar'),
            'Expected PHAR to be executable'
        );

        $this->assertSame(
            1,
            preg_match(
                '/namespace (?<namespace>.*);/',
                $indexContents = file_get_contents('phar://index.phar/index.php'),
                $matches
            ),
            sprintf(
                'Expected the content of the PHAR index.php file to match the given regex. The following '
                .'contents does not: "%s"',
                $indexContents
            )
        );

        $phpScoperNamespace = $matches['namespace'];

        $this->assertStringStartsWith('_HumbugBox', $phpScoperNamespace);
    }

    public function test_it_can_build_a_PHAR_with_a_PHPScoper_config_with_a_specific_prefix(): void
    {
        mirror(self::FIXTURES_DIR.'/dir010', $this->tmp);

        rename('scoper-fixed-prefix.inc.php', 'scoper.inc.php', true);

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $this->assertSame(
            'Index',
            exec('php index.phar'),
            'Expected PHAR to be executable'
        );

        $this->assertSame(
            1,
            preg_match(
                '/namespace (?<namespace>.*);/',
                $indexContents = file_get_contents('phar://index.phar/index.php'),
                $matches
            ),
            sprintf(
                'Expected the content of the PHAR index.php file to match the given regex. The following '
                .'contents does not: "%s"',
                $indexContents
            )
        );

        $phpScoperNamespace = $matches['namespace'];

        $this->assertSame('Acme', $phpScoperNamespace);
    }

    public function test_it_cannot_sign_a_PHAR_with_the_OpenSSL_algorithm_without_a_private_key(): void
    {
        mirror(self::FIXTURES_DIR.'/dir010', $this->tmp);

        dump_file(
            'box.json',
            json_encode(
                [
                    'algorithm' => 'OPENSSL',
                ]
            )
        );

        try {
            $this->commandTester->execute(
                ['command' => 'compile'],
                ['interactive' => true]
            );

            $this->fail('Expected exception to be thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Expected to have a private key for OpenSSL signing but none have been provided.',
                $exception->getMessage()
            );
        }
    }

    public function test_it_displays_recommendations_and_warnings(): void
    {
        mirror(self::FIXTURES_DIR.'/dir010', $this->tmp);

        remove('composer.json');

        dump_file(
            'box.json',
            json_encode(
                [
                    'check-requirements' => true,
                ]
            )
        );

        $this->commandTester->execute(
            ['command' => 'compile'],
            ['interactive' => true]
        );

        $version = get_box_version();

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/index.phar"

? No compactor to register
? Adding main file: /path/to/tmp/index.php
? Skip requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > No file found
? Generating new stub
  - Using shebang line: #!/usr/bin/env php
  - Using banner:
    > Generated by Humbug Box $version.
    >
    > @link https://github.com/humbug/box
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0755
* Done.

💡  1 recommendation found:
    - The "check-requirements" setting can be omitted since is set to its default value
⚠️  1 warning found:
    - The requirement checker could not be used because the composer.json and composer.lock file could not be found.

 // PHAR: 1 file (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual, 'Expected logs to be identical');
    }

    public function test_it_skips_the_compression_when_in_dev_mode(): void
    {
        mirror(self::FIXTURES_DIR.'/dir010', $this->tmp);

        dump_file(
            'box.json',
            json_encode(
                [
                    'compression' => 'GZ',
                ]
            )
        );

        $this->commandTester->execute(
            [
                'command' => 'compile',
                '--dev' => null,
            ],
            ['interactive' => true]
        );

        $version = get_box_version();

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/index.phar"

? No compactor to register
? Adding main file: /path/to/tmp/index.php
? Skip requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? No
? Adding files
    > No file found
? Generating new stub
  - Using shebang line: #!/usr/bin/env php
  - Using banner:
    > Generated by Humbug Box $version.
    >
    > @link https://github.com/humbug/box
? Skipping dumping the Composer autoloader
? Removing the Composer dump artefacts
? Dev mode detected: skipping the compression
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: 1 file (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual, 'Expected logs to be identical');
    }

    public function test_it_can_generate_a_PHAR_with_docker(): void
    {
        if (extension_loaded('xdebug')) {
            $this->markTestSkipped('Skipping this test since xdebug has an include wrapper causing this test to fail');
        }

        mirror(self::FIXTURES_DIR.'/dir010', $this->tmp);

        dump_file('box.json', '{}');
        dump_file('composer.json', '{}');

        $this->commandTester->execute(
            [
                'command' => 'compile',
                '--with-docker' => null,
            ],
            ['interactive' => true]
        );

        $version = get_box_version();

        $numberOfFiles = 41;
        if (self::$runComposer2) {
            // From Composer 2 there are more files
            $numberOfFiles += 2;
        }

        $expected = <<<OUTPUT

    ____
   / __ )____  _  __
  / __  / __ \| |/_/
 / /_/ / /_/ />  <
/_____/\____/_/|_|


Box version 3.x-dev@151e40a

 // Loading the configuration file "/path/to/box.json.dist".

🔨  Building the PHAR "/path/to/tmp/index.phar"

? No compactor to register
? Adding main file: /path/to/tmp/index.php
? Adding requirements checker
? Adding binary files
    > No file found
? Auto-discover files? Yes
? Exclude dev files? Yes
? Adding files
    > 1 file(s)
? Generating new stub
  - Using shebang line: #!/usr/bin/env php
  - Using banner:
    > Generated by Humbug Box $version.
    >
    > @link https://github.com/humbug/box
? Dumping the Composer autoloader
? Removing the Composer dump artefacts
? No compression
? Setting file permissions to 0755
* Done.

No recommendation found.
No warning found.

 // PHAR: $numberOfFiles files (100B)
 // You can inspect the generated PHAR with the "info" command.

 // Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s


 // Loading the configuration file "/path/to/box.json.dist".


🐳  Generating a Dockerfile for the PHAR "/path/to/tmp/index.phar"

 [OK] Done

You can now inspect your Dockerfile file or build your container with:
$ docker build .

OUTPUT;

        $actual = $this->normalizeDisplay($this->commandTester->getDisplay(true));

        $this->assertSame($expected, $actual, 'Expected logs to be identical');
    }

    public function provideAliasConfig(): Generator
    {
        yield [true];
        yield [false];
    }

    private function normalizeDisplay(string $display): string
    {
        $display = str_replace($this->tmp, '/path/to/tmp', $display);

        $display = preg_replace(
            '/Loading the configuration file[\s\n]+.*[\s\n\/]+.*box\.json[comment\<\>\n\s\/]*"\./',
            'Loading the configuration file "/path/to/box.json.dist".',
            $display
        );

        $display = preg_replace(
            '/You can inspect the generated PHAR( | *\n *\/\/ *)with( | *\n *\/\/ *)the( | *\n *\/\/ *)"info"( | *\n *\/\/ *)command/',
            'You can inspect the generated PHAR with the "info" command',
            $display
        );

        $display = preg_replace(
            '/\/\/ PHAR: (\d+ files?) \(\d+\.\d{2}K?B\)/',
            '// PHAR: $1 (100B)',
            $display
        );

        $display = preg_replace(
            '/\/\/ Memory usage: \d+\.\d{2}MB \(peak: \d+\.\d{2}MB\), time: .*?sec/',
            '// Memory usage: 5.00MB (peak: 10.00MB), time: 0.00s',
            $display
        );

        $display = preg_replace(
            '/Box version .+@[a-z\d]{7}/',
            'Box version 3.x-dev@151e40a',
            $display
        );

        $display = str_replace(
            'Xdebug',
            'xdebug',
            $display
        );

        $display = preg_replace(
            '/\[debug\] Increased the maximum number of open file descriptors from \([^\)]+\) to \([^\)]+\)'.PHP_EOL.'/',
            '',
            $display
        );

        $display = str_replace(
            '[debug] Restored the maximum number of open file descriptors'.PHP_EOL,
            '',
            $display
        );

        if (extension_loaded('xdebug')) {
            $display = preg_replace(
                '/'.PHP_EOL.'You are running composer with xdebug enabled. This has a major impact on runtime performance. See https:\/[^\s]+'.PHP_EOL.'/',
                '',
                $display
            );
        }

        return DisplayNormalizer::removeTrailingSpaces($display);
    }

    private function retrievePharFiles(Phar $phar, ?Traversable $traversable = null): array
    {
        $root = 'phar://'.str_replace('\\', '/', realpath($phar->getPath())).'/';

        if (null === $traversable) {
            $traversable = $phar;
        }

        $paths = [];

        foreach ($traversable as $fileInfo) {
            /** @var PharFileInfo $fileInfo */
            $fileInfo = $phar[str_replace($root, '', $fileInfo->getPathname())];

            $path = substr($fileInfo->getPathname(), strlen($root) - 1);

            if ($fileInfo->isDir()) {
                $path .= '/';

                $paths = array_merge(
                    $paths,
                    $this->retrievePharFiles(
                        $phar,
                        new DirectoryIterator($fileInfo->getPathname())
                    )
                );
            }

            $paths[] = $path;
        }

        sort($paths);

        return array_unique($paths);
    }
}

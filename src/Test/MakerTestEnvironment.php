<?php

namespace Paknahad\JsonApiBundle\Test;

use Symfony\Bundle\MakerBundle\Test\MakerTestDetails;
use Symfony\Bundle\MakerBundle\Test\MakerTestProcess;
use Symfony\Bundle\MakerBundle\Util\YamlSourceManipulator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\InputStream;

final class MakerTestEnvironment
{
    private $testDetails;

    private $fs;

    private $rootPath;
    private $cachePath;
    private $flexPath;

    private $path;

    /**
     * @var MakerTestProcess
     */
    private $runnedMakerProcess;

    private function __construct(MakerTestDetails $testDetails)
    {
        $this->testDetails = $testDetails;
        $this->fs = new Filesystem();

        $this->rootPath = realpath(__DIR__.'/../../');

        $cachePath = $this->rootPath.'/tests/tmp/cache';

        if (!$this->fs->exists($cachePath)) {
            $this->fs->mkdir($cachePath);
        }

        $this->cachePath = realpath($cachePath);
        $this->flexPath = $this->cachePath.'/flex_project';

        $this->path = $this->cachePath.\DIRECTORY_SEPARATOR.$testDetails->getUniqueCacheDirectoryName();
    }

    public static function create(MakerTestDetails $testDetails): self
    {
        return new self($testDetails);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    private function changeRootNamespaceIfNeeded()
    {
        if ('App' === ($rootNamespace = $this->testDetails->getRootNamespace())) {
            return;
        }

        $replacements = [
            [
                'filename' => 'composer.json',
                'find' => '"App\\\\": "src/"',
                'replace' => '"'.$rootNamespace.'\\\\": "src/"',
            ],
            [
                'filename' => 'src/Kernel.php',
                'find' => 'namespace App',
                'replace' => 'namespace '.$rootNamespace,
            ],
            [
                'filename' => 'bin/console',
                'find' => 'use App\\Kernel',
                'replace' => 'use '.$rootNamespace.'\\Kernel',
            ],
            [
                'filename' => 'public/index.php',
                'find' => 'use App\\Kernel',
                'replace' => 'use '.$rootNamespace.'\\Kernel',
            ],
            [
                'filename' => 'config/services.yaml',
                'find' => 'App\\',
                'replace' => $rootNamespace.'\\',
            ],
            [
                'filename' => 'phpunit.xml.dist',
                'find' => '<env name="KERNEL_CLASS" value="App\\Kernel" />',
                'replace' => '<env name="KERNEL_CLASS" value="'.$rootNamespace.'\\Kernel" />',
            ],
        ];

        if ($this->fs->exists($this->path.'/config/packages/doctrine.yaml')) {
            $replacements[] = [
                'filename' => 'config/packages/doctrine.yaml',
                'find' => 'App',
                'replace' => $rootNamespace,
            ];
        }

        $this->processReplacements($replacements, $this->path);
    }

    public function prepare()
    {
        if (!$this->fs->exists($this->flexPath)) {
            $this->buildFlexSkeleton();
        }

        if (!$this->fs->exists($this->path)) {
            try {
                $this->fs->mirror($this->flexPath, $this->path);

                // install any missing dependencies
                $dependencies = $this->determineMissingDependencies();
                if ($dependencies) {
                    MakerTestProcess::create(sprintf('composer require %s', implode(' ', $dependencies)), $this->path)
                        ->run();
                }

                $this->changeRootNamespaceIfNeeded();

                file_put_contents($this->path.'/.gitignore', "var/cache/\nvendor/\n");
            } catch (ProcessFailedException $e) {
                $this->fs->remove($this->path);

                throw $e;
            }
        }

        if (null !== $this->testDetails->getFixtureFilesPath()) {
            // move fixture files into directory
            $finder = new Finder();
            $finder->in($this->testDetails->getFixtureFilesPath())->files();

            foreach ($finder as $file) {
                if ($file->getPath() === $this->testDetails->getFixtureFilesPath()) {
                    continue;
                }

                $this->fs->copy($file->getPathname(), $this->path.'/'.$file->getRelativePathname(), true);
            }
        }

        $this->processReplacements($this->testDetails->getReplacements(), $this->path);

        if ($ignoredFiles = $this->testDetails->getFilesToDelete()) {
            foreach ($ignoredFiles as $file) {
                if (file_exists($this->path.'/'.$file)) {
                    $this->fs->remove($this->path.'/'.$file);
                }
            }
        }

        MakerTestProcess::create('composer dump-autoload', $this->path)
            ->run();
    }

    private function preMake()
    {
        foreach ($this->testDetails->getPreMakeCommands() as $preCommand) {
            MakerTestProcess::create($preCommand, $this->path)
                ->run();
        }
    }

    public function runMaker()
    {
        $this->preMake();

        // Lets remove cache
        $this->fs->remove($this->path.'/var/cache');

        // We don't need ansi coloring in tests!
        $testProcess = MakerTestProcess::create(
            sprintf('php bin/console %s %s --no-ansi', $this->testDetails->getMaker()::getCommandName(), $this->testDetails->getArgumentsString()),
            $this->path,
            10
        );

        $testProcess->setEnv([
            'SHELL_INTERACTIVE' => '1',
        ]);

        if ($userInputs = $this->testDetails->getInputs()) {
            $inputStream = new InputStream();

            // start the command with some input
            $inputStream->write(current($userInputs)."\n");

            $inputStream->onEmpty(function () use ($inputStream, &$userInputs) {
                $nextInput = next($userInputs);
                if (false === $nextInput) {
                    $inputStream->close();
                } else {
                    $inputStream->write($nextInput."\n");
                }
            });

            $testProcess->setInput($inputStream);
        }

        $this->runnedMakerProcess = $testProcess->run($this->testDetails->isCommandAllowedToFail());

        $this->postMake();

        return $this->runnedMakerProcess;
    }

    public function getGeneratedFilesFromOutputText()
    {
        $output = $this->runnedMakerProcess->getOutput();

        $matches = [];

        preg_match_all('#(created|updated): (.*)\n#iu', $output, $matches, PREG_PATTERN_ORDER);

        return array_map('trim', $matches[2]);
    }

    public function fileExists(string $file)
    {
        return $this->fs->exists($this->path.'/'.$file);
    }

    public function runPhpCSFixer(string $file)
    {
        return MakerTestProcess::create(sprintf('php vendor/bin/php-cs-fixer --config=%s fix --dry-run --diff %s', __DIR__.'/../Resources/test/.php_cs.test', $this->path.'/'.$file), $this->rootPath)
            ->run(true);
    }

    public function runTwigCSLint(string $file)
    {
        return MakerTestProcess::create(sprintf('php vendor/bin/twigcs lint %s', $this->path.'/'.$file), $this->rootPath)
            ->run(true);
    }

    public function runInternalTests()
    {
        $finder = new Finder();
        $finder->in($this->path.'/tests')->files();
        if ($finder->count() > 0) {
            // execute the tests that were moved into the project!
            return MakerTestProcess::create(sprintf('php %s', $this->rootPath.'/vendor/bin/simple-phpunit'), $this->path)
                ->run(true);
        }

        return null;
    }

    private function postMake()
    {
        $this->processReplacements($this->testDetails->getPostMakeReplacements(), $this->path);

        $guardAuthenticators = $this->testDetails->getGuardAuthenticators();
        if (!empty($guardAuthenticators)) {
            $yaml = file_get_contents($this->path.'/config/packages/security.yaml');
            $manipulator = new YamlSourceManipulator($yaml);
            $data = $manipulator->getData();
            foreach ($guardAuthenticators as $firewallName => $id) {
                if (!isset($data['security']['firewalls'][$firewallName])) {
                    throw new \Exception(sprintf('Could not find firewall "%s"', $firewallName));
                }

                $data['security']['firewalls'][$firewallName]['guard'] = [
                    'authenticators' => [$id],
                ];
            }
            $manipulator->setData($data);
            file_put_contents($this->path.'/config/packages/security.yaml', $manipulator->getContents());
        }

        foreach ($this->testDetails->getPostMakeCommands() as $postCommand) {
            MakerTestProcess::create($postCommand, $this->path)
                ->run();
        }
    }

    private function buildFlexSkeleton()
    {
        MakerTestProcess::create('composer create-project symfony/skeleton flex_project --prefer-dist --no-progress', $this->cachePath)
            ->run();

        $rootPath = str_replace('\\', '\\\\', realpath(__DIR__.'/../..'));

        // processes any changes needed to the Flex project
        $replacements = [
            [
                'filename' => 'config/bundles.php',
                'find' => "Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],",
                'replace' => "Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],\n    Paknahad\JsonApiBundle\JsonApiBundle::class => ['all' => true],",
            ],
            [
                // ugly way to autoload Maker & any other vendor libs needed in the command
                'filename' => 'composer.json',
                'find' => '"App\\\Tests\\\": "tests/"',
                'replace' => sprintf(
                    '"App\\\Tests\\\": "tests/",'."\n".'            "Paknahad\\\JsonApiBundle\\\": "%s/src/",'."\n".'            "PhpParser\\\": "%s/vendor/nikic/php-parser/lib/PhpParser/"',
                    // escape \ for Windows
                    $rootPath,
                    $rootPath
                ),
            ],
        ];
        $this->processReplacements($replacements, $this->flexPath);

        // fetch a few packages needed for testing
        MakerTestProcess::create(
            'composer require symfony/psr-http-message-bridge \
                woohoolabs/yin \
                phpunit \
                symfony/maker-bundle \
                symfony/process \
                phootwork/collection \
                sensio/framework-extra-bundle \
                zendframework/zend-diactoros "^1.3.0" \
                --prefer-dist --no-progress --no-suggest',
            $this->flexPath
        )->run();

        MakerTestProcess::create('php bin/console cache:clear --no-warmup', $this->flexPath)
            ->run();
    }

    private function processReplacements(array $replacements, $rootDir)
    {
        foreach ($replacements as $replacement) {
            $path = realpath($rootDir.'/'.$replacement['filename']);

            if (!$this->fs->exists($path)) {
                throw new \Exception(sprintf('Could not find file "%s" to process replacements inside "%s"', $replacement['filename'], $rootDir));
            }

            $contents = file_get_contents($path);
            if (false === strpos($contents, $replacement['find'])) {
//                throw new \Exception(sprintf('Could not find "%s" inside "%s"', $replacement['find'], $replacement['filename']));
            }

            file_put_contents($path, str_replace($replacement['find'], $replacement['replace'], $contents));
        }
    }

    /**
     * Executes the DependencyBuilder for the Maker command inside the
     * actual project, so we know exactly what dependencies we need or
     * don't need.
     */
    private function determineMissingDependencies(): array
    {
        $depBuilder = $this->testDetails->getDependencyBuilder();
        file_put_contents($this->path.'/dep_builder', serialize($depBuilder));
        file_put_contents($this->path.'/dep_runner.php', '<?php

require __DIR__."/vendor/autoload.php";
$depBuilder = unserialize(file_get_contents("dep_builder"));
$missingDependencies = array_merge(
    $depBuilder->getMissingDependencies(),
    $depBuilder->getMissingDevDependencies()
);
echo json_encode($missingDependencies);
        ');

        $process = MakerTestProcess::create('php dep_runner.php', $this->path)->run();
        $data = json_decode($process->getOutput(), true);
        if (null === $data) {
            throw new \Exception('Could not determine dependencies');
        }

        unlink($this->path.'/dep_builder');
        unlink($this->path.'/dep_runner.php');

        return array_merge($data, $this->testDetails->getExtraDependencies());
    }
}

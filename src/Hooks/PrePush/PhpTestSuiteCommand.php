<?php

declare(strict_types=1);

namespace Rugaard\GitHooks\PHP\Hooks\PrePush;

use Rugaard\GitHooks\Abstracts\AbstractCommand;
use Rugaard\GitHooks\Style\GitHookStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Class PhpTestSuiteCommand.
 *
 * @package Rugaard\GitHooks\PHP\Hooks\PrePush
 */
class PhpTestSuiteCommand extends AbstractCommand
{
    /**
     * Configures the current command.
     *
     * @return void
     */
    public function configure(): void
    {
        $this->setName('php:test-suite')
            ->setDescription('Run PHP test suite')
            ->addArgument('remote', InputArgument::REQUIRED, 'Name of remote')
            ->addArgument('url', InputArgument::REQUIRED, 'URL of remote');
    }

    /**
     * Executes the current command.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Instantiate Git Hooks Styles.
        $io = new GitHookStyle($input, $output);

        // Generate section.
        $io->header('Running PHP test suite', false);

        // Make sure driver exists.
        $driverPath = getcwd() . '/vendor/bin/' . (in_array($this->getConfig('driver'), ['phpunit', 'pest']) ? $this->getConfig('driver') : 'phpunit');
        if (!file_exists($driverPath)) {
            $io->newLine();
            $io->block('Could not locate driver: ' . $driverPath, 'ERROR', 'fg=red;options=bold', '');
            return Command::FAILURE;
        }

        // Determine which standard, we should use for style checking.
        if ($this->getConfig('config') !== null && file_exists(getcwd() . '/' . $this->getConfig('config'))) {
            $config = getcwd() . '/' . $this->getConfig('config');
            $io->writeln('<fg=cyan>[INFO] Using custom configuration file (' . $this->getConfig('config') . ')</>' . "\n");
        } elseif (file_exists(getcwd() . '/phpunit.xml')) {
            $config = getcwd() . '/phpunit.xml';
            $io->writeln('<fg=cyan>[INFO] Using project configuration file (phpunit.xml)</>' . "\n");
        } elseif (file_exists(getcwd() . '/phpunit.xml.dist')) {
            $config = getcwd() . '/phpunit.xml.dist';
            $io->writeln('<fg=cyan>[INFO] Using distributed configuration file (phpunit.xml.dist)</>' . "\n");
        } else {
            $io->block('No configuration file found.', 'ERROR', 'fg=red;options=bold', '');
            return Command::FAILURE;
        }

        // Prepare process.
        $process = new Process(array_merge(
            [
                $driverPath,
                '--configuration=' . $config,
            ],
            !empty($this->getConfig('printer')) && class_exists($this->getConfig('printer')) ? ['--printer=' . $this->getConfig('printer')] : [],
        ));

        // Execute process.
        $process->run(function ($type, $buffer) {
            print $buffer;
        });

        // Validate result of process
        // and output appropriate message.
        $io->writeln('');
        if (!$process->isSuccessful()) {
            $io->block('Test suite failed.', 'ERROR', 'fg=red;options=bold', '');
            $io->writeln('<fg=yellow>Fix the error(s) and try again.</>');
            return Command::FAILURE;
        } else {
            $io->block('Done', 'OK', 'fg=green;options=bold', '');
            return Command::SUCCESS;
        }
    }

    /**
     * Get command's default configuration.
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'driver' => 'phpunit',
            'config' => null,
            'printer' => '\\NunoMaduro\\Collision\\Adapters\\Phpunit\\Printer',
        ];
    }

    /**
     * Type of git-hook command belongs to.
     *
     * @return string
     */
    public static function hookType(): string
    {
        return 'pre-push';
    }
}

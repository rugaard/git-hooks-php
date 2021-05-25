<?php

declare(strict_types=1);

namespace Rugaard\GitHooks\PHP\Hooks\PreCommit;

use Rugaard\GitHooks\Abstracts\AbstractCommand;
use Rugaard\GitHooks\Style\GitHookStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Class PhpStaticAnalysisCommand.
 *
 * @package Rugaard\GitHooks\PHP\Hooks\PreCommit
 */
class PhpStaticAnalysisCommand extends AbstractCommand
{
    /**
     * Configures the current command.
     *
     * @return void
     */
    public function configure(): void
    {
        $this->setName('php:analyze')
             ->setDescription('Static analyze staged PHP files');
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
        $io->header('Static analysis of PHP files', false);

        // Make sure PHP Code Style checker exists.
        $driverPath = getcwd() . '/vendor/bin/phpstan';
        if (!file_exists($driverPath)) {
            $io->newLine();
            $io->block('Could not locate PHPStan: ' . $driverPath, 'ERROR', 'fg=red;options=bold', '');
            return Command::FAILURE;
        }

        $pathsToCheck = array_filter($this->getConfig('paths') ?? [], static function ($path) {
            return (is_dir(getcwd() . DIRECTORY_SEPARATOR . $path) || file_exists(getcwd() . DIRECTORY_SEPARATOR . $path));
        });

        // If only we're should check staged files,
        // then we'll need to update "pathsToCheck"
        // with the list of staged files.
        if ($this->getConfig('onlyStaged')) {
            // Get staged PHP files.
            $pathsToCheck = array_filter($this->getGitStagedFilesByExtension('php', [
                '--diff-filter=d'
            ]), static function ($stagedFile) use ($pathsToCheck) {
                foreach ($pathsToCheck as $pathToCheck) {
                    if (str_starts_with($stagedFile, $pathToCheck)) {
                        return true;
                    }
                }
                return false;
            });

            // If we don't have any staged PHP files,
            // then we'll mark the command as successful.
            if (empty($pathsToCheck)) {
                $io->block('Done', 'OK', 'fg=green;options=bold', '');
                return Command::SUCCESS;
            }

            // Get unstaged PHP files.
            $unstagedFiles = $this->getGitUnstagedFilesByExtension('php', [
                '--diff-filter=d'
            ]);

            // Check if we have a case,
            // where staged files has unstaged changes.
            $stagedFilesWithUnstagedChanges = array_intersect($pathsToCheck, $unstagedFiles);
            if (count($stagedFilesWithUnstagedChanges) > 0) {
                $io->block('Following staged files has unstaged changes:', 'ERROR', 'fg=red;options=bold', '');
                $io->listing($stagedFilesWithUnstagedChanges);
                $io->writeln('<fg=yellow>Fix the error and try again.</>');
                return Command::FAILURE;
            }
        }

        // Determine which config, we should use for analysis.
        if ($this->getConfig('config') !== null && file_exists(getcwd() . '/' . $this->getConfig('config'))) {
            $config = getcwd() . '/' . $this->getConfig('config');
            $io->writeln('<fg=cyan>[INFO] Using custom configuration file (' . $this->getConfig('config') . ')</>' . "\n");
        } elseif (file_exists(getcwd() . '/phpstan.neon')) {
            $config = getcwd() . '/phpstan.neon';
            $io->writeln('<fg=cyan>[INFO] Using project configuration file (phpstan.neon)</>' . "\n");
        } elseif (file_exists(getcwd() . '/phpstan.neon.dist')) {
            $config = getcwd() . '/phpstan.neon.dist';
            $io->writeln('<fg=cyan>[INFO] Using distributed configuration file (phpstan.neon.dist)</>' . "\n");
        }

        if (!empty($config)) {
            // Prepare process.
            $process = new Process(array_merge(
                [
                    $driverPath,
                    'analyze',
                    '--error-format=json',
                    '--configuration=' . $config,
                    '--no-progress',
                    '--no-ansi',
                ],
                !empty($this->getConfig('memory-limit')) ? ['--memory-limit=' . $this->getConfig('memory-limit')] : [],
                $pathsToCheck
            ));
        } else {
            // If no paths has been provided,
            // abort check with an error.
            if (empty($pathsToCheck)) {
                $io->block('No paths were provided.', 'ERROR', 'fg=red;options=bold', '');
                $io->writeln('<fg=yellow>Add paths in your "git-hooks.config.json" file.</>');
                return Command::FAILURE;
            }

            // Make sure we have a valid and supported level.
            $level = $this->getConfig('level') !== null && ($this->getConfig('level') >= 0 && $this->getConfig('level') <= 8) ? $this->getConfig('level') : 1;

            // Output info message
            $io->writeln('<fg=cyan>[INFO] Using default configuration with rule level ' . $level . '</>' . "\n");

            // Prepare process.
            $process = new Process(array_merge(
                [
                    $driverPath,
                    'analyze',
                    '--error-format=json',
                    '--level=' . $level,
                    '--no-progress',
                    '--no-ansi',
                ],
                !empty($this->getConfig('memory-limit')) ? ['--memory-limit=' . $this->getConfig('memory-limit')] : [],
                $pathsToCheck
            ));
        }

        // Execute process.
        $process->run();

        // If analysis returned without any errors
        // we'll output a successful message.
        if ($process->isSuccessful()) {
            $io->block('Done', 'OK', 'fg=green;options=bold', '');
            return Command::SUCCESS;
        }

        // Parse error data.
        $errors = json_decode($process->getOutput(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $io->block('Errors found', 'ERROR', 'fg=red;options=bold', '');
            $io->writeln('<fg=yellow>Could not decode errors returned by static analysis tool.</>');
            return Command::FAILURE;
        }

        // Build overview of error messages.
        foreach ($errors['files'] as $filename => $errorData) {
            // Array of table rows.
            $rows = [];

            // Loop through errors for current file.
            // and add them to table overview.
            foreach ($errorData['messages'] as $error) {
                // Separate previous row.
                if (count($rows) > 0) {
                    $rows[] = new TableSeparator();
                }

                // Add error to row.
                $rows[] = [
                    $error['line'],
                    $error['message']
                ];
            }

            // Render error table for current file.
            $io->boxedTable([
                'Line',
                str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $filename)
            ], $rows);
        }

        // Output error message.
        $io->block('Found ' . $errors['totals']['file_errors'] . ' errors', 'ERROR', 'fg=red;options=bold', '');
        $io->writeln('<fg=yellow>Fix the error(s) and try again.</>' . "\n");

        return Command::FAILURE;
    }

    /**
     * Get command's default configuration.
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'config' => null,
            'memory-limit' => null,
            'level' => 8,
            'onlyStaged' => true,
            'paths' => [],
        ];
    }

    /**
     * Type of git-hook command belongs to.
     *
     * @return string
     */
    public static function hookType(): string
    {
        return 'pre-commit';
    }
}

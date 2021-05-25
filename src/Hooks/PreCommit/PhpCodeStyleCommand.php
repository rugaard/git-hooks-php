<?php

declare(strict_types=1);

namespace Rugaard\GitHooks\PHP\Hooks\PreCommit;

use Rugaard\GitHooks\Abstracts\AbstractCommand;
use Rugaard\GitHooks\PHP\Exception\NoPathsProvidedException;
use Rugaard\GitHooks\Style\GitHookStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Class PhpCodeStyleCommand.
 *
 * @package Rugaard\GitHooks\PHP\Hooks\PreCommit
 */
class PhpCodeStyleCommand extends AbstractCommand
{
    /**
     * Configures the current command.
     *
     * @return void
     */
    public function configure(): void
    {
        $this->setName('php:cs')
            ->setDescription('Checks coding style in staged PHP files');
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
        $io->header('Checking coding style in PHP files', false);

        // Make sure PHP Code Style checker exists.
        $driverPath = getcwd() . '/vendor/bin/phpcs';
        if (!file_exists($driverPath)) {
            $io->newLine();
            $io->block('Could not locate PHP-CS: ' . $driverPath, 'ERROR', 'fg=red;options=bold', '');
            return Command::FAILURE;
        }

        // Determine which standard, we should use for style checking.
        if ($this->getConfig('config') !== null && (strpos($this->getConfig('config'), '.xml') !== false && file_exists(getcwd() . '/' . $this->getConfig('config')))) {
            $config = getcwd() . '/' . $this->getConfig('config');
            $io->writeln('<fg=cyan>[INFO] Using custom configuration file (' . $this->getConfig('config') . ')</>' . "\n");
        } elseif (file_exists(getcwd() . '/phpcs.xml')) {
            $config = getcwd() . '/phpcs.xml';
            $io->writeln('<fg=cyan>[INFO] Using project configuration file (phpcs.xml)</>' . "\n");
        } elseif (file_exists(getcwd() . '/phpcs.xml.dist')) {
            $config = getcwd() . '/phpcs.xml.dist';
            $io->writeln('<fg=cyan>[INFO] Using distributed configuration file (phpcs.xml.dist)</>' . "\n");
        }

        if (!empty($config)) {
            // Prepare process.
            $process = new Process(array_merge([
                $driverPath,
                '--report=json',
                '--standard=' . $config,
            ]));
        } else {
            // By default we will only check staged PHP files.
            // But if this is disabled, we will look to the "paths" array.
            // If this is also empty, we'll default to current directory.
            if ($this->getConfig('onlyStaged')) {
                // Get staged PHP files.
                $pathsToCheck = $this->getGitStagedFilesByExtension('php', [
                    '--diff-filter=d'
                ]);

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
                    $io->newLine();
                    $io->block('Following staged files has unstaged changes:', 'ERROR', 'fg=red;options=bold', '');
                    $io->listing($stagedFilesWithUnstagedChanges);
                    $io->writeln('<fg=yellow>Fix the error and try again.</>');
                    return Command::FAILURE;
                }
            } else {
                try {
                    // Get paths provided by config.
                    $pathsToCheck = $this->getPaths();
                } catch (NoPathsProvidedException $e) {
                    $io->block('No paths were provided.', 'ERROR', 'fg=red;options=bold', '');
                    $io->writeln('<fg=yellow>Add paths in your "git-hooks.config.json" file.</>');
                    return Command::FAILURE;
                }
            }

            // Validate provided standard.
            // If it's not supported, use PSR-12 as fallback.
            $standard = array_search($this->getConfig('standard'), $this->supportedStandards());
            if ($standard === false) {
                $standard = 'PSR12';
            }

            // Output info message.
            $io->writeln('<fg=cyan>[INFO] Using "' . $this->supportedStandards()[$standard] . '" standard</>' . "\n");

            // Prepare process.
            $process =  new Process(array_merge([
                $driverPath,
                '--report=json',
                '--standard=' . $standard,
                '--encoding=' . (!empty($this->getConfig('encoding')) ? $this->getConfig('encoding') : 'utf-8'),
                $this->getConfig('hideWarnings') ? '-n' : '',
            ], $pathsToCheck));
        }

        // Execute process.
        $process->run();

        // If code style check returned without any errors
        // we'll output a successful message.
        if ($process->isSuccessful()) {
            $io->block('Done', 'OK', 'fg=green;options=bold', '');
            return Command::SUCCESS;
        }

        // Parse failed data.
        $failedResponse = json_decode($process->getOutput(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $io->block('Errors found', 'ERROR', 'fg=red;options=bold', '');
            $io->writeln('<fg=yellow>Could not decode errors returned by code style checker.</>');
            return Command::FAILURE;
        }

        // Array of failed files.
        $fixableFiles = [];

        // Build overview of error messages.
        foreach ($failedResponse['files'] as $filename => $failure) {
            // If file has zero messages,
            // we'll skip it and move on.
            if (empty($failure['messages'])) {
                continue;
            }

            // Generate relative filename.
            $relativeFilename = str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $filename);

            // Array of table rows.
            $rows = [];

            // Loop through errors for current file.
            // and add them to table overview.
            foreach ($failure['messages'] as $data) {
                // Separate previous row.
                if (count($rows) > 0) {
                    $rows[] = new TableSeparator();
                }

                // If message is fixable, add the filename
                // to the array of fixable files.
                if (!in_array($relativeFilename, $fixableFiles) && $data['fixable']) {
                    $fixableFiles[] = $relativeFilename;
                }

                // Add error to row.
                $rows[] = [
                    '<fg=' . ($data['type'] === 'WARNING' ? 'yellow' : 'red') . ';options=bold>' . $data['type'] . '</>',
                    $data['line'] . ':' . $data['column'],
                    '[' . ($data['fixable'] ? 'x' : ' ') . ']',
                    $data['message']
                ];
            }

            // Render error table for current file.
            $io->writeln('<fg=cyan;options=bold>' . $relativeFilename . '</>');
            $io->boxedTable([
                'Type',
                'Line',
                'Fix',
                'Message',
            ], $rows);
        }

        $io->block('Coding style errors found', 'ERROR', 'fg=red;options=bold', '');

        // If PHP-CBF is available, then generate helping message.
        if (!empty($fixableFiles) && file_exists(getcwd() . '/vendor/bin/phpcbf')) {
            $io->writeln('<fg=white>Tip: Some errors can be fixed automatically by using following command:</>' . "\n");
            if (!empty($config)) {
                $io->writeln('<fg=white>  ./vendor/bin/phpcbf --standard=' . $config . ' ' . implode(' ', $fixableFiles) . '</>' . "\n");
            } else {
                $io->writeln(sprintf(
                    '<fg=white>  ./vendor/bin/phpcbf %s %s %s%s</>' . "\n",
                    '--standard=' . $standard,
                    '--encoding=' . (!empty($this->getConfig('encoding')) ? $this->getConfig('encoding') : 'utf-8'),
                    $this->getConfig('hideWarnings') ? '-n ' : ' ',
                    implode(' ', $fixableFiles)
                ));
            }
        }

        $io->writeln('<fg=yellow>Fix the error(s) and try again.</>' . "\n");
        return Command::FAILURE;
    }

    /**
     * Get all paths to check.
     *
     * @return array
     * @throws \Rugaard\GitHooks\PHP\Exception\NoPathsProvidedException
     */
    protected function getPaths(): array
    {
        $paths = array_filter($this->getConfig('paths') ?? [], function ($path) {
            return (is_dir(getcwd() . DIRECTORY_SEPARATOR . $path) || file_exists(getcwd() . DIRECTORY_SEPARATOR . $path));
        });

        if (empty($paths)) {
            throw new NoPathsProvidedException('pre-commit', 'An array of paths was not provided.', 412);
        }

        return $paths;
    }

    /**
     * Get array of supported coding standards.
     *
     * @return array
     */
    protected function supportedStandards(): array
    {
        return [
            'PSR1' => 'PSR-1',
            'PSR2' => 'PSR-2',
            'PSR12' => 'PSR-12',
            'Generic' => 'Generic',
            'MySource' => 'MySource',
            'Squiz' => 'Squiz',
            'Zend' => 'Zend'
        ];
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
            'encoding' => 'utf-8',
            'hideWarnings' => true,
            'onlyStaged' => true,
            'paths' => []
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

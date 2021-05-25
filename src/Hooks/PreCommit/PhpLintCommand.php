<?php

declare(strict_types=1);

namespace Rugaard\GitHooks\PHP\Hooks\PreCommit;

use Rugaard\GitHooks\Abstracts\AbstractCommand;
use Rugaard\GitHooks\Style\GitHookStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class PhpLintCommand.
 *
 * @package Rugaard\GitHooks\PHP\Hooks\PreCommit
 */
class PhpLintCommand extends AbstractCommand
{
    /**
     * Configures the current command.
     *
     * @return void
     */
    public function configure(): void
    {
        $this->setName('php:lint')
            ->setDescription('Checks staged PHP files for syntax errors');
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
        $io->header('Checking PHP files for syntax errors');

        // Get staged PHP files.
        $stagedFiles = $this->getGitStagedFilesByExtension('php', [
            '--diff-filter=d'
        ]);

        // If we don't have any staged PHP files,
        // then we'll mark the command as successful.
        if (empty($stagedFiles)) {
            $io->block('Done', 'OK', 'fg=green;options=bold', '');
            return Command::SUCCESS;
        }

        // Get unstaged PHP files.
        $unstagedFiles = $this->getGitUnstagedFilesByExtension('php', [
            '--diff-filter=d'
        ]);

        // Check if we have a case,
        // where staged files has unstaged changes.
        $stagedFilesWithUnstagedChanges = array_intersect($stagedFiles, $unstagedFiles);
        if (count($stagedFilesWithUnstagedChanges) > 0) {
            $io->block('Following staged files has unstaged changes:', 'ERROR', 'fg=red;options=bold', '');
            $io->listing($stagedFilesWithUnstagedChanges);
            $io->writeln('<fg=yellow>Fix the error and try again.</>');
            return Command::FAILURE;
        }

        // Array of files that failed linting.
        $failedFiles = [];

        foreach ($stagedFiles as $file) {
            // Check file for syntax errors.
            $response = trim(shell_exec(PHP_BINARY . ' -l ' . $file), "\n");

            // If no errors were found,
            // we'll silently move on to next file.
            if ($response === 'No syntax errors detected in ' . $file) {
                continue;
            }

            // Add failed file to internal array
            // with error message attached.
            $failedFiles[$file] = explode("\n", $response)[0];
        }

        // If one or more files failed linting,
        // we'll abort the script while listing
        // the failed files and the error messages.
        if (count($failedFiles) > 0) {
            $io->block('Syntax errors were found.', 'ERROR', 'fg=red;options=bold', '');
            foreach ($failedFiles as $file => $errorMessage) {
                $io->writeln([
                    sprintf('<fg=white;options=underscore>%s</>', OutputFormatter::escapeTrailingBackslash($file)),
                    $errorMessage . "\n"
                ]);
            }
            $io->writeln('<fg=yellow>Fix the error(s) and try again.</>');
            return Command::FAILURE;
        }

        // Output successful message.
        $io->block('Done', 'OK', 'fg=green;options=bold', '');

        return Command::SUCCESS;
    }

    /**
     * Get command's default configuration.
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [];
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

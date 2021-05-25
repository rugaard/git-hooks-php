# 🪝 Git Hooks for PHP [![GitHub Actions (tests)](https://github.com/rugaard/git-hooks-php/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/rugaard/git-hooks-php/actions/workflows/tests.yml)

This is a "plugin" package which seamlessly integrates with the [Git Hooks](https://github.com/rugaard/git-hooks) package. 

It will install `git` hooks, that will automatically run multiple checks on your projects PHP files, to make sure they do not contain errors and follow the expected coding standards.

## 📦 Installation

You install the package via [Composer](https://getcomposer.org/) by using the following command:

```shell
composer require rugaard/git-hooks rugaard/git-hooks-php
```

## 📝 Configuration

To change the default configuration of one or more script, you need to have a `git-hooks.config.json` file in your project root. If you don't, you can create it with the following command:

```shell
./vendor/bin/git-hooks config
```

### `Rugaard\GitHooks\PHP\Hooks\PreCommit\PhpCodeStyleCommand`

Checks all staged `.php` files for coding style errors.

| Parameter | Description | Default |
| :--- | :--- | :---: |
| `encoding` | Encoding of the files being checked | `utf-8` |
| `hideWarnings` | Hide code style warnings | `true` |
| `onlyStaged` | Only check code style on staged PHP files. | `true` |
| `paths` | Paths to directories/files that should be checked. _Only used when `onlyStaged` is set to `false`_. | `[]` |
| `config` | Path to custom configuration file.| `null` |

**Note:** By default, if a valid `config` has not been provided, this command will look for `phpcs.xml` or `phpcs.xml.dist` as an alternative.

If it finds any of those options, the above paramters will be ignored, and the configuration file will take priority.

### `Rugaard\GitHooks\PHP\Hooks\PreCommit\PhpLintCommand`

Checks all staged `.php` files for syntax errors.

_**Script has nothing to configure**_

### `Rugaard\GitHooks\PHP\Hooks\PreCommit\PhpStaticAnalysisCommand`

Statically analyzes all (or staged) `.php` files for errors.

| Parameter | Description | Default |
| :--- | :--- | :---: |
| `level` | Level of strictness to use. From `0` to `9`. | `8` |
| `onlyStaged` | Only analyze staged PHP files. | `true` |
| `paths` | Only the following directories/files should be checked. | `[]` |
| `memory-limit` | Set memory limit of process. Fx. `512M` for 512 MB. | `null` |
| `config` | Path to custom configuration file. | `null` |

**Note:** By default, if a valid `config` has not been provided, this command will look for `phpstan.neon` or `phpstan.neon.dist` as an alternative.

If it finds any of those options, the above paramters will be ignored, and the configuration file will take priority.

### `Rugaard\GitHooks\PHP\Hooks\PrePush\PhpTestSuiteCommand`

Runs the projects test suite(s).

| Parameter | Description | Default |
| :--- | :--- | :---: |
| `driver` | Application to run test suite(s). Supports: `phpunit` or `pest`* | `phpunit` |
| `printer` | Change printer used by `driver` application | `\\NunoMaduro\\Collision\\Adapters\\Phpunit\\Printer` |
| `config` | Path to custom configuration file | `null` |

_* Requires `pest` to be installed in your project_

**Note:** By default, if a valid `config` has not been provided, this command will look for `phpunit.xml` or `phpunit.xml.dist` as an alternative.

If it finds any of those options, the above paramters will be ignored, and the configuration file will take priority.

## 🚓 License

This package is licensed under [MIT](https://github.com/rugaard/git-hooks-php/blob/main/LICENSE).

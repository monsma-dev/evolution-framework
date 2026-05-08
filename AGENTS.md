# AGENTS.md

## Cursor Cloud specific instructions

### Codebase overview

This repository is the **Evolution Core** — an extracted subset of the `app/Core/Evolution/` namespace from the private [Evolution Framework](https://github.com/monsma-dev/Framework) monorepo. It is a PHP library (not a standalone application) and contains only:

- `app/Core/Evolution/` — the core PHP source files (~338 files)
- `composer.json` — PHP dependency manifest (requires PHP ^8.3)
- `README.md`

**Important:** There is no `bin/tooling` directory, no `package.json`, no TypeScript files, and no Node.js project in this repository. The `bin/tooling` path referenced by CI exists only in the parent Framework monorepo, not in this extracted core repo.

### Running static analysis (typecheck equivalent)

```bash
vendor/bin/phpstan analyse app/Core/Evolution/ --level=0 --no-progress --memory-limit=512M
```

PHPStan will report ~636 errors at level 0. The vast majority (630) are `class.notFound` errors from missing parent-repo classes (`App\Core\Container`, `App\Core\Config`, `App\Domain\AI\LlmClient`, etc.) — these are expected for an extracted subset. There are also 6 **internal** errors worth noting:

- 2x `new.noConstructor` — `EvolutionMentorService` is instantiated with parameters but has no constructor.
- 3x `staticMethod.notFound` — `EvolutionMasterOpinionService` calls undefined static methods on `EvolutionMentorService`.
- 1x `interface.notFound` — `GeminiClient` implements `LlmCompletionClientInterface` from the parent repo.

The first 5 may indicate real issues within Evolution Core; the last is a parent-repo dependency.

### Running tests

```bash
vendor/bin/phpunit
```

No test files or `phpunit.xml` config exist in this extracted repo (both live in the parent Framework repo). PHPUnit 11.5 is installed and ready if tests/config are added.

### Composer scripts caveat

`composer.json` defines `post-install-cmd` and `post-update-cmd` hooks that run `bin/scripts/ensure-ethereum-tx-php83.php`, which does **not exist** in this repo. Always use `--no-scripts` when running `composer install` or `composer update` here:

```bash
composer install --no-interaction --no-scripts
```

Similarly, `composer phpstan` references `-c phpstan.neon` which doesn't exist. Use the direct PHPStan command shown above instead.

### Autoloader caveat

`composer.json` autoloads `app/Support/helpers.php`, which does not exist in the parent repo's extraction. A stub file is created at that path during environment setup so the Composer autoloader doesn't fatally error. If you see a fatal error about `helpers.php`, run `composer dump-autoload`.

### PHP extensions installed

php8.3-cli, php8.3-xml, php8.3-mbstring, php8.3-curl, php8.3-zip, php8.3-mysql, php8.3-gmp, php8.3-bcmath, php8.3-redis.

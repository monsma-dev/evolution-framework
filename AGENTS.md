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

PHPStan will report ~636 errors at level 0 because this is an extracted subset missing many classes from the parent repo (e.g., `App\Core\Container`, `App\Core\Config`). These errors are **expected** and do not indicate problems with the Evolution Core code itself.

### Running tests

```bash
vendor/bin/phpunit
```

No test files exist in this extracted repo (the `tests/` directory is in the parent Framework repo). PHPUnit is installed and ready to run if tests are added.

### Autoloader caveat

`composer.json` autoloads `app/Support/helpers.php`, which does not exist in the parent repo's extraction. A stub file is created at that path during environment setup so the Composer autoloader doesn't fatally error. If you see a fatal error about `helpers.php`, run `composer dump-autoload`.

### PHP extensions installed

php8.3-cli, php8.3-xml, php8.3-mbstring, php8.3-curl, php8.3-zip, php8.3-mysql, php8.3-gmp, php8.3-bcmath, php8.3-redis.

# Framework (MVC marketplace)

PHP/Twig MVC applicatie.

**Documentatie:**

- Centrale docs-index: [`docs/README.md`](docs/README.md)
- Samengevoegd architectuur + roadmap overzicht: [`docs/project/framework-overview.md`](docs/project/framework-overview.md)
- Volledige technische audit: [`docs/project/FRAMEWORK_COMPLETE_DOCUMENTATION.md`](docs/project/FRAMEWORK_COMPLETE_DOCUMENTATION.md)

## Snelstart (lokaal)

- **Windows (aanbevolen):** dubbelklik of run vanuit de projectmap: **`composer.cmd install`** — zoekt zelf `C:\xampp\php\php.exe` of de env **`PHP_BINARY`**. Daarna: `composer.cmd test`, `composer.cmd phpstan`, enz.
- Composer anders: `composer install` (globaal geïnstalleerd) of `php composer.phar install` (`composer.phar` [download](https://getcomposer.org/download/), staat in `.gitignore`).
- **PHP permanent in PATH (eenmalig):** in PowerShell: `powershell -ExecutionPolicy Bypass -File scripts\add-xampp-php-to-user-path.ps1` — daarna nieuwe terminal; dan werkt `php -v` overal.
- **Handmatig zonder PATH:** `C:\xampp\php\php.exe composer.phar install` / `test` / `phpstan`
- Front-end build (Tailwind v4 + Vite): `cd tooling && npm install && npm run build`
- XAMPP: document root naar `public/` of projectpad zoals je nu gebruikt. **PHP 8.3+** (jouw XAMPP-php wordt gebruikt door Composer-scripts via `@php`).

## Kwaliteit

- `composer test` — PHPUnit (`vendor/bin`-scripts roepen PHP aan via `@php`, dus het werkt ook als `php` niet globaal in PATH staat)
- `composer phpstan` — static analysis (configureer uitbreiding in `phpstan.neon`)
- CI: `.github/workflows/ci.yml`

## Config

- API JWT-achtige tokens: `app.api_token_secret`, optioneel `app.api_token_audience` (of env `APP_API_TOKEN_*`).

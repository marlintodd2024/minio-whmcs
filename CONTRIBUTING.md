# Contributing to ImpulseMinio

Thanks for your interest in contributing! Here's how to get started.

## Development Setup

1. Clone the repo
2. Run `composer install` to get dev dependencies
3. Run checks locally before pushing:

```bash
# Syntax check across PHP versions
find modules/ includes/ -name "*.php" -exec php -l {} \;

# Static analysis
vendor/bin/phpstan analyse

# Code style
vendor/bin/phpcs
vendor/bin/phpcbf   # auto-fix what it can
```

## Pull Request Process

1. Fork the repo and create a feature branch from `main`
2. Make your changes
3. Ensure all CI checks pass (`php -l`, PHPStan, PHPCS)
4. Update `CHANGELOG.md` with your changes under an `## Unreleased` heading
5. Submit a PR with a clear description of what and why

## Code Style

- PSR-12 base with WHMCS module exceptions (snake_case functions, side effects in module files)
- PHP 7.4 minimum compatibility — no union types, named arguments, etc.
- All user input must be sanitized before use in shell commands or SQL
- Use `htmlspecialchars()` for any output rendered in HTML
- Use `escapeshellarg()` for any values passed to `exec()`

## Module Architecture

```
modules/servers/impulseminio/
├── impulseminio.php      # WHMCS module interface (provisioning, client area, admin)
├── hooks.php             # Addon billing hooks (storage recalculation)
├── lib/
│   └── MinioClient.php   # mc CLI wrapper — all MinIO operations go through here
└── templates/
    └── clientarea.tpl    # Smarty template (minimal — delegates to module output)

includes/hooks/
└── impulseminio_hooks.php  # Display hooks (suspension banner, sidebar hiding)
```

## Reporting Issues

- Use GitHub Issues for bugs and feature requests
- For security vulnerabilities, see [SECURITY.md](SECURITY.md)

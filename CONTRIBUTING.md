# Contributing to def-core

Thank you for your interest in contributing to def-core. This guide covers the workflow and conventions for submitting changes.

## Getting Started

### Prerequisites

- PHP 8.0+
- Node.js 18+ and npm
- Docker (for integration tests and smoke tests)
- [gitleaks](https://github.com/gitleaks/gitleaks) (for secret scanning)

### Local Setup

1. **Clone the repository:**

   ```bash
   git clone https://github.com/a3rev-ai/def-core.git
   cd def-core
   ```

2. **Install dependencies:**

   ```bash
   npm install
   composer install
   ```

3. **Start the local WordPress environment:**

   ```bash
   npm run env:start
   ```

   This uses [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) to spin up WordPress + Docker with the plugin loaded. The environment is configured in `.wp-env.json`.

4. **Run the tests to verify your setup:**

   ```bash
   php tests/run.php          # Unit tests (no Docker needed)
   npm run smoke              # Smoke tests (requires Docker)
   npm run env:stop           # Stop when done
   ```

See [docs/TESTING.md](docs/TESTING.md) for the full testing guide.

## Workflow

### Branch Naming

Create a feature branch from `main`:

```bash
git checkout main
git pull
git checkout -b <type>/<short-description>
```

Branch type prefixes:

| Prefix | Use For |
|--------|---------|
| `feature/` | New functionality |
| `fix/` | Bug fixes |
| `docs/` | Documentation only |
| `chore/` | Maintenance (deps, CI, tooling) |
| `refactor/` | Code restructuring without behaviour change |

Examples: `feature/bulk-export-filter`, `fix/jwt-expiry-edge-case`, `docs/update-readme`

### Commits

- Write clear commit messages that explain **why**, not just what
- One logical change per commit
- Reference issue numbers where applicable (e.g., `Fix token refresh race condition (#42)`)

### Pull Requests

1. Push your branch and open a PR against `main`
2. Fill in the PR description — what changed and why
3. Ensure all PR gate checks pass (see below)
4. A maintainer will review your PR

### PR Gate (Must Pass)

Every PR must pass these checks before merging:

1. **Unit tests** — `php tests/run.php` (~170 tests, no Docker)
2. **Smoke test** — `npm run smoke` (WordPress boots, plugin activates, routes register)
3. **Secret scanning** — `gitleaks detect` (no secrets in code)

See [docs/TESTING.md](docs/TESTING.md) for details on all test tiers.

## Code Conventions

### PHP

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- PHP 8.0 minimum — use typed properties and union types where appropriate
- Sanitise all input (`sanitize_text_field()`, `absint()`, etc.)
- Escape all output (`esc_html()`, `esc_attr()`, `esc_url()`)
- Use nonces for form submissions and AJAX
- Check capabilities before any privileged operation

### Security

- Never hardcode secrets, API keys, or credentials
- Use `DEF_Core_Encryption` for storing sensitive values
- All REST endpoints must have proper `permission_callback` functions
- Review [WordPress Plugin Security](https://developer.wordpress.org/plugins/security/) guidelines

### JavaScript

- No build step required — vanilla JS
- Use `textContent` and `createElement` instead of `innerHTML` (XSS prevention)
- Prefix CSS classes with `def-` to avoid conflicts

## Extending the Plugin

def-core has a module system for adding new tool endpoints. See [MODULE_DEVELOPMENT.md](MODULE_DEVELOPMENT.md) for the full guide on:

- Creating extension modules
- Registering tools via the API Registry
- Using the Tool Base Class
- Best practices for module development

## Reporting Issues

Open an issue at [github.com/a3rev-ai/def-core/issues](https://github.com/a3rev-ai/def-core/issues) with:

- WordPress version and PHP version
- Steps to reproduce
- Expected vs actual behaviour
- Any error messages from the browser console or PHP error log

## License

By contributing, you agree that your contributions will be licensed under the [GPLv3](LICENSE.txt).

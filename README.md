# AnthonySkelton.com

The WordPress site for [anthonyskelton.com](https://anthonyskelton.com). It's a Composer-managed WordPress install forked from the [SpinupWP Composer site](https://github.com/deliciousbrains/spinupwp-composer-site) starter, hosted on SpinupWP and deployed by GitHub Actions.

## Versions

Last updated 2026-11-23. `composer.lock` is the source of truth; a weekly automated pull request keeps it current (see [Updating dependencies](#updating-dependencies)).

| Component | Version |
|---|---|
| WordPress core (`johnpbloch/wordpress`) | 7.1.5 |
| PHP used by the production build | 8.5 |
| PHP for local development (LocalWP) | 8.5.13 |
| PHP required by `composer.json` | >= 7.1 |

### Plugins and libraries

| Package | Constraint | Locked |
|---|---|---|
| `ajskelton/block-demos` | `dev-main` | dev-main |
| `deliciousbrains-plugin/wp-migrate-db-pro` | `2.*` | 2.7.12 |
| `sybrew/the-seo-framework` | `5.*` | 5.2.1 |
| `wpackagist-plugin/akismet` | `5.*` | 5.7.2 |
| `wpackagist-plugin/change-last-modified-date` | `1.4.8` (pinned) | 1.4.8 |
| `wpackagist-plugin/google-site-kit` | `1.*` | 1.192.0 |
| `wpackagist-plugin/icon-block` | `2.*` | 2.1.0 |
| `wpackagist-plugin/limit-login-attempts-reloaded` | `3.*` | 3.4.6 |
| `wpackagist-plugin/mailgun` | `2.*` | 2.2.3 |
| `wpackagist-plugin/spinupwp` | `1.*` | 1.9.1 |
| `wpackagist-plugin/wordfence` | `9.*` | 9.1.1 |
| `composer/installers` | `^2.3` | v2.3.0 |
| `oscarotero/env` | `^2.1` | v2.1.1 |
| `vlucas/phpdotenv` | `^5.6` | v5.8.1 |
| `roave/security-advisories` (dev) | `dev-latest` | dev-latest |

Not every installed plugin is active on the site; that's expected.

### Code in this repo

* `public/content/themes/anthonyskelton`: the site theme. Its built assets aren't committed; the deploy workflow builds them (see [Deployment](#deployment)).
* `public/content/themes/phobos`: a Git submodule (`ajskelton/phobos`).
* `public/content/mu-plugins/app.php`: must-use plugin that loads `app/`.
* `app/`: site code, including the Library book post type and the book, beer and race importers.
* `config/`: configuration. Environment-specific settings are in `config/environments/{development,staging,production}.php`.

## Package sources

* WordPress core comes from `johnpbloch/wordpress`.
* Plugins come from [WPackagist](https://wpackagist.org), [composer.deliciousbrains.com](https://composer.deliciousbrains.com) (WP Migrate DB Pro), Packagist (`sybrew/the-seo-framework`) and the [`ajskelton/block-demos`](https://github.com/ajskelton/block-demos) GitHub repository.
* `composer.deliciousbrains.com` needs credentials in a gitignored `auth.json` at the repo root. In CI they come from the `COMPOSER_AUTH_JSON` secret.

## Layout

| Path | Contents |
|---|---|
| `public/` | Web root. Set it as the document root on the server. |
| `public/wp/` | WordPress core, installed by Composer (gitignored) |
| `public/content/` | `wp-content`: themes, plugins, uploads |
| `vendor/` | Composer dependencies (gitignored) |

WordPress admin is at `/wp/wp-admin/`.

## Local development

The local copy runs in [LocalWP](https://localwp.com) at `http://anthonyskelton.test`, with this repo checked out in the site's `app/` folder.

1. Clone with submodules (`git clone --recurse-submodules`), or run `git submodule update --init` afterwards.
2. Add `auth.json` with the Delicious Brains credentials.
3. Copy `.env.example` to `.env` and fill in the database settings, `WP_ENV`, `WP_HOME` and `WP_SITEURL`. `config/app.php` reads it.
4. Run `composer install`.
5. Build the theme: in `public/content/themes/anthonyskelton`, run `composer install`, `npm install` and `npm run build` (or `npm start` to watch).

## Updating dependencies

A scheduled Claude Code task checks for WordPress core and plugin updates every week:

1. It runs `composer update` on a dated `updates-YYYY-MM-DD` branch created from `master`, and commits only `composer.lock`. It never changes version constraints.
2. It opens a pull request into `master` that lists every version change and any major versions the constraints block.
3. It installs the update on the LocalWP site, smoke-tests it (WP-CLI checks, front-end requests, error logs) and posts the results as a PR comment.

Merging the PR deploys the update.

Composer's PHP matters when you update by hand. `roave/security-advisories` refuses to resolve dependencies on a PHP release with known vulnerabilities, so run `composer update` with a current, patched PHP. On 2026-11-23, PHP 8.5.10 was blocked; 8.5.13 and 8.6.0 work.

## Deployment

Deployment is fully automated with GitHub Actions (it previously ran on Buddy). **Every push to `master` deploys to production**, so make changes on a branch and merge them with a pull request. There's no staging or development deploy.

`.github/workflows/production.yml` runs three jobs:

1. **build_site**: installs Composer dependencies with PHP 8.5, using `auth.json` from the `COMPOSER_AUTH_JSON` secret.
2. **build_theme**: builds the `anthonyskelton` theme with Node.js LTS (`composer install`, `npm install`, `npm run build`).
3. **deploy**: combines both builds and rsyncs them to the SpinupWP server over SSH (`easingthemes/ssh-deploy`). `--delete` removes files that aren't in the build, except `.env` and `public/content/uploads` (listed in `rsync-exclude.txt`).

Changes to `README.md` or `bin/**` alone don't trigger a deploy.

### Secrets

| Secret | Used for |
|---|---|
| `COMPOSER_AUTH_JSON` | `auth.json` for composer.deliciousbrains.com |
| `SSH_PRIVATE_KEY` | SSH key for the deploy |
| `REMOTE_HOST` | Server hostname |
| `REMOTE_USER_PRODUCTION` | SSH user for production |
| `REMOTE_TARGET` | Path to the site on the server |

The server keeps its own `.env` and uploads, which the deploy never touches.

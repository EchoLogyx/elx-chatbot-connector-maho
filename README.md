# ELX Chatbot Connector for Maho

Connects a Maho store to ELX Chatbot. The module registers the store with a secret key, publishes a product feed, and syncs order lookup credentials.

Package name: `elx/chatbot-connector`

## Requirements

- PHP 8.3 or newer, with the `curl` and `json` extensions
- A Maho 26.9 or newer project. Install this package inside that project. It is not a standalone application.
- The Maho project must allow `mahocommerce/maho-composer-plugin` (a project created from `mahocommerce/maho` already does)

## Install

From the Maho project root (the directory that contains `composer.json` and `./maho`):

```bash
composer require elx/chatbot-connector
./maho migrate
./maho cache:flush
```

Composer puts the module in `vendor/elx/chatbot-connector`. Maho loads `app/code`, `app/etc/modules`, and `app/design` from that directory. Nothing is copied into Maho core.

`composer require` recompiles routes and cron jobs. `./maho migrate` creates the `elxchatbot_settings` table from `sql/schema.php`. The cache flush loads the new module.

Open the Maho admin. The menu item is **ELX Chatbot Connector**.

If **View JSON Feed** does not return JSON, allow `/feeds/*.json` in the store's nginx config. The feed file is written to `public/feeds/feed.json` when a merchant connects.

## Update

```bash
composer update elx/chatbot-connector
./maho migrate
./maho cache:flush
```

## Remove

```bash
composer remove elx/chatbot-connector
./maho cache:flush
```

This removes the code. The `elxchatbot_settings` table and any saved secret stay in the database. Maho does not drop that table on migrate.

## Versions

Releases are git tags. Do not put a `version` field in `composer.json`. Packagist reads the tag.

| Tag | Meaning |
| --- | --- |
| `v1.0.0` | First Composer release |
| `v1.0.1` | Bug fix |
| `v1.1.0` | Backward-compatible feature |
| `v2.0.0` | Breaking change |

Push the tag, then Packagist picks it up after the GitHub hook is connected:

```bash
git tag v1.0.1
git push origin v1.0.1
```

Customers receive that release the next time they run `composer update elx/chatbot-connector`.

## Publish on Packagist

Packagist registration is manual. The GitHub repository must be public.

1. Push this repository to GitHub, including the `v1.0.0` tag.
2. Sign in at [https://packagist.org](https://packagist.org) with the GitHub account that should own the `elx` vendor.
3. Submit [https://github.com/EchoLogyx/elx-chatbot-connector-maho](https://github.com/EchoLogyx/elx-chatbot-connector-maho).
4. On the package page, choose **GitHub Hook** so new tags are imported without another submit.

After that, a Maho store installs it with:

```bash
composer require elx/chatbot-connector
```

## Zip install

`install.sh` is the older install from `packages/elx-chatbot/elx-chatbot-connector-1.0.0.zip`. Use the Composer commands above for stores that can reach Packagist. Composer reads the version from that zip filename when the archive has no `version` field.

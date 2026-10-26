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

Run `./maho` with the same PHP that serves the store. A Docker-based Maho install keeps Redis inside the app container. Running `./maho` on the host then fails with `Cannot find the "redis" extension`. Use the container instead:

```bash
docker compose exec -u www-data app php ./maho migrate
docker compose exec -u www-data app php ./maho cache:flush
```

Open the Maho admin. The menu item is **ELX Chatbot Connector**.

## Product feed URL

Connecting the store writes `public/feeds/feed.json`. Maho's default nginx answers `deny all` for `.json` files, so the feed URL returns **403** until that file is allowed. The module cannot change the web server config.

Put these two locations above the general `.json` deny rule. nginx uses the first matching regular expression.

```nginx
# Product feeds are public JSON files written under public/feeds/.
location ~ ^/feeds/.*\.json$ {
    try_files $uri =404;
    default_type application/json;
    expires -1;
    add_header Cache-Control "no-cache, must-revalidate";
    access_log off;
}

# Catalog links can prepend the store code (/default/feeds/feed.json).
location ~ ^/[^/]+/feeds/[^/]+\.json$ {
    rewrite ^/[^/]+(/feeds/[^/]+\.json)$ $1 last;
}
```

Reload nginx after saving the file. On this Docker setup that is `docker compose exec nginx nginx -s reload`, or restart the nginx service if it has another name.

## Update

```bash
composer update elx/chatbot-connector
./maho migrate
./maho cache:flush
```

On a Docker install, run the two `./maho` commands inside the app container, as in the install section.

## Remove

```bash
composer remove elx/chatbot-connector
./maho cache:flush
```

On a Docker install, run the cache flush inside the app container.

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

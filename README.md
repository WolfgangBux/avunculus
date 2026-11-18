[![Latest release](https://img.shields.io/github/v/release/WolfgangBux/avunculus)](https://github.com/WolfgangBux/avunculus/releases/latest)
[![License: AGPL v3](https://img.shields.io/badge/license-AGPL--3.0--or--later-blue)](LICENSE)
[![CI](https://github.com/WolfgangBux/avunculus/actions/workflows/ci.yml/badge.svg)](https://github.com/WolfgangBux/avunculus/actions/workflows/ci.yml)

# Avunculus — online collaborative genealogy

Avunculus is a web application for recording, researching and sharing family history. It runs on an ordinary web hosting package with PHP and MariaDB, works in any modern browser, and exchanges data with other genealogy programs through GEDCOM. It is built for large family trees (in use with more than 50,000 people in a single tree) and for several people working on the same data.

*Avunculus* is Latin for a maternal uncle.

**Key features**

- **Several family trees** in one installation, each with its own people, families, sources and media. Places are shared by all trees.
- **People** with multiple names, events and facts (birth, baptism, occupation, residence …), citations with page, transcription, link and quality rating, associated people (godparents, witnesses) and media.
- **Families** with partners, children (including pedigree such as adoption), family events and their own citations.
- **Tree view** with ancestors, siblings and descendants, and a map of each person's places.
- **Sources and repositories**, with a list of every event that cites a source.
- **Place hierarchy** (village → municipality → district …) with alternative names, coordinates, GOV identifiers, Wikidata matching and merging of duplicate places.
- **Media library** for photos, scans, PDFs, audio and video, linked to people, families, events and sources.
- **GEDCOM import** (5.5, 5.5.1, 7.0; also ZIP/GEDZIP with media files) into a new tree or merged into an existing one, with duplicate detection and manual matching.
- **GEDCOM export** (5.5.1, UTF-8), optionally as a ZIP archive including all media.
- **Relatives tree**: create a new tree from all blood relatives of a person and their partners.
- **Statistics** with charts and a map.
- **Users and privacy**: administrators and editors. Visitors who are not logged in only see trees that have been published, with living people hidden.
- **Change log**: who changed what and when, down to individual fields ("Birth – date: 1788 → ABT 1788").
- User interface in **English and German**.

This software is licensed under the GNU Affero General Public License v3 or later. See [LICENSE](LICENSE) for the full text.

## Getting started

### Download

Download `avunculus-<version>.zip` from the [latest release](https://github.com/WolfgangBux/avunculus/releases/latest). This archive is ready to upload; nothing needs to be built.

The green "Code → Download ZIP" button on GitHub gives you the source code instead, which needs Node.js to build the user interface (see [Building from source](#building-from-source)).

### Server requirements

| Component | Requirement |
|---|---|
| Web server | Apache with `mod_rewrite` and `.htaccess` files enabled (standard on most web hosts) |
| PHP | 8.1 to 8.5. We recommend 8.3 or later: PHP 8.1 no longer receives security updates, and 8.2 only until the end of 2026. |
| Database | MariaDB (developed with 11.4). MySQL 8 should work but is untested. |
| Address | Its own (sub)domain, e.g. `https://genealogy.example.com/`, or a subfolder of an existing website, e.g. `https://example.com/genealogy/` |

Required PHP extensions: `pdo_mysql`, `mbstring`, `gd`, `exif`, `zip`, `fileinfo`.

HTTPS is strongly recommended, because passwords are sent when logging in.

Uploads (GEDCOM files, media) are sent in small pieces, so the host's upload limits do not matter. Importing a GEDCOM file with tens of thousands of people can take several minutes, though. Some hosts stop PHP scripts after 30 or 60 seconds (`max_execution_time`). If an import is cut off, raise that limit in your hosting control panel, or import locally and transfer the database.

### Installation

1. Create a database in your hosting control panel and note its name, user, password and server.
2. Open the database in phpMyAdmin (or your host's equivalent), choose **Import** and import `database/schema.sql` from the archive.
3. Rename `backend/.env.example` to `backend/.env` and enter the database details (see [Configuration](#configuration)).
4. Upload the contents of the archive to the folder your (sub)domain points to, or to a new subfolder of your website (e.g. `genealogy/`). Avunculus finds out by itself where it is installed.
5. Open the address in your browser and create the first administrator account. This is only possible while no user exists. Then create a tree with **File → New**, or import one with **File → Import GEDCOM**.

Avunculus stores media files in `data/media/`. PHP needs write permission there; the folder is created on the first upload.

The `.htaccess` file sends every request through `router.php`, which only serves the user interface and the API, never `backend/.env`, media files or source code. If `mod_rewrite` is not available, the `.htaccess` file blocks all access rather than exposing anything.

### Configuration

`backend/.env`:

| Setting | Meaning | Default |
|---|---|---|
| `DB_HOST`, `DB_PORT` | Database server (often `localhost` on web hosts) | `localhost`, `3306` |
| `DB_NAME` | Database name | `avunculus` |
| `DB_USER`, `DB_PASS` | Database user and password | `root`, empty |
| `MEDIA_DIR` | Where media files are stored (one subfolder per tree) | `data/media` in the installation folder |
| `UPDATE_CHECK` | Check GitHub for new releases (`0` to turn off) | `1` |

Environment variables, where set, take precedence over `backend/.env`.

### Upgrading

Avunculus checks GitHub for new releases at most once a day. When one is available, administrators see a dot on their user menu and an entry **Update to … available**. The update dialog shows the release notes and installs the new version with one click:

1. The release archive is downloaded and checked against its SHA-256 checksum.
2. The program files are replaced. `backend/.env` and `data/` (your media files) are kept, and the previous version is kept in `data/updates/previous/`. If anything goes wrong, the previous version is restored.
3. The page reloads, and any database changes the new version needs are applied automatically.

Please back up your database (phpMyAdmin → **Export**) before updating; the dialog asks you to confirm this.

The automatic installation needs PHP to be allowed to change the program files and to download files (`curl` or `allow_url_fopen`). If that is not possible on your host, the dialog says so. Then update by hand: download the new release and upload all files, overwriting the old ones. Keep your `backend/.env` and `data/`. Database changes are applied automatically on the next page load in this case too.

To turn off the update check, set `UPDATE_CHECK=0` in `backend/.env`.

### External services

Map tiles are loaded by the browser from OpenStreetMap. The place matching tool queries Wikidata, and only when you use it. Places link to the GOV gazetteer (gov.genealogy.net). The server only connects to GitHub: to check for new releases (at most once a day, only when an administrator is logged in; can be turned off) and to download an update when an administrator installs one.

## Technical architecture

### Project structure

```
.htaccess            Apache: send every request to router.php
router.php           serves public/ and forwards /api/ to the backend
backend/
  index.php          API entry point: session, tree (header X-Tree-Id), permissions, change log
  routes.php         every endpoint: method, path, controller, action, required role
  api/               controllers (Person, Family, Source, Place, Media, Tree, User, Change …)
  lib/               GEDCOM import/export, privacy, place hierarchy, media storage, row synchronisation
                     (RowSync), change log (ChangeLog, ChangeDiff), migrations, updater …
  migrations/        numbered database changes, applied automatically
database/schema.sql  complete schema for a new installation
VERSION              version number of this release
frontend/            Vue source code, built into public/
  src/api/           one module per API area
  src/components/    Vue components (lists, forms, dialogs, tree view, maps)
  src/composables/   shared logic (paged search, address routing, menus …)
  src/i18n/          translations
  src/assets/main.css  global styles; all adjustments to the UI library at the end
public/              built user interface (not in Git; included in releases)
scripts/             release build
```

### Technology

| Part | Used |
|---|---|
| Frontend | Vue 3, Vite 5, [OpenVue](https://www.npmjs.com/package/openvue) 1.1 (MIT fork of PrimeVue 4) with the Aura theme, Leaflet |
| Backend | PHP without a framework: PDO and a small REST API |
| Database | MariaDB |

The PHP backend has no third-party dependencies, so there is no Composer step.

### Request handling

1. `.htaccess` (or `php -S … router.php` during development) passes every request to `router.php`.
2. `router.php` serves files from `public/`, falls back to `public/index.html` for the single-page app, and forwards `/api/…` to `backend/index.php`.
3. `backend/index.php` checks the session and the origin of write requests, matches the route in `routes.php`, checks the required role and, for tree data, the tree given in the `X-Tree-Id` header.
4. The controller action runs. Every successful write request is recorded in the change log.

### Database

`database/schema.sql` is the complete schema for a new installation. It contains no `CREATE DATABASE`, `USE` or `DROP TABLE`, so it can be imported into any empty database and fails on an existing one instead of deleting data. Every table uses `utf8mb4`.

Changes to the schema are numbered migrations in `backend/migrations/`. The database records the number of the last migration it has run (`settings.schema_version`), and missing migrations run automatically on the first request after an update. A new installation starts at the newest number.

### Building from source

Requirements: Node.js 18 or later, PHP and MariaDB as above.

```bash
cd frontend && npm install && npm run build   # writes the user interface to public/
php -S localhost:8000 router.php              # http://localhost:8000
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for setting up a development environment.

### Releases

The version number is in the file `VERSION`. `scripts/build-release.sh` builds `dist/avunculus-<version>.zip` (the built user interface, the backend, the schema and the documentation) and a `.sha256` checksum file. Pushing a tag such as `v1.1.0` runs the same script on GitHub Actions and attaches both files to a new GitHub release, where installations find them.

## Contributing

Bug reports, ideas and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).

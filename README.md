[![Latest release](https://img.shields.io/github/v/release/WolfgangBux/avunculus)](https://github.com/WolfgangBux/avunculus/releases/latest)
[![License: AGPL v3](https://img.shields.io/badge/license-AGPL--3.0--or--later-blue)](LICENSE)
[![CI](https://github.com/WolfgangBux/avunculus/actions/workflows/ci.yml/badge.svg)](https://github.com/WolfgangBux/avunculus/actions/workflows/ci.yml)

# Avunculus — online collaborative genealogy

Avunculus is a web application for recording, researching and sharing family history. It runs on an ordinary web hosting package with PHP and MariaDB, works in any modern browser, and exchanges data with other genealogy programs through GEDCOM. It is built for large family trees (in use with more than 50,000 people in a single tree) and for several people working on the same data.

*Avunculus* is Latin for a maternal uncle.

**Key features**

- **Several family trees** in one installation, each with its own people, families, sources and media. Places are shared by all trees.
- **People** with multiple names, events and facts (birth, baptism, occupation, residence …), citations with page, transcription, link and quality rating, associated people (godparents, witnesses) and media. All forms save automatically as you type.
- **Surname overview** as the way into large trees: surnames by initial letter with counts, a cloud of the most common names, everyone bearing a name, and its branches (the descendants who carry the name on, with their partners).
- **Quick search** (Ctrl+K) for people, places, sources and media from anywhere in the app.
- **Families** with partners, children (including pedigree such as adoption), family events and their own citations.
- **Tree view** with ancestors, siblings and descendants, and a map of each person's places with their course of life, optionally with parents and grandparents.
- **Sources and repositories**, with a list of every event that cites a source.
- **Place hierarchy** (village → municipality → district …) with alternative names, coordinates, GOV identifiers, Wikidata matching and merging of duplicate places.
- **Media library** for photos, scans, PDFs, audio and video, linked to people, families, events and sources.
- **GEDCOM import** (5.5, 5.5.1, 7.0; also ZIP/GEDZIP with media files) into a new tree or merged into an existing one, with duplicate detection and manual matching.
- **GEDCOM export** (5.5.1, UTF-8), optionally as a ZIP archive including all media.
- **Relatives tree**: create a new tree from all blood relatives of a person and their partners.
- **Statistics** with charts and a map.
- **Reports for printing or PDF**: ancestor list with Kekulé numbers (Ahnenliste), pedigree chart, descendant report, family group sheet, individual sheet, a family book in the style of a German *Ortsfamilienbuch* (numbered families with cross-references and an index of names), and lists of births, baptisms, deaths, marriages, occupations and cemeteries. Family book and lists can be limited to years and a place, the family book also to a surname. Each report opens as a print view in a new tab; the browser saves it as a PDF.
- **Users and privacy**: administrators and editors. Visitors who are not logged in only see trees that an administrator has published (tree menu → *Make public*), with living people hidden.
- **Change log**: who changed what and when, down to individual fields ("Birth – date: 1788 → ABT 1788").
- **Relationship calculator**: names how two people are related (great-uncle, second cousin once removed, half-sister, brother-in-law, stepmother …) and draws the path through their closest common ancestors – in German with the usual terms (Onkel 2. Grades, Ururgroßvater). Pedigree collapse shows every way they are related.
- **Error search**: finds implausible dates such as a mother who is too young or too old, a child born after a parent's death, death before birth or a marriage after death. Approximate dates (about, before, between) never cause false alarms.
- User interface in **English and German**, usable down to phone size: what does not fit in the menu bar moves into a *More* menu.

This software is licensed under the GNU Affero General Public License v3 or later. See [LICENSE](LICENSE) for the full text.
The web interface bundles open-source libraries under MIT, BSD and ISC licenses; their notices are collected in `THIRD-PARTY-LICENSES.txt` (in the release archive and linked in the app's footer). If you run a modified version on a public server, the AGPL requires you to offer its source code to your users: set `SOURCE_URL` in `frontend/src/about.js` to your repository.

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

Required PHP extensions: `pdo_mysql`, `mbstring`, `gd`, `exif`, `zip`, `fileinfo`. Recommended: `intl` (better matching of names in other alphabets when looking for duplicates).

A PHP memory limit of 128 MB is enough to import a GEDCOM file with more than 50,000 people into a new tree. Merging a large file into a large existing tree needs more; Avunculus raises the limit for imports by itself where the host allows it.

HTTPS is strongly recommended, because passwords are sent when logging in.

Uploads (GEDCOM files, media) are sent in small pieces, so the host's upload limits do not matter. Importing a GEDCOM file with tens of thousands of people can take several minutes, though. Some hosts stop PHP scripts after 30 or 60 seconds (`max_execution_time`). If an import is cut off, raise that limit in your hosting control panel, or import locally and transfer the database.

### Installation

1. Create a database in your hosting control panel and note its name, user, password and server.
2. Open the database in phpMyAdmin (or your host's equivalent), choose **Import** and import `database/schema.sql` from the archive.
3. Rename `backend/.env.example` to `backend/.env` and enter the database details (see [Configuration](#configuration)).
4. Upload the contents of the archive to the folder your (sub)domain points to, or to a new subfolder of your website (e.g. `genealogy/`). Avunculus finds out by itself where it is installed.
5. Open the address in your browser and create the first administrator account. This is only possible while no user exists. Then create a tree with **Choose a tree → New tree** at the top left, or import one with **Choose a tree → Import GEDCOM**.

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
| `UPDATE_CHECK` | Check for new releases once a day (`0` to turn off) | `1` |
| `USAGE_STATS` | Let the update check count this installation (`0` to turn off, see [External services](#external-services)) | `1` |

Environment variables, where set, take precedence over `backend/.env`.

#### Site name

Sign in as administrator, open *Settings…* in the user menu and enter a name for your site, e.g. "Smith family history". It appears next to the logo and as the title in the browser tab. Leave it empty to show "Avunculus".

#### Legal notice and privacy policy

Whoever runs an installation is responsible for its legal notice (imprint) and privacy policy. If you run Avunculus publicly, sign in as administrator, open *Settings…* in the user menu and enter the addresses of your pages. The links then appear in the footer and in the sign-in dialog. Leave a field empty for no link.

#### Your own server settings

Some hosts need extra settings, for example to write a PHP error log. Put them in `.htaccess` **below the line `# END Avunculus`**: updates only replace the part between `# BEGIN Avunculus` and `# END Avunculus` and keep everything else. Settings that are purely PHP (`error_log`, `memory_limit` …) can also go into a file `.user.ini` in the installation folder, if your host supports it; Avunculus never ships or changes that file.

### Upgrading

Avunculus checks GitHub for new releases at most once a day. When one is available, administrators see a dot on their user menu and an entry **Update to … available**. The update dialog shows the release notes and installs the new version with one click:

1. The release archive is downloaded and checked against its SHA-256 checksum.
2. The program files are replaced. `backend/.env`, `data/` (your media files) and your own entries in `.htaccess` are kept, and the previous version is kept in `data/updates/previous/`. If anything goes wrong, the previous version is restored.
3. The page reloads, and any database changes the new version needs are applied automatically.

Please back up your database (phpMyAdmin → **Export**) before updating; the dialog asks you to confirm this.

The automatic installation needs PHP to be allowed to change the program files and to download files (`curl` or `allow_url_fopen`). If that is not possible on your host, the dialog says so. Then update by hand: download the new release and upload all files, overwriting the old ones. Keep your `backend/.env` and `data/`, and copy your own lines from the old `.htaccess` (below `# END Avunculus`) into the new one. Database changes are applied automatically on the next page load in this case too.

To turn off the update check, set `UPDATE_CHECK=0` in `backend/.env`. To keep it but not be counted, set `USAGE_STATS=0`.

### External services

Map tiles are loaded by the browser from OpenStreetMap. The place matching tool queries Wikidata, and only when you use it. Places link to the GOV gazetteer (gov.genealogy.net). The server connects to the outside world only for updates:

- **Checking for new releases** (at most once a day, only when an administrator is logged in): the request goes to the Avunculus homepage (`avunculus.wolfgang-baumgartner.de`), which passes on the answer from GitHub. It sends a random identifier of the installation, created on the first check, and the Avunculus and PHP versions – no content, no names. The address of the installation is sent only if at least one tree is published (the site is then public anyway) or an administrator agreed to it under *Settings…*; local addresses (localhost, IP addresses, home network names such as `.local` or `fritz.box`) are never sent. This tells the project how many installations are in use and which versions they run; the homepage stores no IP addresses for it. With `USAGE_STATS=0` the check goes straight to GitHub instead, and if the homepage cannot be reached, it does so too. `UPDATE_CHECK=0` turns the check off altogether. The update dialog shows which applies.
- **Installing an update**: the release archive is downloaded from GitHub when an administrator installs it.

The page also names itself in a `<meta name="generator">` tag (e.g. "Avunculus 1.0.4"), like many web applications.

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

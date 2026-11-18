# How to contribute

Thanks for taking the time to contribute! These are guidelines rather than rules. If something here is unclear or out of date, feel free to propose a change in a pull request.

## Reporting bugs and suggesting features

Open an issue on GitHub. For bugs, please include what you did, what you expected, what happened instead, and your browser, PHP and database versions. If the problem occurs with a GEDCOM file, a small example file that shows it helps a lot. Please remove data about living people first.

## How to submit a change

1. Fork the repository and create a branch from `main`.
2. Make your change and test it (see [How to test](#how-to-test)).
3. Open a pull request against `main`. Describe what the change does and why.

If your change needs a database change, add a migration (see [Database changes](#database-changes)).

Smaller pull requests that do one thing are easier to review than large ones.

## How to set up a development environment

You need:

- [Git](https://git-scm.com/)
- PHP 8.1 or later (8.3 or later recommended) with `pdo_mysql`, `mbstring`, `gd`, `exif`, `zip` and `fileinfo`
- MariaDB
- [Node.js](https://nodejs.org/) 18 or later

There is no Composer step: the PHP backend has no third-party dependencies.

```bash
git clone https://github.com/WolfgangBux/avunculus.git
cd avunculus

# Database
mariadb -u root -p -e "CREATE DATABASE avunculus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mariadb -u root -p avunculus < database/schema.sql
cp backend/.env.example backend/.env      # enter your database credentials

# User interface
cd frontend && npm install && npm run build && cd ..

# Start
php -S localhost:8000 router.php           # http://localhost:8000
```

On first start, create an administrator in the browser, then create or import a tree.

While working on the user interface, also run `npm run dev` in `frontend/`. It serves the app with hot reload on http://localhost:5173 and forwards `/api` to the PHP server on port 8000.

### Built files

`public/` is the build output of `frontend/` and is not committed. Release archives contain it, so people installing Avunculus don't need Node.js (see [Releases](README.md#releases)).

## Project conventions

These are the things that are easy to get wrong in this code base.

### Backend

- **New endpoint**: add one line to `backend/routes.php` (method, path, controller, action, required role: `guest`, `editor` or `admin`). Throw `HttpError(status, message)` for errors instead of calling `exit`.
- **Tree scoping**: controllers for tree data extend `TreeScopedController`. Every query must be limited to `$this->treeId`. Check IDs that come from a request with `idsInTree()` / `inTree()` before using them.
- **Privacy**: endpoints that visitors can read must hide living people. Use the helpers in `backend/lib/Privacy.php` (`personSql`, `familySql`, `mediaSql`, `maskSlim`, `maskRef`).
- **Saving child rows** (names, events, citations, associations): use `RowSync`, so existing rows keep their IDs. The frontend sends the IDs along and takes over the server's response after saving.
- **Change log**: write requests are recorded centrally, so new endpoints are logged automatically. If you add fields that should show up in the field-level history, add them to `backend/lib/ChangeDiff.php` and to `changes.fields` / `changes.parts` in the translations.

### Database changes

Existing installations are updated by numbered migrations; never ask users to run SQL by hand.

1. Add `backend/migrations/NNNN_short_name.php` with the next number. It returns a function:

   ```php
   <?php
   declare(strict_types=1);

   // 1.1.0 – what this migration does
   return function (PDO $db): void {
       $db->exec('ALTER TABLE persons ADD COLUMN IF NOT EXISTS nickname_note VARCHAR(200) NULL');
   };
   ```

   MariaDB cannot roll back `CREATE`/`ALTER`, so write migrations that can safely run again after an interruption (`IF NOT EXISTS`, `IF EXISTS`).
2. Make the same change in `database/schema.sql` and raise the number in its `INSERT INTO settings … 'schema_version'` line, so new installations start at the same state. CI checks that the numbers match (`scripts/check-schema-version.php`).

The migration runs on the first request after the code is updated, also on your development database.

### Frontend

- **Paths**: Avunculus can run in a subfolder. Build API URLs with `apiUrl()` from `src/api/http.js` and reference files relatively (`favicon.svg`, not `/favicon.svg`); never use paths starting with `/api` or `/`.
- **Large trees**: never load all people, families or sources. Lists and pickers search on the server (`…/search` endpoints, `usePagedSearch`, `PersonPicker`, `SourcePicker`, `PlacePicker`).
- **UI library**: import components from `openvue/…` (never `primevue`). Use `useAsk()` for confirmations and `useNotify()` for messages (`src/composables/feedback.js`).
- **Styling**: prefer your own classes and the design tokens at the top of `main.css`. Anything that depends on the UI library's internals goes into the section "Anpassungen an OpenVue" at the end of `main.css`, preferably through the theme's CSS variables (`--p-…`). Note that a rule like `.my-class .p-select-label` loses against theme rules of equal specificity, because the theme styles are inserted later.
- **Translations**: every visible text goes through `t('…')`, with entries in both `src/i18n/de.js` and `src/i18n/en.js`.

## How to test

There is no automated test suite yet. GitHub Actions checks that the user interface builds and that all PHP files are valid, without deprecation warnings, on every PHP version from 8.1 to 8.5.

Please test your change by hand in the browser, both logged in and as a visitor if it touches anything visitors can see. For changes to saving, importing or the database, use a throwaway database rather than one with real data, for example:

```bash
docker run --rm -d --name avunculus-test -e MARIADB_ROOT_PASSWORD=test -e MARIADB_DATABASE=avunculus \
  -p 127.0.0.1:3399:3306 mariadb:11.4
```

Read any SQL file before running it, and make a backup before touching a database with real data.

## Releasing

1. Update `VERSION` (and the `version` in `frontend/package.json`).
2. Commit, then tag and push: `git tag v1.1.0 && git push origin main v1.1.0`.
3. GitHub Actions builds the archive and creates the release. Edit the release notes on GitHub; installations show them in the update dialog.

The release script refuses to build if the tag does not match `VERSION`.

To test the updater without publishing a release, point `UPDATE_API` in `backend/.env` to a JSON file shaped like GitHub's [latest release](https://docs.github.com/en/rest/releases/releases#get-the-latest-release) response, with `avunculus-<version>.zip` and `.zip.sha256` assets. Updates are never installed into a Git working copy.

## Coding conventions

- PHP: `declare(strict_types=1);`, 4 spaces, typed properties and parameters, classes in `backend/api` and `backend/lib`.
- Vue: single-file components, preferably with `<script setup>`; 2 spaces.
- Comments explain *why*, not *what*.
- Most existing comments and commit messages are in German. Contributions in English are welcome.
- Don't commit IDE settings, `node_modules/`, `public/`, `dist/` or `backend/.env`.

## Translations

The user interface texts are in `frontend/src/i18n/` (`de.js`, `en.js`). To add a language, copy `en.js`, translate it and register it in `frontend/src/i18n/index.js` (`LOCALES` and `availableLocales`). Some error messages from the server are still German only.

## License

By contributing, you agree that your contributions are licensed under the [GNU Affero General Public License v3 or later](LICENSE), like the rest of the project.

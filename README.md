# Blog

An extension for [Modulento](https://github.com/alex01at/modulento) that adds
a blog. With no marketplace extension enabled, the installation is a plain
blog portal; next to a marketplace it is the site's news section.

- **Posts** with title, address, summary and text per language, a category,
  an author and an optional cover picture. A post is a draft or published;
  with a publication date in the future it appears by itself when that moment
  has come - the date is checked whenever posts are read, so no cron is
  involved.
- **Categories**: a flat list, each with a name and an address per language.
  A post has one or none; deleting a category leaves its posts without one.
- **Public pages**: `/blog` (newest first, nine per page),
  `/blog/category/<slug>`, `/blog/<slug>` with meta description, `hreflang`
  links and structured data, and `/blog/feed`, an Atom feed of the visitor's
  language with the 20 newest posts.
- An entry "Blog" in the main menu and the three newest posts on the home
  page.
- **Administration → Blog** for accounts with the permission
  `blog.posts.manage`: all posts with status, date and languages, the form
  for a post, cover picture, categories.

A post need not exist in every language. Where it has no text in the
visitor's language, it is shown in the default language, and failing that in
whichever language it has - the same order the core uses for offers. Nothing
disappears from a language's list because of the language it was written in.

The text of a post is HTML, reduced to the core's fixed set of formatting
elements when it is saved. Cover pictures (JPEG, PNG or WebP, 8 MB at most)
are decoded and written anew in two sizes, stored under random names in
`var/uploads/blog/` outside the web root and served at `/blog/media/...`.

Not part of this version, on purpose: **comments** and **tags**. There is
also no preview of drafts and no editor beyond the text field with HTML that
the core's pages have.

## Requirements

Modulento 0.15.0 or newer (interface version 1 with `navigation()` and
`homeSection()`). PHP's GD extension for cover pictures; without it the blog
works without pictures.

## Installing

In Modulento, open **Administration → Packages**, enter
`alex01at/modulento-ext-blog` and install. Then enable "Blog" under
**Administration → Extensions**; this creates the tables. Give the permission
"Blog: manage posts and categories" to the roles that should write. New
versions appear on the Packages page.

By hand: unpack a release into `extensions/blog/` of the installation and
enable the extension.

## Data

The extension keeps its data in tables of its own, all starting with
`x_blog_`: `x_blog_post`, `x_blog_post_translation`, `x_blog_category` and
`x_blog_category_translation`. The posts an account wrote are part of its
data export. When an account is deleted, its posts stay and are shown without
an author. An author is named by the display name of the account, never by
its e-mail address; an account without a display name is not named.
Removing the package leaves the tables and the pictures in place.

## Changing the look

The templates in `templates/` are rendered as `@blog/<file>.twig`. Do not
edit them here - an update would replace the files. A theme overrides a
template by bringing a file of the same name in
`themes/<theme>/extensions/blog/`.

`assets/blog.css` holds the few rules the pages need and takes its colours
from the variables of the site theme (`--line`, `--surface`, `--text`,
`--muted`, `--accent`), so it follows the theme and its dark scheme.

The section on the home page (`home.twig`) is included by the core without
variables. It fetches its posts with

```twig
{% set posts = constant('Modulento\\Blog\\Home::Latest').posts(3) %}
```

which a theme can use in any template of its own while the extension is
enabled. Each post has `title`, `path`, `summary`, `body`, `locale`,
`published_at`, `image` (`large`, `thumb`, `width`, `height`, `alt`),
`category` (`name`, `path`) and `author`.

## Development

The extension has checks of its own. They need a working copy of the core
for its classes - `composer install` has to have run there - and nothing
else: the database is SQLite in memory.

```
MODULENTO_CORE=/path/to/modulento php tests/run.php
```

Without the variable the core is expected next to this repository, in
`../modulento`. The checks cover addresses (built from the title, unique per
language, reserved ones), visibility (draft, scheduled, published), the
choice of language, paging, categories, a deleted author, the cleaning of
HTML, the feed, cover pictures and the names the picture route accepts. The
last line is `OK (N checks)`; otherwise the failed checks are listed and the
exit code is 1.

The core's own test run checks the language files of every extension it
finds. Link this clone into a core and run it there as well:

```
ln -s /path/to/modulento-ext-blog /path/to/modulento/extensions/blog
cd /path/to/modulento && php tests/run.php
```

The queries are written to run on MariaDB and SQLite alike: timestamps come
from the core's `Clock` as parameters, never from `NOW()`.

On every push this repository only checks the syntax of its PHP files.

## Releasing

Set `version` in `extension.json`, commit, then tag and push:

```
git tag v0.1.0 && git push origin main v0.1.0
```

The workflow checks that tag and `extension.json` agree, builds
`modulento-ext-blog-<version>.zip` with its SHA-256 and publishes both as a
GitHub Release.

## Licence

GPL-3.0-or-later, see `LICENSE`.

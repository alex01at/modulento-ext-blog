<?php

declare(strict_types=1);

// Plain-PHP checks of the blog's own classes, on SQLite in memory:
//   MODULENTO_CORE=/path/to/modulento php tests/run.php
// The core is only needed for its classes (vendor/autoload.php); without
// the variable it is expected next to this repository, in ../modulento.
// Exit code 0 means every check passed.

use Modulento\Blog\Categories;
use Modulento\Blog\Feed;
use Modulento\Blog\PostImages;
use Modulento\Blog\Posts;
use Modulento\Blog\PostView;
use Modulento\Blog\Texts;
use Modulento\Core\App;
use Modulento\Core\Event\AccountExport;

$here = dirname(__DIR__);
$core = getenv('MODULENTO_CORE') ?: dirname($here) . '/modulento';

if (!is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "No Modulento core with vendor/ found at {$core}.\n"
        . "Set MODULENTO_CORE to a working copy of the core in which \"composer install\" has run.\n");
    exit(1);
}

require $core . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$failed = [];
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        $failed[] = $label;
    }
}

// --- Database ----------------------------------------------------------------
// The blog's tables as in migrations/001_blog.sql, written for SQLite, and
// of the core only what the blog's classes touch.
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER, enabled_at TEXT)");
$pdo->exec("INSERT INTO extension VALUES ('blog', '0', 1, '2026-01-01 00:00:00')");
$pdo->exec("CREATE TABLE account (id INTEGER PRIMARY KEY, email TEXT UNIQUE, display_name TEXT, password_hash TEXT, status TEXT DEFAULT 'active',
    locale TEXT DEFAULT 'de', created_at TEXT, last_login_at TEXT)");
// The site layout asks for the pages of its menus and the visitor's colour scheme.
$pdo->exec('CREATE TABLE page (id INTEGER PRIMARY KEY, status TEXT, role TEXT, in_header INTEGER, in_footer INTEGER, position INTEGER)');
$pdo->exec('CREATE TABLE page_translation (page_id INTEGER, locale TEXT, title TEXT, slug TEXT, meta_description TEXT, body TEXT)');
$pdo->exec('CREATE TABLE account_preference (account_id INTEGER, name TEXT, value TEXT, PRIMARY KEY (account_id, name))');
$pdo->exec('CREATE TABLE x_blog_category (id INTEGER PRIMARY KEY, created_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE x_blog_category_translation (category_id INTEGER NOT NULL REFERENCES x_blog_category (id) ON DELETE CASCADE,
    locale TEXT NOT NULL, name TEXT NOT NULL, slug TEXT NOT NULL, PRIMARY KEY (category_id, locale), UNIQUE (locale, slug))');
$pdo->exec("CREATE TABLE x_blog_post (id INTEGER PRIMARY KEY, category_id INTEGER REFERENCES x_blog_category (id) ON DELETE SET NULL,
    author_id INTEGER REFERENCES account (id) ON DELETE SET NULL, status TEXT NOT NULL DEFAULT 'draft', published_at TEXT,
    image_name TEXT, image_extension TEXT, image_width INTEGER, image_height INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
$pdo->exec('CREATE TABLE x_blog_post_translation (post_id INTEGER NOT NULL REFERENCES x_blog_post (id) ON DELETE CASCADE,
    locale TEXT NOT NULL, title TEXT NOT NULL, slug TEXT NOT NULL, summary TEXT, body TEXT NOT NULL, image_alt TEXT,
    PRIMARY KEY (post_id, locale), UNIQUE (locale, slug))');

// The migration and the schema above must name the same columns.
$migration = (string) file_get_contents($here . '/migrations/001_blog.sql');
foreach (['x_blog_category', 'x_blog_category_translation', 'x_blog_post', 'x_blog_post_translation'] as $table) {
    preg_match('/CREATE TABLE ' . $table . ' \((.*?)\n\) ENGINE/s', $migration, $body);
    preg_match_all('/^\s{4}([a-z_]+) [A-Z]/m', $body[1] ?? '', $columns);
    $inTest = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    sort($columns[1]);
    sort($inTest);
    check("schema: {$table} has the same columns in the migration and in the tests", $columns[1] !== [] && $columns[1] === $inTest);
}
check('schema: slugs are unique per language by a key of the database', substr_count($migration, 'UNIQUE KEY') === 2
    && str_contains($migration, 'uq_x_blog_post_slug (locale, slug)') && str_contains($migration, 'uq_x_blog_category_slug (locale, slug)'));
check('schema: the author is taken off a post when the account goes', str_contains($migration, 'REFERENCES account (id) ON DELETE SET NULL'));

$uploads = sys_get_temp_dir() . '/modulento-blog-test-' . bin2hex(random_bytes(4));
mkdir($uploads . '/extensions', 0777, true);
// The core finds an extension in a folder named like its id, wherever
// this working copy happens to live.
symlink($here, $uploads . '/extensions/blog');

$app = new App([
    'app' => [
        'env' => 'dev', 'url' => 'https://blog.example', 'name' => 'Testblog', 'root' => $core,
        'extensions' => $uploads . '/extensions', 'uploads' => $uploads, 'secret_key' => $uploads . '/secret.key',
    ],
    'update' => ['token' => ''],
    'packages' => ['sources' => []],
], $pdo, 'de');
// Loaded the way the core does it on every request: its class loader, the
// language files, register().
$app->translator->load($core . '/core/lang', 'core');
$app->extensions->loadEnabled($app);
check('extension: the core loads it', isset($app->extensions->loaded()['blog']) && $app->extensions->loaded()['blog']->version === json_decode((string) file_get_contents($here . '/extension.json'), true)['version']);
check('extension: menu entry, home page section, permission and administration entry are announced', $app->navigation() === [['label_key' => 'blog.nav', 'path' => '/blog']]
    && array_column($app->homeSections(), 'template') === ['@blog/home.twig'] && ($app->permissions()['blog.posts.manage'] ?? null) === 'blog.permission.posts_manage'
    && $app->adminMenu() === [['label_key' => 'blog.admin.menu', 'path' => '/admin/blog', 'permission' => 'blog.posts.manage']]);
$app->locales->save('de', ['de', 'en']);
$app->translator->setLocale('de');

$posts = new Posts($pdo, $app->locales);
$categories = new Categories($pdo, $app->locales);

$pdo->exec("INSERT INTO account (id, email, display_name, created_at) VALUES (1, 'anna@example.test', 'Anna Autorin', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO account (id, email, display_name, created_at) VALUES (2, 'nameless@example.test', NULL, '2026-01-01 00:00:00')");

$text = fn (string $title, string $slug = '', string $body = '<p>Text</p>', string $summary = '', string $alt = '') => [
    'title' => $title, 'slug' => $slug, 'summary' => $summary, 'body' => $body, 'image_alt' => $alt,
];
$fields = fn (string $status = 'published', ?string $date = '2026-03-01 10:00:00', ?int $category = null, ?int $author = 1) => [
    'status' => $status, 'published_at' => $date, 'category_id' => $category, 'author_id' => $author,
];
$now = '2026-06-01 12:00:00';

// --- Slugs ---------------------------------------------------------------------
check('slug: umlauts and signs', Texts::slugify('  Größe & Maß: Über Äpfel!  ') === 'groesse-mass-ueber-aepfel');
check('slug: nothing usable gives an empty slug', Texts::slugify('!!! ???') === '');
check('slug: cut to the given length without a hyphen at the end', Texts::slugify(str_repeat('ab ', 100), 20) === 'ab-ab-ab-ab-ab-ab-ab');

$first = $posts->save(null, $fields(), ['de' => $text('Erster Beitrag'), 'en' => $text('First post')]);
check('post: saved', $first['errors'] === [] && $first['id'] !== null);
$stored = $posts->find($first['id']);
check('slug: built from the title, per language', $stored['translations']['de']['slug'] === 'erster-beitrag' && $stored['translations']['en']['slug'] === 'first-post');

$second = $posts->save(null, $fields(date: '2026-03-02 10:00:00'), ['de' => $text('Erster Beitrag'), 'en' => $text('Something else')]);
check('slug: a title used twice gets a counter in that language only',
    $posts->find($second['id'])['translations']['de']['slug'] === 'erster-beitrag-2' && $posts->find($second['id'])['translations']['en']['slug'] === 'something-else');
$third = $posts->save(null, $fields(date: '2026-03-03 10:00:00'), ['de' => $text('Erster Beitrag')]);
check('slug: and the next one counts on', $posts->find($third['id'])['translations']['de']['slug'] === 'erster-beitrag-3');

$typed = $posts->save(null, $fields(), ['de' => $text('Anderer Titel', 'erster-beitrag')]);
check('slug: a typed address that is taken is refused, not changed', $typed['id'] === null && $typed['errors'][0]['key'] === 'blog.admin.error.slug_taken'
    && $typed['errors'][0]['params'] === ['locale' => 'de', 'slug' => 'erster-beitrag']);
check('slug: the same address is free in another language', $posts->save(null, $fields('draft', null), ['en' => $text('Other', 'erster-beitrag')])['errors'] === []);
check('slug: saving a post again keeps its own address', $posts->save($first['id'], $fields(), ['de' => $text('Erster Beitrag', 'erster-beitrag'), 'en' => $text('First post', 'first-post')])['errors'] === []
    && $posts->find($first['id'])['translations']['de']['slug'] === 'erster-beitrag');
check('slug: the database itself refuses a duplicate', (function () use ($pdo, $second): bool {
    try {
        $pdo->exec("INSERT INTO x_blog_post_translation (post_id, locale, title, slug, body) VALUES ({$second['id']}, 'en', 'x', 'first-post', '')");
    } catch (PDOException) {
        return true;
    }
    return false;
})());

// --- Reserved addresses ----------------------------------------------------------
foreach (Posts::RESERVED_SLUGS as $reserved) {
    $result = $posts->save(null, $fields('draft', null), ['de' => $text('Titel', $reserved)]);
    check("reserved: \"{$reserved}\" cannot be typed as an address", $result['id'] === null && $result['errors'][0]['key'] === 'blog.admin.error.slug');
}
check('reserved: also in another spelling', $posts->save(null, $fields('draft', null), ['de' => $text('Titel', ' Feed! ')])['id'] === null);
$feedPost = $posts->save(null, $fields('draft', null), ['de' => $text('Feed'), 'en' => $text('Category')]);
check('reserved: a title that would give such an address gets a counter instead',
    $posts->find($feedPost['id'])['translations']['de']['slug'] === 'feed-2' && $posts->find($feedPost['id'])['translations']['en']['slug'] === 'category-2');
$signs = $posts->save(null, $fields('draft', null), ['de' => $text('???')]);
check('slug: a title of signs still gets an address', $posts->find($signs['id'])['translations']['de']['slug'] === 'post');
check('reserved: the fixed routes of the extension are all on the list', (function () use ($here): bool {
    preg_match_all("#'/blog/([a-z-]+)[/']#", (string) file_get_contents($here . '/src/Extension.php'), $found);
    return $found[1] !== [] && array_diff(array_unique($found[1]), Posts::RESERVED_SLUGS) === [];
})());
check('post: without a title in any language nothing is saved', $posts->save(null, $fields(), ['de' => $text(''), 'en' => $text('  ')])['errors'][0]['key'] === 'blog.admin.error.no_text');

foreach ([$feedPost['id'], $signs['id']] as $id) {
    $posts->delete($id);
}
$pdo->exec("DELETE FROM x_blog_post WHERE id NOT IN ({$first['id']}, {$second['id']}, {$third['id']})");

// --- Visibility ---------------------------------------------------------------------
$draft = $posts->save(null, $fields('draft', '2026-01-01 00:00:00'), ['de' => $text('Ein Entwurf')]);
$scheduled = $posts->save(null, $fields('published', '2026-07-01 08:00:00'), ['de' => $text('Geplanter Beitrag')]);
$titles = fn (array $list) => array_map(fn (array $row) => $row['text']['title'], $list['rows']);

$list = $posts->listVisible('de', 1, 10, null, $now);
check('visible: published posts, newest first', $titles($list) === ['Erster Beitrag', 'Erster Beitrag', 'Erster Beitrag'] && array_column($list['rows'], 'id') === [$third['id'], $second['id'], $first['id']]);
check('visible: a draft is not listed and not found', $list['total'] === 3 && $posts->findVisibleBySlug('de', 'ein-entwurf', $now) === null);
check('visible: a post dated in the future is not listed and not found', $posts->findVisibleBySlug('de', 'geplanter-beitrag', $now) === null);
check('visible: one second before its date it is still hidden', $posts->findVisibleBySlug('de', 'geplanter-beitrag', '2026-07-01 07:59:59') === null);
check('visible: at its date it appears by itself', $posts->findVisibleBySlug('de', 'geplanter-beitrag', '2026-07-01 08:00:00')['id'] === $scheduled['id']
    && $posts->listVisible('de', 1, 10, null, '2026-07-01 08:00:00')['rows'][0]['id'] === $scheduled['id']);
check('visible: isVisible agrees', !Posts::isVisible($posts->find($draft['id']), $now) && !Posts::isVisible($posts->find($scheduled['id']), $now)
    && Posts::isVisible($posts->find($scheduled['id']), '2026-07-02 00:00:00') && Posts::isVisible($posts->find($first['id']), $now));
check('visible: unknown address', $posts->findVisibleBySlug('de', 'gibt-es-nicht', $now) === null);
$immediate = $posts->save(null, $fields('published', null), ['de' => $text('Sofort')]);
check('visible: published without a date means now', $posts->find($immediate['id'])['published_at'] !== null && $posts->findVisibleBySlug('de', 'sofort') !== null);
$posts->delete($immediate['id']);
check('post: delete removes the post and its texts', $posts->find($immediate['id']) === null
    && (int) $pdo->query("SELECT COUNT(*) FROM x_blog_post_translation WHERE post_id = {$immediate['id']}")->fetchColumn() === 0);
check('admin list: drafts and scheduled posts are included', $posts->listAll(1, 50)['total'] === 5);

check('date: what the date field sends', Posts::parseDate('2026-07-01T08:00') === '2026-07-01 08:00:00' && Posts::parseDate('2026-07-01 08:00:30') === '2026-07-01 08:00:30');
check('date: empty is no date', Posts::parseDate('  ') === null);
check('date: nonsense is refused', Posts::parseDate('morgen') === false && Posts::parseDate('2026-02-31T10:00') === false && Posts::parseDate("2026-07-01T08:00' OR 1=1") === false);

// --- Language fallback ------------------------------------------------------------------
// $first has de + en, $third only de; an English-only post is added.
$englishOnly = $posts->save(null, $fields(date: '2026-03-04 10:00:00'), ['en' => $text('English only')]);
$found = $posts->findVisibleBySlug('en', 'first-post', $now);
check('language: a post with its own English text', $found['id'] === $first['id'] && $found['text_locale'] === 'en' && $found['text']['title'] === 'First post');
check('language: its German address is not its address in English', $posts->findVisibleBySlug('en', 'erster-beitrag', $now) === null);
$found = $posts->findVisibleBySlug('en', 'erster-beitrag-3', $now);
check('language: without an English text the default language is shown under its address', $found['id'] === $third['id'] && $found['text_locale'] === 'de');
$found = $posts->findVisibleBySlug('de', 'english-only', $now);
check('language: a post without a text in the default language is shown in the one it has', $found['id'] === $englishOnly['id'] && $found['text_locale'] === 'en');
$list = $posts->listVisible('en', 1, 10, null, $now);
check('language: the English list shows every post, each in the best text there is',
    $titles($list) === ['English only', 'Erster Beitrag', 'Something else', 'First post'] && array_column($list['rows'], 'text_locale') === ['en', 'de', 'en', 'en']);
check('language: the German list too', $titles($posts->listVisible('de', 1, 10, null, $now)) === ['English only', 'Erster Beitrag', 'Erster Beitrag', 'Erster Beitrag']);
check('language: pick() order', Texts::pick(['fr' => 1, 'en' => 1], 'de', 'en') === 'en' && Texts::pick(['fr' => 1], 'de', 'en') === 'fr' && Texts::pick([], 'de', 'en') === null);
// The same slug in two languages, belonging to two posts.
$clash = $posts->save(null, $fields(date: '2026-03-05 10:00:00'), ['de' => $text('Zwilling', 'english-only')]);
check('language: of two posts with one slug the one written in the visitor\'s language wins',
    $posts->findVisibleBySlug('de', 'english-only', $now)['id'] === $clash['id'] && $posts->findVisibleBySlug('en', 'english-only', $now)['id'] === $englishOnly['id']);
$posts->delete($clash['id']);

// --- Paging -------------------------------------------------------------------------------
$pdo->exec('DELETE FROM x_blog_post');
for ($number = 1; $number <= 23; $number++) {
    $posts->save(null, $fields(date: sprintf('2026-04-%02d 09:00:00', $number)), ['de' => $text("Beitrag {$number}")]);
}
$page1 = $posts->listVisible('de', 1, 9, null, $now);
$page3 = $posts->listVisible('de', 3, 9, null, $now);
check('paging: 23 posts are 3 pages of 9', $page1['total'] === 23 && $page1['pages'] === 3 && count($page1['rows']) === 9 && $titles($page1)[0] === 'Beitrag 23');
check('paging: the last page has the rest, oldest last', count($page3['rows']) === 5 && $titles($page3)[4] === 'Beitrag 1');
check('paging: no post twice, none missing', count(array_unique(array_merge($titles($page1), $titles($posts->listVisible('de', 2, 9, null, $now)), $titles($page3)))) === 23);
check('paging: a page beyond the end is the last one, below 1 the first', $posts->listVisible('de', 99, 9, null, $now)['page'] === 3 && $posts->listVisible('de', -4, 9, null, $now)['page'] === 1);
check('paging: an empty list is one empty page', $posts->listVisible('de', 1, 9, null, '2000-01-01 00:00:00') === ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1]);

// --- Categories ---------------------------------------------------------------------------
$news = $categories->save(null, ['de' => ['name' => 'Neuigkeiten', 'slug' => ''], 'en' => ['name' => 'News', 'slug' => '']]);
$tips = $categories->save(null, ['de' => ['name' => 'Tipps & Tricks', 'slug' => ''], 'en' => ['name' => '', 'slug' => '']]);
check('category: saved with a slug per language', $news['errors'] === [] && $categories->find($news['id'])['translations']['en']['slug'] === 'news'
    && $categories->find($tips['id'])['translations']['de']['slug'] === 'tipps-tricks');
check('category: a name is needed', $categories->save(null, ['de' => ['name' => ' ', 'slug' => 'x']])['errors'][0]['key'] === 'blog.admin.error.no_name');
check('category: an address used by another category is refused', $categories->save(null, ['de' => ['name' => 'Neuigkeiten', 'slug' => '']])['errors'][0]['key'] === 'blog.admin.error.slug_taken');
check('category: renaming', $categories->save($news['id'], ['de' => ['name' => 'Aktuelles', 'slug' => 'aktuelles'], 'en' => ['name' => 'News', 'slug' => 'news']])['errors'] === []
    && $categories->inLocale('de')[$news['id']]['name'] === 'Aktuelles');
check('category: found by its address in a language, by the default one where it has none',
    $categories->findBySlug('en', 'news')['id'] === $news['id'] && $categories->findBySlug('en', 'aktuelles') === null
    && $categories->findBySlug('en', 'tipps-tricks')['id'] === $tips['id'] && $categories->findBySlug('de', 'news') === null);
check('category: sorted by name', array_column($categories->inLocale('de'), 'name') === ['Aktuelles', 'Tipps & Tricks']);

$inNews = $posts->save(null, $fields(date: '2026-05-01 09:00:00', category: $news['id']), ['de' => $text('In Aktuelles')]);
$posts->save(null, $fields(date: '2026-05-02 09:00:00', category: $tips['id']), ['de' => $text('Ein Tipp')]);
check('category: its list holds only its posts', $titles($posts->listVisible('de', 1, 10, $news['id'], $now)) === ['In Aktuelles']);
check('category: one that does not exist is not stored on a post', $posts->find($posts->save(null, $fields('draft', null, 9999), ['de' => $text('Ohne')])['id'])['category_id'] === null);

$view = new PostView($app);
$card = $view->card($posts->findVisibleBySlug('en', 'in-aktuelles', $now), 'en');
check('view: path, category in the visitor\'s language, author by display name',
    $card['path'] === '/blog/in-aktuelles' && $card['category'] === ['name' => 'News', 'path' => '/blog/category/news', 'locale' => 'en']
    && $card['author'] === 'Anna Autorin' && $card['locale'] === 'de' && $card['published_iso'] === '2026-05-01T09:00:00Z' && $card['image'] === null);

$categories->delete($news['id']);
check('category: deleting leaves its posts without a category', $categories->find($news['id']) === null && $posts->find($inNews['id'])['category_id'] === null
    && $posts->find($inNews['id'])['translations']['de']['title'] === 'In Aktuelles' && $view->card($posts->findVisibleBySlug('de', 'in-aktuelles', $now), 'de')['category'] === null);
check('category: its names are gone, the other category is untouched', (int) $pdo->query('SELECT COUNT(*) FROM x_blog_category_translation')->fetchColumn() === 1
    && $titles($posts->listVisible('de', 1, 10, $tips['id'], $now)) === ['Ein Tipp']);

// --- Author ----------------------------------------------------------------------------------
$nameless = $posts->save(null, $fields(date: '2026-05-03 09:00:00', author: 2), ['de' => $text('Ohne Namen')]);
$card = (new PostView($app))->card($posts->findVisibleBySlug('de', 'ohne-namen', $now), 'de');
check('author: an account without a display name is not named - never by its e-mail address', $card['author'] === null && !str_contains(json_encode($card), 'example.test'));
check('author: saving again does not change who wrote a post', $posts->save($inNews['id'], $fields(date: '2026-05-01 09:00:00', author: 2), ['de' => $text('In Aktuelles', 'in-aktuelles')])['errors'] === []
    && (int) $posts->find($inNews['id'])['author_id'] === 1);

$export = new AccountExport(1);
$app->events->dispatch($export);
$exported = $export->sections()['blog']['posts'] ?? [];
check('export: the posts an account wrote, with their texts', count($exported) === 26 && $exported[0]['texts']['de']['title'] === 'Beitrag 1' && isset($exported[0]['status'], $exported[0]['texts']['de']['body']));
$other = new AccountExport(2);
$app->events->dispatch($other);
check('export: only its own', array_map(fn (array $post) => $post['texts']['de']['title'], $other->sections()['blog']['posts']) === ['Ohne Namen']);

$pdo->exec('DELETE FROM account WHERE id = 1');
check('author deleted: the posts stay', $posts->listVisible('de', 1, 50, null, $now)['total'] === 26 && $posts->find($inNews['id']) !== null);
check('post: a save that fails halfway changes nothing', (function () use ($posts, $fields, $text, $inNews, $pdo): bool {
    try {
        // The account is gone, so the database refuses the row.
        $posts->save(null, $fields(author: 1), ['de' => $text('Verwaist')]);
    } catch (PDOException) {
        return !$pdo->inTransaction() && $posts->find($inNews['id'])['translations']['de']['title'] === 'In Aktuelles';
    }
    return false;
})());
check('author deleted: they have no author any more', $posts->find($inNews['id'])['author_id'] === null && $posts->ofAuthor(1) === []
    && (new PostView($app))->card($posts->findVisibleBySlug('de', 'in-aktuelles', $now), 'de')['author'] === null);

// --- Home page section -----------------------------------------------------------------------
// The closure given to Registrar::homeSection() supplies the cards.
$home = fn (): array => array_values(array_filter($app->homeSections(), fn (array $section) => $section['template'] === '@blog/home.twig'))[0]['data']['posts'];
$latest = $home();
check('home: the newest three posts as cards', array_column($latest, 'title') === ['Ohne Namen', 'Ein Tipp', 'In Aktuelles'] && $latest[0]['path'] === '/blog/ohne-namen');
$app->translator->setLocale('en');
$english = $posts->save(null, $fields(date: '2026-05-04 09:00:00', author: 2), ['de' => $text('Zweisprachig'), 'en' => $text('Bilingual')]);
check('home: in the visitor\'s language', $home()[0]['title'] === 'Bilingual');
$app->translator->setLocale('de');
check('home: a draft or scheduled post never shows', (function () use ($posts, $fields, $text, $home): bool {
    $posts->save(null, $fields('draft', null, null, 2), ['de' => $text('Geheim')]);
    $posts->save(null, $fields('published', '2999-01-01 00:00:00', null, 2), ['de' => $text('Zukunft')]);
    return array_intersect(array_column($home(), 'title'), ['Geheim', 'Zukunft']) === [];
})());
$section = $app->view()->render('@blog/home.twig', ['posts' => $home()]);
check('home: the template fetches its posts itself', str_contains($section, 'Zweisprachig') && str_contains($section, 'href="/blog/zweisprachig"') && substr_count($section, 'class="blog-card"') === 3);

// --- HTML cleaning ---------------------------------------------------------------------------
$dirty = '<p onclick="steal()">Hallo <strong>Welt</strong></p><script>alert(1)</script><img src=x onerror=alert(2)>'
    . '<a href="javascript:alert(3)">eins</a><a href=" JaVa&#9;ScRiPt:alert(4)">zwei</a><a href="https://example.org/">drei</a>'
    . '<iframe src="https://evil.example"></iframe><style>p{color:red}</style><h2 style="color:red">Titel</h2>';
$xss = $posts->save(null, $fields(date: '2026-05-05 09:00:00', author: 2), ['de' => $text('<script>alert("t")</script>', '', $dirty, '<img src=x onerror=alert(5)>', '"><script>alert(6)</script>')]);
$body = $posts->find($xss['id'])['translations']['de']['body'];
check('clean: script, style and frames are gone with their content', !preg_match('/<script|alert\(1\)|<style|color:red|<iframe|evil\.example/i', $body));
check('clean: event handlers and style attributes are gone', !preg_match('/onerror|onclick|style=/i', $body) && !str_contains($body, '<img'));
check('clean: javascript: links lose their target', !preg_match('/javascript:/i', $body) && !preg_match('/java\s*script/i', $body) && str_contains($body, '<a>eins</a>'));
check('clean: harmless formatting stays', str_contains($body, '<p>Hallo <strong>Welt</strong></p>') && str_contains($body, '<h2>Titel</h2>') && str_contains($body, 'href="https://example.org/"'));
$storedXss = $posts->find($xss['id'])['translations']['de'];
check('clean: title, summary and alt text are stored as typed - they are escaped when shown', $storedXss['title'] === '<script>alert("t")</script>'
    && $storedXss['summary'] === '<img src=x onerror=alert(5)>' && $storedXss['slug'] === 'script-alert-t-script');

$app->path = '/blog';
$html = $app->view()->render('@blog/index.twig', [
    'posts' => (new PostView($app))->cards($posts->listVisible('de', 1, 3, null, $now)['rows'], 'de'),
    'category' => ['id' => 1, 'name' => '<script>alert(7)</script>', 'path' => '/blog/category/x', 'locale' => 'de'],
    'categories' => [['id' => 1, 'name' => '<script>alert(7)</script>', 'path' => '/blog/category/x', 'locale' => 'de']],
    'page' => 1, 'pages' => 2,
]);
check('templates: the list escapes title, summary and category', !preg_match('/<script>alert|<img src=x/', $html) && str_contains($html, '&lt;script&gt;alert(&quot;t&quot;)&lt;/script&gt;')
    && str_contains($html, '&lt;img src=x onerror=alert(5)&gt;') && str_contains($html, 'rel="next"'));
check('templates: the list links the feed and the stylesheet', str_contains($html, '<link rel="alternate" type="application/atom+xml"') && str_contains($html, 'href="https://blog.example/blog/feed"')
    && str_contains($html, '/assets/ext/blog/blog.css'));

// --- Feed ----------------------------------------------------------------------------------------
$entries = [];
foreach ((new PostView($app))->cards($posts->listVisible('de', 1, 20, null, $now)['rows'], 'de') as $card) {
    $entries[] = [
        'title' => $card['title'], 'url' => 'https://blog.example' . $card['path'], 'locale' => $card['locale'],
        'published' => $card['published_iso'], 'updated' => $card['updated_iso'], 'summary' => $card['summary'], 'body' => $card['body'],
        'author' => $card['author'], 'category' => $card['category']['name'] ?? null,
    ];
}
$entries[] = [
    'title' => "Zeichen & <Klammern> \"und\" ]]> \x08\x00 Steuerzeichen", 'url' => 'https://blog.example/blog/a?b=1&c=2', 'locale' => 'de',
    'published' => '2026-01-01T00:00:00Z', 'updated' => '2026-01-01T00:00:00Z', 'summary' => '</summary><script>alert(8)</script>',
    'body' => '<p>Text mit &amp; und <a href="https://example.org/?a=1&amp;b=2">Link</a></p>', 'author' => 'A & B <c>', 'category' => '"Kat" & <mehr>',
];
$atom = Feed::atom(['title' => 'Test & <Blog>', 'locale' => 'de', 'self' => 'https://blog.example/blog/feed', 'home' => 'https://blog.example/blog', 'updated' => '2026-05-05T09:00:00Z'], $entries);
libxml_use_internal_errors(true);
$xml = simplexml_load_string($atom);
check('feed: well-formed XML', $xml !== false && libxml_get_errors() === []);
check('feed: an Atom feed with its own address and the blog\'s', $xml !== false && $xml->getName() === 'feed' && $xml->getNamespaces()[''] === 'http://www.w3.org/2005/Atom'
    && (string) $xml->title === 'Test & <Blog>' && (string) $xml->link[0]['href'] === 'https://blog.example/blog/feed' && (string) $xml->link[0]['rel'] === 'self');
$last = $xml !== false ? $xml->entry[count($xml->entry) - 1] : null;
check('feed: values come out as they went in, minus characters XML cannot carry', $last !== null && (string) $last->title === 'Zeichen & <Klammern> "und" ]]>  Steuerzeichen'
    && (string) $last->summary === '</summary><script>alert(8)</script>' && (string) $last->author->name === 'A & B <c>' && (string) $last->category['term'] === '"Kat" & <mehr>'
    && (string) $last->link['href'] === 'https://blog.example/blog/a?b=1&c=2');
check('feed: the text travels as escaped HTML', $last !== null && (string) $last->content === '<p>Text mit &amp; und <a href="https://example.org/?a=1&amp;b=2">Link</a></p>' && (string) $last->content['type'] === 'html');
check('feed: nothing typed appears as markup of the feed', !str_contains($atom, '<script') && !str_contains($atom, '<Klammern>') && !str_contains($atom, '<img') && substr_count($atom, '<summary>') === substr_count($atom, '</summary>'));
check('feed: absolute addresses and dates as Atom wants them', $xml !== false && str_starts_with((string) $xml->entry[0]->link['href'], 'https://blog.example/blog/')
    && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $xml->entry[0]->published) === 1 && (string) $xml->entry[0]->id !== '');
check('feed: an empty blog is still a valid feed', simplexml_load_string(Feed::atom(['title' => 'T', 'locale' => 'de', 'self' => 'https://x/f', 'home' => 'https://x/', 'updated' => '1970-01-01T00:00:00Z'], [])) !== false);
check('feed: text that is not valid UTF-8 cannot break it', simplexml_load_string(Feed::atom(['title' => "kaputt \xC3\x28", 'locale' => 'de', 'self' => 'https://x/f', 'home' => 'https://x/', 'updated' => '1970-01-01T00:00:00Z'], [])) !== false);

// --- Cover pictures ------------------------------------------------------------------------------
$images = new PostImages($pdo, $uploads . '/blog');
$tmp = $uploads . '/tmp';
mkdir($tmp);
$makeImage = function (string $type, int $width = 2000, int $height = 1000) use ($tmp): string {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 40));
    $file = $tmp . '/' . bin2hex(random_bytes(4)) . '.' . $type;
    match ($type) {
        'png' => imagepng($image, $file),
        'jpg' => imagejpeg($image, $file),
        'webp' => imagewebp($image, $file),
        'gif' => imagegif($image, $file),
    };
    imagedestroy($image);

    return $file;
};
$upload = fn (string $file) => ['tmp_name' => $file, 'error' => UPLOAD_ERR_OK];
$stored = fn () => array_map('basename', glob($uploads . '/blog/*') ?: []);

if (!PostImages::available()) {
    check('image: without GD an upload is refused with a reason', $images->set($xss['id'], $upload(__FILE__)) === 'blog.image.error.unavailable');
} else {
    check('image: a PNG is accepted', $images->set($xss['id'], $upload($makeImage('png'))) === null);
    $row = $posts->find($xss['id']);
    $extension = $row['image_extension'];
    check('image: stored under a random name in two sizes, outside any web root', preg_match('/^[0-9a-f]{32}$/', (string) $row['image_name']) === 1
        && $stored() === ["{$row['image_name']}.{$extension}", "{$row['image_name']}_thumb.{$extension}"]);
    $large = getimagesize($uploads . '/blog/' . $row['image_name'] . '.' . $extension);
    $thumb = getimagesize($uploads . '/blog/' . $row['image_name'] . '_thumb.' . $extension);
    check('image: written anew and scaled down', $large[0] === 1600 && $large[1] === 800 && $thumb[0] === 640 && (int) $row['image_width'] === 1600 && (int) $row['image_height'] === 800
        && in_array($large[2], [IMAGETYPE_WEBP, IMAGETYPE_JPEG], true));
    $urls = PostImages::urls($row);
    check('image: addresses for templates', $urls['large'] === "/media/blog/{$row['image_name']}.{$extension}" && $urls['thumb'] === "/media/blog/{$row['image_name']}_thumb.{$extension}");

    check('image: JPEG and WebP are accepted too, and a new picture replaces the old files', $images->set($xss['id'], $upload($makeImage('jpg', 300, 200))) === null
        && $images->set($xss['id'], $upload($makeImage('webp', 300, 200))) === null && count($stored()) === 2 && !in_array("{$row['image_name']}.{$extension}", $stored(), true));
    $row = $posts->find($xss['id']);
    check('image: a small picture is not blown up', (int) $row['image_width'] === 300 && (int) $row['image_height'] === 200);

    // A PHP file that calls itself a picture.
    $fake = $tmp . '/shell.png';
    file_put_contents($fake, "<?php system(\$_GET['c']); ?>");
    check('image: a PHP file with a picture\'s name is refused', $images->set($xss['id'], $upload($fake)) === 'blog.image.error.type');
    $gifShell = $tmp . '/shell.jpg';
    file_put_contents($gifShell, "GIF89a<?php system(\$_GET['c']); ?>");
    check('image: also one that starts like a picture', $images->set($xss['id'], $upload($gifShell)) === 'blog.image.error.type');
    check('image: a GIF is not among the accepted kinds', $images->set($xss['id'], $upload($makeImage('gif', 50, 50))) === 'blog.image.error.type');
    check('image: a refused upload leaves the current picture alone', $posts->find($xss['id'])['image_name'] === $row['image_name'] && count($stored()) === 2);
    // A real picture with code hidden behind it: accepted, but what is
    // stored is the newly written picture, not the upload.
    $polyglot = $makeImage('png', 60, 40);
    file_put_contents($polyglot, "<?php echo 'hidden'; ?>", FILE_APPEND);
    check('image: code hidden behind a real picture does not survive', $images->set($xss['id'], $upload($polyglot)) === null
        && array_filter($stored(), fn (string $name) => str_contains((string) file_get_contents($uploads . '/blog/' . $name), '<?php')) === []);
    check('image: an upload the server reports as failed or too large', $images->set($xss['id'], ['tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE]) === 'blog.image.error.too_large'
        && $images->set($xss['id'], ['tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL]) === 'blog.image.error.upload' && $images->set($xss['id'], []) === 'blog.image.error.upload');
    check('image: none for a post that does not exist', $images->set(99999, $upload($makeImage('png', 50, 50))) === 'blog.image.error.upload' && count($stored()) === 2);

    // --- Serving ---------------------------------------------------------------------------------
    $row = $posts->find($xss['id']);
    $name = $row['image_name'];
    $extension = $row['image_extension'];
    check('serve: the stored names are found', $images->path("{$name}.{$extension}") === "{$uploads}/blog/{$name}.{$extension}" && $images->path("{$name}_thumb.{$extension}") !== null);
    file_put_contents($uploads . '/secret.txt', 'secret');
    file_put_contents($uploads . '/blog/notes.txt', 'notes');
    file_put_contents($uploads . '/' . str_repeat('a', 32) . '.webp', 'outside');
    foreach ([
        'climbing out' => '../secret.txt',
        'climbing out with a valid name' => '../' . str_repeat('a', 32) . '.webp',
        'another file in the folder' => 'notes.txt',
        'a valid name that does not exist' => str_repeat('b', 32) . '.webp',
        'upper case' => strtoupper($name) . '.' . $extension,
        'another extension' => "{$name}.php",
        'a double extension' => "{$name}.{$extension}.php",
        'a trailing line break' => "{$name}.{$extension}\n",
        'a null byte' => "{$name}.{$extension}\0.txt",
        'a prefix' => "x{$name}.{$extension}",
        'a folder' => "sub/{$name}.{$extension}",
        'an absolute path' => "{$uploads}/blog/{$name}.{$extension}",
        'an empty name' => '',
    ] as $label => $request) {
        check("serve: {$label} is not served", $images->path($request) === null);
    }

    $images->remove($xss['id']);
    check('image: removing deletes both files and the reference', $posts->find($xss['id'])['image_name'] === null && $stored() === ['notes.txt'] && PostImages::urls($posts->find($xss['id'])) === null);
    check('image: the old address is gone with it', $images->path("{$name}.{$extension}") === null);
}

// --- Structured data and the post page -------------------------------------------------------------
$found = $posts->findVisibleBySlug('de', 'script-alert-t-script', $now);
$card = (new PostView($app))->card($found, 'de');
$jsonLd = (function (array $card) use ($app): string {
    $method = new ReflectionMethod(Modulento\Blog\BlogController::class, 'jsonLd');

    return $method->invoke(new Modulento\Blog\BlogController($app), $card);
})(['title' => '</script><script>alert(9)</script>', 'summary' => "\"quote\" & 'apostrophe' <b>"] + $card);
check('json-ld: nothing in it can end the script block', !preg_match('/[<>&\'"]\s*\/?script/i', $jsonLd) && !str_contains($jsonLd, '<') && !str_contains($jsonLd, '>') && !str_contains($jsonLd, '&') && !str_contains($jsonLd, "'"));
check('json-ld: slashes stay escaped and the values survive', str_contains($jsonLd, 'https:\/\/blog.example\/blog\/script-alert-t-script')
    && json_decode($jsonLd, true)['headline'] === '</script><script>alert(9)</script>' && json_decode($jsonLd, true)['description'] === "\"quote\" & 'apostrophe' <b>");

$app->path = '/blog/script-alert-t-script';
$page = $app->view()->render('@blog/show.twig', ['post' => ['image' => ['large' => '/media/blog/x.webp', 'thumb' => '', 'width' => 10, 'height' => 5, 'alt' => '"><script>alert(6)</script>']] + $card, 'json_ld' => $jsonLd]);
check('templates: the post page escapes title, summary and alt text', !preg_match('/<script>alert|<img src=x/', $page) && str_contains($page, 'alt="&quot;&gt;&lt;script&gt;alert(6)&lt;/script&gt;"')
    && str_contains($page, '<meta name="description" content="&lt;img src=x onerror=alert(5)&gt;">'));
check('templates: the cleaned text is printed as HTML', str_contains($page, '<p>Hallo <strong>Welt</strong></p>') && substr_count($page, '<script') === substr_count($page, '<script src=') + 1);

// --- Language files ---------------------------------------------------------------------------------
$de = require $here . '/lang/de.php';
$en = require $here . '/lang/en.php';
check('lang: de and en have the same keys', array_keys($de) === array_keys($en));
check('lang: every key starts with "blog."', array_filter(array_keys($de), fn (string $key) => !str_starts_with($key, 'blog.')) === []);
$used = [];
foreach ([...glob($here . '/templates/{,*/}*.twig', GLOB_BRACE), ...glob($here . '/src/*.php')] as $file) {
    preg_match_all("/'(blog\.[a-z0-9_.]+[a-z0-9])'/", (string) file_get_contents($file), $found);
    $used = array_merge($used, $found[1]);
}
$unknown = array_diff(array_unique($used), array_keys($de), ['blog.posts.manage', 'blog.css']);
check('lang: every key used in templates and code exists: ' . implode(', ', $unknown), $unknown === []);

// --- Clean up -----------------------------------------------------------------------------------------
$remove = function (string $dir) use (&$remove): void {
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        is_dir("{$dir}/{$entry}") && !is_link("{$dir}/{$entry}") ? $remove("{$dir}/{$entry}") : unlink("{$dir}/{$entry}");
    }
    rmdir($dir);
};
$remove($uploads);

if ($failed !== []) {
    echo "FAILED:\n";
    foreach ($failed as $label) {
        echo "  - {$label}\n";
    }
    echo count($failed) . " of {$checks} checks failed\n";
    exit(1);
}

echo "OK ({$checks} checks)\n";

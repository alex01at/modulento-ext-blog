<?php

declare(strict_types=1);

namespace Modulento\Blog;

use Modulento\Core\App;
use Modulento\Core\Controller\Controller;

/** What visitors see: the list, a category, a post, the feed and the pictures. */
final class BlogController extends Controller
{
    private const PER_PAGE = 9;
    private const FEED_ENTRIES = 20;
    private const TYPES = ['webp' => 'image/webp', 'jpg' => 'image/jpeg'];

    private Posts $posts;
    private Categories $categories;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->posts = new Posts($app->db, $app->locales);
        $this->categories = new Categories($app->db, $app->locales);
    }

    public function index(array $params): void
    {
        $this->renderList(null);
    }

    public function category(array $params): void
    {
        $locale = $this->app->translator->locale();
        $category = $this->categories->findBySlug($locale, $params['slug']);
        if ($category === null) {
            $this->notFound();
            return;
        }

        // The category's address in the other languages, for the language
        // menu and hreflang.
        foreach ($this->app->locales->enabled() as $other) {
            $shown = Texts::pick($category['translations'], $other, $this->app->locales->default());
            $this->app->alternatePaths[$other] = '/blog/category/' . $category['translations'][$shown]['slug'];
        }

        $this->renderList($category['id']);
    }

    public function show(array $params): void
    {
        $locale = $this->app->translator->locale();
        $post = $this->posts->findVisibleBySlug($locale, $params['slug']);
        if ($post === null) {
            $this->notFound();
            return;
        }

        foreach ($this->app->locales->enabled() as $other) {
            $shown = Texts::pick($post['translations'], $other, $this->app->locales->default());
            $this->app->alternatePaths[$other] = '/blog/' . $post['translations'][$shown]['slug'];
        }

        $card = (new PostView($this->app))->card($post, $locale);

        $this->render('@blog/show.twig', [
            'post' => $card,
            'json_ld' => $this->jsonLd($card),
        ]);
    }

    public function feed(array $params): void
    {
        $locale = $this->app->translator->locale();
        $view = new PostView($this->app);
        $list = $this->posts->listVisible($locale, 1, self::FEED_ENTRIES);

        $entries = [];
        $updated = '';
        foreach ($view->cards($list['rows'], $locale) as $card) {
            $entries[] = [
                'title' => $card['title'],
                'url' => $this->app->url($card['path'], null, true),
                'locale' => $card['locale'],
                'published' => $card['published_iso'],
                'updated' => max($card['updated_iso'], $card['published_iso']),
                'summary' => $card['summary'],
                'body' => $card['body'],
                'author' => $card['author'],
                'category' => $card['category']['name'] ?? null,
            ];
            $updated = max($updated, $card['updated_iso'], $card['published_iso']);
        }

        header('Content-Type: application/atom+xml; charset=utf-8');
        echo Feed::atom([
            'title' => $this->trans('blog.feed.title', ['site' => $this->app->siteName()]),
            'locale' => $locale,
            'self' => $this->app->url('/blog/feed', null, true),
            'home' => $this->app->url('/blog', null, true),
            // An empty blog has not changed since the beginning of time.
            'updated' => $updated !== '' ? $updated : '1970-01-01T00:00:00Z',
        ], $entries);
    }

    /**
     * Serves a cover picture from the upload folder, which is outside the
     * web root. Only names PostImages itself created can be requested.
     */
    public function media(array $params): void
    {
        $file = (new PostImages($this->app->db, Extension::uploadDir($this->app)))->path($params['file']);
        if ($file === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        header('Content-Type: ' . self::TYPES[pathinfo($file, PATHINFO_EXTENSION)]);
        // The name is random and never reused, so the file never changes.
        header('Cache-Control: public, max-age=31536000, immutable');
        header("Content-Security-Policy: default-src 'none'");
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }

    private function renderList(?int $categoryId): void
    {
        $locale = $this->app->translator->locale();
        $list = $this->posts->listVisible($locale, max(1, (int) ($_GET['page'] ?? 1)), self::PER_PAGE, $categoryId);

        $categories = [];
        $current = null;
        foreach ($this->categories->inLocale($locale) as $id => $category) {
            $entry = ['id' => $id, 'name' => $category['name'], 'path' => '/blog/category/' . $category['slug'], 'locale' => $category['locale']];
            $categories[] = $entry;
            if ($id === $categoryId) {
                $current = $entry;
            }
        }

        $this->render('@blog/index.twig', [
            'posts' => (new PostView($this->app))->cards($list['rows'], $locale),
            'category' => $current,
            'categories' => $categories,
            'page' => $list['page'],
            'pages' => $list['pages'],
        ]);
    }

    /**
     * The post as structured data for search engines. It is printed into a
     * <script type="application/ld+json"> block, so nothing in it may be
     * able to end that block: "<", ">", "&" and quotes leave json_encode
     * as \uXXXX, and slashes stay escaped.
     */
    private function jsonLd(array $card): string
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $card['title'],
            'inLanguage' => $card['locale'],
            'datePublished' => $card['published_iso'],
            'dateModified' => max($card['updated_iso'], $card['published_iso']),
            'mainEntityOfPage' => $this->app->url($card['path'], null, true),
        ];
        if ($card['summary'] !== null) {
            $data['description'] = $card['summary'];
        }
        if ($card['image'] !== null) {
            $data['image'] = $this->app->config['app']['url'] . $card['image']['large'];
        }
        if ($card['author'] !== null) {
            $data['author'] = ['@type' => 'Person', 'name' => $card['author']];
        }

        return (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
    }
}

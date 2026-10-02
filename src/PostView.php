<?php

declare(strict_types=1);

namespace Modulento\Blog;

use Modulento\Core\App;

/**
 * Turns posts into what the templates get: plain values, paths without
 * language prefix (templates pass them through url()), the category's
 * name in the visitor's language and the author's display name.
 */
final class PostView
{
    /** @var array<string, array<int, array>> categories by locale */
    private array $categories = [];
    /** @var array<int, ?string> display names by account id */
    private array $authors = [];

    public function __construct(private App $app)
    {
    }

    /**
     * @param array<int, array> $rows posts as Posts::listVisible() returns them
     * @return array<int, array>
     */
    public function cards(array $rows, string $locale): array
    {
        return array_map(fn (array $row) => $this->card($row, $locale), $rows);
    }

    /** @param array $post a post with "text" and "text_locale" */
    public function card(array $post, string $locale): array
    {
        $this->categories[$locale] ??= (new Categories($this->app->db, $this->app->locales))->inLocale($locale);
        $category = $post['category_id'] !== null ? ($this->categories[$locale][(int) $post['category_id']] ?? null) : null;
        $image = PostImages::urls($post);

        return [
            'id' => (int) $post['id'],
            'title' => $post['text']['title'],
            'path' => '/blog/' . $post['text']['slug'],
            'summary' => $post['text']['summary'],
            'body' => $post['text']['body'],
            // Differs from the visitor's language where the post has no
            // text in it; templates mark the text with it (lang="").
            'locale' => $post['text_locale'],
            'published_at' => $post['published_at'],
            'published_iso' => self::iso((string) $post['published_at']),
            'updated_iso' => self::iso((string) $post['updated_at']),
            'image' => $image !== null ? $image + ['alt' => (string) ($post['text']['image_alt'] ?? '')] : null,
            'category' => $category !== null
                ? ['name' => $category['name'], 'path' => '/blog/category/' . $category['slug'], 'locale' => $category['locale']]
                : null,
            'author' => $this->author($post['author_id'] !== null ? (int) $post['author_id'] : null),
        ];
    }

    /** The name an account gave itself, never its e-mail address; null without one. */
    public function author(?int $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }
        if (!array_key_exists($accountId, $this->authors)) {
            $name = trim((string) ($this->app->accounts->findById($accountId)['display_name'] ?? ''));
            $this->authors[$accountId] = $name !== '' ? $name : null;
        }

        return $this->authors[$accountId];
    }

    /** A stored UTC time as browsers and feed readers expect it. */
    public static function iso(string $utc): string
    {
        return $utc !== '' ? str_replace(' ', 'T', $utc) . 'Z' : '';
    }
}

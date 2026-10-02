<?php

declare(strict_types=1);

namespace Modulento\Blog;

use Modulento\Core\Support\Clock;
use Modulento\Core\Support\HtmlSanitizer;
use Modulento\Core\Support\Locales;
use PDO;

/**
 * The posts of the blog. A post has a title, an address, a summary and a
 * text per language and need not exist in every one of them.
 *
 * Visible to visitors is a post whose status is "published" and whose
 * publication date has passed. The date is compared in every query, so a
 * post dated in the future appears by itself - no scheduled task has to
 * run for it.
 */
final class Posts
{
    public const STATUSES = ['draft', 'published'];

    // Second path segments below /blog that are routes of their own; a
    // post with such an address would never be reached.
    public const RESERVED_SLUGS = ['category', 'feed'];

    public function __construct(private PDO $db, private Locales $locales)
    {
    }

    /** A post with all its translations, whatever its status. */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_blog_post WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->withTranslations([$row])[0] : null;
    }

    /**
     * Every post for the administration, newest first.
     *
     * @return array{rows: array<int, array>, total: int, page: int, pages: int}
     */
    public function listAll(int $page, int $perPage): array
    {
        $total = (int) $this->db->query('SELECT COUNT(*) FROM x_blog_post')->fetchColumn();
        [$page, $pages, $offset] = self::window($total, $page, $perPage);

        // A draft has no date yet; it is sorted by when it was written.
        $stmt = $this->db->prepare(
            'SELECT * FROM x_blog_post ORDER BY COALESCE(published_at, created_at) DESC, id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute();

        return ['rows' => $this->withTranslations($stmt->fetchAll()), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * The posts a visitor can read, newest first. Each row carries "text"
     * (the translation shown in $locale) and "text_locale".
     *
     * @param string|null $now the moment to judge visibility at; the clock's unless a test sets it
     * @return array{rows: array<int, array>, total: int, page: int, pages: int}
     */
    public function listVisible(string $locale, int $page, int $perPage, ?int $categoryId = null, ?string $now = null): array
    {
        $where = "status = 'published' AND published_at <= :now";
        $params = ['now' => $now ?? Clock::now()];
        if ($categoryId !== null) {
            $where .= ' AND category_id = :category';
            $params['category'] = $categoryId;
        }

        $count = $this->db->prepare('SELECT COUNT(*) FROM x_blog_post WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        [$page, $pages, $offset] = self::window($total, $page, $perPage);

        $stmt = $this->db->prepare(
            'SELECT * FROM x_blog_post WHERE ' . $where . '
             ORDER BY published_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($this->withTranslations($stmt->fetchAll()) as $post) {
            $shown = $this->shown($post, $locale);
            if ($shown !== null) {
                $rows[] = $shown;
            }
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * The visible post that has this address for a visitor of a language:
     * the slug of its text in that language, or of the text shown instead
     * where the post has none in that language.
     */
    public function findVisibleBySlug(string $locale, string $slug, ?string $now = null): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT t.post_id, t.locale FROM x_blog_post_translation t JOIN x_blog_post p ON p.id = t.post_id
             WHERE t.slug = :slug AND p.status = 'published' AND p.published_at <= :now"
        );
        $stmt->execute(['slug' => $slug, 'now' => $now ?? Clock::now()]);
        $matches = $stmt->fetchAll();

        // The same slug may belong to different posts in different
        // languages; the one written in the visitor's language comes first.
        usort($matches, fn (array $a, array $b) => ($b['locale'] === $locale) <=> ($a['locale'] === $locale));

        foreach ($matches as $match) {
            $post = $this->find((int) $match['post_id']);
            $shown = $post !== null ? $this->shown($post, $locale) : null;
            // A slug of another language is this post's address only where
            // that text is the one shown; otherwise the post lives at its
            // own slug here.
            if ($shown !== null && $shown['text_locale'] === $match['locale']) {
                return $shown;
            }
        }

        return null;
    }

    /** @return array<int, array> the posts an account wrote, with all their texts - for its data export */
    public function ofAuthor(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_blog_post WHERE author_id = :id ORDER BY id');
        $stmt->execute(['id' => $accountId]);

        return $this->withTranslations($stmt->fetchAll());
    }

    /**
     * @param array{status: string, published_at: ?string, category_id: ?int, author_id: ?int} $fields
     *        published_at in UTC as "Y-m-d H:i:s"; author_id only counts for a new post
     * @param array<string, array{title: string, slug: string, summary: string, body: string, image_alt: string}> $translations
     *        by locale; a language whose title is empty is removed from the post
     * @return array{id: ?int, errors: array<int, array{key: string, params: array}>}
     */
    public function save(?int $id, array $fields, array $translations): array
    {
        $errors = [];
        $rows = [];

        foreach ($translations as $locale => $text) {
            $title = trim($text['title']);
            if ($title === '') {
                continue;
            }

            if (trim($text['slug']) !== '') {
                // An address someone typed is taken as it is or refused.
                $slug = Texts::slugify($text['slug']);
                if ($slug === '' || in_array($slug, self::RESERVED_SLUGS, true)) {
                    $errors[] = ['key' => 'blog.admin.error.slug', 'params' => ['locale' => $locale, 'slug' => $slug]];
                    continue;
                }
                if ($this->slugTaken($locale, $slug, $id)) {
                    $errors[] = ['key' => 'blog.admin.error.slug_taken', 'params' => ['locale' => $locale, 'slug' => $slug]];
                    continue;
                }
            } else {
                $slug = $this->freeSlug($locale, $title, $id);
            }

            $summary = trim($text['summary']);
            $alt = trim($text['image_alt']);
            $rows[$locale] = [
                'title' => mb_substr($title, 0, 200),
                'slug' => $slug,
                'summary' => $summary !== '' ? mb_substr($summary, 0, 500) : null,
                'body' => HtmlSanitizer::clean($text['body']),
                'image_alt' => $alt !== '' ? mb_substr($alt, 0, 200) : null,
            ];
        }

        if ($rows === [] && $errors === []) {
            $errors[] = ['key' => 'blog.admin.error.no_text', 'params' => []];
        }
        if ($errors !== []) {
            return ['id' => $id, 'errors' => $errors];
        }

        $now = Clock::now();
        $status = $fields['status'] === 'published' ? 'published' : 'draft';
        $values = [
            'category' => $fields['category_id'] !== null && $this->categoryExists($fields['category_id']) ? $fields['category_id'] : null,
            'status' => $status,
            // Published without a date means published now.
            'published' => $fields['published_at'] ?? ($status === 'published' ? $now : null),
            'now' => $now,
        ];

        // All or nothing: a post never loses its texts to a save that
        // failed halfway, e.g. on an address another save took meanwhile.
        $this->db->beginTransaction();
        try {
            if ($id === null) {
                $stmt = $this->db->prepare(
                    'INSERT INTO x_blog_post (category_id, author_id, status, published_at, created_at, updated_at)
                     VALUES (:category, :author, :status, :published, :now, :now2)'
                );
                $stmt->execute($values + ['author' => $fields['author_id'], 'now2' => $now]);
                $id = (int) $this->db->lastInsertId();
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE x_blog_post SET category_id = :category, status = :status, published_at = :published, updated_at = :now
                     WHERE id = :id'
                );
                $stmt->execute($values + ['id' => $id]);
            }

            $delete = $this->db->prepare('DELETE FROM x_blog_post_translation WHERE post_id = :id');
            $delete->execute(['id' => $id]);

            $insert = $this->db->prepare(
                'INSERT INTO x_blog_post_translation (post_id, locale, title, slug, summary, body, image_alt)
                 VALUES (:post_id, :locale, :title, :slug, :summary, :body, :image_alt)'
            );
            foreach ($rows as $locale => $row) {
                $insert->execute(['post_id' => $id, 'locale' => $locale] + $row);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['id' => $id, 'errors' => []];
    }

    /** Removes the post and its texts. Its picture is the caller's to remove first, see PostImages. */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM x_blog_post_translation WHERE post_id = :id');
        $stmt->execute(['id' => $id]);
        $stmt = $this->db->prepare('DELETE FROM x_blog_post WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * A date typed into the form, read as UTC.
     *
     * @return string|null|false "Y-m-d H:i:s", null for an empty field, false for something that is no date
     */
    public static function parseDate(string $input): string|null|false
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        // What <input type="datetime-local"> sends, with or without seconds,
        // and the same with a space for a browser that shows a text field.
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $input, new \DateTimeZone('UTC'));
            if ($date !== false && $date->format($format) === $input && (int) $date->format('Y') >= 1970 && (int) $date->format('Y') <= 9999) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        return false;
    }

    /** Whether a post with these values can be read by visitors at $now. */
    public static function isVisible(array $post, ?string $now = null): bool
    {
        return $post['status'] === 'published' && $post['published_at'] !== null && $post['published_at'] <= ($now ?? Clock::now());
    }

    /** The post with the text a visitor of $locale gets, or null for a post without any text. */
    private function shown(array $post, string $locale): ?array
    {
        $shown = Texts::pick($post['translations'], $locale, $this->locales->default());
        if ($shown === null) {
            return null;
        }

        return $post + ['text' => $post['translations'][$shown], 'text_locale' => $shown];
    }

    /**
     * @param array<int, array> $posts rows of x_blog_post
     * @return array<int, array> the same rows, each with "translations" by locale
     */
    private function withTranslations(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $byId = [];
        foreach ($posts as $index => $post) {
            $posts[$index]['id'] = (int) $post['id'];
            $posts[$index]['translations'] = [];
            $byId[(int) $post['id']] = $index;
        }

        $stmt = $this->db->prepare(
            'SELECT * FROM x_blog_post_translation WHERE post_id IN (' . implode(', ', array_fill(0, count($byId), '?')) . ')
             ORDER BY locale'
        );
        $stmt->execute(array_keys($byId));
        foreach ($stmt->fetchAll() as $row) {
            $posts[$byId[(int) $row['post_id']]]['translations'][$row['locale']] = $row;
        }

        return $posts;
    }

    /** An address made from the title that nothing else uses: "title", "title-2", "title-3" ... */
    private function freeSlug(string $locale, string $title, ?int $exceptPostId): string
    {
        // Room for the counter within the column's 200 characters.
        $base = Texts::slugify($title, 190);
        if ($base === '') {
            // A title of nothing but signs.
            $base = 'post';
        }

        $slug = $base;
        for ($number = 2; in_array($slug, self::RESERVED_SLUGS, true) || $this->slugTaken($locale, $slug, $exceptPostId); $number++) {
            $slug = $base . '-' . $number;
        }

        return $slug;
    }

    private function slugTaken(string $locale, string $slug, ?int $exceptPostId): bool
    {
        $stmt = $this->db->prepare('SELECT post_id FROM x_blog_post_translation WHERE locale = :locale AND slug = :slug');
        $stmt->execute(['locale' => $locale, 'slug' => $slug]);
        $postId = $stmt->fetchColumn();

        return $postId !== false && (int) $postId !== $exceptPostId;
    }

    private function categoryExists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM x_blog_category WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array{0: int, 1: int, 2: int} the page within range, the number of pages, the offset */
    private static function window(int $total, int $page, int $perPage): array
    {
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));

        return [$page, $pages, ($page - 1) * $perPage];
    }
}

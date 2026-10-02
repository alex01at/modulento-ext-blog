<?php

declare(strict_types=1);

namespace Modulento\Blog;

use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Locales;
use PDO;

/**
 * The categories of the blog: a flat list, each with a name and an
 * address per language. A post has one of them or none.
 */
final class Categories
{
    public function __construct(private PDO $db, private Locales $locales)
    {
    }

    /** @return array<int, array{id: int, translations: array<string, array>}> every category with all its names */
    public function all(): array
    {
        $categories = [];
        foreach ($this->db->query('SELECT id FROM x_blog_category ORDER BY id')->fetchAll() as $row) {
            $categories[(int) $row['id']] = ['id' => (int) $row['id'], 'translations' => []];
        }

        foreach ($this->db->query('SELECT * FROM x_blog_category_translation ORDER BY locale')->fetchAll() as $row) {
            if (isset($categories[(int) $row['category_id']])) {
                $categories[(int) $row['category_id']]['translations'][$row['locale']] = $row;
            }
        }

        return $categories;
    }

    public function find(int $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * Name and address of every category as a visitor of a language sees
     * them, sorted by name.
     *
     * @return array<int, array{id: int, name: string, slug: string, locale: string}> by id
     */
    public function inLocale(string $locale): array
    {
        $list = [];
        foreach ($this->all() as $id => $category) {
            $shown = Texts::pick($category['translations'], $locale, $this->locales->default());
            if ($shown === null) {
                continue;
            }
            $text = $category['translations'][$shown];
            $list[$id] = ['id' => $id, 'name' => $text['name'], 'slug' => $text['slug'], 'locale' => $shown];
        }
        uasort($list, fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']));

        return $list;
    }

    /** The category that has this address for a visitor of a language. */
    public function findBySlug(string $locale, string $slug): ?array
    {
        foreach ($this->inLocale($locale) as $id => $shown) {
            if ($shown['slug'] === $slug) {
                return $this->find($id);
            }
        }

        return null;
    }

    /**
     * @param array<string, array{name: string, slug: string}> $translations by locale;
     *        a language whose name is empty is removed from the category
     * @return array{id: ?int, errors: array<int, array{key: string, params: array}>}
     */
    public function save(?int $id, array $translations): array
    {
        $errors = [];
        $rows = [];

        foreach ($translations as $locale => $text) {
            $name = trim($text['name']);
            if ($name === '') {
                continue;
            }

            $slug = Texts::slugify(trim($text['slug']) !== '' ? $text['slug'] : $name, 160);
            if ($slug === '') {
                $errors[] = ['key' => 'blog.admin.error.slug', 'params' => ['locale' => $locale, 'slug' => $slug]];
                continue;
            }
            if ($this->slugTaken($locale, $slug, $id)) {
                $errors[] = ['key' => 'blog.admin.error.slug_taken', 'params' => ['locale' => $locale, 'slug' => $slug]];
                continue;
            }

            $rows[$locale] = ['name' => mb_substr($name, 0, 150), 'slug' => $slug];
        }

        if ($rows === [] && $errors === []) {
            $errors[] = ['key' => 'blog.admin.error.no_name', 'params' => []];
        }
        if ($errors !== []) {
            return ['id' => $id, 'errors' => $errors];
        }

        $this->db->beginTransaction();
        try {
            if ($id === null) {
                $stmt = $this->db->prepare('INSERT INTO x_blog_category (created_at) VALUES (:now)');
                $stmt->execute(['now' => Clock::now()]);
                $id = (int) $this->db->lastInsertId();
            }

            $delete = $this->db->prepare('DELETE FROM x_blog_category_translation WHERE category_id = :id');
            $delete->execute(['id' => $id]);

            $insert = $this->db->prepare(
                'INSERT INTO x_blog_category_translation (category_id, locale, name, slug) VALUES (:id, :locale, :name, :slug)'
            );
            foreach ($rows as $locale => $row) {
                $insert->execute(['id' => $id, 'locale' => $locale] + $row);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['id' => $id, 'errors' => []];
    }

    /** Posts of the category stay and have none afterwards. */
    public function delete(int $id): void
    {
        // Not left to the foreign key alone: the result must not depend on
        // whether the database enforces it.
        $stmt = $this->db->prepare('UPDATE x_blog_post SET category_id = NULL WHERE category_id = :id');
        $stmt->execute(['id' => $id]);
        $stmt = $this->db->prepare('DELETE FROM x_blog_category_translation WHERE category_id = :id');
        $stmt->execute(['id' => $id]);
        $stmt = $this->db->prepare('DELETE FROM x_blog_category WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function slugTaken(string $locale, string $slug, ?int $exceptId): bool
    {
        $stmt = $this->db->prepare('SELECT category_id FROM x_blog_category_translation WHERE locale = :locale AND slug = :slug');
        $stmt->execute(['locale' => $locale, 'slug' => $slug]);
        $found = $stmt->fetchColumn();

        return $found !== false && (int) $found !== $exceptId;
    }
}

<?php

declare(strict_types=1);

namespace Modulento\Blog;

use Modulento\Core\App;
use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Session;

/** Writing and managing posts and categories. Every route requires the permission "blog.posts.manage". */
final class AdminController extends Controller
{
    private const PER_PAGE = 25;

    private Posts $posts;
    private Categories $categories;
    private PostImages $images;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->posts = new Posts($app->db, $app->locales);
        $this->categories = new Categories($app->db, $app->locales);
        $this->images = new PostImages($app->db, Extension::uploadDir($app));
    }

    public function index(array $params): void
    {
        $locale = $this->app->translator->locale();
        $default = $this->app->locales->default();
        $list = $this->posts->listAll(max(1, (int) ($_GET['page'] ?? 1)), self::PER_PAGE);
        $categories = $this->categories->inLocale($locale);
        $view = new PostView($this->app);

        $rows = [];
        foreach ($list['rows'] as $post) {
            $shown = Texts::pick($post['translations'], $locale, $default);
            $visible = Posts::isVisible($post);
            $rows[] = [
                'id' => $post['id'],
                'title' => $shown !== null ? $post['translations'][$shown]['title'] : '',
                'locales' => array_keys($post['translations']),
                // "scheduled" is a published post whose date is still ahead.
                'state' => $post['status'] !== 'published' ? 'draft' : ($visible ? 'published' : 'scheduled'),
                'published_at' => $post['published_at'],
                'category' => $post['category_id'] !== null ? ($categories[(int) $post['category_id']]['name'] ?? null) : null,
                'author' => $view->author($post['author_id'] !== null ? (int) $post['author_id'] : null),
                'path' => $visible && $shown !== null ? '/blog/' . $post['translations'][$shown]['slug'] : null,
            ];
        }

        $this->render('@blog/admin/posts.twig', [
            'posts' => $rows,
            'page' => $list['page'],
            'pages' => $list['pages'],
        ]);
    }

    public function edit(array $params): void
    {
        $post = isset($params['id']) ? $this->posts->find((int) $params['id']) : null;
        if (isset($params['id']) && $post === null) {
            $this->redirect('/admin/blog');
            return;
        }

        $this->renderForm($post, []);
    }

    public function save(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : null;
        $stored = $id !== null ? $this->posts->find($id) : null;
        if ($id !== null && $stored === null) {
            $this->redirect('/admin/blog');
            return;
        }

        $date = Posts::parseDate((string) ($_POST['published_at'] ?? ''));
        $category = (string) ($_POST['category_id'] ?? '');
        $fields = [
            'status' => (string) ($_POST['status'] ?? 'draft'),
            'published_at' => $date !== false ? $date : null,
            'category_id' => ctype_digit($category) ? (int) $category : null,
            'author_id' => $this->app->auth->account()['id'] ?? null,
        ];

        $translations = [];
        foreach ($this->app->locales->enabled() as $locale) {
            $input = is_array($_POST['text'][$locale] ?? null) ? $_POST['text'][$locale] : [];
            $translations[$locale] = [];
            foreach (['title', 'slug', 'summary', 'body', 'image_alt'] as $field) {
                $translations[$locale][$field] = is_string($input[$field] ?? null) ? $input[$field] : '';
            }
        }
        // Texts in a language that has been switched off since are kept as
        // they are rather than deleted by a save that could not show them.
        foreach ($stored['translations'] ?? [] as $locale => $text) {
            $translations[$locale] ??= array_map('strval', array_intersect_key($text, array_flip(['title', 'slug', 'summary', 'body', 'image_alt'])));
        }

        $result = $date === false
            ? ['id' => $id, 'errors' => [['key' => 'blog.admin.error.date', 'params' => []]]]
            : $this->posts->save($id, $fields, $translations);

        if ($result['errors'] !== []) {
            // Show the form again with what was typed, not what is stored.
            $this->renderForm(
                ($stored ?? []) + ['id' => $id] + ['typed_date' => (string) ($_POST['published_at'] ?? '')],
                array_map(fn (array $error) => $this->trans($error['key'], $error['params']), $result['errors']),
                $fields + ['translations' => $translations]
            );
            return;
        }

        // The picture comes with the same form but is a step of its own:
        // the post is saved even where the file is refused.
        $upload = $_FILES['image'] ?? null;
        if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $problem = is_int($upload['error'] ?? null) ? $this->images->set($result['id'], $upload) : 'blog.image.error.upload';
            if ($problem !== null) {
                Session::flash('error', $this->trans('blog.admin.saved_without_image', ['reason' => $this->trans($problem)]));
                $this->redirect('/admin/blog/' . $result['id']);
                return;
            }
        }

        Session::flash('success', $this->trans('blog.admin.saved'));
        $this->redirect('/admin/blog/' . $result['id']);
    }

    public function delete(array $params): void
    {
        $id = (int) $params['id'];
        $this->images->remove($id);
        $this->posts->delete($id);
        Session::flash('success', $this->trans('blog.admin.deleted'));
        $this->redirect('/admin/blog');
    }

    public function deleteImage(array $params): void
    {
        $this->images->remove((int) $params['id']);
        Session::flash('success', $this->trans('blog.admin.image_removed'));
        $this->redirect('/admin/blog/' . (int) $params['id']);
    }

    public function categories(array $params): void
    {
        $locale = $this->app->translator->locale();
        $default = $this->app->locales->default();

        $rows = [];
        foreach ($this->categories->all() as $category) {
            $shown = Texts::pick($category['translations'], $locale, $default);
            $rows[] = [
                'id' => $category['id'],
                'name' => $shown !== null ? $category['translations'][$shown]['name'] : '',
                'locales' => array_keys($category['translations']),
            ];
        }

        $this->render('@blog/admin/categories.twig', ['categories' => $rows]);
    }

    public function editCategory(array $params): void
    {
        $category = isset($params['id']) ? $this->categories->find((int) $params['id']) : null;
        if (isset($params['id']) && $category === null) {
            $this->redirect('/admin/blog/categories');
            return;
        }

        $this->renderCategoryForm($category, []);
    }

    public function saveCategory(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : null;
        $stored = $id !== null ? $this->categories->find($id) : null;
        if ($id !== null && $stored === null) {
            $this->redirect('/admin/blog/categories');
            return;
        }

        $translations = [];
        foreach ($this->app->locales->enabled() as $locale) {
            $input = is_array($_POST['text'][$locale] ?? null) ? $_POST['text'][$locale] : [];
            $translations[$locale] = [
                'name' => is_string($input['name'] ?? null) ? $input['name'] : '',
                'slug' => is_string($input['slug'] ?? null) ? $input['slug'] : '',
            ];
        }
        foreach ($stored['translations'] ?? [] as $locale => $text) {
            $translations[$locale] ??= ['name' => (string) $text['name'], 'slug' => (string) $text['slug']];
        }

        $result = $this->categories->save($id, $translations);

        if ($result['errors'] !== []) {
            $this->renderCategoryForm(
                ['id' => $id, 'translations' => $translations],
                array_map(fn (array $error) => $this->trans($error['key'], $error['params']), $result['errors'])
            );
            return;
        }

        Session::flash('success', $this->trans('blog.admin.categories.saved'));
        $this->redirect('/admin/blog/categories');
    }

    public function deleteCategory(array $params): void
    {
        $this->categories->delete((int) $params['id']);
        Session::flash('success', $this->trans('blog.admin.categories.deleted'));
        $this->redirect('/admin/blog/categories');
    }

    /**
     * @param array|null $post the stored post, null for a new one
     * @param string[] $errors
     * @param array|null $typed what the form sent, shown instead of the stored values after an error
     */
    private function renderForm(?array $post, array $errors, ?array $typed = null): void
    {
        $values = $typed ?? $post;
        $date = $post['typed_date'] ?? (($values['published_at'] ?? null) !== null ? substr(str_replace(' ', 'T', $values['published_at']), 0, 16) : '');

        $this->render('@blog/admin/post_edit.twig', [
            'post' => [
                'id' => $post['id'] ?? null,
                'status' => $values['status'] ?? 'draft',
                'published_at' => $date,
                'category_id' => $values['category_id'] ?? null,
                'translations' => $values['translations'] ?? [],
                'image' => $post !== null ? PostImages::urls($post) : null,
            ],
            'errors' => $errors,
            'locales' => $this->app->locales->enabled(),
            'categories' => array_values($this->categories->inLocale($this->app->translator->locale())),
            'statuses' => Posts::STATUSES,
            'images_available' => PostImages::available(),
            'max_megabytes' => intdiv(PostImages::MAX_BYTES, 1024 * 1024),
        ]);
    }

    /** @param string[] $errors */
    private function renderCategoryForm(?array $category, array $errors): void
    {
        $this->render('@blog/admin/category_edit.twig', [
            'category' => $category,
            'errors' => $errors,
            'locales' => $this->app->locales->enabled(),
        ]);
    }
}

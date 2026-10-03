<?php

declare(strict_types=1);

namespace Modulento\Blog;

use Modulento\Core\App;
use Modulento\Core\Event\AccountExport;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * A blog: posts with a text per language, categories, a cover picture, a
 * feed, an entry in the main menu and the newest posts on the home page.
 * It needs nothing of the catalogue, so an installation without any
 * marketplace extension is a plain blog portal with it.
 */
final class Extension implements ExtensionContract
{
    public const PERMISSION = 'blog.posts.manage';
    private const HOME_POSTS = 3;

    public function register(Registrar $registrar): void
    {
        $registrar->permission(self::PERMISSION, 'blog.permission.posts_manage');

        $registrar->routes(function (Router $router): void {
            $router->get('/blog', [BlogController::class, 'index'], Router::PUBLIC);
            // The fixed addresses come before /blog/{slug}: the first match
            // wins, and Posts::RESERVED_SLUGS keeps posts off them.
            $router->get('/blog/feed', [BlogController::class, 'feed'], Router::PUBLIC);
            // Below /media/ the core starts no session: pictures load side by
            // side and may be kept by any cache.
            $router->get('/media/blog/{file}', [BlogController::class, 'media'], Router::PUBLIC);
            $router->get('/blog/category/{slug}', [BlogController::class, 'category'], Router::PUBLIC);
            $router->get('/blog/{slug}', [BlogController::class, 'show'], Router::PUBLIC);

            $router->get('/admin/blog', [AdminController::class, 'index'], self::PERMISSION);
            $router->get('/admin/blog/categories', [AdminController::class, 'categories'], self::PERMISSION);
            $router->get('/admin/blog/categories/new', [AdminController::class, 'editCategory'], self::PERMISSION);
            $router->post('/admin/blog/categories/new', [AdminController::class, 'saveCategory'], self::PERMISSION);
            $router->get('/admin/blog/categories/{id}', [AdminController::class, 'editCategory'], self::PERMISSION);
            $router->post('/admin/blog/categories/{id}', [AdminController::class, 'saveCategory'], self::PERMISSION);
            $router->post('/admin/blog/categories/{id}/delete', [AdminController::class, 'deleteCategory'], self::PERMISSION);
            $router->get('/admin/blog/new', [AdminController::class, 'edit'], self::PERMISSION);
            $router->post('/admin/blog/new', [AdminController::class, 'save'], self::PERMISSION);
            $router->get('/admin/blog/{id}', [AdminController::class, 'edit'], self::PERMISSION);
            $router->post('/admin/blog/{id}', [AdminController::class, 'save'], self::PERMISSION);
            $router->post('/admin/blog/{id}/delete', [AdminController::class, 'delete'], self::PERMISSION);
            $router->post('/admin/blog/{id}/image/delete', [AdminController::class, 'deleteImage'], self::PERMISSION);
        });

        $registrar->adminMenu('blog.admin.menu', '/admin/blog', self::PERMISSION, 'content');
        $registrar->navigation('blog.nav', '/blog');
        $registrar->homeSection('@blog/home.twig', function (App $app): array {
            $locale = $app->translator->locale();
            $list = (new Posts($app->db, $app->locales))->listVisible($locale, 1, self::HOME_POSTS);

            return ['posts' => (new PostView($app))->cards($list['rows'], $locale)];
        });

        // What an account wrote is part of its data. Nothing to do when an
        // account is deleted: the foreign key takes the author off its
        // posts, and the posts stay.
        $registrar->listen(AccountExport::class, function (AccountExport $event, App $app): void {
            $posts = [];
            foreach ((new Posts($app->db, $app->locales))->ofAuthor($event->accountId) as $post) {
                $posts[] = [
                    'status' => $post['status'],
                    'published_at' => $post['published_at'],
                    'created_at' => $post['created_at'],
                    'updated_at' => $post['updated_at'],
                    'texts' => array_map(
                        fn (array $text) => ['title' => $text['title'], 'slug' => $text['slug'], 'summary' => $text['summary'], 'body' => $text['body']],
                        $post['translations']
                    ),
                ];
            }
            $event->add('blog', ['posts' => $posts]);
        });
    }

    /** Where the cover pictures are kept: a folder of its own below the core's uploads. */
    public static function uploadDir(App $app): string
    {
        return ($app->config['app']['uploads'] ?? $app->config['app']['root'] . '/var/uploads') . '/blog';
    }
}

<?php

declare(strict_types=1);

namespace Modulento\Blog;

use Modulento\Core\App;
use Modulento\Core\Support\Router;

/**
 * Fetches the posts for the home page section. On the home page no code
 * of this extension is running that was given the App, and register()
 * does not get it either - only the router. So the question is asked the
 * one way the interface offers: a route of this extension is dispatched
 * from within, and its handler, which the router calls with the App,
 * leaves the answer here.
 */
final class HomeFeed
{
    public const PATH = '/blog/home-section';

    private static ?Router $router = null;
    /** How many posts the running template asked for; null while nobody asks. */
    private static ?int $wanted = null;
    /** @var array<int, array> */
    private static array $posts = [];

    public static function bind(Router $router): void
    {
        self::$router = $router;
        $router->get(self::PATH, self::answer(...), Router::PUBLIC);
    }

    /** @return array<int, array> */
    public static function latest(int $limit): array
    {
        if (self::$router === null) {
            return [];
        }

        self::$wanted = max(1, min(12, $limit));
        self::$posts = [];
        try {
            self::$router->dispatch('GET', self::PATH);
        } finally {
            self::$wanted = null;
        }

        return self::$posts;
    }

    private static function answer(array $params, App $app): void
    {
        // Requested by a browser, the address is nothing.
        if (self::$wanted === null) {
            http_response_code(404);
            echo $app->view()->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $locale = $app->translator->locale();
        $list = (new Posts($app->db, $app->locales))->listVisible($locale, 1, self::$wanted);
        self::$posts = (new PostView($app))->cards($list['rows'], $locale);
    }
}

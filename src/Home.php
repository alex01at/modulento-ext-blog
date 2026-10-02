<?php

declare(strict_types=1);

namespace Modulento\Blog;

/**
 * The handle templates use to ask for posts where no controller of this
 * extension hands them any - the section on the home page:
 *
 *     {% set posts = constant('Modulento\\Blog\\Home::Latest').posts(3) %}
 *
 * The core includes that template without variables, and an extension
 * cannot add a Twig function. A constant is the one thing of an extension
 * a template can name, and a case of an enum is a constant with methods.
 */
enum Home
{
    case Latest;

    /** @return array<int, array> the newest visible posts in the visitor's language, as PostView::card() */
    public function posts(int $limit = 3): array
    {
        return HomeFeed::latest($limit);
    }
}

-- Posts and their categories. Both keep what a visitor reads - title,
-- address, text - once per language in a table of their own, like the
-- core's pages; a language may be missing.
--
-- A post is visible once its status is "published" and published_at has
-- passed, so a date in the future schedules it without any cron.

CREATE TABLE x_blog_category (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_blog_category_translation (
    category_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    PRIMARY KEY (category_id, locale),
    UNIQUE KEY uq_x_blog_category_slug (locale, slug),
    CONSTRAINT fk_x_blog_category_translation FOREIGN KEY (category_id) REFERENCES x_blog_category (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- author_id and category_id become NULL when the account or the category
-- is deleted: the post stays, without a name or a category next to it.
CREATE TABLE x_blog_post (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NULL,
    author_id INT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    image_name CHAR(32) NULL,
    image_extension VARCHAR(4) NULL,
    image_width SMALLINT UNSIGNED NULL,
    image_height SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_x_blog_post_visible (status, published_at),
    KEY idx_x_blog_post_author (author_id),
    CONSTRAINT fk_x_blog_post_category FOREIGN KEY (category_id) REFERENCES x_blog_category (id) ON DELETE SET NULL,
    CONSTRAINT fk_x_blog_post_author FOREIGN KEY (author_id) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_blog_post_translation (
    post_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(200) NOT NULL,
    summary VARCHAR(500) NULL,
    body MEDIUMTEXT NOT NULL,
    image_alt VARCHAR(200) NULL,
    PRIMARY KEY (post_id, locale),
    UNIQUE KEY uq_x_blog_post_slug (locale, slug),
    CONSTRAINT fk_x_blog_post_translation FOREIGN KEY (post_id) REFERENCES x_blog_post (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

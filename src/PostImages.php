<?php

declare(strict_types=1);

namespace Modulento\Blog;

use PDO;

/**
 * The cover picture of a post. As with the core's offer pictures, an
 * upload is never stored as it arrived: it is decoded and written anew in
 * two sizes, which drops embedded data and anything hidden behind the
 * picture. The files live outside the web root under a random name and
 * are served by BlogController::media().
 */
final class PostImages
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_PIXELS = 40_000_000;
    private const LARGE_EDGE = 1600;
    private const THUMB_EDGE = 640;
    private const ACCEPTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(private PDO $db, private string $uploadDir)
    {
    }

    /** Whether this server can process pictures at all (PHP's GD extension). */
    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor');
    }

    /**
     * Gives a post its picture, replacing the one it had.
     *
     * @param array{tmp_name?: string, error?: int} $upload one entry of $_FILES
     * @return string|null language key of the problem, null on success
     */
    public function set(int $postId, array $upload): ?string
    {
        if (!self::available()) {
            return 'blog.image.error.unavailable';
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'] ?? '')) {
            return in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'blog.image.error.too_large'
                : 'blog.image.error.upload';
        }
        if (filesize($upload['tmp_name']) > self::MAX_BYTES) {
            return 'blog.image.error.too_large';
        }
        $previous = $this->row($postId);
        if ($previous === null) {
            return 'blog.image.error.upload';
        }

        // What the file is is read from its content, never from the name
        // or the type the browser claims.
        $info = @getimagesize($upload['tmp_name']);
        if ($info === false || !in_array($info[2], self::ACCEPTED, true)) {
            return 'blog.image.error.type';
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return 'blog.image.error.dimensions';
        }

        $source = @imagecreatefromstring((string) file_get_contents($upload['tmp_name']));
        if ($source === false) {
            return 'blog.image.error.type';
        }

        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
        $name = bin2hex(random_bytes(16));
        if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
            return 'blog.image.error.storage';
        }

        $large = $this->resized($source, self::LARGE_EDGE);
        $thumb = $this->resized($source, self::THUMB_EDGE);
        $written = $this->write($large, "{$this->uploadDir}/{$name}.{$extension}", $extension)
            && $this->write($thumb, "{$this->uploadDir}/{$name}_thumb.{$extension}", $extension);
        $width = imagesx($large);
        $height = imagesy($large);
        imagedestroy($source);
        imagedestroy($large);
        imagedestroy($thumb);

        if (!$written) {
            $this->unlinkFiles($name, $extension);

            return 'blog.image.error.storage';
        }

        $stmt = $this->db->prepare(
            'UPDATE x_blog_post SET image_name = :name, image_extension = :extension, image_width = :width, image_height = :height
             WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'extension' => $extension, 'width' => $width, 'height' => $height, 'id' => $postId]);

        if ($previous['image_name'] !== null) {
            $this->unlinkFiles($previous['image_name'], (string) $previous['image_extension']);
        }

        return null;
    }

    /** Takes the picture from a post: the files and the reference to them. Also before the post itself is deleted. */
    public function remove(int $postId): void
    {
        $row = $this->row($postId);
        if ($row === null || $row['image_name'] === null) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE x_blog_post SET image_name = NULL, image_extension = NULL, image_width = NULL, image_height = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $postId]);
        $this->unlinkFiles($row['image_name'], (string) $row['image_extension']);
    }

    /** The stored file for a request, or null - only names this class created can match. */
    public function path(string $file): ?string
    {
        if (preg_match('/\A[0-9a-f]{32}(_thumb)?\.(webp|jpg)\z/', $file) !== 1) {
            return null;
        }

        $path = $this->uploadDir . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /**
     * Paths of a post's picture for templates, or null without one.
     *
     * @return array{large: string, thumb: string, width: int, height: int}|null
     */
    public static function urls(array $post): ?array
    {
        if (($post['image_name'] ?? null) === null) {
            return null;
        }

        $base = '/blog/media/' . $post['image_name'];

        return [
            'large' => $base . '.' . $post['image_extension'],
            'thumb' => $base . '_thumb.' . $post['image_extension'],
            'width' => (int) $post['image_width'],
            'height' => (int) $post['image_height'],
        ];
    }

    private function row(int $postId): ?array
    {
        $stmt = $this->db->prepare('SELECT image_name, image_extension FROM x_blog_post WHERE id = :id');
        $stmt->execute(['id' => $postId]);

        return $stmt->fetch() ?: null;
    }

    private function unlinkFiles(string $name, string $extension): void
    {
        @unlink("{$this->uploadDir}/{$name}.{$extension}");
        @unlink("{$this->uploadDir}/{$name}_thumb.{$extension}");
    }

    private function resized(\GdImage $source, int $maxEdge): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxEdge / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        // Transparent areas become white instead of black.
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }

    private function write(\GdImage $image, string $path, string $extension): bool
    {
        return $extension === 'webp' ? imagewebp($image, $path, 82) : imagejpeg($image, $path, 85);
    }
}

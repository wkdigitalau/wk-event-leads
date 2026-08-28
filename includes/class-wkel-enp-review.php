<?php
defined('ABSPATH') || exit;

/**
 * Public, noindex review route for the ENP campaign concepts.
 *
 * Keeps the live capture page at / untouched while serving a tightly
 * allow-listed set of static review files beneath /ENP/.
 */
class WKEL_ENP_Review {

    private const FILES = [
        ''                               => 'index.html',
        'index.html'                     => 'index.html',
        'homepage-review.html'           => 'homepage-review.html',
        'provider-landing-review.html'   => 'provider-landing-review.html',
        'styles.css'                     => 'styles.css',
        'review-pages.css'               => 'review-pages.css',
    ];

    public static function maybe_render(): void {
        if (is_admin()) {
            return;
        }

        $request_path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $request_path = rawurldecode($request_path);

        if (strcasecmp($request_path, '/ENP') === 0) {
            wp_safe_redirect(home_url('/ENP/'), 301);
            exit;
        }

        $trimmed = trim($request_path, '/');
        if (strcasecmp($trimmed, 'ENP') === 0) {
            $relative = '';
        } elseif (stripos($trimmed, 'ENP/') === 0) {
            $relative = substr($trimmed, 4);
        } else {
            return;
        }

        $relative_key = strtolower($relative);
        if (!array_key_exists($relative_key, self::FILES)) {
            status_header(404);
            nocache_headers();
            header('X-Robots-Tag: noindex, nofollow', true);
            echo 'ENP review page not found.';
            exit;
        }

        $filename = self::FILES[$relative_key];
        $filepath = WKEL_PLUGIN_DIR . 'public/enp-review/' . $filename;
        if (!is_readable($filepath)) {
            status_header(404);
            nocache_headers();
            echo 'ENP review page not found.';
            exit;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $content_type = $extension === 'css'
            ? 'text/css; charset=UTF-8'
            : 'text/html; charset=UTF-8';

        status_header(200);
        header('Content-Type: ' . $content_type);
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Cache-Control: no-cache, must-revalidate');
        header('Content-Length: ' . (string) filesize($filepath));
        readfile($filepath);
        exit;
    }
}

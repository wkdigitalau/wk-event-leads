<?php
/**
 * Template Name: WKEL Capture
 * Template Post Type: page
 *
 * Blank page template — form only, no theme header/footer.
 * Add to the active theme or child theme.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php wp_title('|', true, 'right'); ?><?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class('wkel-capture-page'); ?>>
    <main class="wkel-capture-main">
        <?php while (have_posts()): the_post(); ?>
            <?php the_content(); ?>
        <?php endwhile; ?>
    </main>
    <?php wp_footer(); ?>
</body>
</html>

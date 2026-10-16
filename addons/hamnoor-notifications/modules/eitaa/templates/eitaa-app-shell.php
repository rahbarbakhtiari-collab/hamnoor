<?php
if (!defined('ABSPATH')) { exit; }
?><!doctype html>
<html <?php language_attributes(); ?> dir="rtl" class="hamnoor-eitaa-app">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <?php wp_head(); ?>
</head>
<body <?php body_class('hamnoor-eitaa-app-page'); ?>>
<?php wp_body_open(); ?>
<main id="hamnoor-eitaa-app-shell" class="hamnoor-eitaa-app-shell" role="main">
<?php
while (have_posts()) {
    the_post();
    the_content();
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>

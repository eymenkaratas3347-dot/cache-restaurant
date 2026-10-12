<?php
if (!defined('ABSPATH')) exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?php echo esc_attr(KM_Util::site('seo_description')); ?>">
<?php wp_head(); ?>
</head>
<body <?php body_class('km-body'); ?>>
<?php wp_body_open(); ?>
<a class="km-skip" href="#km-main">Saltar al contenido</a>
<?php echo KM_Front::header_html(); ?>
<main id="km-main" class="km-main">
<?php while (have_posts()) { the_post(); the_content(); } ?>
</main>
<?php echo KM_Front::footer_html(); ?>
<?php wp_footer(); ?>
</body>
</html>

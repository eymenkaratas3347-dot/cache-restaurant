<?php
if (!defined('ABSPATH')) exit;
$km = $GLOBALS['km_layout'];
$ev = $km['event'];
$desc = $ev ? wp_trim_words(wp_strip_all_tags($ev->description ?: $ev->event_name . ' – ' . KM_Util::date_human($ev->event_date) . ' en Cacho e Cabra'), 30) : KM_Util::site('seo_description');
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?php echo esc_attr($desc); ?>">
<?php if ($km['noindex']): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<?php if ($ev): ?>
<link rel="canonical" href="<?php echo esc_url(KM_Util::event_url($ev)); ?>">
<meta property="og:title" content="<?php echo esc_attr($ev->event_name); ?>">
<meta property="og:description" content="<?php echo esc_attr($desc); ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?php echo esc_url(KM_Util::event_url($ev)); ?>">
<?php if ($ev->image_url): ?><meta property="og:image" content="<?php echo esc_url($ev->image_url); ?>"><?php endif; ?>
<script type="application/ld+json"><?php echo wp_json_encode([
    '@context' => 'https://schema.org', '@type' => 'Event', 'name' => $ev->event_name,
    'startDate' => KM_Events::start_dt($ev)->format('c'), 'endDate' => KM_Events::end_dt($ev)->format('c'),
    'eventStatus' => $ev->status === 'cancelled' ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
    'image' => $ev->image_url ? [$ev->image_url] : [],
    'location' => ['@type' => 'Place', 'name' => 'Cacho e Cabra', 'address' => KM_Util::site('address')],
    'offers' => ['@type' => 'Offer', 'price' => (float) $ev->price_per_person, 'priceCurrency' => 'CLP', 'url' => KM_Util::booking_url($ev)],
]); ?></script>
<?php endif; ?>
<?php wp_head(); ?>
</head>
<body <?php body_class('km-body'); ?>>
<?php wp_body_open(); ?>
<a class="km-skip" href="#km-main">Saltar al contenido</a>
<?php echo KM_Front::header_html(); ?>
<main id="km-main" class="km-main">
<?php echo $km['content']; // Built from escaped parts. ?>
</main>
<?php echo KM_Front::footer_html(); ?>
<?php wp_footer(); ?>
</body>
</html>

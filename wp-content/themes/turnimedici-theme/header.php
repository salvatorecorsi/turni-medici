<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div id="page" class="tm-site">
    <header class="tm-header">
        <div class="tm-container tm-header__inner">
            <a class="tm-brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php echo esc_attr(get_bloginfo('name')); ?> — home">
                <?php tm_clock_logo('tm-brand__logo'); ?>
                <span class="tm-brand__sub"><?php echo esc_html(get_bloginfo('name')); ?></span>
            </a>

            <?php if (has_nav_menu('primary')) : ?>
            <nav id="tm-primary-nav" class="tm-nav" aria-label="Navigazione principale">
                <?php wp_nav_menu(['theme_location' => 'primary', 'container' => false, 'menu_class' => 'tm-nav__list']); ?>
            </nav>
            <?php endif; ?>
        </div>
    </header>

    <main id="primary" class="tm-main">

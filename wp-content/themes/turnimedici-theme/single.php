<?php
/**
 * Single post — articoli editoriali (guide AIR, ecc.)
 * @package turnimedici
 */

get_header();

while (have_posts()) : the_post();
    $categories = get_the_category();
    $primary    = $categories ? $categories[0] : null;
    $subtitle   = has_excerpt() ? get_the_excerpt() : '';
?>

<section class="tm-detail-hero tm-article-hero">
    <div class="tm-container">
        <nav class="tm-crumbs" aria-label="Percorso">
            <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
            <span aria-hidden="true">/</span>
            <?php if ($primary) : ?>
                <a href="<?php echo esc_url(get_category_link($primary)); ?>"><?php echo esc_html($primary->name); ?></a>
                <span aria-hidden="true">/</span>
            <?php endif; ?>
            <span class="tm-crumbs__current"><?php the_title(); ?></span>
        </nav>
        <?php if ($primary) : ?>
            <span class="tm-eyebrow"><?php echo esc_html($primary->name); ?></span>
        <?php endif; ?>
        <h1><?php the_title(); ?></h1>
        <?php if ($subtitle) : ?>
            <p class="tm-article-hero__lead"><?php echo esc_html($subtitle); ?></p>
        <?php endif; ?>
    </div>
</section>

<section class="tm-section">
    <div class="tm-container">
        <article class="tm-prose tm-article"><?php the_content(); ?></article>
    </div>
</section>

<?php endwhile; ?>

<?php get_footer();

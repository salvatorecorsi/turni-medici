<?php

get_header();
?>

<div class="tm-container tm-page">
    <?php while (have_posts()) : the_post(); ?>
    <article <?php post_class(); ?>>
        <h1 class="tm-page__title"><?php the_title(); ?></h1>
        <div class="tm-article"><?php the_content(); ?></div>
    </article>
    <?php endwhile; ?>
</div>

<?php
get_footer();

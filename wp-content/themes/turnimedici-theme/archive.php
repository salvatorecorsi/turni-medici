<?php

/**
 * The template for displaying archive pages
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 * @package turnimedici
 */

get_header();
?>
<main id="primary" class="site-main">
	<?php if (have_posts()) : ?>
		<header class="page-header">
			<?php
			the_archive_title('<h1 class="page-title">', '</h1>');
			the_archive_description('<div class="archive-description">', '</div>');
			?>
		</header>
	<?php
		while (have_posts()) :
			the_post();
		endwhile;
	else :
		echo __('Nessun post', 'turnimedici');
	endif;
	?>
</main>
<?php
get_footer();

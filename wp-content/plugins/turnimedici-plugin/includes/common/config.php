<?php

add_filter( 'excerpt_length', function() {
    return 20;
} );

add_filter('focal_point_post_types', function() {
    return array('post','project');
});
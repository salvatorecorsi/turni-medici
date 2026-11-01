<?php
	
    function disabled_comments() {

        if(defined('DOING_AJAX') && DOING_AJAX) return;

        global $pagenow;
        $post_types = get_post_types();
        $post_types_exceptions = [];

        if(!is_array($post_types)) return;

        foreach($post_types as $type) {
            if(in_array($type,$post_types_exceptions)) continue;
            remove_post_type_support($type, 'comments');
            remove_post_type_support($type, 'trackbacks');
        }

        if ( $pagenow == 'comment.php' || $pagenow == 'edit-comments.php' || $pagenow == 'options-discussion.php' )
            wp_die( __( 'Comments are closed.' ), '', array( 'response' => 403 ) );

        @remove_menu_page( 'edit-comments.php' );
        @remove_submenu_page( 'options-general.php', 'options-discussion.php' );
    }
    add_action( 'admin_init', 'disabled_comments' );
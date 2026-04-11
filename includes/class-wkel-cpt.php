<?php
defined('ABSPATH') || exit;

class WKEL_CPT {

    public static function register(): void {
        register_post_type('wkel_lead', [
            'labels' => [
                'name'               => __('Leads', 'wk-event-leads'),
                'singular_name'      => __('Lead', 'wk-event-leads'),
                'menu_name'          => __('Leads', 'wk-event-leads'),
                'add_new'            => __('Add Lead', 'wk-event-leads'),
                'add_new_item'       => __('Add New Lead', 'wk-event-leads'),
                'edit_item'          => __('Edit Lead', 'wk-event-leads'),
                'view_item'          => __('View Lead', 'wk-event-leads'),
                'search_items'       => __('Search Leads', 'wk-event-leads'),
                'not_found'          => __('No leads found.', 'wk-event-leads'),
                'not_found_in_trash' => __('No leads found in trash.', 'wk-event-leads'),
            ],
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'supports'            => ['title', 'custom-fields'],
            'capability_type'     => 'post',
            'capabilities'        => [
                'create_posts' => 'manage_options',
                'edit_posts'   => 'manage_options',
                'delete_posts' => 'manage_options',
            ],
            'map_meta_cap'        => true,
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
        ]);
    }
}

<?php
/*
 * Plugin Name:      Emedi Helper Plugin
 * Plugin URI:        https://github.com/shakib6472/
 * Description:       This is a helper plugin for the Emedi website.
 * Version:           1.1.1
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Shakib Shown
 * Author URI:        https://github.com/shakib6472/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       emedi
 * Domain Path:       /languages
 */
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

register_activation_hook(__FILE__, 'emedi_activate');
function emedi_activate()
{
    add_rewrite_endpoint('my-membership', EP_PAGES);
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'emedi_deactivate');
function emedi_deactivate()
{
    //deactivate
    flush_rewrite_rules();
}

// enques assets
function emedi_enqueue_assets()
{
    // Enqueue your styles and scripts here
    //jquery
    wp_enqueue_script('jquery');
    wp_enqueue_script('emedi-scripts', plugin_dir_url(__FILE__) . 'assets/script.js', ['jquery'], null, true);
    wp_enqueue_style(
        'emedi-tailwind',
        'https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css',
        [],
        '2.2.19'
    );
    //localize the script to send ajax, home, pricing page url
    wp_localize_script('emedi-scripts', 'emedi_vars', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'home_url' => home_url(),
        'pricing_url' => home_url('/pricing'),
    ]);
}
add_action('wp_enqueue_scripts', 'emedi_enqueue_assets');


include_once plugin_dir_path(__FILE__) . 'includes/class-emedi-helper.php';
include_once plugin_dir_path(__FILE__) . 'includes/class-shortcodes.php';
include_once plugin_dir_path(__FILE__) . 'includes/Emedi_Temp_Product_Cleaner.php';



const EMEDI_HOOK = 'emedi_test_logger';

add_filter('cron_schedules', function ($s) {
    $s['every_minute'] = [
        'interval' => 60,
        'display' => 'Every Minute'
    ];
    return $s;
});

add_action('plugins_loaded', function () {
    $evt = wp_get_scheduled_event(EMEDI_HOOK);
    $aligned_start = floor(time() / 60) * 60 + 5;

    if (!$evt) {
        wp_schedule_event($aligned_start, 'every_minute', EMEDI_HOOK);
        return;
    }

    if ($evt->schedule !== 'every_minute' || ($evt->timestamp % 60) !== 5) {
        wp_unschedule_event($evt->timestamp, EMEDI_HOOK, $evt->args);
        wp_schedule_event($aligned_start, 'every_minute', EMEDI_HOOK);
    }
});

add_action(EMEDI_HOOK, function () {
    error_log('EMEDI TEST LOGGER: run at ' . current_time('mysql'));
});

// 4) (ঐচ্ছিক) ডিঅ্যাকটিভেশনে পরিষ্কার
register_deactivation_hook(__FILE__, function () {
    if ($evt = wp_get_scheduled_event(EMEDI_HOOK)) {
        wp_unschedule_event($evt->timestamp, EMEDI_HOOK, $evt->args);
    }
});



// Works for Elementor Loop Grid / Query Control
add_action('elementor/query/related_pillar', function( $query ) {
    // Get current post ID safely
    $post_id = 0;
    if ( is_singular() ) {
        $post_id = get_queried_object_id();
    } elseif ( isset($GLOBALS['post']->ID) ) {
        $post_id = (int) $GLOBALS['post']->ID;
    }
    if ( ! $post_id ) return;

    // Get current post's 'pillar' terms
    $term_ids = wp_get_post_terms( $post_id, 'pillar', ['fields' => 'ids'] );
    if ( empty($term_ids) || is_wp_error($term_ids) ) return;

    // Exclude current post
    $query->set( 'post__not_in', [$post_id] );

    // Merge with existing tax_query if any
    $tax_query = (array) $query->get('tax_query');
    $tax_query[] = [
        'taxonomy' => 'pillar',
        'field'    => 'term_id',
        'terms'    => $term_ids,
        'operator' => 'IN',
    ];
    $query->set( 'tax_query', $tax_query );

    // Optional: ordering / count
    // $query->set( 'posts_per_page', 3 );
    // $query->set( 'orderby', 'date' );
    // $query->set( 'order', 'DESC' );
    $query->set( 'ignore_sticky_posts', true );
});


 
function my_course_query( $query ) { 
    $user_id = get_current_user_id();
    if ( ! $user_id ) { 
        $query->set( 'post__in', array( 0 ) );
        return;
    }
 
    $all_meta = get_user_meta( $user_id ); // returns [meta_key => [values]]
    $course_ids = array();

    foreach ( $all_meta as $meta_key => $values ) { 
        if ( preg_match( '/^course_(\d+)_access_from$/', $meta_key, $m ) ) {
            $course_id = absint( $m[1] ); 
            $has_value = ! empty( $values ) && ! empty( $values[0] );
            if ( $course_id && $has_value ) {
                $course_ids[] = $course_id;
            }
        }
    }
 
    if ( empty( $course_ids ) ) {
        $query->set( 'post__in', array( 0 ) );
        return;
    }
  
    $query->set( 'post__in', array_values( array_unique( $course_ids ) ) );
 
}
add_action( 'elementor/query/mycourses', 'my_course_query' );



/**
 * Helper: Get first lesson ID of a LearnDash course
 */
function mcd_get_first_lesson_id_simple( $course_id ) {
    if ( ! $course_id ) {
        return 0;
    }

    // Try LearnDash steps API (LD3 compatible)
    if ( function_exists( 'learndash_course_get_children_of_step' ) ) {
        $lessons = learndash_course_get_children_of_step( $course_id, $course_id, 'sfwd-lessons' );
        if ( ! empty( $lessons ) ) {
            $lesson_ids = array_values( $lessons );
            return absint( $lesson_ids[0] );
        }
    }

    // Fallback: query lessons by course_id
    $q = new WP_Query( array(
        'post_type'      => 'sfwd-lessons',
        'posts_per_page' => 1,
        'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
        'meta_query'     => array(
            array(
                'key'     => 'course_id',
                'value'   => $course_id,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ),
        ),
        'post_status'    => 'publish',
        'no_found_rows'  => true,
        'fields'         => 'ids',
    ) );

    if ( ! empty( $q->posts ) ) {
        return absint( $q->posts[0] );
    }

    return 0;
}

/**
 * Shortcode: [course_first_lesson_url]
 * Always uses get_the_ID() as the course ID.
 */
function mcd_course_first_lesson_url_shortcode_simple() {
    $course_id = get_the_ID();
    $lesson_id = mcd_get_first_lesson_id_simple( $course_id );

    if ( ! $lesson_id ) {
        return '';
    }

    return esc_url( get_permalink( $lesson_id ) );
}
add_shortcode( 'course_first_lesson_url', 'mcd_course_first_lesson_url_shortcode_simple' );

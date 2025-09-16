<?php

class Emedi_Shortcodes
{

    public function __construct()
    {
        add_shortcode('emedi_course_lesson_count', [$this, 'sh_get_course_lesson_count']);
        add_shortcode('emedi_course_quizz_count', [$this, 'sh_get_course_quizz_count']);
        add_shortcode('emedi_learndash_meta_data', [$this, 'learndash_meta_data_shortcode']);
        add_action('elementor/query/lessonforcourse', [$this, 'lesson_query']);

    }

    public function sh_get_course_lesson_count()
    {
        $course_id = get_the_ID();
        // 1) Fetch all lessons under this course
        $course_meta_key = 'ld_course_' . $course_id;
        $lesson_ids = get_posts([
            'post_type' => 'sfwd-lessons',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => $course_meta_key,
                    'value' => $course_id,
                    'compare' => '='
                ]
            ],
            'orderby' => 'menu_order',
            'order' => 'ASC',
        ]);

        $lesson_ids = array_map('intval', (array) $lesson_ids);
        $lesson_ids = array_values(array_unique($lesson_ids));

        if (!empty($lesson_ids)) {
            $total = count($lesson_ids);
            return $total;

        } else {
            return '0';
        }
    }

    public function sh_get_course_quizz_count()
    {
         $course_id = get_the_ID();
        // 1) Fetch all lessons under this course
        $course_meta_key = 'ld_course_' . $course_id;
        $quizz_ids = get_posts([
            'post_type' => 'sfwd-quiz',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => $course_meta_key,
                    'value' => $course_id,
                    'compare' => '='
                ]
            ],
            'orderby' => 'menu_order',
            'order' => 'ASC',
        ]);

        $quizz_ids = array_map('intval', (array) $quizz_ids);
        $quizz_ids = array_values(array_unique($quizz_ids));

        if (!empty($quizz_ids)) {
            $total = count($quizz_ids);
            return $total;

        } else {
            return '0';
        }
    }

    /**
     * Shortcode to display LearnDash meta data
     * @param mixed $attrs
     * Shortcode Style - [emedi_learndash_meta_data field="meta_key"]
     */
    public function learndash_meta_data_shortcode($attrs)
    {
        $attrs = shortcode_atts([
            'field' => '',
        ], $attrs);

        $course_id = get_the_ID();
        $field = $attrs['field'];

        // Fetch the LearnDash meta data based on the field
        $all_meta_value = get_post_meta($course_id, '_sfwd-courses', true);
        $target_meta_value = $all_meta_value[$field] ?? '';

        return $target_meta_value;
    }

    public function lesson_query($query)
    {
        $meta_query = $query->get('meta_query');
        $course_id = get_the_ID();
        $course_meta_key = 'ld_course_' . $course_id;

        if (!$meta_query) {
            $meta_query = [];
        }

        // Append our meta query
        $meta_query[] = [
            'key' => $course_meta_key,
            'value' => $course_id,
            'compare' => '='
        ];
        $query->set('meta_query', $meta_query);

    }
}

// call the Emedi_Shortcodes class
new Emedi_Shortcodes();




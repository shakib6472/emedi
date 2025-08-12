<?php

class Emedi_Helper
{

    public function __construct()
    {
        add_action('fluentform/submission_inserted', [$this, 'emedi_fluent_form_submission'], 20, 3);
        add_filter('woocommerce_get_item_data', [$this, 'add_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_order_item_meta'], 10, 4);

        // সফল পেমেন্টে (অনেক গেটওয়ে এখানে ফায়ার করে)
        add_action('woocommerce_payment_complete', [$this, 'emedi_bind_temp_products_to_order']);
        // যেসব অর্ডারে payment_complete ফায়ার নাও হতে পারে (BACS/COD), সেফটি হিসেবে:
        add_action('woocommerce_order_status_processing', [$this, 'emedi_bind_temp_products_to_order']);
        add_action('woocommerce_order_status_completed', [$this, 'emedi_bind_temp_products_to_order']);
    }
    public function emedi_fluent_form_submission($entryId, $formData, $form)
    {
        error_log('Sanitized Form Data: ' . print_r($formData, true));
        $courses = $formData['courses'];
        $duration = $formData['duration'];
        $totalPrice = $formData['numeric_field'];

        $total_course = count($courses);
        $course_names = [];
        foreach ($courses as $course_id) {
            $course = get_post($course_id);
            if ($course) {
                $course_names[] = $course->post_title;
            }
        }
        // $product_id = 986;
        // set product Title
        $product_title = $total_course . ' Course For ' . $duration;
        // set product price
        $product_price = $totalPrice;

        //create product
        $product_id = wp_insert_post([
            'post_title' => $product_title,
            'post_content' => $product_title,
            'post_status' => 'publish',
            'post_type' => 'product',
        ]);

        // Update product meta
        update_post_meta($product_id, '_price', $product_price);
        update_post_meta($product_id, '_regular_price', $product_price);
        // add course ids in product meta
        update_post_meta($product_id, '_course_ids', $courses);
        update_post_meta($product_id, '_duration', $duration);

        WC()->cart->empty_cart();
        WC()->cart->add_to_cart($product_id, 1, 0, [], [
            'emedi_course_names' => is_array($course_names) ? $course_names : [$course_names],
            'emedi_duration' => (string) $duration,
        ]);

    }

    public function add_cart_item_data($item_data, $cart_item)
    {
        if (!empty($cart_item['emedi_duration'])) {
            $item_data[] = [
                'key' => __('<b>Duration</b>', 'emedi'),
                'value' => wc_clean($cart_item['emedi_duration']),
            ];
        }

        if (!empty($cart_item['emedi_course_names'])) {
            $names = array_map('wc_clean', (array) $cart_item['emedi_course_names']);
            $value = implode(', ', $names);
            $item_data[] = [
                'key' => __('<b>Courses</b>', 'emedi'),
                'value' => $value,
                'display' => $value,
            ];
        }

        return $item_data;
    }

    public function add_order_item_meta($item, $cart_item_key, $values, $order)
    {
        if (!empty($values['emedi_duration'])) {
            $item->add_meta_data(__('Duration', 'emedi'), wc_clean($values['emedi_duration']), true);
        }

        if (!empty($values['emedi_course_names'])) {
            $names = array_map('wc_clean', (array) $values['emedi_course_names']);
            $item->add_meta_data(__('Courses', 'emedi'), implode(', ', $names), true);
        }

    }
   public function emedi_bind_temp_products_to_order($order_id)
{
    if (empty($order_id)) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    // get user id
    $user_id = (int) $order->get_user_id();
    if (!$user_id) {
        $billing_email = $order->get_billing_email();
        if ($billing_email) {
            $u = get_user_by('email', $billing_email);
            if ($u) $user_id = (int) $u->ID;
        }
    }

    foreach ($order->get_items('line_item') as $item) {
        $product_id = $item->get_product_id();
        if (!$product_id) continue;

        // Skip If already bound
        $already = get_post_meta($product_id, '_emedi_bound_order_id', true);
        if (!empty($already)) continue;

        // Get course IDs and duration
        $raw_courses = get_post_meta($product_id, '_course_ids', true);
        $duration_days = (int) get_post_meta($product_id, '_duration', true);

        // Normalize course IDs
        $course_ids = [];
        if (is_array($raw_courses)) {
            $course_ids = $raw_courses;
        } elseif (is_string($raw_courses) && $raw_courses !== '') {
            // If JSON
            $decoded = json_decode($raw_courses, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $course_ids = $decoded;
            } else {
                // If comma-separated
                $course_ids = array_map('trim', explode(',', $raw_courses));
            }
        }
        // Remove empty/duplicate values
        $course_ids = array_values(array_unique(array_filter(array_map('intval', (array) $course_ids))));

        // Sanitize duration (0 if negative)
        if ($duration_days < 0) $duration_days = 0;

        // ===== 2) LearnDash অ্যাক্সেস দাও (যদি user থাকে) =====
        if ($user_id && !empty($course_ids)) {
            if (function_exists('ld_update_course_access')) {
                foreach ($course_ids as $cid) {
                    // Give access (remove=false)
                    ld_update_course_access($user_id, $cid, false);
                }
            } else {
                // If LearnDash is not available: log a normal note
                if (function_exists('wc_get_logger')) {
                    wc_get_logger()->info("LearnDash function missing while binding courses. Order #{$order_id}", ['source' => 'emedi']);
                }
            }
        }

        // ===== 3) ইউজার মেটাতে মেম্বারশিপ ট্র্যাকিং সেভ =====
        // আমরা একটি অ্যারে হিসেবে হিস্টোরি রাখি, যাতে পরের রানে স্ক্রিপ্ট ইভালুয়েট করতে পারে
        if ($user_id) {
            $now = time();
            $expires_at = $duration_days > 0 ? ($now + $duration_days * DAY_IN_SECONDS) : 0;

            $record = [
                'order_id'      => (int) $order_id,
                'product_id'    => (int) $product_id,
                'course_ids'    => $course_ids,
                'duration_days' => $duration_days,
                'start_at'      => $now,
                'expires_at'    => $expires_at, // 0 মানে “মেয়াদহীন”
            ];

            // আগের অ্যারে থাকলে অ্যাপেন্ড; না থাকলে নতুন অ্যারে
            $meta_key = '_emedi_membership_access';
            $existing = get_user_meta($user_id, $meta_key, true);
            if (!is_array($existing)) $existing = [];
            $existing[] = $record;
            update_user_meta($user_id, $meta_key, $existing);

            // চাইলে প্রতিটি প্রোডাক্ট আইডি-ভিত্তিকও স্টোর করতে পারো (সহজ লুকআপের জন্য)
            update_user_meta($user_id, '_emedi_access_' . $product_id, $record);
        } else {
            // গেস্ট কাস্টমার—ইউজার মেটা সেভ করা যাবে না, অর্ডার নোট যোগ করি
            $order->add_order_note(__('EMEDI: Guest checkout – could not store membership meta. Consider creating an account.', 'emedi'));
        }

        // ===== 4) প্রোডাক্টে bind ফ্ল্যাগ বসাও (ডিলিট থেকে প্রটেক্ট) =====
        update_post_meta($product_id, '_emedi_bound_order_id', $order_id);
        update_post_meta($product_id, '_emedi_temp', '0');
        update_post_meta($product_id, '_emedi_bound_at', time());
    }
}


}
// call the Emedi_Helper class
new Emedi_Helper();




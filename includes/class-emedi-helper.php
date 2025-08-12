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
        // Hook this once (e.g., in your class constructor)
        add_filter('learndash_profile_stats', [$this, 'add_membership_stats'], 20, 2);



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
        // --- Step 0: Basic guards & order fetch ---
        error_log('[EMEDI] bind_temp_products_to_order: START | order_id=' . print_r($order_id, true));
        if (empty($order_id)) {
            error_log('[EMEDI] Aborting: Empty $order_id');
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('[EMEDI] Aborting: wc_get_order() returned null for order_id=' . $order_id);
            return;
        }

        // --- Step 1: Resolve user ID (prefer order->get_user_id; fallback by billing email) ---
        $user_id = (int) $order->get_user_id();
        error_log('[EMEDI] Resolved initial user_id from order: ' . $user_id);

        if (!$user_id) {
            $billing_email = $order->get_billing_email();
            error_log('[EMEDI] No user_id on order. Billing email=' . $billing_email);
            if ($billing_email) {
                $user_obj = get_user_by('email', $billing_email);
                if ($user_obj) {
                    $user_id = (int) $user_obj->ID;
                    error_log('[EMEDI] Fallback user resolved by email. user_id=' . $user_id);
                } else {
                    error_log('[EMEDI] Could not resolve user by billing email.');
                }
            }
        }

        // --- Step 2: Iterate line items ---
        $processed_any = false;
        foreach ($order->get_items('line_item') as $item) {
            $product_id = $item->get_product_id();
            error_log('[EMEDI] Processing line item. product_id=' . print_r($product_id, true));

            if (!$product_id) {
                error_log('[EMEDI] Skip: Empty product_id on line item.');
                continue;
            }

            // 2.a: Skip if product already bound to an order
            $already_bound = get_post_meta($product_id, '_emedi_bound_order_id', true);
            if (!empty($already_bound)) {
                error_log('[EMEDI] Skip: Product already bound. _emedi_bound_order_id=' . $already_bound);
                continue;
            }

            // 2.b: Read product meta for course IDs and duration (days)
            $raw_courses = get_post_meta($product_id, '_course_ids', true);
            $duration_months = (int) get_post_meta($product_id, '_duration', true);
            $duration_days = $duration_months * 30; // Approximate conversion

            error_log('[EMEDI] Raw meta _course_ids=' . (is_array($raw_courses) ? json_encode($raw_courses) : print_r($raw_courses, true)));
            error_log('[EMEDI] Raw meta _duration(days)=' . $duration_days);

            // 2.c: Normalize course IDs (array | JSON string | comma-separated string)
            $course_ids = [];
            if (is_array($raw_courses)) {
                $course_ids = $raw_courses;
            } elseif (is_string($raw_courses) && $raw_courses !== '') {
                $decoded = json_decode($raw_courses, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $course_ids = $decoded;
                } else {
                    $course_ids = array_map('trim', explode(',', $raw_courses));
                }
            }
            // Cast to int, remove empties/duplicates
            $course_ids = array_values(array_unique(array_filter(array_map('intval', (array) $course_ids))));
            if ($duration_days < 0)
                $duration_days = 0;

            error_log('[EMEDI] Normalized course_ids=' . json_encode($course_ids));
            error_log('[EMEDI] Normalized duration_days=' . $duration_days);

            // --- Step 3: Grant LearnDash course access (if user exists & courses available) ---
            if ($user_id && !empty($course_ids)) {
                if (function_exists('ld_update_course_access')) {
                    foreach ($course_ids as $cid) {
                        // Grant access: remove=false
                        ld_update_course_access($user_id, $cid, false);
                        error_log('[EMEDI] LearnDash access granted | user_id=' . $user_id . ' | course_id=' . $cid);
                    }
                } else {
                    error_log('[EMEDI] ERROR: LearnDash function ld_update_course_access() not found. Cannot grant access.');
                }
            } else {
                error_log('[EMEDI] Skipping LearnDash grant: user_id=' . $user_id . ' | courses_count=' . count($course_ids));
            }

            // --- Step 4: Persist membership tracking in user meta (hidden) ---
            if ($user_id) {
                $now = time();
                $expires_at = $duration_days > 0 ? ($now + ($duration_days * DAY_IN_SECONDS)) : 0;
                update_user_meta($user_id, 'membership_start', $now);
                update_user_meta($user_id, 'membership_duration_days', $duration_days);
                update_user_meta($user_id, 'membership_expires_at', $expires_at);
            } else {
                // Guest checkout: cannot store user meta
                $order->add_order_note(__('EMEDI: Guest checkout – membership meta not stored (no user_id).', 'emedi'));
                error_log('[EMEDI] Guest checkout: membership meta NOT stored (no user_id).');
            }

            // --- Step 5: Bind product to this order to protect from cleanup ---
            update_post_meta($product_id, '_emedi_bound_order_id', $order_id);
            update_post_meta($product_id, '_emedi_temp', '0');
            update_post_meta($product_id, '_emedi_bound_at', time());

            error_log('[EMEDI] Product bound & protected | product_id=' . $product_id . ' | order_id=' . $order_id);

            $processed_any = true;
        }

        // --- Step 6: Final log ---
        if ($processed_any) {
            error_log('[EMEDI] bind_temp_products_to_order: DONE | order_id=' . $order_id);
        } else {
            error_log('[EMEDI] bind_temp_products_to_order: DONE (no items processed) | order_id=' . $order_id);
        }
    }


    // Always keep code comments in English
    public function add_membership_stats($stats, $user_id)
    {
        // Ensure $stats is an array
        if (!is_array($stats)) {
            $stats = [];
        }
        $now = time(); // Current timestamp 

        // Get membership data from user meta
        $membership_start = (int) get_user_meta($user_id, 'membership_start', true);
        $membership_duration_days = (int) get_user_meta($user_id, 'membership_duration_days', true);
        error_log('[EMEDI] Membership data for user_id=' . $user_id . ' | start=' . $membership_start . ' | duration_days=' . $membership_duration_days);

        $status_class = 'is-expired';
        if ($membership_duration_days <= 0) {
            $label_value = __('You Have to purchase a membership First', 'emedi');
            $this->unenroll_user_from_courses($user_id);
        } else {
            $running_time = $now - $membership_start; // seconds
            $running_days = floor($running_time / DAY_IN_SECONDS); // convert to days
            $is_expire = $running_days >= $membership_duration_days;
            $days_left = $membership_duration_days - $running_days;

            // Build human label
            if (!$is_expire) {
                $label_value = sprintf(
                    _n('Your Access Have %d day left', 'Your Access have %d days left', $days_left, 'emedi'),
                    $days_left
                );
                if ($days_left <= 3) {
                    $status_class = 'is-expiring';   // 3 days or less
                } else {
                    $status_class = 'is-active';
                }
            } else {
                $label_value = __('Your Membership Has Been Expired', 'emedi');
                //unenroll from all courses
                $this->unenroll_user_from_courses($user_id);
            }
        }

        // Create the stat array
        $access_stat = [
            'label' => __('Access', 'emedi'),  //
            'value' => $label_value,
            'class' => 'ld-profile-stat-emedi-access ' . $status_class,
        ];

        // Prepend at the beginning of stats array
        array_unshift($stats, $access_stat);


        return $stats;
    }

 
    private function unenroll_user_from_courses($user_id)
    {
        // Log start
        error_log("[EMEDI] Starting unenrollment for user_id={$user_id}");

        if (empty($user_id)) {
            error_log("[EMEDI] No user_id provided. Exiting unenroll.");
            return;
        }

        // Get all enrolled courses for this user
        $enrolled_courses = ld_get_mycourses($user_id);

        if (empty($enrolled_courses)) {
            error_log("[EMEDI] No enrolled courses found for user_id={$user_id}");
            return;
        } 
        // Loop through each enrolled course and remove access
        foreach ($enrolled_courses as $course_id) {
            ld_update_course_access($user_id, $course_id, true); // true = remove
            error_log("[EMEDI] Unenrolled user_id={$user_id} from course_id={$course_id}");
        }

        error_log("[EMEDI] Completed unenrollment for user_id={$user_id}");
    }

}

// call the Emedi_Helper class
new Emedi_Helper();




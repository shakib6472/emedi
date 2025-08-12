<?php
/*
 * Plugin Name:      Emedi Helper Plugin
 * Plugin URI:        https://github.com/shakib6472/
 * Description:       This is a helper plugin for the Emedi website.
 * Version:           1.0.0
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
function emedi_activate() {
    // Activation code here
}
register_deactivation_hook(__FILE__, 'emedi_deactivate');
function emedi_deactivate() {
    // Deactivation code here
}

include_once plugin_dir_path(__FILE__) . 'includes/class-emedi-helper.php';
include_once plugin_dir_path(__FILE__) . 'includes/Emedi_Temp_Product_Cleaner.php';



const EMEDI_HOOK = 'emedi_test_logger';

// 1) প্রতি ১ মিনিটের কাস্টম ইন্টারভ্যাল
add_filter('cron_schedules', function ($s) {
    $s['every_minute'] = [
        'interval' => 60,          // 60 সেকেন্ড
        'display'  => 'Every Minute'
    ];
    return $s;
});

// 2) প্লাগিন লোডে Auto-(re)Schedule + minute-align
add_action('plugins_loaded', function () {
    // আগে থেকে শিডিউল আছে কি না দেখি
    $evt = wp_get_scheduled_event(EMEDI_HOOK);

    // minute align: 12:00, 12:01, 12:02 ... + ছোট অফসেট
    $aligned_start = floor(time() / 60) * 60 + 5;  // এখনকার মিনিট শুরু +5s

    if (!$evt) {
        // একদম নেই => নতুন করে সেট করুন
        wp_schedule_event($aligned_start, 'every_minute', EMEDI_HOOK);
        return;
    }

    // যদি ভুল ইন্টারভ্যাল/ড্রিফ্ট ধরা পড়ে, রিশিডিউল করুন
    if ($evt->schedule !== 'every_minute' || ($evt->timestamp % 60) !== 5) {
        wp_unschedule_event($evt->timestamp, EMEDI_HOOK, $evt->args);
        wp_schedule_event($aligned_start, 'every_minute', EMEDI_HOOK);
    }
});

// 3) জব: লগে লিখবে
add_action(EMEDI_HOOK, function () {
    error_log('EMEDI TEST LOGGER: run at ' . current_time('mysql'));
});

// 4) (ঐচ্ছিক) ডিঅ্যাকটিভেশনে পরিষ্কার
register_deactivation_hook(__FILE__, function () {
    if ($evt = wp_get_scheduled_event(EMEDI_HOOK)) {
        wp_unschedule_event($evt->timestamp, EMEDI_HOOK, $evt->args);
    }
});

 
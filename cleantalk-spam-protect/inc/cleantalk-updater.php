<?php

use Cleantalk\ApbctWP\Cron;
use Cleantalk\ApbctWP\Helper;
use Cleantalk\ApbctWP\Variables\Server;
use Cleantalk\ApbctWP\Firewall\SFWUpdateHelper;
use Cleantalk\Common\TT;

// Prevent direct call
if ( ! defined('ABSPATH') ) {
    die('Not allowed!');
}

/**
 * Main function to compare versions and run necessary update functions.
 *
 * @param string $current_version
 * @param string $new_version
 *
 * @return bool
 *
 * @psalm-suppress PossiblyUndefinedIntArrayOffset
 */

function apbct_run_update_actions($current_version, $new_version)
{
    global $apbct;
    $need_start_update_sfw = false;

    $apbct->stats['plugin']['plugin_is_being_updated'] = 1;
    $apbct->save('stats');

    $current_version_arr = apbct_version_standardization($current_version);
    $new_version_arr     = apbct_version_standardization($new_version);

    $current_version_str = implode('.', $current_version_arr);
    $new_version_str     = implode('.', $new_version_arr);

    $db_analyzer = new \Cleantalk\ApbctWP\UpdatePlugin\DbAnalyzer();

    // Create not exists tables
    if ($db_analyzer->getNotExistsTables()) {
        foreach ($db_analyzer->getNotExistsTables() as $table) {
            // Checking whether to run the SFW update
            if (substr($table, -3) === 'sfw') {
                $need_start_update_sfw = true;
            }

            $db_tables_creator = new \Cleantalk\ApbctWP\UpdatePlugin\DbTablesCreator();
            $db_tables_creator->createTable($table);
        }
    }

    // Analyze exists tables, update columns
    if ($db_analyzer->getExistsTables()) {
        foreach ($db_analyzer->getExistsTables() as $table_name) {
            $db_column_creator = new \Cleantalk\ApbctWP\UpdatePlugin\DbColumnCreator($table_name);
            $db_column_creator->execute();

            if ($db_column_creator->getTableChangedStatus()) {
                // Checking whether to run the SFW update
                if (substr($table_name, -3) === 'sfw') {
                    $need_start_update_sfw = true;
                }
            }
        }
    }

    for ($ver_major = $current_version_arr[0]; $ver_major <= $new_version_arr[0]; $ver_major++) {
        for ($ver_minor = 0; $ver_minor <= 300; $ver_minor++) {
            for ($ver_fix = 0; $ver_fix <= 10; $ver_fix++) {
                if (version_compare("{$ver_major}.{$ver_minor}.{$ver_fix}", $current_version_str, '<=')) {
                    continue;
                }

                if (function_exists("apbct_update_to_{$ver_major}_{$ver_minor}_{$ver_fix}")) {
                    $result = call_user_func("apbct_update_to_{$ver_major}_{$ver_minor}_{$ver_fix}");
                    if (!empty($result['error'])) {
                        break;
                    }
                }

                if ($ver_fix == 0 && function_exists("apbct_update_to_{$ver_major}_{$ver_minor}")) {
                    $result = call_user_func("apbct_update_to_{$ver_major}_{$ver_minor}");
                    if (!empty($result['error'])) {
                        break;
                    }
                }

                if (version_compare("{$ver_major}.{$ver_minor}.{$ver_fix}", $new_version_str, '>=')) {
                    break( 2 );
                }
            }
        }
    }

    //run automatic default stats filling
    $apbct->runAutoSaveStateVars();

    // Start SFW update
    if ($need_start_update_sfw) {
        apbct_sfw_update__init();
    }

    return true;
}

/**
 * Convert string version to an array
 *
 * @param string $version
 *
 * @return array
 */
function apbct_version_standardization($version)
{
    $parsed_version = explode('.', $version);

    $parsed_version[0] = ! empty($parsed_version[0]) ? (int)$parsed_version[0] : 0;
    $parsed_version[1] = ! empty($parsed_version[1]) ? (int)$parsed_version[1] : 0;
    $parsed_version[2] = ! empty($parsed_version[2]) ? (int)$parsed_version[2] : 0;

    return $parsed_version;
}

/**
 * @return void
 */
function apbct_update_to_5_56_0()
{
    if ( ! wp_next_scheduled('cleantalk_update_sfw_hook') ) {
        wp_schedule_event(time() + 1800, 'daily', 'cleantalk_update_sfw_hook');
    }
}

/**
 * @return void
 */
function apbct_update_to_5_70_0()
{
    // Deleting usless data
    delete_option('cleantalk_sends_reports_till');
    delete_option('cleantalk_activation_timestamp');

    // Disabling WP_Cron tasks
    wp_clear_scheduled_hook('cleantalk_send_daily_report_hook');
    wp_clear_scheduled_hook('ct_hourly_event_hook');
    wp_clear_scheduled_hook('ct_send_sfw_log');
    wp_clear_scheduled_hook('cleantalk_update_sfw_hook');
    wp_clear_scheduled_hook('cleantalk_get_brief_data_hook');

    // Adding Self cron system tasks
    $cron = new Cron();
    $cron->addTask('check_account_status', 'ct_account_status_check', 3600, time() + 1800); // New
    $cron->addTask('delete_spam_comments', 'ct_delete_spam_comments', 3600, time() + 3500);
    $cron->addTask('send_feedback', 'ct_send_feedback', 3600, time() + 3500);
    $cron->addTask('sfw_update', 'apbct_sfw_update__init', 86400, time() + 43200);
    $cron->addTask('send_sfw_logs', 'ct_sfw_send_logs', 3600, time() + 1800); // New
    $cron->addTask('get_brief_data', 'cleantalk_get_brief_data', 86400, time() + 3500);
}

/**
 * @return void
 */
function apbct_update_to_5_74_0()
{
    $cron = new Cron();
    $cron->removeTask('send_daily_request');
}

/**
 * @return void
 */
function apbct_update_to_5_97_0()
{
    global $apbct;

    if ( isset($apbct->data['connection_reports']['negative_report'])
        && is_array($apbct->data['connection_reports']['negative_report'])
        && count($apbct->data['connection_reports']['negative_report']) >= 20 ) {
        $apbct->data['connection_reports']['negative_report'] = array_slice(
            $apbct->data['connection_reports']['negative_report'],
            -20,
            20
        );
    }

    $apbct->saveData();
}

/**
 * @return void
 */
function apbct_update_to_5_109_0()
{
    global $apbct, $wpdb;

    if (apbct_is_plugin_active_for_network($apbct->base_name) && !defined('CLEANTALK_ACCESS_KEY')) {
        $initial_blog  = get_current_blog_id();
        $blogs = array_keys($wpdb->get_results('SELECT blog_id FROM ' . $wpdb->blogs, OBJECT_K));

        foreach ($blogs as $blog) {
            switch_to_blog($blog);
            // Cron tasks
            $cron = new Cron();
            $cron->addTask(
                'check_account_status',
                'ct_account_status_check',
                3600,
                time() + 1800
            ); // Checks account status
            $cron->addTask(
                'delete_spam_comments',
                'ct_delete_spam_comments',
                3600,
                time() + 3500
            ); // Formerly ct_hourly_event_hook()
            $cron->addTask('send_feedback', 'ct_send_feedback', 3600, time() + 3500); // Formerly ct_hourly_event_hook()
            $cron->addTask('sfw_update', 'apbct_sfw_update__init', 86400, time() + 300);  // SFW update
            $cron->addTask('send_sfw_logs', 'ct_sfw_send_logs', 3600, time() + 1800); // SFW send logs
            $cron->addTask(
                'get_brief_data',
                'cleantalk_get_brief_data',
                86400,
                time() + 3500
            ); // Get data for dashboard widget
            $cron->addTask(
                'send_connection_report',
                'ct_mail_send_connection_report',
                86400,
                time() + 3500
            ); // Send connection report to welcome@cleantalk.org
        }

        switch_to_blog($initial_blog);
    }
}

/**
 * @return void
 */
function apbct_update_to_5_110_0()
{
    global $apbct;
    unset($apbct->data['last_remote_call']);
    $apbct->saveData();
    $apbct->save('remote_calls');
}

/**
 * @return void
 */
function apbct_update_to_5_116_0()
{
    global $apbct;

    $apbct->settings['store_urls'] = 0;
    $apbct->settings['store_urls__sessions'] = 0;
    $apbct->saveSettings();
}

/**
 * @return void
 */
function apbct_update_to_5_118_0()
{
    delete_option('cleantalk_server');
}

/**
 * @return void
 */
function apbct_update_to_5_118_2()
{
    global $apbct;

    if ( isset($apbct->data['connection_reports'], $apbct->data['connection_reports']['since'], $apbct->default_data['connection_reports']) ) {
        $apbct->data['connection_reports']          = $apbct->default_data['connection_reports'];
        $apbct->data['connection_reports']['since'] = date('d M');
        $apbct->saveData();
    }
}

/**
 * @return void
 */
function apbct_update_to_5_119_0()
{
    // Drop work url
    update_option(
        'cleantalk_server',
        array(
            'ct_work_url'       => null,
            'ct_server_ttl'     => 0,
            'ct_server_changed' => 0,
        )
    );
}

/**
 * @return void
 */
function apbct_update_to_5_124_0()
{
    global $apbct;
    // Deleting error in database because format were changed
    $apbct->errors = array();
    $apbct->saveErrors();
}

/**
 * @return void
 */
function apbct_update_to_5_126_0()
{
    global $apbct;
    // Enable storing URLs
    $apbct->settings['store_urls']           = 1;
    $apbct->settings['store_urls__sessions'] = 1;
    $apbct->saveSettings();
}

/**
 * @return void
 */
function apbct_update_to_5_127_0()
{
    global $apbct, $wpdb;

    // Move exclusions from variable to settins
    global $cleantalk_url_exclusions, $cleantalk_key_exclusions;

    // URLs
    if (!empty($cleantalk_url_exclusions) && is_array($cleantalk_url_exclusions)) {
        $apbct->settings['exclusions__urls'] = implode(',', $cleantalk_url_exclusions);
        if (APBCT_WPMS) {
            $initial_blog = get_current_blog_id();
            switch_to_blog(1);
            $apbct->saveSettings();
            switch_to_blog($initial_blog);
        } else {
            $apbct->saveSettings();
        }
    }
    // Fields
    if (!empty($cleantalk_key_exclusions) && is_array($cleantalk_key_exclusions)) {
        $apbct->settings['exclusions__fields'] = implode(',', $cleantalk_key_exclusions);
        if (APBCT_WPMS) {
            $initial_blog = get_current_blog_id();
            switch_to_blog(1);
            $apbct->saveSettings();
            switch_to_blog($initial_blog);
        } else {
            $apbct->saveSettings();
        }
    }

    // Deleting legacy
    if (isset($apbct->data['testing_failed'])) {
        unset($apbct->data['testing_failed']);
        $apbct->saveData();
    }

    if (APBCT_WPMS) {
        // Whitelabel
        // Reset "api_key_is_received" flag
        $initial_blog = get_current_blog_id();
        $blogs        = array_keys($wpdb->get_results('SELECT blog_id FROM ' . $wpdb->blogs, OBJECT_K));
        foreach ($blogs as $blog) {
            switch_to_blog($blog);

            $settings = get_option('cleantalk_settings');
            if (isset($settings['use_static_js_key'])) {
                $settings['use_static_js_key'] = $settings['use_static_js_key'] === 0
                    ? -1
                    : $settings['use_static_js_key'];
                update_option('cleantalk_settings', $settings);

                $data = get_option('cleantalk_data');
                if (isset($data['white_label_data']['is_key_recieved'])) {
                    unset($data['white_label_data']['is_key_recieved']);
                    update_option('cleantalk_data', $data);
                }
            }
            switch_to_blog($initial_blog);

            if (defined('APBCT_WHITELABEL')) {
                $apbct->network_settings = array(
                    'white_label'              => defined('APBCT_WHITELABEL') && APBCT_WHITELABEL == true ? 1 : 0,
                    'white_label__plugin_name' => defined('APBCT_WHITELABEL_NAME') ? APBCT_WHITELABEL_NAME : APBCT_NAME,
                );
            } elseif (defined('CLEANTALK_ACCESS_KEY')) {
                $apbct->network_settings = array(
                    'allow_custom_key' => 0,
                    'apikey'           => CLEANTALK_ACCESS_KEY,
                );
            }
            $apbct->saveNetworkSettings();
        }
    } else {
        // Switch data__use_static_js_key to Auto if it was disabled
        $apbct->settings['data__use_static_js_key'] = $apbct->settings['data__use_static_js_key'] === 0
            ? -1
            : $apbct->settings['data__use_static_js_key'];
        $apbct->saveSettings();
    }
}

/**
 * @return void
 */
function apbct_update_to_5_127_1()
{
    global $apbct;

    if (APBCT_WPMS && is_main_site()) {
        $network_settings = get_site_option('cleantalk_network_settings');
        if ($network_settings !== false && empty($network_settings['allow_custom_key']) && empty($network_settings['white_label'])) {
            $network_settings['allow_custom_key'] = 1;
            update_site_option('cleantalk_network_settings', $network_settings);
        }
        if ( $network_settings !== false && $network_settings['white_label'] == 1 && $apbct->data['moderate'] == 0 ) {
            ct_account_status_check(
                $network_settings['apikey'] ? $network_settings['apikey'] : $apbct->settings['apikey'],
                false
            );
        }
    } elseif ( is_main_site() ) {
        ct_account_status_check(
            $apbct->settings['apikey'],
            false
        );
    }
}

/**
 * @return void
 */
function apbct_update_to_5_128_0()
{
    global $apbct;
    $apbct->remote_calls = array();
    $apbct->save('remote_calls');
}

/**
 * @return void
 *
 * @psalm-suppress PossiblyUndefinedStringArrayOffset
 */
function apbct_update_to_5_138_0()
{
    global $wpdb;

    // Actions for WPMS
    if (APBCT_WPMS) {
        // Getting all blog ids
        $initial_blog  = get_current_blog_id();
        $blogs = $wpdb->get_results('SELECT blog_id FROM ' . $wpdb->blogs, OBJECT_K);
        $blogs_ids = array_keys($blogs);

        // Getting main blog setting
        switch_to_blog(1);
        $main_blog_settings = get_option('cleantalk_settings');
        switch_to_blog($initial_blog);

        // Getting network settings
        $net_settings = get_site_option('cleantalk_network_settings');

        foreach ($blogs_ids as $blog) {
            // Update time limit to prevent exec time error
            set_time_limit(20);

            switch_to_blog($blog);

            // Getting Access key
            $settings = $net_settings['allow_custom_key']
                ? get_option('cleantalk_settings')
                : $main_blog_settings;

            // Update plugin status
            if (! empty($settings['apikey'])) {
                $data = get_option('cleantalk_data', array());

                $result = \Cleantalk\ApbctWP\API::methodNoticePaidTill(
                    $settings['api_key'],
                    preg_replace('/http[s]?:\/\//', '', get_option('home'), 1),
                    ! is_main_site() && $net_settings['white_label'] ? 'anti-spam-hosting' : 'antispam'
                );

                if (empty($result['error']) || ! empty($result['valid'])) {
                    // Notices
                    $data['notice_show']        = isset($result['show_notice'])             ? (int)$result['show_notice']             : 0;
                    $data['notice_renew']       = isset($result['renew'])                   ? (int)$result['renew']                   : 0;
                    $data['notice_trial']       = isset($result['trial'])                   ? (int)$result['trial']                   : 0;
                    $data['notice_review']      = isset($result['show_review'])             ? (int)$result['show_review']             : 0;

                    // Other
                    $data['service_id']         = isset($result['service_id'])                         ? (int)$result['service_id']         : 0;
                    $data['valid']              = isset($result['valid'])                              ? (int)$result['valid']              : 0;
                    $data['moderate']           = isset($result['moderate'])                           ? (int)$result['moderate']           : 0;
                    $data['ip_license']         = isset($result['ip_license'])                         ? (int)$result['ip_license']         : 0;
                    $data['moderate_ip']        = isset($result['moderate_ip'], $result['ip_license']) ? (int)$result['moderate_ip']        : 0;
                    $data['spam_count']         = isset($result['spam_count'])                         ? (int)$result['spam_count']         : 0;
                    $data['user_token']         = isset($result['user_token'])                         ? (string)$result['user_token']      : '';
                    $data['license_trial']      = isset($result['license_trial'])                      ? (int)$result['license_trial']      : 0;
                    $data['account_name_ob']    = isset($result['account_name_ob'])                    ? (string)$result['account_name_ob'] : '';
                }

                $data['key_is_ok'] = ! empty($result['valid'])
                    ? true
                    : false;

                update_option('cleantalk_data', $data);
            }
        }

        // Restoring initial blog
        switch_to_blog($initial_blog);
    }
}

/**
 * @return void
 */
function apbct_update_to_5_146_3()
{
    update_option('cleantalk_plugin_request_ids', array());
}

/**
 * @return void
 */
function apbct_update_to_5_148_0()
{
    $cron = new Cron();
    $cron->updateTask('antiflood__clear_table', 'apbct_antiflood__clear_table', 86400);
}

/**
 * @return void
 */
function apbct_update_to_5_150_0()
{
    global $wpdb;

    // Actions for WPMS
    if (APBCT_WPMS) {
        // Getting all blog ids
        $initial_blog = get_current_blog_id();
        $blogs        = array_keys($wpdb->get_results('SELECT blog_id FROM ' . $wpdb->blogs, OBJECT_K));

        foreach ($blogs as $blog) {
            switch_to_blog($blog);

            update_option('cleantalk_plugin_request_ids', array());
        }

        // Restoring initial blog
        switch_to_blog($initial_blog);
    }
}

/**
 * @return void
 */
function apbct_update_to_5_151_1()
{
    global $apbct;
    $apbct->fw_stats['firewall_updating_id']         = isset($apbct->data['firewall_updating_id'])
        ? $apbct->data['firewall_updating_id']
        : '';
    $apbct->fw_stats['firewall_update_percent']      = isset($apbct->data['firewall_update_percent'])
        ? $apbct->data['firewall_update_percent']
        : 0;
    $apbct->fw_stats['firewall_updating_last_start'] = isset($apbct->data['firewall_updating_last_start'])
        ? $apbct->data['firewall_updating_last_start']
        : 0;
    $apbct->save('fw_stats');
}

/**
 * @return void
 * @throws Exception
 */
function apbct_update_to_5_151_3()
{
    global $apbct;

    $apbct->fw_stats['firewall_updating_last_start'] = 0;
    $apbct->save('fw_stats');
    $apbct->stats['sfw']['entries'] = 0;
    $apbct->save('stats');
}

/**
 * @return void
 */
function apbct_update_to_5_151_6()
{
    global $apbct;
    $apbct->errorDelete('sfw_update', true);
}

/**
 * @return void
 */
function apbct_update_to_5_153_4()
{
    // Adding cooldown to sending SFW logs
    global $apbct;
    $apbct->stats['sfw']['sending_logs__timestamp'] = 0;
    $apbct->save('stats');
}

/**
 * @return void
 */
function apbct_update_to_5_154_0()
{
    global $apbct, $wpdb;

    // Old setting name => New setting name
    $keys_map = array(
        'spam_firewall'                  => 'sfw__enabled',
        'registrations_test'             => 'forms__registrations_test',
        'comments_test'                  => 'forms__comments_test',
        'contact_forms_test'             => 'forms__contact_forms_test',
        'general_contact_forms_test'     => 'forms__general_contact_forms_test',
        'wc_checkout_test'               => 'forms__wc_checkout_test',
        'wc_register_from_order'         => 'forms__wc_register_from_order',
        'search_test'                    => 'forms__search_test',
        'check_external'                 => 'forms__check_external',
        'check_external__capture_buffer' => 'forms__check_external__capture_buffer',
        'check_internal'                 => 'forms__check_internal',
        'disable_comments__all'          => 'comments__disable_comments__all',
        'disable_comments__posts'        => 'comments__disable_comments__posts',
        'disable_comments__pages'        => 'comments__disable_comments__pages',
        'disable_comments__media'        => 'comments__disable_comments__media',
        'bp_private_messages'            => 'comments__bp_private_messages',
        'check_comments_number'          => 'comments__check_comments_number',
        'remove_old_spam'                => 'comments__remove_old_spam',
        'remove_comments_links'          => 'comments__remove_comments_links',
        'show_check_links'               => 'comments__show_check_links',
        'manage_comments_on_public_page' => 'comments__manage_comments_on_public_page',
        'protect_logged_in'              => 'data__protect_logged_in',
        'use_ajax'                       => 'data__use_ajax',
        'use_static_js_key'              => 'data__use_static_js_key',
        'general_postdata_test'          => 'data__general_postdata_test',
        'set_cookies'                    => 'data__set_cookies',
        'set_cookies__sessions'          => 'data__set_cookies__sessions',
        'ssl_on'                         => 'data__ssl_on',
        'show_adminbar'                  => 'admin_bar__show',
        'all_time_counter'               => 'admin_bar__all_time_counter',
        'daily_counter'                  => 'admin_bar__daily_counter',
        'sfw_counter'                    => 'admin_bar__sfw_counter',
        'gdpr_enabled'                   => 'gdpr__enabled',
        'gdpr_text'                      => 'gdpr__text',
        'collect_details'                => 'misc__collect_details',
        'async_js'                       => 'misc__async_js',
        'debug_ajax'                     => 'misc__debug_ajax',
        'store_urls'                     => 'misc__store_urls',
        'store_urls__sessions'           => 'misc__store_urls__sessions',
        'complete_deactivation'          => 'misc__complete_deactivation',
        'use_buitin_http_api'            => 'wp__use_builtin_http_api',
        'comment_notify'                 => 'wp__comment_notify',
        'comment_notify__roles'          => 'wp__comment_notify__roles',
        'dashboard_widget__show'         => 'wp__dashboard_widget__show',
        'allow_custom_key'               => 'multisite__allow_custom_key',
        'allow_custom_settings'          => 'multisite__allow_custom_settings',
        'white_label'                    => 'multisite__white_label',
        'white_label__plugin_name'       => 'multisite__white_label__plugin_name',
        'use_settings_template'          => 'multisite__use_settings_template',
        'use_settings_template_apply_for_new' => 'multisite__use_settings_template_apply_for_new',
        'use_settings_template_apply_for_current' => 'multisite__use_settings_template_apply_for_current',
        'use_settings_template_apply_for_current_list_sites' => 'multisite__use_settings_template_apply_for_current_list_sites',
    );

    if (is_multisite()) {
        $network_settings = get_site_option('cleantalk_network_settings');

        if ($network_settings) {
            $_network_settings = array();
            // replacing old key to new keys
            foreach ($network_settings as $key => $value) {
                if (array_key_exists($key, $keys_map)) {
                    $_network_settings[$keys_map[$key]] = $value;
                } else {
                    $_network_settings[$key] = $value;
                }
            }
            if (! empty($_network_settings)) {
                update_site_option('cleantalk_network_settings', $_network_settings);
            }
        }

        $initial_blog  = get_current_blog_id();
        $blogs = array_keys($wpdb->get_results('SELECT blog_id FROM ' . $wpdb->blogs, OBJECT_K));
        foreach ($blogs as $blog) {
            switch_to_blog($blog);

            $settings = get_option('cleantalk_settings');

            if ($settings) {
                // replacing old key to new keys
                $_settings = array();
                foreach ($settings as $key => $value) {
                    if (array_key_exists($key, $keys_map)) {
                        $_settings[$keys_map[$key]] = $value;
                    } else {
                        $_settings[$key] = $value;
                    }
                }
                if (! empty($_settings)) {
                    update_option('cleantalk_settings', $_settings);
                }
            }
        }
        switch_to_blog($initial_blog);
    } else {
        $apbct->data['current_settings_template_id'] = null;
        $apbct->data['current_settings_template_name'] = null;
        $apbct->saveData();

        $settings = (array) $apbct->settings;

        if ($settings) {
            $_settings = array();
            // replacing old key to new keys
            foreach ($settings as $key => $value) {
                if (array_key_exists($key, $keys_map)) {
                    $_settings[$keys_map[$key]] = $value;
                } else {
                    $_settings[$key] = $value;
                }
            }

            $apbct->settings = $_settings;
            $apbct->saveSettings();
        }
    }
}

/**
 * @return void
 */
function apbct_update_to_5_156_0()
{
    global $apbct;

    $apbct->remote_calls['debug']     = array( 'last_call' => 0, 'cooldown' => 0 );
    $apbct->remote_calls['debug_sfw'] = array( 'last_call' => 0, 'cooldown' => 0 );
    $apbct->save('remote_calls');

    $cron = new Cron();
    $cron->updateTask('sfw_update', 'apbct_sfw_update__init', 86400, time() + 42300);
}

/**
 * @return void
 */
function apbct_update_to_5_157_0()
{
    global $apbct;

    $apbct->remote_calls['sfw_update__worker'] = array('last_call' => 0, 'cooldown' => 0);
    $apbct->save('remote_calls');

    if (! empty($apbct->settings['data__set_cookies__sessions'])) {
        $apbct->settings['data__set_cookies'] = 2;
    }
    $apbct->data['ajax_type'] = 'rest';

    $apbct->save('settings');
    $apbct->save('data');

    cleantalk_get_brief_data($apbct->api_key);
}

/**
 * @return void
 */
function apbct_update_to_5_158_0()
{
    global $apbct, $wpdb;
    // change name for prevent psalm false positive
    $_wpdb = $wpdb;

    // Update from fix branch
    if (APBCT_WPMS && is_main_site()) {
        $wp_blogs           = $_wpdb->get_results('SELECT blog_id, site_id FROM ' . $_wpdb->blogs, OBJECT_K);
        $current_sites_list = $apbct->settings['multisite__use_settings_template_apply_for_current_list_sites'];

        if (is_array($wp_blogs) && is_array($current_sites_list)) {
            foreach ($wp_blogs as $blog) {
                $blog_details = get_blog_details(array('blog_id' => $blog->blog_id));
                if ($blog_details) {
                    $site_list_index = array_search($blog_details->blogname, $current_sites_list, true);
                    if ($site_list_index !== false) {
                        $current_sites_list[$site_list_index] = $blog_details->id;
                    }
                }
            }
            $apbct->settings['multisite__use_settings_template_apply_for_current_list_sites'] = $current_sites_list;
            $apbct->settings['comments__hide_website_field']                                  = '0';
            $apbct->settings['data__pixel']                                                   = '0';
            $apbct->saveSettings();
        }
    } else {
        $apbct->settings['comments__hide_website_field'] = '0';
        $apbct->settings['data__pixel']                  = '0';
        $apbct->saveSettings();
    }
}

/**
 * @return void
 */
function apbct_update_to_5_158_2()
{
    global $apbct;
    $apbct->stats['cron']['last_start'] = 0;
    $apbct->save('stats');
}

/**
 * @return void
 */
function apbct_update_to_5_159_6()
{
    global $wpdb;

    $ct_cron = new Cron();

    if (is_multisite()) {
        $initial_blog = get_current_blog_id();
        $blogs        = array_keys($wpdb->get_results('SELECT blog_id FROM ' . $wpdb->blogs, OBJECT_K));
        foreach ($blogs as $blog) {
            switch_to_blog($blog);
            // Cron tasks
            $ct_cron->addTask(
                'check_account_status',
                'ct_account_status_check',
                3600,
                time() + 1800
            ); // Checks account status
            $ct_cron->addTask(
                'delete_spam_comments',
                'ct_delete_spam_comments',
                3600,
                time() + 3500
            ); // Formerly ct_hourly_event_hook()
            $ct_cron->addTask(
                'send_feedback',
                'ct_send_feedback',
                3600,
                time() + 3500
            ); // Formerly ct_hourly_event_hook()
            $ct_cron->addTask('sfw_update', 'apbct_sfw_update__init', 86400);  // SFW update
            $ct_cron->addTask('send_sfw_logs', 'ct_sfw_send_logs', 3600, time() + 1800); // SFW send logs
            $ct_cron->addTask(
                'get_brief_data',
                'cleantalk_get_brief_data',
                86400,
                time() + 3500
            ); // Get data for dashboard widget
            $ct_cron->addTask(
                'send_connection_report',
                'ct_mail_send_connection_report',
                86400,
                time() + 3500
            ); // Send connection report to welcome@cleantalk.org
            $ct_cron->addTask(
                'antiflood__clear_table',
                'apbct_antiflood__clear_table',
                86400,
                time() + 300
            ); // Clear Anti-Flood table
        }
        switch_to_blog($initial_blog);
    } else {
        // Cron tasks
        $ct_cron->addTask(
            'check_account_status',
            'ct_account_status_check',
            3600,
            time() + 1800
        ); // Checks account status
        $ct_cron->addTask(
            'delete_spam_comments',
            'ct_delete_spam_comments',
            3600,
            time() + 3500
        ); // Formerly ct_hourly_event_hook()
        $ct_cron->addTask('send_feedback', 'ct_send_feedback', 3600, time() + 3500); // Formerly ct_hourly_event_hook()
        $ct_cron->addTask('sfw_update', 'apbct_sfw_update__init', 86400);  // SFW update
        $ct_cron->addTask('send_sfw_logs', 'ct_sfw_send_logs', 3600, time() + 1800); // SFW send logs
        $ct_cron->addTask(
            'get_brief_data',
            'cleantalk_get_brief_data',
            86400,
            time() + 3500
        ); // Get data for dashboard widget
        $ct_cron->addTask(
            'send_connection_report',
            'ct_mail_send_connection_report',
            86400,
            time() + 3500
        ); // Send connection report to welcome@cleantalk.org
        $ct_cron->addTask(
            'antiflood__clear_table',
            'apbct_antiflood__clear_table',
            86400,
            time() + 300
        ); // Clear Anti-Flood table
    }
}

/**
 * @return  void
 */
function apbct_update_to_5_159_9()
{
    $cron = new Cron();
    $cron->addTask('rotate_moderate', 'apbct_rotate_moderate', 86400, time() + 3500); // Rotate moderate server
}

/**
 * @return  void
 */
function apbct_update_to_5_160_4()
{
    global $apbct;

    $apbct->settings['sfw__random_get'] = '1';
    $apbct->saveSettings();

    SFWUpdateHelper::removeUpdFolder(APBCT_DIR_PATH . '/fw_files');

    if ($apbct->is_multisite) {
        $apbct->network_settings = array_merge((array)$apbct->network_settings, $apbct->default_network_settings);
        $apbct->save('network_settings');
    }

    SFWUpdateHelper::removeUpdFolder(ABSPATH . '/wp-admin/fw_files');
    $root_path = Server::get('DOCUMENT_ROOT') ? Server::get('DOCUMENT_ROOT') : ABSPATH;
    $root_path = is_array($root_path) ? reset($root_path) : $root_path;
    $root_path = $root_path === false ? ABSPATH : $root_path;
    SFWUpdateHelper::removeUpdFolder($root_path . '/fw_files');
    $base_path = rtrim(ABSPATH, '/');
    $file_path = $base_path . '/fw_filesindex.php';
    if (strpos($file_path, $base_path) === 0 && is_file($file_path) && is_writable($file_path)) {
        if (!unlink($file_path)) {
            error_log('Failed to delete file: ' . $file_path);
        }
    }
}

function apbct_update_to_5_161_1()
{
    global $apbct;

    if ($apbct->is_multisite) {
        $apbct->network_settings = array_merge((array)$apbct->network_settings, $apbct->default_network_settings);
        // Migrate old WPMS to the new wpms mode
        if ( isset($apbct->network_settings['multisite__allow_custom_key']) ) {
            if ( $apbct->network_settings['multisite__allow_custom_key'] == 1 ) {
                $apbct->network_settings['multisite__work_mode'] = 1;
            } else {
                $apbct->network_settings['multisite__work_mode'] = 2;
            }
        }
        $apbct->saveNetworkSettings();
    }
}

function apbct_update_to_5_161_2()
{
    global $apbct;
    // Set type of the alt cookies
    if ($apbct->settings['data__set_cookies'] == 2) {
        // Check rest availability
        $res_rest = Helper::httpRequestGetResponseCode(esc_url(apbct_get_rest_url()));
        if ($res_rest != 200) {
            // Check WP ajax availability
            $res_ajax = Helper::httpRequestGetResponseCode(admin_url('admin-ajax.php'));
            if ($res_ajax != 400) {
                // There is no available alt cookies types. Cookies will be disabled.
                $apbct->settings['data__set_cookies'] = 0;
            } else {
                $apbct->data['ajax_type'] = 'admin_ajax';
            }
        } else {
            $apbct->data['ajax_type'] = 'rest';
        }
        $apbct->saveSettings();
        $apbct->saveData();
    }
}

/**
 * 5.162
 */
function apbct_update_to_5_162_0()
{
    global $apbct;

    $apbct->settings['forms__wc_honeypot'] = '1';
    $apbct->saveSettings();
}

/**
 * 5.162.1
 */
function apbct_update_to_5_162_1()
{
    global $apbct;

    if (
        ! isset($apbct->stats['sfw']['update_period']) ||
        (isset($apbct->stats['sfw']['update_period']) && $apbct->stats['sfw']['update_period'] == 0)
    ) {
        $apbct->stats['sfw']['update_period'] = 14400;
        $apbct->save('stats');
    }

    // Set type of the AJAX handler for the ajax js
    if ( $apbct->settings['data__use_ajax'] == 1 ) {
        // Check rest availability
        $res_rest = Helper::httpRequestGetResponseCode(esc_url(apbct_get_rest_url()));
        if ($res_rest != 200) {
            // Check WP ajax availability
            $res_ajax = Helper::httpRequestGetResponseCode(admin_url('admin-ajax.php'));
            if ($res_ajax != 400) {
                // There is no available alt cookies types. Cookies will be disabled.
                $apbct->settings['data__use_ajax'] = 0;
            } else {
                $apbct->data['ajax_type'] = 'admin_ajax';
            }
        } else {
            $apbct->data['ajax_type'] = 'rest';
        }
        $apbct->saveSettings();
        $apbct->saveData();
    }

    // Migrate old WPMS to the new wpms mode
    if ( isset($apbct->network_settings['multisite__allow_custom_key']) ) {
        if ( $apbct->network_settings['multisite__allow_custom_key'] == 1 ) {
            $apbct->network_settings['multisite__work_mode'] = 1;
        } else {
            $apbct->network_settings['multisite__work_mode'] = 2;
        }
        $apbct->saveNetworkSettings();
    }
}

/**
 * 5.164
 */
function apbct_update_to_5_164_0()
{
    global $apbct;

    $alt_cookies_type = isset($apbct->settings['data__set_cookies__alt_sessions_type'])
        ? $apbct->settings['data__set_cookies__alt_sessions_type']
        : false;

    switch ((int)$alt_cookies_type) {
        case 0:
            $alt_cookies_type = 'rest';
            break;
        case 1:
            $alt_cookies_type = 'custom_ajax';
            break;
        case 2:
            $alt_cookies_type = 'admin_ajax';
            break;
    }

    $apbct->data['ajax_type'] = $alt_cookies_type;
    $apbct->saveData();
}

/**
 * 5.164.2
 */
function apbct_update_to_5_164_2()
{
    global $apbct;
    $apbct->errorDeleteAll();
}

/**
 * 5.167.1
 */
function apbct_update_to_5_167_1()
{
    global $apbct;

    // For the current installations, after updating the option will turn off
    $apbct->settings['exclusions__log_excluded_requests'] = '0';
    $apbct->saveSettings();
}

/**
 * 5.172.1
 */
function apbct_update_to_5_172_1()
{
    global $apbct;

    if ( isset($apbct->settings['forms__wc_honeypot']) ) {
        $apbct->settings['data__honeypot_field'] = $apbct->settings['forms__wc_honeypot'];
        $apbct->saveSettings();
    }
}

/**
 * 5.172.1
 */
function apbct_update_to_5_176()
{
    global $apbct;
    $apbct->data['ajax_type'] = apbct_settings__get_ajax_type() ?: 'admin_ajax';
    $apbct->saveData();
}

/**
 * 5.172.1
 */
function apbct_update_to_5_176_1()
{
    global $apbct;
    if ( ! isset($apbct->settings['data__email_decoder']) ) {
        $apbct->settings['data__email_decoder'] = 0;
        $apbct->saveSettings();
    }
}

function apbct_update_to_5_177_2()
{
    global $apbct;

    if ( isset($apbct->remote_calls['update_plugin']) ) {
        unset($apbct->remote_calls['update_plugin']);
        $apbct->save('remote_calls');
    }
}

function apbct_update_to_5_177_3()
{
    global $apbct;
    if ( ! empty($apbct->settings['exclusions__urls']) ) {
        $apbct->data['check_exclusion_as_url'] = false;
        $apbct->saveData();
    }
}

function apbct_update_to_5_179_2()
{
    global $apbct;

    $apbct->remote_calls['post_api_key'] = array('last_call' => 0);
    $apbct->save('remote_calls');
}

function apbct_update_to_5_182_0()
{
    global $apbct;

    // Move connection report from cleantalk_data to separate option cleantalk_connection_reports
    $connection_reports = array(
        'success'         => 0,
        'negative'        => 0,
        'negative_report' => array(),
        'since'           => '',
    );
    if ( isset($apbct->data['connection_reports']) ) {
        $connection_reports = $apbct->data['connection_reports'];
        unset($apbct->data['connection_reports']);
        $apbct->save('data');
    }

    update_option('cleantalk_connection_reports', $connection_reports, false);
}

function apbct_update_to_5_184_2()
{
    global $apbct;
    if ( ! isset($apbct->settings['misc__send_connection_reports']) ) {
        $apbct->settings['misc__send_connection_reports'] = 1;
        $apbct->saveSettings();
    }
}

function apbct_update_to_6_0_1()
{
    global $apbct;
    if ( isset($apbct->data['connection_reports']) ) {
        unset($apbct->data['connection_reports']);
        $apbct->save('data');
    }

    delete_option('cleantalk_connection_reports');

    $cron = new Cron();
    $cron->removeTask('send_connection_report');
    $cron->addTask(
        'send_connection_report',
        'ct_cron_send_connection_report_email',
        86400,
        time() + 3500
    );

    $apbct->errorDelete('cron', true);
}

function apbct_update_to_6_3_3()
{
    global $apbct;
    if ( ! isset($apbct->settings['data__email_decoder_buffer']) ) {
        $apbct->settings['data__email_decoder_buffer'] = 0;
        $apbct->saveSettings();
    }
}

function apbct_update_to_6_6_0()
{
    $cron = new Cron();
    $cron->removeTask('send_js_error_report');
    $cron->addTask('send_js_error_report', 'ct_cron_send_js_error_report_email', 86400);
}

function apbct_update_to_6_8_0()
{
    global $apbct;

    $apbct->data['wl_brandname'] = isset($apbct->data['wl_brandname']) ? $apbct->data['wl_brandname'] : 'Anti-Spam by CleanTalk';
    $apbct->data['wl_brandname_short'] = isset($apbct->data['wl_brandname_short']) ? $apbct->data['wl_brandname_short'] : 'CleanTalk';
    $apbct->data['wl_url'] = isset($apbct->data['wl_url']) ? $apbct->data['wl_url'] : 'https://cleantalk.org/';
    $apbct->data['wl_support_email'] = isset($apbct->data['wl_support_email']) ? $apbct->data['wl_support_email'] : 'support@cleantalk.org';

    $apbct->data['wl_support_faq'] = isset($apbct->data['wl_support_faq'])
    ? $apbct->data['wl_support_faq'] : 'https://wordpress.org/plugins/cleantalk-spam-protect/faq/';

    $apbct->data['wl_support_url'] = isset($apbct->data['wl_support_url'])
    ? $apbct->data['wl_support_url'] : 'https://wordpress.org/support/plugin/cleantalk-spam-protect';
}

function apbct_update_to_6_9_0()
{
    global $apbct;

    if (isset($apbct->settings['gdpr__enabled']) || isset($apbct->settings['gdpr__text'])) {
        if (isset($apbct->settings['gdpr__enabled'])) {
            unset($apbct->settings['gdpr__enabled']);
        }
        if (isset($apbct->settings['gdpr__text'])) {
            unset($apbct->settings['gdpr__text']);
        }

        $apbct->saveSettings();
    }
}

function apbct_update_to_6_10_2()
{
    global $apbct;

    $apbct->settings['data__bot_detector_enabled'] = 0;
    $apbct->saveSettings();
}

function apbct_update_to_6_17_2()
{
    $cron = new Cron();
    $cron->removeTask('clear_old_session_data');
    $cron->addTask('clear_old_session_data', 'apbct_cron_clear_old_session_data', 86400);
}

function apbct_update_to_6_41_0()
{
    global $wpdb;

    $wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . 'cleantalk_no_cookie_data`;');
}

function apbct_update_to_6_42_0()
{
    add_action('plugins_loaded', function () {
        delete_option('cleantalk_adjust_to_env');
        $adjust = new Cleantalk\ApbctWP\AdjustToEnvironmentModule\AdjustToEnvironmentHandler();
        $adjust->handle();
    });
}

function apbct_update_to_6_46_1()
{
    global $apbct;

    if (isset($apbct->settings['misc__debug_ajax'])) {
        unset($apbct->settings['misc__debug_ajax']);
        $apbct->saveSettings();
    }

    $apbct->deleteOption('debug', true);
}

function apbct_update_to_6_54_1()
{
    global $apbct;

    $apbct->settings['data__email_decoder_encode_phone_numbers'] = 0;

    if ($apbct->settings['data__email_decoder']) {
        $apbct->settings['data__email_decoder_encode_email_addresses'] = 1;
    } else {
        $apbct->settings['data__email_decoder_encode_email_addresses'] = 0;
    }

    if ( ! isset($apbct->settings['forms__gravityforms_save_spam']) ) {
        $apbct->settings['forms__gravityforms_save_spam'] =
            isset($apbct->default_settings['forms__gravityforms_save_spam'])
                ? $apbct->default_settings['forms__gravityforms_save_spam']
                : 1;
        $apbct->saveSettings();
    }

    $apbct->saveSettings();
}

function apbct_update_to_6_60_0()
{
    global $wpdb;

    // Check if the table exists first to avoid unnecessary queries
    if ($wpdb->get_var("SHOW TABLES LIKE '" . APBCT_TBL_SESSIONS . "'") !== APBCT_TBL_SESSIONS) {
        return;
    }

    $query = $wpdb->prepare('
        SELECT 
            index_name AS index_name,
            COUNT(*) AS column_count
        FROM 
            information_schema.statistics
        WHERE 
            table_schema = %s AND
            table_name = %s AND
            index_name IN (
                SELECT index_name 
                FROM information_schema.statistics 
                WHERE column_name = \'id\'
            )
        GROUP BY 
            index_name;
    ', $wpdb->dbname, APBCT_TBL_SESSIONS);

    $indexes = $wpdb->get_results($query, ARRAY_A);

    $wrong_index = false;
    if (!empty($indexes)) {
        $index_data = $indexes[0];
        if (isset($index_data['column_count'], $index_data['index_name'])) {
            $wrong_index = (
                strtoupper($index_data['index_name']) !== 'PRIMARY' ||
                $index_data['column_count'] != 1 // Loose comparison for type flexibility
            );
        }
    }

    if ($wrong_index) {
        $drop_result = $wpdb->query('DROP TABLE IF EXISTS ' . APBCT_TBL_SESSIONS);
        if ($drop_result !== false) {
            $db_creator = new \Cleantalk\ApbctWP\UpdatePlugin\DbTablesCreator();
            $db_creator->createTable(APBCT_TBL_SESSIONS); // Use the original constant
        }
    }
}

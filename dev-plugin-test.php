<?php
/*
Plugin Name: Dev Plugin Test
Plugin URI: http://www.ziprecipes.net/
Description: A plugin that adds all the necessary microdata to your recipes, so they will show up in Google's Recipe Search
Version: 4.3.1.2
Author: HappyGezim
Author URI: http://www.ziprecipes.net/
License: GPLv3 or later

Copyright 2015 Gezim Hoxha
*/

defined('ABSPATH') or die("Error! Cannot be called directly.");


/**
 * Check plugin versions against the latest versions hosted on WordPress.org.
 *
 * The WordPress version, PHP version, and Locale is sent along with a list of
 * all plugins installed. Checks against the WordPress server at
 * api.wordpress.org. Will only check if WordPress isn't installing.
 *
 * @since 2.3.0
 * @uses $wp_version Used to notify the WordPress version.
 *
 * @param array $extra_stats Extra statistics to report to the WordPress.org API.
 * @return false|null Returns null if update is unsupported. Returns false if check is too soon.
 */
function dpt_override_update_plugins( $extra_stats = array() ) {

    include( ABSPATH . WPINC . '/version.php' ); // include an unmodified $wp_version

    $new_version = "4.3.1.3";
    $new_package_url = "http://localhost/devblog/zip-recipes.zip";

    if ( defined('WP_INSTALLING') )
        return false;

    // If running blog-side, bail unless we've not checked in the last 12 hours
    if ( !function_exists( 'get_plugins' ) )
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

    $plugins = get_plugins();
    $translations = wp_get_installed_translations( 'plugins' );

    $active  = get_option( 'active_plugins', array() );
    $current = get_site_transient( 'update_plugins' );
    if ( ! is_object($current) )
        $current = new stdClass;

    $new_option = new stdClass;
    $new_option->last_checked = time();

    // Check for update on a different schedule, depending on the page.
    switch ( current_filter() ) {
        case 'upgrader_process_complete' :
            $timeout = 0;
            break;
        case 'load-update-core.php' :
            $timeout = MINUTE_IN_SECONDS;
            break;
        case 'load-plugins.php' :
        case 'load-update.php' :
            $timeout = HOUR_IN_SECONDS;
            break;
        default :
            if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
                $timeout = 0;
            } else {
                $timeout = 12 * HOUR_IN_SECONDS;
            }
    }

    $time_not_changed = isset( $current->last_checked ) && $timeout > ( time() - $current->last_checked );

    if ( $time_not_changed && ! $extra_stats ) {
        $plugin_changed = false;
        foreach ( $plugins as $file => $p ) {
            $new_option->checked[ $file ] = $p['Version'];

            if ( !isset( $current->checked[ $file ] ) || strval($current->checked[ $file ]) !== strval($p['Version']) )
                $plugin_changed = true;
        }

        if ( isset ( $current->response ) && is_array( $current->response ) ) {
            foreach ( $current->response as $plugin_file => $update_details ) {
                if ( ! isset($plugins[ $plugin_file ]) ) {
                    $plugin_changed = true;
                    break;
                }
            }
        }

        $plugin_changed = true; // TODO: add conidtion here

        // Bail if we've checked recently and if nothing has changed
        if ( ! $plugin_changed )
            return false;
    }

    // Update last_checked for current to prevent multiple blocking requests if request hangs
    $current->last_checked = time();
    set_site_transient( 'update_plugins', $current );

    $to_send = compact( 'plugins', 'active' );

    $locales = array( get_locale() );
    /**
     * Filter the locales requested for plugin translations.
     *
     * @since 3.7.0
     *
     * @param array $locales Plugin locale. Default is current locale of the site.
     */
    $locales = apply_filters( 'plugins_update_check_locales', $locales );

    if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
        $timeout = 30;
    } else {
        // Three seconds, plus one extra second for every 10 plugins
        $timeout = 3 + (int) ( count( $plugins ) / 10 );
    }

    $options = array(
        'timeout' => $timeout,
        'body' => array(
            'plugins'      => wp_json_encode( $to_send ),
            'translations' => wp_json_encode( $translations ),
            'locale'       => wp_json_encode( $locales ),
            'all'          => wp_json_encode( true ),
        ),
        'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' )
    );

    if ( $extra_stats ) {
        $options['body']['update_stats'] = wp_json_encode( $extra_stats );
    }

    $url = $http_url = 'http://api.wordpress.org/plugins/update-check/1.1/';
    if ( $ssl = wp_http_supports( array( 'ssl' ) ) )
        $url = set_url_scheme( $url, 'https' );

    $raw_response = wp_remote_post( $url, $options );
    if ( $ssl && is_wp_error( $raw_response ) ) {
        trigger_error( __( 'An unexpected error occurred. Something may be wrong with WordPress.org or this server&#8217;s configuration. If you continue to have problems, please try the <a href="https://wordpress.org/support/">support forums</a>.' ) . ' ' . __( '(WordPress could not establish a secure connection to WordPress.org. Please contact your server administrator.)' ), headers_sent() || WP_DEBUG ? E_USER_WARNING : E_USER_NOTICE );
        $raw_response = wp_remote_post( $http_url, $options );
    }

    if ( is_wp_error( $raw_response ) || 200 != wp_remote_retrieve_response_code( $raw_response ) )
        return false;

    $response = json_decode( wp_remote_retrieve_body( $raw_response ), true );
    foreach ( $response['plugins'] as &$plugin ) {
        $plugin = (object) $plugin;
    }
    unset( $plugin );
    foreach ( $response['no_update'] as &$plugin ) {
        $plugin = (object) $plugin;
    }
    unset( $plugin );

    if (isset($response['plugins']) && is_object($response['plugins']['zip-recipes/zip-recipes.php']) )
    {
        $response['plugins']['zip-recipes/zip-recipes.php']->package =  $new_package_url;
        $response['plugins']['zip-recipes/zip-recipes.php']->new_version = $new_version;
    }

    if ( is_array( $response ) ) {
        $new_option->response = $response['plugins'];
        $new_option->translations = $response['translations'];
        // TODO: Perhaps better to store no_update in a separate transient with an expiry?
        $new_option->no_update = $response['no_update'];
    } else {
        $new_option->response = array();
        $new_option->translations = array();
        $new_option->no_update = array();
    }

    set_site_transient( 'update_plugins', $new_option );
}

function dpt_settings_load()
{
    add_action( 'load-plugins.php', 'dpt_override_update_plugins', 9 );
    add_action( 'load-update.php', 'dpt_override_update_plugins', 9 );
    add_action( 'load-update-core.php', 'dpt_override_update_plugins', 9 );
    add_action( 'admin_init', 'dpt_override_update_plugins', 9 );
    add_action( 'wp_update_plugins', 'dpt_override_update_plugins', 9 );
    add_action( 'upgrader_process_complete', 'dpt_override_update_plugins', 10, 0 );

    remove_action( 'load-plugins.php', 'wp_update_plugins' );
    remove_action( 'load-update.php', 'wp_update_plugins' );
    remove_action( 'load-update-core.php', 'wp_update_plugins' );
    remove_action( 'admin_init', '_maybe_update_plugins' );
    remove_action( 'wp_update_plugins', 'wp_update_plugins' );
    remove_action( 'upgrader_process_complete', 'wp_update_plugins');
}

function dpt_deactivate()
{
    require_once( ABSPATH . 'wp-admin/includes/update.php' );

    add_action( 'load-plugins.php', 'wp_update_plugins' );
    add_action( 'load-update.php', 'wp_update_plugins' );
    add_action( 'load-update-core.php', 'wp_update_plugins' );
    add_action( 'admin_init', '_maybe_update_plugins' );
    add_action( 'wp_update_plugins', 'wp_update_plugins' );
    add_action( 'upgrader_process_complete', 'wp_update_plugins');


    // Readd ajax action
    add_action( 'wp_ajax_update', 'wp_ajax_update_plugin', 1 );
}

function dpt_ajax_load()
{
    // Remove old action
    remove_action( 'wp_ajax_update-plugin', 'wp_ajax_update_plugin', 1 );

    global $wp_filesystem;

    $plugin = urldecode( $_POST['plugin'] );

    $status = array(
        'update'     => 'plugin',
        'plugin'     => $plugin,
        'slug'       => sanitize_key( $_POST['slug'] ),
        'oldVersion' => '',
        'newVersion' => '',
    );

    $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
    if ( $plugin_data['Version'] ) {
        $status['oldVersion'] = sprintf( __( 'Version %s' ), $plugin_data['Version'] );
    }

    if ( ! current_user_can( 'update_plugins' ) ) {
        $status['error'] = __( 'You do not have sufficient permissions to update plugins for this site.' );
        wp_send_json_error( $status );
    }

    check_ajax_referer( 'updates' );

    include_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );

    dpt_override_update_plugins();

    $skin = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader( $skin );
    $result = $upgrader->bulk_upgrade( array( $plugin ) );

    if ( is_array( $result ) && empty( $result[$plugin] ) && is_wp_error( $skin->result ) ) {
        $result = $skin->result;
    }

    if ( is_array( $result ) && !empty( $result[ $plugin ] ) ) {
        $plugin_update_data = current( $result );

        /*
         * If the `update_plugins` site transient is empty (e.g. when you update
         * two plugins in quick succession before the transient repopulates),
         * this may be the return.
         *
         * Preferably something can be done to ensure `update_plugins` isn't empty.
         * For now, surface some sort of error here.
         */
        if ( $plugin_update_data === true ) {
            wp_send_json_error( $status );
        }

        $plugin_data = get_plugins( '/' . $result[ $plugin ]['destination_name'] );
        $plugin_data = reset( $plugin_data );

        if ( $plugin_data['Version'] ) {
            $status['newVersion'] = sprintf( __( 'Version %s' ), $plugin_data['Version'] );
        }

        wp_send_json_success( $status );
    } else if ( is_wp_error( $result ) ) {
        $status['error'] = $result->get_error_message();
        wp_send_json_error( $status );

    } else if ( is_bool( $result ) && ! $result ) {
        $status['errorCode'] = 'unable_to_connect_to_filesystem';
        $status['error'] = __( 'Unable to connect to the filesystem. Please confirm your credentials.' );

        // Pass through the error from WP_Filesystem if one was raised
        if ( is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->get_error_code() ) {
            $status['error'] = $wp_filesystem->errors->get_error_message();
        }

        wp_send_json_error( $status );

    }
}

add_action( 'plugins_loaded', 'dpt_settings_load' );
add_action( 'wp_ajax_update-plugin', 'dpt_ajax_load', 0);
do_action ( "deactivate_" . plugin_basename(__FILE__), 'dpt_deactivate');
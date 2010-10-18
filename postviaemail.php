<?php
/*
Plugin Name: Post Via Email
Plugin URI: http://postviaemail.com
Description: The simplest way to post to your WordPress blog using email.  One click setup and start sending emails to post to your blog.
Version: 1.0
Author: jonefox
Author URI: http://jonefox.com/blog
*/

define( 'PVE_SERVICE_URL', 'http://postviaemail.com/rest.php' );

function pve_save_option( $name, $value ) {
        global $wpmu_version;
        
        if ( false === get_option( $name ) && empty( $wpmu_version ) ) // Avoid WPMU options cache bug
                add_option( $name, $value, '', 'no' );
        else
                update_option( $name, $value );
}

function pve_generate_remote_token() {
    $letters = array( 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 0, 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z' );
    $str = "";
    for ( $i = 0; $i < 40; $i++ ) {
        $str .= $letters[ rand( 0, sizeof( $letters ) + 1 ) ];
    }
    
    return $str;
}

function pve_register_site() {
        global $current_user;
        
        if ( !$remote_token = get_option( 'pve_remote_token' ) ) {
                $remote_token = pve_generate_remote_token();
                pve_save_option( 'pve_remote_token', $remote_token );
        }
        
        $site = array( 'url' => get_option( 'siteurl' ), 'title' => get_option( 'blogname' ), 'user_email' => $current_user->user_email, 'remote_token' => $remote_token );
        
        $response = pve_send_data( 'add-site', $site );
        if ( strpos( $response, '|' ) ) {
                // Success
                $vals = explode( '|', $response );
                $site_id = $vals[0];
                $site_key = $vals[1];
                if ( isset( $site_id ) && is_numeric( $site_id ) && strlen( $site_key ) > 0 ) {
                        pve_save_option( 'pve_site_id', $site_id );
                        pve_save_option( 'pve_site_key', $site_key );
                        return true;
                }
        }
        
        return $response;
}

function pve_get_posting_email() {
        $site_id  = get_option( 'pve_site_id' );
        $site_key = get_option( 'pve_site_key' );
        
        if ( !$site_id || !$site_key )
                return false;
        
        return pve_send_data( 'get-posting-email', array( 'site_id' => $site_id, 'site_key' => $site_key ) );
}

function pve_get_new_posting_email() {
        $site_id  = get_option( 'pve_site_id' );
        $site_key = get_option( 'pve_site_key' );
        
        if ( !$site_id || !$site_key )
                return false;
        
        return pve_send_data( 'update-posting-email', array( 'site_id' => $site_id, 'site_key' => $site_key ) );
}

function pve_clear_settings() {
        pve_save_option( 'pve_site_id', false );
        pve_save_option( 'pve_site_key', false );
        pve_save_option( 'pve_remote_token', false );
}

function pve_rest_handler() {
        // Basic ping
        if ( isset( $_GET['pve_ping'] ) || isset( $_POST['pve_ping'] ) )
                return pve_ping_handler();
                
        if ( isset( $_GET['pve_auth_check'] ) || isset( $_POST['pve_auth_check'] ) )
                return pve_auth_check();
                
        if ( isset( $_GET['pve_post'] ) || isset( $_POST['pve_post'] ) )
                return pve_post();
        
        if ( isset( $_GET['pve_get_new_email'] ) || isset( $_POST['pve_get_new_email'] ) )
                return pve_get_new_email();
}

add_action( 'init', 'pve_rest_handler' );

function pve_ping_handler() {
        if ( !isset( $_GET['pve_ping'] ) && !isset( $_POST['pve_ping'] ) )
                return false;
        
        $ping = ( $_GET['pve_ping'] ) ? $_GET['pve_ping'] : $_POST['pve_ping'];
        if ( strlen( $ping ) <= 0 )
                exit;
        
        if ( $ping != get_option( 'pve_remote_token' ) )
                exit;
                
        echo sha1( $ping );
        exit;
}

function pve_auth_check() {
        if ( !isset( $_GET['pve_auth_check'] ) && !isset( $_POST['pve_auth_check'] ) )
                return false;
        
        $auth_check = ( $_GET['pve_auth_check'] ) ? $_GET['pve_auth_check'] : $_POST['pve_auth_check'];
        if ( !$auth_check || $auth_check != get_option( 'pve_remote_token' ) )
                echo 'false';
        else
                echo 'true';
        exit;
}

function pve_post() {
        if ( !isset( $_GET['pve_post'] ) && !isset( $_POST['pve_post'] ) )
                return false;
        
        $pve_data = ( $_GET['pve_post'] ) ? $_GET['pve_post'] : $_POST['pve_post'];
        $pve_data = json_decode( base64_decode( $pve_data ) );
        if ( !$pve_data ) {
                echo 'Unable to decode data';
                exit;
        }
        
        if ( !$pve_data->remote_token || $pve_data->remote_token != get_option( 'pve_remote_token' ) ) {
                echo 'Unable to verify remote_token';
                exit;
        }
        
        if ( !$pve_data->html ) {
                echo 'Missing html attrib';
                exit;
        }

        $post = array( 'post_title' => $pve_data->subject, 'post_content' => $pve_data->html, 'post_status' => $pve_data->post_status );
        $post_id = wp_insert_post( $post );
        if ( $post_id <= 0 ) {
                echo 'Failed to save post';
                exit;
        }
        
        echo 'success';
        exit;
}

function pve_get_new_email() {
        if ( !isset( $_GET['pve_get_new_email'] ) && !isset( $_POST['pve_get_new_email'] ) )
                return false;
        
        $auth_check = ( $_GET['pve_get_new_email'] ) ? $_GET['pve_get_new_email'] : $_POST['pve_get_new_email'];
        if ( $auth_check && $auth_check == get_option( 'pve_remote_token' ) )
                pve_get_new_posting_email();
}

function pve_option_settings_api_init() {
        add_settings_field( 'pve_setting', 'PostViaEmail.com', 'pve_setting_callback_function', 'writing', 'post_via_email' );
        register_setting( 'writing', 'pve_setting' );
}

function pve_setting_callback_function() {
        $email = pve_get_posting_email();
        $remote_token = get_option( 'pve_remote_token' );
        
        echo "Your current email address to post to this site is: <a href='mailto: $email'>$email</a> <br /> If you'd like you can also <a href='?pve_get_new_email=$remote_token'>generate a new email address</a>.";
        echo "<script type='text/javascript'> pve_url_index = window.location.href.indexOf( '?pve_get_new_email' ); if ( pve_url_index > 0 ) window.location = window.location.href.substr( 0, pve_url_index ); </script>";
}

add_action( 'admin_init',  'pve_option_settings_api_init' );

function pve_notice() {
        if ( get_option( 'pve_has_shown_notice') )
                return;
        
        if ( !get_option( 'pve_remote_token' ) ){
              pve_register_site();  
        }
        
        $settings_url = get_bloginfo( 'wpurl' ) . '/wp-admin/options-writing.php';
        $email = pve_get_posting_email();
        ?>
        <div class="updated fade-ff0000">
                <p><strong><?php printf( 'You can now post to your blog by sending an email to <a href="mailto:%s">%s</a>.  You can change this email or find it later on <a href="%s">your writing settings page</a>.', $email, $email, $settings_url ); ?></strong></p>
        </div>
        <?php
        pve_save_option( 'pve_has_shown_notice', true );
        return;
}

add_action( 'admin_notices', 'pve_notice' );

function pve_activate() {
        pve_save_option( 'pve_has_shown_notice', false );
}

register_activation_hook( __FILE__, 'pve_activate' );

if ( !function_exists( 'wp_remote_get' ) && !function_exists( 'get_snoopy' ) ) {
        function get_snoopy() {
                include_once( ABSPATH . '/wp-includes/class-snoopy.php' );
                return new Snoopy;
        }
}

function pve_http_query( $url, $fields ) {
        $results = '';
        if ( function_exists( 'wp_remote_get' ) ) {
                // The preferred WP HTTP library is available
                $url .= '?' . http_build_query( $fields );
                $response = wp_remote_get( $url );
                if ( !is_wp_error( $response ) )
                        $results = wp_remote_retrieve_body( $response );
        } else {
                // Fall back to Snoopy
                $snoopy = get_snoopy();
                $url .= '?' . http_build_query( $fields );
                if ( $snoopy->fetch( $url ) )
                        $results = $snoopy->results;
        }
        return $results;
}

function pve_send_data( $action, $data_fields ) {
        $data = array( 'action' => $action, 'data' => base64_encode( json_encode( $data_fields ) ) );
        
        return pve_http_query( PVE_SERVICE_URL, $data );
}

?>
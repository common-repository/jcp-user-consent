<?php
/*
  Plugin Name: JCP User Consent
  Plugin URI: https://wordpress.org/plugins/jcp-user-consent/
  Description: General Data Protection Regulations adjustments for user registration
  Version: 1.1.1
  Author: Andre Lohan
  Text Domain: jcp-user-consent
  Domain Path: /languages
  License: GPL2
 */

define( 'JCP_UC_CONFIRMATION_KEY_LENGTH', 32 );

// Utility function to return client IP address
function jcp_uc_get_ip() {
    $ip = '';
    if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
        // Shared connection?
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        // By proxy
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return apply_filters( 'jcp_get_ip', $ip );
}

add_action( 'plugins_loaded', 'jcp_uc_plugins_loaded' );
function jcp_uc_plugins_loaded() {
    load_plugin_textdomain( 'jcp-user-consent', false, basename( dirname( __FILE__ ) ) . '/languages' );
}

// Adds checkbox to user registration form
add_action( 'register_form', 'jcp_uc_register_form' );
function jcp_uc_register_form() {
    // WP 4.9.6 has option to set privacy policy page
    $privacy_page_id = get_option( 'wp_page_for_privacy_policy' );

    echo '<p><input type="checkbox" name="consent_given" value="1">';
    // Link privacy policy page if available
    if ( $privacy_page_id ) {
        $privacy_page_link = get_permalink( $privacy_page_id );
        if ( $privacy_page_link ) {
            $link_text = sprintf( '<a href="%s" target="_blank">%s</a>', esc_attr( $privacy_page_link ), __( 'storing my data', 'jcp-user-consent' ) );
            printf( __( 'I agree to %s.', 'jcp-user-consent' ), $link_text );
        }
    } else {
        // No privacy policy page set
        _e( 'I agree to storing my data.', 'jcp-user-consent' );
    }
    echo '</p>';
}

// Users have to agree to their data being stored
add_filter( 'registration_errors', 'jcp_uc_registration_errors', 10, 3 );
function jcp_uc_registration_errors( $errors, $sanitized_user_login, $user_email ) {
    if ( empty( $_POST['consent_given'] ) ) {
        $errors->add( 'consent_missing_error', sprintf('<strong>%s</strong>: %s', __( 'ERROR', 'jcp-user-consent' ),__( 'You must agree to store your data.', 'jcp-user-consent' ) ) );
    }
    return $errors;
}

// Store IP address on user registration as evidence
add_action( 'user_register', 'jcp_uc_user_register', 10, 1 );
function jcp_uc_user_register( $user_id ) {
    update_user_meta( $user_id, '_jcp_uc_register_ip', jcp_uc_get_ip() );
    update_user_meta( $user_id, '_jcp_uc_register_time', current_time( 'Y-m-d H:i:s' ) );
    // Force no role until consent key has been received
    $user_data = get_userdata( $user_id );
    if ( $user_data ) {
        $user_data->set_role( '' );
    }
}

// Customize email sent to user after registration to add extra consent key
add_filter( 'wp_new_user_notification_email', 'jcp_uc_registration_email', 10, 3 );
function jcp_uc_registration_email( $email, $user, $blogname ) {
    global $wpdb, $wp_hasher;

    $key = get_password_reset_key( $user );
    // Check for error? Like when retrieve password disabled?
    if ( is_wp_error( $key ) ) {
		$key = '';
	}

    // Generate additional key just for confirmation
    $consent_key = wp_generate_password( JCP_UC_CONFIRMATION_KEY_LENGTH, false );
    update_user_meta( $user->ID, '_jcp_uc_consent_key', $consent_key );

    $activation_link = network_site_url( "wp-login.php?action=rp&key=$key&consent_key=$consent_key&login=" . $user->user_login );

    $email['subject'] = sprintf( __( '[%s] Complete registration', 'jcp-user-consent' ), $blogname );
    $email['message'] = sprintf( __( "Hello %s!\r\n\r\nThanks for your registration on %s. To complete the registration process and set a password for your account please click here: %s", 'jcp-user-consent' ), $user->user_login, $blogname, $activation_link );

    // Store email as evidence
    update_user_meta( $user->ID, '_jcp_uc_register_email', $email );

    return $email;
}

// Registering has a new query var for consent key
add_filter( 'query_vars', 'jcp_uc_add_query_vars' );
function jcp_uc_add_query_vars( $vars ) {
    $vars[] = 'consent_key';
    return $vars;
}

// Create endpoint for consent
add_action( 'init', 'jcp_uc_add_rewrite_endpoint');
function jcp_uc_add_rewrite_endpoint() {
    add_rewrite_endpoint( 'consent_key', EP_PERMALINK | EP_PAGES );
}

// Checks if consent key has been received and updates user role and meta
add_action( 'login_form_rp', 'jcp_uc_login_form_rp' );
function jcp_uc_login_form_rp() {
    global $wp_query;

    if ( ! isset( $_GET['consent_key'] ) || empty( $_GET['consent_key'] ) ) return;

    $consent_key = trim( $_GET['consent_key'] );

    // Check key length
    if ( strlen( $consent_key ) != JCP_UC_CONFIRMATION_KEY_LENGTH ) return;

    // Find user by consent key
    $user = reset(
        get_users(
            array(
                'meta_key' => '_jcp_uc_consent_key',
                'meta_value' => $consent_key,
                'number' => 1,
                'count_total' => false
            )
        )
    );

    if ( ! $user ) return;

    $granted = get_user_meta( $user->ID, '_jcp_uc_consent_granted', true );
    // Don't update consent info when already granted
    if ( $granted ) return;

    update_user_meta( $user->ID, '_jcp_uc_consent_ip', jcp_uc_get_ip() );
    update_user_meta( $user->ID, '_jcp_uc_consent_time', current_time( 'Y-m-d H:i:s' ) );
    update_user_meta( $user->ID, '_jcp_uc_consent_granted', true );

    // Restore default role since it has been set to none on registration by plugin
    $role = get_option( 'default_role' );
    $user->set_role( $role );

    // Too late to add message?
    add_filter( 'login_messages', function( $messages ) {
        $messages['consent_granted'] = __( 'Thanks for granting consent!', 'jcp-user-consent' );
        return $messages;
    } );

    // Feature: Send confirmation of activation email?
}

// Adds column to users
add_filter( 'manage_users_columns', 'jcp_uc_column_header_confirmed' );
function jcp_uc_column_header_confirmed( $column_headers ) {

    // Find offset for email to insert column right after
    $offset = array_search( 'email', array_keys( $column_headers ) ) + 1;

    $column_headers = array_slice( $column_headers, 0, $offset, true ) +
            array('consent' => __( 'Consent', 'jcp-user-consent' ) ) +
            array_slice( $column_headers, $offset, null, true );

	return $column_headers;
}

// Sets confirmed user column to sortable
add_filter( 'manage_users_sortable_columns', 'jcp_uc_sortable_columns' );
function jcp_uc_sortable_columns( $columns ) {
	$columns['consent']  = 'consent';
	return $columns;
}

// Renders content of costum column
add_filter( 'manage_users_custom_column', 'jcp_uc_manage_custom_column', 10, 3 );
function jcp_uc_manage_custom_column( $value, $column_name, $user_id ) {
	if ( $column_name == 'consent' ) {
		$consent = get_user_meta( $user_id, '_jcp_uc_consent_granted', true );
		$value =  $consent ? __( 'Granted', 'jcp-user-consent' ) : '—';
        // row actions for admins
        if ( $consent && current_user_can( 'manage_options' ) ) {
            $consent_date_meta = get_user_meta( $user_id, '_jcp_uc_consent_time', true );
            $consent_ip_meta = get_user_meta( $user_id, '_jcp_uc_consent_ip', true );
            $consent_date = DateTime::createFromFormat( 'Y-m-d H:i:s', $consent_date_meta );
            if ( $consent_date ) {
                $value .= '<br><div class="row-actions"><a href="#">' . $consent_date->format( 'd.m.Y H:i' )
                        . ' - ' . esc_html( $consent_ip_meta )
                        . '</a></div>';
            }
        }
	}
	return $value;
}

// Sorts users by custom column
add_filter( 'pre_get_users', 'jcp_uc_sort_by_column' );
function jcp_uc_sort_by_column( $query ) {
	if ( 'consent' == $query->get( 'orderby' ) ) {
        $query->set(
            'meta_query',
            array(
                'relation' => 'OR',
                array(
                    'key' => '_jcp_users_gdpr_consent_granted',
                    'value' => '1',
                    'compare' => 'EXISTS'
                ),
                // Fix for results not having this key at all
                array(
                    'key' => '_jcp_users_gdpr_consent_granted',
                    'value' => '',
                    'compare' => 'NOT EXISTS'
                )
            )
        );
		$query->set( 'orderby', 'meta_value' );
	}
}

// Prevent users with no roles to login
add_filter( 'authenticate', 'jcp_uc_authenticate', 30, 3 );
function jcp_uc_authenticate( $user, $username, $password ) {

    if ( is_wp_error( $user ) || empty( $username ) || empty( $password ) ) {
        return $user;
    }

    if ( empty( $user->roles ) ) {
        return new WP_Error( 'account_not_enabled', __( 'Account not enabled', 'jcp-user-consent' ) );
    }

    return $user;
}

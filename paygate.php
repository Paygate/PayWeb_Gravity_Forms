<?php
/**
 * Plugin Name: Gravity Forms PayGate Add-On
 * Plugin URI: https://github.com/PayGate/PayWeb_Gravity_Forms
 * Description: Integrates Gravity Forms with PayGate, a South African payment gateway.
 * Version: 2.4.18
 * Tested: 5.4.2
 * Author: PayGate (Pty) Ltd
 * Author URI: https://www.paygate.co.za/
 * Developer: App Inlet (Pty) Ltd
 * Developer URI: https://www.appinlet.com/
 * Text Domain: gravityformspaygate
 * Domain Path: /languages
 *
 * Copyright: © 2020 PayGate (Pty) Ltd.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

add_action( 'plugins_loaded', 'paygate_init' );

/**
 * Initialize the gateway.
 *
 * @since 1.0.0
 */

function paygate_init()
{
    /**
     * Auto updates from GIT
     *
     * @since 2.2.9
     *
     */

    require_once 'updater.class.php';

    if ( is_admin() ) {
        // note the use of is_admin() to double check that this is happening in the admin

        $config = array(
            'slug'               => plugin_basename( __FILE__ ),
            'proper_folder_name' => 'gravity-forms-paygate-plugin',
            'api_url'            => 'https://api.github.com/repos/PayGate/PayWeb_Gravity_Forms',
            'raw_url'            => 'https://raw.github.com/PayGate/PayWeb_Gravity_Forms/master',
            'github_url'         => 'https://github.com/PayGate/PayWeb_Gravity_Forms',
            'zip_url'            => 'https://github.com/PayGate/PayWeb_Gravity_Forms/archive/master.zip',
            'homepage'           => 'https://github.com/PayGate/PayWeb_Gravity_Forms',
            'sslverify'          => true,
            'requires'           => '4.0',
            'tested'             => '5.3.0',
            'readme'             => 'README.md',
            'access_token'       => '',
        );

        new WP_GitHub_Updater_PW3_GF( $config );

    }
}

ob_start();
if (  ( function_exists( 'session_status' ) && session_status() !== PHP_SESSION_ACTIVE ) || !session_id() ) {
    session_start();
}

add_action( 'gform_loaded', array( 'GF_PayGate_Bootstrap', 'load' ), 5 );

class GF_PayGate_Bootstrap
{

    public static function load()
    {
        if ( !method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
            return;
        }

        require_once 'paygate_gf_class.php';

        GFAddOn::register( 'PayGateGF' );
    }

}

// Filters for payment status message
function change_message( $message, $form )
{
    if ( isset( $_SESSION['trans_failed'] ) && !empty( $_SESSION['trans_failed'] ) && strlen( $_SESSION['trans_failed'] ) > 0 ) {
        $err_msg = $_SESSION['trans_failed'];
        return "<div class='validation_error'>" . $_SESSION['trans_failed'] . '</div>';
    } else if ( isset( $_SESSION['trans_declined'] ) && !empty( $_SESSION['trans_declined'] ) ) {
        $err_msg = $_SESSION['trans_declined'];
        return "<div class='validation_error'>" . $_SESSION['trans_declined'] . '</div>';
    } else {
        return $message;
    }
}

add_filter( 'gform_pre_render', 'gform_pre_render_callback' );

function gform_pre_render_callback( $form )
{
    ob_start();
    ob_clean();
    $form_id = $form['id'];
    if ( isset( $_SESSION['trans_failed'] ) && !empty( $_SESSION['trans_failed'] ) ) {
        $msg = $_SESSION['trans_failed'];
        echo '<script type="text/javascript">';
        echo 'jQuery(document).ready(function($){';
        echo 'jQuery("#gform_' . $form_id . ' .gform_heading").append("<div class=\"validation_error\">' . $msg . '</div>")';
        echo '});';
        echo '</script>';
    } else if ( isset( $_SESSION['trans_declined'] ) && !empty( $_SESSION['trans_declined'] ) ) {
        $msg = $_SESSION['trans_declined'];
        echo '<script type="text/javascript">';
        echo 'jQuery(document).ready(function($){';
        echo 'jQuery("#gform_' . $form_id . ' .gform_heading").append("<div class=\"validation_error\">' . $msg . '</div>")';
        echo '});';
        echo '</script>';
    } else if ( isset( $_SESSION['trans_cancelled'] ) && !empty( $_SESSION['trans_cancelled'] ) ) {
        $msg = $_SESSION['trans_cancelled'];
        echo '<script type="text/javascript">';
        echo 'jQuery(document).ready(function($){';
        echo 'jQuery("#gform_' . $form_id . ' .gform_heading").append("<div class=\"validation_error\">' . $msg . '</div>")';
        echo '});';
        echo '</script>';
    }
    return $form;
}

add_filter( 'gform_pre_validation', 'cleanTransaction_status' );

function cleanTransaction_status( $form )
{
    unset( $_SESSION['trans_failed'] );
    unset( $_SESSION['trans_declined'] );
    unset( $_SESSION['trans_cancelled'] );
    return $form;
}

add_filter( 'gform_after_submission', 'gw_conditional_requirement' );

function gw_conditional_requirement( $form )
{
    if ( isset( $_SESSION['trans_failed'] ) && !empty( $_SESSION['trans_failed'] ) ) {
        $confirmation = $_SESSION['trans_failed'];
        add_filter( 'gform_validation_message', 'change_message', 10, 2 );
    } else if ( isset( $_SESSION['trans_declined'] ) && !empty( $_SESSION['trans_declined'] ) ) {
        $confirmation = $_SESSION['trans_declined'];
        add_filter( 'gform_validation_message', 'change_message', 10, 2 );
    }
    return $form;
}

/**
 * Encrypt and decrypt
 * @param string $string string to be encrypted/decrypted
 * @param string $action what to do with this? e for encrypt, d for decrypt
 */
function GF_encryption( $string, $action = 'e' )
{
    // you may change these values to your own
    $secret_key = AUTH_SALT;
    $secret_iv  = NONCE_SALT;

    $output         = false;
    $encrypt_method = "AES-256-CBC";
    $key            = hash( 'sha256', $secret_key );
    $iv             = substr( hash( 'sha256', $secret_iv ), 0, 16 );

    if ( $action == 'e' ) {
        $output = base64_encode( openssl_encrypt( $string, $encrypt_method, $key, 0, $iv ) );
    } else if ( $action == 'd' ) {
        $output = openssl_decrypt( base64_decode( $string ), $encrypt_method, $key, 0, $iv );
    }

    return $output;
}

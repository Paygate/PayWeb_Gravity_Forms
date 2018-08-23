<?php

/*
Plugin Name: Gravity Forms PayGate Add-On
Plugin URI: http://www.gravityforms.com
Description: Integrates Gravity Forms with PayGate, a South African payment gateway.
Version: 2.2.8
Author: PayGate (Pty) Ltd
Author URI: https://www.paygate.co.za/
Developer: App Inlet (Pty) Ltd
Developer URI: https://www.appinlet.com/
Text Domain: gravityformspaygate
Domain Path: /languages

------------------------------------------------------------------------
Copyright 2018 PayGate

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 */

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

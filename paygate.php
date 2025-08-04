<?php

/**
 * Plugin Name: Gravity Forms Paygate Add-On
 * Plugin URI: https://github.com/PayGate/PayWeb_Gravity_Forms
 * Description: Integrates Gravity Forms with Paygate, a South African payment gateway.
 * Version: 2.6.1
 * Tested: 6.8
 * Author: Payfast (Pty) Ltd
 * Author URI: https://payfast.io/
 * Developer: App Inlet (Pty) Ltd
 * Developer URI: https://www.appinlet.com/
 * Text Domain: gravityformspaygate
 * Domain Path: /languages
 *
 * Copyright: © 2025 Payfast (Pty) Ltd.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace PayGate\GravityFormsPayGatePlugin;

// phpcs:disable
require_once __DIR__ . '/vendor/autoload.php';

$ns = '\\' . __NAMESPACE__ . '\\';

add_action('plugins_loaded', "{$ns}paygate_init");
add_filter('gform_pre_render', "{$ns}gform_pre_render_callback");
add_filter('gform_pre_validation', "{$ns}cleanTransaction_status");
add_filter('gform_after_submission', "{$ns}gw_conditional_requirement");

ob_start();
if (!headers_sent() && empty(session_id())) {
    try {
        session_start();
    } catch (Exception $e) {
        // Catch exception
    }
}

add_action('gform_loaded', [Bootstrap::class, 'load'], 5);
// phpcs:enable

/**
 * Initialize the gateway.
 *
 * @since 1.0.0
 */

function paygate_init(): void
{
    /**
     * Auto updates from GIT
     *
     * @since 2.2.9
     *
     */

    if (is_admin() && strpos($_SERVER['PHP_SELF'], 'plugins.php') !== false) {
        // note the use of is_admin() to double check that this is happening in the admin and at the plugins page

        $config = [
            'slug'               => plugin_basename(__FILE__),
            'proper_folder_name' => 'gravity-forms-paygate-plugin',
            'api_url'            => 'https://api.github.com/repos/PayGate/PayWeb_Gravity_Forms',
            'raw_url'            => 'https://raw.github.com/PayGate/PayWeb_Gravity_Forms/master',
            'github_url'         => 'https://github.com/PayGate/PayWeb_Gravity_Forms',
            'zip_url'            => 'https://github.com/PayGate/PayWeb_Gravity_Forms/archive/master.zip',
            'homepage'           => 'https://github.com/PayGate/PayWeb_Gravity_Forms',
            'sslverify'          => true,
            'requires'           => '4.0',
            'tested'             => '6.0.2',
            'readme'             => 'README.md',
            'access_token'       => '',
        ];

        new Updater($config);
    }
}

/**
 * @param $message
 * @param $form
 *
 * @return mixed|string
 */
function change_message($message, $form)
{
    if (!empty($_SESSION['trans_failed']) && strlen($_SESSION['trans_failed']) > 0)
    {
        $err_msg = $_SESSION['trans_failed'];

        return "<div class='validation_error'>" . $_SESSION['trans_failed'] . '</div>';
    } elseif (!empty($_SESSION['trans_declined'])) {
        $err_msg = $_SESSION['trans_declined'];

        return "<div class='validation_error'>" . $_SESSION['trans_declined'] . '</div>';
    } else {
        return $message;
    }
}

/**
 * @param $form
 *
 * @return mixed
 */
function gform_pre_render_callback($form)
{
    ob_start();
    ob_clean();
    $form_id = $form['id'];
    define('SCRIPT', '<script type="text/javascript">');
    define('QUERY', 'jQuery(document).ready(function($){');
    define('QUERY_FORM', 'jQuery("#gform_');
    define('APPEND', ' .gform_heading").append("<div class=\"validation_error\">');
    define('DIV_TAG_CLOSING', '</div>")');
    define('SCRIPT_TAG_CLOSING', '</script>');

    if (!empty($_SESSION['trans_failed'])) {
        $msg = $_SESSION['trans_failed'];
        echo constant('SCRIPT');
        echo constant('Query');
        echo constant('QUERY_FORM') . $form_id . constant('APPEND') . $msg . constant('DIV_TAG_CLOSING');
        echo '});';
        echo constant('SCRIPT_TAG_CLOSING');
    } elseif (!empty($_SESSION['trans_declined'])) {
        $msg = $_SESSION['trans_declined'];
        echo constant('SCRIPT');
        echo constant('QUERY');
        echo constant('QUERY_FORM') . $form_id . constant('APPEND') . $msg . constant('DIV_TAG_CLOSING');
        echo '});';
        echo constant('SCRIPT_TAG_CLOSING');
    } elseif (!empty($_SESSION['trans_cancelled'])) {
        $msg = $_SESSION['trans_cancelled'];
        echo constant('SCRIPT');
        echo constant('QUERY');
        echo constant('QUERY_FORM') . $form_id . constant('APPEND') . $msg . constant('DIV_TAG_CLOSING');
        echo '});';
        echo constant('SCRIPT_TAG_CLOSING');
    }

    return $form;
}

/**
 * @param $form
 *
 * @return mixed
 */
function cleanTransaction_status($form)
{
    unset($_SESSION['trans_failed']);
    unset($_SESSION['trans_declined']);
    unset($_SESSION['trans_cancelled']);

    return $form;
}

/**
 * @param $form
 *
 * @return mixed
 */
function gw_conditional_requirement($form)
{
    global $ns;
    if (!empty($_SESSION['trans_failed'])) {
        $confirmation = $_SESSION['trans_failed'];
        add_filter('gform_validation_message', "{$ns}change_message", 10, 2);
    } elseif (!empty($_SESSION['trans_declined'])) {
        $confirmation = $_SESSION['trans_declined'];
        add_filter('gform_validation_message', "{$ns}change_message", 10, 2);
    }

    return $form;
}

/**
 * Encrypt and decrypt
 *
 * @param string $string string to be encrypted/decrypted
 * @param string $action what to do with this? e for encrypt, d for decrypt
 */
function GF_encryption(string $string, string $action = 'e')
{
    // you may change these values to your own
    /** @noinspection PhpUndefinedConstantInspection */
    $secret_key = AUTH_SALT;
    /** @noinspection PhpUndefinedConstantInspection */
    $secret_iv = NONCE_SALT;

    $output         = false;
    $encrypt_method = 'AES-256-CBC';
    $key            = hash('sha256', $secret_key);
    $iv             = substr(hash('sha256', $secret_iv), 0, 16);

    if ($action == 'e') {
        $output = base64_encode(openssl_encrypt($string, $encrypt_method, $key, 0, $iv));
    } elseif ($action == 'd') {
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
    }

    return $output;
}

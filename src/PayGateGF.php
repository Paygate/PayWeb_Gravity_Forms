<?php

/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\GravityFormsPayGatePlugin;

// phpcs:disable
use GFAPI;
use GFCommon;
use GFFormDisplay;
use GFForms;
use GFFormsModel;
use GFPaymentAddOn;
use Payfast\PayfastCommon\Gateway\Request\PaymentRequest;
use RGFormsModel;
use stdClass;
use WP_Error;
use WP_Post;

add_action('parse_request', [PayGateGF::class, 'notify_handler']);
add_action('wp', [PayGateGF::class, 'maybe_thankyou_page'], 5);
GFForms::include_payment_addon_framework();

// phpcs:enable

/**
 *
 */
class PayGateGF extends GFPaymentAddOn
{
    //phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
    //phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

    private const PAYGATE_REDIRECT_RESPONSE = 'Paygate Redirect Response';
    private const PAYGATE_NOTIFY_RESPONSE   = 'Paygate Notify Response';

    public const         INIT_TRANS_URL     = 'https://secure.paygate.co.za/payweb3/initiate.trans';
    public const         QUERY_TRANS_URL    = 'https://secure.paygate.co.za/payweb3/query.trans';
    public const         PROCESSS_TRANS_URL = 'https://secure.paygate.co.za/payweb3/process.trans';
    private const        DATE               = 'y-m-d H:i:s';
    private static $_instance = null;
    protected $_min_gravityforms_version = '2.2.5';
    protected $_slug = 'gravityformspaygate';
    protected $_path = 'gravityformspaygate/paygate.php';
    protected $_full_path = __FILE__;
    protected $_url = 'http://www.gravityforms.com';
    protected $_title = 'Gravity Forms Paygate Add-On';
    protected $_short_title = 'Paygate';
    // Permissions
    protected $_supports_callbacks = true;
    protected $_capabilities = ['gravityforms_paygate', 'gravityforms_paygate_uninstall'];
    protected $_capabilities_settings_page = 'gravityforms_paygate';
    // Automatic upgrade enabled
    protected $_capabilities_form_settings = 'gravityforms_paygate';
    protected $_capabilities_uninstall = 'gravityforms_paygate_uninstall';
    protected $_enable_rg_autoupgrade = false;

    private const H6_TAG         = '<h6>';
    private const H6_TAG_CLOSING = '</h6>';

    /**
     * @return PayGateGF|null
     */
    public static function get_instance()
    {
        if (self::$_instance == null) {
            self::$_instance = new PayGateGF();
        }

        return self::$_instance;
    }

    /**
     * @return void
     */
    public static function maybe_thankyou_page()
    {
        $instance = self::get_instance();

        if (!$instance->is_gravityforms_supported()) {
            return;
        }

        if ($str = rgget('gf_paygate_return')) {
            $str = GF_encryption($str, 'd');

            parse_str($str, $query);
            if (wp_hash('ids=' . $query['ids']) == $query['hash']) {
                list($form_id, $lead_id, $user_id, $feed_id) = explode('|', $query['ids']);

                $form = GFAPI::get_form($form_id);
                GFAPI::get_entry($lead_id);

                $feed = GFAPI::get_feeds($feed_id, $form_id);
                // add `eid` to use Merge Tags on confirmation page.
                $eid                 = GF_encryption($lead_id);
                $confirmationPageUrl = $feed['0']['meta']['failedPageUrl'];
                $confirmationPageUrl = add_query_arg(['eid' => $eid], $confirmationPageUrl);

                $payGate     = new PayGate();
                $status_desc = 'failed';

                $pay_request_id = $payGate->accessValue('PAY_REQUEST_ID', 'post');
                GFAPI::update_entry_property($lead_id, 'transaction_id', $pay_request_id);

                $disableIPN = isset($feed['0']['meta']['disableipn']) && $feed['0']['meta']['disableipn'] == 'yes';

                $lead = RGFormsModel::get_lead($lead_id);

                $leadHasNotBeenProcessed = isset($lead['payment_status']) && $lead['payment_status'] != 'Approved';

                switch ($payGate->accessValue('TRANSACTION_STATUS', 'post')) {
                    case '1':
                        $status_desc = 'approved';
                        if ($disableIPN) {
                            if ($leadHasNotBeenProcessed) {
                                GFAPI::update_entry_property($lead_id, 'payment_status', 'Approved');
                                GFFormsModel::add_note(
                                    $lead_id,
                                    '',
                                    self::PAYGATE_REDIRECT_RESPONSE,
                                    'Transaction Approved, Pay Request ID: ' . $pay_request_id
                                );
                                GFAPI::send_notifications($form, $lead, 'complete_payment');
                            } else {
                                GFFormsModel::add_note(
                                    $lead_id,
                                    '',
                                    self::PAYGATE_REDIRECT_RESPONSE,
                                    'Avoided additional process of this lead: ' . $pay_request_id
                                );
                            }
                        }
                        $confirmationPageUrl = $feed['0']['meta']['successPageUrl'];
                        $confirmationPageUrl = add_query_arg(['eid' => $eid], $confirmationPageUrl);
                        break;
                    case '4':
                        $status_desc = 'cancelled';
                        if ($disableIPN) {
                            GFAPI::update_entry_property($lead_id, 'payment_status', 'Cancelled');
                            GFFormsModel::add_note(
                                $lead_id,
                                '',
                                self::PAYGATE_REDIRECT_RESPONSE,
                                'Transaction Cancelled, Pay Request ID: ' . $pay_request_id
                            );
                        }
                        $confirmationPageUrl = $feed['0']['meta']['cancelUrl'];
                        $confirmationPageUrl = add_query_arg(['eid' => $eid], $confirmationPageUrl);
                        break;
                    default:
                        if ($disableIPN) {
                            GFAPI::update_entry_property($lead_id, 'payment_status', 'Declined');
                            GFFormsModel::add_note(
                                $lead_id,
                                '',
                                self::PAYGATE_REDIRECT_RESPONSE,
                                'Transaction declined, Pay Request ID: ' . $pay_request_id
                            );
                        }
                        $confirmationPageUrl = $feed['0']['meta']['failedPageUrl'];
                        $confirmationPageUrl = add_query_arg(['eid' => $eid], $confirmationPageUrl);
                        break;
                }

                if (!class_exists('\GFFormDisplay')) {
                    require_once GFCommon::get_base_path() . '/form_display.php';
                }

                if ($feed['0']['meta']['useCustomConfirmationPage'] == 'yes') {
                    wp_redirect($confirmationPageUrl);
                    exit;
                } else {
                    $confirmation_msg = 'Thanks for contacting us! We will get in touch with you shortly.';
                    // Display the correct message depending on transaction status
                    foreach ($form['confirmations'] as $row) {
                        foreach ($row as $val) {
                            if (is_array($val)) {
                                continue;
                            }
                            $updatedVal = str_replace(' ', '', $val);
                            // This condition does NOT working when using the Custom Confirmation Page setting
                            if (is_string($updatedVal) && $status_desc == strtolower($updatedVal)) {
                                $confirmation_msg = $row['message'];
                                $confirmation_msg = apply_filters('the_content', $confirmation_msg);
                                $confirmation_msg = str_replace(']]>', ']]&gt;', $confirmation_msg);
                            }
                        }
                    }
                    $confirmation_msg = apply_filters('the_content', $confirmation_msg);

                    GFFormDisplay::$submission[$form_id] = [
                        'is_confirmation'      => true,
                        'confirmation_message' => $confirmation_msg,
                        'form'                 => $form,
                        'lead'                 => $lead
                    ];
                }
            }
        }
    }

    /**
     * @return void
     */
    public static function notify_handler()
    {
        if (isset($_POST['PAY_REQUEST_ID']) && isset($_GET['page'])) {
            // Notify paygate that the request was successful
            echo 'OK   ';

            $payRequestId = $_POST['PAY_REQUEST_ID'];
            $transient    = get_transient($payRequestId);
            if (!$transient) {
                set_transient($payRequestId, '1', 10);
            } else {
                return;
            }

            $payGate  = new PayGate();
            $instance = self::get_instance();

            $errors       = false;
            $paygate_data = [];

            $notify_data = [];
            $post_data   = '';
            // Get notify data
            $paygate_data = $payGate->getPostData();
            $instance->log_debug('Get posted data');
            if ($paygate_data === false) {
                $errors = true;
            }

            $entry = GFAPI::get_entry($paygate_data['REFERENCE']);
            if (!$entry) {
                $instance->log_error("Entry could not be found. Entry ID: {$paygate_data['REFERENCE']}. Aborting.");

                return;
            }

            $instance->log_debug('Entry has been found.' . print_r($entry, true));

            // Verify security signature
            $checkSumParams = '';
            if (!$errors) {
                foreach ($paygate_data as $key => $val) {
                    $post_data         .= $key . '=' . $val . "\n";
                    $notify_data[$key] = stripslashes($val);

                    if ($key == 'PAYGATE_ID') {
                        $checkSumParams .= $val;
                    }
                    if ($key != 'CHECKSUM' && $key != 'PAYGATE_ID') {
                        $checkSumParams .= $val;
                    }

                    if (empty($notify_data)) {
                        $errors = true;
                    }
                }
            }

            // Check status and update order
            if (!$errors) {
                $instance->log_debug('Check status and update order');

                $lead = RGFormsModel::get_lead($notify_data['REFERENCE']);

                $leadHasNotBeenProcessed = isset($lead['payment_status']) && $lead['payment_status'] != 'Approved';

                switch ($paygate_data['TRANSACTION_STATUS']) {
                    case '1':
                        if ($leadHasNotBeenProcessed) {
                            // Creates transaction
                            GFAPI::update_entry_property($notify_data['REFERENCE'], 'payment_status', 'Approved');
                            GFAPI::update_entry_property(
                                $notify_data['REFERENCE'],
                                'transaction_id',
                                $notify_data['REFERENCE']
                            );
                            GFAPI::update_entry_property($notify_data['REFERENCE'], 'transaction_type', '1');
                            GFAPI::update_entry_property(
                                $notify_data['REFERENCE'],
                                'payment_amount',
                                number_format($notify_data['AMOUNT'] / 100, 2, ',', '')
                            );
                            GFAPI::update_entry_property($notify_data['REFERENCE'], 'is_fulfilled', '1');
                            GFAPI::update_entry_property($notify_data['REFERENCE'], 'payment_method', 'Paygate');
                            GFAPI::update_entry_property(
                                $notify_data['REFERENCE'],
                                'payment_date',
                                gmdate('y-m-d H:i:s')
                            );
                            GFFormsModel::add_note(
                                $notify_data['REFERENCE'],
                                '',
                                self::PAYGATE_NOTIFY_RESPONSE,
                                'Transaction approved, Paygate TransId: ' . $notify_data['TRANSACTION_ID']
                            );
                            $form = GFAPI::get_form($entry['form_id']);
                            GFAPI::send_notifications($form, $entry, 'complete_payment');
                        } else {
                            GFFormsModel::add_note(
                                $notify_data['REFERENCE'],
                                '',
                                self::PAYGATE_NOTIFY_RESPONSE,
                                'Avoided additional process of this lead, Paygate TransId: '
                                . $notify_data['TRANSACTION_ID']
                            );
                        }
                        break;

                    default:
                        GFFormsModel::add_note(
                            $notify_data['REFERENCE'],
                            '',
                            self::PAYGATE_NOTIFY_RESPONSE,
                            'Transaction declined, Paygate TransId: ' . $notify_data['TRANSACTION_ID']
                        );
                        GFAPI::update_entry_property($notify_data['REFERENCE'], 'payment_status', 'Declined');
                        break;
                }

                $instance->log_debug('Send notifications.');
                $instance->log_debug($entry);
                GFFormsModel::get_form_meta($entry['form_id']);
            }
        }
    }

    //----- SETTINGS PAGES ----------//

    /**
     * @param $entry
     *
     * @return array|false|mixed
     */
    public static function get_config_by_entry($entry)
    {
        $paygate = PayGateGF::get_instance();

        $feed = $paygate->get_payment_feed($entry);

        if (empty($feed)) {
            return false;
        }

        return $feed['addon_slug'] == $paygate->_slug ? $feed : false;
    }

    /**
     * @param $form_id
     *
     * @return false|mixed|stdClass
     */
    public static function get_config($form_id)
    {
        $paygate = PayGateGF::get_instance();
        $feed    = $paygate->get_feeds($form_id);

        // Ignore ITN messages from forms that are no longer configured with the Paygate add-on
        if (!$feed) {
            return false;
        }

        return $feed[0]; // Only one feed per form is supported (left for backwards compatibility)
    }

    /**
     * @return void
     */
    public function init_frontend()
    {
        parent::init_frontend();

        add_filter('gform_disable_post_creation', [$this, 'delay_post'], 10, 3);

        add_action(
            'gform_post_payment_action',
            function ($entry, $action) {
                $form = GFAPI::get_form($entry['form_id']);
                GFAPI::send_notifications($form, $entry, rgar($action, 'type'));
            },
            10,
            2
        );
    }

    /**
     * @return array[]
     */
    public function plugin_settings_fields(): array
    {
        // Call the parent method to ensure any essential logic from the parent is executed.
        $parent_fields = parent::plugin_settings_fields();

        $custom_fields = PaygateGFForm::get_plugin_settings_fields();

        // Merge parent fields with custom fields, ensuring both sets are included.
        return array_merge($parent_fields, $custom_fields);
    }

    /**
     * @return string
     */
    public function feed_list_no_item_message()
    {
        $settings = $this->get_plugin_settings();
        if (!rgar($settings, 'gf_paygate_configured')) {
            return sprintf(
                __('To get started, configure your %sPaygate Settings%s!', 'gravityformspaygate'),
                '<a href="' . admin_url('admin.php?page=gf_settings&subview=' . $this->_slug) . '">',
                '</a>'
            );
        } else {
            return parent::feed_list_no_item_message();
        }
    }

    /**
     * @return array[]|mixed|null
     */
    public function feed_settings_fields()
    {
        $default_settings = parent::feed_settings_fields();

        //--add Paygate fields
        $fields = PaygateGFForm::get_default_settings_fields();

        $default_settings = parent::add_field_after('feedName', $fields, $default_settings);
        //--------------------------------------------------------------------------------------

        $message          = [
            'name'  => 'message',
            'label' => __('Paygate does not currently support subscription billing', 'gravityformsstripe'),
            'style' => 'width:40px;text-align:center;',
            'type'  => 'checkbox',
        ];
        $default_settings = $this->add_field_after('trial', $message, $default_settings);

        $default_settings = $this->remove_field('recurringTimes', $default_settings);
        $default_settings = $this->remove_field('billingCycle', $default_settings);
        $default_settings = $this->remove_field('recurringAmount', $default_settings);
        $default_settings = $this->remove_field('setupFee', $default_settings);
        $default_settings = $this->remove_field('trial', $default_settings);

        // Add donation to transaction type drop down
        $transaction_type = parent::get_field('transactionType', $default_settings);
        $choices          = $transaction_type['choices'];

        // Check if 'donation' already exists in choices
        $donation_exists = array_filter($choices, fn($choice) => $choice['value'] === 'donation');

        if (!$donation_exists) {
            // Add donation transaction type
            $choices[] = ['label' => __('Donations', 'gravityformspaygate'), 'value' => 'donation'];
        }

        $transaction_type['choices'] = $choices;
        $default_settings            = $this->replace_field(
            'transactionType',
            $transaction_type,
            $default_settings
        );
        //-------------------------------------------------------------------------------------------------

        $fields = [
            [
                'name'  => 'logo',
                'label' => __('Paygate', 'gravityformspaygate'),
                'type'  => 'custom'
            ],
        ];

        $default_settings = $this->add_field_before('feedName', $fields, $default_settings);

        // Add Page Style, Continue Button Label, Cancel URL
        $fields = PaygateGFForm::get_cancel_url();

        // Add post fields if form has a post
        $form = $this->get_current_form();
        if (GFCommon::has_post_field($form['fields'])) {
            $post_settings = PaygateGFForm::get_post_settings();

            if ($this->get_setting('transactionType') == 'subscription') {
                $post_settings['choices'][] = [
                    'label'    => __('Change post status when subscription is canceled.', 'gravityformspaygate'),
                    'name'     => 'change_post_status',
                    'onChange' => 'var action = this.checked ? "draft" : "";
                     jQuery("#update_post_action").val(action);',
                ];
            }

            $fields[] = $post_settings;
        }

        // Adding custom settings for backwards compatibility with hook 'gform_paygate_add_option_group'
        $fields[] = [
            'name'  => 'custom_options',
            'label' => '',
            'type'  => 'custom',
        ];

        $default_settings = $this->add_field_after('billingInformation', $fields, $default_settings);
        //-----------------------------------------------------------------------------------------
        // Get billing info section and add customer first/last name
        $billing_info   = parent::get_field('billingInformation', $default_settings);
        $billing_fields = $billing_info['field_map'];
        $add_first_name = true;
        $add_last_name  = true;
        foreach ($billing_fields as $mapping) {
            // Add first/last name if it does not already exist in billing fields
            if ($mapping['name'] == 'firstName') {
                $add_first_name = false;
            } elseif ($mapping['name'] == 'lastName') {
                $add_last_name = false;
            }
        }

        if ($add_last_name) {
            // Add last name
            array_unshift(
                $billing_info['field_map'],
                [
                    'name'     => 'lastName',
                    'label'    => __('Last Name', 'gravityformspaygate'),
                    'required' => false
                ]
            );
        }
        if ($add_first_name) {
            array_unshift(
                $billing_info['field_map'],
                [
                    'name'     => 'firstName',
                    'label'    => __('First Name', 'gravityformspaygate'),
                    'required' => false
                ]
            );
        }
        $default_settings = parent::replace_field('billingInformation', $billing_info, $default_settings);

        return apply_filters('gform_paygate_feed_settings_fields', $default_settings, $form);
    }

    /**
     * @return string|null
     */
    public function field_map_title()
    {
        // Call the parent method if it exists and combine/override its behavior
        $parentTitle = parent::field_map_title(); // Ensure this line works without causing errors
        return $parentTitle . ' - ' . __('Paygate Field', 'gravityformspaygate');
    }

    /**
     * @param $field
     * @param bool $echo
     *
     * @return string
     */
    public function settings_trial_period($field, bool $echo = true)
    {
        // Use the parent billing cycle function to make the drop down for the number and type
        return parent::settings_billing_cycle($field);
    }

    /**
     * @param $field
     *
     * @return string
     */
    public function set_trial_onchange($field)
    {
        // Call the parent implementation if it exists
        $parentJs = parent::set_trial_onchange($field);

        // Return the javascript for the onchange event
        $customJs =  "
        if(jQuery(this).prop('checked')){
            jQuery('#{$field['name']}_product').show('slow');
            jQuery('#gaddon-setting-row-trialPeriod').show('slow');
            if (jQuery('#{$field['name']}_product').val() == 'enter_amount'){
                jQuery('#{$field['name']}_amount').show('slow');
            }
            else{
                jQuery('#{$field['name']}_amount').hide();
            }
        }
        else {
            jQuery('#{$field['name']}_product').hide('slow');
            jQuery('#{$field['name']}_amount').hide();
            jQuery('#gaddon-setting-row-trialPeriod').hide('slow');
        }";
        // Combine parent and custom JavaScript
        return $parentJs . $customJs;
    }

    /**
     * @param $field
     * @param bool $echo
     *
     * @return string
     */
    public function settings_options($field, bool $echo = true)
    {
        $checkboxes = PaygateGFForm::get_settings_options_checkboxes();

        $html = $this->settings_checkbox($checkboxes, false);

        //--------------------------------------------------------
        // For backwards compatibility.
        ob_start();
        do_action('gform_paygate_action_fields', $this->get_current_feed(), $this->get_current_form());
        $html .= ob_get_clean();
        //--------------------------------------------------------

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    /**
     * @param $field
     * @param bool $echo
     *
     * @return false|string
     */
    public function settings_custom($field, bool $echo = true)
    {
        ob_start();
        ?>
        <div id='gf_paygate_custom_settings'>
            <?php
            do_action('gform_paygate_add_option_group', $this->get_current_feed(), $this->get_current_form());
            ?>
        </div>

        <script type='text/javascript'>
          jQuery(document).ready(function () {
            jQuery('#gf_paygate_custom_settings label.left_header').css('margin-left', '-200px');
          });
        </script>

        <?php
        $html = ob_get_clean();

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    //------ SENDING TO PAYGATE -----------//

    /**
     * @param $choice
     * @param $attributes
     * @param $value
     * @param $tooltip
     *
     * @return string
     */
    public function checkbox_input_change_post_status($choice, $attributes, $value, $tooltip)
    {
        $markup = $this->checkbox_input($choice, $attributes, $value, $tooltip);

        $dropdown_field = PaygateGFForm::get_dropdown_field();
        $markup         .= '&nbsp;&nbsp;' . $this->settings_select($dropdown_field, false);

        return $markup;
    }

    /**
     * @return false
     */
    public function option_choices()
    {
        // Call the parent method if it exists and append or modify the behavior as needed
        $parent_choices = parent::option_choices();

        return false;
    }

    /**
     * @param $feed_id
     * @param $form_id
     * @param $settings
     *
     * @return false|int
     */
    public function save_feed_settings($feed_id, $form_id, $settings)
    {
        //--------------------------------------------------------
        // For backwards compatibility
        $feed = $this->get_feed($feed_id);

        // Saving new fields into old field names to maintain backwards compatibility for delayed payments
        $settings['type'] = $settings['transactionType'];

        if (isset($settings['recurringAmount'])) {
            $settings['recurring_amount_field'] = $settings['recurringAmount'];
        }

        $feed['meta'] = $settings;
        $feed         = apply_filters('gform_paygate_save_config', $feed);

        // Call hook to validate custom settings/meta added using gform_paygate_action_fields or
        // gform_paygate_add_option_group action hooks
        $is_validation_error = apply_filters('gform_paygate_config_validation', false, $feed);
        if ($is_validation_error) {
            // Fail save
            return false;
        }

        $settings = $feed['meta'];

        //--------------------------------------------------------

        return parent::save_feed_settings($feed_id, $form_id, $settings);
    }

    /**
     * @param $feed
     * @param $submission_data
     * @param $form
     * @param $entry
     *
     * @return false|string
     */
    public function redirect_url($feed, $submission_data, $form, $entry)
    {
        // Call the parent method if applicable
        $parent_return = parent::redirect_url($feed, $submission_data, $form, $entry);

        // Don't process redirect url if request is a Paygate return
        if (!rgempty('gf_paygate_return', $_GET)) {
            return false;
        }

        // Unset transaction session on re-submit
        unset($_SESSION['trans_failed']);
        unset($_SESSION['trans_declined']);
        unset($_SESSION['trans_cancelled']);

        // Updating lead's payment_status to Processing
        GFAPI::update_entry_property($entry['id'], 'payment_status', 'Pending');

        // Set return mode to 2 (Paygate will post info back to page). rm=1 seems to create lots of problems
        // with the redirect back to the site. Defaulting it to 2.

        $return_mode = '2';

        $return_url = PaygateGFUtilities::return_url(
            $form['id'],
            $entry['id'],
            $entry['created_by'],
            $feed['id']
        );
        $return_url .= "&rm=$return_mode";
        $eid        = GF_encryption($entry['id']);
        $return_url = add_query_arg(['eid' => $eid], $return_url);

        // URL that will listen to notifications from Paygate
        $notify_url   = get_bloginfo('url') . '/?page=gf_paygate';
        $merchant_id  = $feed['meta']['testmode'] == 'no' ? $feed['meta']['paygateMerchantId'] : '10011072130';
        $merchant_key = $feed['meta']['testmode'] == 'no' ? $feed['meta']['paygateMerchantKey'] : 'secret';

        $country_code3 = null;
        $country_code2 = strtoupper(GFCommon::get_country_code($submission_data['country']));

        // Set country_code3 conditionally if needed
        if ($country_code3 === null || $country_code2 === '') {
            $country_code3 = 'ZAF'; // Default value
        }

        $currency = rgar( $entry, 'currency' ) ?? GFCommon::get_currency();

        $fields = [
            'REFERENCE'        => $entry['id'],
            'AMOUNT'           => number_format(
                GFCommon::get_order_total($form, $entry),
                2,
                '',
                ''
            ),
            'CURRENCY'         => $currency,
            'RETURN_URL'       => $return_url,
            'TRANSACTION_DATE' => date('Y-m-d H:m:s'),
            'LOCALE'           => 'en-za',
            'COUNTRY'          => $country_code3,
            'EMAIL'            => $submission_data['email'],
        ];
        // Check if IPN is disabled
        if (!isset($feed['meta']['disableipn']) || $feed['meta']['disableipn'] != 'yes') {
            $fields['NOTIFY_URL'] = $notify_url;
        }

        $fields['USER1'] = $entry['created_by'];
        $fields['USER2'] = get_bloginfo('admin_email');
        $fields['USER3'] = 'gravityforms-v2.6.1';

        $paymentRequest = new PaymentRequest($merchant_id, $merchant_key);
        $paygate = new PayGate();
        $response           = $paymentRequest->initiate($fields);

        parse_str($response, $fields);

        unset($fields['CHECKSUM']);
        $checksum = md5(implode('', $fields) . $merchant_key);
        $htlmForm = $paymentRequest->getRedirectHTML($fields['PAY_REQUEST_ID'], $checksum);

        print $paygate->getPaygatePostForm($htlmForm);

        return '';
    }

    /**
     * @param $submission_data
     * @param $entry_id
     *
     * @return false|string
     */
    public function get_product_query_string($submission_data, $entry_id)
    {
        if (empty($submission_data)) {
            return false;
        }

        $query_string   = '';
        $payment_amount = rgar($submission_data, 'payment_amount');
        $line_items     = rgar($submission_data, 'line_items');
        $discounts      = rgar($submission_data, 'discounts');

        $product_index = 1;
        $shipping      = '';
        $discount_amt  = 0;
        $cmd           = '_cart';
        $extra_qs      = '&upload=1';

        // Work on products
        if (is_array($line_items)) {
            foreach ($line_items as $item) {
                $product_name = urlencode($item['name']);
                $quantity     = $item['quantity'];
                $unit_price   = $item['unit_price'];
                $options      = rgar($item, 'options');
                $is_shipping  = rgar($item, 'is_shipping');

                if ($is_shipping) {
                    // Populate shipping info
                    $shipping .= !empty($unit_price) ? "&shipping_1=$unit_price" : '';
                } else {
                    // Add product info to querystring
                    $query_string .= "&item_name_$product_index=$product_name";
                    $query_string .= "&amount_$product_index=$unit_price&quantity_$product_index=$quantity";
                }
                // Add options
                if (!empty($options) && is_array($options)) {
                    $option_index = 1;
                    foreach ($options as $option) {
                        $option_label = urlencode($option['field_label']);
                        $option_name  = urlencode($option['option_name']);
                        $query_string .= "&on{$option_index}_$product_index=$option_label";
                        $query_string .= "&os{$option_index}_$product_index=$option_name";
                        $option_index++;
                    }
                }
                $product_index++;
            }
        }

        // Look for discounts
        if (is_array($discounts)) {
            foreach ($discounts as $discount) {
                $discount_full = abs($discount['unit_price']) * $discount['quantity'];
                $discount_amt  += $discount_full;
            }
            if ($discount_amt > 0) {
                $query_string .= "&discount_amount_cart=$discount_amt";
            }
        }

        $query_string .= "$shipping&cmd=$cmd$extra_qs";

        // Save payment amount to lead meta
        gform_update_meta($entry_id, 'payment_amount', $payment_amount);

        return $payment_amount > 0 ? $query_string : false;
    }

    /**
     * @param $submission_data
     * @param $entry_id
     *
     * @return false|string
     */
    public function get_donation_query_string($submission_data, $entry_id)
    {
        if (empty($submission_data)) {
            return false;
        }

        $payment_amount = rgar($submission_data, 'payment_amount');
        $line_items     = rgar($submission_data, 'line_items');
        $purpose        = '';
        $cmd            = '_donations';

        // Work on products
        if (is_array($line_items)) {
            foreach ($line_items as $item) {
                $product_name    = $item['name'];
                $quantity        = $item['quantity'];
                $quantity_label  = $quantity > 1 ? $quantity . ' ' : '';
                $options         = rgar($item, 'options');
                $is_shipping     = rgar($item, 'is_shipping');
                $product_options = '';

                if (!$is_shipping) {
                    // Add options
                    if (!empty($options) && is_array($options)) {
                        $product_options = ' (';
                        foreach ($options as $option) {
                            $product_options .= $option['option_name'] . ', ';
                        }
                        $product_options = substr($product_options, 0, strlen($product_options) - 2) . ')';
                    }
                    $purpose .= $quantity_label . $product_name . $product_options . ', ';
                }
            }
        }

        if (!empty($purpose)) {
            $purpose = substr($purpose, 0, strlen($purpose) - 2);
        }

        $purpose = urlencode($purpose);

        // Truncating to maximum length allowed by Paygate
        if (strlen($purpose) > 127) {
            $purpose = substr($purpose, 0, 124) . '...';
        }

        $query_string = "&amount=$payment_amount&item_name=$purpose&cmd=$cmd";

        // Save payment amount to lead meta
        gform_update_meta($entry_id, 'payment_amount', $payment_amount);

        return $payment_amount > 0 ? $query_string : false;
    }

    /**
     * @param $feed
     * @param $lead
     *
     * @return string
     */
    public function customer_query_string($feed, $lead)
    {
        $fields = '';
        foreach (PaygateGFUtilities::get_customer_fields() as $field) {
            $field_id = $feed['meta'][$field['meta_name']];
            $value    = rgar($lead, $field_id);

            if ($field['name'] == 'country') {
                $value = class_exists('GF_Field_Address') ? GF_Fields::get('address')->get_country_code(
                    $value
                ) : GFCommon::get_country_code($value);
            } elseif ($field['name'] == 'state') {
                $value = class_exists('GF_Field_Address') ? GF_Fields::get('address')->get_us_state_code(
                    $value
                ) : GFCommon::get_us_state_code($value);
            }

            if (!empty($value)) {
                $fields .= "&{$field['name']}=" . urlencode($value);
            }
        }

        return $fields;
    }

    //------- PROCESSING PAYGATE (Callback) -----------//

    /**
     * @param $is_disabled
     * @param $form
     * @param $entry
     *
     * @return bool
     */
    public function delay_post($is_disabled, $form, $entry)
    {
        $feed            = $this->get_payment_feed($entry);
        $submission_data = $this->get_submission_data($feed, $form, $entry);

        if (!$feed || empty($submission_data['payment_amount'])) {
            return $is_disabled;
        }

        return !rgempty('delayPost', $feed['meta']);
    }

    /**
     * @param $is_disabled
     * @param $notification
     * @param $form
     * @param $entry
     *
     * @return true
     */
    public function delay_notification($is_disabled, $notification, $form, $entry)
    {
        $this->log_debug('Delay notification ' . $notification . ' for ' . $entry['id'] . '.');
        $feed            = $this->get_payment_feed($entry);
        $submission_data = $this->get_submission_data($feed, $form, $entry);

        if (!$feed || empty($submission_data['payment_amount'])) {
            return $is_disabled;
        }

        $selected_notifications = is_array(rgar($feed['meta'], 'selectedNotifications')) ? rgar(
            $feed['meta'],
            'selectedNotifications'
        ) : [];

        return isset($feed['meta']['delayNotification']) && in_array(
            $notification['id'],
            $selected_notifications
        ) ? true : $is_disabled;
    }

    // Notification

    /**
     * @param $entry
     * @param array|bool $form
     *
     * @return array|false|mixed|null
     */
    public function get_payment_feed($entry, $form = false)
    {
        $feed = parent::get_payment_feed($entry, $form);

        if (empty($feed) && !empty($entry['id'])) {
            // Looking for feed created by legacy versions
            $feed = $this->get_paygate_feed_by_entry($entry['id']);
        }

        return apply_filters('gform_paygate_get_payment_feed', $feed, $entry, $form);
    }

    /**
     * @param $custom_field
     *
     * @return array|false|WP_Error
     */
    public function get_entry($custom_field)
    {
        if (empty($custom_field)) {
            $this->log_error(
                __METHOD__ . '(): ITN request does not have a custom field, so it was not created by Gravity Forms.
                 Aborting.'
            );

            return false;
        }

        // Getting entry associated with this ITN message (entry id is sent in the 'custom' field)
        list($entry_id, $hash) = explode('|', $custom_field);
        $hash_matches = wp_hash($entry_id) == $hash;

        // Allow the user to do some other kind of validation of the hash
        $hash_matches = apply_filters('gform_paygate_hash_matches', $hash_matches, $entry_id, $hash, $custom_field);

        // Validates that Entry Id wasn't tampered with
        if (!rgpost('test_itn') && !$hash_matches) {
            $this->log_error(
                __METHOD__ . "(): Entry Id verification failed. Hash does not match. Custom field: $custom_field.
                 Aborting."
            );

            return false;
        }

        $this->log_debug(__METHOD__ . "(): ITN message has a valid custom field: $custom_field");

        $entry = GFAPI::get_entry($entry_id);

        if (is_wp_error($entry)) {
            $this->log_error(__METHOD__ . '(): ' . $entry->get_error_message());

            return false;
        }

        return $entry;
    }

    /**
     * @param $post_id
     * @param $action
     *
     * @return array|false|int|WP_Error|WP_Post|null
     */
    public function modify_post($post_id, $action)
    {
        if (!$post_id) {
            return false;
        }

        switch ($action) {
            case 'draft':
                $post              = get_post($post_id);
                $post->post_status = 'draft';
                $result            = wp_update_post($post);
                $this->log_debug(__METHOD__ . "(): Set post (#$post_id) status to \"draft\".");
                break;
            case 'delete':
                $result = wp_delete_post($post_id);
                $this->log_debug(__METHOD__ . "(): Deleted post (#$post_id).");
                break;
            default:
                return false;
        }

        return $result;
    }

    /**
     * @return bool
     */
    public function is_callback_valid()
    {
        // Call the parent method first, if it exists
        if (!parent::is_callback_valid()) {
            return false;
        }

        if (rgget('page') != 'gf_paygate') {
            return false;
        }

        return true;
    }

    /**
     * @return void
     */
    public function init_ajax()
    {
        parent::init_ajax();

        add_action('wp_ajax_gf_dismiss_paygate_menu', [$this, 'ajax_dismiss_menu']);
    }

    //------- AJAX FUNCTIONS ------------------//

    /**
     * @return void
     */
    public function init_admin()
    {
        parent::init_admin();

        // Add actions to allow the payment status to be modified
        add_action('gform_payment_status', [$this, 'admin_edit_payment_status'], 3, 3);

        if (version_compare(GFCommon::$version, '1.8.17.4', '<')) {
            // Using legacy hook
            add_action('gform_entry_info', [$this, 'admin_edit_payment_status_details'], 4, 2);
        } else {
            add_action('gform_payment_date', [$this, 'admin_edit_payment_date'], 3, 3);
            add_action('gform_payment_transaction_id', [$this, 'admin_edit_payment_transaction_id'], 3, 3);
            add_action('gform_payment_amount', [$this, 'admin_edit_payment_amount'], 3, 3);
        }

        add_action('gform_after_update_entry', [$this, 'admin_update_payment'], 4, 2);

        add_filter('gform_addon_navigation', [$this, 'maybe_create_menu']);

        add_filter('gform_notification_events', [$this, 'notification_events_dropdown'], 10, 2);
    }

    //------- ADMIN FUNCTIONS/HOOKS -----------//

    /**
     * @param $notification_events
     *
     * @return array
     */
    public function notification_events_dropdown($notification_events)
    {
        $payment_events = [
            'complete_payment' => __('Payment Complete', 'gravityforms')
        ];

        return array_merge($notification_events, $payment_events);
    }

    /**
     * @param $menus
     *
     * @return mixed
     */
    public function maybe_create_menu($menus)
    {
        $current_user         = wp_get_current_user();
        $dismiss_paygate_menu = get_metadata('user', $current_user->ID, 'dismiss_paygate_menu', true);
        if ($dismiss_paygate_menu != '1') {
            $menus[] = [
                'name'       => $this->_slug,
                'label'      => $this->get_short_title(),
                'callback'   => [$this, 'temporary_plugin_page'],
                'permission' => $this->_capabilities_form_settings
            ];
        }

        return $menus;
    }

    /**
     * @return void
     */
    public function ajax_dismiss_menu()
    {
        $current_user = wp_get_current_user();
        update_metadata('user', $current_user->ID, 'dismiss_paygate_menu', '1');
    }

    /**
     * @return void
     */
    public function temporary_plugin_page()
    {
        ?>
        <script type="text/javascript">
          function dismissMenu() {
            jQuery('#gf_spinner').show();
            jQuery.post(ajaxurl, {
                action: 'gf_dismiss_paygate_menu'
              },
              function (response) {
                document.location.href = '?page=gf_edit_forms';
                jQuery('#gf_spinner').hide();
              }
            );

          }
        </script>

        <div class="wrap about-wrap">
            <h1><?php
                _e('Paygate Add-On', 'gravityformspaygate') ?></h1>
            <div class="about-text">
                <?php
                _e(
                    'Thank you for updating! The new version of the Gravity Forms Paygate Add-On makes changes
                     to how you manage your Paygate integration.',
                    'gravityformspaygate'
                );
                ?>
            </div>
            <div class="changelog">
                <hr/>
                <div class="feature-section col two-col">
                    <div class="col-1">
                        <h3><?php
                            _e('Manage Paygate Contextually', 'gravityformspaygate') ?></h3>
                        <p>
                            <?php
                            _e(
                                'Paygate Feeds are now accessed via the Paygate sub-menu within the
                                 Form Settings for the Form you would like to integrate Paygate with.',
                                'gravityformspaygate'
                            )
                            ?>
                        </p>
                    </div>
                </div>

                <hr/>

                <form method="post" id="dismiss_menu_form" style="margin-top: 20px;">
                    <input type="checkbox" name="dismiss_paygate_menu" value="1" onclick="dismissMenu();"> <label><?php
                        _e('I understand, dismiss this message!', 'gravityformspaygate') ?></label>
                    <img id="gf_spinner" src="<?php
                    echo GFCommon::get_base_url() . '/images/spinner.gif' ?>" alt="<?php
                    _e('Please wait...', 'gravityformspaygate') ?>" style="display:none;"/>
                </form>

            </div>
        </div>
        <?php
    }

    /**
     * @param $payment_status
     * @param $form
     * @param $lead
     *
     * @return string
     */
    public function admin_edit_payment_status($payment_status, $form, $lead)
    {
        // Allow the payment status to be edited when for paygate, not set to Approved/Paid, and not a subscription
        if (
            !$this->is_payment_gateway($lead['id']) ||
            strtolower(rgpost('save')) != 'edit' ||
            $payment_status == 'Approved' ||
            $payment_status == 'Paid' ||
            rgar(
                $lead,
                'transaction_type'
            ) == 2
        ) {
            return $payment_status;
        }

        // Create drop down for payment status
        $payment_string = gform_tooltip('paygate_edit_payment_status', '', true);
        $payment_string .= '<select id="payment_status" name="payment_status">';
        $payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
        $payment_string .= '<option value="Paid">Paid</option>';
        $payment_string .= '</select>';

        return $payment_string;
    }

    /**
     * @param $payment_date
     * @param $form
     * @param $lead
     *
     * @return string
     */
    public function admin_edit_payment_date($payment_date, $form, $lead)
    {
        // Allow the payment date to be edited
        if (!$this->is_payment_gateway($lead['id']) || strtolower(rgpost('save')) != 'edit') {
            return $payment_date;
        }

        $payment_date = $lead['payment_date'];
        if (empty($payment_date)) {
            $payment_date = gmdate(self::DATE);
        }

        return '<input type="text" id="payment_date" name="payment_date" value="' . $payment_date . '">';
    }

    /**
     * @param $transaction_id
     * @param $form
     * @param $lead
     *
     * @return string
     */
    public function admin_edit_payment_transaction_id($transaction_id, $form, $lead)
    {
        // Allow the transaction ID to be edited
        if (!$this->is_payment_gateway($lead['id']) || strtolower(rgpost('save')) != 'edit') {
            return $transaction_id;
        }

        return '<input type="text" id="paygate_transaction_id" name="paygate_transaction_id" value="'
               . $transaction_id . '">';
    }

    /**
     * @param $payment_amount
     * @param $form
     * @param $lead
     *
     * @return string
     */
    public function admin_edit_payment_amount($payment_amount, $form, $lead)
    {
        // Allow the payment amount to be edited
        if (!$this->is_payment_gateway($lead['id']) || strtolower(rgpost('save')) != 'edit') {
            return $payment_amount;
        }

        if (empty($payment_amount)) {
            $payment_amount = GFCommon::get_order_total($form, $lead);
        }

        return '<input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="'
               . $payment_amount . '">';
    }

    /**
     * @param $form_id
     * @param $lead
     *
     * @return void
     */
    public function admin_edit_payment_status_details($form_id, $lead)
    {
        $form_action = strtolower(rgpost('save'));
        if (!$this->is_payment_gateway($lead['id']) || $form_action != 'edit') {
            return;
        }

        // Get data from entry to pre-populate fields
        $payment_amount = rgar($lead, 'payment_amount');
        if (empty($payment_amount)) {
            $form           = GFFormsModel::get_form_meta($form_id);
            $payment_amount = GFCommon::get_order_total($form, $lead);
        }
        $transaction_id = rgar($lead, 'transaction_id');
        $payment_date   = rgar($lead, 'payment_date');
        if (empty($payment_date)) {
            $payment_date = gmdate(self::DATE);
        }

        // Display edit fields
        PaygateGFForm::get_edit_fields($payment_date, $payment_amount, $transaction_id);
    }

    /**
     * @param $form
     * @param $lead_id
     *
     * @return void
     */
    public function admin_update_payment($form, $lead_id)
    {
        check_admin_referer('gforms_save_entry', 'gforms_save_entry');

        // Update payment information in admin, need to use this function so the lead data is updated before
        // displayed in the sidebar info section
        $form_action = strtolower(rgpost('save'));
        if (!$this->is_payment_gateway($lead_id) || $form_action != 'update') {
            return;
        }
        // Get lead
        $lead = GFFormsModel::get_lead($lead_id);

        // Check if current payment status is processing
        if ($lead['payment_status'] != 'Processing') {
            return;
        }

        // Get payment fields to update
        $payment_status = $_POST['payment_status'];
        // When updating, payment status may not be editable, if no value in post, set to lead payment status
        if (empty($payment_status)) {
            $payment_status = $lead['payment_status'];
        }

        $payment_amount      = GFCommon::to_number(rgpost('payment_amount'));
        $payment_transaction = rgpost('paygate_transaction_id');
        $payment_date        = rgpost('payment_date');
        if (empty($payment_date)) {
            $payment_date = gmdate(self::DATE);
        } else {
            // Format date entered by user
            $payment_date = date(self::DATE, strtotime($payment_date));
        }

        global $current_user;
        $user_id   = 0;
        $user_name = 'System';
        if ($current_user && $user_data = get_userdata($current_user->ID)) {
            $user_id   = $current_user->ID;
            $user_name = $user_data->display_name;
        }

        $lead['payment_status'] = $payment_status;
        $lead['payment_amount'] = $payment_amount;
        $lead['payment_date']   = $payment_date;
        $lead['transaction_id'] = $payment_transaction;

        // If payment status does not equal approved/paid or the lead has already been fulfilled,
        // do not continue with fulfillment
        if (($payment_status == 'Approved' || $payment_status == 'Paid') && !$lead['is_fulfilled']) {
            $action['id']             = $payment_transaction;
            $action['type']           = 'complete_payment';
            $action['transaction_id'] = $payment_transaction;
            $action['amount']         = $payment_amount;
            $action['entry_id']       = $lead['id'];

            $this->complete_payment($lead, $action);
            $this->fulfill_order($lead, $payment_transaction, $payment_amount);
        }
        // Update lead, add a note
        GFAPI::update_entry($lead);
        GFFormsModel::add_note(
            $lead['id'],
            $user_id,
            $user_name,
            sprintf(
                __(
                    'Payment information was manually updated. Status: %s. Amount: %s. Transaction Id: %s. Date: %s',
                    'gravityformspaygate'
                ),
                $lead['payment_status'],
                GFCommon::to_money($lead['payment_amount'], $lead['currency']),
                $payment_transaction,
                $lead['payment_date']
            )
        );
    }

    /**
     * @param $entry
     * @param $transaction_id
     * @param $amount
     * @param $feed
     *
     * @return void
     */
    public function fulfill_order(&$entry, $transaction_id, $amount, $feed = null)
    {
        if (!$feed) {
            $feed = $this->get_payment_feed($entry);
        }

        $form = GFFormsModel::get_form_meta($entry['form_id']);
        if (rgars($feed, 'meta/delayPost')) {
            $this->log_debug(__METHOD__ . '(): Creating post.');
            $entry['post_id'] = GFFormsModel::create_post($form, $entry);
            $this->log_debug(__METHOD__ . '(): Post created.');
        }

        // Sending notifications
        GFAPI::send_notifications($form, $entry);

        do_action('gform_paygate_fulfillment', $entry, $feed, $transaction_id, $amount);
        if (has_filter('gform_paygate_fulfillment')) {
            $this->log_debug(__METHOD__ . '(): Executing functions hooked to gform_paygate_fulfillment.');
        }
    }

    /**
     * @param $entry
     * @param $paygate_config
     * @param $transaction_id
     * @param $amount
     *
     * @return false
     */
    public function paygate_fulfillment($entry, $paygate_config, $transaction_id, $amount)
    {
        // No need to do anything for paygate when it runs this function, ignore
        return false;
    }

    /**
     * @param $previous_version
     *
     * @return void
     */
    public function upgrade($previous_version)
    {
        // Call the parent class's upgrade method first
        parent::upgrade($previous_version);

        $previous_is_pre_addon_framework = version_compare($previous_version, '1.0', '<');

        if ($previous_is_pre_addon_framework) {
            // Copy plugin settings
            $this->copy_settings();

            // Copy existing feeds to new table
            $this->copy_feeds();

            // Copy existing paygate transactions to new table
            $this->copy_transactions();

            // Updating payment_gateway entry meta to 'gravityformspaygate' from 'paygate'
            $this->update_payment_gateway();

            // Updating entry status from 'Approved' to 'Paid'
            $this->update_lead();
        }
    }

    /**
     * @param $old_feed_id
     * @param $new_feed_id
     *
     * @return void
     */
    public function update_feed_id($old_feed_id, $new_feed_id)
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s WHERE meta_key='paygate_feed_id' AND meta_value=%s",
            $new_feed_id,
            $old_feed_id
        );
        $wpdb->query($sql);
    }

    //------ FOR BACKWARDS COMPATIBILITY ----------------------//
    // Change data when upgrading from legacy paygate

    /**
     * @param $new_meta
     * @param $old_feed
     *
     * @return mixed
     */
    public function add_legacy_meta($new_meta, $old_feed)
    {
        $known_meta_keys = [
            'email',
            'mode',
            'type',
            'style',
            'continue_text',
            'cancel_url',
            'disable_note',
            'disable_shipping',
            'recurring_amount_field',
            'recurring_times',
            'recurring_retry',
            'billing_cycle_number',
            'billing_cycle_type',
            'trial_period_enabled',
            'trial_amount',
            'trial_period_number',
            'trial_period_type',
            'delay_post',
            'update_post_action',
            'delay_notifications',
            'selected_notifications',
            'paygate_conditional_enabled',
            'paygate_conditional_field_id',
            'paygate_conditional_operator',
            'paygate_conditional_value',
            'customer_fields',
        ];

        foreach ($old_feed['meta'] as $key => $value) {
            if (!in_array($key, $known_meta_keys)) {
                $new_meta[$key] = $value;
            }
        }

        return $new_meta;
    }

    /**
     * @return void
     */
    public function update_payment_gateway()
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s
                         WHERE meta_key='payment_gateway' AND meta_value='paygate'",
            $this->_slug
        );
        $wpdb->query($sql);
    }

    /**
     * @return void
     */
    public function update_lead()
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rg_lead
             SET payment_status='Paid', payment_method='Paygate'
             WHERE payment_status='Approved'
                    AND ID IN (
                        SELECT lead_id FROM {$wpdb->prefix}rg_lead_meta WHERE meta_key='payment_gateway'
                              AND meta_value=%s
                    )",
            $this->_slug
        );

        $wpdb->query($sql);
    }

    /**
     * @return void
     */
    public function copy_settings()
    {
        // Copy plugin settings
        $old_settings = get_option('gf_paygate_configured');
        $new_settings = ['gf_paygate_configured' => $old_settings];
        $this->update_plugin_settings($new_settings);
    }

    /**
     * @return void
     */
    public function copy_feeds()
    {
        // Get feeds
        $old_feeds = $this->get_old_feeds();

        if ($old_feeds) {
            $counter = 1;
            foreach ($old_feeds as $old_feed) {
                $feed_name       = 'Feed ' . $counter;
                $form_id         = $old_feed['form_id'];
                $is_active       = $old_feed['is_active'];
                $customer_fields = $old_feed['meta']['customer_fields'];

                $new_meta = [
                    'feedName'                     => $feed_name,
                    'paygateMerchantId'            => rgar($old_feed['meta'], 'paygateMerchantId'),
                    'paygateMerchantKey'           => rgar($old_feed['meta'], 'paygateMerchantKey'),
                    'useCustomConfirmationPage'    => rgar($old_feed['meta'], 'useCustomConfirmationPage'),
                    'successPageUrl'               => rgar($old_feed['meta'], 'successPageUrl'),
                    'failedPageUrl'                => rgar($old_feed['meta'], 'failedPageUrl'),
                    'mode'                         => rgar($old_feed['meta'], 'mode'),
                    'transactionType'              => rgar($old_feed['meta'], 'type'),
                    'type'                         => rgar($old_feed['meta'], 'type'),
                    // For backwards compatibility of the delayed payment feature
                    'pageStyle'                    => rgar($old_feed['meta'], 'style'),
                    'continueText'                 => rgar($old_feed['meta'], 'continue_text'),
                    'cancelUrl'                    => rgar($old_feed['meta'], 'cancel_url'),
                    'disableNote'                  => rgar($old_feed['meta'], 'disable_note'),
                    'disableShipping'              => rgar($old_feed['meta'], 'disable_shipping'),
                    'recurringAmount'              => rgar($old_feed['meta'], 'recurring_amount_field') == 'all' ?
                        'form_total' :
                        rgar($old_feed['meta'], 'recurring_amount_field'),
                    'recurring_amount_field'       => rgar($old_feed['meta'], 'recurring_amount_field'),
                    // For backwards compatibility of the delayed payment feature
                    'recurringTimes'               => rgar($old_feed['meta'], 'recurring_times'),
                    'recurringRetry'               => rgar($old_feed['meta'], 'recurring_retry'),
                    'paymentAmount'                => 'form_total',
                    'billingCycle_length'          => rgar($old_feed['meta'], 'billing_cycle_number'),
                    'billingCycle_unit'            => PaygateGFUtilities::convert_interval(
                        rgar($old_feed['meta'], 'billing_cycle_type'),
                        'text'
                    ),
                    'trial_enabled'                => rgar($old_feed['meta'], 'trial_period_enabled'),
                    'trial_product'                => 'enter_amount',
                    'trial_amount'                 => rgar($old_feed['meta'], 'trial_amount'),
                    'trialPeriod_length'           => rgar($old_feed['meta'], 'trial_period_number'),
                    'trialPeriod_unit'             => PaygateGFUtilities::convert_interval(
                        rgar($old_feed['meta'], 'trial_period_type'),
                        'text'
                    ),
                    'delayPost'                    => rgar($old_feed['meta'], 'delay_post'),
                    'change_post_status'           => rgar($old_feed['meta'], 'update_post_action') ? '1' : '0',
                    'update_post_action'           => rgar($old_feed['meta'], 'update_post_action'),
                    'delayNotification'            => rgar($old_feed['meta'], 'delay_notifications'),
                    'selectedNotifications'        => rgar($old_feed['meta'], 'selected_notifications'),
                    'billingInformation_firstName' => rgar($customer_fields, 'first_name'),
                    'billingInformation_lastName'  => rgar($customer_fields, 'last_name'),
                    'billingInformation_email'     => rgar($customer_fields, 'email'),
                    'billingInformation_address'   => rgar($customer_fields, 'address1'),
                    'billingInformation_address2'  => rgar($customer_fields, 'address2'),
                    'billingInformation_city'      => rgar($customer_fields, 'city'),
                    'billingInformation_state'     => rgar($customer_fields, 'state'),
                    'billingInformation_zip'       => rgar($customer_fields, 'zip'),
                    'billingInformation_country'   => rgar($customer_fields, 'country'),
                ];

                $new_meta = $this->add_legacy_meta($new_meta, $old_feed);

                // Add conditional logic
                $conditional_enabled = rgar($old_feed['meta'], 'paygate_conditional_enabled');
                if ($conditional_enabled) {
                    $new_meta['feed_condition_conditional_logic']        = 1;
                    $new_meta['feed_condition_conditional_logic_object'] = [
                        'conditionalLogic' => [
                            'actionType' => 'show',
                            'logicType'  => 'all',
                            'rules'      => [
                                [
                                    'fieldId'  => rgar($old_feed['meta'], 'paygate_conditional_field_id'),
                                    'operator' => rgar($old_feed['meta'], 'paygate_conditional_operator'),
                                    'value'    => rgar($old_feed['meta'], 'paygate_conditional_value'),
                                ],
                            ],
                        ],
                    ];
                } else {
                    $new_meta['feed_condition_conditional_logic'] = 0;
                }

                $new_feed_id = $this->insert_feed($form_id, $is_active, $new_meta);
                $this->update_feed_id($old_feed['id'], $new_feed_id);

                $counter++;
            }
        }
    }

    /**
     * @return void
     */
    public function copy_transactions()
    {
        // Copy transactions from the paygate transaction table to the add payment transaction table
        global $wpdb;
        $old_table_name = $this->get_old_transaction_table_name();
        $this->log_debug(__METHOD__ . '(): Copying old Paygate transactions into new table structure.');

        $new_table_name = $this->get_new_transaction_table_name();

        $sql = "INSERT INTO $new_table_name (lead_id, transaction_type, transaction_id, is_recurring, amount,
                   date_created)
                    SELECT entry_id, transaction_type, transaction_id, is_renewal, amount, date_created
                    FROM $old_table_name";

        $wpdb->query($sql);

        $this->log_debug(__METHOD__ . "(): transactions: $wpdb->rows_affected rows were added.");
    }

    /**
     * @return string
     */
    public function get_old_transaction_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . 'rg_paygate_transaction';
    }

    /**
     * @return string
     */
    public function get_new_transaction_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . 'gf_addon_payment_transaction';
    }

    /**
     * @return array|object|stdClass[]
     */
    public function get_old_feeds()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rg_paygate';

        $form_table_name = GFFormsModel::get_form_table_name();
        $sql             = "SELECT s.id, s.is_active, s.form_id, s.meta, f.title as form_title
                    FROM $table_name s
                    INNER JOIN $form_table_name f ON s.form_id = f.id";

        $this->log_debug(__METHOD__ . "(): getting old feeds: $sql");

        /** @noinspection PhpUndefinedConstantInspection */
        $results = $wpdb->get_results($sql, ARRAY_A);

        $this->log_debug(__METHOD__ . "(): error?: $wpdb->last_error");

        $count = sizeof($results);

        $this->log_debug(__METHOD__ . "(): count: $count");

        for ($i = 0; $i < $count; $i++) {
            $results[$i]['meta'] = maybe_unserialize($results[$i]['meta']);
        }

        return $results;
    }

    /**
     * @return void
     */
    private function __clone()
    {
        /* Do nothing */
    }

    /**
     * @param $entry_id
     *
     * @return array|false|object|stdClass
     */
    private function get_paygate_feed_by_entry($entry_id)
    {
        $feed_id = gform_get_meta($entry_id, 'paygate_feed_id');
        $feed    = $this->get_feed($feed_id);

        return !empty($feed) ? $feed : false;
    }

    //------------------------------------------------------
}

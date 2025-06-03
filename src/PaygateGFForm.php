<?php

namespace PayGate\GravityFormsPayGatePlugin;

class PaygateGFForm
{
    private const H6_TAG         = '<h6>';
    private const H6_TAG_CLOSING = '</h6>';

    public static function get_plugin_settings_fields()
    {
        $description = '
            <p style="text-align: left;">' .
                       __(
                           'You will need a Paygate account in order to use the Paygate Add-On.',
                           'gravityformspaygate'
                       ) .
                       '</p>
            <ul>
                <li>' . sprintf(
                           __('Go to the %sPaygate Website%s in order to register an account.', 'gravityformspaygate'),
                           '<a href="https://payfast.io/paygate-signup/" target="_blank">',
                           '</a>'
                       ) . '</li>' .
                       '<li>' . __(
                           'Check \'I understand\' and click on \'Update Settings\' in order to proceed.',
                           'gravityformspaygate'
                       ) . '</li>' .
                       '</ul>
                <br/>';

        return [
            [
                'title'       => '',
                'description' => $description,
                'fields'      => [
                    [
                        'name'    => 'gf_paygate_configured',
                        'label'   => __('I understand', 'gravityformspaygate'),
                        'type'    => 'checkbox',
                        'choices' => [
                            [
                                'label' => __('', 'gravityformspaygate'),
                                'name'  => 'gf_paygate_configured'
                            ]
                        ],
                    ],
                    [
                        'type'     => 'save',
                        'messages' => [
                            'success' => __('Settings have been updated.', 'gravityformspaygate'),
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function get_default_settings_fields() {
        return [
            [
                'name'     => 'paygateMerchantId',
                'label'    => __('Paygate ID ', 'gravityformspaygate'),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => true,
                'tooltip'  => self::H6_TAG .
                              __('Paygate ID', 'gravityformspaygate') .
                              self::H6_TAG_CLOSING .
                              __(
                                  'This is the Paygate ID, received from Paygate.',
                                  'gravityformspaygate'
                              ),
            ],
            [
                'name'     => 'paygateMerchantKey',
                'label'    => __('Encryption Key', 'gravityformspaygate'),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => true,
                'tooltip'  => self::H6_TAG .
                              __('Paygate Merchant Key', 'gravityformspaygate') .
                              self::H6_TAG_CLOSING .
                              __('This is the Encryption Key set in the Paygate Back Office.', 'gravityformspaygate'),
            ],
            [
                'name'          => 'testmode',
                'label'         => __('Test mode', 'gravityformspaygate'),
                'type'          => 'radio',
                'choices'       => [
                    [
                        'id'    => 'gf_paygate_mode_test',
                        'label' => __('Yes', 'gravityformspaygate'),
                        'value' => 'yes'
                    ],
                    [
                        'id'    => 'gf_paygate_mode_production',
                        'label' => __('No', 'gravityformspaygate'),
                        'value' => 'no'
                    ],
                ],
                'horizontal'    => true,
                'default_value' => 'no',
                'tooltip'       => self::H6_TAG .
                                   __('Mode', 'gravityformspaygate') .
                                   self::H6_TAG_CLOSING .
                                   __(
                                       'Uses a Paygate test account. Request test cards from Paygate',
                                       'gravityformspaygate'
                                   ),
            ],
            [
                'name'          => 'disableipn',
                'label'         => __('Disable IPN', 'gravityformspaygate'),
                'type'          => 'radio',
                'choices'       => [
                    [
                        'id'    => 'gf_paygate_disableipn_yes',
                        'label' => __('Yes', 'gravityformspaygate'),
                        'value' => 'yes'
                    ],
                    [
                        'id'    => 'gf_paygate_disableipn_no',
                        'label' => __('No', 'gravityformspaygate'),
                        'value' => 'no'
                    ],
                ],
                'horizontal'    => true,
                'default_value' => 'no',
                'tooltip'       => self::H6_TAG .
                                   __('Disable IPN', 'gravityformspaygate') .
                                   self::H6_TAG_CLOSING .
                                   __(
                                       'Disable IPN notify method and use redirect method instead.',
                                       'gravityformspaygate'
                                   ),
            ],
            [
                'name'          => 'useCustomConfirmationPage',
                'label'         => __('Use Custom Confirmation Page', 'gravityformspaygate'),
                'type'          => 'radio',
                'choices'       => [
                    [
                        'id'    => 'gf_paygate_thankyou_yes',
                        'label' => __('Yes', 'gravityformspaygate'),
                        'value' => 'yes'
                    ],
                    ['id' => 'gf_paygate_thakyou_no', 'label' => __('No', 'gravityformspaygate'), 'value' => 'no'],
                ],
                'horizontal'    => true,
                'default_value' => 'yes',
                'tooltip'       => self::H6_TAG .
                                   __(
                                       'Use Custom Confirmation Page',
                                       'gravityformspaygate'
                                   ) . self::H6_TAG_CLOSING . __(
                                       'Select Yes to display custom confirmation thank you page to the user.',
                                       'gravityformspaygate'
                                   ),
            ],
            [
                'name'    => 'successPageUrl',
                'label'   => __('Successful Page Url', 'gravityformspaygate'),
                'type'    => 'text',
                'class'   => 'medium',
                'tooltip' => self::H6_TAG .
                             __('Successful Page Url', 'gravityformspaygate') .
                             self::H6_TAG_CLOSING .
                             __('Enter a thank you page url when a transaction is successful.', 'gravityformspaygate'),
            ],
            [
                'name'    => 'failedPageUrl',
                'label'   => __('Failed Page Url', 'gravityformspaygate'),
                'type'    => 'text',
                'class'   => 'medium',
                'tooltip' => self::H6_TAG .
                             __('Failed Page Url', 'gravityformspaygate') .
                             self::H6_TAG_CLOSING .
                             __(
                                 'Enter a thank you page url when a transaction is failed.',
                                 'gravityformspaygate'
                             ),
            ]
        ];
    }

    public static function get_cancel_url() {
        return [
            [
                'name'     => 'continueText',
                'label'    => __('Continue Button Label', 'gravityformspaygate'),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => false,
                'tooltip'  => self::H6_TAG .
                              __('Continue Button Label', 'gravityformspaygate') .
                              self::H6_TAG_CLOSING .
                              __(
                                  'Enter the text that should appear on the continue button once payment has
                                   been completed via Paygate.',
                                  'gravityformspaygate'
                              ),
            ],
            [
                'name'     => 'cancelUrl',
                'label'    => __('Cancel URL', 'gravityformspaygate'),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => false,
                'tooltip'  => self::H6_TAG .
                              __('Cancel URL', 'gravityformspaygate') .
                              self::H6_TAG_CLOSING .
                              __(
                                  'Enter the URL the user should be sent to should they cancel before completing
                                   their payment. It currently defaults to the Paygate website.',
                                  'gravityformspaygate'
                              ),
            ],
        ];
    }

    public static function get_post_settings() {
        return [
            'name'    => 'post_checkboxes',
            'label'   => __('Posts', 'gravityformspaygate'),
            'type'    => 'checkbox',
            'tooltip' => self::H6_TAG .
                         __('Posts', 'gravityformspaygate') .
                         self::H6_TAG_CLOSING .
                         __(
                             'Enable this option if you would like to only create the post after payment has
                                  been received.',
                             'gravityformspaygate'
                         ),
            'choices' => [
                [
                    'label' => __('Create post only when payment is received.', 'gravityformspaygate'),
                    'name'  => 'delayPost'
                ],
            ],
        ];
    }

    public static function get_settings_options_checkboxes() {
        return [
            'name'    => 'options_checkboxes',
            'type'    => 'checkboxes',
            'choices' => [
                [
                    'label' => __('Do not prompt buyer to include a shipping address.', 'gravityformspaygate'),
                    'name'  => 'disableShipping'
                ],
                [
                    'label' => __('Do not prompt buyer to include a note with payment.', 'gravityformspaygate'),
                    'name'  => 'disableNote'
                ],
            ],
        ];
    }

    public static function get_dropdown_field() {
        return [
            'name'     => 'update_post_action',
            'choices'  => [
                ['label' => ''],
                ['label' => __('Mark Post as Draft', 'gravityformspaygate'), 'value' => 'draft'],
                ['label' => __('Delete Post', 'gravityformspaygate'), 'value' => 'delete'],
            ],
            'onChange' => "var checked = jQuery(this).val() ? 'checked' : false;
             jQuery('#change_post_status').attr('checked', checked);",
        ];
    }

    public static function get_configuration_instructions() {
        $description = '
            <p style="text-align: left;">' .
                       sprintf(
                           __(
                               'You will need a Payfast account in order to use the Payfast Add-On. Navigate to %sPayfast%s to register.',
                               'gravityformspayfast'
                           ),
                           '<a href="https://payfast.io" target="_blank">',
                           '</a>'
                       ) .
                       '</p>
            <ul>
                <li>' . __(
                           'The Payfast settings are configured per form. Navigate to \'Forms\' -> select \'Settings\' for the form, and select the \'Payfast\' tab.',
                           'gravityformspayfast'
                       ) . '</li>' .
                       '<li>' . __(
                           'From there, click \'Add New\' to configure Payfast feed settings for the currently selected form.',
                           'gravityformspayfast'
                       ) . '</li>' .
                       '</ul>
            <p style="text-align: left;">' .
                       __(
                           'Enable \'Debug\' below to log the server-to-server communication between Payfast and your website, for each transaction. The log file for debugging can be found at /wp-content/plugins/gravityformspayfast/payfast.log. If activated, be sure to protect it by adding an .htaccess file in the same directory. If not, the file will be readable by anyone. ',
                           'gravityformspayfast'
                       ) .
                       '</p>';

        return array(
            array(
                'title'       => esc_html__('How to configure Payfast', 'gravityformspayfast'),
                'description' => $description,
                'fields'      => array(
                    array(
                        'name'    => 'gf_payfast_debug',
                        'label'   => esc_html__('Payfast Debug', 'gravityformspayfast'),
                        'type'    => 'checkbox',
                        'choices' => array(
                            array(
                                'label' => __('Enable Debug', 'gravityformspayfast'),
                                'name'  => 'gf_payfast_debug'
                            )
                        )
                    ),
                    array(
                        'type'     => 'save',
                        'messages' => array(
                            'success' => __('Settings have been updated.', 'gravityformspayfast')
                        ),
                    ),
                ),
            ),
        );
    }

    public static function get_edit_fields($payment_date, $payment_amount, $transaction_id) {
        ?>
        <div id="edit_payment_status_details" style="display:block">
            <table>
                <caption>Display edit fields</caption>
                <tr>
                    <th scope="col">Payment Information</th>
                    <th scope="col">Value</th>
                </tr>
                <tr>
                    <td colspan="2"><strong>Payment Information</strong></td>
                </tr>

                <tr>
                    <td>Date:<?php
                        gform_tooltip('paygate_edit_payment_date') ?></td>
                    <td>
                        <input type="text" id="payment_date" name="payment_date" value="<?php
                        echo $payment_date ?>">
                    </td>
                </tr>
                <tr>
                    <td>Amount:<?php
                        gform_tooltip('paygate_edit_payment_amount') ?></td>
                    <td>
                        <input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="<?php
                        echo $payment_amount ?>">
                    </td>
                </tr>
                <tr>
                    <td>Transaction ID:<?php
                        gform_tooltip('paygate_edit_payment_transaction_id') ?></td>
                    <td>
                        <input type="text" id="paygate_transaction_id" name="paygate_transaction_id" value="<?php
                        echo $transaction_id ?>">
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
}

<?php

namespace PayGate\GravityFormsPayGatePlugin;

use GFCommon;

class PaygateGFUtilities
{
    /**
     * @param $form_id
     * @param $lead_id
     * @param $user_id
     * @param $feed_id
     *
     * @return string
     */
    public static function return_url($form_id, $lead_id, $user_id, $feed_id)
    {
        $pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

        $server_port = apply_filters('gform_paygate_return_url_port', $_SERVER['SERVER_PORT']);

        if ($server_port != '80') {
            $pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
        } else {
            $pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
        }

        $ids_query         = "ids=$form_id|$lead_id|$user_id|$feed_id";
        $ids_query         .= '&hash=' . wp_hash($ids_query);
        $encrpyt_ids_query = GF_encryption($ids_query);

        return add_query_arg('gf_paygate_return', $encrpyt_ids_query, $pageURL);
    }

    /**
     * @return array[]
     */
    public static function get_customer_fields()
    {
        return [
            ['name' => 'first_name', 'label' => 'First Name', 'meta_name' => 'billingInformation_firstName'],
            ['name' => 'last_name', 'label' => 'Last Name', 'meta_name' => 'billingInformation_lastName'],
            ['name' => 'email', 'label' => 'Email', 'meta_name' => 'billingInformation_email'],
            ['name' => 'address1', 'label' => 'Address', 'meta_name' => 'billingInformation_address'],
            ['name' => 'address2', 'label' => 'Address 2', 'meta_name' => 'billingInformation_address2'],
            ['name' => 'city', 'label' => 'City', 'meta_name' => 'billingInformation_city'],
            ['name' => 'state', 'label' => 'State', 'meta_name' => 'billingInformation_state'],
            ['name' => 'zip', 'label' => 'Zip', 'meta_name' => 'billingInformation_zip'],
            ['name' => 'country', 'label' => 'Country', 'meta_name' => 'billingInformation_country'],
        ];
    }

    /**
     * @param $interval
     * @param $to_type
     *
     * @return string
     */
    public static function convert_interval($interval, $to_type)
    {
        // Convert single character into long text for new feed settings or convert long text into
        // single character for sending to paygate
        // $to_type: text (change character to long text), OR char (change long text to character)
        if (empty($interval)) {
            return '';
        }

        if ($to_type == 'text') {
            // Convert single char to text
            $new_interval = match (strtoupper($interval)) {
                'D' => 'day',
                'W' => 'week',
                'M' => 'month',
                'Y' => 'year',
                default => $interval,
            };
        } else {
            // Convert text to single char
            $new_interval = match (strtolower($interval)) {
                'day' => 'D',
                'week' => 'W',
                'month' => 'M',
                'year' => 'Y',
                default => $interval,
            };
        }

        return $new_interval;
    }
}

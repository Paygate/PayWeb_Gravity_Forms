<?php

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\GravityFormsPayGatePlugin;

class PayGate
{
    public $paygate_id;
    public $encryption_key;
    public $enable_logging;
    public $logging_path;

    public function __construct()
    {
        GWPostContentMergeTags::get_instance();
    }

    public function loadSettings()
    {
        // Get Settings From XML File
        $file     = getcwd() . '/settings.xml';
        $settings = simplexml_load_file($file);

        $this->paygate_id     = (string)$settings->paygateid->value;
        $this->encryption_key = (string)$settings->encryptionkey->value;
        $this->enable_logging = (string)$settings->enable_logging->value;
        $this->logging_path   = (string)$settings->logging_path->value;
    }

    public function curlPost($url, $fields)
    {
        $curl = curl_init($url);

        // Set the url, number of POST vars, POST data
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_NOBODY, false);
        curl_setopt($curl, CURLOPT_REFERER, $_SERVER['HTTP_HOST']);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($curl);
        curl_close($curl);

        return $response;
    }

    public function logPostRequest($logging_path, $prefix, $type)
    {
        $content = print_r($_POST, true);
        $fp      = fopen("$logging_path/$prefix-$type.log", "wb");
        fwrite($fp, $content);
        fclose($fp);
    }

    public function accessValue($key, $type)
    {
        if ($type == 'post') {
            $value = array_key_exists($key, $_POST) ? $_POST[$key] : null;
        } elseif ($type == 'session') {
            $value = isset($_SESSION[$key]) ? $_SESSION[$key] : null;
        }

        return $value;
    }

    public function savePostSession()
    {
        $_SESSION          = $_POST;
        $_SESSION['store'] = $_SERVER['HTTP_REFERER'];
    }

    public function getPaygatePostForm($pay_request_id, $checksum)
    {
        $processUrl = PayGateGF::PROCESSS_TRANS_URL;

        return "
          <form action='$processUrl' method='post' name='paygate'>
               <input name='PAY_REQUEST_ID' type='hidden' value='$pay_request_id' />
               <input name='CHECKSUM' type='hidden' value='$checksum' />
          </form>
          <script>
               document.forms['paygate'].submit();
          </script>";
    }

    public function getPostData()
    {
        // Posted variables from ITN
        $nData = $_POST;

        // Strip any slashes in data
        foreach ($nData as $key => $val) {
            $nData[$key] = stripslashes($val);
        }

        // Return "false" if no data was received
        if (empty($nData)) {
            return false;
        } else {
            return $nData;
        }
    }

    public function logData($msg = '', $close = false)
    {
        static $fh = 0;

        if ($close) {
            fclose($fh);
        } else {
            // If file doesn't exist, create it
            if (!$fh) {
                $pathinfo = pathinfo(__FILE__);
                $fh       = fopen($pathinfo['dirname'] . '/paygate.log', 'a+');
            }

            // If file was successfully created
            if ($fh) {
                $line = date('Y-m-d H:i:s') . ' : ' . $msg . "\n";

                fwrite($fh, $line);
            }
        }
    }
}

<?php

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\GravityFormsPayGatePlugin;

/**
 *
 */
class PayGate
{
    public string $paygate_id;
    public string $encryption_key;
    public string $enable_logging;
    public string $logging_path;

    public function __construct()
    {
        GWPostContentMergeTags::get_instance();
    }

    /**
     * @return void
     */
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

    /**
     * @param $logging_path
     * @param $prefix
     * @param $type
     *
     * @return void
     */
    public function logPostRequest($logging_path, $prefix, $type)
    {
        $content = print_r($_POST, true);
        $fp      = fopen("$logging_path/$prefix-$type.log", 'wb');
        fwrite($fp, $content);
        fclose($fp);
    }

    /**
     * @param $key
     * @param $type
     *
     * @return mixed|null
     */
    public function accessValue($key, $type)
    {
        if ($type == 'post') {
            $value = array_key_exists($key, $_POST) ? $_POST[$key] : null;
        } elseif ($type == 'session') {
            $value = $_SESSION[$key] ?? null;
        }

        return $value;
    }

    /**
     * @return void
     */
    public function savePostSession()
    {
        $_SESSION          = $_POST;
        $_SESSION['store'] = $_SERVER['HTTP_REFERER'];
    }

    /**
     * @param $pay_request_id
     * @param $checksum
     *
     * @return string
     */
    public function getPaygatePostForm($htmlForm)
    {
        return "
          $htmlForm;
          <script>
               document.forms['paygate_payment_form'].submit();
          </script>";
    }

    /**
     * @return array|false
     */
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

    /**
     * @param string $msg
     * @param $close
     *
     * @return void
     */
    public function logData(string $msg = '', $close = false)
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

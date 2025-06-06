<?php

namespace PayGate\GravityFormsPayGatePlugin;

use GFCommon;
use GFFormsModel;
use WP_Error;

/**
 *
 */
class GWPostContentMergeTags
{
    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

    public static $entry = null;
    private static $instance = null;
    private array $args;

    /**
     * @param $args
     */
    public function __construct($args)
    {
        if (!class_exists('GFForms')) {
            return;
        }

        $this->args = wp_parse_args($args, [
            'auto_append_eid' => true, // true, false or array of form IDs
            'encrypt_eid'     => false,
        ]);

        add_filter('the_content', [$this, 'replace_merge_tags'], 1);
        add_filter('gform_replace_merge_tags', [$this, 'replace_encrypt_entry_id_merge_tag'], 10, 3);

        if (!empty($this->args['auto_append_eid'])) {
            add_filter('gform_confirmation', [$this, 'append_eid_parameter'], 20, 3);
        }
    }

    /**
     * @param array $args
     *
     * @return self|null
     */
    public static function get_instance(array $args = [])
    {
        if (self::$instance == null) {
            self::$instance = new self($args);
        }

        return self::$instance;
    }

    /**
     * @param $post_content
     *
     * @return array|mixed|string|string[]|null
     */
    public function replace_merge_tags($post_content)
    {
        $entry = $this->get_entry();
        if (!$entry) {
            return $post_content;
        }

        $form = GFFormsModel::get_form_meta($entry['form_id']);

        $post_content = $this->replace_field_label_merge_tags($post_content, $form);

        return GFCommon::replace_variables($post_content, $form, $entry, false, false, false);
    }

    /**
     * @param $text
     * @param $form
     *
     * @return array|mixed|string|string[]
     */
    public function replace_field_label_merge_tags($text, $form)
    {
        preg_match_all('/{([^:]+?)}/', $text, $matches, PREG_SET_ORDER);
        if (empty($matches)) {
            return $text;
        }

        foreach ($matches as $match) {
            list($search, $field_label) = $match;

            foreach ($form['fields'] as $field) {
                $matches_admin_label = rgar($field, 'adminLabel') == $field_label;
                $matches_field_label = false;

                if (is_array($field['inputs'])) {
                    foreach ($field['inputs'] as $input) {
                        if (GFFormsModel::get_label($field, $input['id']) == $field_label) {
                            $matches_field_label = true;
                            $input_id            = $input['id'];
                            break;
                        }
                    }
                } else {
                    $matches_field_label = GFFormsModel::get_label($field) == $field_label;
                    $input_id            = $field['id'];
                }

                if (!$matches_admin_label && !$matches_field_label) {
                    continue;
                }

                $replace = sprintf('{%s:%s}', $field_label, $input_id);
                $text    = str_replace($search, $replace, $text);

                break;
            }
        }

        return $text;
    }

    /**
     * @param $text
     * @param $form
     * @param $entry
     *
     * @return array|mixed|string|string[]
     */
    public function replace_encrypt_entry_id_merge_tag($text, $form, $entry)
    {
        if (!str_contains($text, '{encrypted_entry_id}')) {
            return $text;
        }

        // $entry is not always a "full" entry
        $entry_id = rgar($entry, 'id');
        if ($entry_id) {
            $entry_id = $this->prepare_eid($entry['id'], true);
        }

        return str_replace('{encrypted_entry_id}', $entry_id, $text);
    }

    /**
     * @param $confirmation
     * @param $form
     * @param $entry
     *
     * @return array|mixed|string|string[]
     */
    public function append_eid_parameter($confirmation, $form, $entry)
    {
        $is_ajax_redirect = is_string($confirmation) && strpos($confirmation, 'gformRedirect');
        $is_redirect      = is_array($confirmation) && isset($confirmation['redirect']);

        if (!$this->is_auto_eid_enabled($form) || !($is_ajax_redirect || $is_redirect)) {
            return $confirmation;
        }

        $eid = $this->prepare_eid($entry['id']);

        if ($is_ajax_redirect) {
            preg_match_all('/gformRedirect.+?(http.+?)(?=\'|")/', $confirmation, $matches, PREG_SET_ORDER);
            list($url) = $matches[0];
            $redirect_url = add_query_arg(['eid' => $eid], $url);
            $confirmation = str_replace($url, $redirect_url, $confirmation);
        } else {
            $redirect_url             = add_query_arg(['eid' => $eid], $confirmation['redirect']);
            $confirmation['redirect'] = $redirect_url;
        }

        return $confirmation;
    }

    /**
     * @param $entry_id
     * @param bool $force_encrypt
     *
     * @return false|mixed|string
     */
    public function prepare_eid($entry_id, bool $force_encrypt = false)
    {
        $eid        = $entry_id;
        $do_encrypt = $force_encrypt || $this->args['encrypt_eid'];

        if ($do_encrypt && is_callable(['\GFCommon', 'encrypt'])) {
            $eid = GF_encryption($eid);
        }

        return $eid;
    }

    /**
     * @return array|false|WP_Error|null
     */
    public function get_entry()
    {
        if (!self::$entry) {
            $entry_id = $this->get_entry_id();
            if (!$entry_id) {
                return false;
            }

            $entry = GFFormsModel::get_lead($entry_id);
            if (empty($entry)) {
                return false;
            }

            self::$entry = $entry;
        }

        return self::$entry;
    }

    /**
     * @return false|float|int|mixed|string|null
     */
    public function get_entry_id()
    {
        $entry_id = rgget('eid');
        if ($entry_id) {
            return $this->maybe_decrypt_entry_id($entry_id);
        }

        $post = get_post();
        if ($post) {
            $entry_id = get_post_meta($post->ID, '_gform-entry-id', true);
        }

        return $entry_id ?: false;
    }

    /**
     * @param $entry_id
     *
     * @return float|int|string|null
     */
    public function maybe_decrypt_entry_id($entry_id)
    {
        // if encryption is enabled, 'eid' parameter MUST be encrypted
        $do_encrypt = $this->args['encrypt_eid'];

        if (!$entry_id) {
            return null;
        } elseif (!$do_encrypt && is_numeric($entry_id) && intval($entry_id) > 0) {
            return $entry_id;
        } else {
            if (is_callable(['\GFCommon', 'decrypt'])) {
                $entry_id = GF_encryption($entry_id, 'd');
            }

            return intval($entry_id);
        }
    }

    /**
     * @param $form
     *
     * @return bool
     */
    public function is_auto_eid_enabled($form)
    {
        $auto_append_eid = $this->args['auto_append_eid'];

        if ($auto_append_eid === true) {
            return true;
        }

        if (is_array($auto_append_eid) && in_array($form['id'], $auto_append_eid)) {
            return true;
        }

        return false;
    }
}

<?php

namespace PayGate\GravityFormsPayGatePlugin;

use GFAddOn;

/**
 *
 */
class Bootstrap
{
    /**
     * @return void
     */
    public static function load()
    {
        if (!method_exists('GFForms', 'include_payment_addon_framework')) {
            return;
        }

        GFAddOn::register(PayGateGF::class);
    }
}

<?php

namespace PayGate\GravityFormsPayGatePlugin;

class Bootstrap
{
    public static function load()
    {
        if (!method_exists('GFForms', 'include_payment_addon_framework')) {
            return;
        }

        \GFAddOn::register(PayGateGF::class);
    }
}

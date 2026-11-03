<?php
/**
 * 2007-2026 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2026 PrestaShop SA
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Ps_Wirepayment $module
 *
 * @return bool
 */
function upgrade_module_2_1_0($module)
{
    if ($module->isRegisteredInHook('paymentReturn')) {
        $module->unregisterHook('paymentReturn');
    }

    if (!$module->isRegisteredInHook('displayPaymentReturn')) {
        if (!$module->registerHook('displayPaymentReturn')) {
            return false;
        }
    }

    return Configuration::updateValue('PS_WIREPAYMENT_HOOK_MIGRATED', 1);
}

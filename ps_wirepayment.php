<?php
/*
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_Wirepayment extends PaymentModule
{
    const FLAG_DISPLAY_PAYMENT_INVITE = 'BANK_WIRE_PAYMENT_INVITE';

    protected $_html = '';
    protected $_postErrors = array();

    public $details;
    public $owner;
    public $address;
    public $account;
    public $accountext;
    public $extra_mail_vars;

    /** @var int */
    public $is_eu_compatible;

    /** @var false|int|string */
    public $reservation_days;

    public function __construct()
    {
        $this->name = 'ps_wirepayment';
        $this->tab = 'payments_gateways';
        $this->version = '2.1.0';
        $this->ps_versions_compliancy = array('min' => '8.1.0', 'max' => _PS_VERSION_);
        $this->author = 'PrestaShop';
        $this->controllers = array('payment', 'validation');
        $this->is_eu_compatible = 1;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $config = Configuration::getMultiple(array('BANK_WIRE_DETAILS', 'BANK_WIRE_OWNER', 'BANK_WIRE_ADDRESS', 'BANK_WIRE_ACCOUNT', 'BANK_WIRE_ACCOUNT_EXT', 'BANK_WIRE_RESERVATION_DAYS'));
        if (!empty($config['BANK_WIRE_OWNER'])) {
            $this->owner = $config['BANK_WIRE_OWNER'];
        }
        if (!empty($config['BANK_WIRE_DETAILS'])) {
            $this->details = $config['BANK_WIRE_DETAILS'];
        }
        if (!empty($config['BANK_WIRE_ADDRESS'])) {
            $this->address = $config['BANK_WIRE_ADDRESS'];
        }
        if (!empty($config['BANK_WIRE_ACCOUNT'])) {
            $this->account = $config['BANK_WIRE_ACCOUNT'];
        }
        if (!empty($config['BANK_WIRE_ACCOUNT_EXT'])) {
            $this->accountext = $config['BANK_WIRE_ACCOUNT_EXT'];
        }
        if (!empty($config['BANK_WIRE_RESERVATION_DAYS'])) {
            $this->reservation_days = $config['BANK_WIRE_RESERVATION_DAYS'];
        }

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('Wire payment', array(), 'Modules.Wirepayment.Admin');
        $this->description = $this->trans('Accept payments by bank transfer.', array(), 'Modules.Wirepayment.Admin');
        $this->confirmUninstall = $this->trans('Are you sure about removing these details?', array(), 'Modules.Wirepayment.Admin');
        if (!isset($this->owner) || !isset($this->details) || !isset($this->address) || !isset($this->account) || !isset($this->accountext)) {
            $this->warning = $this->trans('Account owner and account details must be configured before using this module.', array(), 'Modules.Wirepayment.Admin');
        }
        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->trans('No currency has been set for this module.', array(), 'Modules.Wirepayment.Admin');
        }

        $this->extra_mail_vars = $this->getMailVars();

        if ($this->id) {
            $this->migratePaymentReturnHook();
        }
    }

    public function install()
    {
        Configuration::updateValue(self::FLAG_DISPLAY_PAYMENT_INVITE, true);
        if (!parent::install()
            || !$this->registerHook('displayPaymentReturn')
            || !$this->registerHook('paymentOptions')
        ) {
            return false;
        }

        Configuration::updateValue('PS_WIREPAYMENT_HOOK_MIGRATED', 1);

        return true;
    }

    public function uninstall()
    {
        $languages = Language::getLanguages(false);
        foreach ($languages as $lang) {
            if (!Configuration::deleteByName('BANK_WIRE_CUSTOM_TEXT', $lang['id_lang'])) {
                return false;
            }
        }

        if (!Configuration::deleteByName('BANK_WIRE_DETAILS')
                || !Configuration::deleteByName('BANK_WIRE_OWNER')
                || !Configuration::deleteByName('BANK_WIRE_ADDRESS')
                || !Configuration::deleteByName('BANK_WIRE_ACCOUNT')
                || !Configuration::deleteByName('BANK_WIRE_ACCOUNT_EXT')
                || !Configuration::deleteByName('BANK_WIRE_RESERVATION_DAYS')
                || !Configuration::deleteByName('PS_WIREPAYMENT_HOOK_MIGRATED')
                || !Configuration::deleteByName(self::FLAG_DISPLAY_PAYMENT_INVITE)
                || !parent::uninstall()) {
            return false;
        }
        return true;
    }

    protected function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue(self::FLAG_DISPLAY_PAYMENT_INVITE,
                Tools::getValue(self::FLAG_DISPLAY_PAYMENT_INVITE));

            if (!Tools::getValue('BANK_WIRE_DETAILS')) {
                $this->_postErrors[] = $this->trans('Account details are required.', array(), 'Modules.Wirepayment.Admin');
            } elseif (!Tools::getValue('BANK_WIRE_OWNER')) {
                $this->_postErrors[] = $this->trans('Account owner is required.', array(), "Modules.Wirepayment.Admin");
            } elseif (!Tools::getValue('BANK_WIRE_ACCOUNT')) {
                $this->_postErrors[] = $this->trans('Account number is required.', array(), "Modules.Wirepayment.Admin");
            } elseif (!Tools::getValue('BANK_WIRE_ACCOUNT_EXT')) {
                $this->_postErrors[] = $this->trans('External account account is required.', array(), "Modules.Wirepayment.Admin");
            }
        }
    }

    protected function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('BANK_WIRE_DETAILS', Tools::getValue('BANK_WIRE_DETAILS'));
            Configuration::updateValue('BANK_WIRE_OWNER', Tools::getValue('BANK_WIRE_OWNER'));
            Configuration::updateValue('BANK_WIRE_ADDRESS', Tools::getValue('BANK_WIRE_ADDRESS'));
            Configuration::updateValue('BANK_WIRE_ACCOUNT', Tools::getValue('BANK_WIRE_ACCOUNT'));
            Configuration::updateValue('BANK_WIRE_ACCOUNT_EXT', Tools::getValue('BANK_WIRE_ACCOUNT_EXT'));

            $custom_text = array();
            $languages = Language::getLanguages(false);
            foreach ($languages as $lang) {
                if (Tools::getIsset('BANK_WIRE_CUSTOM_TEXT_'.$lang['id_lang'])) {
                    $custom_text[$lang['id_lang']] = Tools::getValue('BANK_WIRE_CUSTOM_TEXT_'.$lang['id_lang']);
                }
            }
            Configuration::updateValue('BANK_WIRE_RESERVATION_DAYS', Tools::getValue('BANK_WIRE_RESERVATION_DAYS'));
            Configuration::updateValue('BANK_WIRE_CUSTOM_TEXT', $custom_text);
        }
        $this->_html .= $this->displayConfirmation($this->trans('Settings updated', array(), 'Admin.Global'));
    }

    protected function _displayBankWire()
    {
        return $this->display(__FILE__, 'infos.tpl');
    }

    public function getContent()
    {
        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } else {
            $this->_html .= '<br />';
        }

        $this->_html .= $this->_displayBankWire();
        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return array();
        }

        if (!$this->checkCurrency($params['cart'])) {
            return array();
        }

        $this->smarty->assign(
            $this->getTemplateVarInfos()
        );

        $newOption = new PaymentOption();
        $newOption->setModuleName($this->name)
                ->setCallToActionText($this->trans('Pay by bank wire', array(), 'Modules.Wirepayment.Shop'))
                ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
                ->setAdditionalInformation($this->fetch('module:ps_wirepayment/views/templates/hook/ps_wirepayment_intro.tpl'));
        $payment_options = [
            $newOption,
        ];

        return $payment_options;
    }

    public function hookPaymentReturn($params)
    {
        return $this->hookDisplayPaymentReturn($params);
    }

    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active || !Configuration::get(self::FLAG_DISPLAY_PAYMENT_INVITE)) {
            return '';
        }

        $state = (int) $params['order']->getCurrentState();
        if (
            in_array(
                $state,
                array(
                    (int) Configuration::get('PS_OS_BANKWIRE'),
                    (int) Configuration::get('PS_OS_OUTOFSTOCK'),
                    (int) Configuration::get('PS_OS_OUTOFSTOCK_UNPAID'),
                )
        )) {
            $bankwireOwner = $this->owner;
            if (!$bankwireOwner) {
                $bankwireOwner = '___________';
            }

            $bankwireDetails = $this->multilineValue($this->details);
            if (!$bankwireDetails) {
                $bankwireDetails = '___________';
            }

            $bankwireAddress = $this->multilineValue($this->address);
            if (!$bankwireAddress) {
                $bankwireAddress = '___________';
            }

            $bankwireAccount = $this->multilineValue($this->account);
            if (!$bankwireAccount) {
                $bankwireAccount = '___________';
            }

            $bankwireAccountExt = $this->multilineValue($this->accountext);
            if (!$bankwireAccountExt) {
                $bankwireAccountExt = '___________';
            }

            $orderCurrency = new Currency((int) $params['order']->id_currency);
            $totalToPay = $params['order']->getOrdersTotalPaid() - $params['order']->getTotalPaid();

            $this->smarty->assign(array(
                'shop_name' => $this->context->shop->name,
                'total' => $this->formatPrice($totalToPay, $orderCurrency),
                'bankwireDetails' => $bankwireDetails,
                'bankwireAddress' => $bankwireAddress,
                'bankwireOwner' => $bankwireOwner,
                'bankwireAccount' => $bankwireAccount,
                'bankwireAccountExt' => $bankwireAccountExt,
                'status' => 'ok',
                'reference' => $params['order']->reference,
                'contact_url' => $this->context->link->getPageLink('contact', true)
            ));
        } else {
            $this->smarty->assign(
                array(
                    'status' => 'failed',
                    'contact_url' => $this->context->link->getPageLink('contact', true),
                )
            );
        }

        return $this->fetch('module:ps_wirepayment/views/templates/hook/payment_return.tpl');
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Account details', array(), 'Modules.Wirepayment.Admin'),
                    'icon' => 'icon-envelope'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Account owner', array(), 'Modules.Wirepayment.Admin'),
                        'name' => 'BANK_WIRE_OWNER',
                        'required' => true
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->trans('Account details', array(), 'Modules.Wirepayment.Admin'),
                        'name' => 'BANK_WIRE_DETAILS',
                        'desc' => $this->trans('Such as bank branch, IBAN number, BIC, etc.', array(), 'Modules.Wirepayment.Admin'),
                        'required' => true
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->trans('Bank address', array(), 'Modules.Wirepayment.Admin'),
                        'name' => 'BANK_WIRE_ADDRESS',
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Bank account', array(), 'Modules.Wirepayment.Admin'),
                        'name' => 'BANK_WIRE_ACCOUNT',
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Bank external account', array(), 'Modules.Wirepayment.Admin'),
                        'name' => 'BANK_WIRE_ACCOUNT_EXT',
                        'required' => true
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                )
            ),
        );
        $fields_form_customization = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Customization', array(), 'Modules.Wirepayment.Admin'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Reservation period', array(), 'Modules.Wirepayment.Admin'),
                        'desc' => $this->trans('Number of days the items remain reserved', array(), 'Modules.Wirepayment.Admin'),
                        'name' => 'BANK_WIRE_RESERVATION_DAYS',
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->trans('Information to the customer', array(), 'Modules.Wirepayment.Admin'),
                        'name' => 'BANK_WIRE_CUSTOM_TEXT',
                        'desc' => $this->trans('Information on the bank transfer (processing time, starting of the shipping...)', array(), 'Modules.Wirepayment.Admin'),
                        'lang' => true
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Display the invitation to pay in the order confirmation page', array(), 'Modules.Wirepayment.Admin'),
                        'name' => self::FLAG_DISPLAY_PAYMENT_INVITE,
                        'is_bool' => true,
                        'hint' => $this->trans('Your country\'s legislation may require you to send the invitation to pay by email only. Disabling the option will hide the invitation on the confirmation page.', array(), 'Modules.Wirepayment.Admin'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->trans('Enabled', array(), 'Admin.Global'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->trans('Disabled', array(), 'Admin.Global'),
                            )
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? : 0;
        $this->fields_form = array();
        $helper->id = (int)Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='
            .$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form, $fields_form_customization));
    }

    public function getConfigFieldsValues()
    {
        $custom_text = array();
        $languages = Language::getLanguages(false);
        foreach ($languages as $lang) {
            $custom_text[$lang['id_lang']] = Tools::getValue(
                'BANK_WIRE_CUSTOM_TEXT_'.$lang['id_lang'],
                Configuration::get('BANK_WIRE_CUSTOM_TEXT', $lang['id_lang'])
            );
        }

        return array(
            'BANK_WIRE_DETAILS' => Tools::getValue('BANK_WIRE_DETAILS', Configuration::get('BANK_WIRE_DETAILS')),
            'BANK_WIRE_OWNER' => Tools::getValue('BANK_WIRE_OWNER', Configuration::get('BANK_WIRE_OWNER')),
            'BANK_WIRE_ADDRESS' => Tools::getValue('BANK_WIRE_ADDRESS', Configuration::get('BANK_WIRE_ADDRESS')),
            'BANK_WIRE_ACCOUNT' => Tools::getValue('BANK_WIRE_ACCOUNT', Configuration::get('BANK_WIRE_ACCOUNT')),
            'BANK_WIRE_ACCOUNT_EXT' => Tools::getValue('BANK_WIRE_ACCOUNT_EXT', Configuration::get('BANK_WIRE_ACCOUNT_EXT')),
            'BANK_WIRE_RESERVATION_DAYS' => Tools::getValue('BANK_WIRE_RESERVATION_DAYS', Configuration::get('BANK_WIRE_RESERVATION_DAYS')),
            'BANK_WIRE_CUSTOM_TEXT' => $custom_text,
            self::FLAG_DISPLAY_PAYMENT_INVITE => Tools::getValue(self::FLAG_DISPLAY_PAYMENT_INVITE,
                Configuration::get(self::FLAG_DISPLAY_PAYMENT_INVITE))
        );
    }

    public function getTemplateVarInfos()
    {
        $cart = $this->context->cart;
        $total = sprintf(
            $this->trans('%1$s (tax incl.)', array(), 'Modules.Wirepayment.Shop'),
            $this->formatPrice($cart->getOrderTotal(true, Cart::BOTH), $this->context->currency)
        );

        $bankwireOwner = $this->owner;
        if (!$bankwireOwner) {
            $bankwireOwner = '___________';
        }

        $bankwireDetails = $this->multilineValue($this->details);
        if (!$bankwireDetails) {
            $bankwireDetails = '___________';
        }

        $bankwireAddress = $this->multilineValue($this->address);
        if (!$bankwireAddress) {
            $bankwireAddress = '___________';
        }

        $bankwireAccount = $this->multilineValue($this->account);
        if (!$bankwireAccount) {
            $bankwireAccount = '___________';
        }

        $bankwireAccountExt = $this->multilineValue($this->accountext);
        if (!$bankwireAccountExt) {
            $bankwireAccountExt = '___________';
        }

        $bankwireReservationDays = Configuration::get('BANK_WIRE_RESERVATION_DAYS');
        if (false === $bankwireReservationDays) {
            $bankwireReservationDays = 7;
        }

        $bankwireCustomText = $this->multilineValue(
            Configuration::get('BANK_WIRE_CUSTOM_TEXT', $this->context->language->id)
        );

        return array(
            'total' => $total,
            'bankwireDetails' => $bankwireDetails,
            'bankwireAddress' => $bankwireAddress,
            'bankwireOwner' => $bankwireOwner,
            'bankwireAccount' => $bankwireAccount,
            'bankwireAccountExt' => $bankwireAccountExt,
            'bankwireReservationDays' => (int)$bankwireReservationDays,
            'bankwireCustomText' => $bankwireCustomText,
        );
    }

    /**
     * Variables injected into the order and bank-wire emails.
     *
     * @return array<string, string>
     */
    public function getMailVars()
    {
        return array(
            '{bankwire_owner}' => (string) Configuration::get('BANK_WIRE_OWNER'),
            '{bankwire_details}' => $this->multilineValue(Configuration::get('BANK_WIRE_DETAILS')),
            '{bankwire_address}' => $this->multilineValue(Configuration::get('BANK_WIRE_ADDRESS')),
            '{bankwire_account}' => $this->multilineValue(Configuration::get('BANK_WIRE_ACCOUNT')),
            '{bankwire_account_ext}' => $this->multilineValue(Configuration::get('BANK_WIRE_ACCOUNT_EXT')),
        );
    }

    /**
     * PrestaShop 9.2 reads Mail::TYPE_BOTH_AUTOMATIC_TEXT while building the
     * order confirmation. If classes/Mail.php does not define it, validateOrder()
     * fatals after the payment. This keeps the core rendering rules without
     * evaluating a missing constant.
     *
     * @param string $template_name
     * @param int $mail_type
     * @param array $var
     *
     * @return string
     */
    protected function getEmailTemplateContent($template_name, $mail_type, $var)
    {
        $configuration = Configuration::get('PS_MAIL_TYPE');
        $textTypes = array(Mail::TYPE_TEXT, Mail::TYPE_BOTH);
        $htmlTypes = array(Mail::TYPE_HTML, Mail::TYPE_BOTH);

        if (defined('Mail::TYPE_BOTH_AUTOMATIC_TEXT')) {
            $htmlTypes[] = constant('Mail::TYPE_BOTH_AUTOMATIC_TEXT');
        } else {
            $htmlTypes[] = 4;
        }

        if ($mail_type === Mail::TYPE_TEXT && !in_array($configuration, $textTypes)) {
            return '';
        }

        if ($mail_type === Mail::TYPE_HTML && !in_array($configuration, $htmlTypes)) {
            return '';
        }

        return $this->getPartialRenderer()->render($template_name, $this->context->language, $var);
    }

    /**
     * @param float|int|string $price
     * @param Currency|null $currency
     *
     * @return string
     */
    protected function formatPrice($price, $currency = null)
    {
        if (!$currency instanceof Currency || empty($currency->iso_code)) {
            $currency = $this->context->currency;
        }

        return $this->context->getCurrentLocale()->formatPrice((float) $price, $currency->iso_code);
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    protected function multilineValue($value)
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        return nl2br($value);
    }

    /**
     * paymentReturn is only an alias in PrestaShop 9 and raises a deprecation
     * on PHP 8.4 when the shop runs with deprecations enabled.
     */
    protected function migratePaymentReturnHook()
    {
        if (Configuration::get('PS_WIREPAYMENT_HOOK_MIGRATED')) {
            return;
        }

        if ($this->isRegisteredInHook('paymentReturn') && !$this->unregisterHook('paymentReturn')) {
            return;
        }

        if (!$this->isRegisteredInHook('displayPaymentReturn') && !$this->registerHook('displayPaymentReturn')) {
            return;
        }

        Configuration::updateValue('PS_WIREPAYMENT_HOOK_MIGRATED', 1);
    }
}

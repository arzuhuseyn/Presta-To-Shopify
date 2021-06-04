<?php
/**
* 2007-2021 PrestaShop
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
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2021 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

define('MPROOT_BASE_NAME', basename(getcwd()));
define('MPCONNECTOR_BASE_DIR', dirname(__FILE__));
define('MPSTORE_BASE_DIR', dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR);

class ShopifyMigrate extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'ShopifyMigrate';
        $this->tab = 'administration';
        $this->version = '0.0.1';
        $this->author = 'ShopifyMigrate';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('ShopifyMigrate');
        $this->description = $this->l('Migrate store data to Shopify');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        Configuration::updateValue('SHOPIFYMIGRATE_LIVE_MODE', false);

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('actionOrderReturn') &&
            $this->registerHook('actionProductAdd') &&
            $this->registerHook('actionProductDelete') &&
            $this->registerHook('actionProductSave') &&
            $this->registerHook('actionProductUpdate');
    }

    public function uninstall()
    {
        Configuration::deleteByName('SHOPIFYMIGRATE_LIVE_MODE');

        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitShopifyMigrateModule')) == true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        return $output.$this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitShopifyMigrateModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Migration Process'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Migrate data to shopify?'),
                        'name' => 'SHOPIFYMIGRATE_NOW',
                        'is_bool' => true,
                        'desc' => $this->l(''),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Migrate')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Not Migrate')
                            )
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'SHOPIFYMIGRATE_LIVE_MODE' => Configuration::get('SHOPIFYMIGRATE_LIVE_MODE', true),
            'SHOPIFYMIGRATE_ACCOUNT_EMAIL' => Configuration::get('SHOPIFYMIGRATE_ACCOUNT_EMAIL', 'contact@prestashop.com'),
            'SHOPIFYMIGRATE_ACCOUNT_PASSWORD' => Configuration::get('SHOPIFYMIGRATE_ACCOUNT_PASSWORD', null),
        );
    }

    protected function exportProductSql()
    {
        $sql_query = "
        SELECT
        p.id_product,
        p.active,
        pl.name,
        GROUP_CONCAT(DISTINCT (cl.name)
        SEPARATOR ',') as categories,
        p.price,
        p.id_tax_rules_group,
        p.wholesale_price,
        p.reference,
        p.id_supplier,
        p.id_manufacturer as vendor_id,
        pm.name as vendor,
        p.ecotax,
        p.weight,
        p.quantity,
        pl.description_short,
        pl.description,
        pl.link_rewrite,
        pl.available_now,
        pl.available_later,
        p.available_for_order,
        p.date_add,
        concat('http://',
        ifnull(shop_domain.value, 'domain'),
        '/presta/img/p/',
        if(CHAR_LENGTH(pi.id_image) >= 5,
        concat(SUBSTRING(pi.id_image from - 5 FOR 1),
        '/'),
        ''),
        if(CHAR_LENGTH(pi.id_image) >= 4,
        concat(SUBSTRING(pi.id_image from - 4 FOR 1),
        '/'),
        ''),
        if(CHAR_LENGTH(pi.id_image) >= 3,
        concat(SUBSTRING(pi.id_image from - 3 FOR 1),
        '/'),
        ''),
        if(CHAR_LENGTH(pi.id_image) >= 2,
        concat(SUBSTRING(pi.id_image from - 2 FOR 1),
        '/'),
        ''),
        if(CHAR_LENGTH(pi.id_image) >= 1,
        concat(SUBSTRING(pi.id_image from - 1 FOR 1),
        '/'),
        ''),
        pi.id_image,
        '.jpg') as image_url,
        p.online_only,
        p.condition,
        p.id_shop_default
        FROM
        ps_product p
        LEFT JOIN ps_product_lang pl ON (p.id_product = pl.id_product)
        LEFT JOIN ps_manufacturer pm ON (pm.id_manufacturer = p.id_manufacturer)
        LEFT JOIN ps_category_product cp ON (p.id_product = cp.id_product)
        LEFT JOIN ps_category_lang cl ON (cp.id_category = cl.id_category)
        LEFT JOIN ps_category c ON (cp.id_category = c.id_category)
        LEFT JOIN ps_product_tag pt ON (p.id_product = pt.id_product)
        LEFT JOIN ps_image pi ON p.id_product = pi.id_product
        LEFT JOIN ps_configuration shop_domain ON shop_domain.name = 'PS_SHOP_DOMAIN'
        GROUP BY p.id_product";

        $query = Db::getInstance()->executeS($sql_query);
        $qt = Db::getInstance()->NumRows($sql_query);

        $url = 'http://localhost:5000/';
        $data = array('products' => json_encode($query), 'nums' => $qt);

        $options = array(
            'http' => array(
            'header'  => "Content-type: application/x-www-form-urlencoded",
            'method'  => 'POST',
            'content' => http_build_query($data),
        ),
        );
        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        var_dump($result);

        return $result;
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        if(Tools::getValue('SHOPIFYMIGRATE_NOW') == true) {
            $products_array = $this->exportProductSql();
        };

    }

    /**
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }

    public function hookActionOrderReturn()
    {
        /* Place your code here. */
    }

    public function hookActionProductAdd()
    {
        /* Place your code here. */
    }

    public function hookActionProductDelete()
    {
        /* Place your code here. */
    }

    public function hookActionProductSave()
    {
        /* Place your code here. */
    }

    public function hookActionProductUpdate()
    {
        /* Place your code here. */
    }
}

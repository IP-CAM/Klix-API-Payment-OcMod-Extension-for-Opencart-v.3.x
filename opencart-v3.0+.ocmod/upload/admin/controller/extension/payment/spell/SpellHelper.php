<?php

class SpellHelper
{
    private $language;
    private $load;
    private $model_localisation_order_status;
    private $model_setting_setting;

    public function __construct($registry)
    {
        $this->language = $registry->get('language');
        $this->load = $registry->get('load');
        $this->model_localisation_order_status = $registry->get('model_localisation_order_status');
        $this->model_setting_setting = $registry->get('model_setting_setting');
    }

    public function init()
    {
        // Load models
        $this->load->language('extension/payment/spell_payment');
        $this->load->model('setting/setting');
        $this->load->model('setting/store');
        $this->load->model('localisation/order_status');
    }

    /** @return ModelLocalisationOrderStatus */
    public function getOrderStatus()
    {
        $this->load->model('localisation/order_status');

        return $this->model_localisation_order_status;
    }

    /** @return ModelSettingSetting */
    public function getSettingSetting()
    {
        $this->load->model('setting/setting');

        return $this->model_setting_setting;
    }

    public function getAdminLangdata()
    {
        $lang = array();
        $lang['module_config_title'] = $this->language->get('text_module_config_title');
        $lang['enable_api'] = $this->language->get('text_enable_api');
        $lang['enable_payment_method_selection'] = $this->language->get('text_enable_pm_selection');
        $lang['pm_info'] = $this->language->get('text_pm_info');
        $lang['pm_description'] = $this->language->get('text_pm_description');
        $lang['pm_description_info'] = $this->language->get('text_pm_description_info');
        $lang['pm_title'] = $this->language->get('text_pm_title');
        $lang['enable_pdp'] = $this->language->get('text_enable_pdp');
        $lang['pm_title_info'] = $this->language->get('text_pm_title_info');
        $lang['enable_pdp_info'] = $this->language->get('text_enable_pdp_info');
        $lang['brand_id'] = $this->language->get('text_brand_id');
        $lang['brand_id_info'] = $this->language->get('text_brand_id_info');
        $lang['secret_key'] = $this->language->get('text_secret_key');
        $lang['secret_key_info'] = $this->language->get('text_secret_key_info');
        $lang['secret_key'] = $this->language->get('text_secret_key');
        $lang['order_payment_success_status'] = $this->language->get('text_order_payment_success_status');
        $lang['order_payment_error_status'] = $this->language->get('text_order_payment_error_status');
        $lang['order_payment_success_status_info'] = $this->language->get('text_order_payment_success_status_info');
        $lang['order_payment_error_status_info'] = $this->language->get('text_order_payment_error_status_info');
        $lang['select_option'] = $this->language->get('text_select_option');
        $lang['enable_log'] = $this->language->get('text_enable_log');
        $lang['debug_log'] = $this->language->get('text_debug_log');
        $lang['log_to'] = $this->language->get('text_log_to');

        return $lang;
    }
}

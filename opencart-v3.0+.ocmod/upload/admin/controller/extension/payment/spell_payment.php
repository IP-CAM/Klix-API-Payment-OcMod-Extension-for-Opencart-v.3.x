<?php

require_once realpath(dirname(__FILE__)) . '/spell/SpellHelper.php';
require_once realpath(dirname(__FILE__)) . '/spell/SpellRefundHelper.php';

class ControllerExtensionPaymentSpellPayment extends Controller
{
    /**
     * each setting must start with "payment_spell_payment_",
     * otherwise OpenCart will just ignore it I believe
     */
    const SETTINGS = array(
        "payment_spell_payment_brand_id",
        "payment_spell_payment_secret_code",
        "payment_spell_payment_status",
        "payment_spell_payment_debug",
        "payment_spell_payment_enabled",
        "payment_spell_payment_pdp",
        "payment_spell_payment_method_desc",
        "payment_spell_payment_method_title",
        "payment_spell_payment_success_status_id",
        "payment_spell_payment_error_status_id",
    );

    public function index()
    {
        $this->registry->set('spellhelper', new SpellHelper($this->registry));
        $this->spellhelper->init();
        $tpl_data = $this->collectTmplData();
        $html = $this->load->view('extension/payment/spell_payment', $tpl_data);
        $this->response->setOutput($html);
    }

    private function collectTmplData()
    {
        $errors = array();
        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            $errors = $this->validate();
            if (!$errors) {
                if (!array_key_exists('payment_spell_payment_enabled', $this->request->post)) {
                    $this->request->post['payment_spell_payment_enabled'] = false;
                }
                if (!array_key_exists('payment_spell_payment_pdp', $this->request->post)) {
                    $this->request->post['payment_spell_payment_pdp'] = false;
                }
                $this->model_setting_setting->editSetting('payment_spell_payment', $this->request->post);
            }
            $tpl_data = $this->request->post;
        } else {
            foreach (self::SETTINGS as $setting) {
                $tpl_data[$setting] = $this->config->get($setting);
            }
        }
        $tpl_data['header'] = $this->load->controller('common/header');
        $tpl_data['column_left'] = $this->load->controller('common/column_left');
        $tpl_data['footer'] = $this->load->controller('common/footer');
        $tpl_data['error_warning'] = implode('; ', $errors);
        $tpl_data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $tpl_data['languages'] = $this->spellhelper->getAdminLangdata();
        $tpl_data['logs_dir_path'] = DIR_LOGS;

        return $tpl_data;
    }

    private function validate()
    {
        $errors = [];
        if (!$this->user->hasPermission('modify', 'extension/payment/spell_payment')) {
            $errors[] = $this->language->get('error_permission');
        }
        if (!$this->request->post['payment_spell_payment_brand_id']) {
            $errors[] = $this->language->get('error_brand_id');
        }
        if (!$this->request->post['payment_spell_payment_secret_code']) {
            $errors[] = $this->language->get('error_secrete_key');
        }
        if (!$this->request->post['payment_spell_payment_success_status_id']) {
            $errors[] = $this->language->get('error_success_step');
        }
        if (!$this->request->post['payment_spell_payment_error_status_id']) {
            $errors[] = $this->language->get('error_error_step');;
        }

        return $errors;
    }

    public function refund(){
        $this->registry->set('spellrefundhelper', new SpellRefundHelper($this->registry));
        $this->spellrefundhelper->init();
		$this->load->model('sale/order');
        if (isset($this->request->post['order_id'])) {
			$order_id = $this->request->post['order_id'];
		} else {
			$order_id = 0;
		}
        if (isset($this->request->post['refund-amount'])) {
            $amount = $this->request->post['refund-amount'];
        }
		$order_info = $this->model_sale_order->getOrder($order_id);
        $title = $this->config->get('payment_spell_payment_method_desc') ?: 'Klix E-commerce Gateway';
		if ($order_info && $order_info['payment_method'] == $title) {
            $this->spellrefundhelper->process_refund($order_info,$amount);
        }
        if($this->request->server['HTTP_REFERER']){
            $this->response->redirect($this->request->server['HTTP_REFERER']);
        }else{
            $this->response->redirect($this->url->link('sale/order', 'user_token=' . $this->session->data['user_token'] . $url, true));
        }
    }
}

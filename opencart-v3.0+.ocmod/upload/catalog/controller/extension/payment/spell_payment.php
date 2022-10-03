<?php

require_once __DIR__ . '/../../../model/extension/payment/spell/api.php';
require_once __DIR__ . '/../../../model/extension/payment/spell/DefaultLogger.php';
require_once realpath(dirname(__FILE__)) . '/spell/helper/LanguageHelper.php';
require_once realpath(dirname(__FILE__)) . '/spell/controller/OrderController.php';
require_once realpath(dirname(__FILE__)) . '/spell/controller/PDPCheckoutController.php';

class ControllerExtensionPaymentSpellPayment extends Controller
{
    /**
     * @return array [
     *     'currency' => 'USD',
     *     'customer_id' => '2',
     *     'shipping_address' => (array),
     *     'payment_address' => (array), // same format as shipping_address
     *     'shipping_methods' => (array),
     *     'shipping_method' => (array),
     *     'comment' => '',
     *     'payment_methods' => (array),
     *     'payment_method' => [
     *         'code' => 'spell_payment',
     *         'terms' => '',
     *         'title' => 'Spell Payment',
     *         'sort_order' => null,
     *     ],
     *     'order_id' => 11,
     * ]
     */
    private function getSessionData()
    {
        return $this->session->data;
    }

    /** @return ModelExtensionPaymentSpellPayment */
    private function getSpellModel()
    {
        $this->load->model('extension/payment/spell_payment');

        return $this->model_extension_payment_spell_payment;
    }

    /** @return ModelCheckoutOrder */
    private function getCheckoutOrder()
    {
        $this->load->model('checkout/order');

        return $this->model_checkout_order;
    }

    private function showErrorPage()
    {
        $this->response->redirect($this->url->link('checkout/failure', '', true));
    }

    private static function collectByMethod($payment_methods)
    {
        $by_method = [];
        foreach ($payment_methods['by_country'] as $country => $pms) {
            foreach ($pms as $pm) {
                if (!array_key_exists($pm, $by_method)) {
                    $max_multiple_logos_width = -1;
                    if (is_array($payment_methods['logos'][$pm])) {
                        $max_multiple_logos_width = count($payment_methods['logos'][$pm]);
                        if ($max_multiple_logos_width > 4) {
                            $max_multiple_logos_width = 4;
                        }
                        $max_multiple_logos_width = $max_multiple_logos_width * 50;
                    }

                    $by_method[$pm] = [
                        "payment_method" => $pm,
                        "countries" => [],
                        "max_multiple_logos_width" => $max_multiple_logos_width,
                    ];
                }
                if (!in_array($country, $by_method[$pm]["countries"])) {
                    $by_method[$pm]["countries"][] = $country;
                }
            }
        }

        return $by_method;
    }

    private function collectTplData()
    {
        $title = $this->config->get('payment_spell_payment_method_title');
        $currency = $this->getSessionData()['currency'];
        $spell = $this->getSpellModel()->getSpell();
        $language = $this->languageHelper->get_language();

        $amountInCents=$this->cart->getTotal()*100;

        if(isset($this->session->data['shipping_method']['cost'])) {
            $amountInCents+=$this->session->data['shipping_method']['cost']*100;
        }
        
        $payment_methods = $spell->payment_methods($currency, $language,$amountInCents);
        

        if (is_null($payment_methods)) {
            return ['error' => $this->language->get('text_system_error')];
        }

        if (!array_key_exists('by_country', $payment_methods)) {
            return ['error' => $this->language->get('text_plugin_config_error')];
        }

        $country_options = array_values(array_unique(
            array_keys($payment_methods['by_country'])
        ));
        $any_index = array_search('any', $country_options);
        if ($any_index !== false) {
            array_splice($country_options, $any_index, 1);
            $country_options = array_merge($country_options, ['any']);
        }

        $detected_country = $this->getCheckoutOrder()->getOrder(
            $this->session->data['order_id']
        )['payment_iso_code_2'];
        $selected_country = '';
        if (in_array($detected_country, $country_options)) {
            $selected_country = $detected_country;
        } elseif ($any_index !== false) {
            $selected_country = 'any';
        } elseif (count($country_options) > 0) {
            $selected_country = $country_options[0];
        }

        return [
            'title' => $title ?: $this->language->get('text_select_payment_method'),
            'place_order' => $this->language->get('text_place_order'),
            'action' => $this->url->link('extension/payment/spell_payment/process', '', true),
            'payment_methods_api_data' => $payment_methods,
            'country_options' => $country_options,
            'by_method' => self::collectByMethod($payment_methods),
            'config' => [
                'payment_spell_payment_enabled' => $this->config->get('payment_spell_payment_enabled'),
            ],
            'selected_country' => $selected_country,
        ];
    }

    /** @Route(part of "/index.php?route=extension/payment/spell_payment/confirm") */
    public function index()
    {
        $this->load->language('extension/payment/spell_payment');
        $this->registry->set('languageHelper', new LanguageHelper($this->registry));
        $tpl_data = $this->collectTplData();

        return $this->load->view('extension/payment/spell_payment', $tpl_data);
    }

    private function collectUrlParams()
    {
        $order_id = $this->session->data['order_id'];
        $arr = array(
            // 'country' => $this->request->post['country'],
            'order_info' => $this->getCheckoutOrder()->getOrder($order_id),
        );

        if (array_key_exists('payment_method', $this->request->post)) {
            $arr['payment_method'] = $this->request->post['payment_method'];
        }

        return $arr;
    }

    /** @Route("/index.php?route=extension/payment/spell_payment/process") */
    public function process()
    {
        $urlParams = $this->collectUrlParams();
        $payment = $this->getSpellModel()->createPayment($urlParams);
        if (!isset($payment['checkout_url'])) {
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        } else {
            $this->session->data['spell_payment_id'] = $payment['id'];
            header("Location:" . $payment['checkout_url']);
        }
    }

    public function success()
    {
        $this->db->query("SELECT GET_LOCK('spell_payment', 15);");

        $payment_id = $this->session->data['spell_payment_id'];
        $purchases = $this->getSpellModel()->getSpell()->purchases($payment_id);
        $status = !$purchases ? null : $purchases['status'];
        $purchases_payment_method = $purchases['transaction_data']['payment_method'];
        $orderId = $purchases['reference'];
        $order = $this->getCheckoutOrder()->getOrder($orderId);
        $successStatusId = $this->config->get('payment_spell_payment_success_status_id');
        if ($status === 'paid') {
            if ($successStatusId !== $order['order_status_id']) {
                $this->getCheckoutOrder()->addOrderHistory($orderId, $successStatusId, $status);
                // payment_id into the db.
                $payment_id = $this->session->data['spell_payment_id'];
                if($purchases_payment_method === 'klix'){
                    $sql = "UPDATE `".DB_PREFIX."order` SET `payment_code` = 'spell_payment_".$this->db->escape($payment_id)."' WHERE `oc_order`.`order_id` = ".$orderId;
                    $this->db->query( $sql );
                }else{
                    $sql = "UPDATE `".DB_PREFIX."order` SET `payment_code` = 'spell_multilink_payment_".$this->db->escape($payment_id)."' WHERE `oc_order`.`order_id` = ".$orderId;
                    $this->db->query( $sql );
                }
            }
            $this->response->redirect($this->url->link('checkout/success', '', true));
        } else {
            if ($successStatusId === $order['order_status_id']) {
                $this->response->redirect($this->url->link('checkout/success', '', true));
            } else {
                $errorStatusId = $this->config->get('payment_spell_payment_error_status_id');
                $this->getCheckoutOrder()->addOrderHistory($orderId, $errorStatusId, $status);
                $this->showErrorPage($this->language->get('error_unexpected_gateway') . ' - ' . $status);
            }
        }

        $this->db->query("SELECT RELEASE_LOCK('spell_payment');");
    }

    public function error()
    {
        $payment_id = $this->session->data['spell_payment_id'];
        $purchases = $this->getSpellModel()->getSpell()->purchases($payment_id);
        $orderId = $purchases['reference'];
        $order = $this->getCheckoutOrder()->getOrder($orderId);
        $errorStatusId = $this->config->get('payment_spell_payment_error_status_id');
        $successStatusId = $this->config->get('payment_spell_payment_success_status_id');
        if ($successStatusId === $order['order_status_id']) {
            $this->response->redirect($this->url->link('checkout/success', '', true));
        } else {
            $this->getCheckoutOrder()->addOrderHistory($orderId, $errorStatusId, $purchases['status']);
            $errorMsg = $this->language->get('error_gateway_fail');
            $this->showErrorPage($errorMsg);
        }
    }

    public function callback()
    {
        $this->db->query("SELECT GET_LOCK('spell_payment', 15);");

        $spell = $this->getSpellModel()->getSpell();
        $orderId = $_GET['id'];
        $order = $this->getCheckoutOrder()->getOrder($orderId);
        $payment_id = $this->session->data['spell_payment_id'];
        if (!$payment_id) {
            $input = json_decode(file_get_contents('php://input'), true);
            $payment_id = array_key_exists('id', $input) ? $input['id'] : '';
        }
        $purchase = $spell->purchases($payment_id);
        $status = !$purchase ? null : $purchase['status'];
        $successStatusId = $this->config->get('payment_spell_payment_success_status_id');
        if ($spell->was_payment_successful($payment_id) && $status === 'paid') {
            $order_history_id = $this->getCheckoutOrder()->addOrderHistory($orderId, $successStatusId, $status);
        } else {
            if ($successStatusId === $order['order_status_id']) {
                $this->response->redirect($this->url->link('checkout/success', '', true));
            } else {
                $errorStatusId = $this->config->get('payment_spell_payment_error_status_id');
                $this->getCheckoutOrder()->addOrderHistory($orderId, $errorStatusId, $status);
            }
        }

        $this->db->query("SELECT RELEASE_LOCK('spell_payment');");
    }

    /**
     * Function for once click process
     *
     * @Route("/index.php?route=extension/payment/spell_payment/oneClickProcess")
     *
     * @return void
     */
    public function oneClickProcess()
    {
        $urlParams = $this->collectUrlParams();
        $payment = $this->getSpellModel()->createPayment($urlParams, true);
        if (!isset($payment['checkout_url'])) {
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        } else {
            $this->session->data['spell_payment_id'] = $payment['id'];
            header("Location:" . $payment['checkout_url']);
        }
    }

    /**
     * Callback function
     *
     * @return void
     */
    public function oneClickCallback()
    {
        $this->registry->set(
            'PDPCheckoutController',
            new PDPCheckoutController($this->registry)
        );
        $this->PDPCheckoutController->oneClickCallback();
    }

    /**
     * Function for creating the order
     * after one click success from pdp or cart
     *
     * @param $purchase
     *
     * @return void;
     */
    public function createOrder($purchase)
    {
        $this->registry->set(
            'OrderController',
            new OrderController($this->registry)
        );
        return $this->OrderController->createOrder($purchase);
    }

    /**
     * Call back function for success
     *
     * @return void;
     */
    public function oneClickSuccess()
    {
        $this->db->query("SELECT GET_LOCK('spell_payment', 15);");
        $payment_id = $this->session->data['spell_payment_id'];
        $purchases = $this->getSpellModel()->getSpell()->purchases($payment_id);
        $orderId = $this->createOrder($purchases);
        $status = !$purchases ? null : $purchases['status'];
        $order = $this->getCheckoutOrder()->getOrder($orderId);
        $successStatusId = $this->config->get('payment_spell_payment_success_status_id');

        if ($status === 'paid') {
            if ($successStatusId !== $order['order_status_id']) {
                $order_history_id = $this->getCheckoutOrder()->addOrderHistory($orderId, $successStatusId, $status);
            }
            $this->response->redirect($this->url->link('checkout/success', '', true));
        } else {
            if ($successStatusId === $order['order_status_id']) {
                $this->response->redirect($this->url->link('checkout/success', '', true));
            } else {
                $errorStatusId = $this->config->get('payment_spell_payment_error_status_id');
                $this->getCheckoutOrder()->addOrderHistory($orderId, $errorStatusId, $status);
                $this->showErrorPage($this->language->get('error_unexpected_gateway') . ' - ' . $status);
            }
        }

        $this->db->query("SELECT RELEASE_LOCK('spell_payment');");
    }

    /**
     * Call back function for error
     *
     * @return void;
     */
    public function oneClickError()
    {
        $errorMsg = $this->language->get('error_gateway_fail');
        $this->showErrorPage($errorMsg);
    }

    /**
     * Call back function for error
     *
     * @return void;
     */
    public function cancel()
    {
        $this->response->redirect($this->url->link('checkout/cart', '', true));
    }

    public function install()
    {
        $this->checkFieldInModel();
    }

    public function checkFieldInModel() {
        $isModelField = FALSE;
        $result = $this->db->query( "DESCRIBE `".DB_PREFIX."order`;" );
        foreach ($result->rows as $row) {
           if ($row['Field'] == 'payment_id') {
              $isModelField = TRUE;
              break;
           }
        }
        if (!$isModelField) {
           $sql = "ALTER TABLE `".DB_PREFIX."order` ADD `payment_id` int( 11 ) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT ''";
           $this->db->query( $sql );
        }
    }
}

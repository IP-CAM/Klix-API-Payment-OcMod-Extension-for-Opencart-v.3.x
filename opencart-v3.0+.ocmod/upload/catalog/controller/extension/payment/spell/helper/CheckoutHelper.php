<?php

require_once __DIR__ . '/../../spell/helper/Helper.php';

class CheckoutHelper
{
    const SPELL_MODULE_VERSION = 'v1.1.6';

    private $load;
    private $tax;
    private $log;
    private $url;
    private $cart;
    private $currency;
    private $registry;
    private $customer;
    private $session;
    private $config;
    private $request;
    private $languageHelper;
    private $model_catalog_product;
    private $model_setting_extension;
    private $helpers;

    public function __construct($registry)
    {
        $this->registry = $registry;
        $this->language = $registry->get('language');
        $this->load = $registry->get('load');
        $this->cart = $registry->get('cart');
        $this->tax = $registry->get('tax');
        $this->url = $registry->get('url');
        $this->log = $registry->get('log');
        $this->request = $registry->get('request');
        $this->customer = $registry->get('customer');
        $this->session = $registry->get('session');
        $this->config = $registry->get('config');
        $this->currency = $registry->get('currency');
        $this->load->model('localisation/zone');
        $this->load->model('catalog/product');
        $this->load->model('setting/extension');
        $this->load->model('checkout/order');
        $this->model_catalog_product = $registry->get('model_catalog_product');
        $this->model_setting_extension = $registry->get('model_setting_extension');
        $this->languageHelper =  new LanguageHelper($this->registry);
        $this->helpers =  new Helpers($this->registry);
    }

    
    public function paymentParamsArray($order,$isOneClick = false)
    {
        $default_params =  [
            'creator_agent' => 'OpenCart module: ' . self::SPELL_MODULE_VERSION,
            'reference' => (string) $this->helpers->gerReference($order),
            'platform' => 'opencart',
            'purchase' => $this->helpers->getPurchase($order,$isOneClick),
            'client' => $this->helpers->getClientInfo($order),
            'brand_id' => $this->helpers->getBrandId()
        ];
        if (!array_key_exists('order_id', $order)) {
            $default_params['payment_method_whitelist'] = ['klix'];
        }
        $redirect_urls = $this->helpers->getRedirectAndCallbackUrl($order);
        return array_merge($redirect_urls, $default_params);
    }

    public function createPayment($urlParams,$isOneClick = false)
    {
        $spell = $this->helpers->getSpell();
        if(!$isOneClick){
            $paymentParams = $this->makePaymentParams($urlParams);
        }else{
            $paymentParams = $this->_makeOneClickPaymentParams($urlParams,$isOneClick);

        }
        $payment = $spell->create_payment($paymentParams);
        if (!array_key_exists('id', $payment)) {
            return [
                'id' => false
            ];
        }

        $checkout_url = $payment['checkout_url'];
        $this->logger = new DefaultLogger($this->log);
        $spell->log_info("INFO: " . print_r($paymentParams, true) . ";");
        $spell->log_info("INFO: " . print_r($payment, true) . ";");

        if (isset($urlParams['payment_method'])) {
            $checkout_url .= '?preferred=' . $urlParams['payment_method'];
        }

        return [
            'id' => $payment['id'],
            'checkout_url' => $checkout_url,
        ];
    }

    /**
     * Create the object of parameters
     *
     * @return array of payament data;
     */
    public function _makeOneClickPaymentParams()
    {
        $this->load->model('localisation/currency');
        $this->load->model('setting/setting');
        $this->load->model('catalog/product');
        $product_ids = [];

        if (array_key_exists('product_id', $this->request->get)) {
            $product_ids[] = $this->request->get['product_id'];
            $product_info = $this->model_catalog_product->getProduct($this->request->get['product_id']);

            $price = $product_info['price'];
            if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
                $price = $this->currency->format(
                    $this->tax->calculate($product_info['price'], $product_info['tax_class_id'], $this->config->get('config_tax')),
                    $this->session->data['currency']
                );
            }

            if (!is_null($product_info['special']) && (float) $product_info['special'] >= 0) {
                $price = $this->currency->format(
                    $this->tax->calculate($product_info['special'], $product_info['tax_class_id'], $this->config->get('config_tax')),
                    $this->session->data['currency']
                );
            }
            $total = $this->currency->format(
                $price,
                $this->helpers->getCurrency(),
                $this->currency->getValue($this->session->data['currency']),
                false
            );
            $total = (int) (string) ($total * 100);
            $products[] = array(
                'product_id' => $product_info['product_id'],
                'name' => $product_info['product_id'] . ',' . $product_info['name'],
                'price' => $total,
                'quantity' => 1,
            );
            $this->cart->add($product_info['product_id'], 1, [], 0);
        } else {
            $cart_products = $this->cart->getProducts();
            if (!empty($cart_products)) {
                foreach ($cart_products as $key => $cart_product) {
                    $product_ids[] = $cart_product['product_id'];
                    $pid = $cart_product['product_id'];
                    $product_info = $this->model_catalog_product->getProduct($pid);
                    $tax = $this->tax->getTax($product_info['price'], $product_info['tax_class_id']);
                    $total = $this->helpers->getProductTotal($product_info, $tax);
                    $products[] = array(
                        'product_id' => $product_info['product_id'],
                        'name' => $product_info['product_id'] . ',' . $product_info['name'],
                        'price' => $total,
                        'quantity' => $cart_product['quantity'],
                    );
                }
            }
        }
        return $this->paymentParamsArray($products,true);
    }

    public function makePaymentParams($urlParams)
    {
        $this->load->model('localisation/currency');
        $this->load->model('setting/setting');
        $this->registry->set('languageHelper', new LanguageHelper($this->registry));
        $order = $urlParams['order_info'];
        return $this->paymentParamsArray($order,false);
    }

}

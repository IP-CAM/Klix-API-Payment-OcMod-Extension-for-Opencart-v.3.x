<?php

require_once __DIR__ . '/../../spell/helper/LanguageHelper.php';

require_once __DIR__ . '/../../../../../model/extension/payment/spell/api.php';

require_once __DIR__ . '/../../../../../model/extension/payment/spell/DefaultLogger.php';

class Helpers
{
    
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
    }


    public function getBrandId()
    {
        return $this->config->get('payment_spell_payment_brand_id');
    }

    public function getSpell()
    {
        $brand_id = $this->getBrandId();
        $secret_code = $this->config->get('payment_spell_payment_secret_code');
        $debug = $this->config->get('payment_spell_payment_debug') === 'on' ? true : false;
        $logger = new DefaultLogger($this->log);

        return new SpellAPI($secret_code, $brand_id, $logger, $debug);
    }

    public function getPurchase($order,$isOneClick)
    {
        if($isOneClick){
            return [
                "currency" => $this->getCurrency(),
                "language" => $this->languageHelper->get_language(),
                "notes" => $this->getNotes(),
                "products" => $this->getProduct($order),
                'shipping_options' => $this->getShippingPackages(),
            ];
        }else{
            return [
                "currency" => $this->getCurrency(),
                "language" => $this->languageHelper->get_language(),
                "notes" => $this->getNotes(),
                "products" => $this->getProduct($order)
            ];
        }
    }

    public function getShippingPackages()
    {
        $result = array();
        $this->load->model('setting/extension');

        try {
            $shippingMethods = $this->model_setting_extension->getExtensions('shipping');
            foreach ($shippingMethods as $shippingMethod) {
                if ($this->config->get('shipping_' . $shippingMethod['code'] . '_status')) {
                    $this->load->model('extension/shipping/' . $shippingMethod['code']);

                    $shippingModels = $this->registry->get('model_extension_shipping_' . $shippingMethod['code']);
                    if (isset($this->session->data['shipping_address'])) {
                        $quotes = $shippingModels->getQuote($this->session->data['shipping_address']);
                        $quote = $quotes['quote'];
                        foreach ($quote as $key => $method) {
                            if ($method) {
                                $result[] = array(
                                    'id' => $method['tax_class_id'],
                                    'label' => $method['title'],
                                    'price' => round($method['cost'] * 100),
                                );
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $this->getSpell()->log_error('Unable to retrieve shipping packages! Message - ' . $e->getMessage());
        }
        return $result;
    }

    public function getProductTotal($order, $tax = 0)
    {
        if (array_key_exists('price',$order)) {
            $total = $this->currency->format(
                $order['price'],
                $this->getCurrency(),
                $this->currency->getValue($this->session->data['currency']),
                false
            );
        } else {
            $total = $this->currency->format(
                $order['total'],
                $order['currency_code'],
                $order['currency_value'],
                false
            );
        }
        $total = (int)(string)(($total + $tax) * 100);
        return $total;
    }

    public function getProduct($products)
    {
        if (!array_key_exists('order_id',$products)) {
            return  $products;
        }else{
            return [
                [
                    'name' => 'Payment',
                    'price' => $this->getProductTotal($products),
                    'quantity' => 1,
                ],
            ];
        }
    }

    public function getCurrency()
    {
        $currency = 'EUR'; // fallback to the default currency, if it's not set
        if (array_key_exists('currency', $this->session->data)) {
            $currency = $this->session->data['currency'];
        }
        return $currency;
    }

    private function getNotes()
    {
        $cart_products = $this->cart->getProducts();
        $nameString = '';
        if (!empty($cart_products)) {
            foreach ($cart_products as $key => $cart_product) {
                if ($key == 0) {
                    $nameString = $cart_product['name'];
                } else {
                    $nameString = $nameString . ';' . $cart_product['name'];
                }
            }
        }
        return $nameString;
    }

    public function gerReference($orders)
    {
        $reference = "";
        if (!array_key_exists('order_id', $orders)) {
            $product_ids = [];
            foreach ($orders as $key => $order) {
                $product_ids[] = $order['product_id'];
            }
            $reference = implode(',', $product_ids);
        } else {
            $reference = $orders['order_id'];
        }
        return $reference;
    }

    public function getClientInfo($order)
    {
        if (!array_key_exists('order_id', $order)) {
            return [
                'email' => 'dummy@data.com'
            ];
        }
        return [
            'email' => $order['email'],
            'phone' => $order['telephone'],
            'full_name' => $order['payment_firstname'] . ' '
                . $order['payment_lastname'],
            'street_address' => $order['payment_address_1'] . ' '
                . $order['payment_address_2'],
            'country' => $order['payment_iso_code_2'],
            'city' => $order['payment_city'],
            'zip_code' => $order['payment_postcode'],
            'shipping_street_address' => $order['shipping_address_1']
                . ' ' . $order['shipping_address_2'],
            'shipping_country' => $order['shipping_iso_code_2'],
            'shipping_city' => $order['shipping_city'],
            'shipping_zip_code' => $order['shipping_postcode'],
        ];
    }

    public function getRedirectAndCallbackUrl($order)
    {
        if (!array_key_exists('order_id',$order)) {
            return $this->klixOneClickRedirect();
        } else {
            return $this->klixRedirect($order);
        }
    }

    private function klixRedirect($order)
    {
        return [
            'success_callback' => $this->url->link('extension/payment/spell_payment/callback&id=' . $order['order_id'], '', true),
            'success_redirect' => $this->url->link('extension/payment/spell_payment/success', '', true),
            'failure_redirect' => $this->url->link('extension/payment/spell_payment/error', '', true),
            'cancel_redirect' => $this->url->link('extension/payment/spell_payment/cancel', '', true)
        ];
    }

    private function klixOneClickRedirect()
    {
        return [
            'success_callback' => $this->url->link('extension/payment/spell_payment/oneClickCallback'),
            'success_redirect' => $this->url->link('extension/payment/spell_payment/OneClickSuccess'),
            'failure_redirect' => $this->url->link('extension/payment/spell_payment/OneClickError'),
            'cancel_redirect' => $this->url->link('extension/payment/spell_payment/cancel'),
        ];
    }
}

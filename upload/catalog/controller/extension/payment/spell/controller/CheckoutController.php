<?php

require_once __DIR__ . '/../../../../../model/extension/payment/spell/DefaultLogger.php';
require_once __DIR__ . '/../../spell/helper/LanguageHelper.php';

class CheckoutController
{

    const SPELL_MODULE_VERSION = 'v1.1.1e';

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

    private function getBrandId()
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

    /**
     * Create the object of parameters
     *
     * @return array of payament data;
     */
    private function _makeOneClickPaymentParams()
    {
        $this->load->model('localisation/currency');
        $this->load->model('setting/setting');
        $this->load->model('catalog/product');
        $product_ids = [];
        $currency = 'EUR'; // fallback to the default currency, if it's not set

        if (array_key_exists('product_id', $this->request->get)) {
            $product_ids[] = $this->request->get['product_id'];
            $product_info = $this->model_catalog_product->getProduct($this->request->get['product_id']);
            if (array_key_exists('currency', $this->session->data)) {
                $currency = $this->session->data['currency'];
            }
            $price = $product_info['price'];
            if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
                $price = $this->currency->format($this->tax->calculate($product_info['price'], $product_info['tax_class_id'], $this->config->get('config_tax')),
                    $this->session->data['currency']);
            }

            if (!is_null($product_info['special']) && (float) $product_info['special'] >= 0) {
                $price = $this->currency->format($this->tax->calculate($product_info['special'], $product_info['tax_class_id'], $this->config->get('config_tax')),
                    $this->session->data['currency']);
                $tax_price = (float) $product_info['special'];
            }
            $total = $this->currency->format(
                $price,
                $currency,
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
                    if (array_key_exists('currency', $this->session->data)) {
                        $currency = $this->session->data['currency'];
                    }
                    $total = $this->currency->format(
                        $product_info['price'],
                        $currency,
                        $this->currency->getValue($this->session->data['currency']),
                        false
                    );
                    $tax = $this->tax->getTax($product_info['price'], $product_info['tax_class_id']);
                    $total = (int)(string)(($total + $tax) * 100);
                    $products[] = array(
                        'product_id' => $product_info['product_id'],
                        'name' => $product_info['product_id'] . ',' . $product_info['name'],
                        'price' => $total,
                        'quantity' => $cart_product['quantity'],
                    );
                }
            }
        }

        return [
            'success_callback' => $this->url->link('extension/payment/spell_payment/oneClickCallback'),
            'success_redirect' => $this->url->link('extension/payment/spell_payment/OneClickSuccess'),
            'failure_redirect' => $this->url->link('extension/payment/spell_payment/OneClickError'),
            'cancel_redirect' => $this->url->link('extension/payment/spell_payment/OneClickError'),
            'creator_agent' => 'OpenCart module: ' . self::SPELL_MODULE_VERSION,
            'reference' => implode(',', $product_ids),
            'platform' => 'opencart',
            'purchase' => [
                "currency" => $currency,
                "language" => $this->languageHelper->get_language(),
                "notes" => "",
                'shipping_options' => $this->getShippingPackages(),
                "products" => $products
            ],
            'brand_id' => $this->getBrandId(),
            'payment_method_whitelist' => ['klix'],
            'client' => [
                'email' => 'dummy@data.com',
            ],
        ];
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
        } catch (Exception $e) {
            $this->getSpell()->log_error('Unable to retrieve shipping packages! Message - ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * The function is for creating the object of one click payment
     *
     * @param mix $urlParams accept the url parameters
     *
     * @return payment_id,checkout_url
     */
    public function createOneClickPayment($urlParams)
    {
        $spell = $this->getSpell();
        $paymentParams = $this->_makeOneClickPaymentParams();
        $payment = $spell->create_payment($paymentParams);
        if (!array_key_exists('id', $payment)) {
            return [
                'id' => false,
            ];
        }

        $checkout_url = $payment['checkout_url'];

        return [
            'id' => $payment['id'],
            'checkout_url' => $checkout_url,
        ];
    }
}

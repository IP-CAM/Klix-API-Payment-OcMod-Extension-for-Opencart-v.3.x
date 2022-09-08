<?php

class OrderController
{
    private $load;
    private $tax;
    private $db;
    private $cart;
    private $currency;
    private $registry;
    private $language;
    private $session;
    private $request;
    private $model_localisation_zone;
    private $model_setting_extension;
    private $model_catalog_product;
    private $model_checkout_order;

    public function __construct($registry)
    {
        $this->language = $registry->get('language');
        $this->load = $registry->get('load');
        $this->db = $registry->get('db');
        $this->tax = $registry->get('tax');
        $this->cart = $registry->get('cart');
        $this->request = $registry->get('request');
        $this->session = $registry->get('session');
        $this->config = $registry->get('config');
        $this->currency = $registry->get('currency');
        $this->load->model('localisation/zone');
        $this->registry = $registry;
        $this->load->model('catalog/product');
        $this->load->model('setting/extension');
        $this->load->model('checkout/order');
        $this->model_localisation_zone = $registry->get('model_localisation_zone');
        $this->model_checkout_order = $registry->get('model_checkout_order');
        $this->model_catalog_product = $registry->get('model_catalog_product');
        $this->model_setting_extension = $registry->get('model_setting_extension');
    }

    public function createOrder($purchase)
    {
        $products = $purchase['purchase']['products'];
        $this->load->model('catalog/product');
        if ($purchase) {
            $order_data = array();
            // Store Details
            $order_data = $this->_setStoreDetails($order_data);
            // Customer Details
            $order_data = $this->_setCustomerDetails($order_data, $purchase);
            // Payment Details
            $order_data = $this->_setPaymentDetails($order_data, $purchase);

            // Shipping Details
            $order_data = $this->_setShippingDetails($order_data, $purchase);

            $order_data = $this->_setProductsData($order_data, $products, $purchase);

            // Gift Voucher
            $order_data['vouchers'] = array();

            // Order Totals
            $totals = array();
            $taxes = $this->cart->getTaxes();
            $total = 0;

            // Because __call can not keep var references so we put them into an array.
            $total_data = array(
                'totals' => &$totals,
                'taxes' => &$taxes,
                'total' => &$total,
            );

            $sort_order = array();
            $results = array();
            $results = $this->model_setting_extension->getExtensions('total');
            $ext = [];
            foreach ($results as $key => $result) {
                if ($this->config->get('total_' . $result['code'] . '_status')) {
                    $this->load->model('extension/total/' . $result['code']);

                    $ext['model_extension_total_' . $result['code']] = $this->registry->get('model_extension_total_' . $result['code']);
                    // We have to put the totals in an array so that they pass by reference.
                    $ext['model_extension_total_' . $result['code']]->getTotal($total_data);
                }
            }

            array_multisort($sort_order, SORT_ASC, $results);

            $sort_order = array();

            foreach ($total_data['totals'] as $key => $value) {
                $sort_order[$key] = $value['sort_order'];
            }

            array_multisort($sort_order, SORT_ASC, $total_data['totals']);

            $order_data = array_merge($order_data, $total_data);

            if (isset($this->request->post['comment'])) {
                $order_data['comment'] = $this->request->post['comment'];
            } else {
                $order_data['comment'] = '';
            }

            $order_data = $this->_setGeneralOrderInfo($order_data);

            $this->load->model('checkout/order');
            $order_id = $this->model_checkout_order->addOrder($order_data);

            // Set the order history
            if (isset($this->request->post['order_status_id'])) {
                $order_status_id = $this->request->post['order_status_id'];
            } else {
                $order_status_id = $this->config->get('config_order_status_id');
            }

            $this->model_checkout_order->addOrderHistory($order_id, $order_status_id);

            // clear cart since the order has already been successfully stored.
            $this->cart->clear();

            return $order_id;
        }
    }

    /**
     * The order "name" attribute in the response contains both product_id & product name values
     * Product ID is separated by comma (",")
     *
     * @param $name // name
     *
     * @return mixed
     */
    private function _get_product_id_from_response($name)
    {
        $result = explode(",", $name);

        return $result[0];
    }

    private function _setShippingDetails($order_data, $purchase)
    {
        $zone = $this->model_localisation_zone->getZone($this->config->get('config_zone_id'));
        $order_data['shipping_firstname'] = $purchase['client']["full_name"];
        $order_data['shipping_lastname'] = '';
        $order_data['shipping_company'] = $purchase['client']['brand_name'];
        $order_data['shipping_address_1'] = $purchase['client']['shipping_street_address'];
        $order_data['shipping_address_2'] = '';
        $order_data['shipping_city'] = $purchase['client']['shipping_city'];;
        $order_data['shipping_postcode'] = $purchase['client']['shipping_zip_code'];;
        $order_data['shipping_zone'] = $zone['name'];
        $order_data['shipping_zone_id'] = $zone['zone_id'];
        $order_data['shipping_country'] = $purchase['client']['shipping_country'];
        $order_data['shipping_country_id'] = $this->config->get('config_country_id');
        $order_data['shipping_address_format'] = '';
        $order_data['shipping_custom_field'] = array();
        $order_data['shipping_method'] = '';
        $order_data['shipping_code'] = '';

        return $order_data;
    }

    private function _setProductsData($order_data, $products, $purchase)
    {
        $option_data = array();
        $order_data['products'] = array();
        $pProducts = $purchase['purchase']['products'];
        foreach ($products as $orderProduct) {
            $prodId = $this->_get_product_id_from_response($orderProduct['name']);
            $product = $this->model_catalog_product->getProduct($prodId);
            $option_data = array();
            $productOption = $this->model_catalog_product->getProductOptions($prodId);
            $serachKey = $product['product_id'] . "," . $product['name'];
            foreach ($productOption as $option) {
                $option_data[] = array(
                    'product_option_id' => $option['product_option_id'],
                    'product_option_value_id' => $option['product_option_value_id'],
                    'option_id' => $option['option_id'],
                    'option_value_id' => $option['option_value_id'],
                    'name' => $option['name'],
                    'value' => $option['value'],
                    'type' => $option['type'],
                );
            }
            $key = array_search($serachKey, array_column($pProducts, 'name'));
            $currency = "USD";
            if (array_key_exists('currency', $this->session->data)) {
                $currency = $this->session->data['currency'];
            }
            $order_data['products'][] = array(
                'product_id' => $product['product_id'],
                'name' => $product['name'],
                'model' => $product['model'],
                'option' => $option_data,
                // 'download'   => $product['download'],
                'quantity' => $pProducts[$key]['quantity'],
                'subtract' => $product['subtract'],
                'price' => $product['price'],
                'total' => $this->currency->format(
                    $product['price'],
                    $currency,
                    $this->currency->getValue($this->session->data['currency']),
                    false
                ),
                'tax' => $this->tax->getTax($product['price'], $product['tax_class_id']),
                'reward' => $product['reward'],
            );
        }

        return $order_data;
    }

    private function _setPaymentDetails($order_data, $purchase)
    {
        $zone = $this->model_localisation_zone->getZone($this->config->get('config_zone_id'));
        $order_data['payment_firstname'] = $purchase['client']["full_name"];
        $order_data['payment_lastname'] = "";
        $order_data['payment_company'] = $purchase['client']['brand_name'];
        $order_data['payment_address_1'] = $purchase['client']['shipping_street_address'];
        $order_data['payment_address_2'] = "";
        $order_data['payment_city'] = $purchase['client']['shipping_city'];
        $order_data['payment_postcode'] = $purchase['client']['shipping_zip_code'];
        $order_data['payment_zone'] = $zone['name'];
        $order_data['payment_zone_id'] = $zone['zone_id'];
        $order_data['payment_country'] = $purchase['client']['shipping_country'];
        $order_data['payment_country_id'] = $this->config->get('config_country_id');
        $order_data['payment_address_format'] = "";
        $order_data['payment_custom_field'] =
            (isset($this->session->data['payment_address']['custom_field']) ? $this->session->data['payment_address']['custom_field'] : array());
        $order_data['payment_method'] = $this->config->get('payment_spell_payment_method_desc') ?: 'Klix E-commerce Gateway';;
        $payment_id = $this->session->data['spell_payment_id'];
        
        $purchases_payment_method = $purchases['transaction_data']['payment_method'];
        if($purchases_payment_method === 'klix'){
            $order_data['payment_code'] = "spell_payment_".$payment_id;
        }else{
            $order_data['payment_code'] = "spell_multilink_payment_".$payment_id;
        }
        return $order_data;
    }

    private function _setCustomerDetails($order_data, $purchase)
    {
        $order_data['customer_id'] = 0;
        $order_data['customer_group_id'] = 1;
        $order_data['firstname'] = $purchase['client']["full_name"];
        $order_data['lastname'] = "";
        $order_data['email'] = $purchase['client']["email"];
        $order_data['telephone'] = $purchase['client']['phone'];

        return $order_data;
    }

    private function _setStoreDetails($order_data)
    {
        $order_data['invoice_prefix'] = $this->config->get('config_invoice_prefix');
        $order_data['store_id'] = $this->config->get('config_store_id');
        $order_data['store_name'] = $this->config->get('config_name');
        $order_data['store_url'] = $this->config->get('config_url');

        return $order_data;
    }

    private function _setGeneralOrderInfo($order_data)
    {
        $order_data['affiliate_id'] = 0;
        $order_data['commission'] = 0;
        $order_data['marketing_id'] = 0;
        $order_data['tracking'] = '';

        $order_data['language_id'] = $this->config->get('config_language_id');
        $order_data['currency_id'] = $this->currency->getId($this->session->data['currency']);
        $order_data['currency_code'] = $this->session->data['currency'];
        $order_data['currency_value'] = $this->currency->getValue($this->session->data['currency']);
        $order_data['ip'] = $this->request->server['REMOTE_ADDR'];

        if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
            $order_data['forwarded_ip'] = $this->request->server['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($this->request->server['HTTP_CLIENT_IP'])) {
            $order_data['forwarded_ip'] = $this->request->server['HTTP_CLIENT_IP'];
        } else {
            $order_data['forwarded_ip'] = '';
        }

        if (isset($this->request->server['HTTP_USER_AGENT'])) {
            $order_data['user_agent'] = $this->request->server['HTTP_USER_AGENT'];
        } else {
            $order_data['user_agent'] = '';
        }

        if (isset($this->request->server['HTTP_ACCEPT_LANGUAGE'])) {
            $order_data['accept_language'] = $this->request->server['HTTP_ACCEPT_LANGUAGE'];
        } else {
            $order_data['accept_language'] = '';
        }

        return $order_data;
    }
}

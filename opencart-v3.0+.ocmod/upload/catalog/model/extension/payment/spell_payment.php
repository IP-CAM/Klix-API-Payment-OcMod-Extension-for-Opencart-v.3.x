<?php

require_once realpath(dirname(__FILE__)) . '/spell/api.php';
require_once __DIR__ . '/../../../controller/extension/payment/spell/helper/LanguageHelper.php';
require_once __DIR__ . '/../../../controller/extension/payment/spell/controller/CheckoutController.php';

/**
 * @property Config config
 * @property Log log
 * @property Loader load
 * @property ModelLocalisationCurrency currency
 * @property Url url
 */
class ModelExtensionPaymentSpellPayment extends Model
{

    const SPELL_MODULE_VERSION = 'v1.1.2';

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
     * called internally by OpenCart when customer opens "Step 5: Payment Method"
     */
    public function getMethod($address, $total)
    {
        $this->load->model('localisation/currency');

        if (!$this->config->get('payment_spell_payment_status')) {
            return;
        }
        $title = $this->config->get('payment_spell_payment_method_desc')
            ?: 'Klix E-commerce Gateway';

        $method_data = array(
            'code'       => 'spell_payment',
            'terms'      => '',
            'title'      => $title,
            'sort_order' => null,
        );

        return $method_data;
    }

    private function makePaymentParams($urlParams)
    {
        $this->load->model('localisation/currency');
        $this->load->model('setting/setting');
        $this->registry->set('languageHelper', new LanguageHelper($this->registry));
        $currency_code = $this->session->data['currency'];
        $order = $urlParams['order_info'];
        $notes = $this->getNotes();
        $total = $this->currency->format(
            $order['total'],
            $order['currency_code'],
            $order['currency_value'],
            false
        );
        $total = (int)(string)($total * 100);

        return [
            'success_callback' => $this->url->link('extension/payment/spell_payment/callback&id=' . $order['order_id'], '', true),
            'success_redirect' => $this->url->link('extension/payment/spell_payment/success', '', true),
            'failure_redirect' => $this->url->link('extension/payment/spell_payment/error', '', true),
            'cancel_redirect' => $this->url->link('extension/payment/spell_payment/error', '', true),
            'creator_agent' => 'OpenCart module: ' . self::SPELL_MODULE_VERSION,
            'reference' => (string) $order['order_id'],
            'platform' => 'opencart',
            'purchase' => [
                "currency" => $currency_code,
                "language" => $this->languageHelper->get_language(),
                "notes" => $notes,
                "products" => [
                    [
                        'name' => 'Payment',
                        'price' => $total,
                        'quantity' => 1,
                    ],
                ]
            ],
            'brand_id' => $this->getBrandId(),
            'client' => [
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
            ],
        ];
    }

    private function getNotes()
    {
        $cart_products = $this->cart->getProducts();
        $nameString = '';
        if (!empty($cart_products)) {
            foreach ($cart_products as $key => $cart_product) {
                $name=$cart_product['name'].' x '.$cart_product['quantity'];

                if ($key == 0) {
                    $nameString = $name;
                } else {
                    $nameString = $nameString . '; ' . $name;
                }
            }
        }
        return $nameString;
    }

    /**
     * @param $urlParams = ControllerExtensionPaymentSpellPayment::collectUrlParams()
     *
     */
    public function createPayment($urlParams)
    {
        $spell = $this->getSpell();
        $paymentParams = $this->makePaymentParams($urlParams);
        $payment = $spell->create_payment($paymentParams);
        if (!array_key_exists('id', $payment)) {
            return [
                'id' => false
            ];
        }

        $checkout_url = $payment['checkout_url'];
        $this->logger = new DefaultLogger($this->log);
        $this->logger->log("INFO: " . print_r($paymentParams, true) . ";");
        $this->logger->log("INFO: " . print_r($payment, true) . ";");
        if (isset($urlParams['payment_method'])) {
            $checkout_url .= '?preferred=' . $urlParams['payment_method'];
        }

        return [
            'id' => $payment['id'],
            'checkout_url' => $checkout_url,
        ];
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
        $this->registry->set(
            'CheckoutController',
            new CheckoutController($this->registry)
        );
        return $this->CheckoutController->createOneClickPayment($urlParams);
    }
}

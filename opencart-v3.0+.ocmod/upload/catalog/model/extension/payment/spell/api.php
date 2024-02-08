<?php

define('SPELL_MODULE_VERSION', 'v1.1.7');
define("ROOT_URL", "https://portal.klix.app");

class SpellAPI
{
    private $private_key;
    private $brand_id;
    private $logger;
    private $debug;

    public function __construct($private_key, $brand_id, $logger, $debug)
    {
        $this->private_key = $private_key;
        $this->brand_id = $brand_id;
        $this->logger = $logger;
        $this->debug = $debug;
    }

    /**
     * @param $params = [
     *     'success_redirect' => $u . "&action=paid",
     *     'failure_redirect' => $u . "&action=cancel",
     *     'creator_agent' => 'Woocommerce v3 module: '
     *         . SPELL_MODULE_VERSION,
     *     'reference' => (string) $o_id,
     *     'purchase' => [
     *         "currency" => $o->get_currency(),
     *         "products" => [
     *             [
     *                 'name' => 'Payment',
     *                 'price' => round($o->calculate_totals() * 100),
     *                 'quantity' => 1,
     *             ],
     *         ],
     *     ],
     *     'brand_id' => $this->settings['brand-id'],
     *     'client' => [
     *         'email' => $o->get_billing_email(),
     *         'phone' => $o->get_billing_phone(),
     *         'full_name' => $o->get_billing_first_name() . ' '
     *             . $o->get_billing_last_name(),
     *         'street_address' => $o->get_billing_address_1() . ' '
     *             . $o->get_billing_address_2(),
     *         'country' => $o->get_billing_country(),
     *         'city' => $o->get_billing_city(),
     *         'zip_code' => $o->get_shipping_postcode(),
     *         'shipping_street_address' => $o->get_shipping_address_1()
     *             . ' ' . $o->get_shipping_address_2(),
     *         'shipping_country' => $o->get_shipping_country(),
     *         'shipping_city' => $o->get_shipping_city(),
     *         'shipping_zip_code' => $o->get_shipping_postcode(),
     *     ],
     * ]
     * @return array [
     *     'id' => (int),
     *     'checkout_url' => (string),
     * ]
     */
    public function create_payment($params)
    {
        $this->log_info("loading payment form");
        return $this->call('POST', '/purchases/', $params);
    }

    public function payment_methods($currency, $language)
    {
        $this->log_info("fetching payment methods");
        return $this->call(
            'GET',
            "/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}"
        );
    }

    public function purchases($payment_id)
    {
        return $this->call('GET', "/purchases/{$payment_id}/");
    }

    public function was_payment_successful($payment_id)
    {
        $this->log_info(sprintf("validating payment: %s", $payment_id));
        $result = $this->purchases($payment_id);
        $this->log_info(sprintf(
            "success check result: %s",
            var_export($result, true)
        ));
        return $result && $result['status'] == 'paid';
    }

    private function call($method, $route, $params = [])
    {
        $private_key = $this->private_key;
        if (!empty($params)) {
            $params = json_encode($params);
        }

        $response = $this->request(
            $method,
            sprintf("%s/api/v1%s", ROOT_URL, $route),
            $params,
            [
                'Content-type: application/json',
                'Authorization: ' . "Bearer " . $private_key,
            ]
        );
        $this->log_info(sprintf('received response: %s', $response));
        $result = json_decode($response, true);
        if (!$result) {
            $this->log_error('JSON parsing error/NULL API response');
            return null;
        }

        if (!empty($result['errors'])) {
            $this->log_error('API error', $result['errors']);
            return null;
        }

        return $result;
    }

    private function request($method, $url, $params = [], $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
        }
        if ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_PUT, 1);
        }
        if ($method == 'PUT' or $method == 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        // curl_setopt($conn, CURLOPT_FAILONERROR, false);
        // curl_setopt($conn, CURLOPT_HTTP200ALIASES, (array) 400);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $this->log_info(sprintf(
            "%s `%s`\n%s\n%s",
            $method,
            $url,
            var_export($params, true),
            var_export($headers, true)
        ));
        $response = curl_exec($ch);
        switch ($code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
            case 200:
            case 201:
                break;
            default:
                $this->log_error(
                    sprintf("%s %s: %d", $method, $url, $code),
                    $response
                );
        }
        if (!$response) {
            $this->log_error('curl', curl_error($ch));
        }

        curl_close($ch);

        return $response;
    }

    public function log_info($text, $error_data = null)
    {
        if ($this->debug) {
            $this->logger->log("INFO: " . $text . ";");
        }
    }

    public function log_error($error_text, $error_data = null)
    {
        if (!$this->debug) {
            return;
        }
        $error_text = "ERROR: " . $error_text . ";";
        if ($error_data) {
            $error_text .= " ERROR DATA: " . var_export($error_data, true) . ";";
        }
        $this->logger->log($error_text);
    }
}

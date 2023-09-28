<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once realpath('') . '/model/extension/payment/spell/api.php';

require_once realpath('') . '/model/extension/payment/spell/DefaultLogger.php';

class SpellRefundHelper{

    private $language;
    private $load;
    private $session;
    private $db;
    private $cache;
    private $config;
    private $log;
    private $model_localisation_order_status;
    private $model_setting_setting;
    private $model_setting_store;
    private $model_customer_customer;
    private $model_sale_order;

    public function __construct($registry)
    {
        $this->language = $registry->get('language');
        $this->load = $registry->get('load');
        $this->db = $registry->get('db');
        $this->cache = $registry->get('cache');
        $this->session = $registry->get('session');
        $this->config = $registry->get('config');
        $this->model_localisation_order_status = $registry->get('model_localisation_order_status');
        $this->model_setting_setting = $registry->get('model_setting_setting');
        $this->model_customer_customer = $registry->get('model_customer_customer');
        $this->model_sale_order = $registry->get('model_sale_order');
        $this->model_setting_store = $registry->get('model_setting_store');
        // $this->model_checkout_order = $registry->get('model_checkout_order');
        $this->log = $registry->get('log');
        $this->logger = new DefaultLogger($this->log);

    }

    public function init()
    {
        // Load models
        $this->load->language('extension/payment/spell_payment');
        $this->load->model('setting/setting');
        $this->load->model('setting/store');
        $this->load->model('sale/order');
        // $this->load->model('checkout/order');
		$this->load->model('localisation/order_status');
    }

    public function process_refund($order_info, $amount = null, $reason = 'Refund payment')
    {

        if (!$this->can_refund_order($order_info, $amount)) {
             $this->log_order_info('Cannot refund order', $order_info);
            return null;
        }

        $spell = $this->getSpell();

        $params = [
            'amount' => round($amount * 100),
        ];

        $payment_id = str_replace("spell_payment_","",$order_info['payment_code']);
        $result = $spell->refund_payment($payment_id, $params);

        if (isset($result['__all__'])) {
            $order_status = $this->getOrderStatusForRefund();
            $this->addOrderHistory($order_info, $order_status['order_status_id'], $reason);
            $this->log_order_info('already refunded: ' , $order_info);
            return null;
        }

         $this->log_order_info('Refund Result: ' . print_r($result, true), $order_info);

        switch (strtolower($result['status'])) {
            case 'success':
                $refund_amount = round($result['payment']['amount'] / 100, 2) . $result['payment']['currency'];
                $order_status = $this->getOrderStatusForRefund();
                $this->addOrderHistory($order_info, $order_status['order_status_id'], $reason);
                break;
            default:
                 $this->log_order_info('Refund result status is missing: ' . print_r($result, true), $order_info);
                break;
        }

        return true;
    }

    public function addOrderHistory($order_info, $order_status_id, $comment = '', $notify = false, $override = false) {
        $order_id = $order_info['order_id'];
		if ($order_info) {
            // Stock subtraction
            $order_products = $this->getOrderProducts($order_id);

            foreach ($order_products as $order_product) {
                $this->db->query("UPDATE " . DB_PREFIX . "product SET quantity = (quantity - " . (int)$order_product['quantity'] . ") WHERE product_id = '" . (int)$order_product['product_id'] . "' AND subtract = '1'");

                $order_options = $this->getOrderOptions($order_id, $order_product['order_product_id']);

                foreach ($order_options as $order_option) {
                    $this->db->query("UPDATE " . DB_PREFIX . "product_option_value SET quantity = (quantity - " . (int)$order_product['quantity'] . ") WHERE product_option_value_id = '" . (int)$order_option['product_option_value_id'] . "' AND subtract = '1'");
                }
            }
            
            // Add commission if sale is linked to affiliate referral.
            if ($order_info['affiliate_id'] && $this->config->get('config_affiliate_auto')) {
                $this->load->model('customer/customer');

                if (!$this->model_customer_customer->getTotalTransactionsByOrderId($order_id)) {
                    $this->model_customer_customer->addTransaction($order_info['affiliate_id'], $this->language->get('text_order_id') . ' #' . $order_id, $order_info['commission'], $order_id);
                }
            }

			// Update the DB with the new statuses
			$this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = '" . (int)$order_status_id . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");

			$this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = '" . (int)$order_id . "', order_status_id = '" . (int)$order_status_id . "', notify = '" . (int)$notify . "', comment = '" . $this->db->escape($comment) . "', date_added = NOW()");

			// If old order status is the processing or complete status but new status is not then commence restock, and remove coupon, voucher and reward history
			if (in_array($order_info['order_status_id'], array_merge($this->config->get('config_processing_status'), $this->config->get('config_complete_status'))) && !in_array($order_status_id, array_merge($this->config->get('config_processing_status'), $this->config->get('config_complete_status')))) {
				// Restock
				$order_products = $this->getOrderProducts($order_id);

				foreach($order_products as $order_product) {
					$this->db->query("UPDATE `" . DB_PREFIX . "product` SET quantity = (quantity + " . (int)$order_product['quantity'] . ") WHERE product_id = '" . (int)$order_product['product_id'] . "' AND subtract = '1'");

					$order_options = $this->getOrderOptions($order_id, $order_product['order_product_id']);

					foreach ($order_options as $order_option) {
						$this->db->query("UPDATE " . DB_PREFIX . "product_option_value SET quantity = (quantity + " . (int)$order_product['quantity'] . ") WHERE product_option_value_id = '" . (int)$order_option['product_option_value_id'] . "' AND subtract = '1'");
					}
				}

				// Remove commission if sale is linked to affiliate referral.
				if ($order_info['affiliate_id']) {
					$this->load->model('account/customer');
					
					$this->model_account_customer->deleteTransactionByOrderId($order_id);
				}
			}

			$this->cache->delete('product');
		}
	}

	public function getOrderTotals($order_id) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_total` WHERE order_id = '" . (int)$order_id . "' ORDER BY sort_order ASC");
		
		return $query->rows;
	}

    public function getOrderProducts($order_id) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = '" . (int)$order_id . "'");
		
		return $query->rows;
	}

    public function getOrderStatusForRefund($string = 'refund')
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_status WHERE language_id = '" . (int)$this->config->get('config_language_id') . "' AND name LIKE '%$string%'");

		return $query->row;
    }

    public function getOrderOptions($order_id, $order_product_id) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_option WHERE order_id = '" . (int)$order_id . "' AND order_product_id = '" . (int)$order_product_id . "'");
		
		return $query->rows;
	}

    public function can_refund_order($order, $amount)
    {
        $secret_code = $this->config->get('payment_spell_payment_secret_code');
        $brand_id = $this->getBrandId();
        $enable = $this->config->get('payment_spell_payment_enabled');
        $has_api_creds = $enable && $secret_code && $brand_id; 
        $payment_id = str_replace("spell_payment_","",$order['payment_code']);
        return  $has_api_creds && $order && $payment_id && ($amount > 0);
    }

    private function log_order_info($msg, $o)
    {
        $debug = $this->config->get('payment_spell_payment_debug') === 'on' ? true : false;
        if($debug) {
            $this->logger->log("order_info: ".$msg . ': ' . print_r($o));
        }
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
}
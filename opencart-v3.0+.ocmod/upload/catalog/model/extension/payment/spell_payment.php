<?php

require_once realpath(dirname(__FILE__)) . '/spell/api.php';
require_once __DIR__ . '/../../../controller/extension/payment/spell/helper/LanguageHelper.php';
require_once __DIR__ . '/../../../controller/extension/payment/spell/helper/Helper.php';
require_once __DIR__ . '/../../../controller/extension/payment/spell/helper/CheckoutHelper.php';

/**
 * @property Config spell_payment
 * @property Log log
 * @property Loader load
 * @property ModelLocalisationCurrency currency
 * @property Url url
 */
class ModelExtensionPaymentSpellPayment extends Model
{

    const SPELL_MODULE_VERSION = 'v1.1.6';


    public function getSpell()
    {
        $this->registry->set(
            'helpers',
            new Helpers($this->registry)
        );
        $brand_id = $this->helpers->getBrandId();
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

    /** 
     * @param $urlParams = ControllerExtensionPaymentSpellPayment::collectUrlParams() 
     * 
     */
    public function createPayment($urlParams, $isOneClick = false)
    {
        $this->registry->set(
            'CheckoutHelper',
            new CheckoutHelper($this->registry)
        );
        return $this->CheckoutHelper->createPayment($urlParams, $isOneClick);
    }
}

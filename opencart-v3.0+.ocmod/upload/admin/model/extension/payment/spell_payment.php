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


}

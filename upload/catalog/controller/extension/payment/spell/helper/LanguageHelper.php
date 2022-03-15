<?php

class LanguageHelper
{

    private $language;
    private $load;

    public function __construct($registry)
    {
        $this->language = $registry->get('language');
        $this->load = $registry->get('load');
    }

    public function get_language()
    {
        $ln = $this->language->get('code');
        switch ($ln) {
            case 'et':
                $ln = 'et';
                break;
            case 'ru':
                $ln = 'ru';
                break;
            case 'lt':
                $ln = 'lt';
                break;
            case 'lv':
                $ln = 'lv';
                break;
            default:
                $ln = 'en';
        }

        return $ln;
    }

    /**
     * @return string
     */
    public function get_button_image_url()
    {
        $ln = $this->language->get('code');
        switch ($ln) {
            case 'lv_LV':
            case 'lv':
                $image_url = 'https://developers.klix.app/images/logos/quick-checkout-lv.gif';
                break;
            case 'lt_LT':
            case 'lt':
                $image_url = 'https://developers.klix.app/images/logos/quick-checkout-lt.gif';
                break;
            case 'et_EE':
            case 'et':
                $image_url = 'https://developers.klix.app/images/logos/quick-checkout-ee.gif';
                break;
            case 'ru_RU':
            case 'ru':
                $image_url = 'https://developers.klix.app/images/logos/quick-checkout-ru.gif';
                break;
            default:
                $image_url = 'https://developers.klix.app/images/logos/quick-checkout-en.gif';
                break;
        }

        return $image_url;
    }
}

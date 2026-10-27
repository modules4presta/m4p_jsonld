<?php

declare(strict_types=1);

/**
 * m4pmerchantpolicy
 *
 * @author    Modules4Presta <contact@modules4presta.io>
 * @copyright 2026 Nice Code sp. z o.o. (Modules4Presta)
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Return and shipping policy of the shop as schema.org data for Google: organization-level
 * MerchantReturnPolicy and ShippingService, offer-level OfferShippingDetails and MerchantReturnPolicy.
 * The theme merges the arrays into its own JSON-LD (getOrganizationData(), getOfferData()).
 */
class M4pMerchantPolicy extends Module
{
    public const CONFIG_ENABLED = 'M4PMERCHANTPOLICY_ENABLED';
    public const CONFIG_RETURN_DAYS = 'M4PMERCHANTPOLICY_RETURN_DAYS';
    public const CONFIG_RETURN_FEE = 'M4PMERCHANTPOLICY_RETURN_FEE';
    public const CONFIG_RETURN_METHOD = 'M4PMERCHANTPOLICY_RETURN_METHOD';
    public const CONFIG_RETURN_COUNTRIES = 'M4PMERCHANTPOLICY_RETURN_COUNTRIES';
    public const CONFIG_RETURN_CMS = 'M4PMERCHANTPOLICY_RETURN_CMS';
    public const CONFIG_SHIP_COUNTRY = 'M4PMERCHANTPOLICY_SHIP_COUNTRY';
    public const CONFIG_SHIP_COST_MIN = 'M4PMERCHANTPOLICY_SHIP_COST_MIN';
    public const CONFIG_SHIP_COST_MAX = 'M4PMERCHANTPOLICY_SHIP_COST_MAX';
    public const CONFIG_HANDLING_MIN = 'M4PMERCHANTPOLICY_HANDLING_MIN';
    public const CONFIG_HANDLING_MAX = 'M4PMERCHANTPOLICY_HANDLING_MAX';
    public const CONFIG_TRANSIT_MIN = 'M4PMERCHANTPOLICY_TRANSIT_MIN';
    public const CONFIG_TRANSIT_MAX = 'M4PMERCHANTPOLICY_TRANSIT_MAX';
    public const CONFIG_FREE_FROM = 'M4PMERCHANTPOLICY_FREE_FROM';
    public const CONFIG_EU_CARRIER = 'M4PMERCHANTPOLICY_EU_CARRIER';
    public const CONFIG_EU_TRANSIT_MIN = 'M4PMERCHANTPOLICY_EU_TRANSIT_MIN';
    public const CONFIG_EU_TRANSIT_MAX = 'M4PMERCHANTPOLICY_EU_TRANSIT_MAX';

    public const METHOD_BY_MAIL = 'ReturnByMail';
    public const METHOD_IN_STORE = 'ReturnInStore';

    private const SCHEMA = 'https://schema.org/';

    private const INT_FIELDS = [
        self::CONFIG_RETURN_DAYS,
        self::CONFIG_HANDLING_MIN,
        self::CONFIG_HANDLING_MAX,
        self::CONFIG_TRANSIT_MIN,
        self::CONFIG_TRANSIT_MAX,
        self::CONFIG_EU_TRANSIT_MIN,
        self::CONFIG_EU_TRANSIT_MAX,
    ];

    private const AMOUNT_FIELDS = [
        self::CONFIG_RETURN_FEE,
        self::CONFIG_SHIP_COST_MIN,
        self::CONFIG_SHIP_COST_MAX,
    ];

    public function __construct()
    {
        $this->name = 'm4pmerchantpolicy';
        $this->tab = 'seo';
        $this->version = '1.0.2';
        $this->author = 'Modules4Presta';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('Return and shipping policy for Google', [], 'Modules.M4pmerchantpolicy.Admin');
        $this->description = $this->trans('Return and shipping conditions of the shop as structured data in the Organization and Offer markup.', [], 'Modules.M4pmerchantpolicy.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        foreach ($this->getDefaults() as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        return parent::install();
    }

    public function uninstall(): bool
    {
        foreach (array_keys($this->getDefaults()) as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /**
     * Properties to merge into the Organization node: hasMerchantReturnPolicy and hasShippingService.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getOrganizationData(): array
    {
        if (!Configuration::get(self::CONFIG_ENABLED)) {
            return [];
        }

        $currency = Currency::getDefaultCurrency();
        $country = $this->getShipCountryIso();
        $transit = $this->servicePeriod(
            (int) Configuration::get(self::CONFIG_TRANSIT_MIN),
            (int) Configuration::get(self::CONFIG_TRANSIT_MAX)
        );
        $destination = ['@type' => 'DefinedRegion', 'addressCountry' => $country];

        $conditions = [[
            '@type' => 'ShippingConditions',
            'shippingDestination' => $destination,
            'shippingRate' => $this->rate(
                (float) Configuration::get(self::CONFIG_SHIP_COST_MIN),
                (float) Configuration::get(self::CONFIG_SHIP_COST_MAX),
                $currency->iso_code
            ),
            'transitTime' => $transit,
        ]];

        $freeFrom = $this->getFreeShippingThreshold();
        if ($freeFrom > 0 && $this->isFreeShippingCountry((int) Configuration::get(self::CONFIG_SHIP_COUNTRY))) {
            $conditions[] = [
                '@type' => 'ShippingConditions',
                'shippingDestination' => $destination,
                'orderValue' => ['@type' => 'MonetaryAmount', 'minValue' => $freeFrom, 'currency' => $currency->iso_code],
                'shippingRate' => $this->rate(0.0, 0.0, $currency->iso_code),
                'transitTime' => $transit,
            ];
        }

        return [
            'hasMerchantReturnPolicy' => $this->getReturnPolicy(),
            'hasShippingService' => [
                '@type' => 'ShippingService',
                '@id' => $this->nodeId('shipping-service'),
                'handlingTime' => $this->servicePeriod(
                    (int) Configuration::get(self::CONFIG_HANDLING_MIN),
                    (int) Configuration::get(self::CONFIG_HANDLING_MAX)
                ),
                'shippingConditions' => $conditions,
            ],
        ];
    }

    /**
     * Properties to merge into a product's Offer: shippingDetails for the shipping country (free when the
     * product's price reaches the threshold) and for each country of the EU carrier, plus hasMerchantReturnPolicy.
     * Rates are gross, in the context currency (Google requires the Offer's currency).
     *
     * @return array<string, mixed>
     */
    public function getOfferData(int $idProduct, int $idProductAttribute = 0): array
    {
        if (!Configuration::get(self::CONFIG_ENABLED)) {
            return [];
        }

        $data = ['hasMerchantReturnPolicy' => $this->getReturnPolicy()];
        $product = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
            'SELECT p.`is_virtual`, p.`weight` + IFNULL(pa.`weight`, 0) AS `weight`, p.`additional_shipping_cost`
            FROM `' . _DB_PREFIX_ . 'product` p
            LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON pa.`id_product_attribute` = ' . $idProductAttribute . '
                AND pa.`id_product` = p.`id_product`
            WHERE p.`id_product` = ' . $idProduct
        );
        if (!$product || $product['is_virtual']) {
            return $data;
        }

        $currency = $this->context->currency;
        $price = (float) Product::getPriceStatic($idProduct, true, $idProductAttribute ?: null);
        $freeFrom = (float) Tools::convertPrice($this->getFreeShippingThreshold(), $currency);
        $reachesThreshold = $freeFrom > 0 && $price >= $freeFrom;
        $handling = [
            (int) Configuration::get(self::CONFIG_HANDLING_MIN),
            (int) Configuration::get(self::CONFIG_HANDLING_MAX),
        ];

        $idCountry = (int) Configuration::get(self::CONFIG_SHIP_COUNTRY);
        $details = [];
        if ($reachesThreshold && $this->isFreeShippingCountry($idCountry)) {
            $rate = $this->rate(0.0, 0.0, $currency->iso_code);
        } else {
            $rate = $this->rate(
                (float) Tools::convertPrice((float) Configuration::get(self::CONFIG_SHIP_COST_MIN), $currency),
                (float) Tools::convertPrice((float) Configuration::get(self::CONFIG_SHIP_COST_MAX), $currency),
                $currency->iso_code
            );
        }
        $details[] = $this->offerShippingDetails($this->getShipCountryIso(), $rate, $handling, [
            (int) Configuration::get(self::CONFIG_TRANSIT_MIN),
            (int) Configuration::get(self::CONFIG_TRANSIT_MAX),
        ]);

        $euTransit = [
            (int) Configuration::get(self::CONFIG_EU_TRANSIT_MIN),
            (int) Configuration::get(self::CONFIG_EU_TRANSIT_MAX),
        ];
        foreach ($this->getEuRates($idProduct, (float) $product['weight'], (float) $product['additional_shipping_cost']) as $iso => $row) {
            $amount = $reachesThreshold && $this->isFreeShippingCountry($row['id_country'])
                ? 0.0
                : (float) Tools::convertPrice($row['gross'], $currency);
            $details[] = $this->offerShippingDetails($iso, $this->rate($amount, $amount, $currency->iso_code), $handling, $euTransit);
        }

        $data['shippingDetails'] = count($details) === 1 ? $details[0] : $details;

        return $data;
    }

    /**
     * Free shipping threshold (gross, default currency): the module's own value, otherwise
     * m4p_freeshipping's threshold for customers outside the B2B groups, otherwise the core setting.
     */
    public function getFreeShippingThreshold(): float
    {
        $own = (string) Configuration::get(self::CONFIG_FREE_FROM);
        if ($own !== '') {
            return (float) $own;
        }

        return $this->getFreeShippingModule()
            ? (float) Configuration::get('M4PFREESHIPPING_DEFAULT')
            : (float) Configuration::get('PS_SHIPPING_FREE_PRICE');
    }

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitM4pmerchantpolicy')) {
            [$values, $errors] = $this->readForm();
            if ($errors) {
                $output .= $this->displayError($errors);
            } else {
                foreach ($values as $key => $value) {
                    Configuration::updateValue($key, $value);
                }
                $output .= $this->displayConfirmation($this->trans('Settings updated.', [], 'Modules.M4pmerchantpolicy.Admin'));
            }
        }

        return $output . $this->renderForm();
    }

    /**
     * @return array{0: array<string, string|int>, 1: string[]}
     */
    private function readForm(): array
    {
        $values = [self::CONFIG_ENABLED => (int) (bool) Tools::getValue(self::CONFIG_ENABLED)];
        $errors = [];

        foreach (self::INT_FIELDS as $key) {
            $raw = trim((string) Tools::getValue($key));
            if (!ctype_digit($raw)) {
                $errors[$key] = $this->trans('Days must be whole numbers, 0 or more.', [], 'Modules.M4pmerchantpolicy.Admin');
            }
            $values[$key] = (int) $raw;
        }
        foreach (self::AMOUNT_FIELDS as $key) {
            $amount = $this->parseAmount((string) Tools::getValue($key));
            if ($amount === null) {
                $errors[$key] = $this->trans('Amounts must be numbers, 0 or more.', [], 'Modules.M4pmerchantpolicy.Admin');
            }
            $values[$key] = (string) ($amount ?? 0);
        }

        $freeFrom = trim((string) Tools::getValue(self::CONFIG_FREE_FROM));
        if ($freeFrom !== '') {
            $amount = $this->parseAmount($freeFrom);
            if ($amount === null) {
                $errors[self::CONFIG_FREE_FROM] = $this->trans('Amounts must be numbers, 0 or more.', [], 'Modules.M4pmerchantpolicy.Admin');
            }
            $freeFrom = (string) ($amount ?? '');
        }
        $values[self::CONFIG_FREE_FROM] = $freeFrom;

        if ($values[self::CONFIG_RETURN_DAYS] < 1) {
            $errors[] = $this->trans('The return window must be at least 1 day.', [], 'Modules.M4pmerchantpolicy.Admin');
        }
        if ($values[self::CONFIG_HANDLING_MIN] > $values[self::CONFIG_HANDLING_MAX]
            || $values[self::CONFIG_TRANSIT_MIN] > $values[self::CONFIG_TRANSIT_MAX]
            || $values[self::CONFIG_EU_TRANSIT_MIN] > $values[self::CONFIG_EU_TRANSIT_MAX]
            || (float) $values[self::CONFIG_SHIP_COST_MIN] > (float) $values[self::CONFIG_SHIP_COST_MAX]) {
            $errors[] = $this->trans('A minimum cannot be greater than its maximum.', [], 'Modules.M4pmerchantpolicy.Admin');
        }

        $method = (string) Tools::getValue(self::CONFIG_RETURN_METHOD);
        $values[self::CONFIG_RETURN_METHOD] = in_array($method, [self::METHOD_BY_MAIL, self::METHOD_IN_STORE], true) ? $method : self::METHOD_BY_MAIL;

        $countries = array_values(array_unique(array_filter(array_map('intval', (array) Tools::getValue(self::CONFIG_RETURN_COUNTRIES, [])))));
        if (!$countries) {
            $errors[] = $this->trans('Select at least one country for returns.', [], 'Modules.M4pmerchantpolicy.Admin');
        }
        if (count($countries) > 50) {
            $errors[] = $this->trans('Google accepts at most 50 countries for returns.', [], 'Modules.M4pmerchantpolicy.Admin');
        }
        $values[self::CONFIG_RETURN_COUNTRIES] = (string) json_encode($countries);

        $values[self::CONFIG_RETURN_CMS] = (int) Tools::getValue(self::CONFIG_RETURN_CMS);
        $values[self::CONFIG_EU_CARRIER] = (int) Tools::getValue(self::CONFIG_EU_CARRIER);
        $values[self::CONFIG_SHIP_COUNTRY] = (int) Tools::getValue(self::CONFIG_SHIP_COUNTRY);
        if (!Country::getIsoById($values[self::CONFIG_SHIP_COUNTRY])) {
            $errors[] = $this->trans('Select the shipping country.', [], 'Modules.M4pmerchantpolicy.Admin');
        }

        return [$values, array_values(array_unique($errors))];
    }

    private function parseAmount(string $raw): ?float
    {
        $raw = str_replace([',', ' '], ['.', ''], trim($raw));

        return is_numeric($raw) && (float) $raw >= 0 ? round((float) $raw, 2) : null;
    }

    private function renderForm(): string
    {
        $idLang = (int) $this->context->language->id;
        $sign = Currency::getDefaultCurrency()->sign;
        $days = $this->trans('days', [], 'Modules.M4pmerchantpolicy.Admin');
        $countries = array_map(
            static fn (array $country): array => ['id' => (int) $country['id_country'], 'name' => $country['name']],
            Country::getCountries($idLang, true)
        );
        $cmsPages = [['id' => 0, 'name' => '–']];
        foreach (CMS::getCMSPages($idLang, null, true) as $cms) {
            $cmsPages[] = ['id' => (int) $cms['id_cms'], 'name' => $cms['meta_title']];
        }
        $carriers = [['id' => 0, 'name' => $this->trans('None (domestic shipping only)', [], 'Modules.M4pmerchantpolicy.Admin')]];
        foreach (Carrier::getCarriers($idLang, true, false, false, null, Carrier::ALL_CARRIERS) as $carrier) {
            $carriers[] = ['id' => (int) $carrier['id_reference'], 'name' => $carrier['name']];
        }

        $text = static fn (string $name, string $label, string $suffix, string $desc = ''): array => [
            'type' => 'text',
            'name' => $name,
            'label' => $label,
            'suffix' => $suffix,
            'class' => 'fixed-width-sm',
            'desc' => $desc,
        ];
        $select = static fn (string $name, string $label, array $options, string $desc = '', bool $multiple = false): array => [
            'type' => 'select',
            'name' => $multiple ? $name . '[]' : $name,
            'label' => $label,
            'multiple' => $multiple,
            'class' => $multiple ? 'chosen' : '',
            'options' => ['query' => $options, 'id' => 'id', 'name' => 'name'],
            'desc' => $desc,
        ];
        $submit = ['title' => $this->trans('Save', [], 'Admin.Actions'), 'name' => 'submitM4pmerchantpolicy'];

        $returnsForm = ['form' => [
            'legend' => ['title' => $this->trans('Returns', [], 'Modules.M4pmerchantpolicy.Admin'), 'icon' => 'icon-undo'],
            'input' => [
                [
                    'type' => 'switch',
                    'name' => self::CONFIG_ENABLED,
                    'label' => $this->trans('Add the policies to the structured data', [], 'Modules.M4pmerchantpolicy.Admin'),
                    'is_bool' => true,
                    'values' => [
                        ['id' => 'on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
                        ['id' => 'off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                    ],
                    'desc' => $this->trans('Organization markup on the home page, Offer markup on product pages.', [], 'Modules.M4pmerchantpolicy.Admin'),
                ],
                $text(self::CONFIG_RETURN_DAYS, $this->trans('Return window', [], 'Modules.M4pmerchantpolicy.Admin'), $days, $this->trans('Days from delivery.', [], 'Modules.M4pmerchantpolicy.Admin')),
                $text(self::CONFIG_RETURN_FEE, $this->trans('Return shipping fee (gross)', [], 'Modules.M4pmerchantpolicy.Admin'), $sign, $this->trans('Paid by the customer. 0 means free returns.', [], 'Modules.M4pmerchantpolicy.Admin')),
                $select(self::CONFIG_RETURN_METHOD, $this->trans('Return method', [], 'Modules.M4pmerchantpolicy.Admin'), [
                    ['id' => self::METHOD_BY_MAIL, 'name' => $this->trans('By mail', [], 'Modules.M4pmerchantpolicy.Admin')],
                    ['id' => self::METHOD_IN_STORE, 'name' => $this->trans('In store', [], 'Modules.M4pmerchantpolicy.Admin')],
                ]),
                $select(self::CONFIG_RETURN_COUNTRIES, $this->trans('Countries the return policy applies to', [], 'Modules.M4pmerchantpolicy.Admin'), $countries, $this->trans('At most 50.', [], 'Modules.M4pmerchantpolicy.Admin'), true),
                $select(self::CONFIG_RETURN_CMS, $this->trans('Return policy page', [], 'Modules.M4pmerchantpolicy.Admin'), $cmsPages),
            ],
            'submit' => $submit,
        ]];

        $freeDesc = $this->getFreeShippingModule()
            ? $this->trans('Empty: the threshold of the free delivery module (now %amount%). 0 turns free shipping off in the markup.', ['%amount%' => $this->formatAmount((float) Configuration::get('M4PFREESHIPPING_DEFAULT'))], 'Modules.M4pmerchantpolicy.Admin')
            : $this->trans('Empty: "Free shipping starts at" from Shipping > Preferences. 0 turns free shipping off in the markup.', [], 'Modules.M4pmerchantpolicy.Admin');

        $shippingForm = ['form' => [
            'legend' => ['title' => $this->trans('Shipping', [], 'Modules.M4pmerchantpolicy.Admin'), 'icon' => 'icon-truck'],
            'input' => [
                $select(self::CONFIG_SHIP_COUNTRY, $this->trans('Shipping country', [], 'Modules.M4pmerchantpolicy.Admin'), $countries),
                $text(self::CONFIG_SHIP_COST_MIN, $this->trans('Lowest shipping cost (gross)', [], 'Modules.M4pmerchantpolicy.Admin'), $sign),
                $text(self::CONFIG_SHIP_COST_MAX, $this->trans('Highest shipping cost (gross)', [], 'Modules.M4pmerchantpolicy.Admin'), $sign),
                $text(self::CONFIG_FREE_FROM, $this->trans('Free shipping from (gross)', [], 'Modules.M4pmerchantpolicy.Admin'), $sign, $freeDesc),
                $text(self::CONFIG_HANDLING_MIN, $this->trans('Handling time from', [], 'Modules.M4pmerchantpolicy.Admin'), $days, $this->trans('Business days from the order to dispatch.', [], 'Modules.M4pmerchantpolicy.Admin')),
                $text(self::CONFIG_HANDLING_MAX, $this->trans('Handling time to', [], 'Modules.M4pmerchantpolicy.Admin'), $days),
                $text(self::CONFIG_TRANSIT_MIN, $this->trans('Transit time from', [], 'Modules.M4pmerchantpolicy.Admin'), $days, $this->trans('Business days from dispatch to delivery.', [], 'Modules.M4pmerchantpolicy.Admin')),
                $text(self::CONFIG_TRANSIT_MAX, $this->trans('Transit time to', [], 'Modules.M4pmerchantpolicy.Admin'), $days),
                $select(self::CONFIG_EU_CARRIER, $this->trans('Carrier for other countries', [], 'Modules.M4pmerchantpolicy.Admin'), $carriers, $this->trans('Product pages get a shipping rate for every active country in the zones of this carrier: its price for the product weight, with the VAT of that country.', [], 'Modules.M4pmerchantpolicy.Admin')),
                $text(self::CONFIG_EU_TRANSIT_MIN, $this->trans('Transit time abroad from', [], 'Modules.M4pmerchantpolicy.Admin'), $days),
                $text(self::CONFIG_EU_TRANSIT_MAX, $this->trans('Transit time abroad to', [], 'Modules.M4pmerchantpolicy.Admin'), $days),
            ],
            'submit' => $submit,
        ]];

        $fields = [];
        foreach (array_keys($this->getDefaults()) as $key) {
            $fields[$key] = Tools::getValue($key, Configuration::get($key));
        }
        $fields[self::CONFIG_RETURN_COUNTRIES . '[]'] = Tools::isSubmit('submitM4pmerchantpolicy')
            ? array_map('intval', (array) Tools::getValue(self::CONFIG_RETURN_COUNTRIES, []))
            : $this->getReturnCountryIds();

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitM4pmerchantpolicy';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->tpl_vars = ['fields_value' => $fields];

        return $helper->generateForm([$returnsForm, $shippingForm]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getReturnPolicy(): array
    {
        $isoCodes = array_values(array_filter(array_map(
            static fn (int $id): string => (string) Country::getIsoById($id),
            $this->getReturnCountryIds()
        )));
        $fee = (float) Configuration::get(self::CONFIG_RETURN_FEE);

        $policy = [
            '@type' => 'MerchantReturnPolicy',
            '@id' => $this->nodeId('return-policy'),
            'applicableCountry' => count($isoCodes) === 1 ? $isoCodes[0] : $isoCodes,
            'returnPolicyCountry' => Country::getIsoById((int) Configuration::get('PS_SHOP_COUNTRY_ID')) ?: $this->getShipCountryIso(),
            'returnPolicyCategory' => self::SCHEMA . 'MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => (int) Configuration::get(self::CONFIG_RETURN_DAYS),
            'returnMethod' => self::SCHEMA . (Configuration::get(self::CONFIG_RETURN_METHOD) ?: self::METHOD_BY_MAIL),
            'returnFees' => self::SCHEMA . ($fee > 0 ? 'ReturnShippingFees' : 'FreeReturn'),
        ];
        if ($fee > 0) {
            $policy['returnShippingFeesAmount'] = [
                '@type' => 'MonetaryAmount',
                'value' => $fee,
                'currency' => Currency::getDefaultCurrency()->iso_code,
            ];
        }
        $idCms = (int) Configuration::get(self::CONFIG_RETURN_CMS);
        if ($idCms > 0) {
            $policy['merchantReturnLink'] = $this->context->link->getCMSLink($idCms);
        }

        return $policy;
    }

    /**
     * Gross shipping cost of one product to each active country of the EU carrier's zones, in the
     * default currency. Countries outside the carrier's weight ranges are left out.
     *
     * @return array<string, array{id_country: int, gross: float}>
     */
    private function getEuRates(int $idProduct, float $weight, float $additionalCost): array
    {
        $idReference = (int) Configuration::get(self::CONFIG_EU_CARRIER);
        $carrier = $idReference > 0 ? Carrier::getCarrierByReference($idReference) : false;
        if (!$carrier || !$carrier->active || !$this->productUsesCarrier($idProduct, $idReference)) {
            return [];
        }

        $countries = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT c.`id_country`, c.`iso_code`, c.`id_zone`
            FROM `' . _DB_PREFIX_ . 'country` c
            INNER JOIN `' . _DB_PREFIX_ . 'carrier_zone` cz ON cz.`id_zone` = c.`id_zone` AND cz.`id_carrier` = ' . (int) $carrier->id . '
            INNER JOIN `' . _DB_PREFIX_ . 'zone` z ON z.`id_zone` = c.`id_zone` AND z.`active` = 1
            WHERE c.`active` = 1 AND c.`id_country` != ' . (int) Configuration::get(self::CONFIG_SHIP_COUNTRY) . '
            ORDER BY c.`iso_code`'
        ) ?: [];

        $handling = $carrier->shipping_handling ? (float) Configuration::get('PS_SHIPPING_HANDLING') : 0.0;
        $rates = [];
        foreach ($countries as $country) {
            $idZone = (int) $country['id_zone'];
            if ($carrier->is_free) {
                $net = 0.0;
            } elseif ((int) $carrier->shipping_method === Carrier::SHIPPING_METHOD_PRICE) {
                continue;
            } else {
                if ($carrier->range_behavior && !Carrier::checkDeliveryPriceByWeight($carrier->id, $weight, $idZone)) {
                    continue;
                }
                $price = $carrier->getDeliveryPriceByWeight($weight, $idZone);
                if ($price === false) {
                    continue;
                }
                $net = (float) $price + $handling + $additionalCost;
            }

            $address = new Address();
            $address->id_country = (int) $country['id_country'];
            $rates[$country['iso_code']] = [
                'id_country' => (int) $country['id_country'],
                'gross' => round($net * (1 + $carrier->getTaxesRate($address) / 100), 2),
            ];
        }

        return $rates;
    }

    private function productUsesCarrier(int $idProduct, int $idReference): bool
    {
        $references = array_map('intval', array_column(Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT `id_carrier_reference` FROM `' . _DB_PREFIX_ . 'product_carrier`
            WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . (int) $this->context->shop->id
        ) ?: [], 'id_carrier_reference'));

        return !$references || in_array($idReference, $references, true);
    }

    /**
     * @param int[] $handling
     * @param int[] $transit
     *
     * @return array<string, mixed>
     */
    private function offerShippingDetails(string $iso, array $rate, array $handling, array $transit): array
    {
        return [
            '@type' => 'OfferShippingDetails',
            'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => $iso],
            'shippingRate' => $rate,
            'deliveryTime' => [
                '@type' => 'ShippingDeliveryTime',
                'handlingTime' => $this->days($handling[0], $handling[1]),
                'transitTime' => $this->days($transit[0], $transit[1]),
            ],
        ];
    }

    /**
     * Fixed cost as value, a range as minValue/maxValue (Google reads maxValue as the upper bound).
     *
     * @return array<string, mixed>
     */
    private function rate(float $min, float $max, string $currencyIso): array
    {
        $rate = ['@type' => 'MonetaryAmount', 'currency' => $currencyIso];

        return round($min, 2) === round($max, 2)
            ? $rate + ['value' => round($max, 2)]
            : $rate + ['minValue' => round($min, 2), 'maxValue' => round($max, 2)];
    }

    /**
     * @return array<string, mixed>
     */
    private function servicePeriod(int $min, int $max): array
    {
        return ['@type' => 'ServicePeriod', 'duration' => $this->days($min, $max)];
    }

    /**
     * @return array<string, mixed>
     */
    private function days(int $min, int $max): array
    {
        return ['@type' => 'QuantitativeValue', 'unitCode' => 'DAY', 'minValue' => $min, 'maxValue' => $max];
    }

    private function nodeId(string $fragment): string
    {
        return $this->context->link->getPageLink('index', true) . '#' . $fragment;
    }

    private function getShipCountryIso(): string
    {
        return (string) Country::getIsoById((int) Configuration::get(self::CONFIG_SHIP_COUNTRY));
    }

    /**
     * @return int[]
     */
    private function getReturnCountryIds(): array
    {
        return array_map('intval', json_decode((string) Configuration::get(self::CONFIG_RETURN_COUNTRIES), true) ?: []);
    }

    private function isFreeShippingCountry(int $idCountry): bool
    {
        return $this->getFreeShippingModule()
            ? M4p_FreeShipping::isCountryEligible($idCountry)
            : $idCountry === (int) Configuration::get(self::CONFIG_SHIP_COUNTRY);
    }

    private function getFreeShippingModule(): ?Module
    {
        $module = Module::isEnabled('m4p_freeshipping') ? Module::getInstanceByName('m4p_freeshipping') : false;

        return $module instanceof Module ? $module : null;
    }

    private function formatAmount(float $amount): string
    {
        return Tools::getContextLocale($this->context)->formatPrice($amount, Currency::getDefaultCurrency()->iso_code);
    }

    /**
     * Client's policy, read at install; the EU carrier and the return page are looked up by name.
     *
     * @return array<string, string|int>
     */
    private function getDefaults(): array
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $idCountry = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $idCms = (int) Db::getInstance()->getValue(
            'SELECT c.`id_cms` FROM `' . _DB_PREFIX_ . 'cms` c
            INNER JOIN `' . _DB_PREFIX_ . 'cms_lang` cl ON cl.`id_cms` = c.`id_cms` AND cl.`id_lang` = ' . $idLang . '
            WHERE c.`active` = 1 AND (cl.`link_rewrite` LIKE "%zwrot%" OR cl.`link_rewrite` LIKE "%return%")
            ORDER BY c.`id_cms`'
        );

        return [
            self::CONFIG_ENABLED => 0,
            self::CONFIG_RETURN_DAYS => 14,
            self::CONFIG_RETURN_FEE => '0',
            self::CONFIG_RETURN_METHOD => self::METHOD_BY_MAIL,
            self::CONFIG_RETURN_COUNTRIES => (string) json_encode([$idCountry]),
            self::CONFIG_RETURN_CMS => $idCms,
            self::CONFIG_SHIP_COUNTRY => $idCountry,
            self::CONFIG_SHIP_COST_MIN => '0',
            self::CONFIG_SHIP_COST_MAX => '0',
            self::CONFIG_HANDLING_MIN => 0,
            self::CONFIG_HANDLING_MAX => 1,
            self::CONFIG_TRANSIT_MIN => 1,
            self::CONFIG_TRANSIT_MAX => 3,
            self::CONFIG_FREE_FROM => '',
            self::CONFIG_EU_CARRIER => 0,
            self::CONFIG_EU_TRANSIT_MIN => 2,
            self::CONFIG_EU_TRANSIT_MAX => 5,
        ];
    }
}

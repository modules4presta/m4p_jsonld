<?php

declare(strict_types=1);

/**
 * m4p_jsonld
 *
 * @author    Modules4Presta <contact@modules4presta.io>
 * @copyright 2026 Nice Code sp. z o.o. (Modules4Presta)
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Adds the organization identity settings (empty) and hands the organization data to templates as
 * `$modules.m4p_jsonld.organization`.
 */
function upgrade_module_2_1_0(Module $module): bool
{
    foreach ([
        M4p_JsonLd::CONFIG_ORG_NAME,
        M4p_JsonLd::CONFIG_ORG_URL,
        M4p_JsonLd::CONFIG_ORG_DESCRIPTION,
        M4p_JsonLd::CONFIG_ORG_LOGO,
        M4p_JsonLd::CONFIG_ORG_SAME_AS,
    ] as $key) {
        if (!Configuration::hasKey($key)) {
            Configuration::updateValue($key, '');
        }
    }

    return $module->registerHook('actionFrontControllerSetVariables');
}

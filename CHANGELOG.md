# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the module uses
[semantic versioning](https://semver.org/).

## [2.0.0] - 2026-10-28

The module is now the shop's structured-data module and is installed as `m4p_jsonld`. Settings are
unchanged, and so are their names, so a shop that carries them over keeps its configuration.

### Changed

- Module name, directory and class: `m4p_jsonld` and `M4p_JsonLd`. PrestaShop reads the directory
  name as the module's identity, so the previous directory stays in place until it is removed by
  hand and the settings are carried over.
- Translation domain `Modules.M4pjsonld`, with the catalogue files renamed to match.
- The name and description name the whole scope of the module instead of one of its areas.

## [1.0.2] - 2026-10-27

### Changed

- Handling and transit times are always given as `minValue` and `maxValue`, also when both are the same
  number of days, as Google's merchant listings expect.
- The free shipping threshold and the countries with free shipping are read from `m4p_freeshipping`.

## [1.0.1] - 2026-10-21

First open-source release, published under the MIT license.

### Added

- en-US catalogue, MIT headers, `LICENSE`, `README`, `SECURITY`, `CONTRIBUTING`, `config_pl.xml` and
  `index.php` guards in every directory.

### Changed

- The module installs switched off, with neutral defaults: a return fee and shipping costs of 0, no
  foreign carrier, and the first active CMS page with "return" or "zwrot" in its URL as the returns page.

## [1.0.0] - 2026-10-21

### Added

- Organization-level `MerchantReturnPolicy` and `ShippingService` (paid shipping, free shipping from an order
  value) for Google.
- Offer-level `OfferShippingDetails` (free when the product's price reaches the threshold, a rate per country
  of a selected foreign carrier) and `MerchantReturnPolicy`.
- Configuration page, defaults set at install, pl-PL translations.

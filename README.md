# Structured data for PrestaShop 8 & 9

**The shop's JSON-LD in one module. This release covers the fields Google reads for merchant listings: return window, return fee and delivery costs – set once in the back office, current on every product page.**

> **Meta description (155 chars):** Structured data for PrestaShop as schema.org JSON-LD: MerchantReturnPolicy and OfferShippingDetails for Google merchant listings. Free, MIT.

---

## Why return and shipping data matter for your store

Google shows delivery cost and return conditions next to products in search results and in the Shopping tab, and Search Console reports missing `shippingDetails` and `hasMerchantReturnPolicy` on every product page as warnings. Without them a listing competes with offers that state "free delivery" and "30-day returns" while yours states nothing.

Writing the values by hand into a theme template works until a carrier price changes; then the markup and the checkout disagree, which is what Merchant Center flags as a mismatch.

- **One place for the policy** – return window, fee, method and countries in the module configuration.
- **Rates from your carriers** – foreign shipping rates come from the carrier's own weight ranges and taxes.
- **Consistent with the checkout** – the free shipping threshold is the one the shop applies.

## What the module does

The module builds the schema.org arrays and returns them to the theme, which merges them into its existing `Organization` and `Offer` JSON-LD. It renders nothing by itself and changes no prices or carriers.

### Key features

- **Organization identity** – name, main website, description, logo and profiles (`sameAs`) of the organization behind the shop, with an `@id` built from the website. Several sites of one company point to the same organization, so search engines and AI assistants connect them.
- **MerchantReturnPolicy** – finite return window, return method, countries, return fee (`FreeReturn` for 0) and a link to your returns page.
- **ShippingService** for the organization – handling time and the cost range for the shipping country, plus a free shipping condition from an order value.
- **OfferShippingDetails** for each product – free when the product's gross price reaches the threshold, and one rate per active country in the zones of a selected foreign carrier, with that country's VAT.
- **Free shipping threshold** – read from `m4p_freeshipping` when installed, otherwise from "Free shipping starts at".

### Before you switch it on

The module installs switched off, with neutral defaults: a 14-day return window, a fee of 0 and no foreign carrier. Set your own values first; markup with wrong data is worse than none.

## Compatibility

| | |
|---|---|
| PrestaShop | 8.0 – 9.1 (return and shipping data tested on 8.x) |
| PHP | 7.4+ |
| Requirements | a theme with product JSON-LD (the module adds to it) |
| Multistore | Settings follow the shop context of the configuration page |
| Themes | Needs a few lines in the theme's JSON-LD templates (see below) |

The module overrides no core class and creates no database table.

## Installation

1. Upload and install the module from **Modules → Module Manager**.
2. Open its configuration, fill in the return policy and the shipping costs, then switch on **Add the policies to the structured data**.
3. Add the theme integration below and check a product page in Google's Rich Results Test.

## Configuration options

| Setting | Configuration key | Default |
|---|---|---|
| Organization name | `M4P_JSONLD_ORG_NAME` | empty: the shop name |
| Organization website | `M4P_JSONLD_ORG_URL` | empty: this shop; `@id` is `<website>#organization` |
| Organization description | `M4P_JSONLD_ORG_DESCRIPTION` | empty: no description |
| Organization logo | `M4P_JSONLD_ORG_LOGO` | empty: the shop logo |
| Organization profiles (`sameAs`) | `M4P_JSONLD_ORG_SAME_AS` | empty; one address per line |
| Add the policies to the structured data | `M4PMERCHANTPOLICY_ENABLED` | off |
| Return window (days) | `M4PMERCHANTPOLICY_RETURN_DAYS` | 14 |
| Return shipping fee (gross) | `M4PMERCHANTPOLICY_RETURN_FEE` | 0 (`FreeReturn`) |
| Return method | `M4PMERCHANTPOLICY_RETURN_METHOD` | `ReturnByMail` (`ReturnInStore`) |
| Return countries | `M4PMERCHANTPOLICY_RETURN_COUNTRIES` | the default country |
| Return policy page | `M4PMERCHANTPOLICY_RETURN_CMS` | first active CMS page with "return" or "zwrot" in its URL |
| Shipping country | `M4PMERCHANTPOLICY_SHIP_COUNTRY` | the default country |
| Lowest / highest shipping cost (gross) | `M4PMERCHANTPOLICY_SHIP_COST_MIN` / `_MAX` | 0 / 0 |
| Handling time (business days) | `M4PMERCHANTPOLICY_HANDLING_MIN` / `_MAX` | 0 / 1 |
| Transit time (business days) | `M4PMERCHANTPOLICY_TRANSIT_MIN` / `_MAX` | 1 / 3 |
| Free shipping from (gross) | `M4PMERCHANTPOLICY_FREE_FROM` | empty: the shop's threshold; 0 leaves free shipping out |
| Carrier for other countries | `M4PMERCHANTPOLICY_EU_CARRIER` | none |
| Transit time abroad | `M4PMERCHANTPOLICY_EU_TRANSIT_MIN` / `_MAX` | 2 / 5 |

## Output

| Method | Where | Properties |
|---|---|---|
| `getOrganizationData()` | `Organization` | `@id` always; `name`, `url`, `description`, `logo`, `sameAs` when set; `hasMerchantReturnPolicy`, `hasShippingService` while the policies are on |
| `getOfferData(int $idProduct, int $idProductAttribute = 0)` | `Offer` (product page) | `hasMerchantReturnPolicy`, `shippingDetails` |

`getOfferData()` returns `[]` while the policies are switched off. Foreign rates come from the carrier's weight ranges for the product's weight (product plus combination), plus handling and the product's additional shipping cost. A country outside the ranges (with "out of range: disable") is left out, and so is the carrier for a product limited to other carriers. Organization amounts are in the default currency, offer amounts in the context currency, as Google expects the Offer's currency.

## Theme integration

```smarty
{$mp = Module::getInstanceByName('m4p_jsonld')}
{if $mp && $mp->active}
  {foreach $mp->getOfferData((int) $product.id, (int) $product.id_product_attribute) as $key => $value}
    {$offer[$key] = $value}
  {/foreach}
{/if}
```

The organization data also reaches every front template as `$modules.m4p_jsonld.organization`, so the theme needs no call to the module:

```smarty
{if isset($modules.m4p_jsonld.organization)}
  {foreach $modules.m4p_jsonld.organization as $key => $value}
    {$organization[$key] = $value}
  {/foreach}
{/if}
```

Render the result with `json_encode`, not by hand. Give the `WebSite` node `"publisher": {"@id": …}` with the organization's `@id`.

## Frequently asked questions

**Does it output its own JSON-LD block?**
No. Google expects these properties inside the `Offer` and `Organization` the theme already prints, so the module hands the arrays to the theme instead of adding a second, competing block.

**Where do the foreign shipping rates come from?**
From the selected carrier's weight ranges and zones, with the tax rule of each country, so they match what the checkout charges.

**What happens when I switch it off?**
Both methods return an empty array and the theme prints its markup as before.

---

**Keywords:** prestashop merchant return policy, prestashop shipping details schema, google merchant listings prestashop, prestashop structured data shipping, offershippingdetails prestashop

## License

MIT – see [LICENSE](LICENSE). Free to use commercially, fork and modify; keep the copyright notice.

## Contributing

Bug reports and pull requests are welcome – see [CONTRIBUTING.md](CONTRIBUTING.md). For security
issues, follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

---

Built by [Nice Code](https://nice-code.com/pl/oferta/moduly-prestashop) – we build and maintain PrestaShop stores.

© Nice Code sp. z o.o. (Modules4Presta) – released under the MIT license.

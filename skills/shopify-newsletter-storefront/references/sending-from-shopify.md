# Sending newsletters from Shopify (what the customer does)

The website only collects subscribers; the newsletters themselves are written and sent in Shopify
Messaging (formerly "Shopify Email"). Facts as checked in October 2026 - verify prices in the store.

## What to tell the customer

- **Cost:** 10,000 e-mails a month are free, then about USD 1 per 1,000 (Shopify's own figure; check
  it in the store before quoting it).
- **One issue per language.** Subscribers carry the tags `newsletter-de`, `newsletter-it`,
  `newsletter-en` and the customer `locale`; a Shopify customer segment such as
  `customer_tags CONTAINS 'newsletter-it'` addresses one language.
- **Sender:** a sender name and an address on the customer's own domain (e.g. newsletter@…).
  Shopify asks for a few DNS records (sender domain authentication) under
  Settings > Notifications; the customer's IT/DNS provider adds them. Without them mail lands in
  spam more often. Check the domain's DMARC record too - in the reference project its `rua` still
  pointed at the previous tool (Brevo).
- **Mandatory footer:** unsubscribe link and the shop's postal address are inserted by Shopify;
  the address comes from the store settings, so make sure it is right.
- **Discount codes:** create the discount in Shopify and add it to the e-mail. The link carries the
  code to the website (redirect theme + `?discount=`), and the cart applies it.
- **Product suggestions from earlier purchases:** Shopify segments can filter by purchased products
  ("bought product X"); send that segment an issue with matching wines. The selection per segment is
  manual. Automatic per-recipient recommendations need a third-party tool with a Shopify app
  (Klaviyo, Omnisend, ...); they sync customers, orders and consent from Shopify, so the website
  side stays the same. Purchase-based suggestions are profiling: the privacy policy and the consent
  text must cover it.

## Why the website needs the redirect pieces at all

Shopify builds every product link on its own online store (`/products/{handle}` on the
myshopify.com domain), in Shopify Messaging, order mails and any tool that syncs the catalog. In a
headless setup that store only exists to hand visitors on, so:

1. the online store runs the redirect theme (assets/shopify-theme) - it keeps path and query and
   adds the `discount_code` cookie as `?discount=`;
2. the website maps Shopify's paths itself (`ShopifyStorefrontController`), so the mapping lives in
   one tested place;
3. the cart takes the code from `?discount=` and applies it through the Storefront API.

A Shopify discount link is `https://<store>.myshopify.com/discount/CODE?redirect=/products/handle`:
Shopify answers it itself (sets the cookie, redirects), then the theme hands over. The website's own
`/discount/{code}` route only matters if someone builds the link on the website's domain.

Liquid cannot send a real 301, so the theme redirects with JavaScript (and a `<noscript>` meta
refresh without the query). Shopify's own headless redirect theme does the same; ours differs only
in leaving the path mapping to the website.

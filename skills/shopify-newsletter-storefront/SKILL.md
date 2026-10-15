---
name: shopify-newsletter-storefront
description: "Newsletter sign-up into Shopify customers (own double opt-in) plus the storefront plumbing a headless Shopify shop needs so newsletters can be sent from Shopify: a redirect theme for the online store, website routes for Shopify's /products, /collections, /cart and /discount links, and discount codes from links applied to the cart. Built on kreatif/validus-shopify-bridge's Shopify connection in a Statamic + Laravel site. Use this whenever a project wants newsletter sign-ups to land in Shopify (instead of Brevo, Mailchimp, ...), wants to send newsletters with Shopify Messaging / Shopify Email, asks why product links from Shopify mails end on a 404, wants discount or coupon codes from newsletter links in the cart, or needs a redirect theme for a headless Shopify store - even if the user only says 'newsletter' or 'Gutscheincode' and never names Shopify customers."
license: proprietary
metadata:
  author: kreatif
  reference-project: kellerei-st-michael (Oct 2026, live-tested)
---

# Newsletter into Shopify + headless storefront links

A tested setup from kellerei-st-michael (Statamic 5, Laravel 12, sites `de`/`it`/`en`, Shopify Basic
plan). The files under `assets/` are the working code from that project; copy them and adapt the
few project-specific points listed below instead of rebuilding from scratch.

## How it fits together

```
Website form ──POST /!/newsletter──▶ NewsletterController::subscribe
   (or contact form toggle → SubscribeContactToNewsletter)
        └─ NewsletterSignup::requestConfirmation() ─▶ queued NewsletterConfirmation mail
               link = signed, 7-day URL carrying the sign-up encrypted (no address in the URL)
Click ──GET /!/newsletter/confirm──▶ NewsletterController::confirm
        ├─ bad / expired link ─▶ errors/newsletter_link (403, in the sign-up's language)
        └─ SubscribeToNewsletter job ─▶ ShopifyNewsletter::subscribe
               customerByIdentifier → customerCreate | consent update + tagsAdd
               consent SUBSCRIBED / CONFIRMED_OPT_IN, locale, tags newsletter + newsletter-{lang}

Shopify Messaging mail ─▶ <store>.myshopify.com/products/x (?discount via /discount/CODE)
   redirect theme ─▶ website /products/x?…&discount=CODE
   ShopifyStorefrontController ─301▶ /it/product/x?year=…&format=…&discount=CODE
   cart (Alpine store) ─▶ keeps the code, applies it with cartDiscountCodesUpdate
```

The bridge's `ShopifyGraphqlClient` (Admin API, `config/validus-shopify.php`) does all Shopify writes,
so no second Shopify connection is needed.

## Before starting

1. **Ask, don't assume, what happens to existing subscribers** of the old tool. In the reference
   project they were deliberately not migrated (they never confirmed into Shopify).
2. **Shopify app scopes:** the bridge's custom app needs `read_customers` and `write_customers`
   (Settings › Apps › Develop apps › the app › Admin API scopes). Verify with
   `{ currentAppInstallation { accessScopes { handle } } }`. In the reference project the existing
   token kept working after adding the scope.
3. **Live systems:** a local `.env` usually holds the live Shopify tokens. Keep the test guard
   (`Http::preventStrayRequests()` in `tests/TestCase.php`, `.invalid` hosts in `phpunit.xml`), and
   never open a real confirmation link or run the queue after one without the user's explicit OK -
   that writes a customer into the live shop. Do not `queue:retry all` locally either: old failed
   jobs (e.g. Validus order exports) would hit live systems.
4. **Production needs a queue worker** - the mail and the Shopify write are both queued.

## Setup

1. Copy `assets/laravel/**` into the project (same relative paths). The newsletter form markup
   (`resources/views/layout/_newsletter_form.antlers.html`) and `newsletter.js` are examples to merge
   into the project's own newsletter module, not drop-in files.
2. Wire the rest from `references/wiring.md`: routes, `bootstrap/app.php`, `config/services.php`,
   `.env.example`, the contact form listener, translations, and - for shops using the
   statamic-rad-pack/shopify cart - the discount code pieces of `cart.js`, `alpine.js` and the cart
   overlay.
3. Adapt the project-specific points:
   - site handles and URL prefixes (`whereIn('site', ['it', 'en'])` in the routes, sites in tests);
   - product URL `/product/{slug}` (the entry slug must equal the Shopify handle) and the shop page
     URI `/shop` in `ShopifyStorefrontController`;
   - variant preselection `?year=option1&format=option2` - use whatever the project's product page
     reads;
   - the mail templates use the project's mail layout and logo; keep `'config' => Cascade::config()`
     in the Mailable (see Gotchas);
   - the contact form handle `contact` and its `newsletter` toggle in the listener;
   - text register: German site copy for end customers used "Sie" in the reference project - match the
     site, not this skill.
4. Remove the old newsletter integration (service class, config block, env vars, tests) in the same
   change, so nothing posts to two places.
5. Run the copied tests (`NewsletterSubscriptionTest`, `ShopifyNewsletterTest`,
   `SubscribeContactToNewsletterTest`, `ShopifyStorefrontRedirectTest`,
   `KeepQueryOnLocaleRedirectTest`) and the full suite. `ShopifyStorefrontRedirectTest` works on real
   products from `content/`, like the project's other content-based tests.
6. Build the frontend assets and check the form, the mail (Mailpit) and the link page in the browser.
   For the cart, mock the Storefront API in the browser (Playwright `page.route('**/graphql.json')`)
   rather than creating carts in the live shop.

## Redirect theme for the Shopify online store

`assets/shopify-theme/` is a minimal theme: every page of the online store sends the visitor to the
**same path and query** on the website and adds Shopify's `discount_code` cookie as `?discount=`.
Policies, gift cards, Shopify's bot check / queue pages and the theme editor are left alone.

- Zip it (`zip -r redirect-theme.zip layout templates config locales`), upload under Online Store ›
  Themes, set **Website** in the theme settings (default is a placeholder), preview, then publish.
- `npx @shopify/cli@3.80.0 theme check --path assets/shopify-theme` passes (newer CLI versions need
  Node ≥ 22).
- **Order matters:** this theme leaves the path mapping to the website, so publish it only after the
  website routes are live - otherwise `/products/…` lands on the website's 404. If the store already
  runs Shopify's own headless redirect theme, it keeps working meanwhile (it also only swaps the host
  and passes the discount code).
- The online store must not be password protected, or Shopify shows its password page first.

## Gotchas (each one cost time in the reference project)

**Shopify Admin API**
- Shopify sends **no** double opt-in mail for customers created through the API, and setting the
  consent to `PENDING` does not trigger one either. Hence the own DOI; on confirmation write
  `SUBSCRIBED` + `CONFIRMED_OPT_IN` with `consentUpdatedAt` = the click.
- `customers(query: "email:…")` matches parts of addresses - `anna@x.com` also finds
  `hanna@x.com`. Use `customerByIdentifier(identifier: {emailAddress: …})`: exact, `null` if unknown.
- On the Basic plan an admin-created custom app may **write** customer e-mail and names but not
  **read** them back (`ACCESS_DENIED` on `email`, `firstName`, …). When checking a customer, select
  only `id`, `locale`, `tags`, `emailMarketingConsent`. Creating customers with an e-mail works on
  Basic (verified 2026-10-15).
- `shopLocales` needs `read_locales`; the language comes from the Statamic site instead.

**Laravel / Statamic**
- The confirmation link carries the sign-up encrypted (`Crypt::encryptString`) inside a
  `URL::temporarySignedRoute`, so no address shows up in URLs or access logs.
- The route deliberately has no `signed` middleware: the controller decrypts first (to know the
  language) and then checks `$request->hasValidSignature()`, so even an expired link gets the
  friendly page in the right language instead of Laravel's bare 403.
- Rendering a Statamic page from a controller: `(new \Statamic\View\View)->template(…)->layout('layout')->with([...])->render()`,
  after `Site::setCurrent($site)` and `app()->setLocale(...)`.
- Antlers mail views rendered from a Mailable have no cascade, so `{{ config:app:url }}` (the logo
  URL) is empty: pass `'config' => \Statamic\Facades\Cascade::config()` in `content()->with`, as
  Statamic's own form mails do. Use a PNG logo at 2× size - many mail clients do not show SVG.
- Laravel hands route parameters to controller actions **by position**. With an optional `/{site}`
  prefix the action would receive the site as `$handle`; read them by name (`$request->route('handle')`).
- reachweb/locale-lander redirects `/` to the browser language's home page and **drops the query
  string** - newsletter `utm_*` and `?discount=` were lost for Italian/English browsers.
  `KeepQueryOnLocaleRedirect` (global middleware) puts it back; never patch vendor/.
- A layout partial using `{{ collection:handle }}` on a page without an entry (the link page) hits
  the collection *tag* and prints junk into inline JS. Guard it with the entry id
  (`{{ id ? collection:handle : '' }}`).
- Antlers modifiers do not apply to a tag's output (`{{ trans:key | to_json }}` stays raw), so pass
  translated texts to Alpine as rendered elements, or with `{{ trans:key count="<span x-text='…'></span>" }}`.
- Statamic's `SubmissionCreated` fires twice per submission - the listener keeps a static guard.

**Storefront cart**
- A discount code Shopify does not know, or whose conditions are not met yet, stays on the cart with
  `applicable: false`; it starts to count once the conditions are met. Show it with a hint instead of
  treating it as an error. The pending code is cleared before the request, so a refused one is not
  retried on every page.
- Line `amountPerQuantity` is before discounts, `cost.totalAmount` after; the discount shown is the
  sum of cart-level and line-level `discountAllocations`.

## Live test (only with the user's OK)

1. Read-only first: `customerByIdentifier` for the test address should be `null`.
2. Sign up through the real form (reCAPTCHA steps aside locally without a secret), check the queue
   holds exactly one `NewsletterConfirmation`, run `queue:work --stop-when-empty`.
3. Take the link from Mailpit (`/api/v1/messages`), open it, check exactly one
   `SubscribeToNewsletter` is queued, run the queue once more.
4. Read the customer back (id, locale, tags, consent) - expect `SUBSCRIBED`, `CONFIRMED_OPT_IN`,
   the site language, both tags.
5. Sign up the same address again: same customer id, no duplicate.
6. Leave deleting the test customer to the user.

## Talking to the customer

- `references/customer-plan.md` - the plan the reference customer approved (German, informal "du",
  no technical detail). Kreatif always addresses customers with "du".
- `references/sending-from-shopify.md` - what they need to know to send from Shopify Messaging:
  cost, one issue per language, sender domain authentication, discount codes, purchase-based
  suggestions and the privacy policy.

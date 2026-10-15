# Wiring: the pieces that live inside existing project files

Copied from the reference project (kellerei-st-michael, Statamic 5 / Laravel 12, sites `de` `it` `en`).
The standalone files are under `assets/laravel/`; these are the excerpts that go into files every
project already has. Adapt names (`it`/`en` prefixes, `/shop`, `/product/{slug}`) to the project.

## routes/web.php

```php
// Newsletter module (layout/_newsletter.antlers.html) -> double opt-in mail.
// reCAPTCHA is checked by VerifyRecaptcha; the limit keeps bots from mailing
// confirmation requests to arbitrary addresses.
Route::post('/!/newsletter', [NewsletterController::class, 'subscribe'])
    ->name('newsletter.subscribe')
    ->middleware('throttle:5,1');

// The link in that mail -> Shopify customer. Signed and time-limited, see
// App\Support\NewsletterSignup; the controller checks the signature itself
// to show errors/newsletter_link for bad links.
Route::get('/!/newsletter/confirm', [NewsletterController::class, 'confirm'])
    ->name('newsletter.confirm')
    ->middleware('throttle:20,1');

// Links to Shopify's online store (Shopify Email, order mails, newsletter
// tools). Its theme sends them here with only the host name swapped, see
// ShopifyStorefrontController. Optional /it or /en prefix, like the sites.
foreach (['', '/{site}'] as $prefix) {
    Route::get($prefix.'/products/{handle}', [ShopifyStorefrontController::class, 'product'])
        ->whereIn('site', ['it', 'en']);
    Route::get($prefix.'/collections/{collection}/products/{handle}', [ShopifyStorefrontController::class, 'product'])
        ->whereIn('site', ['it', 'en']);
    Route::get($prefix.'/collections/{collection?}', [ShopifyStorefrontController::class, 'shop'])
        ->whereIn('site', ['it', 'en']);
    Route::get($prefix.'/cart', [ShopifyStorefrontController::class, 'shop'])
        ->whereIn('site', ['it', 'en']);
    Route::get($prefix.'/discount/{code}', [ShopifyStorefrontController::class, 'discount'])
        ->whereIn('site', ['it', 'en']);
}
```

`whereIn('site', ['it', 'en'])` = the non-default site handles, which are also their URL prefixes here.
Check that no Statamic entry uses `/products`, `/collections`, `/cart` or `/discount` before adding them.

## bootstrap/app.php (inside `withMiddleware`)

```php
        // Global, so it wraps locale-lander in Statamic's middleware group.
        $middleware->append(KeepQueryOnLocaleRedirect::class);
```

Only needed when the project uses reachweb/locale-lander (it drops the query string on its home page redirect).

## config/services.php

```php
    /*
    | Newsletter sign-ups (newsletter module and the contact form's marketing
    | toggle) are confirmed by double opt-in (App\Support\NewsletterSignup) and
    | then saved as subscribed Shopify customers (App\Support\ShopifyNewsletter),
    | through the Shopify connection of config/validus-shopify.php. Without a URL
    | here the confirmation link leads to the home page of the language the
    | visitor signed up in.
    */
    'newsletter' => [
        'confirmed_urls' => [
            'de' => env('NEWSLETTER_CONFIRMED_URL_DE'),
            'it' => env('NEWSLETTER_CONFIRMED_URL_IT'),
            'en' => env('NEWSLETTER_CONFIRMED_URL_EN'),
        ],
    ],
```

## .env.example

```dotenv
# Newsletter -> double opt-in mail, then a subscribed Shopify customer (the
# Shopify app needs write_customers), see config/services.php. Pages the
# confirmation link leads to; empty = home page of the language.
NEWSLETTER_CONFIRMED_URL_DE=
NEWSLETTER_CONFIRMED_URL_IT=
NEWSLETTER_CONFIRMED_URL_EN=
```

## AppServiceProvider::boot() (contact form marketing toggle, optional)

```php
Event::listen(\Statamic\Events\SubmissionCreated::class, \App\Listeners\SubscribeContactToNewsletter::class);
```

The listener expects a Statamic form `contact` with a toggle field `newsletter` and fields `email`, `first_name`, `last_name`.

## Translations

`resources/lang/{de,it,en}/strings.php` (mail + link page):

```php
// de
'newsletter_mail_subject' => 'Bitte bestätigen Sie Ihre Newsletter-Anmeldung',
'newsletter_mail_body' => 'vielen Dank für Ihr Interesse an unserem Newsletter. Bitte bestätigen Sie Ihre Anmeldung mit einem Klick auf den folgenden Link.',
'newsletter_mail_button' => 'Anmeldung bestätigen',
'newsletter_mail_validity' => 'Der Link ist :days Tage gültig. Falls Sie sich nicht angemeldet haben, können Sie diese E-Mail einfach ignorieren – Sie erhalten dann keinen Newsletter.',
'newsletter_link_title' => 'Dieser Link ist nicht mehr gültig',
'newsletter_link_text' => 'Der Bestätigungslink ist abgelaufen oder unvollständig. Bitte melden Sie sich einfach noch einmal für den Newsletter an – Sie erhalten dann einen neuen Link.',
'newsletter_link_button' => 'Zur Startseite',
// it
'newsletter_mail_subject' => 'Conferma la tua iscrizione alla newsletter',
'newsletter_mail_body' => 'grazie per il tuo interesse per la nostra newsletter. Conferma la tua iscrizione cliccando sul link seguente.',
'newsletter_mail_button' => 'Conferma iscrizione',
'newsletter_mail_validity' => 'Il link è valido per :days giorni. Se non ti sei iscritto/a, puoi semplicemente ignorare questa e-mail: non riceverai la newsletter.',
'newsletter_link_title' => 'Questo link non è più valido',
'newsletter_link_text' => 'Il link di conferma è scaduto o incompleto. Iscriviti di nuovo alla newsletter: riceverai un nuovo link.',
'newsletter_link_button' => 'Alla pagina iniziale',
// en
'newsletter_mail_subject' => 'Please confirm your newsletter subscription',
'newsletter_mail_body' => 'thank you for your interest in our newsletter. Please confirm your subscription by clicking the link below.',
'newsletter_mail_button' => 'Confirm subscription',
'newsletter_mail_validity' => 'The link is valid for :days days. If you did not sign up, simply ignore this e-mail – you will not receive the newsletter.',
'newsletter_link_title' => 'This link is no longer valid',
'newsletter_link_text' => 'The confirmation link has expired or is incomplete. Simply sign up for the newsletter again – you will receive a new link.',
'newsletter_link_button' => 'To the home page',
```

`resources/lang/{de,it,en}/labels.php` (form messages + cart):

```php
// de
'cart_discount_code' => 'Gutscheincode',
'cart_discount_not_applicable' => 'gilt (noch) nicht für diesen Warenkorb',
'cart_discount_amount' => 'Rabatt',
'newsletter_success' => 'Fast geschafft! Bitte bestätigen Sie Ihre Anmeldung über den Link in der E-Mail, die wir Ihnen gerade geschickt haben.',
'newsletter_error' => 'Die Anmeldung hat leider nicht geklappt. Bitte versuchen Sie es später erneut.',
// it
'cart_discount_code' => 'Codice sconto',
'cart_discount_not_applicable' => 'non (ancora) valido per questo carrello',
'cart_discount_amount' => 'Sconto',
'newsletter_success' => 'Quasi fatto! La preghiamo di confermare l\'iscrizione tramite il link nell\'e-mail che Le abbiamo appena inviato.',
'newsletter_error' => 'Purtroppo l\'iscrizione non è andata a buon fine. Si prega di riprovare più tardi.',
// en
'cart_discount_code' => 'Discount code',
'cart_discount_not_applicable' => 'does not apply to this cart (yet)',
'cart_discount_amount' => 'Discount',
'newsletter_success' => 'Almost done! Please confirm your subscription via the link in the email we have just sent you.',
'newsletter_error' => 'Unfortunately the sign-up did not work. Please try again later.',
```

German website copy addresses end customers with "Sie", Italian with "tu"/"Lei" as the rest of the site does - match the project.

## Storefront cart: discount codes (resources/js/.../shopify/cart.js + alpine.js)

Only for projects with the statamic-rad-pack/shopify cart store (`Alpine.store('statamic.shopify.cart')`).

Add to the cart query (cart level, next to `cost`):

```graphql
      discountCodes {
        code
        applicable
      }
      discountAllocations {
        discountedAmount {
          amount
        }
      }
```

and to each line node:

```graphql
            discountAllocations {
              discountedAmount {
                amount
              }
            }
```

New mutation in cart.js (export it):

```js
// Replaces the cart's codes. A code Shopify does not know, or one whose
// conditions the cart does not meet (yet), stays on the cart with
// applicable: false - it is not an error.
const setDiscountCodes = async (cartId, discountCodes) => {
    const operation = `mutation cartDiscountCodesUpdate($discountCodes: [String!]) {
      cartDiscountCodesUpdate(cartId: "${cartId}", discountCodes: $discountCodes) {
        cart {
          ${cartQuery}
        }
        userErrors {
          field
          message
        }
      }
    }`;

    const { data, errors } = await client.request(operation, {
        variables: {
            discountCodes: discountCodes,
        },
    });

    if (errors) {
        console.warn(errors);

        return;
    }

    if (data.cartDiscountCodesUpdate.userErrors.length > 0) {
        console.warn(data.cartDiscountCodesUpdate.userErrors);

        return;
    }

    return data.cartDiscountCodesUpdate.cart;
};
```

In the Alpine store: a pending code from `?discount=`, applied once a cart exists:

```js
// A discount code from a link (?discount=CODE) waits here until there is a
// cart to put it on - a visitor from a newsletter usually has none yet.
const PENDING_DISCOUNT_KEY = 'statamic.shopify.cart.discount';

const createStore = (Alpine) => {
    Alpine.store('statamic.shopify.cart', {
        cartId: null,
        checkoutUrl: '',
        lineItems: [],
        note: '',
        subtotal: null,
        subtotalAmount: 0,
        currencyCode: 'EUR',
        // [{ code, applicable }] as Shopify reports them
        discountCodes: [],
        discount: null,
        discountAmount: 0,

        async init() {
            this.takeDiscountFromUrl();

            this.cartId = localStorage.getItem('statamic.shopify.cart.id');

            if (this.cartId) {
                this.updateFromResponse(await getOrCreateCart(this.cartId));
            }
        },

        // Shopify's discount links arrive as ?discount=CODE (Shopify's online
        // store hands the code over that way, see ShopifyStorefrontController).
        // Taken out of the address so a shared or bookmarked URL does not carry it.
        takeDiscountFromUrl() {
            const url = new URL(window.location.href);
            const code = (url.searchParams.get('discount') ?? '').trim();

            if (!code) {
                return;
            }

            localStorage.setItem(PENDING_DISCOUNT_KEY, code);
            url.searchParams.delete('discount');
            window.history.replaceState(window.history.state, '', url);
        },

        // Adds a waiting code to the cart's codes. Cleared before the request,
        // so a code Shopify refuses is not tried again on every page.
        async applyPendingDiscount() {
            const code = localStorage.getItem(PENDING_DISCOUNT_KEY);

            if (!code || !this.cartId) {
                return;
            }

            localStorage.removeItem(PENDING_DISCOUNT_KEY);

            const codes = this.discountCodes.map((discount) => discount.code);

            if (codes.some((existing) => existing.toLowerCase() === code.toLowerCase())) {
                return;
            }

            this.updateFromResponse(await setDiscountCodes(this.cartId, [...codes, code]));
        },
```

At the end of `updateFromResponse(checkout)`, before saving the cart id:

```js
            // Line prices above are before discounts, the total after them.
            // The difference is what the cart's and the lines' discounts take off.
            const allocations = [
                ...(checkout.discountAllocations ?? []),
                ...checkout.lines.edges.flatMap(({ node }) => node.discountAllocations ?? []),
            ];
            this.discountCodes = checkout.discountCodes ?? [];
            this.discountAmount = allocations.reduce(
                (total, allocation) => total + Number.parseFloat(allocation.discountedAmount.amount),
                0,
            );
            this.discount = this.formatCurrency(this.discountAmount);

            localStorage.setItem('statamic.shopify.cart.id', this.cartId);

            this.applyPendingDiscount();
```

`init()` calls `this.takeDiscountFromUrl()` before loading the cart, and the store gets
`discountCodes: []`, `discount: null`, `discountAmount: 0`.

Cart overlay, above the total row:

```antlers
              {{# Codes from discount links (?discount=, see takeDiscountFromUrl in
                  kreatif/shopify/alpine.js). One whose conditions the cart does not
                  meet stays on it and applies as soon as they are met. #}}
              <template x-if="Alpine.store('statamic.shopify.cart').discountCodes.length">
                <div class="k-secondary-book-base flex flex-col gap-1 border-t border-black-25 p-4 font-normal">
                  <template x-for="discount in Alpine.store('statamic.shopify.cart').discountCodes">
                    <div>
                      <div>{{ trans:labels.cart_discount_code }}: <span class="font-bold" x-text="discount.code"></span></div>
                      <div x-show="!discount.applicable" class="text-black-50">{{ trans:labels.cart_discount_not_applicable }}</div>
                    </div>
                  </template>
                  <div x-show="Alpine.store('statamic.shopify.cart').discountAmount > 0" class="flex items-center justify-between gap-4">
                    <div>{{ trans:labels.cart_discount_amount }}</div>
                    <div class="font-bold" x-text="'– ' + Alpine.store('statamic.shopify.cart').discount"></div>
                  </div>
                </div>
              </template>
```

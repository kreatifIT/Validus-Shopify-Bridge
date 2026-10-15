<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Takes in links to Shopify's own online store. Shopify is headless here, but
 * Shopify Email, order mails and newsletter tools still link products as
 * /products/{handle} on the online store. Its theme sends every request to
 * this site and only swaps the host name, so the links arrive with Shopify's
 * paths and would end on the 404 page.
 *
 * Products go to their page (the entry slug is the Shopify handle), with a
 * linked variant preselected the same way the shop's product cards do
 * (?year=option1&format=option2). Collections and the cart go to the shop.
 * Other query parameters, like utm_*, are kept.
 */
class ShopifyStorefrontController extends Controller
{
    public function product(Request $request): RedirectResponse
    {
        $handle = (string) $request->route('handle');
        $site = $this->site($request);
        $product = $this->productPage($handle, $site);

        if (! $product) {
            return $this->toShop($request, $site);
        }

        $query = Arr::except($request->query(), 'variant');

        if ($variant = $this->variant($request->query('variant'), $handle)) {
            $query = array_filter([
                'year' => $variant->get('option1'),
                'format' => $variant->get('option2'),
            ]) + $query;
        }

        return $this->redirectTo($product->url(), $query);
    }

    public function shop(Request $request): RedirectResponse
    {
        return $this->toShop($request, $this->site($request));
    }

    /**
     * Shopify's discount link, /discount/{code}?redirect=/products/…, should it
     * be pointed at this site. Shopify's own online store answers it itself and
     * hands the code over as ?discount=; this does the same. The cart applies
     * it (resources/js/kreatif/shopify/alpine.js).
     */
    public function discount(Request $request): RedirectResponse
    {
        $target = (string) $request->query('redirect', '');

        // Only paths on this site, never "//other.host" or "https://…"
        if (! str_starts_with($target, '/') || str_starts_with($target, '//') || str_contains($target, '\\')) {
            $target = Site::get($this->site($request))->url();
        }

        $separator = str_contains($target, '?') ? '&' : '?';

        return redirect()->to($target.$separator.http_build_query(['discount' => (string) $request->route('code')]), 302);
    }

    /**
     * From the /it or /en prefix. Route parameters are read by name: Laravel
     * hands them to the action by position, and the prefix is optional.
     */
    private function site(Request $request): string
    {
        return $request->route('site') ?? Site::default()->handle();
    }

    private function toShop(Request $request, string $site): RedirectResponse
    {
        $shop = Entry::findByUri('/shop', $site);

        return $this->redirectTo($shop?->url() ?? Site::get($site)->url(), Arr::except($request->query(), 'variant'));
    }

    /**
     * The product page in the given language, or none if it is not published there.
     */
    private function productPage(string $handle, string $site): ?EntryContract
    {
        $product = Entry::query()
            ->where('collection', 'products')
            ->where('site', Site::default()->handle())
            ->where('slug', $handle)
            ->first()
            ?->in($site);

        return $product?->published() ? $product : null;
    }

    /**
     * Shopify's numeric variant id is the slug of the variant entry. A variant
     * of another product is ignored.
     */
    private function variant(mixed $variantId, string $handle): ?EntryContract
    {
        if (! is_string($variantId) || ! ctype_digit($variantId)) {
            return null;
        }

        $variant = Entry::query()
            ->where('collection', 'variants')
            ->where('slug', $variantId)
            ->first();

        return $variant?->get('product_slug') === $handle ? $variant : null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function redirectTo(string $path, array $query): RedirectResponse
    {
        return redirect()->to($query ? $path.'?'.http_build_query($query) : $path, 301);
    }
}

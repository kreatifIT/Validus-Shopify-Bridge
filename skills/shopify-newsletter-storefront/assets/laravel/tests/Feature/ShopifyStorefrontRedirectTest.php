<?php

namespace Tests\Feature;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Tests\TestCase;

/**
 * Links to Shopify's online store arrive here with Shopify's paths. Works on
 * real products from content/, like ProductAwareInvalidatorTest.
 */
class ShopifyStorefrontRedirectTest extends TestCase
{
    public function test_a_product_link_goes_to_the_product_page(): void
    {
        $product = $this->product();

        $this->get('/products/'.$product->slug())
            ->assertMovedPermanently()
            ->assertRedirect($product->url());
    }

    public function test_a_product_link_keeps_its_language(): void
    {
        $product = $this->product();

        $this->get('/it/products/'.$product->slug())
            ->assertRedirect($product->in('it')->url());

        $this->get('/en/collections/all/products/'.$product->slug())
            ->assertRedirect($product->in('en')->url());
    }

    /** The same preselection the shop's product cards link with. */
    public function test_a_linked_variant_is_preselected_and_other_parameters_stay(): void
    {
        $variant = $this->variant();
        $product = $this->product($variant->get('product_slug'));

        $this->get('/products/'.$product->slug().'?variant='.$variant->slug().'&utm_source=shopify')
            ->assertRedirect($product->url().'?'.http_build_query(array_filter([
                'year' => $variant->get('option1'),
                'format' => $variant->get('option2'),
            ]) + ['utm_source' => 'shopify']));
    }

    public function test_a_variant_of_another_product_is_ignored(): void
    {
        $variant = $this->variant();
        $product = Entry::query()
            ->where('collection', 'products')
            ->where('site', 'de')
            ->where('published', true)
            ->where('slug', '!=', $variant->get('product_slug'))
            ->first();

        $this->get('/products/'.$product->slug().'?variant='.$variant->slug())
            ->assertRedirect($product->url());
    }

    public function test_an_unknown_product_goes_to_the_shop(): void
    {
        $this->get('/it/products/gibt-es-nicht')
            ->assertMovedPermanently()
            ->assertRedirect(Entry::findByUri('/shop', 'it')->url());
    }

    public function test_collections_and_the_cart_go_to_the_shop(): void
    {
        $this->get('/collections/all')->assertRedirect(Entry::findByUri('/shop', 'de')->url());
        $this->get('/en/collections')->assertRedirect(Entry::findByUri('/shop', 'en')->url());
        $this->get('/cart?utm_medium=email')->assertRedirect(Entry::findByUri('/shop', 'de')->url().'?utm_medium=email');
    }

    public function test_a_discount_link_hands_the_code_to_its_target(): void
    {
        $this->get('/discount/SOMMER10?redirect=%2Fproducts%2Fsome-wine')
            ->assertRedirect('/products/some-wine?discount=SOMMER10');

        $this->get('/discount/A%26B?redirect=%2Fshop%3Fx%3D1')
            ->assertRedirect('/shop?x=1&discount=A%26B');
    }

    public function test_a_discount_link_without_target_goes_to_the_home_page_of_its_language(): void
    {
        $this->get('/it/discount/SOMMER10')
            ->assertRedirect(Site::get('it')->url().'?discount=SOMMER10');
    }

    public function test_a_discount_link_never_leads_off_the_site(): void
    {
        foreach (['https://evil.example', '//evil.example', '/\\evil.example', 'evil.example'] as $target) {
            $this->get('/discount/SOMMER10?redirect='.urlencode($target))
                ->assertRedirect(Site::get('de')->url().'?discount=SOMMER10');
        }
    }

    public function test_only_the_sites_prefixes_are_taken(): void
    {
        $this->assertSame(['de', 'it', 'en'], Site::all()->keys()->all());

        $this->get('/fr/products/x')->assertNotFound();
    }

    private function product(?string $slug = null): ?EntryContract
    {
        return Entry::query()
            ->where('collection', 'products')
            ->where('site', 'de')
            ->where('published', true)
            ->when($slug, fn ($query) => $query->where('slug', $slug))
            ->get()
            ->first(fn (EntryContract $product) => $product->in('it')?->published() && $product->in('en')?->published());
    }

    /**
     * A variant with both options whose product is published in every language.
     */
    private function variant(): EntryContract
    {
        return Entry::query()
            ->where('collection', 'variants')
            ->get()
            ->first(fn (EntryContract $variant) => $variant->get('option1') && $variant->get('option2')
                && $this->product($variant->get('product_slug')));
    }
}

<?php

namespace Tests\Feature;

use Statamic\Facades\Site;
use Tests\TestCase;

class KeepQueryOnLocaleRedirectTest extends TestCase
{
    public function test_the_language_redirect_keeps_the_query(): void
    {
        $this->withHeader('Accept-Language', 'it-IT,it;q=0.9')
            ->get('/?discount=SOMMER10&utm_source=newsletter')
            ->assertRedirect(Site::get('it')->absoluteUrl().'?discount=SOMMER10&utm_source=newsletter');
    }

    public function test_without_query_the_redirect_stays_as_it_was(): void
    {
        $this->withHeader('Accept-Language', 'it-IT,it;q=0.9')
            ->get('/')
            ->assertRedirect(Site::get('it')->absoluteUrl());
    }

    /** Other redirects already carry their own query, or none on purpose. */
    public function test_other_redirects_are_left_alone(): void
    {
        $this->get('/cart?utm_medium=email')->assertRedirect('/shop?utm_medium=email');
        $this->get('/discount/SOMMER10?redirect=%2Fshop')->assertRedirect('/shop?discount=SOMMER10');
    }
}

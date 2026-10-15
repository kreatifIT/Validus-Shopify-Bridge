<?php

namespace App\Http\Controllers;

use App\Jobs\SubscribeToNewsletter;
use App\Support\NewsletterSignup;
use App\Support\ShopifyNewsletter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Statamic\Facades\Site;
use Statamic\View\View;

/**
 * The newsletter module's sign-up (layout/_newsletter.antlers.html) and the
 * confirmation link of its double opt-in mail, see NewsletterSignup.
 *
 * subscribe() only mails the link; confirm() hands the sign-up to Shopify
 * (SubscribeToNewsletter). reCAPTCHA is checked by VerifyRecaptcha before
 * subscribe() runs; both routes are rate-limited in routes/web.php. The texts
 * shown to the visitor live in the template, so the responses carry no message.
 */
class NewsletterController extends Controller
{
    /** Hidden field only bots fill in. */
    public const HONEYPOT = 'website';

    public function subscribe(Request $request, ShopifyNewsletter $shopify): JsonResponse
    {
        if ($request->filled(self::HONEYPOT)) {
            return response()->json(['subscribed' => true]);
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'consent' => ['accepted'],
            'site' => ['required', Rule::in(Site::all()->keys()->all())],
        ]);

        if (! $shopify->isConfigured()) {
            Log::warning('Newsletter sign-up not accepted: the Shopify connection is not configured');

            return response()->json(['subscribed' => false], 503);
        }

        try {
            (new NewsletterSignup($data['email'], $data['first_name'] ?? null, $data['last_name'] ?? null, $data['site']))
                ->requestConfirmation();
        } catch (\Throwable $e) {
            Log::error('Newsletter confirmation mail could not be sent: '.$e->getMessage(), ['site' => $data['site']]);

            return response()->json(['subscribed' => false], 503);
        }

        return response()->json(['subscribed' => true]);
    }

    /**
     * The link in the confirmation mail. Altered and expired links get a page
     * saying so instead of the bare 403.
     */
    public function confirm(Request $request): Response|RedirectResponse
    {
        try {
            $signup = NewsletterSignup::fromLink((string) $request->query('signup'));
        } catch (DecryptException) {
            return $this->refuseLink(Site::current()->handle());
        }

        // An expired link still says which language the visitor signed up in.
        if (! $request->hasValidSignature()) {
            return $this->refuseLink($signup->site);
        }

        SubscribeToNewsletter::dispatch($signup, now()->toIso8601String());

        return redirect()->away(
            config("services.newsletter.confirmed_urls.{$signup->site}") ?: Site::get($signup->site)->absoluteUrl()
        );
    }

    /**
     * errors/newsletter_link in the given site's language, with status 403.
     */
    private function refuseLink(string $site): Response
    {
        Site::setCurrent($site);
        app()->setLocale(Site::get($site)->lang());

        $page = (new View)
            ->template('errors/newsletter_link')
            ->layout('layout')
            ->with([
                'title' => __('strings.newsletter_link_title'),
                'home_url' => Site::get($site)->absoluteUrl(),
            ])
            ->render();

        return response($page, Response::HTTP_FORBIDDEN);
    }
}

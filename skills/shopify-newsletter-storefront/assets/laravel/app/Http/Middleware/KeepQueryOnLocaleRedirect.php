<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Statamic\Facades\Site;
use Symfony\Component\HttpFoundation\Response;

/**
 * locale-lander sends "/" to the home page of the browser's language, but
 * drops the query string on the way. Links to the home page lost their
 * utm_* parameters and the ?discount= code that Shopify's online store
 * hands over. This puts the query back onto that one redirect.
 */
class KeepQueryOnLocaleRedirect
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof RedirectResponse || $request->getQueryString() === null || ! $this->isHomePage($request->path())) {
            return $response;
        }

        $target = $response->getTargetUrl();

        if (parse_url($target, PHP_URL_QUERY) === null && $this->isHomePage((string) parse_url($target, PHP_URL_PATH))) {
            $response->setTargetUrl($target.'?'.$request->getQueryString());
        }

        return $response;
    }

    private function isHomePage(string $path): bool
    {
        $path = trim($path, '/');

        return Site::all()->contains(fn ($site) => trim((string) parse_url($site->absoluteUrl(), PHP_URL_PATH), '/') === $path);
    }
}

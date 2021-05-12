<?php

namespace Osiset\ShopifyApp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Response;
use Osiset\ShopifyApp\Util;

/**
 * Response for ensuring a proper webhook request.
 */
class AuthWebhook
{
    /**
     * Handle an incoming request to ensure webhook is valid.
     *
     * @param Request  $request The request object.
     * @param \Closure $next    The next action.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $hmac = $request->header('x-shopify-hmac-sha256') ?: '';
        $shop = $request->header('x-shopify-shop-domain');
        $data = $request->getContent();
        $hmacLocal = Util::createHmac(
            [
                'data'   => $data,
                'raw'    => true,
                'encode' => true,
            ],
            Util::getShopifyConfig('api_secret', $shop)
        );

        if (hash_equals($hmac, $hmacLocal) === false || empty($shop)) {
            // Issue with HMAC or missing shop header
            return Response::make('Invalid webhook signature.', HttpResponse::HTTP_UNAUTHORIZED);
        }

        // All good, process webhook
        return $next($request);
    }
}

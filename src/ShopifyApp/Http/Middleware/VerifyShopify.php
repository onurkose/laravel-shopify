<?php

namespace Osiset\ShopifyApp\Http\Middleware;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Assert\AssertionFailedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Osiset\ShopifyApp\Services\ShopSession;
use Osiset\ShopifyApp\Exceptions\HttpException;
use Osiset\ShopifyApp\Objects\Enums\DataSource;
use function Osiset\ShopifyApp\getShopifyConfig;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Objects\Values\SessionToken;
use Osiset\ShopifyApp\Objects\Values\NullShopDomain;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Exceptions\SignatureVerificationException;
use Osiset\ShopifyApp\Contracts\Objects\Values\ShopDomain as ShopDomainValue;
use Osiset\ShopifyApp\Objects\Values\NullableSessionId;

class VerifyShopify
{
    /**
     * The API helper.
     *
     * @var IApiHelper
     */
    protected $apiHelper;

    /**
     * The shop session helper.
     *
     * @var ShopSession
     */
    protected $shopSession;

    /**
     * Constructor.
     *
     * @param IApiHelper  $apiHelper   The API helper.
     * @param ShopSession $shopSession The shop session helper.
     *
     * @return void
     */
    public function __construct(IApiHelper $apiHelper, ShopSession $shopSession)
    {
        $this->shopSession = $shopSession;
        $this->apiHelper = $apiHelper;
        $this->apiHelper->make();
    }

    /**
     * Undocumented function
     *
     * @param Request $request The request object.
     * @param Closure $next    The next action.
     *
     * @throws SignatureVerificationException If HMAC verification fails.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Verify the HMAC (if available)
        $hmacResult = $this->verifyHmac($request);
        if ($hmacResult === false) {
            // Invalid HMAC
            throw new SignatureVerificationException('Unable to verify signature.');
        }

        // Get the token (if available)
        $tokenSource = $request->ajax() ? $request->bearerToken() : $request->all('token');
        if (empty($tokenSource)) {
            // Not available, we need to get one
            return $this->handleMissingToken($request);
        }

        try {
            // Try and process the token
            $token = SessionToken::fromNative($tokenSource);
        } catch (AssertionFailedException $e) {
            // Invalid or expired token, we need a new one
            return $this->handleInvalidToken($request, $e);
        }

        // Login the shop
        $loginResult = $this->loginShopFromToken($token);
        if (! $loginResult) {
            // Shop is not installed or something is missing from it's data
            return $this->handleInvalidShop($request);
        }

        // Verify incoming session token (if available)
        $tokenResult = $this->verifyShopifySessionToken($request);
        if (! $tokenResult) {
            // Invalid session token
            return $this->handleMissingToken($request);
        }

        // Everything appears to be good, add the session token to request user
        // TODO HERE

        return $next($request);
    }

    /**
     * Handle missing token.
     *
     * @param Request $request The request object.
     *
     * @throws HttpException If an AJAX/JSON request.
     *
     * @return mixed
     */
    protected function handleMissingToken(Request $request)
    {
        if ($request->ajax() || $request->expectsJson()) {
            // AJAX, return HTTP exception
            throw new HttpException(
                SessionToken::EXCEPTION_INVALID,
                Response::HTTP_BAD_REQUEST
            );
        }

        return $this->tokenRedirect(
            $this->getShopDomainFromRequest($request)->toNative()
        );
    }

    /**
     * Handle an invalid or expired token.
     *
     * @param Request                  $request The request object.
     * @param AssertionFailedException $e       The assertion failure exception.
     *
     * @throws HttpException If an AJAX/JSON request.
     *
     * @return mixed
     */
    protected function handleInvalidToken(Request $request, AssertionFailedException $e)
    {
        $isExpired = $e->getMessage() === SessionToken::EXCEPTION_EXPIRED;
        if ($request->ajax() || $request->expectsJson()) {
            // AJAX, return HTTP exception
            throw new HttpException(
                $e->getMessage(),
                $isExpired ? Response::HTTP_FORBIDDEN : Response::HTTP_BAD_REQUEST
            );
        }

        return $this->tokenRedirect(
            $this->getShopDomainFromRequest($request)->toNative()
        );
    }

    /**
     * Handle a shop that is not installed or it's data is invalid.
     *
     * @param Request $request The request object.
     *
     * @throws HttpException If an AJAX/JSON request.
     *
     * @return mixed
     */
    protected function handleInvalidShop(Request $request)
    {
        if ($request->ajax() || $request->expectsJson()) {
            // AJAX, return HTTP exception
            throw new HttpException('', Response::HTTP_FORBIDDEN);
        }

        return $this->installRedirect(
            $this->getShopDomainFromRequest($request)->toNative()
        );
    }

    /**
     * Verify HMAC data, if present.
     *
     * @param Request $request The request object.
     *
     * @throws SignatureVerificationException
     *
     * @return bool|null
     */
    protected function verifyHmac(Request $request): ?bool
    {
        $hmac = $this->getHmacFromRequest($request);
        if ($hmac['source'] === null) {
            // No HMAC, skip
            return null;
        }

        // We have HMAC, validate it
        $data = $this->getRequestData($request, $hmac['source']);
        return $this->apiHelper->verifyRequest($data);
    }

    /**
     * Grab the shop, if present, and how it was found.
     * Order of precedence is:.
     *
     *  - GET/POST Variable
     *  - Headers
     *  - Referer
     *
     * @param Request $request The request object.
     *
     * @return ShopDomainValue
     */
    protected function getShopDomainFromRequest(Request $request): ShopDomainValue
    {
        // All possible methods
        $options = [
            // GET/POST
            DataSource::INPUT()->toNative() => $request->input('shop'),
            // Headers
            DataSource::HEADER()->toNative() => $request->header('X-Shop-Domain'),
            // Headers: Referer
            DataSource::REFERER()->toNative() => function () use ($request): ?string {
                $url = parse_url($request->header('referer'), PHP_URL_QUERY);
                parse_str($url, $refererQueryParams);

                return Arr::get($refererQueryParams, 'shop');
            },
        ];

        // Loop through each until we find the HMAC
        foreach ($options as $method => $value) {
            $result = is_callable($value) ? $value() : $value;
            if ($result !== null) {
                // Found a shop
                return ShopDomain::fromNative($result);
            }
        }

        // No shop domain found in any source
        return NullShopDomain::fromNative(null);
    }

    /**
     * Grab the HMAC value, if present, and how it was found.
     * Order of precedence is:.
     *
     *  - GET/POST Variable
     *  - Headers
     *  - Referer
     *
     * @param Request $request The request object.
     *
     * @return array
     */
    protected function getHmacFromRequest(Request $request): array
    {
        // All possible methods
        $options = [
            // GET/POST
            DataSource::INPUT()->toNative() => $request->input('hmac'),
            // Headers
            DataSource::HEADER()->toNative() => $request->header('X-Shop-Signature'),
            // Headers: Referer
            DataSource::REFERER()->toNative() => function () use ($request): ?string {
                $url = parse_url($request->header('referer'), PHP_URL_QUERY);
                parse_str($url, $refererQueryParams);
                if (! $refererQueryParams || ! isset($refererQueryParams['hmac'])) {
                    return null;
                }

                return $refererQueryParams['hmac'];
            },
        ];

        // Loop through each until we find the HMAC
        foreach ($options as $method => $value) {
            $result = is_callable($value) ? $value() : $value;
            if ($result !== null) {
                return ['source' => $method, 'value' => $value];
            }
        }

        return ['source' => null, 'value' => null];
    }

    /**
     * Grab the request data.
     *
     * @param Request $request The request object.
     * @param string  $source  The source of the data.
     *
     * @return array
     */
    protected function getRequestData(Request $request, string $source): array
    {
        // All possible methods
        $options = [
            // GET/POST
            DataSource::INPUT()->toNative() => function () use ($request): array {
                // Verify
                $verify = [];
                foreach ($request->query() as $key => $value) {
                    $verify[$key] = $this->parseDataSourceValue($value);
                }

                return $verify;
            },
            // Headers
            DataSource::HEADER()->toNative() => function () use ($request): array {
                // Always present
                $shop = $request->header('X-Shop-Domain');
                $signature = $request->header('X-Shop-Signature');
                $timestamp = $request->header('X-Shop-Time');

                $verify = [
                    'shop'      => $shop,
                    'hmac'      => $signature,
                    'timestamp' => $timestamp,
                ];

                // Sometimes present
                $code = $request->header('X-Shop-Code') ?? null;
                $locale = $request->header('X-Shop-Locale') ?? null;
                $state = $request->header('X-Shop-State') ?? null;
                $id = $request->header('X-Shop-ID') ?? null;
                $ids = $request->header('X-Shop-IDs') ?? null;

                foreach (compact('code', 'locale', 'state', 'id', 'ids') as $key => $value) {
                    if ($value) {
                        $verify[$key] = $this->parseDataSourceValue($value);
                    }
                }

                return $verify;
            },
            // Headers: Referer
            DataSource::REFERER()->toNative() => function () use ($request): array {
                $url = parse_url($request->header('referer'), PHP_URL_QUERY);
                parse_str($url, $refererQueryParams);

                // Verify
                $verify = [];
                foreach ($refererQueryParams as $key => $value) {
                    $verify[$key] = $this->parseDataSourceValue($value);
                }

                return $verify;
            },
        ];

        return $options[$source]();
    }

    /**
     * Login and verify the shop and it's data.
     *
     * @param SessionToken $token The session token.
     *
     * @return bool
     */
    protected function loginShopFromToken(SessionToken $token): bool
    {
        // Log the shop in and check validity
        $status = $this->shopSession->make($token->getShopDomain());
        $this->shopSession->setSessionToken($token->getSessionId());
        return $status && $this->shopSession->isValid();
    }

    /**
     * Check the Shopify session token.
     *
     * @param Request $request The request object.
     *
     * @return bool
     */
    protected function verifyShopifySessionToken(Request $request): bool
    {
        // Ensure Shopify session token is OK
        $incomingToken = NullableSessionId::fromNative($request->query('session'));
        if (! $incomingToken->isNull()) {
            if ($this->shopSession->isSessionTokenValid($incomingToken)) {
                // Save the session token
                $this->shopSession->setSessionToken($incomingToken);
            }

            return true;
        }

        return false;
    }

    /**
     * Redirect to token route.
     *
     * @param ShopDomainValue $shopDomain The shop domain.
     *
     * @return RedirectResponse
     */
    protected function tokenRedirect(ShopDomainValue $shopDomain): RedirectResponse
    {
        return Redirect::route(
            getShopifyConfig('route_names.authenticate.token'),
            ['shop' => $shopDomain]
        );
    }

    /**
     * Redirect to install route.
     *
     * @param ShopDomainValue $shopDomain The shop domain.
     *
     * @return RedirectResponse
     */
    protected function installRedirect(ShopDomainValue $shopDomain): RedirectResponse
    {
        return Redirect::route(
            getShopifyConfig('route_names.authenticate.install'),
            ['shop' => $shopDomain]
        );
    }

    /**
     * Parse the data source value.
     * Handle simple key/values, arrays, and nested arrays.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function parseDataSourceValue($value): string
    {
        /**
         * Format the value.
         *
         * @param mixed $val
         *
         * @return string
         */
        $formatValue = function ($val): string {
            return is_array($val) ? '["'.implode('", "', $val).'"]' : $val;
        };

        // Nested array
        if (is_array($value) && is_array(current($value))) {
            return implode(', ', array_map($formatValue, $value));
        }

        // Array or basic value
        return $formatValue($value);
    }
}

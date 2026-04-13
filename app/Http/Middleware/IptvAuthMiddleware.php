<?php

namespace App\Http\Middleware;

use App\Models\IptvAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to authenticate IPTV clients via username + password query/POST params.
 *
 * Reads credentials from the request, validates against iptv_accounts,
 * and attaches the resolved account to the request attributes.
 */
class IptvAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $username = $request->input('username');
        $password = $request->input('password');

        if (! $username || ! $password) {
            return response('Invalid credentials', 401)
                ->header('Content-Type', 'text/plain');
        }

        $account = IptvAccount::where('username', $username)
            ->where('password', $password)
            ->first();

        if (! $account) {
            return response('Invalid credentials', 401)
                ->header('Content-Type', 'text/plain');
        }

        if ($account->status === 'suspended') {
            return response('Account suspended', 403)
                ->header('Content-Type', 'text/plain');
        }

        if ($account->isExpired()) {
            return response('Account expired', 403)
                ->header('Content-Type', 'text/plain');
        }

        $request->attributes->set('iptv_account', $account);

        return $next($request);
    }
}

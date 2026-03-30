<?php

namespace App\Http\Middleware;

use App\Models\IptvUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class XtreamAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Extract credentials from query string or POST body
        $username = $request->input('username');
        $password = $request->input('password');

        // If credentials missing, return unauthorized
        if (!$username || !$password) {
            return response()->json([
                'user_info' => ['auth' => 0],
            ], 401);
        }

        // Look up user
        $user = IptvUser::where('username', $username)
            ->where('password', $password)
            ->first();

        // Validate user
        if (!$user) {
            return response()->json([
                'user_info' => ['auth' => 0],
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'user_info' => ['auth' => 0],
                'message' => 'Account is inactive',
            ], 401);
        }

        if ($user->isExpired()) {
            return response()->json([
                'user_info' => ['auth' => 0],
                'message' => 'Account has expired',
            ], 401);
        }

        // Bind authenticated user to request
        $request->merge(['iptv_user' => $user]);

        return $next($request);
    }
}

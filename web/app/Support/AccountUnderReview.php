<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AccountUnderReview
{
    public const MESSAGE = 'Your account is under review. You can access your workspace once your registration is approved.';

    public static function response(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return response()->view('Auth.account-under-review', ['message' => self::MESSAGE], 403)
            ->header('Cache-Control', 'no-store, private');
    }
}

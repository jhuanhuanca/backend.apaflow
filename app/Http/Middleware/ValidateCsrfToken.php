<?php

namespace App\Http\Middleware;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as BaseValidateCsrfToken;

/**
 * SPA + Axios: X-XSRF-TOKEN puede ir cifrado (valor de cookie cifrada) o en claro
 * (cookie XSRF-TOKEN sin cifrar). El middleware por defecto solo intenta decrypt;
 * si falla, comparamos con el token de sesión para evitar 419 falsos.
 */
class ValidateCsrfToken extends BaseValidateCsrfToken
{
    protected function getTokenFromRequest($request)
    {
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');

        if ($token || ! $header = $request->header('X-XSRF-TOKEN')) {
            return $token;
        }

        try {
            return CookieValuePrefix::remove($this->encrypter->decrypt($header, static::serialized()));
        } catch (DecryptException) {
            $plain = urldecode($header);
            $sessionToken = $request->session()->token();

            return is_string($sessionToken) && is_string($plain) && hash_equals($sessionToken, $plain)
                ? $sessionToken
                : '';
        }
    }
}

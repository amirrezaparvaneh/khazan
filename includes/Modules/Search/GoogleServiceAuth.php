<?php
/**
 * Google service-account authentication (signed JWT for the OAuth token endpoint). The pure parts need no WordPress.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Search;

if (!defined('ABSPATH')) {
    exit;
}

final class GoogleServiceAuth {

    const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    const SCOPE_READONLY = 'https://www.googleapis.com/auth/webmasters.readonly';

    public static function b64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Reads the downloaded key file. Returns array(client_email, private_key, token_uri) or null when it is not a service-account key. */
    public static function parse_key($json) {
        $data = json_decode((string) $json, true);
        if (!is_array($data)) return null;
        $email = isset($data['client_email']) ? trim((string) $data['client_email']) : '';
        $key = isset($data['private_key']) ? (string) $data['private_key'] : '';
        if ($email === '' || strpos($email, '@') === false || strpos($key, 'PRIVATE KEY') === false) return null;
        if (isset($data['type']) && $data['type'] !== 'service_account') return null;
        $uri = isset($data['token_uri']) && is_string($data['token_uri']) && strpos($data['token_uri'], 'https://') === 0 ? $data['token_uri'] : self::TOKEN_URI;
        return array('client_email' => $email, 'private_key' => $key, 'token_uri' => $uri);
    }

    /** Signed RS256 assertion. Returns null when the private key cannot be used. */
    public static function jwt(array $key, $scope, $now) {
        $header = self::b64url(json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $claims = self::b64url(json_encode(array(
            'iss' => $key['client_email'], 'scope' => $scope, 'aud' => $key['token_uri'],
            'iat' => (int) $now, 'exp' => (int) $now + 3600,
        )));
        $input = $header . '.' . $claims;
        $signature = '';
        $pkey = @openssl_pkey_get_private($key['private_key']);
        if (!$pkey || !@openssl_sign($input, $signature, $pkey, OPENSSL_ALGO_SHA256)) return null;
        return $input . '.' . self::b64url($signature);
    }
}

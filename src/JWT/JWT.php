<?php

declare(strict_types=1);

namespace App\JWT;

use DomainException;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Matches Brizy Cloud AppBundle\JWT\JWT (Multipass-compatible decode).
 */
class JWT
{
    public static int $leeway = 0;

    public static string $alg = 'SHA256';

    public static string $enc_alg = 'AES-128-CBC';

    public static function decode($jwt, $signature_key, $encryption_key)
    {
        if (empty($signature_key)) {
            throw new InvalidArgumentException('Key may not be empty');
        }

        $tks = explode('.', $jwt);
        if (count($tks) != 2) {
            throw new UnexpectedValueException('Wrong number of segments');
        }

        [$bodyb64, $cryptob64] = $tks;

        if (null === $payload = JWT::jsonDecode(JWT::decrypt(JWT::urlsafeB64Decode($bodyb64), $encryption_key))) {
            throw new UnexpectedValueException('Invalid claims encoding');
        }
        $sig = JWT::urlsafeB64Decode($cryptob64);

        if (!JWT::verify("$bodyb64", $sig, $signature_key, self::$alg)) {
            throw new SignatureInvalidException('Signature verification failed');
        }

        if (isset($payload->exp)) {
            $exp = (int) $payload->exp;
            if (time() > $exp) {
                throw new UnexpectedValueException('Expired token');
            }
        }

        return $payload;
    }

    public static function encode($payload, $signature_key, $encryption_key)
    {
        $segments = [];

        $segments[] = JWT::urlsafeB64Encode(JWT::encrypt(JWT::jsonEncode($payload), $encryption_key));
        $signing_input = implode('.', $segments);

        $signature = JWT::sign($signing_input, $signature_key, self::$alg);
        $segments[] = JWT::urlsafeB64Encode($signature);

        return implode('.', $segments);
    }

    public static function sign($msg, $key, $alg = 'HS256')
    {
        return hash_hmac($alg, $msg, $key, true);
    }

    private static function verify($msg, $signature, $key, $alg)
    {
        $hash = hash_hmac($alg, $msg, $key, true);
        if (function_exists('hash_equals')) {
            return hash_equals($signature, $hash);
        }
        $len = min(self::safeStrlen($signature), self::safeStrlen($hash));

        $status = 0;
        for ($i = 0; $i < $len; $i++) {
            $status |= (ord($signature[$i]) ^ ord($hash[$i]));
        }
        $status |= (self::safeStrlen($signature) ^ self::safeStrlen($hash));

        return ($status === 0);
    }

    public static function jsonDecode($input)
    {
        if (version_compare(PHP_VERSION, '5.4.0', '>=') && !(defined('JSON_C_VERSION') && PHP_INT_SIZE > 4)) {
            $obj = json_decode($input, false, 512, JSON_BIGINT_AS_STRING);
        } else {
            $max_int_length = strlen((string) PHP_INT_MAX) - 1;
            $json_without_bigints = preg_replace('/:\s*(-?\d{'.$max_int_length.',})/', ': "$1"', $input);
            $obj = json_decode($json_without_bigints);
        }

        if (function_exists('json_last_error') && $errno = json_last_error()) {
            JWT::handleJsonError($errno);
        } elseif ($obj === null && $input !== 'null') {
            throw new DomainException('Null result with non-null input');
        }

        return $obj;
    }

    public static function jsonEncode($input)
    {
        $json = json_encode($input);
        if (function_exists('json_last_error') && $errno = json_last_error()) {
            JWT::handleJsonError($errno);
        } elseif ($json === 'null' && $input !== null) {
            throw new DomainException('Null result with non-null input');
        }

        return $json;
    }

    public static function urlsafeB64Decode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }

        return base64_decode(strtr($input, '-_', '+/'));
    }

    public static function urlsafeB64Encode($input)
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    private static function handleJsonError($errno)
    {
        $messages = [
            JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
            JSON_ERROR_CTRL_CHAR => 'Unexpected control character found',
            JSON_ERROR_SYNTAX => 'Syntax error, malformed JSON',
        ];
        throw new DomainException(
            isset($messages[$errno])
                ? $messages[$errno]
                : 'Unknown JSON error: '.$errno
        );
    }

    private static function safeStrlen($str)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($str, '8bit');
        }

        return strlen($str);
    }

    public static function encrypt($json_payload, $encryption_key)
    {
        return openssl_encrypt($json_payload, self::$enc_alg, $encryption_key, OPENSSL_RAW_DATA, self::getInitVector($encryption_key));
    }

    public static function decrypt($json_payload, $encryption_key)
    {
        return openssl_decrypt($json_payload, self::$enc_alg, $encryption_key, OPENSSL_RAW_DATA, self::getInitVector($encryption_key));
    }

    public static function getSignatureKey($secret_key)
    {
        $key_material = hash(self::$alg, $secret_key, true);

        return substr($key_material, 16, 16);
    }

    public static function getEncryptionKey($secret_key)
    {
        $key_material = hash(self::$alg, $secret_key, true);

        return substr($key_material, 0, 16);
    }

    public static function getInitVector($encryption_key)
    {
        $key_material = hash(self::$alg, $encryption_key, true);

        return substr($key_material, 0, 16);
    }
}

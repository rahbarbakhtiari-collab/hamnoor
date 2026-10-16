<?php
/**
 * Config-free P-256 private-key generator for Hamnoor Web Push.
 *
 * Some Windows/LocalWP PHP/OpenSSL builds fail inside the native EC key-generation
 * API when it tries to resolve an external OpenSSL configuration file. To keep Web
 * Push portable without weakening validation, this helper generates a valid
 * secp256r1 private scalar with random_bytes(), wraps it in the standard SEC1
 * ECPrivateKey DER structure, and immediately asks OpenSSL to parse and expose
 * the public coordinates. Signing and ECDH remain OpenSSL operations.
 *
 * This class deliberately has no WordPress dependency so the release-gate
 * preflight can execute the exact same key-generation path before activation.
 */
final class Hamnoor_P256_Keygen {
    private const ORDER_HEX = 'FFFFFFFF00000000FFFFFFFFFFFFFFFFBCE6FAADA7179E84F3B9CAC2FC632551';
    private const PRIME256V1_OID_DER = "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";

    private static function compare_unsigned_bytes($a, $b) {
        $lenA = strlen($a); $lenB = strlen($b);
        if ($lenA !== $lenB) { return $lenA < $lenB ? -1 : 1; }
        for ($i = 0; $i < $lenA; $i++) {
            $x = ord($a[$i]); $y = ord($b[$i]);
            if ($x === $y) { continue; }
            return $x < $y ? -1 : 1;
        }
        return 0;
    }

    private static function nonzero($bytes) {
        return '' !== trim((string)$bytes, "\x00");
    }

    private static function random_scalar() {
        if (!function_exists('random_bytes')) { return false; }
        $order = hex2bin(self::ORDER_HEX);
        if (false === $order) { return false; }
        for ($i = 0; $i < 128; $i++) {
            try { $candidate = random_bytes(32); }
            catch (Throwable $e) { return false; }
            if (!self::nonzero($candidate)) { continue; }
            if (self::compare_unsigned_bytes($candidate, $order) < 0) { return $candidate; }
        }
        return false;
    }

    private static function scalar_to_sec1_pem($scalar) {
        if (!is_string($scalar) || 32 !== strlen($scalar)) { return ''; }
        // RFC 5915 ECPrivateKey:
        // SEQUENCE { version=1, privateKey OCTET STRING,
        //            parameters [0] OBJECT IDENTIFIER prime256v1 }
        $der = "\x30\x31\x02\x01\x01\x04\x20" . $scalar . "\xA0\x0A" . self::PRIME256V1_OID_DER;
        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
    }

    private static function valid_details($details) {
        return is_array($details)
            && isset($details['ec']) && is_array($details['ec'])
            && 'prime256v1' === (string)($details['ec']['curve_name'] ?? '')
            && 32 === strlen((string)($details['ec']['x'] ?? ''))
            && 32 === strlen((string)($details['ec']['y'] ?? ''));
    }

    public static function generate() {
        if (!extension_loaded('openssl') || !function_exists('openssl_pkey_get_private') || !function_exists('openssl_pkey_get_details')) {
            return array('ok'=>false, 'error'=>'OpenSSL EC key parsing functions are unavailable.');
        }
        $scalar = self::random_scalar();
        if (false === $scalar) { return array('ok'=>false, 'error'=>'Could not generate a valid P-256 private scalar.'); }
        $pem = self::scalar_to_sec1_pem($scalar);
        if ('' === $pem) { return array('ok'=>false, 'error'=>'Could not encode the P-256 private key.'); }

        $key = @openssl_pkey_get_private($pem);
        if (!$key) { return array('ok'=>false, 'error'=>'OpenSSL rejected the generated P-256 SEC1 key.'); }
        $details = @openssl_pkey_get_details($key);
        if (!self::valid_details($details)) { return array('ok'=>false, 'error'=>'OpenSSL did not expose valid prime256v1 public coordinates.'); }

        return array(
            'ok'=>true,
            'private_pem'=>$pem,
            'key'=>$key,
            'details'=>$details,
            'method'=>'sec1_random_scalar',
        );
    }
}

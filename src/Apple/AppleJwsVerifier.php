<?php

namespace Vipertecpro\MobileEntitlements\Apple;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use OpenSSLCertificate;
use Throwable;
use Vipertecpro\MobileEntitlements\Exceptions\VerificationException;

/**
 * Verifies App Store JWS values (signedPayload, signedTransactionInfo, signedRenewalInfo).
 *
 * Rules: ES256 only; the x5c header must hold exactly leaf, intermediate and root; the leaf and
 * intermediate must chain to a configured trusted root (Apple Root CA - G3 by default) and be valid
 * now; the leaf must carry OID 1.2.840.113635.100.6.11.1 and the intermediate OID
 * 1.2.840.113635.100.6.2.1; the signature must verify with the leaf's public key.
 */
class AppleJwsVerifier
{
    public const LEAF_OID = '1.2.840.113635.100.6.11.1';

    public const INTERMEDIATE_OID = '1.2.840.113635.100.6.2.1';

    /**
     * @param  list<string>  $rootCertificatePaths
     */
    public function __construct(private array $rootCertificatePaths) {}

    /**
     * @return array<string, mixed>
     *
     * @throws VerificationException
     */
    public function verify(string $jws): array
    {
        $segments = explode('.', $jws);

        if (count($segments) !== 3) {
            throw new VerificationException('Malformed JWS.');
        }

        $header = $this->decodeSegment($segments[0]);

        if (($header['alg'] ?? null) !== 'ES256') {
            throw new VerificationException('JWS algorithm must be ES256.');
        }

        $chain = $header['x5c'] ?? null;

        if (! is_array($chain) || count($chain) !== 3 || ! array_is_list($chain)) {
            throw new VerificationException('JWS x5c header must contain exactly three certificates.');
        }

        [$leafPem, $intermediatePem] = [$this->toPem($chain[0]), $this->toPem($chain[1])];
        $leaf = $this->readCertificate($leafPem);
        $intermediate = $this->readCertificate($intermediatePem);

        $this->assertChainsToTrustedRoot($leaf, $leafPem, $intermediate, $intermediatePem);
        $this->assertHasExtension($leaf, self::LEAF_OID, 'leaf');
        $this->assertHasExtension($intermediate, self::INTERMEDIATE_OID, 'intermediate');

        $publicKey = openssl_pkey_get_public($leaf);

        if ($publicKey === false) {
            throw new VerificationException('Leaf certificate has no usable public key.');
        }

        try {
            $payload = JWT::decode($jws, new Key($publicKey, 'ES256'));
        } catch (Throwable $exception) {
            throw new VerificationException('JWS signature did not verify: '.$exception->getMessage(), 0, $exception);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) json_encode($payload), true, 512, JSON_BIGINT_AS_STRING);

        return $decoded;
    }

    /**
     * Read the unverified payload. Only for logging something that already failed verification.
     *
     * @return array<string, mixed>|null
     */
    public static function peek(string $jws): ?array
    {
        $segments = explode('.', $jws);

        if (count($segments) !== 3) {
            return null;
        }

        $decoded = json_decode(JWT::urlsafeB64Decode($segments[1]), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function assertChainsToTrustedRoot(
        OpenSSLCertificate $leaf,
        string $leafPem,
        OpenSSLCertificate $intermediate,
        string $intermediatePem,
    ): void {
        $roots = $this->trustedRoots();
        $now = time();

        foreach (['leaf' => $leaf, 'intermediate' => $intermediate] as $name => $certificate) {
            $info = openssl_x509_parse($certificate);

            if (! is_array($info) || $now < (int) $info['validFrom_time_t'] || $now > (int) $info['validTo_time_t']) {
                throw new VerificationException("The {$name} certificate is not valid at this time.");
            }
        }

        if (openssl_x509_verify($leaf, $intermediate) !== 1) {
            throw new VerificationException('Leaf certificate is not signed by the intermediate.');
        }

        $signedByTrustedRoot = false;

        foreach ($roots as $root) {
            if (openssl_x509_verify($intermediate, $root) === 1) {
                $signedByTrustedRoot = true;

                break;
            }
        }

        if (! $signedByTrustedRoot) {
            throw new VerificationException('Intermediate certificate does not chain to a trusted root.');
        }

        // Full path validation by OpenSSL (basic constraints, validity, signatures) with only the
        // configured roots trusted and the x5c intermediate treated as untrusted.
        $untrusted = tempnam(sys_get_temp_dir(), 'me-x5c-');

        if ($untrusted === false) {
            throw new VerificationException('Could not create a temporary file for chain validation.');
        }

        try {
            file_put_contents($untrusted, $intermediatePem);
            $valid = openssl_x509_checkpurpose($leafPem, X509_PURPOSE_ANY, $this->rootCertificatePaths, $untrusted);
        } finally {
            @unlink($untrusted);
        }

        if ($valid !== true) {
            throw new VerificationException('Certificate chain failed OpenSSL path validation.');
        }
    }

    /**
     * @return list<OpenSSLCertificate>
     */
    private function trustedRoots(): array
    {
        $roots = [];

        foreach ($this->rootCertificatePaths as $path) {
            $contents = is_readable($path) ? file_get_contents($path) : false;
            $certificate = $contents === false ? false : openssl_x509_read($contents);

            if ($certificate === false) {
                throw new VerificationException("Trusted root certificate [{$path}] could not be read.");
            }

            $roots[] = $certificate;
        }

        if ($roots === []) {
            throw new VerificationException('No trusted root certificates are configured.');
        }

        return $roots;
    }

    private function assertHasExtension(OpenSSLCertificate $certificate, string $oid, string $name): void
    {
        $info = openssl_x509_parse($certificate, false);

        if (! is_array($info) || ! array_key_exists($oid, $info['extensions'] ?? [])) {
            throw new VerificationException("The {$name} certificate is missing OID {$oid}.");
        }
    }

    private function readCertificate(string $pem): OpenSSLCertificate
    {
        $certificate = @openssl_x509_read($pem);

        if ($certificate === false) {
            throw new VerificationException('x5c contains an unreadable certificate.');
        }

        return $certificate;
    }

    private function toPem(mixed $der): string
    {
        if (! is_string($der) || $der === '' || base64_decode($der, true) === false) {
            throw new VerificationException('x5c entries must be base64 DER certificates.');
        }

        return "-----BEGIN CERTIFICATE-----\n".chunk_split($der, 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSegment(string $segment): array
    {
        try {
            $decoded = json_decode(JWT::urlsafeB64Decode($segment), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new VerificationException('JWS header is not valid JSON.');
        }

        if (! is_array($decoded)) {
            throw new VerificationException('JWS header is not an object.');
        }

        return $decoded;
    }
}

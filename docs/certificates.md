# Certificates

## Apple Root CA - G3 (production trust anchor)

- File: `resources/certs/AppleRootCA-G3.pem`
- Source: https://www.apple.com/certificateauthority/AppleRootCA-G3.cer (linked from https://www.apple.com/certificateauthority/)
- Downloaded and converted on 2026-10-07 with:

  ```sh
  curl -sSfL -o AppleRootCA-G3.cer https://www.apple.com/certificateauthority/AppleRootCA-G3.cer
  openssl x509 -inform DER -in AppleRootCA-G3.cer -out resources/certs/AppleRootCA-G3.pem
  ```

- Subject: `CN=Apple Root CA - G3, OU=Apple Certification Authority, O=Apple Inc., C=US`
- Validity: 2014-04-30 18:19:06 GMT to 2039-04-30 18:19:06 GMT
- SHA-256 fingerprint (of the DER certificate):
  `63:34:3A:BF:B8:9A:6A:03:EB:B5:7E:9B:3F:5F:A7:BE:7C:4F:5C:75:6F:30:17:B3:A8:C4:88:C3:65:3E:91:79`

`tests/Feature/AppleSignatureTest.php` asserts this fingerprint, so a swapped or corrupted
root fails the suite.

## Test-only chain

`tests/fixtures/apple/certs/` holds a throwaway root, intermediates and leaves (P-256) used only by
the tests, including deliberately broken ones (no OID, expired, untrusted root). Regenerate them
with `tests/fixtures/apple/certs/generate.sh` (OpenSSL 3.4+). They are never trusted outside the
tests: the test suite swaps `mobile-entitlements.apple.root_certificates` to the test root.

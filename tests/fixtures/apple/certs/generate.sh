#!/usr/bin/env bash
# Regenerates the TEST-ONLY certificate chain used by the Pest suite. Requires OpenSSL 3.4+.
# None of these keys or certificates are trusted outside the tests.
set -euo pipefail
cd "$(dirname "$0")"
rm -f *.pem *.key *.srl *.cnf

cat > ext.cnf <<'CNF'
[ca_ext]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash

[intermediate_ext]
basicConstraints = critical, CA:TRUE, pathlen:0
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
1.2.840.113635.100.6.2.1 = ASN1:NULL

[intermediate_no_oid_ext]
basicConstraints = critical, CA:TRUE, pathlen:0
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid

[leaf_ext]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
1.2.840.113635.100.6.11.1 = ASN1:NULL

[leaf_no_oid_ext]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
CNF

key() { openssl ecparam -name prime256v1 -genkey -noout -out "$1.key"; }
root() { key "$1"; openssl req -x509 -new -key "$1.key" -sha256 -days 36500 -subj "/CN=$2/O=mobile-entitlements tests" -extensions ca_ext -config <(cat /dev/null; echo '[req]'; echo 'distinguished_name=dn'; echo '[dn]'; cat ext.cnf) -out "$1.pem"; }
issue() { # name cn issuer ext key [notbefore notafter]
  openssl req -new -key "$5.key" -subj "/CN=$2/O=mobile-entitlements tests" -out "$1.csr"
  local dates=(-days 36500)
  if [ $# -ge 7 ]; then dates=(-not_before "$6" -not_after "$7"); fi
  openssl x509 -req -in "$1.csr" -CA "$3.pem" -CAkey "$3.key" -CAcreateserial -sha256 "${dates[@]}" -extfile ext.cnf -extensions "$4" -out "$1.pem"
  rm -f "$1.csr"
}

root root "Test Root CA"
key intermediate
issue intermediate "Test WWDR Intermediate" root intermediate_ext intermediate
key intermediate-no-oid
issue intermediate-no-oid "Test Intermediate Without OID" root intermediate_no_oid_ext intermediate-no-oid
key leaf
issue leaf "Test Prod ECC Mac App Store and iTunes Store Receipt Signing" intermediate leaf_ext leaf
issue leaf-no-oid "Test Leaf Without OID" intermediate leaf_no_oid_ext leaf
issue leaf-expired "Test Expired Leaf" intermediate leaf_ext leaf 20200101000000Z 20210101000000Z
issue leaf-under-no-oid-intermediate "Test Leaf Under Intermediate Without OID" intermediate-no-oid leaf_ext leaf

root other-root "Untrusted Root CA"
key other-intermediate
issue other-intermediate "Untrusted Intermediate" other-root intermediate_ext other-intermediate
key other-leaf
issue other-leaf "Untrusted Leaf" other-intermediate leaf_ext other-leaf

rm -f *.srl ext.cnf

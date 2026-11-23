#!/bin/bash

DOMINIOS="$*"

mkdir -p traefik/certs
cd traefik/certs || exit

rm -f cert.crt
rm -f cert.key

mkcert -cert-file cert.crt -key-file cert.key $DOMINIOS

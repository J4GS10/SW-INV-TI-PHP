# =========================================================================
# Doble de Active Directory para las pruebas: controlador de dominio Samba AD
# (gratuito). A diferencia de OpenLDAP implementa lo que usa la integración:
# objectGUID, userAccountControl, memberOf con la regla de membresía transitiva
# (LDAP_MATCHING_RULE_IN_CHAIN), resultados paginados y bind simple solo sobre
# canal cifrado (LDAPS en :636 o StartTLS en :389), como un AD real.
#
# Dominio PRUEBAS.LOCAL, DC dc1.pruebas.local, certificado autofirmado de Samba
# (su CA queda en /var/lib/samba/private/tls/ca.pem). Nunca se usa el AD real.
#
#   docker build --secret id=ca_corporativa,src=<pem> -t inventario-samba-ad-pruebas docker/pruebas/samba-ad
# =========================================================================
FROM debian:bookworm-slim

# Paquetes por HTTPS, verificados: con las CA públicas y, en una red con
# inspección TLS, además con la CA corporativa (secreto de BuildKit, solo en
# este paso; no queda en la imagen).
COPY --from=dunglas/frankenphp:1-php8.5-bookworm /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-publicas.crt
RUN --mount=type=secret,id=ca_corporativa,required=false \
    cat /etc/ssl/certs/ca-publicas.crt > /tmp/ca.pem \
    && if [ -s /run/secrets/ca_corporativa ]; then cat /run/secrets/ca_corporativa >> /tmp/ca.pem; fi \
    && sed -i 's#http://deb.debian.org#https://deb.debian.org#g' /etc/apt/sources.list.d/debian.sources \
    && apt-get -o Acquire::https::CaInfo=/tmp/ca.pem -o Acquire::Retries=3 update \
    && DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::https::CaInfo=/tmp/ca.pem -o Acquire::Retries=3 \
        install -y --no-install-recommends samba samba-ad-provision samba-ad-dc winbind krb5-user ldb-tools \
    && rm -rf /var/lib/apt/lists/* /tmp/ca.pem \
    && rm -f /etc/samba/smb.conf

COPY iniciar.sh /usr/local/bin/iniciar.sh
RUN chmod +x /usr/local/bin/iniciar.sh

EXPOSE 389 636
HEALTHCHECK --interval=5s --timeout=3s --start-period=60s --retries=30 \
    CMD test -f /var/lib/samba/.semilla_ok
ENTRYPOINT ["/usr/local/bin/iniciar.sh"]

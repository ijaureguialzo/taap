#!/bin/sh
# El contenedor arranca como root para poder chown los volúmenes montados;
# squid degrada a `cache_effective_user squid` tras el arranque (squid.conf).
rm -f /run/squid/squid.pid
if [ ! -f /etc/squid/taap/taap-acl.conf ]; then
    # Por defecto: sin agentes; los ACLs deben existir para que la config
    # sea válida (el orquestador sustituye el fragmento en el arranque real).
    cat > /etc/squid/taap/taap-acl.conf <<'EOF'
acl taap_agents src 127.0.0.1
acl taap_internet src 127.0.0.1
acl model_hosts url_regex ^!
EOF
fi
chown -R squid:squid /etc/squid/taap /var/log/squid /var/spool/squid /run/squid
exec squid -N -f /etc/squid/squid.conf

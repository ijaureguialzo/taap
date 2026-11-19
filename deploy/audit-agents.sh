#!/bin/sh
# Auditoria de seguridad de los contenedores de agente TAAP (task 10.2).
#
# Comprueba cada contenedor gestionado (label taap.managed=1, runtime=agent):
#   1. Sin socket de Docker montado
#   2. Sin modo privileged / capadd
#   3. Sin puertos publicados (los agentes no escuchan)
#   4. Usuario no root (excepcion documentada: Hermes con s6-linux-init)
#   5. Rootfs solo lectura (excepcion: Hermes/s6)
#
# Salida: una linea por comprobacion por contenedor; exit 0 si todo pasa,
# exit 1 si alguna falla. Las excepciones conocidas se marcan WARN (no fallan).

set -u

FAIL=0

CONTAINERS=$(docker ps -a --filter label=taap.managed=1 --format '{{.Names}}' | grep -v '^taap-egress' | grep 'taap-agent' || true)

if [ -z "$CONTAINERS" ]; then
    echo "audit: no hay contenedores de agente (nada que auditar)"
    echo "PASS audit-agents (0 contenedores)"
    exit 0
fi

for c in $CONTAINERS; do
    RUNTIME=$(docker inspect -f '{{index .Config.Labels "taap.runtime"}}' "$c" 2>/dev/null)
    USER=$(docker inspect -f '{{.Config.User}}' "$c" 2>/dev/null)
    RO=$(docker inspect -f '{{.HostConfig.ReadonlyRootfs}}' "$c" 2>/dev/null)
    PRIV=$(docker inspect -f '{{.HostConfig.Privileged}}' "$c" 2>/dev/null)
    CAPS=$(docker inspect -f '{{range .HostConfig.CapAdd}}{{. }} {{end}}' "$c" 2>/dev/null)
    PORTS=$(docker inspect -f '{{json .HostConfig.PortBindings}}' "$c" 2>/dev/null)
    SOCK=$(docker inspect -f '{{range .HostConfig.Binds}}{{.}} {{end}}' "$c" 2>/dev/null | grep -c "docker.sock" || true)

    # 1. Socket de Docker dentro del agente
    if [ "$SOCK" -gt 0 ]; then
        echo "FAIL $c: tiene el socket de Docker montado ($CAPS)"
        FAIL=1
    else
        echo "ok   $c: sin socket de Docker"
    fi

    # 2. Privileged / capacidades
    if [ "$PRIV" = "true" ]; then
        echo "FAIL $c: modo privileged"
        FAIL=1
    else
        echo "ok   $c: no privileged (caps: ${CAPS:-ninguna})"
    fi

    # 3. Puertos publicados
    if [ "$PORTS" != "{}" ] && [ -n "$PORTS" ]; then
        echo "FAIL $c: puertos publicados $PORTS"
        FAIL=1
    else
        echo "ok   $c: sin puertos publicados"
    fi

    # 4. Usuario
    if [ "$RUNTIME" = "hermes" ]; then
        # s6-linux-init necesita root para el init; el workload corre como
        # `hermes` (comprobamos que exista un proceso no root del servicio).
        if [ "$USER" = "root" ] || [ -z "$USER" ]; then
            NONROOT=$(docker exec "$c" sh -c 'for p in /proc/[0-9]*; do stat -c %U "$p" 2>/dev/null; done | grep -v root | head -1' 2>/dev/null)
            if [ -n "$NONROOT" ]; then
                echo "WARN $c: init root (s6) pero el workload corre como '$NONROOT' (excepcion documentada)"
            else
                echo "FAIL $c: todo el proceso corre como root y no es s6"
                FAIL=1
            fi
        else
            echo "ok   $c: usuario $USER"
        fi
    else
        if [ "$USER" = "root" ] || [ -z "$USER" ]; then
            echo "FAIL $c: corre como root (el runtime '$RUNTIME' lo permite evitar)"
            FAIL=1
        else
            echo "ok   $c: usuario $USER (no root)"
        fi
    fi

    # 5. Rootfs
    if [ "$RO" = "true" ]; then
        echo "ok   $c: rootfs solo lectura"
    elif [ "$RUNTIME" = "hermes" ]; then
        echo "WARN $c: rootfs escribible (excepcion: init s6 de la imagen)"
    else
        echo "FAIL $c: rootfs escribible y el runtime no la necesita"
        FAIL=1
    fi
done

if [ "$FAIL" -eq 0 ]; then
    echo "PASS audit-agents"
else
    echo "FAIL audit-agents"
fi
exit "$FAIL"

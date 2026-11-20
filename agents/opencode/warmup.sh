#!/bin/sh
# Pre-warm de la app dir de opencode (build-time): opencode instala las deps
# de sus providers npm (~60 MB: @ai-sdk/* + effect) en la app dir
# ($XDG_CONFIG_HOME/opencode/) LA PRIMERA VEZ que resuelve un provider.
# Los agentes suelen no tener Internet -> se copia a /config/opencode/ en
# runtime (entrypoint). Sin --pure la instalacion se haria en cada arranque.
set -u
mkdir -p /opt/warm-src/opencode
printf '%s' '{"provider":{"taap-oc":{"npm":"@ai-sdk/openai-compatible","options":{"baseURL":"http://127.0.0.1:9","apiKey":"warm"},"models":{"warm":{"name":"warm"}}},"taap-ant":{"npm":"@ai-sdk/anthropic","options":{"baseURL":"http://127.0.0.1:9","apiKey":"warm"},"models":{"warm":{"name":"warm"}}}}}' > /opt/warm-src/opencode/opencode.json

XDG_DATA_HOME=/opt/warm-data XDG_CONFIG_HOME=/opt/warm-src opencode serve --port 4097 --hostname 127.0.0.1 --pure >/dev/null 2>&1 &
warmup=$!
for i in $(seq 1 90); do
    curl -sf http://127.0.0.1:4097/api/health >/dev/null 2>&1 && break
    sleep 2
done
curl -sf http://127.0.0.1:4097/api/health >/dev/null || { echo "warmup: health no responde" >&2; kill $warmup 2>/dev/null; exit 1; }

# Crear una sesion con cada provider: es lo que dispara la instalacion.
for p in taap-oc taap-ant; do
    sid=$(curl -s -X POST http://127.0.0.1:4097/session -H 'content-type: application/json' -d '{"model":{"id":"warm","providerID":"'$p'"}}' | node -pe 'try{JSON.parse(require("fs").readFileSync(0,"utf8")).id||""}catch(e){""}')
    [ -n "$sid" ] && curl -s -X POST "http://127.0.0.1:4097/session/$sid/prompt_async" -H 'content-type: application/json' -d '{"parts":[{"type":"text","text":"warmup"}],"model":{"providerID":"'$p'","modelID":"warm"}}' >/dev/null
done

for i in $(seq 1 120); do
    [ -d /opt/warm-src/opencode/node_modules/@ai-sdk ] && break
    sleep 3
done

# opencode (bun) no siempre contesta al SIGTERM: SIGKILL como red.
kill $warmup 2>/dev/null
sleep 2
kill -9 $warmup 2>/dev/null || true
[ -d /opt/warm-src/opencode/node_modules/@ai-sdk ] || { echo "warmup: node_modules incompleto" >&2; exit 1; }
mkdir -p /opt/warm
cp -a /opt/warm-src/opencode /opt/warm/opencode
rm -rf /opt/warm-src /opt/warm-data
echo "warmup OK: $(du -sh /opt/warm/opencode | cut -f1)"

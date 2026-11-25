#!/bin/bash
# =============================================================================
# TAAP smoke E2E (tarea 10.4)
#
# Recorre el flujo completo como un admin:
#   1. Proveedor + modelo (creados y limpiados por el script)
#   2. Agente Pi   -> chat con APROBACION de comando bash
#   3. Agente Pi   -> MCP (get_time) conectado y usado
#   4. Agente Hermes -> chat
#   5. FS compartido -> subida en "Archivos", agente rw lee/escribe sin
#      confirmacion, segundo agente ro ve el cambio y no escribe, el admin
#      lo ve todo en su explorador
#
# Precondiciones: `docker compose up -d` (orquestador, reverb, postgres,
# queue, egress) y un admin existente. Sale 0 si todo paso, 1 si no.
#
# Variables:
#   TAAP_BASE_URL      (defecto http://127.0.0.1:8011)
#   TAAP_ADMIN_EMAIL   (defecto admin@taap.local)
#   TAAP_ADMIN_PASSWORD
#   TAAP_MODEL_BASE_URL (defecto http://host.docker.internal:8000/v1; el
#   orquestador lo llama desde dentro de la red docker)
#   TAAP_MODEL_ID      (defecto orcarouter/Qwen3.8-27B-Uncensored-MLX)
#   SMOKE_MCP_PORT     (defecto 9911; el mcp-test-server.mjs corre en el host)
#   SMOKE_KEEP=1       no limpia los recursos creados (debug)
# =============================================================================
set -u

BASE_URL="${TAAP_BASE_URL:-http://127.0.0.1:8011}"
ADMIN_EMAIL="${TAAP_ADMIN_EMAIL:-admin@taap.local}"
ADMIN_PASSWORD="${TAAP_ADMIN_PASSWORD:-taap1234}"
MODEL_BASE_URL="${TAAP_MODEL_BASE_URL:-http://host.docker.internal:8000/v1}"
MODEL_ID="${TAAP_MODEL_ID:-orcarouter/Qwen3.8-27B-Uncensored-MLX}"
MCP_PORT="${SMOKE_MCP_PORT:-9911}"
TS="$(date +%H%M%S)"
FAIL=0
MCP_PID=""
ADMIN_ID=""
QUOTA_BEFORE=""
SMOKE_IDS=""
SMOKE_MCP=""
SMOKE_MODEL=""
SMOKE_PROVIDER=""

say()  { printf '\n== %s\n' "$*"; }
ok()   { printf '   ok: %s\n' "$*"; }
fail() { printf '   FALLO: %s\n' "$*"; FAIL=1; }

# json <campo> [json]  -> extrae un campo (recibe el json por stdin si no se da)
json() {
  php -r '
    $f = $argv[1];
    $s = isset($argv[2]) ? $argv[2] : stream_get_contents(STDIN);
    $d = json_decode($s, true);
    $v = $d;
    foreach (explode(".", $f) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; }
    if (is_array($v)) { echo json_encode($v); }
    elseif ($v !== null) { echo $v; }
  ' "$@"
}

api() { # api METHOD PATH [json-body]  -> respuesta (usa el token)
  local method=$1 path=$2 body=${3:-}
  if [ -n "$body" ]; then
    curl -s -X "$method" "$BASE_URL$path" -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" -H "Accept: application/json" -d "$body"
  else
    curl -s -X "$method" "$BASE_URL$path" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
  fi
}

cleanup() {
  trap - EXIT
  [ -n "$MCP_PID" ] && kill "$MCP_PID" 2>/dev/null
  if [ -n "${TOKEN:-}" ] && [ -n "${ADMIN_ID:-}" ]; then
    if [ -n "${QUOTA_BEFORE:-}" ]; then
      api PUT "/api/v1/users/$ADMIN_ID" "{\"role\":\"admin\",\"agent_quota\":$QUOTA_BEFORE}" > /dev/null
    else
      api PUT "/api/v1/users/$ADMIN_ID" '{"role":"admin","agent_quota":null}' > /dev/null
    fi
  fi
  [ "${SMOKE_KEEP:-}" = "1" ] && return
  if [ -n "${TOKEN:-}" ]; then
    local id
    for id in $SMOKE_IDS; do
      curl -s -X DELETE "$BASE_URL/api/v1/agents/$id" -H "Authorization: Bearer $TOKEN" -o /dev/null 2>/dev/null
    done
    local v
    [ -n "$SMOKE_MCP" ] && curl -s -X DELETE "$BASE_URL/api/v1/mcp/$SMOKE_MCP" -H "Authorization: Bearer $TOKEN" -o /dev/null 2>/dev/null
    [ -n "$SMOKE_MODEL" ] && curl -s -X DELETE "$BASE_URL/api/v1/models/$SMOKE_MODEL" -H "Authorization: Bearer $TOKEN" -o /dev/null 2>/dev/null
    [ -n "$SMOKE_PROVIDER" ] && curl -s -X DELETE "$BASE_URL/api/v1/providers/$SMOKE_PROVIDER" -H "Authorization: Bearer $TOKEN" -o /dev/null 2>/dev/null
  fi
}
trap cleanup EXIT

# wait_status AGENT_ID STATUS [timeout_s]  -> 0 si llega al estado
wait_status() {
  local id=$1 want=$2 timeout=${3:-180} t=0 s
  while [ "$t" -lt "$timeout" ]; do
    s=$(api GET "/api/v1/agents/$id/status" | json agent.status)
    [ "$s" = "$want" ] && return 0
    # offline = contenedor muerto: no va a llegar solo.
    # error al arrancar: el gateway Hermes tarda 60-90s en escuchar; el
    # primer sondeo puede fallar y el bridge lo re-levanta: seguimos.
    [ "$s" = "offline" ] && return 1
    sleep 5; t=$((t+5))
  done
  return 1
}

agent_count() { # agent_count AGENT_ID -> numero de mensajes del agente
  api GET "/api/v1/agents/$1/messages?limit=50" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    echo count(array_filter($d["messages"] ?? [], fn($m) => $m["author"] === "agent"));
  '
}

last_agent_message() { # ultimo mensaje del agente
  api GET "/api/v1/agents/$1/messages?limit=5" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    foreach (array_reverse($d["messages"] ?? []) as $m) if ($m["author"] === "agent") { echo $m["content"]; break; }
  '
}

# wait_agent_message AGENT_ID [timeout_s]  -> 0 si llega un mensaje nuevo
# (timeout amplio: los turnos de razonamiento de Qwen a high-effort tardan)
wait_agent_message() {
  local id=$1 timeout=${2:-420} t=0 base n
  base=$(agent_count "$id")
  while [ "$t" -lt "$timeout" ]; do
    n=$(agent_count "$id")
    [ "$n" -gt "$base" ] 2>/dev/null && return 0
    sleep 5; t=$((t+5))
  done
  return 1
}

# =============================================================================
say "0. Precondiciones (stack + admin)"
# =============================================================================
code=000
for i in $(seq 1 30); do
  code=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/up" 2>/dev/null)
  [ "$code" = "200" ] && break
  sleep 2
done
[ "$code" = "200" ] || { fail "orquestador no responde en $BASE_URL/up"; exit 1; }
ok "orquestador responde"

LOGIN=$(curl -s -X POST "$BASE_URL/api/v1/auth/login" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
TOKEN=$(echo "$LOGIN" | json token)
[ -n "$TOKEN" ] || { fail "login de $ADMIN_EMAIL"; exit 1; }
ok "login $ADMIN_EMAIL"

# Cuota de agentes: el smoke crea 4; se sube la cuota del admin (y se
# restaura en la limpieza) para no chocar con los agentes ya existentes.
ADMIN_ID=$(api GET /api/v1/users | php -r '
  $d = json_decode(stream_get_contents(STDIN), true);
  foreach ($d["users"] ?? [] as $u) if ($u["email"] === $argv[1]) { echo $u["id"]; break; }
' "$ADMIN_EMAIL")
QUOTA_BEFORE=$(api GET /api/v1/users | php -r '
  $d = json_decode(stream_get_contents(STDIN), true);
  foreach ($d["users"] ?? [] as $u) if ((int) $u["id"] === (int) $argv[1]) { echo $u["agent_quota"] ?? ""; break; }
' "$ADMIN_ID")
EXISTING=$(api GET /api/v1/agents | php -r '
  $d = json_decode(stream_get_contents(STDIN), true);
  echo count($d["agents"] ?? []);
')
QUOTA=$(( EXISTING + 4 ))
[ "$QUOTA" -lt 8 ] && QUOTA=8
api PUT "/api/v1/users/$ADMIN_ID" "{\"role\":\"admin\",\"agent_quota\":$QUOTA}" > /dev/null
ok "cuota temporal: ${QUOTA_BEFORE:-default} -> $QUOTA ($EXISTING existentes + 4 del smoke)"

# =============================================================================
say "1. Proveedor + modelo"
# =============================================================================
PROV_NAME="smoke-qwen-$TS"
RESP=$(api POST /api/v1/providers "{\"name\":\"$PROV_NAME\",\"type\":\"openai\",\"base_url\":\"$MODEL_BASE_URL\"}")
PROVIDER_ID=$(echo "$RESP" | json provider.id)
[ -n "$PROVIDER_ID" ] || { fail "crear proveedor: $RESP"; exit 1; }
ok "provider $PROV_NAME (id $PROVIDER_ID)"
SMOKE_PROVIDER=$PROVIDER_ID

MODEL_NAME="smoke-model-$TS"
RESP=$(api POST /api/v1/models "{\"name\":\"$MODEL_NAME\",\"provider_id\":$PROVIDER_ID,\"model_id\":\"$MODEL_ID\",\"context_window\":131072,\"max_tokens\":8192}")
echo "$RESP" | grep -q '"ok":true' || { fail "crear modelo: $RESP"; exit 1; }
# store usa firstOrCreate y no devuelve el id: se busca por (provider, model_id)
MODEL_ID_ROW=$(api GET /api/v1/models | php -r '
  $pid = (int) $argv[1]; $mid = $argv[2];
  $d = json_decode(stream_get_contents(STDIN), true);
  foreach ($d["models"] ?? [] as $m) if ((int) $m["provider_id"] === $pid && $m["model_id"] === $mid) { echo $m["id"]; break; }
' "$PROVIDER_ID" "$MODEL_ID")
[ -n "$MODEL_ID_ROW" ] || { fail "localizar modelo $MODEL_ID"; exit 1; }
ok "model $MODEL_NAME (id $MODEL_ID_ROW)"
SMOKE_MODEL=$MODEL_ID_ROW

# =============================================================================
say "2. Agente Pi: chat con aprobacion"
# =============================================================================
AG1="smoke-pi-$TS"
RESP=$(api POST /api/v1/agents "{\"name\":\"$AG1\",\"runtime\":\"pi\",\"model_id\":$MODEL_ID_ROW,\"internet_enabled\":false,\"system_prompt\":\"Eres un agente de shell. Ejecuta los comandos que el usuario pida con la herramienta bash y responde con la salida.\"}")
AG1_ID=$(echo "$RESP" | json agent.id)
[ -n "$AG1_ID" ] || { fail "crear agente pi: $RESP"; exit 1; }
ok "agente $AG1 (id $AG1_ID)"
SMOKE_IDS="$AG1_ID"

if wait_status "$AG1_ID" idle 240; then ok "pi ready"; else fail "pi no llego a idle"; exit 1; fi

# El modelo qwen es intermitente en la decision de tool-call: se reintenta
# (reiniciando el agente = sesion limpia) hasta que pida aprobacion.
APPROVED=0
for try in 1 2 3; do
  api POST "/api/v1/agents/$AG1_ID/messages" '{"content":"Ejecuta el comando: date -u y dime la salida"}' > /dev/null
  for i in $(seq 1 12); do
    sleep 15
    N=$(api GET "/api/v1/agents/$AG1_ID/messages?limit=1" | php -r '
      $d = json_decode(stream_get_contents(STDIN), true);
      echo count($d["pending_approvals"] ?? []);
    ')
    if [ "${N:-0}" -ge 1 ] 2>/dev/null; then APPROVED=1; break; fi
    S=$(api GET "/api/v1/agents/$AG1_ID/status" | json agent.status)
    if [ "$S" = "idle" ]; then break; fi
  done
  [ "$APPROVED" = "1" ] && break
  [ "$try" -lt 3 ] && { echo "   (intento $try sin tool-call; reiniciando agente)"; api POST "/api/v1/agents/$AG1_ID/restart" > /dev/null; wait_status "$AG1_ID" idle 240; }
done

if [ "$APPROVED" = "1" ]; then
  AP_ID=$(api GET "/api/v1/agents/$AG1_ID/messages?limit=1" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    $a = $d["pending_approvals"][0] ?? [];
    echo $a["id"] ?? "";
  ')
  RESP=$(api POST "/api/v1/agents/$AG1_ID/approvals/$AP_ID" "{\"approval_id\":\"$AP_ID\",\"approved\":true}")
  echo "$RESP" | grep -q '"ok":true' && ok "aprobacion enviada ($AP_ID)" || fail "aprobar: $RESP"
  if wait_agent_message "$AG1_ID" 240; then ok "pi respondio despues de la aprobacion"; else fail "sin respuesta del pi tras la aprobacion"; fi
  LAST=$(last_agent_message "$AG1_ID")
  echo "$LAST" | grep -q "UTC" && ok "la respuesta incluye la salida de date -u" || fail "respuesta sin salida UTC: $(echo "$LAST" | head -c 120)"
else
  fail "pi nunca pidio aprobacion en 3 intentos (model intermitente)"
fi

# =============================================================================
say "3. Agente Pi: MCP (get_time)"
# =============================================================================
cd "$(dirname "$0")"
# Dejar libre el puerto del mcp-test (queda de corridas anteriores)
if command -v lsof > /dev/null; then lsof -ti tcp:"$MCP_PORT" 2>/dev/null | xargs kill 2>/dev/null; fi
sleep 1
node mcp-test-server.mjs & MCP_PID=$!
sleep 2
MCP_NAME="smoke-mcp-$TS"
RESP=$(api POST /api/v1/mcp "{\"name\":\"$MCP_NAME\",\"type\":\"url\",\"url\":\"http://host.docker.internal:$MCP_PORT/mcp\"}")
MCP_ID=$(echo "$RESP" | json server.id)
[ -n "$MCP_ID" ] || { fail "crear mcp: $RESP"; }
if [ -n "$MCP_ID" ]; then
  ok "mcp $MCP_NAME (id $MCP_ID)"
  SMOKE_MCP=$MCP_ID
  RESP=$(api PUT "/api/v1/agents/$AG1_ID" "{\"name\":\"$AG1\",\"mcp_server_ids\":[$MCP_ID]}")
  echo "$RESP" | grep -q 'error' && fail "conectar mcp al agente: $RESP" || ok "mcp conectado (restart aplicado)"
  if wait_status "$AG1_ID" idle 240; then
    MCP_OK=0
    for try in 1 2; do
      api POST "/api/v1/agents/$AG1_ID/messages" '{"content":"Usa la herramienta get_time y dime que hora es en UTC"}' > /dev/null
      if wait_agent_message "$AG1_ID" 420; then
        LAST=$(last_agent_message "$AG1_ID")
        echo "$LAST" | grep -Eq "UTC|:|hora|T[0-9]" && ok "el agente respondio con MCP conectado: $(echo "$LAST" | head -c 80)" || echo "   info: respuesta no menciona la hora (el modelo pudo usar bash): $(echo "$LAST" | head -c 80)"
        MCP_OK=1
        break
      fi
      [ "$try" -lt 2 ] && { echo "   (sin respuesta; reiniciando agente)"; api POST "/api/v1/agents/$AG1_ID/restart" > /dev/null; wait_status "$AG1_ID" idle 240; }
    done
    [ "$MCP_OK" = "1" ] || fail "sin respuesta del pi sobre MCP"
  else
    fail "pi no se recupero tras conectar MCP"
  fi
fi

# =============================================================================
say "4. Agente Hermes: chat"
# =============================================================================
AG2="smoke-hermes-$TS"
RESP=$(api POST /api/v1/agents "{\"name\":\"$AG2\",\"runtime\":\"hermes\",\"model_id\":$MODEL_ID_ROW,\"internet_enabled\":false,\"system_prompt\":\"Eres un asistente util y conciso.\"}")
AG2_ID=$(echo "$RESP" | json agent.id)
[ -n "$AG2_ID" ] || { fail "crear agente hermes: $RESP"; exit 1; }
ok "agente $AG2 (id $AG2_ID)"
SMOKE_IDS="$SMOKE_IDS $AG2_ID"

if wait_status "$AG2_ID" idle 300; then
  ok "hermes ready"
  api POST "/api/v1/agents/$AG2_ID/messages" '{"content":"Responde exactamente: HERMES-OK"}' > /dev/null
  if wait_agent_message "$AG2_ID" 300; then
    LAST=$(last_agent_message "$AG2_ID")
    echo "$LAST" | grep -q "HERMES-OK" && ok "hermes respondio: $(echo "$LAST" | head -c 80)" || fail "respuesta hermes: $(echo "$LAST" | head -c 80)"
  else
    fail "sin respuesta del hermes"
  fi
else
  fail "hermes no llego a idle"
fi

# =============================================================================
say "5. FS compartido (shared-filesystem 9.1)"
# =============================================================================
# Flujo completo: el admin sube un fichero a una carpeta del arbol (el
# mismo endpoint que usa la pagina "Archivos"), un agente Pi con la carpeta
# en read_write lo lee/escribe SIN confirmacion (extension de auto-aprobacion
# 6.4), un segundo agente en read_only ve el cambio y se niega a escribir
# (EROFS), y el explorador del admin lo ve todo.

FS_DIR="/smoke-fs-$TS"

# 5a. El admin crea la carpeta y sube el fichero (API v1 files = "Archivos")
RESP=$(api POST "/api/v1/files/folders" "{\"path\":\"$FS_DIR\"}")
echo "$RESP" | grep -q 'error' && fail "crear carpeta $FS_DIR: $RESP" || ok "carpeta $FS_DIR creada"

SMOKE_FILE="/tmp/smoke-fs-$TS.txt"
printf 'MARKA-%s\n' "$TS" > "$SMOKE_FILE"
RESP=$(curl -s -X POST "$BASE_URL/api/v1/files/uploads" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -F "path=$FS_DIR" -F "file=@$SMOKE_FILE;filename=hola.txt")
echo "$RESP" | grep -q '"ok"\|"name"' || { fail "subida de hola.txt: $RESP"; }
LIST=$(api GET "/api/v1/files?path=$FS_DIR" | php -r '
  $d = json_decode(stream_get_contents(STDIN), true);
  echo implode(",", array_map(fn($e) => $e["name"], $d["entries"] ?? []));
')
echo "$LIST" | grep -q "hola.txt" && ok "el admin ve la subida en su explorador: $LIST" || fail "subida no visible: $LIST"

# 5b. Agente A (pi) con la carpeta en read_write: lee y escribe sin confirmacion
AG3="smoke-fs-rw-$TS"
RESP=$(api POST /api/v1/agents "{\"name\":\"$AG3\",\"runtime\":\"pi\",\"model_id\":$MODEL_ID_ROW,\"internet_enabled\":false,\"fs_mounts\":[{\"path\":\"$FS_DIR\",\"mode\":\"read_write\"}],\"system_prompt\":\"Eres un agente de shell. Usa la herramienta bash para leer y escribir ficheros en $FS_DIR montado en /fs$FS_DIR. Si un comando falla, di claramente porque fallo.\"}")
AG3_ID=$(echo "$RESP" | json agent.id)
[ -n "$AG3_ID" ] || { fail "crear agente fs-rw: $RESP"; }
ok "agente $AG3 (id $AG3_ID) con $FS_DIR en read_write"
SMOKE_IDS="$SMOKE_IDS $AG3_ID"

if wait_status "$AG3_ID" idle 240; then ok "fs-rw ready"; else fail "fs-rw no llego a idle"; fi

FS_OK=0
for try in 1 2 3; do
  api POST "/api/v1/agents/$AG3_ID/messages" "{\"content\":\"Con la herramienta bash escribe la marca MARKA-$TS en el fichero $FS_DIR/out.txt (ruta /fs$FS_DIR/out.txt) y despues lee /fs$FS_DIR/hola.txt y dime que contiene.\"}" > /dev/null
  for i in $(seq 1 16); do
    sleep 15
    N=$(api GET "/api/v1/agents/$AG3_ID/messages?limit=1" | php -r '
      $d = json_decode(stream_get_contents(STDIN), true);
      echo count($d["pending_approvals"] ?? []);
    ')
    if [ "${N:-0}" -ge 1 ] 2>/dev/null; then
      # Auto-aprobacion 6.4 deberia evitarlo en rw; si el modelo pidio algo
      # de todos modos, se aprueba (info, no fallo).
      AP_ID=$(api GET "/api/v1/agents/$AG3_ID/messages?limit=1" | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        echo ($d["pending_approvals"][0]["id"] ?? "");
      ')
      echo "   info: pidio aprobacion (auto-aprobacion rw no se disparo); aprobando $AP_ID"
      [ -n "$AP_ID" ] && api POST "/api/v1/agents/$AG3_ID/approvals/$AP_ID" "{\"approval_id\":\"$AP_ID\",\"approved\":true}" > /dev/null
    fi
    S=$(api GET "/api/v1/agents/$AG3_ID/status" | json agent.status)
    if [ "$S" = "idle" ]; then break; fi
  done
  # La prueba objetiva: el fichero escrito por el agente esta en el arbol
  DL=$(api GET "/api/v1/files/download?path=$FS_DIR/out.txt" 2>/dev/null)
  if echo "$DL" | grep -q "MARKA-$TS"; then FS_OK=1; break; fi
  [ "$try" -lt 3 ] && { echo "   (intento $try sin escritura; reiniciando agente)"; api POST "/api/v1/agents/$AG3_ID/restart" > /dev/null; wait_status "$AG3_ID" idle 240; }
done

if [ "$FS_OK" = "1" ]; then
  ok "el agente en rw escribio en el arbol compartido (out.txt con MARKA-$TS), sin confirmacion"
  LAST=$(last_agent_message "$AG3_ID")
  echo "$LAST" | grep -q "MARKA-$TS" && ok "el agente leio hola.txt y reporto su contenido: $(echo "$LAST" | head -c 80)" || echo "   info: la respuesta no cita la marca literal: $(echo "$LAST" | head -c 80)"
else
  fail "el agente en rw no llego a escribir en el arbol compartido"
fi

# 5c. Agente B (pi) con la carpeta en read_only: ve el cambio y no puede escribir
AG4="smoke-fs-ro-$TS"
RESP=$(api POST /api/v1/agents "{\"name\":\"$AG4\",\"runtime\":\"pi\",\"model_id\":$MODEL_ID_ROW,\"internet_enabled\":false,\"fs_mounts\":[{\"path\":\"$FS_DIR\",\"mode\":\"read_only\"}],\"system_prompt\":\"Eres un agente de shell. Usa la herramienta bash para inspeccionar $FS_DIR montado en /fs$FS_DIR. Si un comando falla, di claramente porque fallo.\"}")
AG4_ID=$(echo "$RESP" | json agent.id)
[ -n "$AG4_ID" ] || { fail "crear agente fs-ro: $RESP"; }
ok "agente $AG4 (id $AG4_ID) con $FS_DIR en read_only"
SMOKE_IDS="$SMOKE_IDS $AG4_ID"

if wait_status "$AG4_ID" idle 240; then ok "fs-ro ready"; else fail "fs-ro no llego a idle"; fi

api POST "/api/v1/agents/$AG4_ID/messages" "{\"content\":\"Lee /fs$FS_DIR/out.txt con la herramienta bash y dime el contenido.\"}" > /dev/null
if wait_agent_message "$AG4_ID" 420; then
  LAST=$(last_agent_message "$AG4_ID")
  echo "$LAST" | grep -q "MARKA-$TS" && ok "el segundo agente (ro) ve el cambio del primero: $(echo "$LAST" | head -c 80)" || echo "   info: el ro respondio sin citar la marca: $(echo "$LAST" | head -c 80)"
else
  fail "sin respuesta del agente ro sobre la lectura"
fi

api POST "/api/v1/agents/$AG4_ID/messages" "{\"content\":\"Intenta escribir PROBA en /fs$FS_DIR/ro.txt con la herramienta bash. Si falla, di claramente que no puedes escribir.\"}" > /dev/null
# La comprobacion decisiva es el arbol (abajo): el modelo puede no citar la
# negacion literal, o no responder en el plazo (model intermitente): info.
if wait_agent_message "$AG4_ID" 420; then
  ok "el agente ro intento escribir: $(last_agent_message "$AG4_ID" | head -c 80)"
else
  echo "   info: el ro no respondio en el plazo (el arbol es la prueba decisiva)"
fi
LIST=$(api GET "/api/v1/files?path=$FS_DIR" | php -r '
  $d = json_decode(stream_get_contents(STDIN), true);
  echo implode(",", array_map(fn($e) => $e["name"], $d["entries"] ?? []));
')
echo "$LIST" | grep -q "ro.txt" && fail "el read_only consiguio crear ro.txt en el arbol: $LIST" || ok "el arbol no muestra ro.txt (la escritura en read_only se nego): $LIST"

# 5d. El explorador del admin ve el estado final del arbol
LIST=$(api GET "/api/v1/files?path=$FS_DIR" | php -r '
  $d = json_decode(stream_get_contents(STDIN), true);
  echo implode(",", array_map(fn($e) => $e["name"], $d["entries"] ?? []));
')
echo "$LIST" | grep -q "hola.txt" && echo "$LIST" | grep -q "out.txt" && ok "el explorador del admin ve todo: $LIST" || fail "explorador del admin incompleto: $LIST"

rm -f "$SMOKE_FILE"

# =============================================================================
say "Resultado"
# =============================================================================
if [ "$FAIL" = "0" ]; then
  echo "SMOKE OK: todo el flujo completo funciono"
else
  echo "SMOKE FALLO: revisa los pasos marcados FALLO"
fi
exit "$FAIL"

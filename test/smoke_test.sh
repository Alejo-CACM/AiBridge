#!/usr/bin/env bash
# AI Bridge smoke test. Usage:
#   AIBRIDGE_URL="https://tienda.com" AIBRIDGE_TOKEN="..." [AIBRIDGE_BASIC_AUTH="user:pass"] ./smoke_test.sh
set -u

URL="${AIBRIDGE_URL:?Set AIBRIDGE_URL}"
TOKEN="${AIBRIDGE_TOKEN:?Set AIBRIDGE_TOKEN}"
AUTH="${AIBRIDGE_BASIC_AUTH:-}"
CURL_AUTH=()
if [ -n "$AUTH" ]; then CURL_AUTH=(-u "$AUTH"); fi

PASS=0
FAIL=0

check() {
  local name="$1" expect="$2" actual="$3"
  if echo "$actual" | grep -q "$expect"; then
    echo "[OK]   $name"
    PASS=$((PASS+1))
  else
    echo "[FAIL] $name"
    echo "       expected to contain: $expect"
    echo "       got: $actual"
    FAIL=$((FAIL+1))
  fi
}

req() {
  local method="$1" path="$2" data="${3:-}"
  if [ -n "$data" ]; then
    curl -s "${CURL_AUTH[@]}" -X "$method" "$URL/index.php?fc=module&module=aibridge&controller=$path" \
      -H "X-AI-Bridge-Token: $TOKEN" -H "Content-Type: application/json" -d "$data"
  else
    curl -s "${CURL_AUTH[@]}" "$URL/index.php?fc=module&module=aibridge&controller=$path" \
      -H "X-AI-Bridge-Token: $TOKEN"
  fi
}

echo "== AI Bridge smoke test against $URL =="

check "ping" '"status":"ok"' "$(req GET ping)"
check "categories" '"categories"' "$(req GET categories)"
check "brands" '"brands"' "$(req GET brands)"
check "productfields" '"groups"' "$(req GET productfields)"
check "diagnostics" '"recent_execution_logs"' "$(req GET diagnostics)"

REF="AIBRIDGE-SMOKE-$(date +%s)"
CREATE_RESP=$(req POST productcreatepreview '{
  "shop_id": 1, "language_id": 3,
  "name": {"3": "AIBRIDGE SMOKE TEST - borrar"},
  "link_rewrite": {"3": "aibridge-smoke-test-borrar-'"$(date +%s)"'"},
  "price": 1.00, "id_tax_rules_group": 1, "reference": "'"$REF"'",
  "categories": [2], "id_category_default": 2, "active": false
}')
check "product.create preview valid" '"valid":true' "$CREATE_RESP"
check "product.create request created" '"approval_uuid"' "$CREATE_RESP"

echo ""
echo "== Result: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]

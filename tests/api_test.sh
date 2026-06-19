#!/bin/bash

# ==========================================
# Pruebas cURL para API Pública PUI
# ==========================================

URL="http://localhost:8000/api/v1/pui"
USUARIO="PUI"
CLAVE="CyborgPui2026!*@"
CURP_VALIDA="GODE561231HDFABC09"
CURP_INVALIDA="12345"
EXTERNAL_ID="A1B2C3D4E5F6-550e8400-e29b-41d4-a716-446655440000"

echo "1. Haciendo Login..."
LOGIN_RES=$(curl -s -X POST $URL/login \
  -H "Content-Type: application/json" \
  -d "{\"usuario\": \"$USUARIO\", \"clave\": \"$CLAVE\"}")

echo $LOGIN_RES
TOKEN=$(echo $LOGIN_RES | grep -o '"token":"[^"]*' | grep -o '[^"]*$')

if [ -z "$TOKEN" ]; then
    echo "Fallo al obtener token. Revisa las credenciales."
    exit 1
fi
echo "Token obtenido exitosamente."

echo ""
echo "2. Activar Reporte (Coincidencia Pendiente / Sin Coincidencia)"

PAYLOAD_2=$(cat <<EOF
{
  "id": "$EXTERNAL_ID",
  "curp": "$CURP_VALIDA",
  "lugar_nacimiento": ""
}
EOF
)

echo "Payload enviado:"
echo "$PAYLOAD_2"

ACTIVATE_RES=$(curl -s -w "\n%{http_code}" -X POST $URL/activar-reporte \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "$PAYLOAD_2")

HTTP_CODE=$(echo "$ACTIVATE_RES" | tail -n1)
ACTIVATE_BODY=$(echo "$ACTIVATE_RES" | sed '$d')

echo "Respuesta:"
echo "$ACTIVATE_BODY" | jq . || echo "$ACTIVATE_BODY"

if [ "$HTTP_CODE" != "200" ]; then
    echo "El paso 2 falló. No se ejecutará la desactivación."
    exit 1
fi

echo ""
echo "3. Activar Reporte (Formato Inválido)"

PAYLOAD_3=$(cat <<EOF
{
  "id": "$EXTERNAL_ID",
  "curp": "$CURP_INVALIDA",
  "lugar_nacimiento": ""
}
EOF
)

echo "Payload enviado:"
echo "$PAYLOAD_3"

curl -s -X POST $URL/activar-reporte \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "$PAYLOAD_3" | jq . || true

echo ""
echo "4. Activar Reporte Prueba (Simulación)"

PAYLOAD_4=$(cat <<EOF
{
  "id": "TEST-002-A1B2C3D4E5F6-550e8400-e29b-41d4",
  "curp": "$CURP_VALIDA",
  "lugar_nacimiento": ""
}
EOF
)

echo "Payload enviado:"
echo "$PAYLOAD_4"

curl -s -X POST $URL/activar-reporte-prueba \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "$PAYLOAD_4" | jq . || true

echo ""
echo "5. Desactivar Reporte (Por ID)"

PAYLOAD_5=$(cat <<EOF
{
  "id": "$EXTERNAL_ID"
}
EOF
)

echo "Payload enviado:"
echo "$PAYLOAD_5"

curl -s -X POST $URL/desactivar-reporte \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "$PAYLOAD_5" | jq . || true

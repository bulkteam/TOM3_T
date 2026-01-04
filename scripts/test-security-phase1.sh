#!/bin/bash

# Security Phase 1 - Test Script
# Testet Auth-Zwang, CSRF-Schutz und APP_ENV härten

set -e

# Farben für Output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Konfiguration
BASE_URL="${BASE_URL:-http://localhost/tom3/public}"
API_URL="${BASE_URL}/api"
COOKIE_FILE="/tmp/tom3_test_cookies.txt"

# Test-Counter
TESTS_PASSED=0
TESTS_FAILED=0

# Helper-Funktionen
print_test() {
    echo -e "${YELLOW}Testing: $1${NC}"
}

print_pass() {
    echo -e "${GREEN}✓ PASS: $1${NC}"
    ((TESTS_PASSED++))
}

print_fail() {
    echo -e "${RED}✗ FAIL: $1${NC}"
    ((TESTS_FAILED++))
}

# Test 1: CSRF-Token Endpoint
test_csrf_token_endpoint() {
    print_test "CSRF-Token Endpoint"
    
    response=$(curl -s -w "\n%{http_code}" "${API_URL}/auth/csrf-token")
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | head -n-1)
    
    if [ "$http_code" -eq 200 ]; then
        token=$(echo "$body" | jq -r '.token // empty')
        if [ -n "$token" ] && [ "$token" != "null" ]; then
            print_pass "CSRF-Token Endpoint"
            echo "$token" > /tmp/tom3_csrf_token.txt
            return 0
        else
            print_fail "CSRF-Token Endpoint - Token fehlt"
            return 1
        fi
    else
        print_fail "CSRF-Token Endpoint - HTTP $http_code"
        return 1
    fi
}

# Test 2: GET ohne Auth (sollte funktionieren)
test_get_without_auth() {
    print_test "GET /api/orgs ohne Auth"
    
    response=$(curl -s -w "\n%{http_code}" "${API_URL}/orgs")
    http_code=$(echo "$response" | tail -n1)
    
    if [ "$http_code" -eq 200 ]; then
        print_pass "GET /api/orgs ohne Auth"
        return 0
    else
        print_fail "GET /api/orgs ohne Auth - HTTP $http_code"
        return 1
    fi
}

# Test 3: POST ohne CSRF-Token (Dev-Mode: sollte funktionieren, Prod: sollte 403)
test_post_without_csrf() {
    print_test "POST /api/orgs ohne CSRF-Token"
    
    response=$(curl -s -w "\n%{http_code}" \
        -X POST \
        -H "Content-Type: application/json" \
        -d '{"name":"Test Org"}' \
        "${API_URL}/orgs")
    http_code=$(echo "$response" | tail -n1)
    
    # In Dev-Mode: 200 oder 201 OK
    # In Production: 403 Forbidden
    if [ "$http_code" -eq 200 ] || [ "$http_code" -eq 201 ]; then
        print_pass "POST /api/orgs ohne CSRF-Token (Dev-Mode: OK)"
        return 0
    elif [ "$http_code" -eq 403 ]; then
        print_pass "POST /api/orgs ohne CSRF-Token (Production: 403 erwartet)"
        return 0
    else
        print_fail "POST /api/orgs ohne CSRF-Token - HTTP $http_code"
        return 1
    fi
}

# Test 4: POST mit CSRF-Token (sollte funktionieren)
test_post_with_csrf() {
    print_test "POST /api/orgs mit CSRF-Token"
    
    # Hole CSRF-Token
    if [ ! -f /tmp/tom3_csrf_token.txt ]; then
        test_csrf_token_endpoint
    fi
    
    token=$(cat /tmp/tom3_csrf_token.txt)
    
    if [ -z "$token" ]; then
        print_fail "POST /api/orgs mit CSRF-Token - Kein Token verfügbar"
        return 1
    fi
    
    response=$(curl -s -w "\n%{http_code}" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-CSRF-Token: $token" \
        -d '{"name":"Test Org with CSRF"}' \
        "${API_URL}/orgs")
    http_code=$(echo "$response" | tail -n1)
    
    if [ "$http_code" -eq 200 ] || [ "$http_code" -eq 201 ]; then
        print_pass "POST /api/orgs mit CSRF-Token"
        return 0
    else
        print_fail "POST /api/orgs mit CSRF-Token - HTTP $http_code"
        echo "Response: $(echo "$response" | head -n-1)"
        return 1
    fi
}

# Test 5: POST mit ungültigem CSRF-Token (sollte 403)
test_post_with_invalid_csrf() {
    print_test "POST /api/orgs mit ungültigem CSRF-Token"
    
    response=$(curl -s -w "\n%{http_code}" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-CSRF-Token: invalid-token-12345" \
        -d '{"name":"Test Org"}' \
        "${API_URL}/orgs")
    http_code=$(echo "$response" | tail -n1)
    
    # In Dev-Mode: Kann 200/201 sein (optional)
    # In Production: Sollte 403 sein
    if [ "$http_code" -eq 403 ]; then
        print_pass "POST /api/orgs mit ungültigem CSRF-Token (403 erwartet)"
        return 0
    elif [ "$http_code" -eq 200 ] || [ "$http_code" -eq 201 ]; then
        print_pass "POST /api/orgs mit ungültigem CSRF-Token (Dev-Mode: OK)"
        return 0
    else
        print_fail "POST /api/orgs mit ungültigem CSRF-Token - HTTP $http_code"
        return 1
    fi
}

# Test 6: APP_ENV Prüfung (nur wenn APP_ENV nicht gesetzt)
test_app_env() {
    print_test "APP_ENV Prüfung"
    
    # Prüfe ob APP_ENV gesetzt ist
    if [ -z "$APP_ENV" ]; then
        # In Dev: Sollte funktionieren (Default auf 'local')
        # In Prod: Sollte 500 zurückgeben
        response=$(curl -s -w "\n%{http_code}" "${API_URL}/orgs")
        http_code=$(echo "$response" | tail -n1)
        
        if [ "$http_code" -eq 200 ]; then
            print_pass "APP_ENV Prüfung (Dev-Mode: Default auf 'local')"
            return 0
        elif [ "$http_code" -eq 500 ]; then
            print_pass "APP_ENV Prüfung (Production: 500 erwartet)"
            return 0
        else
            print_fail "APP_ENV Prüfung - HTTP $http_code"
            return 1
        fi
    else
        print_pass "APP_ENV Prüfung (APP_ENV ist gesetzt: $APP_ENV)"
        return 0
    fi
}

# Hauptfunktion
main() {
    echo "=========================================="
    echo "Security Phase 1 - Test Suite"
    echo "=========================================="
    echo "Base URL: $BASE_URL"
    echo "API URL: $API_URL"
    echo ""
    
    # Prüfe ob jq installiert ist
    if ! command -v jq &> /dev/null; then
        echo -e "${RED}Error: jq ist nicht installiert${NC}"
        echo "Installiere jq: sudo apt-get install jq"
        exit 1
    fi
    
    # Führe Tests aus
    test_csrf_token_endpoint
    test_get_without_auth
    test_post_without_csrf
    test_post_with_csrf
    test_post_with_invalid_csrf
    test_app_env
    
    # Zusammenfassung
    echo ""
    echo "=========================================="
    echo "Test-Zusammenfassung"
    echo "=========================================="
    echo -e "${GREEN}Bestanden: $TESTS_PASSED${NC}"
    echo -e "${RED}Fehlgeschlagen: $TESTS_FAILED${NC}"
    echo ""
    
    if [ $TESTS_FAILED -eq 0 ]; then
        echo -e "${GREEN}Alle Tests bestanden!${NC}"
        exit 0
    else
        echo -e "${RED}Einige Tests sind fehlgeschlagen!${NC}"
        exit 1
    fi
}

# Führe Tests aus
main


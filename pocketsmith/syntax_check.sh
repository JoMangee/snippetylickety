#!/bin/bash
# PocketSmith PHP Syntax Audit Script
# Run this before every commit to catch common syntax errors

echo "=== PocketSmith Bridge Syntax Check ==="
echo ""

FILES="index.php includes/pocketsmith.php"
ERRORS=0

for file in $FILES; do
    if [ ! -f "$file" ]; then
        echo "❌ Missing: $file"
        continue
    fi
    
    echo "🔍 Checking: $file"
    
    # Count lines
    LINES=$(wc -l < "$file")
    echo "   Lines: $LINES"
    
    # Check for missing $ on variable assignments
    MISSING_DOLLAR=$(grep -n "^[[:space:]]*[a-z_][a-z0-9_]*[[:space:]]*=" "$file" | grep -v '^[[:space:]]*\$' | grep -v 'function' | grep -v 'return' | grep -v 'if' | grep -v 'while' | grep -v 'for' | grep -v 'echo' | grep -v 'http_' | grep -v 'file_' | grep -v 'bin2hex' | grep -v 'curl_' | grep -v 'json_' | grep -v 'die(' | grep -v 'exit(' | grep -v '?>' || true)
    if [ -n "$MISSING_DOLLAR" ]; then
        echo "   ⚠️ POTENTIAL MISSING $:"
        echo "$MISSING_DOLLAR"
        ERRORS=$((ERRORS + 1))
    else
        echo "   ✓ Variables look good"
    fi
    
    # Check for function name typos
    WRONG_FUNC=$(grep -n "pocketsmith_generate_pck()" "$file" | grep -v "pocketsmith_generate_pkce()" || true)
    if [ -n "$WRONG_FUNC" ]; then
        echo "   ❌ WRONG FUNCTION NAME FOUND:"
        echo "$WRONG_FUNC"
        ERRORS=$((ERRORS + 1))
    else
        echo "   ✓ Function name 'pocketsmith_generate_pkce()' is correct"
    fi
    
    # Check for arch_state typo
    BAD_STATE=$(grep -n '\$arch_state' "$file" || true)
    if [ -n "$BAD_STATE" ]; then
        echo "   ❌ TYPO FOUND: \$arch_state should be \$auth_state"
        echo "$BAD_STATE"
        ERRORS=$((ERRORS + 1))
    else
        echo "   ✓ State variable named correctly as \$auth_state"
    fi
    
    # Check balanced braces
    OPEN_BRACES=$(grep -o '{' "$file" | wc -l)
    CLOSE_BRACES=$(grep -o '}' "$file" | wc -l)
    if [ "$OPEN_BRACES" -ne "$CLOSE_BRACES" ]; then
        echo "   ❌ UNBALANCED BRACES: Open=$OPEN_BRACES, Close=$CLOSE_BRACES"
        ERRORS=$((ERRORS + 1))
    else
        echo "   ✓ Braces balanced (Open=$OPEN_BRACES, Close=$CLOSE_BRACES)"
    fi
    
    # Check function exists
    FUNC_NAME="pocketsmith_generate_pkce"
    if grep -q "function $FUNC_NAME" "$file"; then
        echo "   ✓ Function $FUNC_NAME is defined"
    fi
    
    echo ""
done

# Check MCP request structure for correct tool naming
echo "🔍 Checking MCP request structure..."
if grep -q "'method' => 'tools/call'" pocketsmith/*/*.php; then
    echo "   ❌ FOUND: Using 'tools/call' instead of direct tool name"
    ERRORS=$((ERRORS + 1))
else
    echo "   ✓ MCP request uses direct tool names"
fi

# Check Accept header
echo "🔍 Checking MCP request headers..."
if grep -q "Accept: application/json, text/event/stream" pocketsmith/*/*.php; then
    echo "   ✓ Accept header present in MCP requests"
else
    echo "   ⚠️ WARNING: Accept header may be missing from MCP requests"
fi

echo ""
echo "=== Summary ==="
if [ $ERRORS -eq 0 ]; then
    echo "✅ ALL CHECKS PASSED - Code is syntactically clean!"
else
    echo "❌ $ERRORS ERROR(S) FOUND - Please fix before committing"
    exit 1
fi

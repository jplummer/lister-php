#!/bin/bash
# Pre-commit hook to automatically update CHANGELOG.md

CHANGELOG="docs/CHANGELOG.md"
TODAY=$(date +"%Y-%m-%d")

# Check if changelog was already modified in this commit
if git diff --cached --name-only | grep -q "^${CHANGELOG}$"; then
  # Changelog already updated, skip
  exit 0
fi

# Get list of changed files
CHANGED_FILES=$(git diff --cached --name-status)

# Analyze changes and generate entry
ENTRY_TYPE="Changed"
ENTRY_TEXT=""

# Check for new files
NEW_FILES=$(echo "$CHANGED_FILES" | grep "^A" | cut -f2)
if [ -n "$NEW_FILES" ]; then
  NEW_COUNT=$(echo "$NEW_FILES" | wc -l | xargs)
  if [ "$NEW_COUNT" -eq 1 ]; then
    FILE=$(echo "$NEW_FILES" | head -1)
    BASENAME=$(basename "$FILE")
    if [[ "$FILE" == scripts/* ]]; then
      ENTRY_TEXT="- Add $BASENAME script"
    elif [[ "$FILE" == lister/includes/*.php ]]; then
      ENTRY_TEXT="- Add $(basename "$FILE" .php) class"
    elif [[ "$FILE" == lister/templates/*.php ]]; then
      ENTRY_TEXT="- Add $(basename "$FILE" .php) template"
    elif [[ "$FILE" == docs/*.md ]]; then
      ENTRY_TEXT="- Add $(basename "$FILE" .md) documentation"
    else
      ENTRY_TEXT="- Add $BASENAME"
    fi
  else
    ENTRY_TEXT="- Add $NEW_COUNT new files"
  fi
  ENTRY_TYPE="Added"
fi

# Check for security-related changes
if echo "$CHANGED_FILES" | grep -qE "(Security\.php|security|log\.php)"; then
  ENTRY_TYPE="Security"
  if [ -z "$ENTRY_TEXT" ]; then
    ENTRY_TEXT="- Update security system"
  fi
fi

# Check for configuration changes
if echo "$CHANGED_FILES" | grep -qE "(config/|default\.json)"; then
  if [ -z "$ENTRY_TEXT" ]; then
    ENTRY_TEXT="- Update configuration"
  fi
fi

# Check for deployment changes
if echo "$CHANGED_FILES" | grep -qE "(deploy\.sh|\.htaccess)"; then
  if [ -z "$ENTRY_TEXT" ]; then
    ENTRY_TEXT="- Update deployment configuration"
  fi
fi

# If no specific entry generated, create generic one
if [ -z "$ENTRY_TEXT" ]; then
  MODIFIED_FILES=$(echo "$CHANGED_FILES" | grep "^M" || true)
  if [ -n "$MODIFIED_FILES" ]; then
    ENTRY_TEXT="- Update files"
  fi
fi

# If still no entry, skip (might be docs-only or other non-code changes)
if [ -z "$ENTRY_TEXT" ]; then
  exit 0
fi

# Create backup
cp "$CHANGELOG" "$CHANGELOG.bak"

# Check if today's date section exists
if grep -q "^### $TODAY" "$CHANGELOG"; then
  # Date exists - check if section exists
  if sed -n "/^### $TODAY/,/^###/p" "$CHANGELOG" | grep -q "^#### $ENTRY_TYPE"; then
    # Section exists - insert after the section header
    sed -i '' "/^#### $ENTRY_TYPE$/a\\
$ENTRY_TEXT
" "$CHANGELOG"
  else
    # Section doesn't exist - add it after the date
    sed -i '' "/^### $TODAY$/a\\
\\
#### $ENTRY_TYPE\\
$ENTRY_TEXT
" "$CHANGELOG"
  fi
else
  # Date doesn't exist - add new date section after "## Recent Changes"
  sed -i '' "/^## Recent Changes$/a\\
\\
### $TODAY\\
\\
#### $ENTRY_TYPE\\
$ENTRY_TEXT
" "$CHANGELOG"
fi

# Remove backup
rm "$CHANGELOG.bak"

# Stage the changelog
git add "$CHANGELOG"

echo "✓ Auto-added changelog entry: $ENTRY_TEXT"
echo "  Edit docs/CHANGELOG.md if you want to customize it before committing."

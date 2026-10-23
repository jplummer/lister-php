<?php
// Test the pattern matching logic

function matchesPattern($filename, $pattern) {
  // Handle directory patterns (ending with /)
  if (substr($pattern, -1) === '/') {
    $dirPattern = substr($pattern, 0, -1);
    return $filename === $dirPattern;
  }
  
  // Convert wildcard pattern to regex
  $regex = str_replace(['*', '.'], ['.*', '\.'], $pattern);
  $regex = '/^' . $regex . '$/i';
  
  return preg_match($regex, $filename);
}

$testFiles = ['docs', 'scripts', 'lister', 'index.php'];
$patterns = ['index.php', 'lister/'];

echo "Testing pattern matching:\n";
foreach ($testFiles as $file) {
  echo "File: $file\n";
  foreach ($patterns as $pattern) {
    $matches = matchesPattern($file, $pattern);
    echo "  Pattern '$pattern': " . ($matches ? 'MATCHES (HIDDEN)' : 'no match') . "\n";
  }
  echo "\n";
}
?>

<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

refresh_captcha();
$label = captcha_label();

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="110" height="40" viewBox="0 0 110 40">
  <rect width="110" height="40" rx="3" fill="#eef7ff"/>
  <path d="M4 11 L103 30 M8 32 L98 6" stroke="#b8d6f0" stroke-width="1"/>
  <text x="55" y="26" text-anchor="middle" font-family="Arial, sans-serif" font-size="16" fill="#1879D2">$safeLabel</text>
</svg>
SVG;

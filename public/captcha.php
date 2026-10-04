<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

refresh_captcha();
$code = captcha_code();

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$segments = [
    '0' => ['a', 'b', 'c', 'd', 'e', 'f'],
    '1' => ['b', 'c'],
    '2' => ['a', 'b', 'g', 'e', 'd'],
    '3' => ['a', 'b', 'g', 'c', 'd'],
    '4' => ['f', 'g', 'b', 'c'],
    '5' => ['a', 'f', 'g', 'c', 'd'],
    '6' => ['a', 'f', 'g', 'e', 'c', 'd'],
    '7' => ['a', 'b', 'c'],
    '8' => ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
    '9' => ['a', 'b', 'c', 'd', 'f', 'g'],
];
$shapes = [
    'a' => 'M3 2 L15 2 L17 4 L15 6 L3 6 L1 4 Z',
    'b' => 'M16 5 L18 7 L18 17 L16 19 L14 17 L14 7 Z',
    'c' => 'M16 21 L18 23 L18 33 L16 35 L14 33 L14 23 Z',
    'd' => 'M3 34 L15 34 L17 36 L15 38 L3 38 L1 36 Z',
    'e' => 'M2 21 L4 23 L4 33 L2 35 L0 33 L0 23 Z',
    'f' => 'M2 5 L4 7 L4 17 L2 19 L0 17 L0 7 Z',
    'g' => 'M3 18 L15 18 L17 20 L15 22 L3 22 L1 20 Z',
];

$digits = '';
foreach (str_split($code) as $index => $digit) {
    $x = 10 + ($index * 22);
    $rotate = random_int(-9, 9);
    $y = random_int(-1, 1);
    $paths = '';
    foreach ($segments[$digit] as $segment) {
        $paths .= '<path d="' . $shapes[$segment] . '"/>';
    }
    $digits .= '<g transform="translate(' . $x . ' ' . $y . ') rotate(' . $rotate . ' 9 20)">' . $paths . '</g>';
}

$noise = '';
for ($i = 0; $i < 8; $i++) {
    $noise .= '<path d="M' . random_int(0, 140) . ' ' . random_int(2, 38)
        . ' L' . random_int(0, 140) . ' ' . random_int(2, 38)
        . '" stroke="#b8d6f0" stroke-width="1"/>';
}

echo '<svg xmlns="http://www.w3.org/2000/svg" width="145" height="40" viewBox="0 0 145 40">'
    . '<rect width="145" height="40" rx="3" fill="#eef7ff"/>'
    . '<g fill="#1879D2">' . $digits . '</g>'
    . $noise
    . '</svg>';

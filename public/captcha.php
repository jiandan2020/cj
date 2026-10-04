<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

refresh_captcha();
$code = captcha_code();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!extension_loaded('gd') || !function_exists('imagettftext')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('图形验证码需要启用 PHP GD 和 FreeType 扩展');
}

$fontCandidates = array_filter([
    getenv('CAPTCHA_FONT') ?: null,
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/croscore/Arimo-Bold.ttf',
]);
$font = null;
foreach ($fontCandidates as $candidate) {
    if (is_readable($candidate)) {
        $font = $candidate;
        break;
    }
}
if ($font === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('未找到验证码字体；请设置 CAPTCHA_FONT');
}

$width = 170;
$height = 48;
$image = imagecreatetruecolor($width, $height);
imagealphablending($image, true);
$background = imagecolorallocate($image, random_int(242, 250), random_int(246, 253), 255);
imagefilledrectangle($image, 0, 0, $width, $height, $background);

// Discuz-style interference: first draw low-contrast curves/lines behind variable glyphs.
for ($i = 0; $i < 10; $i++) {
    $noise = imagecolorallocatealpha($image, random_int(120, 190), random_int(160, 215), 235, random_int(55, 95));
    imagesetthickness($image, random_int(1, 2));
    imagearc(
        $image,
        random_int(-20, $width + 20),
        random_int(-10, $height + 10),
        random_int(35, 130),
        random_int(20, 90),
        random_int(0, 180),
        random_int(180, 360),
        $noise
    );
}

foreach (str_split($code) as $index => $character) {
    $color = imagecolorallocate($image, random_int(20, 85), random_int(70, 135), random_int(145, 210));
    $size = random_int(20, 26);
    $angle = random_int(-28, 28);
    $x = 9 + ($index * 31) + random_int(-2, 2);
    $y = random_int(31, 39);
    imagettftext($image, $size, $angle, $x, $y, $color, $font, $character);
}

for ($i = 0; $i < 190; $i++) {
    $dot = imagecolorallocatealpha($image, random_int(75, 175), random_int(105, 195), random_int(165, 235), random_int(35, 95));
    imagefilledellipse($image, random_int(0, $width), random_int(0, $height), random_int(1, 2), random_int(1, 2), $dot);
}

header('Content-Type: image/png');
imagepng($image);
imagedestroy($image);

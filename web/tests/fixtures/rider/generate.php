<?php

// Generates rider-reg fixtures with correct magic bytes + exact sizes.
// Run: php tests/fixtures/rider/generate.php
$dir = __DIR__;
$files = [
    ['drivers_license.jpg', 1572864, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00"],
    ['drivers_license.png', 2097152, "\x89PNG\r\n\x1A\n"],
    ['drivers_license.pdf', 716800, "%PDF-1.4\n"],
    ['or_cr.pdf', 921600, "%PDF-1.4\n"],
    ['size_4.9MB.jpg', 5138022, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00"],
    ['size_5.0MB.jpg', 5242880, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00"],
    ['size_5.1MB.jpg', 5347738, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00"],
    ['price_list.docx', 327680, "PK\x03\x04"],
    ['store_banner.gif', 491520, 'GIF89a'],
    ['installer.exe', 2202009, 'MZ'],
];
foreach ($files as [$name, $size, $magic]) {
    $path = $dir.'/'.$name;
    $pad = $size - strlen($magic);
    file_put_contents($path, $magic.str_repeat("\0", $pad));
    echo $name.' => '.filesize($path)." bytes\n";
}

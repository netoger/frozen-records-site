<?php
// Генерация WebP-версий для JPEG-изображений сайта (нужен PHP с GD+WebP).
// Запуск: php scripts/convert_images.php
// Страницы сами подхватят .webp через <picture>, когда файлы появятся;
// JPEG остаётся фолбэком для старых браузеров.

declare(strict_types=1);

if (!function_exists('imagewebp')) {
    fwrite(STDERR, "В этой сборке PHP нет GD с поддержкой WebP — пропускаем.\n");
    exit(1);
}

// __DIR__ = public_html/app/scripts → два уровня вверх это корень сайта
$imgDir = dirname(__DIR__, 2) . '/assets/img';
$files = array_merge(
    glob($imgDir . '/*.jpg') ?: [],
    glob($imgDir . '/gallery/*.jpg') ?: [],
);

foreach ($files as $jpg) {
    $webp = substr($jpg, 0, -4) . '.webp';
    if (is_file($webp) && filemtime($webp) >= filemtime($jpg)) {
        continue;
    }
    $im = imagecreatefromjpeg($jpg);
    if ($im === false) {
        fwrite(STDERR, "Не прочитан: {$jpg}\n");
        continue;
    }
    imagewebp($im, $webp, 78);
    imagedestroy($im);
    printf("OK: %s (%d КБ)\n", basename($webp), (int) (filesize($webp) / 1024));
}
echo "Готово.\n";

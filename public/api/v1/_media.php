<?php
declare(strict_types=1);

function nj_media_metadata(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    try {
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
    } catch (Throwable) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function nj_media_relative_file(string $value): string
{
    $value = str_replace('\\', '/', trim($value));
    $value = ltrim($value, '/');

    if (
        $value === ''
        || str_contains($value, "\0")
        || preg_match('#(^|/)\.\.?(/|$)#', $value) === 1
        || str_contains($value, ':')
    ) {
        return '';
    }

    return $value;
}

function nj_media_upload_url(string $relativeFile): string
{
    $relativeFile = nj_media_relative_file($relativeFile);

    return $relativeFile !== ''
        ? '/wp-content/uploads/' . $relativeFile
        : '';
}

function nj_media_local_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $path = parse_url($url, PHP_URL_PATH);
    if (is_string($path) && str_starts_with($path, '/wp-content/uploads/')) {
        return $path;
    }

    return $url;
}

function nj_media_descriptor(
    string $guid,
    string $attachedFile,
    string $metadataRaw,
    string $alt,
    string $fallbackAlt = ''
): ?array {
    $metadata = nj_media_metadata($metadataRaw);
    $metadataFile = nj_media_relative_file((string) ($metadata['file'] ?? ''));
    $attachedFile = nj_media_relative_file($attachedFile);
    $relativeFile = $metadataFile !== '' ? $metadataFile : $attachedFile;

    $url = $relativeFile !== ''
        ? nj_media_upload_url($relativeFile)
        : nj_media_local_url($guid);

    if ($url === '') {
        return null;
    }

    $width = max(0, (int) ($metadata['width'] ?? 0));
    $height = max(0, (int) ($metadata['height'] ?? 0));
    $alt = trim($alt) !== '' ? trim($alt) : trim($fallbackAlt);

    $variants = [];
    $variantKeys = [];

    $addVariant = static function (
        array &$variants,
        array &$variantKeys,
        string $name,
        string $variantUrl,
        int $variantWidth,
        int $variantHeight
    ): void {
        if ($variantUrl === '' || $variantWidth <= 0) {
            return;
        }

        $key = $variantWidth . '|' . $variantUrl;
        if (isset($variantKeys[$key])) {
            return;
        }

        $variantKeys[$key] = true;
        $variants[] = [
            'name' => $name,
            'url' => $variantUrl,
            'width' => $variantWidth,
            'height' => max(0, $variantHeight),
        ];
    };

    $sizes = is_array($metadata['sizes'] ?? null) ? $metadata['sizes'] : [];
    $baseDirectory = $relativeFile !== '' ? dirname($relativeFile) : '';
    if ($baseDirectory === '.') {
        $baseDirectory = '';
    }

    foreach ($sizes as $name => $size) {
        if (!is_array($size)) {
            continue;
        }

        $file = nj_media_relative_file((string) ($size['file'] ?? ''));
        if ($file === '' || str_contains($file, '/')) {
            continue;
        }

        $variantRelative = $baseDirectory !== ''
            ? $baseDirectory . '/' . $file
            : $file;

        $addVariant(
            $variants,
            $variantKeys,
            is_string($name) ? $name : 'derived',
            nj_media_upload_url($variantRelative),
            max(0, (int) ($size['width'] ?? 0)),
            max(0, (int) ($size['height'] ?? 0))
        );
    }

    if ($width > 0) {
        $addVariant(
            $variants,
            $variantKeys,
            'original',
            $url,
            $width,
            $height
        );
    }

    usort(
        $variants,
        static fn (array $left, array $right): int =>
            $left['width'] <=> $right['width']
            ?: strcmp((string) $left['url'], (string) $right['url'])
    );

    $srcSet = implode(
        ', ',
        array_map(
            static fn (array $variant): string =>
                (string) $variant['url'] . ' ' . (int) $variant['width'] . 'w',
            $variants
        )
    );

    return [
        'url' => $url,
        'alt' => $alt,
        'width' => $width > 0 ? $width : null,
        'height' => $height > 0 ? $height : null,
        'srcSet' => $srcSet,
        'variants' => $variants,
    ];
}

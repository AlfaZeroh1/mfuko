<?php
declare(strict_types=1);

/**
 * Image uploads (product photos, avatars, business logo).
 * Validates real image type, re-encodes with GD when available (strips EXIF,
 * resizes, converts to WebP/JPEG) so phone photos don't bloat the sell page.
 */
final class Upload
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** Accepts a $_FILES entry or a data: URL (camera capture). Returns relative path. */
    public static function image($source, string $folder, int $maxSide = 800): string
    {
        $tmp = null;
        if (is_string($source) && str_starts_with($source, 'data:image/')) {
            if (!preg_match('#^data:image/(png|jpe?g|webp);base64,#', $source, $m)) throw new ApiError('Unsupported image');
            $bin = base64_decode(substr($source, strpos($source, ',') + 1), true);
            if ($bin === false || strlen($bin) > 8 * 1024 * 1024) throw new ApiError('Image too large');
            $tmp = tempnam(sys_get_temp_dir(), 'mf');
            file_put_contents($tmp, $bin);
        } elseif (is_array($source) && ($source['error'] ?? 1) === UPLOAD_ERR_OK) {
            if ($source['size'] > 8 * 1024 * 1024) throw new ApiError('Image too large (max 8MB)');
            $tmp = $source['tmp_name'];
        } else {
            throw new ApiError('No image received');
        }

        $info = @getimagesize($tmp);
        $mime = $info['mime'] ?? '';
        if (!isset(self::TYPES[$mime])) throw new ApiError('File is not a supported image');

        $dir = MF_ROOT . '/uploads/' . trim($folder, '/');
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = date('Ymd') . '-' . bin2hex(random_bytes(6));

        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring((string)file_get_contents($tmp));
            if ($img) {
                [$w, $h] = [imagesx($img), imagesy($img)];
                $scale = min(1, $maxSide / max($w, $h));
                if ($scale < 1) {
                    $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
                    $dst = imagecreatetruecolor($nw, $nh);
                    imagealphablending($dst, false); imagesavealpha($dst, true);
                    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    imagedestroy($img);
                    $img = $dst;
                } else {
                    imagesavealpha($img, true);
                }
                if (function_exists('imagewebp')) {
                    $file = "$name.webp"; imagewebp($img, "$dir/$file", 82);
                } elseif ($mime === 'image/png') {
                    $file = "$name.png"; imagepng($img, "$dir/$file", 7);
                } else {
                    $file = "$name.jpg"; imagejpeg($img, "$dir/$file", 82);
                }
                imagedestroy($img);
                return 'uploads/' . trim($folder, '/') . '/' . $file;
            }
        }
        $file = $name . '.' . self::TYPES[$mime];
        if (is_uploaded_file($tmp)) move_uploaded_file($tmp, "$dir/$file"); else rename($tmp, "$dir/$file");
        return 'uploads/' . trim($folder, '/') . '/' . $file;
    }

    public static function delete(?string $path): void
    {
        if (!$path || !str_starts_with($path, 'uploads/') || str_contains($path, '..')) return;
        $f = MF_ROOT . '/' . $path;
        if (is_file($f)) @unlink($f);
    }
}

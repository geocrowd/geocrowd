<?php

namespace Geocrowd;

/**
 * Images jointes aux points. Chaque fichier est réencodé : les métadonnées
 * (EXIF, géolocalisation de l'appareil) sont supprimées et la taille est bornée.
 */
final class Images
{
    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    private const MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    private const MAX_SIDE = 2000;
    public const NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)$/';

    public function __construct(private readonly string $dir)
    {
    }

    /** Erreur de validation d'un fichier envoyé, ou null s'il est acceptable. */
    public static function check(array $file, int $maxMb): ?string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return 'Envoi du fichier échoué.';
        }
        if ($file['size'] > $maxMb * 1024 * 1024) {
            return "$maxMb Mo maximum par image.";
        }
        $info = @getimagesize($file['tmp_name']);
        return $info && isset(self::TYPES[$info[2]]) ? null : 'Format accepté : JPEG, PNG ou WebP.';
    }

    /** Enregistre des fichiers déjà validés et renvoie leurs noms. */
    public function store(array $files): array
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0750, true);
        }
        return array_map(fn (array $file) => $this->storeOne($file['tmp_name']), $files);
    }

    /** Place occupée par les images enregistrées, en octets. */
    public function size(): int
    {
        return array_sum(array_map('filesize', glob($this->dir . '/*') ?: []));
    }

    public function delete(array $names): void
    {
        foreach ($names as $name) {
            if (preg_match(self::NAME_PATTERN, $name)) {
                @unlink($this->dir . '/' . $name);
            }
        }
    }

    public function send(string $name): void
    {
        $path = $this->dir . '/' . $name;
        if (!preg_match(self::NAME_PATTERN, $name) || !is_file($path)) {
            throw new HttpError(404, 'Image introuvable.');
        }
        header('Content-Type: ' . self::MIME[pathinfo($name, PATHINFO_EXTENSION)]);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    private function storeOne(string $tmp): string
    {
        $ext = self::TYPES[getimagesize($tmp)[2]];
        $image = imagecreatefromstring(file_get_contents($tmp));
        $image = $this->orient($image, $tmp, $ext);
        $image = $this->fit($image);
        imagesavealpha($image, true);

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $path = $this->dir . '/' . $name;
        match ($ext) {
            'jpg' => imagejpeg($image, $path, 85),
            'png' => imagepng($image, $path, 6),
            'webp' => imagewebp($image, $path, 85),
        };
        imagedestroy($image);
        return $name;
    }

    /** Applique l'orientation EXIF avant qu'elle ne soit perdue au réencodage. */
    private function orient(\GdImage $image, string $tmp, string $ext): \GdImage
    {
        if ($ext !== 'jpg' || !function_exists('exif_read_data')) {
            return $image;
        }
        $angle = match (@exif_read_data($tmp)['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        return $angle ? imagerotate($image, $angle, 0) : $image;
    }

    private function fit(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = self::MAX_SIDE / max($width, $height);
        if ($ratio >= 1) {
            return $image;
        }
        return imagescale($image, (int) round($width * $ratio), (int) round($height * $ratio));
    }
}

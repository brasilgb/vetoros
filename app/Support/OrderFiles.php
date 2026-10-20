<?php

namespace App\Support;

use App\Models\App\Image;
use App\Models\App\Order;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Fotos e assinatura da OS em armazenamento privado, separado por empresa e por OS
 * ({tenant_id}/{order_id}/...). Antes ficavam em public/storage/orders/{número da OS}, pasta
 * que o número sequencial por empresa fazia ser compartilhada entre empresas.
 *
 * Os arquivos só saem por URL assinada e temporária, gerada depois de a requisição ter sido
 * autorizada (tela da OS, API do app ou portal público com acesso liberado).
 */
final class OrderFiles
{
    public const DISK = 'order_files';

    private const SIGNATURE = 'signature.png';

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public static function directory(Order $order): string
    {
        return (int) $order->tenant_id.'/'.(int) $order->id;
    }

    public static function imagePath(Order $order, string $filename): string
    {
        return self::directory($order).'/images/'.basename($filename);
    }

    public static function signaturePath(Order $order): string
    {
        return self::directory($order).'/'.self::SIGNATURE;
    }

    /** Grava a foto com nome aleatório e extensão pelo conteúdo (nunca a do cliente). */
    public static function storeUploadedImage(Order $order, UploadedFile $file): string
    {
        $filename = Str::random(40).'.'.($file->guessExtension() ?: 'bin');
        self::disk()->putFileAs(self::directory($order).'/images', $file, $filename);

        return $filename;
    }

    public static function storeImageContents(Order $order, string $contents, string $extension = 'png'): string
    {
        $filename = Str::random(40).'.'.$extension;
        self::disk()->put(self::imagePath($order, $filename), $contents);

        return $filename;
    }

    public static function imageContents(Image $image, ?Order $order = null): ?string
    {
        $order ??= self::orderOf($image);
        if (! $order) {
            return null;
        }

        $path = self::imagePath($order, $image->filename);
        if (self::disk()->exists($path)) {
            return self::disk()->get($path);
        }

        $legacy = self::legacyImagePath($image, $order);

        return $legacy ? file_get_contents($legacy) : null;
    }

    public static function deleteImage(Image $image, Order $order): void
    {
        self::disk()->delete(self::imagePath($order, $image->filename));

        if ($legacy = self::legacyImagePath($image, $order)) {
            @unlink($legacy);
        }
    }

    public static function imageUrl(Image $image): string
    {
        return URL::temporarySignedRoute('order-files.image', self::expiresAt(), ['image' => $image->getKey()]);
    }

    public static function storeSignature(Order $order, string $contents): void
    {
        self::disk()->put(self::signaturePath($order), $contents);
    }

    public static function signatureContents(Order $order): ?string
    {
        $path = self::signaturePath($order);

        return self::disk()->exists($path) ? self::disk()->get($path) : null;
    }

    public static function hasSignature(Order $order): bool
    {
        return self::disk()->exists(self::signaturePath($order));
    }

    public static function signatureUrl(Order $order): ?string
    {
        if (! self::hasSignature($order)) {
            return null;
        }

        // `v` muda a cada nova assinatura, para o navegador não mostrar a anterior em cache.
        return URL::temporarySignedRoute('order-files.signature', self::expiresAt(), [
            'order' => $order->getKey(),
            'v' => self::disk()->lastModified(self::signaturePath($order)),
        ]);
    }

    /**
     * Foto antiga ainda não migrada, em public/storage/orders/{número ou id da OS}/{arquivo}.
     * Só é usada quando nenhuma outra foto (de qualquer empresa) tem o mesmo nome de arquivo:
     * nome repetido na pasta compartilhada é ambíguo e fica para a migração assistida.
     */
    public static function legacyImagePath(Image $image, Order $order): ?string
    {
        $filename = basename((string) $image->filename);
        if ($filename === '' || self::legacyFilenameIsShared($image)) {
            return null;
        }

        $root = rtrim((string) config('order_files.legacy_root'), '/');
        foreach (array_unique([(string) $order->order_number, (string) $order->id]) as $folder) {
            $path = $root.'/'.$folder.'/'.$filename;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function legacyFilenameIsShared(Image $image): bool
    {
        return Image::withoutGlobalScopes()
            ->where('filename', $image->filename)
            ->whereKeyNot($image->getKey())
            ->exists();
    }

    public static function mimeType(string $contents): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';

        // Só imagens rasterizadas saem como imagem; qualquer outra coisa vira download inerte.
        return in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) ? $mime : 'application/octet-stream';
    }

    private static function orderOf(Image $image): ?Order
    {
        return Order::withoutGlobalScopes()->find($image->order_id);
    }

    private static function expiresAt(): \DateTimeInterface
    {
        return now()->addMinutes(max(1, (int) config('order_files.url_ttl_minutes', 60)));
    }
}

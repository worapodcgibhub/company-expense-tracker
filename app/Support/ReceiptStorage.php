<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where and how receipt files are kept.
 *
 * - Always the private disk ("local" = storage/app/private). Files are
 *   never reachable by a public URL; they are only sent by
 *   ChargeReceiptController after a permission check.
 * - The stored name is random; the extension comes from the REAL content
 *   type (detected from the file itself), never from what the user typed.
 * - Only PDF, JPEG and PNG, at most MAX_KB. The extension, the detected
 *   content type and the file's first bytes must all match one of them.
 */
class ReceiptStorage
{
    public const DISK = 'local';

    /**
     * 2 MB, matching PHP's upload_max_filesize on this server.
     */
    public const MAX_KB = 2048;

    public const MAX_PER_CHARGE = 10;

    /**
     * Allowed real content types => stored extension.
     */
    public const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * First bytes of each allowed type ("magic numbers").
     */
    private const SIGNATURES = [
        'application/pdf' => '%PDF-',
        'image/jpeg' => "\xFF\xD8\xFF",
        'image/png' => "\x89PNG\r\n\x1A\n",
    ];

    /**
     * Laravel validation rules for an uploaded document: extension, the
     * detected content type, AND the file's own first bytes must all agree.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return [
            'required',
            'file',
            'max:'.self::MAX_KB,
            'extensions:pdf,jpg,jpeg,png',
            'mimetypes:'.implode(',', array_keys(self::TYPES)),
            function (string $attribute, mixed $value, \Closure $fail) {
                if (! $value instanceof UploadedFile || self::detectType($value) === null) {
                    $fail('เนื้อไฟล์ไม่ใช่ PDF, JPG หรือ PNG จริง');
                }
            },
        ];
    }

    /**
     * The allowed type the file's content really is, or null.
     */
    public static function detectType(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        $handle = $path !== false ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, 8);
        fclose($handle);

        foreach (self::SIGNATURES as $mime => $signature) {
            if (str_starts_with($head, $signature)) {
                return $mime;
            }
        }

        return null;
    }

    /**
     * Saves the file under a random name and returns what to keep in the
     * database.
     *
     * @return array{disk: string, path: string, mime: string, size: int, sha256: string, original_name: string}
     */
    public static function store(UploadedFile $file): array
    {
        // The type comes from the content itself, never from the name.
        $mime = self::detectType($file) ?? throw new RuntimeException('Unsupported receipt type.');
        $extension = self::TYPES[$mime];

        $realPath = $file->getRealPath();
        $sha256 = $realPath !== false ? hash_file('sha256', $realPath) : false;

        if ($sha256 === false) {
            throw new RuntimeException('The uploaded receipt could not be read.');
        }

        $directory = 'receipts/'.now()->format('Y/m');
        $name = Str::uuid()->toString().'.'.$extension;

        $path = $file->storeAs($directory, $name, self::DISK);

        if ($path === false) {
            throw new RuntimeException('The receipt could not be stored.');
        }

        return [
            'disk' => self::DISK,
            'path' => $path,
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'sha256' => $sha256,
            'original_name' => self::cleanName($file->getClientOriginalName(), $extension),
        ];
    }

    /**
     * Removes a file that was stored moments ago when saving its database
     * row failed (so no orphan is left). Never used on documents that were
     * saved: those are kept forever.
     */
    public static function discardUnsaved(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * A safe display name: no folders, no control characters, max 255.
     */
    public static function cleanName(string $name, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"]/u', '', $name);
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'document.'.$extension;
        }

        return Str::limit($name, 250, '');
    }

    public static function sizeLabel(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? number_format($bytes / 1_048_576, 1).' MB'
            : number_format(max(1, (int) round($bytes / 1024))).' KB';
    }
}

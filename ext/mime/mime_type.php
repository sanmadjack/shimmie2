<?php declare(strict_types=1);

require_once "file_extension.php";

class MimeType
{
    // Couldn't find a mimetype for ani, so made one up based on it being a riff container
    public const ANI = 'application/riff+ani';
    public const ASF = 'video/x-ms-asf';
    public const AVI = 'video/x-msvideo';
    // Went with mime types from http://fileformats.archiveteam.org/wiki/Comic_Book_Archive
    public const COMIC_ZIP = 'application/vnd.comicbook+zip';
    public const COMIC_RAR = 'application/vnd.comicbook-rar';
    public const BMP = 'image/x-ms-bmp';
    public const BZIP = 'application/x-bzip';
    public const BZIP2 = 'application/x-bzip2';
    public const CSS = 'text/css';
    public const CSV = 'text/csv';
    public const FLASH = 'application/x-shockwave-flash';
    public const FLASH_VIDEO = 'video/x-flv';
    public const GIF = 'image/gif';
    public const GZIP = 'application/x-gzip';
    public const HTML = 'text/html';
    public const ICO = 'image/x-icon';
    public const ICO_OSX = 'image/vnd.microsoft.icon';
    public const JPEG = 'image/jpeg';
    public const JS = 'text/javascript';
    public const JSON = 'application/json';
    public const MKV = 'video/x-matroska';
    public const MP3 = 'audio/mpeg';
    public const MP4_AUDIO = 'audio/mp4';
    public const MP4_VIDEO = 'video/mp4';
    public const MPEG = 'video/mpeg';
    public const OCTET_STREAM = 'application/octet-stream';
    public const OGG = 'application/ogg';
    public const OGG_VIDEO = 'video/ogg';
    public const OGG_AUDIO = 'audio/ogg';
    public const PDF = 'application/pdf';
    public const PHP = 'text/x-php';
    public const PNG = 'image/png';
    public const PPM = 'image/x-portable-pixmap';
    public const PSD = 'image/vnd.adobe.photoshop';
    public const QUICKTIME = 'video/quicktime';
    public const RSS = 'application/rss+xml';
    public const SVG = 'image/svg+xml';
    public const STL = 'model/stl';
    public const TAR = 'application/x-tar';
    public const TGA = 'image/x-tga';
    public const TEXT = 'text/plain';
    public const TIFF = 'image/tiff';
    public const WAV = 'audio/x-wav';
    public const WEBM = 'video/webm';
    public const WEBP = 'image/webp';
    public const WEBP_LOSSLESS = self::WEBP."; ".self::LOSSLESS_PARAMETER;
    public const WIN_BITMAP = 'image/x-win-bitmap';
    public const WMA = 'audio/x-ms-wma';
    public const WMV = 'video/x-ms-wmv';
    public const XML = 'text/xml';
    public const XML_APPLICATION = 'application/xml';
    public const XSL = 'application/xsl+xml';
    public const ZIP = 'application/zip';

    public const LOSSLESS_PARAMETER = "lossless=true";

    public const CHARSET_UTF8 = "charset=utf-8";

    //RIFF####WEBPVP8?..............ANIM
    private const WEBP_ANIMATION_HEADER =
        [0x52, 0x49, 0x46, 0x46, null, null, null, null, 0x57, 0x45, 0x42, 0x50, 0x56, 0x50, 0x38, null,
            null, null, null, null, null, null, null, null, null, null, null, null, null, null, 0x41, 0x4E, 0x49, 0x4D];

    //RIFF####WEBPVP8L
    private const WEBP_LOSSLESS_HEADER =
        [0x52, 0x49, 0x46, 0x46, null, null, null, null, 0x57, 0x45, 0x42, 0x50, 0x56, 0x50, 0x38, 0x4C];

    //RIFF####WEBPVP8X
    private const WEBP_EXTENDED_HEADER =
        [0x52, 0x49, 0x46, 0x46, null, null, null, null, 0x57, 0x45, 0x42, 0x50, 0x56, 0x50, 0x38, 0x58];

    //VP8L
    private const WEBP_EXTENDED_VP8L_CHUNK =
        [0x56, 0x50, 0x38, 0x4C];

    private const REGEX_MIME_TYPE = "/^([-\w.]+)\/([-\w.]+)(;.+)?$/";

    public static function is_mime(string $value): bool
    {
        return preg_match(self::REGEX_MIME_TYPE, $value)===1;
    }

    public static function add_parameters(String $mime, String...$parameters): string
    {
        if (empty($parameters)) {
            return $mime;
        }
        return $mime."; ".join("; ", $parameters);
    }

    public static function remove_parameters(string $mime): string
    {
        $i = strpos($mime, ";");
        if ($i!==false) {
            return substr($mime, 0, $i);
        }
        return $mime;
    }

    public static function matches_array(string $mime, array $mime_array, bool $exact = false): bool
    {
        // If there's an exact match, find it and that's it
        if (in_array($mime, $mime_array)) {
            return true;
        }
        if ($exact) {
            return false;
        }

        $mime = self::remove_parameters($mime);

        return in_array($mime, $mime_array);
    }

    public static function matches(string $mime1, string $mime2, bool $exact = false): bool
    {
        if (!$exact) {
            $mime1 = self::remove_parameters($mime1);
            $mime2 = self::remove_parameters($mime2);
        }
        return strtolower($mime1)===strtolower($mime2);
    }


    /**
     * Determines if a file is an animated gif.
     *
     * @param String $image_filename The path of the file to check.
     * @return bool true if the file is an animated gif, false if it is not.
     */
    public static function is_animated_gif(string $image_filename): bool
    {
        $is_anim_gif = 0;
        if (($fh = @fopen($image_filename, 'rb'))) {
            try {
                //check if gif is animated (via https://www.php.net/manual/en/function.imagecreatefromgif.php#104473)
                $chunk = false;

                while (!feof($fh) && $is_anim_gif < 2) {
                    $chunk =  ($chunk ? substr($chunk, -20) : "") . fread($fh, 1024 * 100); //read 100kb at a time
                    $is_anim_gif += preg_match_all('#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $chunk, $matches);
                }
            } finally {
                @fclose($fh);
            }
        }
        return ($is_anim_gif >=2);
    }


    private static function compare_file_bytes(string $file_name, array $comparison, int $offset = 0): bool
    {
        $size = filesize($file_name);
        if ($size < count($comparison)) {
            // Can't match because it's too small
            return false;
        }

        if (($fh = @fopen($file_name, 'rb'))) {
            try {
                if ($offset>0) {
                    fseek($fh, $offset);
                }
                $chunk = unpack("C*", fread($fh, count($comparison)));

                return self::compare_buffers($chunk, $comparison, 1);
            } finally {
                @fclose($fh);
            }
        } else {
            throw new MediaException("Unable to open file for byte check: $file_name");
        }
    }

    private static function compare_buffers(array $file_buffer, array $comparison_buffer, int $file_byte_offset = 0)
    {
        if (sizeof($file_buffer)!=sizeof($comparison_buffer)) {
            return false;
        }

        for ($i = 0; $i < count($file_buffer); $i++) {
            $comparison_byte = $comparison_buffer[$i];
            if ($comparison_byte == null) {
                continue;
            } else {
                $fileByte = $file_buffer[$i + $file_byte_offset];
                if ($fileByte != $comparison_byte) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function search_for_bytes(string $file_name, array $comparison, int $offset = 0): bool
    {
        if (empty($comparison)) {
            throw new Exception("comparison cannot be empty");
        }
        if ($comparison[0]==null) {
            throw new Exception("First value of comparison must be a value, you likely want to use offset");
        }

        $size = filesize($file_name);
        if ($size < count($comparison)) {
            // Can't match because it's too small
            return false;
        }

        if (($fh = @fopen($file_name, 'rb'))) {
            try {
                $first = $comparison[0];
                if ($offset>0) {
                    fseek($fh, $offset);
                }
                $buffer = null;
                while (!feof($fh)) {
                    if ($buffer!=null) {
                        // Make sure that we bring the last n bytes before the next chunk to make sure we don't miss
                        // a result that splits across a chunk divide.
                        fseek($fh, sizeof($comparison) * -1, SEEK_CUR);
                    }
                    $buffer = unpack("C*", fread($fh, 1000));

                    for ($i = 1; $i < sizeof($buffer); $i++) {
                        if ($buffer[$i]==$first) {
                            // We found the first value. Now we check if the subsequent values are present.
                            $chunk = array_slice($buffer, $i-1, sizeof($comparison));
                            if (self::compare_buffers($chunk, $comparison)) {
                                return true;
                            }
                        }
                    }
                } ;
                return false;
            } finally {
                @fclose($fh);
            }
        } else {
            throw new MediaException("Unable to open file for byte check: $file_name");
        }
    }

    public static function is_animated_webp(string $image_filename): bool
    {
        // TODO: Implement extended header format detection
        return self::compare_file_bytes($image_filename, self::WEBP_ANIMATION_HEADER);
    }

    public static function is_lossless_webp(string $image_filename): bool
    {
        if (self::compare_file_bytes($image_filename, self::WEBP_LOSSLESS_HEADER)) {
            return true;
        }
        if (self::compare_file_bytes($image_filename, self::WEBP_EXTENDED_HEADER)) {
            // Webp has an alternate extended header format that organizes the information differently.
            // this extended format stores animated and non-animated data in a very similar format,
            // with a non-animated image just having a single frame.
            // The exact position varies based on what chunks come before it, so we basically just have to search for VP8L
            return self::search_for_bytes($image_filename, self::WEBP_EXTENDED_VP8L_CHUNK, 30);
        }
        return false;
    }





    /**
     * Returns the mimetype that matches the provided extension.
     */
    public static function get_for_extension(string $ext): ?string
    {
        $data = MimeMap::get_for_extension($ext);
        if ($data!=null) {
            return $data[MimeMap::MAP_MIME][0];
        }
        // This was an old solution for differentiating lossless webps
        if ($ext==="webp-lossless") {
            return MimeType::WEBP_LOSSLESS;
        }
        return null;
    }

    /**
     * Returns the mimetype for the specified file via file inspection
     * @param String $file
     * @return String The mimetype that was found. Returns generic octet binary mimetype if not found.
     */
    public static function get_for_file(string $file, ?string $ext = null): string
    {
        if (!file_exists($file)) {
            throw new SCoreException("File not found: ".$file);
        }

        $output = self::OCTET_STREAM;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        try {
            $type = finfo_file($finfo, $file);
        } finally {
            finfo_close($finfo);
        }

        if ($type !== false && !empty($type)) {
            $output = $type;
        }

        if (!empty($ext)) {
            // Here we handle the few file types that need extension-based handling
            $ext = strtolower($ext);
            if ($type===MimeType::ZIP && $ext===FileExtension::CBZ) {
                $output = MimeType::COMIC_ZIP;
            }
            if ($type===MimeType::OCTET_STREAM) {
                switch ($ext) {
                    case FileExtension::ANI:
                        $output = MimeType::ANI;
                        break;
                    case FileExtension::PPM:
                        $output = MimeType::PPM;
                        break;
// TODO: There is no uniquely defined Mime type for the cursor format. Need to figure this out.
//                    case FileExtension::CUR:
//                        $output = MimeType::CUR;
//                        break;
                }
            }
        }

        // TODO: Implement manual byte inspections for supported esoteric formats, like ANI

        return $output;
    }
}

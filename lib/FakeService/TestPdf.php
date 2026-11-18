<?php

declare(strict_types=1);

namespace Aclips\Fakesign\FakeService;

/**
 * Минимальный валидный PDF-1.4 без внешних зависимостей - тестовый подписанный файл.
 *
 * Маркировка: заметный латинский маркер в потоке текста (виден в любом просмотрщике)
 * и «ТЕСТ» в свойствах документа (/Info /Title, UTF-16BE - показывается в свойствах файла).
 */
final class TestPdf
{
    /**
     * Возвращает минимальный валидный PDF с тестовой маркировкой TEST/ТЕСТ.
     */
    public static function render(string $title): string
    {
        $line1 = self::escape('TEST - NOT A LEGAL SIGNATURE');
        $line2 = self::escape('Document: ' . $title);

        $stream = "BT /F1 14 Tf 50 780 Td ($line1) Tj T* /F1 11 Tf ($line2) Tj ET";
        $content = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";

        $marked = 'ТЕСТ: подписание эмулировано, подпись не является электронной';
        $infoTitle = "\xEF\xFF" . iconv('UTF-8', 'UTF-16BE', $marked);

        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R"
                . " /Resources << /Font << /F1 5 0 R >> >> >>",
            4 => $content,
            5 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
            6 => "<< /Title (" . $infoTitle . ") /Producer (aclips.fakesign) /Creator (aclips.fakesign) >>",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefPosition = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= 'xref' . "\n" . '0 ' . $size . "\n" . '0000000000 65535 f ' . "\n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer' . "\n" . '<< /Size ' . $size . ' /Root 1 0 R /Info 6 0 R >>' . "\n"
            . 'startxref' . "\n" . $xrefPosition . "\n" . '%%EOF';

        return $pdf;
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}

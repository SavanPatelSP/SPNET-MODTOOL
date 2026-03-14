<?php

namespace App\Services;

class PdfService
{
    public function isAvailable(): bool
    {
        $path = trim((string)shell_exec('command -v wkhtmltopdf 2>/dev/null'));
        return $path !== '';
    }

    public function htmlToPdf(string $htmlFile, string $pdfFile): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $cmd = sprintf('wkhtmltopdf %s %s', escapeshellarg($htmlFile), escapeshellarg($pdfFile));
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        return $code === 0 && file_exists($pdfFile);
    }
}

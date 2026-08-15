<?php

namespace App\Http\Controllers;

use App\Support\RequestCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RequestCatalogController extends Controller
{
    public function index(Request $request)
    {
        try {
            return response()->json(RequestCatalog::listings($request->user()));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el catalogo de solicitudes.',
            ], 500);
        }
    }

    public function showTransfer(Request $request, string $number)
    {
        try {
            $detail = RequestCatalog::transferDetail($number, $request->user());

            if ($detail === null) {
                return response()->json([
                    'message' => 'Transferencia no encontrada.',
                ], 404);
            }

            return response()->json($detail);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el detalle de la transferencia.',
            ], 500);
        }
    }

    public function showTransferBoxLabel(Request $request, string $number, string $boxNumber)
    {
        try {
            $detail = RequestCatalog::transferDetail($number, $request->user());

            if ($detail === null) {
                return response()->json([
                    'message' => 'Transferencia no encontrada.',
                ], 404);
            }

            if (!($detail['canViewBoxLocationLabels'] ?? false)) {
                return response()->json([
                    'message' => 'No tiene permisos para consultar esta etiqueta.',
                ], 403);
            }

            $normalizedBoxNumber = str_pad((string) ((int) $boxNumber), 3, '0', STR_PAD_LEFT);
            $box = collect($detail['boxes'] ?? [])
                ->first(fn ($item) => ($item['number'] ?? null) === $normalizedBoxNumber);

            if (!$box) {
                return response()->json([
                    'message' => 'Caja no encontrada para esta transferencia.',
                ], 404);
            }

            $boxCode = $box['boxCode'] ?? $box['code'] ?? ('C-' . $detail['number'] . '-' . $normalizedBoxNumber);
            $content = $box['contentDescription'] ?? $box['title'] ?? '';

            if (str_contains($content, ' - ')) {
                $content = trim(collect(explode(' - ', $content))->slice(1)->implode(' - '));
            }

            if ($content === '' || preg_match('/^Caja\s+(#?\d+|C-)/i', $content) === 1) {
                $firstDocument = collect($box['documents'] ?? [])->first();
                $content = $firstDocument['series'] ?? $firstDocument['name'] ?? ('Documentacion de caja ' . $normalizedBoxNumber);
            }

            return response()->json([
                'transferNumber' => $detail['number'],
                'transferDate' => $detail['completion']['date'] ?? $detail['receivedAt'] ?? null,
                'unit' => $detail['unit'],
                'authorizationStatus' => $detail['auth'],
                'workflowStatus' => $detail['status'],
                'boxNumber' => $box['number'],
                'boxCode' => $boxCode,
                'boxLabel' => $boxCode,
                'labelCode' => $boxCode,
                'locationCode' => $box['physicalLocation'],
                'period' => $box['period'],
                'content' => $content,
                'assignedBy' => $detail['completion']['completedBy'] ?? $detail['authorizedBy'] ?? 'UGDA',
                'assignedAt' => trim(($detail['completion']['date'] ?? '') . ' ' . ($detail['completion']['time'] ?? '')) ?: ($detail['authorizedAt'] ?? null),
                'documents' => $box['documents'] ?? [],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar la informacion de la etiqueta.',
            ], 500);
        }
    }

    public function showLoan(Request $request, string $number)
    {
        try {
            $detail = RequestCatalog::loanDetail($number, $request->user());

            if ($detail === null) {
                return response()->json([
                    'message' => 'Préstamo no encontrado.',
                ], 404);
            }

            return response()->json($detail);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el detalle del prestamo.',
            ], 500);
        }
    }

    public function downloadTransferDocument(Request $request, string $number, string $code)
    {
        try {
            $document = RequestCatalog::transferDocumentDownload($number, $code, $request->user());

            if ($document === null) {
                return response()->json([
                    'message' => 'Documento digital no encontrado.',
                ], 404);
            }

            $fileName = $document['digitalFile'] ?? ($document['code'] . '.pdf');
            $filePath = $this->resolveDigitalDocumentPath($document['digitalPath'] ?? null);

            if ($filePath !== null) {
                return response()->file($filePath, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="' . addslashes($fileName) . '"',
                ]);
            }

            return response($this->buildDigitalDocumentPreviewPdf($number, $document), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . addslashes($fileName) . '"',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo descargar el documento digital.',
            ], 500);
        }
    }

    public function downloadTransferSummarySheet(Request $request, string $number)
    {
        try {
            $summary = RequestCatalog::transferSummarySheet($number, $request->user());

            if ($summary === null) {
                return response()->json([
                    'message' => 'Hoja resumen de transferencia no disponible.',
                ], 404);
            }

            $fileName = 'hoja-resumen-transferencia-' . $number . '.pdf';

            return response($this->buildTransferSummarySheetPdf($summary), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . addslashes($fileName) . '"',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo generar la hoja resumen de transferencia.',
            ], 500);
        }
    }

    public function downloadLoanSummarySheet(Request $request, string $number)
    {
        try {
            $summary = RequestCatalog::loanSummarySheet($number, $request->user());

            if ($summary === null) {
                return response()->json([
                    'message' => 'Hoja de préstamo no disponible.',
                ], 404);
            }

            $fileName = 'hoja-prestamo-' . $number . '.pdf';

            return response($this->buildLoanSummarySheetPdf($summary), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . addslashes($fileName) . '"',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo generar la hoja de préstamo.',
            ], 500);
        }
    }

    public function dashboard(Request $request)
    {
        try {
            return response()->json(RequestCatalog::dashboard($request->user()));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el dashboard.',
            ], 500);
        }
    }

    private function resolveDigitalDocumentPath(?string $digitalPath): ?string
    {
        $normalizedPath = str_replace('\\', '/', trim((string) $digitalPath));

        if ($normalizedPath === '' || str_contains($normalizedPath, '..')) {
            return null;
        }

        $candidatePaths = array_values(array_unique(array_filter([
            $normalizedPath,
            'public/' . $normalizedPath,
            str_starts_with($normalizedPath, 'uploads/') ? $normalizedPath : 'uploads/' . basename($normalizedPath),
            str_starts_with($normalizedPath, 'uploads/') ? 'public/' . $normalizedPath : 'public/uploads/' . basename($normalizedPath),
        ])));

        foreach ($candidatePaths as $candidatePath) {
            if (Storage::disk('local')->exists($candidatePath)) {
                return Storage::disk('local')->path($candidatePath);
            }

            if (str_starts_with($candidatePath, 'public/')) {
                $publicPath = substr($candidatePath, strlen('public/'));

                if (Storage::disk('public')->exists($publicPath)) {
                    return Storage::disk('public')->path($publicPath);
                }
            }
        }

        return null;
    }

    private function buildDigitalDocumentPreviewPdf(string $number, array $document): string
    {
        $lines = [
            'Sistema UGDA FIA UES',
            'Vista previa del documento',
            'Transferencia: ' . $number,
            'Caja: ' . ($document['boxNumber'] ?? 'N/D'),
            'Código: ' . ($document['code'] ?? 'N/D'),
            'Nombre: ' . ($document['name'] ?? 'N/D'),
            'Serie: ' . ($document['series'] ?? 'N/D'),
            'Soporte: ' . ($document['support'] ?? 'N/D'),
            'Año: ' . ($document['year'] ?? 'N/D'),
            'Paginas: ' . ($document['pages'] ?? 'N/D'),
            'Archivo registrado: ' . ($document['digitalFile'] ?? 'N/D'),
        ];

        $content = "BT\n/F1 16 Tf\n72 760 Td\n";

        foreach ($lines as $index => $line) {
            $fontSize = $index === 0 ? 18 : 12;
            $content .= "/F1 {$fontSize} Tf\n";
            $content .= '(' . $this->escapePdfText($line) . ") Tj\n";
            $content .= '0 -' . ($index === 0 ? 30 : 22) . " Td\n";
        }

        $content .= "ET\n";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n",
            "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function buildTransferSummarySheetPdf(array $summary): string
    {
        // Tamano carta vertical: 8.5 x 11 pulgadas a 72 puntos por pulgada.
        $pageWidth = 612;
        $pageHeight = 792;
        $margin = 34;
        $pages = [];
        $content = '';
        $y = 28;

        $addPage = function () use (&$pages, &$content, &$y) {
            if ($content !== '') {
                $pages[] = $content;
            }

            $content = '';
            $y = 28;
        };

        $drawText = function (float $x, float $topY, string $text, int $size = 9, string $font = 'F1', string $color = '0 0 0') use (&$content, $pageHeight) {
            $pdfY = $pageHeight - $topY;
            $content .= $color . " rg\n";
            $content .= "BT\n/{$font} {$size} Tf\n" . $x . ' ' . $pdfY . " Td\n(" . $this->escapePdfText($text) . ") Tj\nET\n";
            $content .= "0 0 0 rg\n";
        };

        $drawRect = function (float $x, float $topY, float $width, float $height, string $stroke = '0 0 0', ?string $fill = null, float $lineWidth = 0.7) use (&$content, $pageHeight) {
            $pdfY = $pageHeight - $topY - $height;
            $content .= $lineWidth . " w\n";
            $content .= $stroke . " RG\n";

            if ($fill !== null) {
                $content .= $fill . " rg\n";
                $content .= $x . ' ' . $pdfY . ' ' . $width . ' ' . $height . " re B\n";
                $content .= "0 0 0 rg\n";
            } else {
                $content .= $x . ' ' . $pdfY . ' ' . $width . ' ' . $height . " re S\n";
            }
        };

        $drawLine = function (float $x1, float $topY1, float $x2, float $topY2, string $stroke = '0 0 0', float $lineWidth = 0.5) use (&$content, $pageHeight) {
            $content .= $lineWidth . " w\n";
            $content .= $stroke . " RG\n";
            $content .= $x1 . ' ' . ($pageHeight - $topY1) . ' m ' . $x2 . ' ' . ($pageHeight - $topY2) . " l S\n";
        };

        $estimateTextWidth = function (string $text, float $fontSize): float {
            return strlen($this->escapePdfText($text)) * $fontSize * 0.54;
        };

        $wrapText = function (string $text, float $maxWidth, float $fontSize) use ($estimateTextWidth): array {
            $words = preg_split('/\s+/', trim($text)) ?: [];
            $lines = [];
            $line = '';
            $splitLongWord = function (string $word) use ($maxWidth, $fontSize, $estimateTextWidth): array {
                $parts = [];
                $part = '';

                foreach (str_split($word) as $character) {
                    $candidate = $part . $character;

                    if ($part !== '' && $estimateTextWidth($candidate, $fontSize) > $maxWidth) {
                        $parts[] = $part;
                        $part = $character;
                        continue;
                    }

                    $part = $candidate;
                }

                if ($part !== '') {
                    $parts[] = $part;
                }

                return $parts ?: [$word];
            };

            foreach ($words as $word) {
                if ($estimateTextWidth($word, $fontSize) > $maxWidth) {
                    if ($line !== '') {
                        $lines[] = $line;
                        $line = '';
                    }

                    $parts = $splitLongWord($word);
                    $line = (string) array_pop($parts);
                    $lines = array_merge($lines, $parts);
                    continue;
                }

                $candidate = trim($line . ' ' . $word);

                if ($line !== '' && $estimateTextWidth($candidate, $fontSize) > $maxWidth) {
                    $lines[] = $line;
                    $line = $word;
                    continue;
                }

                $line = $candidate;
            }

            if ($line !== '') {
                $lines[] = $line;
            }

            return $lines ?: [''];
        };

        $drawFittedText = function (float $x, float $topY, string $text, int $size = 9, string $font = 'F1', string $color = '0 0 0', ?float $maxWidth = null) use ($drawText, $estimateTextWidth) {
            $fontSize = $size;

            while ($maxWidth !== null && $fontSize > 5 && $estimateTextWidth($text, $fontSize) > $maxWidth) {
                $fontSize -= 0.5;
            }

            $drawText($x, $topY, $text, (int) floor($fontSize), $font, $color);
        };

        $drawCenteredText = function (float $topY, string $text, int $size = 9, string $font = 'F1', string $color = '0 0 0') use ($drawText, $estimateTextWidth, $pageWidth) {
            $x = max(0, ($pageWidth - $estimateTextWidth($text, $size)) / 2);
            $drawText($x, $topY, $text, $size, $font, $color);
        };

        $drawHeader = function () use (&$y, $drawCenteredText) {
            $headerColor = '0.07 0.31 0.48';

            $drawCenteredText($y + 20, 'UNIVERSIDAD DE EL SALVADOR', 9, 'F2', $headerColor);
            $drawCenteredText($y + 34, 'FACULTAD DE INGENIERIA Y ARQUITECTURA', 9, 'F2', $headerColor);
            $drawCenteredText($y + 48, 'UNIDAD DE GESTION DOCUMENTAL Y ARCHIVOS', 9, 'F2', $headerColor);
            $drawCenteredText($y + 62, 'TRANSFERENCIA DE DOCUMENTOS', 9, 'F2', $headerColor);
            $y += 78;
        };

        $drawInfoRow = function (array $items) use (&$y, $margin, $pageWidth, $drawRect, $drawText, $drawFittedText, $wrapText) {
            $cellWidth = ($pageWidth - ($margin * 2)) / count($items);
            $lineSets = [];
            $rowHeight = 34;

            foreach ($items as $index => $item) {
                $lineSets[$index] = $wrapText((string) $item['value'], $cellWidth - 14, 7);
                $rowHeight = max($rowHeight, 21 + (count($lineSets[$index]) * 9));
            }

            foreach ($items as $index => $item) {
                $x = $margin + ($cellWidth * $index);
                $drawRect($x, $y, $cellWidth, $rowHeight, '0.74 0.78 0.82', '0.96 0.98 0.99', 0.5);
                $drawText($x + 6, $y + 12, $item['label'], 6, 'F2', '0.27 0.33 0.39');

                foreach ($lineSets[$index] as $lineIndex => $line) {
                    $drawFittedText($x + 6, $y + 24 + ($lineIndex * 9), $line, 7, 'F2', '0.07 0.31 0.48', $cellWidth - 14);
                }
            }

            $y += $rowHeight;
        };

        $ensureSpace = function (float $height) use (&$y, $pageHeight, $addPage, $drawHeader) {
            if ($y + $height > $pageHeight - 42) {
                $addPage();
                $drawHeader();
            }
        };

        $drawTableRow = function (array $columns, array $widths, array $values, bool $header = false) use (&$y, $margin, $drawRect, $drawText, $drawFittedText, $wrapText) {
            $lineSets = [];
            $fontSize = $header ? 5 : 6;
            $rowHeight = $header ? 21 : 25;

            foreach ($values as $index => $value) {
                $lineSets[$index] = $wrapText((string) $value, max(18, ($widths[$index] ?? 60) - 10), $fontSize);
                $rowHeight = max($rowHeight, 13 + (count($lineSets[$index]) * 9));
            }

            $x = $margin;

            foreach ($values as $index => $value) {
                $width = $widths[$index];
                $drawRect($x, $y, $width, $rowHeight, '0.70 0.75 0.80', $header ? '0.88 0.91 0.93' : null, 0.45);

                foreach ($lineSets[$index] as $lineIndex => $line) {
                    $drawFittedText(
                        $x + 4,
                        $y + 12 + ($lineIndex * 9),
                        $line,
                        $fontSize,
                        $header ? 'F2' : 'F1',
                        $header ? '0.07 0.31 0.48' : '0.16 0.20 0.24',
                        $width - 9
                    );
                }

                $x += $width;
            }

            $y += $rowHeight;
        };

        $drawHeader();
        $drawInfoRow([
            ['label' => 'N. DE TRANSFERENCIA', 'value' => $summary['number'] ?? 'N/D'],
            ['label' => 'FECHA DE SOLICITUD', 'value' => $summary['requestedAt'] ?? 'N/D'],
            ['label' => 'FECHA PROGRAMADA', 'value' => $summary['scheduledFor'] ?? 'N/D'],
        ]);
        $drawInfoRow([
            ['label' => 'RESPONSABLE DEL ENVIO', 'value' => $summary['responsible'] ?? 'N/D'],
            ['label' => 'UNIDAD PRODUCTORA', 'value' => $summary['unit'] ?? 'N/D'],
        ]);

        $y += 14;
        $drawText($margin, $y, 'DETALLE DE CAJAS Y DOCUMENTOS', 11, 'F2', '0.07 0.31 0.48');
        $y += 12;

        $columns = ['Código', 'Documento', 'Serie / Subserie', 'Fechas extremas', 'Soporte', 'Páginas'];
        $widths = [68, 148, 137, 68, 48, 58];

        foreach (($summary['boxes'] ?? []) as $box) {
            $ensureSpace(82);
            $drawRect($margin, $y, $pageWidth - ($margin * 2), 28, '0.07 0.31 0.48', '0.90 0.96 0.98', 0.7);
            $drawText($margin + 8, $y + 12, 'CAJA ' . ($box['number'] ?? 'N/D') . ' - ' . ($box['code'] ?? 'N/D'), 8, 'F2', '0.07 0.31 0.48');
            $drawText($margin + 8, $y + 24, 'Periodo: ' . ($box['period'] ?? 'N/D'), 7, 'F1', '0.27 0.33 0.39');
            $y += 28;

            $drawTableRow($columns, $widths, $columns, true);

            foreach (($box['documents'] ?? []) as $document) {
                $ensureSpace(38);
                $drawTableRow($columns, $widths, [
                    $document['code'] ?? 'N/D',
                    $document['name'] ?? 'N/D',
                    $document['series'] ?? 'N/D',
                    $document['year'] ?? 'N/D',
                    $document['support'] ?? 'N/D',
                    $document['pages'] ?? 'N/D',
                ]);
            }

            if (empty($box['documents'])) {
                $ensureSpace(28);
                $drawTableRow($columns, $widths, ['-', 'Sin documentos registrados', '-', '-', '-', '-']);
            }

            $y += 10;
        }

        $ensureSpace(142);
        $drawRect($margin, $y, $pageWidth - ($margin * 2), 28, '0.70 0.75 0.80', '0.96 0.98 0.99', 0.5);
        $drawText($margin + 8, $y + 12, 'Total de cajas: ' . ($summary['totalBoxes'] ?? 0), 8, 'F2', '0.07 0.31 0.48');
        $drawText($margin + 180, $y + 12, 'Total de documentos: ' . ($summary['totalDocuments'] ?? 0), 8, 'F2', '0.07 0.31 0.48');
        $y += 42;

        $signatureColor = '0.07 0.31 0.48';
        $signatureWidth = ($pageWidth - ($margin * 2)) / 2;
        $authorizedBy = trim((string) ($summary['authorizedBy'] ?? 'N/D')) ?: 'N/D';
        $drawRect($margin, $y, $signatureWidth, 58, $signatureColor, null, 0.5);
        $drawRect($margin + $signatureWidth, $y, $signatureWidth, 58, $signatureColor, null, 0.5);
        $drawFittedText($margin + 6, $y + 12, 'Autorizado por ' . $authorizedBy, 7, 'F2', $signatureColor, $signatureWidth - 12);
        $drawText($margin + $signatureWidth + 6, $y + 12, 'RECIBIDO POR', 7, 'F2', $signatureColor);
        $drawText($margin + 18, $y + 38, 'Firma', 7, 'F2', $signatureColor);
        $drawLine($margin + 18, $y + 47, $margin + $signatureWidth - 18, $y + 47, $signatureColor);
        $drawLine($margin + $signatureWidth + 18, $y + 47, $pageWidth - $margin - 18, $y + 47, $signatureColor);
        $y += 70;

        $finalNote = 'Una vez que la documentacion ha ingresado en el Archivo, corresponde a este su custodia y tratamiento, incluyendo en servicio de consulta, informacion, prestamos y/o copias a las unidades productoras y a los usuarios y usuarias en general, segun la legislacion archivistica vigente.';
        $noteLines = $wrapText($finalNote, $pageWidth - ($margin * 2) - 24, 8);
        $noteHeight = 26 + (count($noteLines) * 11);

        $drawRect($margin, $y, $pageWidth - ($margin * 2), $noteHeight, '0.86 0.55 0.08', '1 0.97 0.84', 0.8);
        $drawText($margin + 10, $y + 16, 'IMPORTANTE:', 9, 'F2', '0.54 0.32 0.03');

        foreach ($noteLines as $index => $line) {
            $drawText($margin + 10, $y + 32 + ($index * 11), $line, 8, 'F1', '0.27 0.20 0.08');
        }

        $pages[] = $content;

        return $this->buildPdfFromPages($pages, $pageWidth, $pageHeight);
    }

    private function buildLoanSummarySheetPdf(array $summary): string
    {
        $pageWidth = 612;
        $pageHeight = 792;
        $margin = 36;
        $pages = [];
        $content = '';
        $y = 28;

        $addPage = function () use (&$pages, &$content, &$y) {
            if ($content !== '') {
                $pages[] = $content;
            }

            $content = '';
            $y = 28;
        };

        $drawText = function (float $x, float $topY, string $text, int $size = 9, string $font = 'F1', string $color = '0 0 0') use (&$content, $pageHeight) {
            $pdfY = $pageHeight - $topY;
            $content .= $color . " rg\nBT\n/{$font} {$size} Tf\n" . $x . ' ' . $pdfY . " Td\n(" . $this->escapePdfText($text) . ") Tj\nET\n0 0 0 rg\n";
        };

        $drawRect = function (float $x, float $topY, float $width, float $height, string $stroke = '0 0 0', ?string $fill = null, float $lineWidth = 0.7) use (&$content, $pageHeight) {
            $pdfY = $pageHeight - $topY - $height;
            $content .= $lineWidth . " w\n{$stroke} RG\n";

            if ($fill !== null) {
                $content .= $fill . " rg\n" . $x . ' ' . $pdfY . ' ' . $width . ' ' . $height . " re B\n0 0 0 rg\n";
            } else {
                $content .= $x . ' ' . $pdfY . ' ' . $width . ' ' . $height . " re S\n";
            }
        };

        $drawLine = function (float $x1, float $topY1, float $x2, float $topY2, string $stroke = '0 0 0', float $lineWidth = 0.5) use (&$content, $pageHeight) {
            $content .= $lineWidth . " w\n{$stroke} RG\n" . $x1 . ' ' . ($pageHeight - $topY1) . ' m ' . $x2 . ' ' . ($pageHeight - $topY2) . " l S\n";
        };

        $estimateTextWidth = function (string $text, float $fontSize): float {
            return strlen($this->escapePdfText($text)) * $fontSize * 0.54;
        };

        $wrapText = function (string $text, float $maxWidth, float $fontSize) use ($estimateTextWidth): array {
            $words = preg_split('/\s+/', trim($text)) ?: [];
            $lines = [];
            $line = '';

            foreach ($words as $word) {
                $candidate = trim($line . ' ' . $word);

                if ($line !== '' && $estimateTextWidth($candidate, $fontSize) > $maxWidth) {
                    $lines[] = $line;
                    $line = $word;
                    continue;
                }

                $line = $candidate;
            }

            if ($line !== '') {
                $lines[] = $line;
            }

            return $lines ?: [''];
        };

        $drawFittedText = function (float $x, float $topY, string $text, int $size = 9, string $font = 'F1', string $color = '0 0 0', ?float $maxWidth = null) use ($drawText, $estimateTextWidth) {
            $fontSize = $size;

            while ($maxWidth !== null && $fontSize > 5 && $estimateTextWidth($text, $fontSize) > $maxWidth) {
                $fontSize -= 0.5;
            }

            $drawText($x, $topY, $text, (int) floor($fontSize), $font, $color);
        };

        $drawCenteredText = function (float $topY, string $text, int $size = 9, string $font = 'F1', string $color = '0 0 0') use ($drawText, $estimateTextWidth, $pageWidth) {
            $drawText(max(0, ($pageWidth - $estimateTextWidth($text, $size)) / 2), $topY, $text, $size, $font, $color);
        };

        $drawHeader = function () use (&$y, $drawCenteredText) {
            $headerColor = '0.07 0.31 0.48';
            $drawCenteredText($y + 20, 'UNIVERSIDAD DE EL SALVADOR', 9, 'F2', $headerColor);
            $drawCenteredText($y + 34, 'FACULTAD DE INGENIERIA Y ARQUITECTURA', 9, 'F2', $headerColor);
            $drawCenteredText($y + 48, 'UNIDAD DE GESTION DOCUMENTAL Y ARCHIVOS', 9, 'F2', $headerColor);
            $drawCenteredText($y + 62, 'HOJA DE PRESTAMO DE DOCUMENTOS', 9, 'F2', $headerColor);
            $y += 78;
        };

        $drawInfoRow = function (array $items) use (&$y, $margin, $pageWidth, $drawRect, $drawText, $drawFittedText, $wrapText) {
            $cellWidth = ($pageWidth - ($margin * 2)) / count($items);
            $lineSets = [];
            $rowHeight = 34;

            foreach ($items as $index => $item) {
                $lineSets[$index] = $wrapText((string) $item['value'], $cellWidth - 14, 7);
                $rowHeight = max($rowHeight, 21 + (count($lineSets[$index]) * 9));
            }

            foreach ($items as $index => $item) {
                $x = $margin + ($cellWidth * $index);
                $drawRect($x, $y, $cellWidth, $rowHeight, '0.74 0.78 0.82', '0.96 0.98 0.99', 0.5);
                $drawText($x + 6, $y + 12, $item['label'], 6, 'F2', '0.27 0.33 0.39');

                foreach ($lineSets[$index] as $lineIndex => $line) {
                    $drawFittedText($x + 6, $y + 24 + ($lineIndex * 9), $line, 7, 'F2', '0.07 0.31 0.48', $cellWidth - 14);
                }
            }

            $y += $rowHeight;
        };

        $ensureSpace = function (float $height) use (&$y, $pageHeight, $addPage, $drawHeader) {
            if ($y + $height > $pageHeight - 42) {
                $addPage();
                $drawHeader();
            }
        };

        $drawTableRow = function (array $widths, array $values, bool $header = false) use (&$y, $margin, $drawRect, $drawFittedText, $wrapText) {
            $lineSets = [];
            $fontSize = $header ? 6 : 7;
            $rowHeight = $header ? 22 : 27;
            $x = $margin;

            foreach ($values as $index => $value) {
                $lineSets[$index] = $wrapText((string) $value, max(20, ($widths[$index] ?? 60) - 10), $fontSize);
                $rowHeight = max($rowHeight, 13 + (count($lineSets[$index]) * 9));
            }

            foreach ($values as $index => $value) {
                $width = $widths[$index];
                $drawRect($x, $y, $width, $rowHeight, '0.70 0.75 0.80', $header ? '0.88 0.91 0.93' : null, 0.45);

                foreach ($lineSets[$index] as $lineIndex => $line) {
                    $drawFittedText($x + 4, $y + 12 + ($lineIndex * 9), $line, $fontSize, $header ? 'F2' : 'F1', $header ? '0.07 0.31 0.48' : '0.16 0.20 0.24', $width - 9);
                }

                $x += $width;
            }

            $y += $rowHeight;
        };

        $drawHeader();
        $drawInfoRow([
            ['label' => 'N. DE PRESTAMO', 'value' => $summary['number'] ?? 'N/D'],
            ['label' => 'FECHA DE SOLICITUD', 'value' => $summary['requestedAt'] ?? 'N/D'],
            ['label' => 'UNIDAD SOLICITANTE', 'value' => $summary['unit'] ?? 'N/D'],
        ]);
        $drawInfoRow([
            ['label' => 'SOLICITANTE', 'value' => $summary['applicant'] ?? 'N/D'],
            ['label' => 'FECHA DE PRESTAMO', 'value' => $summary['loanDate'] ?? 'N/D'],
            ['label' => 'DEVOLUCION PROGRAMADA', 'value' => $summary['dueDate'] ?? 'N/D'],
        ]);

        $y += 14;
        $drawText($margin, $y, 'DOCUMENTOS ENTREGADOS', 11, 'F2', '0.07 0.31 0.48');
        $y += 12;

        $columns = ['Documento', 'Serie / Subserie', 'Caja', 'Año', 'Tipo'];
        $widths = [170, 125, 90, 65, 90];
        $drawTableRow($widths, $columns, true);

        foreach (($summary['documents'] ?? []) as $document) {
            $ensureSpace(40);
            $drawTableRow($widths, [
                $document['title'] ?? 'N/D',
                $document['series'] ?? 'N/D',
                $document['box'] ?? 'N/D',
                $document['year'] ?? 'N/D',
                $document['documentType'] ?? 'N/D',
            ]);
        }

        if (empty($summary['documents'])) {
            $drawTableRow($widths, ['-', 'Sin documentos registrados', '-', '-', '-']);
        }

        $y += 12;
        $drawRect($margin, $y, $pageWidth - ($margin * 2), 32, '0.70 0.75 0.80', '0.96 0.98 0.99', 0.5);
        $drawText($margin + 8, $y + 20, 'Total de documentos: ' . ($summary['totalDocuments'] ?? 0), 8, 'F2', '0.07 0.31 0.48');
        $y += 46;

        $observationLines = $wrapText((string) ($summary['observations'] ?? 'Sin observaciones.'), $pageWidth - ($margin * 2) - 20, 8);
        $observationHeight = 28 + (count($observationLines) * 11);
        $ensureSpace($observationHeight + 126);
        $drawRect($margin, $y, $pageWidth - ($margin * 2), $observationHeight, '0.74 0.78 0.82', '0.98 0.99 0.99', 0.5);
        $drawText($margin + 10, $y + 16, 'OBSERVACIONES', 8, 'F2', '0.07 0.31 0.48');

        foreach ($observationLines as $index => $line) {
            $drawText($margin + 10, $y + 30 + ($index * 11), $line, 8, 'F1', '0.27 0.33 0.39');
        }

        $y += $observationHeight + 16;
        $signatureColor = '0.07 0.31 0.48';
        $signatureWidth = ($pageWidth - ($margin * 2)) / 2;
        $drawRect($margin, $y, $signatureWidth, 78, $signatureColor, null, 0.5);
        $drawRect($margin + $signatureWidth, $y, $signatureWidth, 78, $signatureColor, null, 0.5);
        $drawFittedText($margin + 8, $y + 14, 'ENTREGADO POR UGDA', 8, 'F2', $signatureColor, $signatureWidth - 16);
        $drawFittedText($margin + 8, $y + 29, $summary['deliveredBy'] ?? 'N/D', 7, 'F1', '0.27 0.33 0.39', $signatureWidth - 16);
        $drawText($margin + $signatureWidth + 8, $y + 14, 'RECIBIDO POR', 8, 'F2', $signatureColor);
        $drawFittedText($margin + $signatureWidth + 8, $y + 29, $summary['receivedBy'] ?? 'N/D', 7, 'F1', '0.27 0.33 0.39', $signatureWidth - 16);
        $drawLine($margin + 18, $y + 61, $margin + $signatureWidth - 18, $y + 61, $signatureColor);
        $drawLine($margin + $signatureWidth + 18, $y + 61, $pageWidth - $margin - 18, $y + 61, $signatureColor);
        $drawText($margin + 18, $y + 72, 'Firma y sello', 6, 'F2', $signatureColor);
        $drawText($margin + $signatureWidth + 18, $y + 72, 'Firma', 6, 'F2', $signatureColor);

        $y += 94;
        $note = 'La persona que recibe declara que recibe los documentos indicados y se compromete a devolverlos en la fecha programada, conservando su integridad y orden documental.';
        $noteLines = $wrapText($note, $pageWidth - ($margin * 2) - 20, 8);
        $noteHeight = 26 + (count($noteLines) * 11);
        $drawRect($margin, $y, $pageWidth - ($margin * 2), $noteHeight, '0.86 0.55 0.08', '1 0.97 0.84', 0.8);
        $drawText($margin + 10, $y + 16, 'IMPORTANTE:', 9, 'F2', '0.54 0.32 0.03');

        foreach ($noteLines as $index => $line) {
            $drawText($margin + 10, $y + 32 + ($index * 11), $line, 8, 'F1', '0.27 0.20 0.08');
        }

        $pages[] = $content;

        return $this->buildPdfFromPages($pages, $pageWidth, $pageHeight);
    }

    private function buildPdfFromPages(array $pageContents, int $pageWidth, int $pageHeight): string
    {
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        ];
        $pageObjectNumbers = [];
        $fontStart = 3 + (count($pageContents) * 2);
        $regularFontObject = $fontStart;
        $boldFontObject = $fontStart + 1;

        foreach ($pageContents as $index => $content) {
            $pageObject = 3 + ($index * 2);
            $contentObject = $pageObject + 1;
            $pageObjectNumbers[] = $pageObject . ' 0 R';
            $objects[] = $pageObject . " 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 {$regularFontObject} 0 R /F2 {$boldFontObject} 0 R >> >> /Contents {$contentObject} 0 R >>\nendobj\n";
            $objects[] = $contentObject . " 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n";
        }

        array_splice($objects, 1, 0, "2 0 obj\n<< /Type /Pages /Kids [" . implode(' ', $pageObjectNumbers) . '] /Count ' . count($pageContents) . " >>\nendobj\n");
        $objects[] = $regularFontObject . " 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";
        $objects[] = $boldFontObject . " 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function escapePdfText(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        if ($converted === false) {
            $converted = $text;
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }
}

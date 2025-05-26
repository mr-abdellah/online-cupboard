<?php

namespace App\Http\Controllers;

use App\Models\Binder;
use App\Models\CupboardUserPermission;
use App\Models\Document;
use App\Models\DocumentUserPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\PdfToImage\Pdf;
use thiagoalessio\TesseractOCR\TesseractOCR;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use Symfony\Component\Mime\MimeTypes;

class DocumentController extends Controller
{

    private const CONVERTIBLE_TYPES = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/csv',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation'
    ];

    private const VIEWABLE_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'text/plain'
    ];

    public function searchDocuments(Request $request)
    {
        $searchTerm = $request->input('search');
        $fileType = $request->input('file_type');
        $perPage = $request->input('per_page', 10); // Default to 10 if not provided

        $query = Document::with(['binder.cupboard'])
            ->where('title', 'like', '%' . $searchTerm . '%');

        if ($fileType) {
            $fileTypeMap = [
                'image' => ['jpg', 'jpeg', 'png'],
                'doc' => ['doc', 'docx'],
                'pdf' => ['pdf'],
                'excel' => ['xls', 'xlsx'],
                'presentation' => ['ppt', 'pptx'],
            ];

            if (array_key_exists($fileType, $fileTypeMap)) {
                $query->whereIn('type', $fileTypeMap[$fileType]);
            } else {
                return response()->json(['error' => 'Invalid file type, bro!'], 400);
            }
        }

        $paginated = $query->paginate($perPage);

        if ($paginated->isEmpty()) {
            return response()->json([]);
        }

        $userId = auth()->id();

        $result = $paginated->getCollection()->map(function ($document) use ($userId) {
            $fileSize = Storage::exists($document->path) ? Storage::size($document->path) : 0;
            $formattedSize = $this->formatSearchBytes($fileSize);

            $cupboardName = $document->binder && $document->binder->cupboard
                ? $document->binder->cupboard->name
                : 'N/A';
            $binderName = $document->binder
                ? $document->binder->name
                : 'N/A';

            $canView = $this->checkViewPermission($document, $userId);

            $hasManagePermission = $document->binder && $document->binder->cupboard
                ? CupboardUserPermission::where('cupboard_id', $document->binder->cupboard->id)
                ->where('user_id', $userId)
                ->where('permission', 'manage')
                ->exists()
                : false;

            $permissions = [];
            if ($canView) {
                $permissions = DocumentUserPermission::where('document_id', $document->id)
                    ->where('user_id', $userId)
                    ->pluck('permission')
                    ->toArray();
            }

            return [
                'id' => $document->id,
                'type' => $document->type,
                'name' => $document->title,
                'size' => $formattedSize,
                'cupboard' => $cupboardName,
                'binder' => $binderName,
                'can_view' => $canView,
                'permissions' => $permissions,
            ];
        });

        return response()->json([
            'data' => $result,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    private function checkViewPermission($document, $userId)
    {
        // Check if user has 'manage' permission on the cupboard
        $hasCupboardManagePermission = $document->binder && $document->binder->cupboard_id
            ? CupboardUserPermission::where('user_id', $userId)
            ->where('cupboard_id', $document->binder->cupboard_id)
            ->where('permission', 'manage')
            ->exists()
            : false;

        // If no manage permission, return false immediately
        if (!$hasCupboardManagePermission) {
            Log::info('Checking view permission: No cupboard manage permission', [
                'document_id' => $document->id,
                'user_id' => $userId,
                'has_cupboard_manage_permission' => $hasCupboardManagePermission,
                'can_view' => false
            ]);
            return false;
        }

        // Check if user has 'view' permission on the document
        $hasDocumentViewPermission = DocumentUserPermission::where('document_id', $document->id)
            ->where('user_id', $userId)
            ->where('permission', 'view')
            ->exists();

        // Return true only if both permissions exist
        $canView = $hasCupboardManagePermission && $hasDocumentViewPermission;

        Log::info('Checking view permission', [
            'document_id' => $document->id,
            'user_id' => $userId,
            'has_document_view_permission' => $hasDocumentViewPermission,
            'has_cupboard_manage_permission' => $hasCupboardManagePermission,
            'can_view' => $canView
        ]);

        return $canView;
    }

    // Helper method to format file size in bytes
    private function formatSearchBytes($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }
    public function index()
    {
        if (!auth()->user()->hasGlobalPermission('can_view_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        return Document::with('permissions')->orderBy('order')->get();
    }

    public function store(Request $request)
    {
        if (!auth()->user()->hasGlobalPermission('can_upload_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'binder_id' => 'required|uuid|exists:binders,id',
            'file' => 'required|file', // Removed mimes restriction to allow all file types
            'is_searchable' => 'boolean',
            'tags' => 'nullable|array',
        ]);

        // Fetch the binder to get the associated cupboard_id
        $binder = Binder::findOrFail($validated['binder_id']);
        $cupboardId = $binder->cupboard_id;

        // Check if the user has 'manage' permission for the cupboard
        $userId = auth()->id();
        $hasManagePermission = CupboardUserPermission::where('user_id', $userId)
            ->where('cupboard_id', $cupboardId)
            ->where('permission', 'manage')
            ->exists();

        if (!$hasManagePermission) {
            return response()->json([
                'error' => "You don't have permission to manage this cupboard"
            ], 403);
        }

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = uniqid() . '.' . $extension;
        $subfolder = $extension ? strtolower($extension) : 'unknown';
        $path = $file->storeAs($subfolder, $filename, 'local');

        $document = Document::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'type' => $extension ?: 'unknown',
            'ocr' => '',
            'tags' => $validated['tags'] ?? [],
            'binder_id' => $validated['binder_id'],
            'path' => $path,
            'is_searchable' => $request->input('is_searchable', true),
        ]);

        $permissions = ['view', 'edit', 'delete', 'download'];
        foreach ($permissions as $perm) {
            $document->permissions()->create([
                'user_id' => auth()->id(),
                'permission' => $perm,
            ]);
        }

        return response()->json($document, 201);
    }

    public function show(Document $document)
    {
        if (!$document->is_public && !auth()->user()->hasDocumentPermission($document, 'view')) {
            return response()->json(['error' => 'You don’t have permission'], 403);
        }
        $permissions = DB::table('document_user_permissions')
            ->where('document_id', $document->id)
            ->where('user_id', Auth::id())
            ->pluck('permission')
            ->unique()
            ->values()
            ->toArray();

        $document->permissions = $permissions;

        return response()->json($document);
    }

    public function update(Request $request, Document $document)
    {
        if (!auth()->user()->hasDocumentPermission($document, 'edit')) {
            return response()->json(['error' => 'You don’t have permission'], 403);
        }
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'tags' => 'nullable|array',
            'is_searchable' => 'boolean',
        ]);

        $document->update($request->only([
            'title',
            'description',
            'tags',
            'is_searchable'
        ]));

        return response()->json($document);
    }

    public function destroy(Document $document)
    {
        if (!auth()->user()->hasDocumentPermission($document, 'delete')) {
            return response()->json(['error' => 'You don’t have permission'], 403);
        }
        $document->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function extractOcr(Request $request)
    {
        if (!auth()->user()->hasGlobalPermission('can_upload_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx',
        ]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();

        $tmpPath = storage_path('app/private/tmp');
        if (!Storage::exists('private/tmp')) {
            Storage::makeDirectory('private/tmp', 0755, true);
        }

        $fullPath = $tmpPath . '/' . uniqid() . '.' . $extension;
        $file->move($tmpPath, basename($fullPath));

        $tempFiles = [];
        try {
            $ocrText = '';

            if (in_array($extension, ['jpg', 'jpeg', 'png'])) {
                $ocrText = (new TesseractOCR($fullPath))->run();
            } elseif ($extension === 'pdf') {
                // Use Imagick to count pages
                $imagick = new \Imagick($fullPath);
                $pageCount = $imagick->getNumberImages();
                $imagick->clear();
                $imagick->destroy();

                $pdf = new Pdf($fullPath);
                $ocrTextParts = [];

                for ($page = 1; $page <= $pageCount; $page++) {
                    $imagePath = $tmpPath . '/' . uniqid() . '.jpg';
                    $pdf->selectPage($page)->save($imagePath);
                    $ocrTextParts[] = (new TesseractOCR($imagePath))->run();
                    $tempFiles[] = $imagePath; // Track for cleanup
                }

                $ocrText = implode("\n", array_filter($ocrTextParts));
            } elseif (in_array($extension, ['doc', 'docx'])) {
                $phpWord = PhpWordIOFactory::load($fullPath);
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if (method_exists($element, 'getText')) {
                            $text .= $element->getText() . "\n";
                        }
                    }
                }
                $ocrText = trim($text);
            } elseif (in_array($extension, ['xls', 'xlsx'])) {
                $spreadsheet = SpreadsheetIOFactory::load($fullPath);
                $text = '';
                foreach ($spreadsheet->getAllSheets() as $sheet) {
                    foreach ($sheet->toArray(null, true, true, true) as $row) {
                        $text .= implode(' ', array_filter($row, fn($cell) => !is_null($cell))) . "\n";
                    }
                }
                $ocrText = trim($text);
            } elseif (in_array($extension, ['ppt', 'pptx'])) {
                $presentation = PresentationIOFactory::load($fullPath);
                $text = '';
                foreach ($presentation->getAllSlides() as $slide) {
                    foreach ($slide->getShapeCollection() as $shape) {
                        if (method_exists($shape, 'getText')) {
                            $text .= $shape->getText() . "\n";
                        } elseif (method_exists($shape, 'getRichTextElements')) {
                            foreach ($shape->getRichTextElements() as $element) {
                                if (method_exists($element, 'getText')) {
                                    $text .= $element->getText() . "\n";
                                }
                            }
                        }
                    }
                }
                $ocrText = trim($text);
            }

            Storage::delete($fullPath);
            foreach ($tempFiles as $tempFile) {
                if (Storage::exists($tempFile)) {
                    Storage::delete($tempFile);
                }
            }

            if (empty($ocrText)) {
                return response()->json(['error' => 'No text could be extracted from the file.'], 422);
            }

            return response()->json(['text' => $ocrText]);
        } catch (\Exception $e) {
            Storage::delete($fullPath);
            foreach ($tempFiles as $tempFile) {
                if (Storage::exists($tempFile)) {
                    Storage::delete($tempFile);
                }
            }
            return response()->json(['error' => 'Text extraction failed: ' . $e->getMessage()], 500);
        }
    }


    /**
     * Enhanced PDF conversion with better Excel handling
     */
    private function convertToPdf(Document $document, string $mimeType): ?string
    {
        $inputPath = Storage::disk('local')->path($document->path);
        $cacheKey = md5($document->path . $document->updated_at);
        $cacheDir = storage_path('app/pdf_cache');
        $cachedPdf = "$cacheDir/{$cacheKey}.pdf";

        // Return cached version if exists
        if (file_exists($cachedPdf)) {
            Log::info("Using cached PDF", ['cache_file' => $cachedPdf]);
            return $cachedPdf;
        }

        // Ensure cache directory exists
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $tempDir = storage_path('app/temp/' . uniqid());

        try {
            // Create temp directory
            if (!mkdir($tempDir, 0755, true)) {
                throw new \RuntimeException("Failed to create temp directory: $tempDir");
            }

            $success = false;

            // Try different conversion methods based on file type
            if ($this->isExcelFile($mimeType)) {
                $success = $this->convertExcelToPdf($inputPath, $tempDir, $cachedPdf);
            } else {
                $success = $this->convertOfficeToPdf($inputPath, $tempDir, $cachedPdf);
            }

            if (!$success || !file_exists($cachedPdf)) {
                Log::error("Conversion failed", [
                    'input_path' => $inputPath,
                    'mime_type' => $mimeType,
                    'cache_file' => $cachedPdf
                ]);
                return null;
            }

            Log::info("Document converted successfully", [
                'input_path' => $inputPath,
                'output_path' => $cachedPdf,
                'file_size' => filesize($cachedPdf)
            ]);

            return $cachedPdf;
        } catch (\Throwable $e) {
            Log::error("Conversion error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * Enhanced Excel to PDF conversion with better fidelity for wide sheets
     */
    private function convertExcelToPdf(string $inputPath, string $tempDir, string $outputPath): bool
    {
        $isWindows = PHP_OS_FAMILY === 'Windows';

        // Method 1: Try macro-based conversion (best for wide sheets)
        if ($this->convertExcelWithMacro($inputPath, $tempDir, $outputPath, $isWindows)) {
            return true;
        }

        // Method 2: Try print-to-PDF approach
        if ($this->convertExcelWithPrint($inputPath, $tempDir, $outputPath, $isWindows)) {
            return true;
        }

        // Method 3: Fallback to basic conversion
        return $this->convertOfficeToPdf($inputPath, $tempDir, $outputPath);
    }

    /**
     * Convert Excel using macro to control page layout
     */
    private function convertExcelWithMacro(string $inputPath, string $tempDir, string $outputPath, bool $isWindows): bool
    {
        $soffice = $this->getLibreOfficePath($isWindows);
        if (!$soffice) {
            return false;
        }

        $loProfile = "$tempDir/libreoffice_macro";
        mkdir($loProfile, 0755, true);

        // Create macro file to handle wide Excel sheets
        $macroContent = $this->createExcelScalingMacro();
        $macroFile = "$loProfile/ExcelMacro.bas";
        file_put_contents($macroFile, $macroContent);

        // Step 1: Convert to ODS first (this preserves more formatting)
        $odsFile = "$tempDir/temp.ods";
        $step1Command = sprintf(
            '%s --headless --convert-to ods --outdir %s %s',
            $soffice,
            escapeshellarg($tempDir),
            escapeshellarg($inputPath)
        );

        exec($step1Command, $output1, $code1);

        if ($code1 !== 0 || !file_exists($odsFile)) {
            return false;
        }

        // Step 2: Use macro to scale and convert to PDF
        $step2Command = sprintf(
            '%s --headless --invisible --calc --run-macro %s --convert-to pdf --outdir %s %s',
            $soffice,
            escapeshellarg($macroFile),
            escapeshellarg($tempDir),
            escapeshellarg($odsFile)
        );

        exec($step2Command, $output2, $code2);

        // Find converted PDF
        $convertedFiles = glob("$tempDir/*.pdf");
        if (!empty($convertedFiles)) {
            $tempPdf = reset($convertedFiles);
            if (file_exists($tempPdf) && filesize($tempPdf) > 500) {
                return rename($tempPdf, $outputPath);
            }
        }

        return false;
    }

    /**
     * Convert Excel using print approach for better scaling
     */
    private function convertExcelWithPrint(string $inputPath, string $tempDir, string $outputPath, bool $isWindows): bool
    {
        $soffice = $this->getLibreOfficePath($isWindows);
        if (!$soffice) {
            return false;
        }

        $loProfile = "$tempDir/libreoffice_print";
        mkdir($loProfile, 0755, true);

        // Use calc with print settings that force fit-to-page
        $command = sprintf(
            '%s --headless --calc --print-to-file --printer-name "Microsoft Print to PDF" --outdir %s %s',
            $soffice,
            escapeshellarg($tempDir),
            escapeshellarg($inputPath)
        );

        // For non-Windows, use different approach
        if (!$isWindows) {
            $command = sprintf(
                '%s --headless --calc --convert-to pdf:calc_pdf_Export --outdir %s %s',
                $soffice,
                escapeshellarg($tempDir),
                escapeshellarg($inputPath)
            );
        }

        $env = [
            'HOME' => $loProfile,
            'TMPDIR' => $tempDir,
        ];

        if (!$isWindows) {
            $env['SHELL'] = '/bin/bash';
        }

        exec($command, $output, $exitCode);

        Log::info("Excel print conversion attempt", [
            'command' => $command,
            'exit_code' => $exitCode,
            'output' => implode("\n", $output)
        ]);

        $convertedFiles = glob("$tempDir/*.pdf");
        if (!empty($convertedFiles)) {
            $tempPdf = reset($convertedFiles);
            if (file_exists($tempPdf) && filesize($tempPdf) > 500) {
                return rename($tempPdf, $outputPath);
            }
        }

        return false;
    }

    /**
     * Create macro content for Excel scaling
     */
    private function createExcelScalingMacro(): string
    {
        return '
Sub ScaleExcelToPDF
    Dim oDoc As Object
    Dim oSheet As Object
    Dim oPageStyle As Object
    Dim i As Integer
    
    oDoc = ThisComponent
    
    For i = 0 To oDoc.getSheets().getCount() - 1
        oSheet = oDoc.getSheets().getByIndex(i)
        
        \' Set print area to include all used cells
        oUsedRange = oSheet.getCellRangeByName("A1").getSpreadsheet().getCellRangeByName(oSheet.getCellRangeByName("A1").getSpreadsheet().createCursor().getRangeAddress().toString())
        
        \' Get page style for this sheet
        oPageStyle = oDoc.getStyleFamilies().getByName("PageStyles").getByName("Default")
        
        \' Force landscape orientation for wide sheets
        oPageStyle.IsLandscape = True
        
        \' Set scaling to fit all columns on one page
        oPageStyle.ScaleToPages = 0
        oPageStyle.ScaleToPagesX = 1
        oPageStyle.ScaleToPagesY = 0
        
        \' Reduce margins to maximize space
        oPageStyle.LeftMargin = 500   \' 0.5cm
        oPageStyle.RightMargin = 500
        oPageStyle.TopMargin = 500
        oPageStyle.BottomMargin = 500
        
    Next i
End Sub
';
    }

    /**
     * LibreOffice Excel conversion with WORKING fit-to-page approach
     */
    private function convertExcelWithLibreOffice(string $inputPath, string $tempDir, string $outputPath, bool $isWindows): bool
    {
        $loProfile = "$tempDir/libreoffice_profile";
        mkdir($loProfile, 0755, true);

        $soffice = $this->getLibreOfficePath($isWindows);
        if (!$soffice) {
            Log::warning("LibreOffice not found");
            return false;
        }

        // SIMPLE approach that actually works - force A4 landscape with scaling
        $command = sprintf(
            '%s --headless --invisible --nologo --calc ' .
                '--convert-to pdf --outdir %s %s',
            $soffice,
            escapeshellarg($tempDir),
            escapeshellarg($inputPath)
        );

        // Set environment to force specific print settings
        $env = [
            'HOME' => $loProfile,
            'TMPDIR' => $tempDir,
            // These are the key settings that actually work
            'SAL_USE_VCLPLUGIN' => 'svp',  // Use headless plugin
            'LIBREOFFICE_PRINT_FITTOPAGE' => '1',  // Force fit to page
        ];

        if (!$isWindows) {
            $env['SHELL'] = '/bin/bash';
            $env['DISPLAY'] = ':99';  // Dummy display
        }

        Log::info("Excel conversion attempt", [
            'command' => $command,
            'env_vars' => $env
        ]);

        // Execute with environment variables
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($command, $descriptorspec, $pipes, null, $env);

        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            Log::info("LibreOffice execution result", [
                'exit_code' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr
            ]);

            if ($exitCode === 0) {
                $convertedFiles = glob("$tempDir/*.pdf");
                if (!empty($convertedFiles)) {
                    $tempPdf = reset($convertedFiles);
                    if (file_exists($tempPdf) && filesize($tempPdf) > 1000) {
                        return rename($tempPdf, $outputPath);
                    }
                }
            }
        }

        return false;
    }

    /**
     * Standard Office document conversion
     */
    private function convertOfficeToPdf(string $inputPath, string $tempDir, string $outputPath): bool
    {
        $isWindows = PHP_OS_FAMILY === 'Windows';
        $soffice = $this->getLibreOfficePath($isWindows);

        if (!$soffice) {
            return false;
        }

        $loProfile = "$tempDir/libreoffice_profile";
        mkdir($loProfile, 0755, true);

        $command = sprintf(
            '%s --headless --convert-to pdf --outdir %s %s',
            $soffice,
            escapeshellarg($tempDir),
            escapeshellarg($inputPath)
        );

        $env = [
            'HOME' => $loProfile,
            'TMPDIR' => $tempDir,
        ];

        if (!$isWindows) {
            $env['SHELL'] = '/bin/bash';
        }

        exec($command, $output, $exitCode);

        $convertedFiles = glob("$tempDir/*.pdf");
        if (!empty($convertedFiles)) {
            $tempPdf = reset($convertedFiles);
            return rename($tempPdf, $outputPath);
        }

        return false;
    }

    /**
     * Get LibreOffice executable path
     */
    private function getLibreOfficePath(bool $isWindows): ?string
    {
        if ($isWindows) {
            $paths = [
                'C:\Program Files\LibreOffice\program\soffice.exe',
                'C:\Program Files (x86)\LibreOffice\program\soffice.exe',
            ];
        } else {
            $paths = [
                '/Applications/LibreOffice.app/Contents/MacOS/soffice',
                '/usr/bin/libreoffice',
                '/usr/bin/soffice',
                '/opt/libreoffice/program/soffice',
            ];
        }

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $isWindows ? "\"$path\"" : $path;
            }
        }

        return null;
    }

    /**
     * Handle convertible document types
     */
    private function handleConvertibleDocument(Document $document, string $mimeType)
    {
        $pdfPath = $this->convertToPdf($document, $mimeType);

        if (!$pdfPath || !file_exists($pdfPath)) {
            Log::error("Failed to convert document", [
                'document_id' => $document->id,
                'mime_type' => $mimeType
            ]);
            return response()->json(['error' => 'Failed to convert document'], 500);
        }

        return $this->serveFile(
            $pdfPath,
            'application/pdf',
            pathinfo($document->title, PATHINFO_FILENAME) . '.pdf'
        );
    }

    /**
     * Serve file with proper headers and security
     */
    private function serveFile(string $filePath, string $mimeType, string $filename)
    {
        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => filesize($filePath),
            'Content-Disposition' => 'inline; filename="' . basename($filename) . '"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
        ];

        return response()->file($filePath, $headers);
    }

    /**
     * Check if user can view document
     */
    private function canViewDocument(Document $document): bool
    {
        if ($document->is_public) {
            return true;
        }

        if (!auth()->check()) {
            return false;
        }

        return auth()->user()->hasDocumentPermission($document, 'view');
    }

    /**
     * Detect MIME type with fallback
     */
    private function detectMimeType(string $filePath): string
    {
        $mimeTypes = new MimeTypes();
        $mimeType = $mimeTypes->guessMimeType($filePath);

        // Fallback for common extensions
        if (!$mimeType) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $extensionMap = [
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
            ];

            $mimeType = $extensionMap[$extension] ?? 'application/octet-stream';
        }

        return $mimeType;
    }

    /**
     * Check if file is Excel format
     */
    private function isExcelFile(string $mimeType): bool
    {
        return in_array($mimeType, [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv'
        ]);
    }

    /**
     * Safe recursive directory removal
     */
    private function removeDirectory(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir($dir);
        } catch (\Exception $e) {
            Log::warning("Failed to remove directory: " . $e->getMessage());
        }
    }

    /**
     * Clean old cached PDFs (call this periodically)
     */
    public function cleanPdfCache(): void
    {
        $cacheDir = storage_path('app/pdf_cache');
        if (!is_dir($cacheDir)) {
            return;
        }

        $files = glob("$cacheDir/*.pdf");
        $maxAge = 7 * 24 * 3600; // 7 days

        foreach ($files as $file) {
            if (filemtime($file) < time() - $maxAge) {
                unlink($file);
            }
        }
    }

    public function display(Document $document)
    {
        // Security check
        if (!$this->canViewDocument($document)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if file exists
        if (!Storage::disk('local')->exists($document->path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $fullPath = Storage::disk('local')->path($document->path);
        $mimeType = $this->detectMimeType($fullPath);

        Log::info("Document display request", [
            'document_id' => $document->id,
            'mime_type' => $mimeType,
            'path' => $document->path
        ]);

        // Handle convertible documents
        if (in_array($mimeType, self::CONVERTIBLE_TYPES)) {
            return $this->handleConvertibleDocument($document, $mimeType);
        }

        // Handle directly viewable documents
        if (in_array($mimeType, self::VIEWABLE_TYPES)) {
            return $this->serveFile($fullPath, $mimeType, $document->title);
        }

        return response()->json(['error' => 'Unsupported file type for preview'], 415);
    }

    public function download(Document $document)
    {
        if (!auth()->user()->hasDocumentPermission($document, 'download')) {
            return response()->json(['error' => 'You don’t have permission'], 403);
        }
        if (!Storage::disk('local')->exists($document->path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        switch ($document->type) {
            case 'jpg':
            case 'jpeg':
                $mimeType = 'image/jpeg';
                break;
            case 'png':
                $mimeType = 'image/png';
                break;
            case 'pdf':
                $mimeType = 'application/pdf';
                break;
            case 'doc':
                $mimeType = 'application/msword';
                break;
            case 'docx':
                $mimeType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                break;
            case 'xls':
                $mimeType = 'application/vnd.ms-excel';
                break;
            case 'xlsx':
                $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                break;
            case 'ppt':
                $mimeType = 'application/vnd.ms-powerpoint';
                break;
            case 'pptx':
                $mimeType = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                break;
            default:
                $mimeType = 'application/octet-stream';
        }

        return Storage::disk('local')->response($document->path, $document->title . '.' . $document->type, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $document->title . '.' . $document->type . '"',
        ]);
    }


    // Helper function to convert bytes to human-readable format
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }


    public function storageUsage()
    {
        // Define a fixed total space (500 GB for your PC)
        $diskPath = Storage::disk('local')->path('/'); // gets the actual local disk path
        $totalSpaceBytes = disk_total_space($diskPath);
        $freeSpaceBytes = disk_free_space($diskPath);

        // Get all documents
        $documents = Document::all();
        $totalDocuments = $documents->count();

        // Initialize storage usage by file type
        $storageByType = [
            'pdf' => 0,
            'image' => 0,
            'doc' => 0,
            'excel' => 0,
            'other' => 0,
        ];

        // Initialize file counts by type
        $fileCounts = [
            'pdf' => 0,
            'image' => 0,
            'doc' => 0,
            'excel' => 0,
            'other' => 0,
        ];

        $usedSpaceDocuments = 0;

        // Calculate storage usage and file counts by type
        foreach ($documents as $document) {
            if (Storage::disk('local')->exists($document->path)) {
                $fileSize = Storage::disk('local')->size($document->path);
                $usedSpaceDocuments += $fileSize;

                // Categorize by file type
                if ($document->type === 'pdf') {
                    $storageByType['pdf'] += $fileSize;
                    $fileCounts['pdf']++;
                } elseif (in_array($document->type, ['jpg', 'jpeg', 'png'])) {
                    $storageByType['image'] += $fileSize;
                    $fileCounts['image']++;
                } elseif (in_array($document->type, ['doc', 'docx'])) {
                    $storageByType['doc'] += $fileSize;
                    $fileCounts['doc']++;
                } elseif (in_array($document->type, ['xls', 'xlsx'])) {
                    $storageByType['excel'] += $fileSize;
                    $fileCounts['excel']++;
                } else {
                    $storageByType['other'] += $fileSize;
                    $fileCounts['other']++;
                }
            }
        }

        // Get file type statistics (pdf_count, image_count, etc.)
        $stats = Document::selectRaw("
            SUM(CASE WHEN type IN ('pdf') THEN 1 ELSE 0 END) as pdf_count,
            SUM(CASE WHEN type IN ('jpg', 'jpeg', 'png') THEN 1 ELSE 0 END) as image_count,
            SUM(CASE WHEN type IN ('doc', 'docx') THEN 1 ELSE 0 END) as doc_count,
            SUM(CASE WHEN type IN ('xls', 'xlsx') THEN 1 ELSE 0 END) as excel_count,
            SUM(CASE WHEN type NOT IN ('pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx') THEN 1 ELSE 0 END) as other_count
        ")->first();

        return response()->json([
            'free_space' => $this->formatBytes($freeSpaceBytes),
            'used_space' => $this->formatBytes($totalSpaceBytes - $freeSpaceBytes),
            'total_space' => $this->formatBytes($totalSpaceBytes),
            'used_space_documents' => $this->formatBytes($usedSpaceDocuments),
            'storage_by_type' => [
                'pdf' => $this->formatBytes($storageByType['pdf']),
                'image' => $this->formatBytes($storageByType['image']),
                'doc' => $this->formatBytes($storageByType['doc']),
                'excel' => $this->formatBytes($storageByType['excel']),
                'other' => $this->formatBytes($storageByType['other']),
            ],
            'file_counts' => $fileCounts,
            'total_documents' => $totalDocuments,
            // Add file type statistics to the response
            'file_type_stats' => [
                'pdf' => (int) $stats->pdf_count,
                'image' => (int) $stats->image_count,
                'doc' => (int) $stats->doc_count,
                'excel' => (int) $stats->excel_count,
                'other' => (int) $stats->other_count,
            ],
        ]);
    }

    public function changeBinder(Request $request, Document $document)
    {
        if (!auth()->user()->hasDocumentPermission($document, 'edit')) {
            return response()->json(['error' => 'You don’t have permission'], 403);
        }
        $validated = $request->validate([
            'binder_id' => 'required|uuid|exists:binders,id',
        ]);

        try {
            $document->update(['binder_id' => $validated['binder_id']]);

            return response()->json($document);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update binder: ' . $e->getMessage()], 500);
        }
    }

    public function copyToBinders(Request $request, $documentId)
    {
        $userId = auth()->id();

        // Validate the request
        $validated = $request->validate([
            'binder_ids' => 'required|array',
            'binder_ids.*' => 'uuid|exists:binders,id',
        ]);

        // Fetch the source document
        $document = Document::findOrFail($documentId);

        // Check if the user has 'view' permission for the source document
        $hasViewPermission = $document->permissions()
            ->where('user_id', $userId)
            ->where('permission', 'view')
            ->exists();

        if (!$hasViewPermission) {
            return response()->json([
                'error' => "You don't have permission to view this document"
            ], 403);
        }

        // Fetch binders and their associated cupboards
        $binders = Binder::whereIn('id', $validated['binder_ids'])->get();
        $cupboardIds = $binders->pluck('cupboard_id')->unique()->toArray();

        // Check if the user has 'manage' permission for all associated cupboards
        $manageableCupboardIds = CupboardUserPermission::where('user_id', $userId)
            ->whereIn('cupboard_id', $cupboardIds)
            ->where('permission', 'manage')
            ->pluck('cupboard_id')
            ->toArray();

        $missingPermissions = array_diff($cupboardIds, $manageableCupboardIds);
        if (!empty($missingPermissions)) {
            return response()->json([
                'error' => "You don't have permission to manage one or more cupboards"
            ], 403);
        }

        $newDocuments = [];
        foreach ($binders as $binder) {
            // Copy the file
            $extension = pathinfo($document->path, PATHINFO_EXTENSION);
            $filename = uniqid() . '.' . $extension;
            $subfolder = strtolower($extension);
            $newPath = Storage::disk('local')->putFileAs($subfolder, Storage::disk('local')->path($document->path), $filename);

            // Create a new document record
            $newDocument = Document::create([
                'title' => $document->title,
                'description' => $document->description,
                'type' => $document->type,
                'ocr' => $document->ocr,
                'tags' => $document->tags,
                'binder_id' => $binder->id,
                'path' => $newPath,
                'is_searchable' => $document->is_searchable,
            ]);

            // Assign permissions to the new document (same as the original)
            $permissions = ['view', 'edit', 'delete', 'download'];
            foreach ($permissions as $perm) {
                $newDocument->permissions()->create([
                    'user_id' => $userId,
                    'permission' => $perm,
                ]);
            }

            $newDocuments[] = $newDocument;
        }

        return response()->json([
            'message' => 'Document copied successfully to specified binders',
            'documents' => $newDocuments,
        ], 201);
    }
}

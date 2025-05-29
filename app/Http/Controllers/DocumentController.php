<?php

namespace App\Http\Controllers;

use App\Models\Binder;
use App\Models\CupboardUserPermission;
use App\Models\Document;
use App\Models\DocumentUserPermission;
use App\Models\Workspace;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\PdfToImage\Pdf;
use thiagoalessio\TesseractOCR\TesseractOCR;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentController extends Controller
{
    private const CONVERTIBLE_TYPES = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    private const VIEWABLE_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
    ];

    public function searchDocuments(Request $request)
    {
        if (!Auth::user()->hasGlobalPermission('can_view_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $validated = $request->validate([
            'workspace_id' => 'required|exists:workspaces,id',
            'search' => 'nullable|string',
            'file_type' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $userId = Auth::id();
        $workspaceId = $validated['workspace_id'];
        $searchTerm = $validated['search'] ?? '';
        $fileType = $validated['file_type'] ?? null; // ← Fixed: Use null coalescing operator
        $perPage = $validated['per_page'] ?? 10;

        // Check if user has access to the workspace
        $canAccessWorkspace = Workspace::where('id', $workspaceId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->orWhereHas('users', function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    });
            })
            ->exists();

        if (!$canAccessWorkspace) {
            return response()->json([
                'error' => "You don't have permission to access this workspace"
            ], 403);
        }

        $query = Document::with(['binder.cupboard'])
            ->whereHas('binder.cupboard', function ($q) use ($workspaceId) {
                $q->where('workspace_id', $workspaceId);
            })
            ->where('title', 'like', '%' . $searchTerm . '%');

        // ← Fixed: Check if file_type is not null AND not empty
        if (!empty($fileType)) {
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

    public function display(Document $document)
    {
        // Security check
        if (!$this->canViewDocument($document)) {
            abort(403, 'Unauthorized');
        }

        // Check if file exists
        if (!Storage::disk('local')->exists($document->path)) {
            abort(404, 'File not found');
        }

        $fullPath = Storage::disk('local')->path($document->path);
        $mimeType = $this->detectMimeType($fullPath);

        Log::info("Document display request", [
            'document_id' => $document->id,
            'mime_type' => $mimeType,
            'file_size' => filesize($fullPath),
            'file_path' => $fullPath
        ]);

        // Define image mime types
        $imageMimeTypes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/svg+xml'
        ];

        // Define video mime types
        $videoMimeTypes = [
            'video/mp4',
            'video/avi',
            'video/mov',
            'video/wmv',
            'video/webm'
        ];

        // Define text mime types
        $textMimeTypes = [
            'text/plain',
            'text/html',
            'text/css',
            'text/javascript',
            'application/json',
            'application/xml'
        ];

        // Serve images directly
        if (in_array($mimeType, $imageMimeTypes)) {
            return response()->file($fullPath, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . $document->title . '"'
            ]);
        }

        // Serve videos directly
        if (in_array($mimeType, $videoMimeTypes)) {
            return response()->file($fullPath, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . $document->title . '"'
            ]);
        }

        // Serve text files directly
        if (in_array($mimeType, $textMimeTypes)) {
            return response()->file($fullPath, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . $document->title . '"'
            ]);
        }

        // If it's already PDF, serve it directly
        if ($mimeType === 'application/pdf') {
            return $this->servePdf($fullPath, $document->title);
        }

        // Convert to PDF if possible
        if (in_array($mimeType, self::CONVERTIBLE_TYPES)) {
            $pdfPath = $this->convertToPdf($document, $mimeType);

            if ($pdfPath && file_exists($pdfPath)) {
                return $this->servePdf($pdfPath, $document->title);
            }
        }

        // If we can't convert or display, show error
        abort(415, 'Cannot display this file type. Supported: Images (JPG, PNG, GIF), Videos (MP4, AVI, MOV), Text files, DOC, DOCX, XLS, XLSX, PPT, PPTX, PDF');
    }

    /**
     * Convert document to PDF with multiple fallback methods
     */
    private function convertToPdf(Document $document, string $mimeType): ?string
    {
        $inputPath = Storage::disk('local')->path($document->path);
        $cacheKey = md5($document->path . $document->updated_at . filemtime($inputPath));
        $cacheDir = storage_path('app/pdf_cache');
        $cachedPdf = "$cacheDir/{$cacheKey}.pdf";

        // Return cached PDF if it exists and is valid
        if (file_exists($cachedPdf) && filesize($cachedPdf) > 1000) {
            Log::info("Using cached PDF", ['document_id' => $document->id]);
            return $cachedPdf;
        }

        // Create cache directory
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $tempDir = storage_path('app/temp/' . uniqid());
        mkdir($tempDir, 0755, true);

        Log::info("Created temp directory", [
            'document_id' => $document->id,
            'temp_dir' => $tempDir,
            'cache_dir' => $cacheDir
        ]);

        try {
            // Try conversion methods in order
            $methods = $this->getConversionMethods($mimeType);

            foreach ($methods as $method) {
                Log::info("Trying conversion method", [
                    'document_id' => $document->id,
                    'method' => $method
                ]);

                $result = $this->$method($inputPath, $tempDir, $cachedPdf);

                Log::info("Conversion method result", [
                    'document_id' => $document->id,
                    'method' => $method,
                    'result' => $result,
                    'output_exists' => file_exists($cachedPdf),
                    'output_size' => file_exists($cachedPdf) ? filesize($cachedPdf) : 0
                ]);

                if ($result && file_exists($cachedPdf) && filesize($cachedPdf) > 1000) {
                    Log::info("Conversion successful", [
                        'document_id' => $document->id,
                        'method' => $method,
                        'size' => filesize($cachedPdf)
                    ]);
                    return $cachedPdf;
                }

                // Clean up failed attempt
                if (file_exists($cachedPdf)) {
                    unlink($cachedPdf);
                }
            }

            Log::error("All conversion methods failed", [
                'document_id' => $document->id,
                'mime_type' => $mimeType
            ]);

            return null;
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * Get conversion methods based on file type
     */
    private function getConversionMethods(string $mimeType): array
    {
        // For text files, use text-specific method first
        if ($this->isTextFile($mimeType)) {
            return ['convertTextToPdf', 'convertWithLibreOffice'];
        }

        // For Excel, use Excel-specific methods
        if ($this->isExcelFile($mimeType)) {
            return ['convertExcelToPdf', 'convertWithLibreOffice'];
        }

        // For everything else, standard LibreOffice
        return ['convertWithLibreOffice', 'convertWithUnoconv'];
    }

    /**
     * Convert text files to PDF
     */
    private function convertTextToPdf(string $inputPath, string $tempDir, string $outputPath): bool
    {
        $content = file_get_contents($inputPath);
        if ($content === false) {
            Log::error("Failed to read text file", ['path' => $inputPath]);
            return false;
        }

        // Create HTML version
        $html = $this->textToHtml($content, basename($inputPath));
        $htmlFile = "$tempDir/document.html";
        file_put_contents($htmlFile, $html);

        // Try wkhtmltopdf first
        if ($this->htmlToPdfWithWkhtml($htmlFile, $outputPath)) {
            return true;
        }

        // Fallback to LibreOffice
        return $this->convertWithLibreOffice($htmlFile, $tempDir, $outputPath);
    }

    /**
     * Convert text to HTML
     */
    private function textToHtml(string $content, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            return $this->csvToHtml($content, $filename);
        }

        // For plain text
        $htmlContent = '<pre style="font-family: Consolas, monospace; white-space: pre-wrap; word-wrap: break-word; font-size: 12px; line-height: 1.4;">';
        $htmlContent .= htmlspecialchars($content);
        $htmlContent .= '</pre>';

        return "<!DOCTYPE html>
    <html>
    <head>
        <meta charset=\"UTF-8\">
        <title>" . htmlspecialchars($filename) . "</title>
        <style>
            body { margin: 20px; font-family: Arial, sans-serif; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #ccc; padding: 8px; text-align: left; font-size: 11px; }
            th { background-color: #f0f0f0; }
        </style>
    </head>
    <body>
        <h2>" . htmlspecialchars($filename) . "</h2>
        $htmlContent
    </body>
    </html>";
    }

    /**
     * Convert CSV to HTML table
     */
    private function csvToHtml(string $content, string $filename): string
    {
        $lines = explode("\n", trim($content));
        $table = '<table>';

        foreach ($lines as $index => $line) {
            if (empty(trim($line))) continue;

            $cells = str_getcsv($line);
            $table .= '<tr>';

            foreach ($cells as $cell) {
                $tag = $index === 0 ? 'th' : 'td';
                $table .= "<$tag>" . htmlspecialchars(trim($cell)) . "</$tag>";
            }

            $table .= '</tr>';
        }

        $table .= '</table>';

        return $this->textToHtml($table, $filename);
    }

    /**
     * HTML to PDF using wkhtmltopdf
     */
    private function htmlToPdfWithWkhtml(string $htmlFile, string $outputPath): bool
    {
        $wkhtml = $this->findExecutable('wkhtmltopdf');
        if (!$wkhtml) {
            Log::info("wkhtmltopdf not found");
            return false;
        }

        // Windows-compatible command
        $command = sprintf(
            '"%s" --page-size A4 --margin-top 15mm --margin-bottom 15mm --margin-left 15mm --margin-right 15mm --quiet "%s" "%s" 2>nul',
            $wkhtml,
            $htmlFile,
            $outputPath
        );

        Log::info("Executing wkhtmltopdf command", ['command' => $command]);
        exec($command, $output, $exitCode);

        Log::info("wkhtmltopdf result", [
            'exit_code' => $exitCode,
            'output_exists' => file_exists($outputPath),
            'output_size' => file_exists($outputPath) ? filesize($outputPath) : 0
        ]);

        return $exitCode === 0 && file_exists($outputPath);
    }

    /**
     * Excel-specific conversion
     */
    private function convertExcelToPdf(string $inputPath, string $tempDir, string $outputPath): bool
    {
        $soffice = $this->findLibreOffice();
        if (!$soffice) {
            Log::error("LibreOffice not found for Excel conversion");
            return false;
        }

        Log::info("Found LibreOffice", ['path' => $soffice]);

        $userProfile = "$tempDir\\soffice_profile";
        mkdir($userProfile, 0755, true);

        // Check if input file is readable
        if (!is_readable($inputPath)) {
            Log::error("Input file not readable", ['path' => $inputPath]);
            return false;
        }

        // Windows-compatible command
        $command = sprintf(
            'set HOME=%s && "%s" --headless --invisible --nodefault --nofirststartwizard --calc --convert-to pdf --outdir "%s" "%s" 2>nul',
            escapeshellarg($userProfile),
            $soffice,
            $tempDir,
            $inputPath
        );

        Log::info("Executing Excel conversion command", ['command' => $command]);
        exec($command, $output, $exitCode);

        Log::info("Excel conversion result", [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output),
            'temp_dir_contents' => scandir($tempDir)
        ]);

        // Find the generated PDF
        $files = glob("$tempDir\\*.pdf");
        if (empty($files)) {
            $files = glob("$tempDir/*.pdf"); // Try forward slash too
        }

        Log::info("PDF files found", ['files' => $files]);

        if (!empty($files)) {
            $generatedPdf = reset($files);
            if (file_exists($generatedPdf) && filesize($generatedPdf) > 1000) {
                Log::info("Moving generated PDF", [
                    'from' => $generatedPdf,
                    'to' => $outputPath,
                    'size' => filesize($generatedPdf)
                ]);
                return rename($generatedPdf, $outputPath);
            } else {
                Log::error("Generated PDF too small or doesn't exist", [
                    'file' => $generatedPdf,
                    'exists' => file_exists($generatedPdf),
                    'size' => file_exists($generatedPdf) ? filesize($generatedPdf) : 0
                ]);
            }
        }

        return false;
    }

    /**
     * Standard LibreOffice conversion
     */
    private function convertWithLibreOffice(string $inputPath, string $tempDir, string $outputPath): bool
    {
        $soffice = $this->findLibreOffice();
        if (!$soffice) {
            Log::error("LibreOffice not found");
            return false;
        }

        Log::info("Found LibreOffice", ['path' => $soffice]);

        $userProfile = "$tempDir\\soffice_profile";
        if (!is_dir($userProfile)) {
            mkdir($userProfile, 0755, true);
        }

        // Check if input file is readable
        if (!is_readable($inputPath)) {
            Log::error("Input file not readable", ['path' => $inputPath]);
            return false;
        }

        // Windows-compatible command
        $command = sprintf(
            'set HOME=%s && "%s" --headless --invisible --nodefault --nofirststartwizard --convert-to pdf --outdir "%s" "%s" 2>nul',
            escapeshellarg($userProfile),
            $soffice,
            $tempDir,
            $inputPath
        );

        Log::info("Executing LibreOffice command", ['command' => $command]);
        exec($command, $output, $exitCode);

        Log::info("LibreOffice conversion result", [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output),
            'temp_dir_contents' => scandir($tempDir)
        ]);

        // Find the generated PDF - try both slash types
        $files = glob("$tempDir\\*.pdf");
        if (empty($files)) {
            $files = glob("$tempDir/*.pdf");
        }

        Log::info("PDF files found", ['files' => $files]);

        if (!empty($files)) {
            $generatedPdf = reset($files);
            if (file_exists($generatedPdf) && filesize($generatedPdf) > 1000) {
                Log::info("Moving generated PDF", [
                    'from' => $generatedPdf,
                    'to' => $outputPath,
                    'size' => filesize($generatedPdf)
                ]);
                return rename($generatedPdf, $outputPath);
            } else {
                Log::error("Generated PDF too small or doesn't exist", [
                    'file' => $generatedPdf,
                    'exists' => file_exists($generatedPdf),
                    'size' => file_exists($generatedPdf) ? filesize($generatedPdf) : 0
                ]);
            }
        }

        return false;
    }

    /**
     * Unoconv conversion (alternative to LibreOffice)
     */
    private function convertWithUnoconv(string $inputPath, string $tempDir, string $outputPath): bool
    {
        $unoconv = $this->findExecutable('unoconv');
        if (!$unoconv) {
            Log::info("unoconv not found");
            return false;
        }

        // Windows-compatible command
        $command = sprintf(
            '"%s" -f pdf -o "%s" "%s" 2>nul',
            $unoconv,
            $outputPath,
            $inputPath
        );

        Log::info("Executing unoconv command", ['command' => $command]);
        exec($command, $output, $exitCode);

        Log::info("unoconv result", [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output),
            'output_exists' => file_exists($outputPath),
            'output_size' => file_exists($outputPath) ? filesize($outputPath) : 0
        ]);

        return $exitCode === 0 && file_exists($outputPath) && filesize($outputPath) > 1000;
    }

    /**
     * Find LibreOffice executable on Windows
     */
    private function findLibreOffice(): ?string
    {
        // Windows paths for LibreOffice
        $paths = [
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            'C:\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files\\LibreOffice\\program\\soffice.com',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.com',
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                Log::info("Found LibreOffice at", ['path' => $path]);
                return $path;
            }
        }

        // Try where command (Windows equivalent of which)
        exec('where soffice 2>nul', $output, $exitCode);
        if ($exitCode === 0 && !empty($output)) {
            $path = trim($output[0]);
            Log::info("Found soffice via where", ['path' => $path]);
            return $path;
        }

        exec('where libreoffice 2>nul', $output, $exitCode);
        if ($exitCode === 0 && !empty($output)) {
            $path = trim($output[0]);
            Log::info("Found libreoffice via where", ['path' => $path]);
            return $path;
        }

        // Try the registry approach (more complex but thorough)
        $registryPath = $this->findLibreOfficeFromRegistry();
        if ($registryPath) {
            return $registryPath;
        }

        Log::warning("LibreOffice not found in any standard location");
        return null;
    }

    /**
     * Find LibreOffice from Windows Registry
     */
    private function findLibreOfficeFromRegistry(): ?string
    {
        try {
            // Try to find LibreOffice installation path from registry
            exec('reg query "HKLM\\SOFTWARE\\LibreOffice\\UNO\\InstallPath" /ve 2>nul', $output, $exitCode);
            if ($exitCode === 0 && !empty($output)) {
                foreach ($output as $line) {
                    if (strpos($line, 'REG_SZ') !== false) {
                        $parts = explode('REG_SZ', $line);
                        if (count($parts) > 1) {
                            $path = trim($parts[1]) . '\\program\\soffice.exe';
                            if (file_exists($path)) {
                                Log::info("Found LibreOffice via registry", ['path' => $path]);
                                return $path;
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Log::debug("Registry lookup failed", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Find any executable on Windows
     */
    private function findExecutable(string $name): ?string
    {
        // Try where command (Windows equivalent of which)
        exec("where $name 2>nul", $output, $exitCode);
        if ($exitCode === 0 && !empty($output)) {
            $path = trim($output[0]);
            Log::info("Found executable", ['name' => $name, 'path' => $path]);
            return $path;
        }

        // Try with .exe extension
        if (!str_ends_with($name, '.exe')) {
            exec("where $name.exe 2>nul", $output, $exitCode);
            if ($exitCode === 0 && !empty($output)) {
                $path = trim($output[0]);
                Log::info("Found executable with .exe", ['name' => $name, 'path' => $path]);
                return $path;
            }
        }

        Log::info("Executable not found", ['name' => $name]);
        return null;
    }

    /**
     * Serve PDF with proper headers for browser display
     */
    private function servePdf(string $pdfPath, string $originalFilename): BinaryFileResponse
    {
        $filename = pathinfo($originalFilename, PATHINFO_FILENAME) . '.pdf';

        return response()->file($pdfPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'public, max-age=3600',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    /**
     * Enhanced MIME type detection
     */
    private function detectMimeType(string $filePath): string
    {
        // Try built-in detection first
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($filePath);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        // Fallback to extension mapping
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            'rtf' => 'application/rtf',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'html' => 'text/html',
            'epub' => 'application/epub+zip',
        ];

        return $mimeMap[$extension] ?? 'application/octet-stream';
    }

    /**
     * File type checks
     */
    private function isTextFile(string $mimeType): bool
    {
        return in_array($mimeType, ['text/plain', 'text/csv', 'text/html']);
    }

    private function isExcelFile(string $mimeType): bool
    {
        return in_array($mimeType, [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv'
        ]);
    }

    private function removeDirectory(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }

    /**
     * Clean old cached PDFs (run this via cron)
     */
    public function cleanPdfCache(): int
    {
        $cacheDir = storage_path('app/pdf_cache');
        if (!is_dir($cacheDir)) {
            return 0;
        }

        $files = glob("$cacheDir/*.pdf");
        $maxAge = 7 * 24 * 3600; // 7 days
        $cleaned = 0;

        foreach ($files as $file) {
            if (filemtime($file) < time() - $maxAge) {
                unlink($file);
                $cleaned++;
            }
        }

        Log::info("PDF cache cleaned", ['files_removed' => $cleaned]);
        return $cleaned;
    }

    /**
     * Debug method to test LibreOffice installation
     */
    public function debugLibreOffice()
    {
        $info = [
            'os' => PHP_OS,
            'libreoffice_path' => $this->findLibreOffice(),
            'unoconv_path' => $this->findExecutable('unoconv'),
            'wkhtmltopdf_path' => $this->findExecutable('wkhtmltopdf'),
            'php_user' => get_current_user(),
            'temp_dir_writable' => is_writable(storage_path('app/temp')),
            'cache_dir_writable' => is_writable(storage_path('app/pdf_cache')),
        ];

        // Test LibreOffice version
        if ($info['libreoffice_path']) {
            exec('"' . $info['libreoffice_path'] . '" --version 2>nul', $output, $exitCode);
            $info['libreoffice_version'] = [
                'exit_code' => $exitCode,
                'output' => implode("\n", $output)
            ];
        }

        // Test unoconv version
        if ($info['unoconv_path']) {
            exec('"' . $info['unoconv_path'] . '" --version 2>nul', $output, $exitCode);
            $info['unoconv_version'] = [
                'exit_code' => $exitCode,
                'output' => implode("\n", $output)
            ];
        }

        Log::info("LibreOffice debug info", $info);
        return response()->json($info);
    }


    public function download(Document $document)
    {
        if (!auth()->user()->hasDocumentPermission($document, 'download')) {
            abort(403, 'No permission');
        }

        $fullPath = Storage::disk('local')->path($document->path);

        if (!file_exists($fullPath)) {
            abort(404, 'File not found');
        }

        $filename = ($document->title ?? 'document') . '.' . pathinfo($document->path, PATHINFO_EXTENSION);

        return response()->file($fullPath, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"'
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

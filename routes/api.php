<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BinderController;
use App\Http\Controllers\CupboardController;
use App\Http\Controllers\CupboardUserPermissionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentUserPermissionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceUserPermissionController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('/login', [AuthController::class, 'login']);

Route::get('/private-file', function (Request $request) {
    if (!$request->hasValidSignature()) {
        abort(403);
    }

    $path = $request->query('path');
    $fullPath = storage_path('app/private/' . $path);

    if (!file_exists($fullPath)) {
        abort(404);
    }

    return response()->file($fullPath);
})->name('private-file');




Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/profile', [AuthController::class, 'updateProfile']);

    // Users APIs
    Route::get('/users/all', [UserController::class, 'getAll']);
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users/{user}', [UserController::class, 'update']);
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
    Route::post('/users/{user}/activate', [UserController::class, 'activate']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);

    // Document User Permissions APIs
    Route::post('/document/{document}/manage-permissions', [DocumentUserPermissionController::class, 'storeOrUpdate']);
    Route::get('/document/{document}/permission-details', [DocumentUserPermissionController::class, 'getDocumentWithUsersAndPermissions']);

    // Cupboard User Permissions APIs
    Route::get('/cupboards/{cupboard}/permissions', [CupboardUserPermissionController::class, 'getCupboardWithUsersAndPermissions']);
    Route::put('/users/{userId}/cupboards/permissions', [CupboardUserPermissionController::class, 'assignManagePermissionToUserForCupboards']);
    Route::put('/cupboards/{cupboardId}/users/permissions', [CupboardUserPermissionController::class, 'assignManagePermissionToUsersForCupboard']);
    Route::get('/cupboards/{cupboard}/permissions/{userId}', [CupboardUserPermissionController::class, 'getCupboardWithUserPermissions']);

    // Cupboard APIs
    Route::get('/cupboards', [CupboardController::class, 'index']);
    Route::post('/cupboards', [CupboardController::class, 'store']);
    Route::get('/cupboards/all', [CupboardController::class, 'getAll']);
    Route::get('/cupboards/workspaces', [CupboardController::class, 'getWorkspaces']);
    Route::get('/cupboards/{cupboard}', [CupboardController::class, 'show']);
    Route::post('/cupboards/{cupboard}', [CupboardController::class, 'update']);
    Route::delete('/cupboards/{cupboard}', [CupboardController::class, 'destroy']);

    // Binder APIs
    Route::get('/binders', [BinderController::class, 'index']);
    Route::post('/binders', [BinderController::class, 'store']);
    Route::get('/binders/{binder}', [BinderController::class, 'show']);
    Route::post('/binders/{binder}', [BinderController::class, 'update']);
    Route::delete('/binders/{binder}', [BinderController::class, 'destroy']);
    Route::patch('binders/{binder}/change-cupboard', [BinderController::class, 'changeCupboard']);

    // Document APIs
    Route::get('/documents/storage-usage', [DocumentController::class, 'storageUsage']);
    Route::get('/documents/search', [DocumentController::class, 'searchDocuments']);
    Route::post('/documents/extract-ocr', [DocumentController::class, 'extractOcr']);
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::post('/documents/{document}', [DocumentController::class, 'update']);
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download');

    // New route for actual file serving
    Route::get('/documents/{document}/file', [DocumentController::class, 'downloadFile'])
        ->name('documents.download.file');
    Route::get('/documents/{document}/display', [DocumentController::class, 'display']);
    Route::put('/documents/{document}/change-binder', [DocumentController::class, 'changeBinder']);
    Route::post('/documents/{documentId}/copy-to-binders', [DocumentController::class, 'copyToBinders']);

    // serve files
    Route::get('/storage/converted/{filename}', [DocumentController::class, 'serveConvertedFile']);

    // Workspace APIs
    Route::get('/workspaces/manageable', [WorkspaceController::class, 'getManageable']);
    Route::post('/workspaces', [WorkspaceController::class, 'store']);
    Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show']);
    Route::post('/workspaces/{workspace}', [WorkspaceController::class, 'update']);
    Route::delete('/workspaces/{workspace}', [WorkspaceController::class, 'destroy']);
    Route::post('/workspaces/{workspace}/share', [WorkspaceController::class, 'shareWithUser']);
    Route::post('/workspaces/{workspace}/remove-user', [WorkspaceController::class, 'removeUserPermission']);

    // workspace permissions
    Route::post('/workspaces/{workspace}/permissions', [WorkspaceUserPermissionController::class, 'storeOrUpdate']);
    Route::get('/workspaces/{workspace}/permissions', [WorkspaceUserPermissionController::class, 'getWorkspaceWithUsersAndPermissions']);
});

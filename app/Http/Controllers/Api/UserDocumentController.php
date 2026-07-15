<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserDocument;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserDocumentController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private AuditLogService $auditLogs,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'documents' => $request->user()
                ->documents()
                ->with('reviewer:id,name,email')
                ->limit(20)
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:80'],
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $file = $validated['document'];
        $path = $file->store('user-documents/' . $user->id, 'public');

        $document = UserDocument::create([
            'user_id' => $user->id,
            'type' => $validated['type'],
            'document_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'status' => 'pending',
        ]);

        $this->notifications->createNotification([
            'audience' => 'admin',
            'title' => 'New client document uploaded',
            'severity' => 'info',
            'type' => 'document_uploaded',
            'data' => [
                'message' => $user->name . ' uploaded a ' . $document->type . ' document.',
                'user_name' => $user->name,
                'user_email' => $user->email,
                'document_type' => $document->type,
            ],
            'action_url' => '/admin/dashboard',
        ]);

        $this->auditLogs->record('user_document_uploaded', $user, [
            'subject' => $document,
            'severity' => 'info',
            'ip_address' => $request->ip(),
            'data' => [
                'document_type' => $document->type,
                'document_name' => $document->document_name,
            ],
        ]);

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'document' => $document->fresh(['reviewer:id,name,email']),
        ], 201);
    }
}

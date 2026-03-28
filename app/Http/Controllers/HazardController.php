<?php

namespace App\Http\Controllers;

use App\Models\HazardAttachment;
use App\Models\HazardReport;
use App\Models\HazardStatus;
use App\Models\HazardStatusHistory;
use App\Models\UserNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HazardController extends Controller
{
    private function checkUploadedFilesValid(Request $request, string $field)
    {
        if (! $request->hasFile($field)) {
            return null;
        }

        foreach ((array) $request->file($field) as $file) {
            if (! $file || ! method_exists($file, 'isValid')) {
                continue;
            }

            if (! $file->isValid()) {
                // Most commonly caused by server-side upload size limits (php.ini/nginx).
                return response()->json([
                    'message' => 'Attachment upload failed. Please use smaller image files.',
                    'code' => 'UPLOAD_FAILED',
                    'details' => [
                        'upload_error_code' => (int) $file->getError(),
                        'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                        'post_max_size' => (string) ini_get('post_max_size'),
                        'max_file_uploads' => (string) ini_get('max_file_uploads'),
                    ],
                ], 422);
            }
        }

        return null;
    }

    private function isMutableStatus(HazardReport $report): bool
    {
        $key = strtolower((string) optional($report->currentStatus)->key);
        // Editable/deletable only while pending (or legacy `new`).
        return in_array($key, ['pending', 'new'], true);
    }

    private function formatRecordId(int $hazardReportId): string
    {
        return 'HZR-' . str_pad((string) $hazardReportId, 5, '0', STR_PAD_LEFT);
    }

    private function createNotification(User $recipient, ?HazardReport $report, string $type, string $title, string $message, ?string $statusKey = null): void
    {
        UserNotification::query()->create([
            'user_id' => $recipient->id,
            'hazard_report_id' => $report?->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'status_key' => $statusKey,
            'read_at' => null,
            'created_at' => now(),
        ]);
    }

    private function sendHazardEmail(User $recipient, string $subject, string $body): void
    {
        if (! $recipient->email) {
            return;
        }

        try {
            Mail::raw($body, function ($m) use ($recipient, $subject) {
                $m->to($recipient->email)->subject($subject);
            });
        } catch (\Throwable $e) {
            // Do not block core hazard workflows if email delivery fails.
            logger()->warning('Hazard email delivery failed', [
                'recipient_email' => $recipient->email,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function my(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $query = HazardReport::query()
            ->with(['category', 'location', 'currentStatus'])
            ->where('reporter_user_id', $user->id)
            ->orderByDesc('created_at');

        if ($statusKey = $request->query('status_key')) {
            $statusId = HazardStatus::query()->where('key', $statusKey)->value('id');
            if ($statusId) {
                $query->where('current_status_id', $statusId);
            }
        }

        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }

        $perPage = min(max((int) $request->query('per_page', 10), 1), 50);

        return response()->json($query->paginate($perPage));
    }

    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $query = HazardReport::query()
            ->with(['reporter', 'category', 'location', 'currentStatus'])
            ->orderByDesc('created_at');

        if ($statusKey = $request->query('status_key')) {
            $statusId = HazardStatus::query()->where('key', $statusKey)->value('id');
            if ($statusId) {
                $query->where('current_status_id', $statusId);
            }
        }

        foreach (['category_id', 'location_id'] as $field) {
            if ($value = $request->query($field)) {
                $query->where($field, $value);
            }
        }

        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }

        if ($q = $request->query('q')) {
            $q = trim($q);
            $query->where(function ($sub) use ($q) {
                $sub->where('description', 'like', "%{$q}%")
                    ->orWhereHas('reporter', function ($u) use ($q) {
                        $u->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%");
                    });
            });
        }

        $perPage = min(max((int) $request->query('per_page', 10), 1), 50);

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(function (HazardReport $report) {
            return $report->makeHidden(['assigned_to_user_id']);
        });

        return response()->json($paginator);
    }

    public function show(Request $request, int $id)
    {
        /** @var User $user */
        $user = $request->user();

        $report = HazardReport::query()
            ->withTrashed()
            ->with([
                'reporter',
                'category',
                'location',
                'currentStatus',
                'assignedTo',
                'attachments',
                'statusHistory.toStatus',
                'statusHistory.fromStatus',
                'statusHistory.changedBy',
            ])
            ->findOrFail($id);

        $isAdmin = $user->role === User::ROLE_ADMIN;
        if (! $isAdmin && $report->reporter_user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $isAdmin) {
            $report->setRelation('statusHistory', $report->statusHistory->where('is_public', true)->values());
        }

        return response()->json(['data' => $report]);
    }

    public function store(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->checkUploadedFilesValid($request, 'attachments')) {
            return $response;
        }

        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:hazard_categories,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'severity' => ['required', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
            'observed_at' => ['nullable', 'date'],
            'description' => ['required', 'string', 'min:10'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:5120'], // 5MB each
        ]);

        $pendingStatusId = HazardStatus::query()->where('key', 'pending')->value('id');
        // Backward compatibility: older databases may still have `new` as the initial status.
        if (! $pendingStatusId) {
            $pendingStatusId = HazardStatus::query()->where('key', 'new')->value('id');
        }
        if (! $pendingStatusId) {
            throw ValidationException::withMessages([
                'status' => ['Missing default status: pending. Please run database seeders.'],
            ]);
        }

        $report = DB::transaction(function () use ($data, $user, $request, $pendingStatusId) {
            $report = HazardReport::query()->create([
                'reporter_user_id' => $user->id,
                'category_id' => $data['category_id'],
                'location_id' => $data['location_id'],
                'severity' => $data['severity'],
                'observed_at' => $data['observed_at'] ?? null,
                'description' => $data['description'],
                'current_status_id' => $pendingStatusId,
                'assigned_to_user_id' => null,
            ]);

            HazardStatusHistory::query()->create([
                'hazard_report_id' => $report->id,
                'from_status_id' => null,
                'to_status_id' => $pendingStatusId,
                'changed_by_user_id' => $user->id,
                'note' => 'Report submitted.',
                'is_public' => true,
                'created_at' => now(),
            ]);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store("hazards/{$report->id}", ['disk' => config('filesystems.default')]);

                    HazardAttachment::query()->create([
                        'hazard_report_id' => $report->id,
                        'uploaded_by_user_id' => $user->id,
                        'disk' => config('filesystems.default'),
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
                        'size_bytes' => $file->getSize(),
                        'created_at' => now(),
                    ]);
                }
            }

            return $report;
        });

        $report->load(['reporter', 'category', 'location', 'currentStatus', 'attachments']);

        // Notify reporter (in-app + email).
        $recipient = $report->reporter;
        $currentStatusKey = strtolower((string) optional($report->currentStatus)->key);
        $currentStatusLabel = $report->currentStatus?->label ?? $currentStatusKey;
        $recordId = $this->formatRecordId($report->id);
        $recipientName = $recipient?->name ?? 'Reporter';

        $title = "Hazard Report Submitted ({$recordId})";
        $message = "Dear {$recipientName},\n\n" .
            "Your hazard report ({$recordId}) has been submitted to the administrator for review.\n" .
            "Current status: {$currentStatusLabel}.\n\n" .
            "Thank you.";

        $this->createNotification(
            $recipient,
            $report,
            'submitted',
            $title,
            $message,
            $currentStatusKey ?: 'pending'
        );
        $this->sendHazardEmail($recipient, "Hazard report submitted - {$recordId}", $message);

        return response()->json(['data' => $report], 201);
    }

    public function update(Request $request, int $id)
    {
        /** @var User $user */
        $user = $request->user();

        $report = HazardReport::query()->with('currentStatus')->findOrFail($id);

        $isAdmin = $user->role === User::ROLE_ADMIN;
        if (! $isAdmin && $report->reporter_user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if (! $this->isMutableStatus($report)) {
            if (! $isAdmin) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
            return response()->json([
                'message' => 'This report can only be edited while status is Pending.',
                'code' => 'REPORT_NOT_MUTABLE',
            ], 422);
        }

        $data = $request->validate($isAdmin ? [
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'category_id' => ['sometimes', 'integer', 'exists:hazard_categories,id'],
            'location_id' => ['sometimes', 'integer', 'exists:locations,id'],
            'severity' => ['sometimes', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
            'observed_at' => ['nullable', 'date'],
            'description' => ['sometimes', 'string', 'min:10'],
        ] : [
            'category_id' => ['sometimes', 'integer', 'exists:hazard_categories,id'],
            'location_id' => ['sometimes', 'integer', 'exists:locations,id'],
            'severity' => ['sometimes', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
            'observed_at' => ['nullable', 'date'],
            'description' => ['sometimes', 'string', 'min:10'],
        ]);

        $report->fill($data);
        $report->save();

        $report->load(['reporter', 'category', 'location', 'currentStatus', 'assignedTo', 'attachments']);

        // Add a timeline/audit entry even though the status itself did not change.
        $currentStatusId = $report->current_status_id;
        $note = $isAdmin
            ? 'Hazard record updated by administrator.'
            : 'Hazard record updated by reporter.';

        HazardStatusHistory::query()->create([
            'hazard_report_id' => $report->id,
            'from_status_id' => $currentStatusId,
            'to_status_id' => $currentStatusId,
            'changed_by_user_id' => $user->id,
            'note' => $note,
            'is_public' => true,
            'created_at' => now(),
        ]);

        if ($isAdmin) {
            // Notify reporter (in-app + email) for admin edits.
            $recipient = $report->reporter;
            $currentStatusKey = strtolower((string) optional($report->currentStatus)->key);
            $currentStatusLabel = $report->currentStatus?->label ?? $currentStatusKey;
            $recordId = $this->formatRecordId($report->id);
            $recipientName = $recipient?->name ?? 'Reporter';

            $title = "Hazard Report Updated ({$recordId})";
            $message = "Dear {$recipientName},\n\n" .
                "An administrator updated your hazard report ({$recordId}).\n" .
                "Current status: {$currentStatusLabel}.\n\n" .
                "Thank you.";

            $this->createNotification(
                $recipient,
                $report,
                'edited',
                $title,
                $message,
                $currentStatusKey ?: null
            );
            $this->sendHazardEmail($recipient, "Hazard report updated - {$recordId}", $message);
        }

        return response()->json(['data' => $report]);
    }

    public function destroy(Request $request, int $id)
    {
        /** @var User $user */
        $user = $request->user();

        $report = HazardReport::query()->with(['reporter', 'currentStatus', 'attachments', 'statusHistory'])->findOrFail($id);

        $isAdmin = $user->role === User::ROLE_ADMIN;
        if (! $isAdmin && $report->reporter_user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if (! $this->isMutableStatus($report)) {
            if (! $isAdmin) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
            return response()->json([
                'message' => 'This report can only be deleted while status is Pending.',
                'code' => 'REPORT_NOT_MUTABLE',
            ], 422);
        }

        DB::transaction(function () use ($report, $user, $isAdmin) {
            // Preserve hazard status history & attachments for audit/timeline after delete.
            $note = $isAdmin
                ? 'Report deleted by administrator.'
                : 'Report deleted by reporter.';

            HazardStatusHistory::query()->create([
                'hazard_report_id' => $report->id,
                'from_status_id' => $report->current_status_id,
                'to_status_id' => $report->current_status_id,
                'changed_by_user_id' => $user->id,
                'note' => $note,
                'is_public' => true,
                'created_at' => now(),
            ]);

            $report->delete(); // soft delete (keeps timeline)
        });

        if ($isAdmin) {
            $recipient = $report->reporter;
            $currentStatusKey = strtolower((string) optional($report->currentStatus)->key);
            $currentStatusLabel = $report->currentStatus?->label ?? $currentStatusKey;
            $recordId = $this->formatRecordId($report->id);
            $recipientName = $recipient?->name ?? 'Reporter';

            $title = "Hazard Report Deleted ({$recordId})";
            $message = "Dear {$recipientName},\n\n" .
                "Your hazard report ({$recordId}) has been deleted by an administrator.\n" .
                "Last known status: {$currentStatusLabel}.\n\n" .
                "If you believe this is an error, please contact system administrators.\n\n" .
                "Thank you.";

            $this->createNotification(
                $recipient,
                $report,
                'deleted',
                $title,
                $message,
                $currentStatusKey ?: null
            );
            $this->sendHazardEmail($recipient, "Hazard report deleted - {$recordId}", $message);
        }

        return response()->json(['ok' => true]);
    }

    public function changeStatus(Request $request, int $id)
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'to_status_key' => ['required', 'string', Rule::in(['pending', 'in_progress', 'resolved']), 'exists:hazard_statuses,key'],
            'note' => ['nullable', 'string'],
        ]);

        $report = HazardReport::query()->with('currentStatus')->findOrFail($id);
        $toStatus = HazardStatus::query()->where('key', $data['to_status_key'])->firstOrFail();

        $fromStatusId = $report->current_status_id;

        DB::transaction(function () use ($report, $fromStatusId, $toStatus, $data, $user) {
            $report->current_status_id = $toStatus->id;
            $report->save();

            HazardStatusHistory::query()->create([
                'hazard_report_id' => $report->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $toStatus->id,
                'changed_by_user_id' => $user->id,
                'note' => $data['note'] ?? null,
                'is_public' => true,
                'created_at' => now(),
            ]);
        });

        $report->refresh()->load(['reporter', 'category', 'location', 'currentStatus', 'assignedTo']);

        // Notify reporter (in-app + email).
        $recipient = $report->reporter;
        $currentStatusKey = strtolower((string) optional($report->currentStatus)->key);
        $currentStatusLabel = $report->currentStatus?->label ?? $currentStatusKey;
        $recordId = $this->formatRecordId($report->id);
        $recipientName = $recipient?->name ?? 'Reporter';

        $title = "Hazard Status Updated ({$recordId})";
        $message = "Dear {$recipientName},\n\n" .
            "The status of your hazard report ({$recordId}) has been updated.\n" .
            "New status: {$currentStatusLabel}.\n";

        if (! empty($data['note'])) {
            $message .= "\nAdministrator note:\n{$data['note']}\n";
        }

        $message .= "\nThank you.";

        $this->createNotification(
            $recipient,
            $report,
            'status_updated',
            $title,
            $message,
            $currentStatusKey ?: $data['to_status_key']
        );
        $this->sendHazardEmail($recipient, "Hazard status updated - {$recordId}", $message);

        return response()->json(['data' => $report]);
    }

    public function addAttachments(Request $request, int $id)
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->checkUploadedFilesValid($request, 'attachments')) {
            return $response;
        }

        $report = HazardReport::query()->findOrFail($id);

        $isAdmin = $user->role === User::ROLE_ADMIN;
        if (! $isAdmin && $report->reporter_user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'attachments' => ['required', 'array', 'min:1', 'max:5'],
            'attachments.*' => ['file', 'max:5120'],
        ]);

        foreach ($request->file('attachments') as $file) {
            $path = $file->store("hazards/{$report->id}", ['disk' => config('filesystems.default')]);

            HazardAttachment::query()->create([
                'hazard_report_id' => $report->id,
                'uploaded_by_user_id' => $user->id,
                'disk' => config('filesystems.default'),
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'created_at' => now(),
            ]);
        }

        $report->load(['attachments']);

        return response()->json(['data' => $report->attachments]);
    }

    public function removeAttachment(Request $request, int $id, int $attachmentId)
    {
        /** @var User $user */
        $user = $request->user();

        $report = HazardReport::query()->findOrFail($id);

        $isAdmin = $user->role === User::ROLE_ADMIN;
        if (! $isAdmin && $report->reporter_user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $attachment = HazardAttachment::query()
            ->where('hazard_report_id', $report->id)
            ->findOrFail($attachmentId);

        $disk = $attachment->disk ?: config('filesystems.default');
        $path = $attachment->path;
        if ($disk && $path) {
            Storage::disk($disk)->delete($path);
        }

        $attachment->delete();

        $report->load(['attachments']);

        return response()->json(['data' => $report->attachments]);
    }

    public function downloadAttachment(Request $request, int $id, int $attachmentId): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $report = HazardReport::query()->findOrFail($id);
        $isAdmin = $user->role === User::ROLE_ADMIN;
        if (! $isAdmin && $report->reporter_user_id !== $user->id) {
            abort(403, 'Forbidden.');
        }

        $attachment = HazardAttachment::query()
            ->where('hazard_report_id', $report->id)
            ->findOrFail($attachmentId);

        $disk = $attachment->disk ?: config('filesystems.default');
        $path = $attachment->path;
        if (! $disk || ! $path) {
            abort(404, 'Attachment not found.');
        }

        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            abort(404, 'Attachment not found.');
        }

        $filename = $attachment->original_name ?: basename($path);
        $mime = $attachment->mime_type ?: 'application/octet-stream';

        return response()->stream(function () use ($storage, $path) {
            $stream = $storage->readStream($path);
            if (! $stream) {
                return;
            }
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}


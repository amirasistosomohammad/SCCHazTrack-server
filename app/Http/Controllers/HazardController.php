<?php

namespace App\Http\Controllers;

use App\Models\HazardAttachment;
use App\Models\HazardReport;
use App\Models\HazardStatus;
use App\Models\HazardStatusHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HazardController extends Controller
{
    private function canReporterMutate(HazardReport $report, User $user): bool
    {
        if ($user->role === User::ROLE_ADMIN) {
            return true;
        }

        if ($report->reporter_user_id !== $user->id) {
            return false;
        }

        $key = strtolower((string) optional($report->currentStatus)->key);
        // Reporters may only edit/delete while the report is still pending (or legacy `new`).
        return in_array($key, ['pending', 'new'], true);
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
            ->with(['reporter', 'category', 'location', 'currentStatus', 'assignedTo'])
            ->orderByDesc('created_at');

        if ($statusKey = $request->query('status_key')) {
            $statusId = HazardStatus::query()->where('key', $statusKey)->value('id');
            if ($statusId) {
                $query->where('current_status_id', $statusId);
            }
        }

        foreach (['category_id', 'location_id', 'assigned_to_user_id'] as $field) {
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

        return response()->json($query->paginate($perPage));
    }

    public function show(Request $request, int $id)
    {
        /** @var User $user */
        $user = $request->user();

        $report = HazardReport::query()
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

        $report->load(['category', 'location', 'currentStatus', 'attachments']);

        return response()->json(['data' => $report], 201);
    }

    public function update(Request $request, int $id)
    {
        /** @var User $user */
        $user = $request->user();

        $report = HazardReport::query()->with('currentStatus')->findOrFail($id);

        $isAdmin = $user->role === User::ROLE_ADMIN;
        if (! $isAdmin && ! $this->canReporterMutate($report, $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate($isAdmin ? [
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'category_id' => ['sometimes', 'integer', 'exists:hazard_categories,id'],
            'location_id' => ['sometimes', 'integer', 'exists:locations,id'],
            'severity' => ['sometimes', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
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

        return response()->json(['data' => $report]);
    }

    public function destroy(Request $request, int $id)
    {
        /** @var User $user */
        $user = $request->user();

        $report = HazardReport::query()->with(['currentStatus', 'attachments', 'statusHistory'])->findOrFail($id);

        $isAdmin = $user->role === User::ROLE_ADMIN;
        if (! $isAdmin && ! $this->canReporterMutate($report, $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        DB::transaction(function () use ($report) {
            foreach ($report->attachments as $attachment) {
                $disk = $attachment->disk ?: config('filesystems.default');
                $path = $attachment->path;
                if ($disk && $path) {
                    Storage::disk($disk)->delete($path);
                }
            }

            $report->attachments()->delete();
            $report->statusHistory()->delete();
            $report->delete();
        });

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
            'to_status_key' => ['required', 'string', 'exists:hazard_statuses,key'],
            'note' => ['nullable', 'string'],
            'is_public' => ['sometimes', 'boolean'],
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
                'is_public' => (bool) ($data['is_public'] ?? true),
                'created_at' => now(),
            ]);
        });

        $report->refresh()->load(['reporter', 'category', 'location', 'currentStatus', 'assignedTo']);

        return response()->json(['data' => $report]);
    }

    public function addAttachments(Request $request, int $id)
    {
        /** @var User $user */
        $user = $request->user();

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


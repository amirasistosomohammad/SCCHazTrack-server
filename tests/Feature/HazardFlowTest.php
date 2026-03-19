<?php

namespace Tests\Feature;

use App\Models\HazardCategory;
use App\Models\HazardReport;
use App\Models\HazardStatus;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HazardFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporter_can_create_hazard_and_list_my_reports(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'reporter@scc.test')->firstOrFail();
        Sanctum::actingAs($user);

        $categoryId = HazardCategory::query()->value('id');
        $locationId = Location::query()->value('id');

        $create = $this->postJson('/api/hazards', [
            'category_id' => $categoryId,
            'location_id' => $locationId,
            'severity' => 'low',
            'description' => 'Broken light in hallway near room 101.',
        ]);

        $create->assertCreated();
        $id = $create->json('data.id');
        $this->assertNotNull($id);

        $list = $this->getJson('/api/hazards/my');
        $list->assertOk();
        $list->assertJsonPath('total', 1);

        $show = $this->getJson("/api/hazards/{$id}");
        $show->assertOk();
        $show->assertJsonPath('data.reporter_user_id', $user->id);
    }

    public function test_reporter_cannot_access_admin_list(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'reporter@scc.test')->firstOrFail();
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/hazards');
        $res->assertStatus(403);
    }

    public function test_admin_can_change_status(): void
    {
        $this->seed();

        $reporter = User::query()->where('email', 'reporter@scc.test')->firstOrFail();
        Sanctum::actingAs($reporter);

        $categoryId = HazardCategory::query()->value('id');
        $locationId = Location::query()->value('id');

        $create = $this->postJson('/api/hazards', [
            'category_id' => $categoryId,
            'location_id' => $locationId,
            'severity' => 'low',
            'description' => 'Leaking pipe in restroom.',
        ])->assertCreated();

        $reportId = $create->json('data.id');

        $admin = User::query()->where('email', 'admin@scc.test')->firstOrFail();
        Sanctum::actingAs($admin);

        $change = $this->postJson("/api/hazards/{$reportId}/status", [
            'to_status_key' => 'under_review',
            'note' => 'Checking details.',
            'is_public' => true,
        ]);

        $change->assertOk();

        $statusId = HazardStatus::query()->where('key', 'under_review')->value('id');
        $this->assertSame($statusId, HazardReport::query()->findOrFail($reportId)->current_status_id);
    }

    public function test_reporter_can_download_attachment_stream(): void
    {
        $this->seed();

        Storage::fake(config('filesystems.default'));

        $user = User::query()->where('email', 'reporter@scc.test')->firstOrFail();
        Sanctum::actingAs($user);

        $categoryId = HazardCategory::query()->value('id');
        $locationId = Location::query()->value('id');

        $file = UploadedFile::fake()->image('evidence.jpg', 640, 480);

        $create = $this->post('/api/hazards', [
            'category_id' => $categoryId,
            'location_id' => $locationId,
            'severity' => 'low',
            'description' => 'Photo evidence attached for testing.',
            'attachments' => [$file],
        ]);

        $create->assertCreated();

        $reportId = $create->json('data.id');
        $attachmentId = $create->json('data.attachments.0.id');
        $this->assertNotNull($reportId);
        $this->assertNotNull($attachmentId);

        $download = $this->get("/api/hazards/{$reportId}/attachments/{$attachmentId}");
        $download->assertOk();
        $download->assertHeader('Content-Type', 'image/jpeg');
        $this->assertNotEmpty($download->streamedContent());
    }

    public function test_reporter_can_add_and_remove_attachments_on_pending_report(): void
    {
        $this->seed();

        Storage::fake(config('filesystems.default'));

        $user = User::query()->where('email', 'reporter@scc.test')->firstOrFail();
        Sanctum::actingAs($user);

        $categoryId = HazardCategory::query()->value('id');
        $locationId = Location::query()->value('id');

        $create = $this->post('/api/hazards', [
            'category_id' => $categoryId,
            'location_id' => $locationId,
            'severity' => 'low',
            'description' => 'Report for attachment add/remove.',
        ])->assertCreated();

        $reportId = $create->json('data.id');

        $file = UploadedFile::fake()->image('extra.jpg', 320, 240);

        $add = $this->post("/api/hazards/{$reportId}/attachments", [
            'attachments' => [$file],
        ])->assertOk();

        $attachmentId = $add->json('data.0.id');
        $this->assertNotNull($attachmentId);

        $remove = $this->delete("/api/hazards/{$reportId}/attachments/{$attachmentId}")
            ->assertOk();

        $remove->assertJsonCount(0, 'data');
    }

    public function test_reporter_can_edit_and_delete_own_pending_report(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'reporter@scc.test')->firstOrFail();
        Sanctum::actingAs($user);

        $categoryId = HazardCategory::query()->value('id');
        $locationId = Location::query()->value('id');

        $create = $this->postJson('/api/hazards', [
            'category_id' => $categoryId,
            'location_id' => $locationId,
            'severity' => 'low',
            'description' => 'Initial description for edit/delete test.',
        ])->assertCreated();

        $reportId = $create->json('data.id');
        $this->assertNotNull($reportId);

        $patch = $this->patchJson("/api/hazards/{$reportId}", [
            'severity' => 'medium',
            'description' => 'Updated description for edit/delete test.',
        ]);

        $patch->assertOk();
        $patch->assertJsonPath('data.severity', 'medium');
        $patch->assertJsonPath('data.description', 'Updated description for edit/delete test.');

        $del = $this->deleteJson("/api/hazards/{$reportId}");
        $del->assertOk();
        $del->assertJsonPath('ok', true);

        $this->assertNull(HazardReport::query()->find($reportId));
    }

    public function test_reporter_cannot_edit_or_delete_after_under_review(): void
    {
        $this->seed();

        $reporter = User::query()->where('email', 'reporter@scc.test')->firstOrFail();
        Sanctum::actingAs($reporter);

        $categoryId = HazardCategory::query()->value('id');
        $locationId = Location::query()->value('id');

        $create = $this->postJson('/api/hazards', [
            'category_id' => $categoryId,
            'location_id' => $locationId,
            'severity' => 'low',
            'description' => 'Reporter cannot edit once under review.',
        ])->assertCreated();

        $reportId = $create->json('data.id');

        $admin = User::query()->where('email', 'admin@scc.test')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson("/api/hazards/{$reportId}/status", [
            'to_status_key' => 'under_review',
            'note' => 'Lock it.',
            'is_public' => true,
        ])->assertOk();

        Sanctum::actingAs($reporter);

        $patch = $this->patchJson("/api/hazards/{$reportId}", [
            'description' => 'Should be forbidden.',
        ]);
        $patch->assertStatus(403);

        $del = $this->deleteJson("/api/hazards/{$reportId}");
        $del->assertStatus(403);
    }
}


<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_me_work(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'reporter@scc.test')->firstOrFail();
        Sanctum::actingAs($user);

        $me = $this->getJson('/api/auth/me');
        $me->assertOk();
        $me->assertJsonPath('user.email', $user->email);
    }
}


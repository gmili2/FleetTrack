<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function spa(): array
{
    return ['Referer' => 'http://localhost:5173', 'Origin' => 'http://localhost:5173', 'Accept' => 'application/json'];
}

it('connecte un utilisateur de démo et renvoie ses permissions', function () {
    $this->withHeaders(spa())->postJson('/api/auth/login', ['email' => 'flotte@demo.ma', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.role', 'fleet_manager')
        ->assertJsonPath('data.role_label', 'Responsable de flotte');

    $this->withHeaders(spa())->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.email', 'flotte@demo.ma');
});

it('refuse un mauvais mot de passe', function () {
    $this->withHeaders(spa())->postJson('/api/auth/login', ['email' => 'flotte@demo.ma', 'password' => 'faux'])
        ->assertStatus(422)->assertJsonValidationErrors('email');
});

it('verrouille le compte 15 minutes après 5 échecs', function () {
    foreach (range(1, 4) as $i) {
        $this->withHeaders(spa())->postJson('/api/auth/login', ['email' => 'admin@demo.ma', 'password' => 'faux'])->assertStatus(422);
    }
    $this->withHeaders(spa())->postJson('/api/auth/login', ['email' => 'admin@demo.ma', 'password' => 'faux'])->assertStatus(423);

    // Même le bon mot de passe est refusé pendant le verrouillage.
    $this->withHeaders(spa())->postJson('/api/auth/login', ['email' => 'admin@demo.ma', 'password' => 'password'])->assertStatus(423);
    expect(User::where('email', 'admin@demo.ma')->first()->locked_until->isFuture())->toBeTrue();

    $this->travel(16)->minutes();
    $this->withHeaders(spa())->postJson('/api/auth/login', ['email' => 'admin@demo.ma', 'password' => 'password'])->assertOk();
});

it('protège les routes API', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized();
});

it('déconnecte l’utilisateur', function () {
    $this->withHeaders(spa())->postJson('/api/auth/login', ['email' => 'operateur@demo.ma', 'password' => 'password'])->assertOk();
    $this->withHeaders(spa())->postJson('/api/auth/logout')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withHeaders(spa())->getJson('/api/auth/me')->assertUnauthorized();
});

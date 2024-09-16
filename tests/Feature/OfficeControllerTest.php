<?php

use App\Models\Image;
use App\Models\Tag;
use App\Models\User;
use App\Models\Office;
use App\Models\Reservation;

it('lists all offices in paginated way', function () {
    Office::factory(3)->create();

    $response = $this->get('api/offices');
    expect($response->json())->toHaveKeys(['meta', 'links']);
});

it('lists offices that are approved and not hidden', function () {
    Office::factory(3)->create();

    Office::factory()->create(['hidden' => true]);
    Office::factory()->create(['approval_status' => Office::APPROVAL_APPROVED]);

    $response = $this->get('/api/offices');
    expect($this->get('/api/offices'))->assertOk();
    expect($response->json())->toHaveKeys(['meta', 'links']);
});

it('filters by host id', function () {
    Office::factory(3)->create();

    $host = User::factory()->create();
    $office = Office::factory()->for($host)->create();

    $response = $this->get('/api/offices?host_id='.$host->id);
    $response->assertOk();
    expect($response->json())->toBeGreaterThanOrEqual(1);
    $this->assertEquals($office->id, $response->json('data')[0]['id']);
});

it('filters by user id', function () {
    Office::factory(3)->create();

    $user = User::factory()->create();
    $office = Office::factory()->create();

    Reservation::factory()->for($office::factory())->create();
    Reservation::factory()->for($office)->for($user)->create();

    $response = $this->get('/api/offices?user_id='.$user->id);
    $response->assertOk();
    expect($response->json())->toBeGreaterThanOrEqual(1);
    $this->assertEquals($office->id, $response->json('data')[0]['id']);
});

it('include images tags and user', function () {
    $user = User::factory()->create();
    $tag = Tag::factory(['name' => 'mokrane'])->create();
    $office = Office::factory()->for($user)->create();

    $office->tags()->attach($tag);
    $office->images()->create(['path' => 'image.jpg']);

    $response = $this->get('/api/offices');
    expect($response->json('data')[0])->toHaveKeys(['images', 'tags', 'user']);
});

it('returns the number of active reservations', function () {
    $office = Office::factory()->create();

    Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_ACTIVE]);
    Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_CANCELLED]);
    $response = $this->get('/api/offices');
    $response->assertOk();
    expect($response->json('data')[0]['reservations_count'])->toEqual(1);
});
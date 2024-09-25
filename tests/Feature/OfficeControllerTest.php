<?php

use App\Models\Tag;
use App\Models\User;
use App\Models\Image;
use App\Models\Office;
use App\Models\Reservation;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use App\Notifications\OfficePendingApprovalNotification;

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

it('lists offices including hidden and unapproved if filtering for the current logged-in user', function () {
    $user = User::factory()->create();

    Office::factory(3)->for($user)->create();
    Office::factory()->hidden()->for($user)->create();
    Office::factory()->pending()->for($user)->create();
    
    $this->actingAs($user);
    
    $response = $this->get('/api/offices?user_id='.$user->id);
    $response->assertOk();
});

it('filters by user id', function () {
    Office::factory(3)->create();

    $host = User::factory()->create();
    $office = Office::factory()->for($host)->create();

    $response = $this->get('/api/offices?user_id=' . $host->id);
    $response->assertOk();
    expect($response->json())->toBeGreaterThanOrEqual(1);
});

it('filters by visitor id', function () {
    Office::factory(3)->create();

    $user = User::factory()->create();
    $office = Office::factory()->create();

    Reservation::factory()->for($office::factory())->create();
    Reservation::factory()->for($office)->for($user)->create();

    $response = $this->get('/api/offices?visitor_id=' . $user->id);
    $response->assertOk();
    expect($response->json())->toBeGreaterThanOrEqual(1);
    $this->assertEquals($office->id, $response->json('data')[0]['id']);
});

it('include images tags and user', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->create(['name' => fake()->name()]);
    $office = Office::factory()->for($user)->create();

    $office->tags()->attach($tag);
    $office->images()->create(['path' => 'image.jpg']);

    $response = $this->get('/api/offices');
    expect($response->json('data')[0])->toHaveKeys(['images', 'tags', 'user']);
    $response->assertOk()
        ->assertJsonCount(1, 'data.0.tags')
        ->assertJsonCount(1, 'data.0.images');
});

it('returns the number of active reservations', function () {
    $office = Office::factory()->create();

    // the for() says that we are creating a reservation model that belongs to the office we just created.
    Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_ACTIVE]);
    Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_CANCELLED]);
    $response = $this->get('/api/offices');
    $response->assertOk();
    expect($response->json('data')[0]['reservations_count'])->toEqual(1);
});

it('Orders by distance when coordinates are provided', function () {
    $officie1 = Office::factory()->create([
        'lat' => '39.74051727562952',
        'lng' => '-8.770375324893696',
        'title' => 'Leiria'
    ]);

    $office2 = Office::factory()->create([
        'lat' => '39.07753883078113',
        'lng' => '-9.281266331143293',
        'title' => 'Torres Vedras'
    ]);

    $response = $this->get('/api/offices?lat=38.7206613846444046&lng=-9.16044783453807');

    $response->assertOk();
    $this->assertEquals('Torres Vedras', $response->json('data')[0]['title']);
    $this->assertEquals('Leiria', $response->json('data')[1]['title']);

    $response = $this->get("/api/offices");


    $this->assertEquals('Leiria', $response->json('data')[0]['title']);
    $this->assertEquals('Torres Vedras', $response->json('data')[1]['title']);
});

it('shows the office', function () {
    $user = User::factory()->create();
    $tag = Tag::factory(['name' => fake()->name()])->create();
    $office = Office::factory()->for($user)->create();


    $office->tags()->attach($tag);
    $office->images()->create(['path' => 'image.jpg']);

    Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_ACTIVE]);
    Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_CANCELLED]);

    $response = $this->get("/api/offices/" . $office->id);

    $this->assertEquals(1, $response->json('data')['reservations_count']);
    $this->assertIsArray($response->json('data')['images']);
    $this->assertCount(1, $response->json('data')['images']);
    $this->assertIsArray($response->json('data')['tags']);
    $this->assertCount(1, $response->json('data')['tags']);
    $this->assertEquals($user->id, $response->json('data')['user']['id']);
});

it('Create an office', function () {
    $user = User::factory()->createQuietly();

    $admin = User::factory()->create(['is_admin' => true]);

    Notification::fake();

    $this->actingAs($user);

    $tag = Tag::factory(['name' => fake()->name()])->create();
    $tag1 = Tag::factory(['name' => fake()->name()])->create();

    $response = $this->postJson('/api/offices', [
        'title' => 'Bonjour les gens',
        'description' => 'Description',
        'lat' => '39.74051727562952',
        'lng' => '-8.770375324893696',
        'address_line1' => 'address',
        'price_per_day' => 10_000,
        'monthly_discount' => 5,
        'tags' => [
            $tag->id,
            $tag1->id
        ],
    ]);

    expect($response->json()['data']['tags'])
        ->toBeArray()
        ->toBeGreaterThanOrEqual(2);
    expect($response->json()['data']['title'])->toBeString();

    $this->assertDatabaseHas('offices', [
        'title' => 'Bonjour les gens'
    ]);

    Notification::assertSentTo($admin, OfficePendingApprovalNotification::class);
});

it('Updates an office', function () {
    $user = User::factory()->create();
    $tags = Tag::factory(3, ["name" => fake()->name()])->create();
    $anotherTag = Tag::factory(["name" => fake()->name()])->create();
    $office = Office::factory()->for($user)->create();

    $office->tags()->attach($tags);

    $this->actingAs($user);

    $response = $this->putJson('api/offices/' . $office->id, [
        'title' => 'Amazing Office',
        'tags' => [$tags[0]->id, $anotherTag->id]
    ]);

    $response->assertOk()
        ->assertJsonCount(2, 'data.tags')
        ->assertJsonPath('data.tags.0.id', $tags[0]->id)
        ->assertJsonPath('data.tags.1.id', $anotherTag->id)
        ->assertJsonPath('data.title', 'Amazing Office');
});

it('Updates the featured image of an office', function () {
    $user = User::factory()->create();
    $office = Office::factory()->for($user)->create();
    $image = $office->images()->create([
        'path' => 'image.png'
    ]);

    $this->actingAs($user);

    $response = $this->putJson('api/offices/' . $office->id, [
        'featured_image_id' => $image->id,
    ]);

    $response->assertOk()
            ->assertJsonPath( 'data.featured_image_id' ,$image->id);
});

it('Doesn\'t updates featured image that belongs to another office', function () {
    $user = User::factory()->create();
    $office = Office::factory()->for($user)->create();
    $office2 = Office::factory()->for($user)->create();
    $image = $office2->images()->create([
        'path' => 'image.png'
    ]);

    $this->actingAs($user);

    $response = $this->putJson('api/offices/' . $office->id, [
        'featured_image_id' => $image->id,
    ]);

    $response->assertUnprocessable()->assertInvalid('featured_image_id');
});

it('Doesn\'t update an office that doesnt belong to an user', function () {
    $user = User::factory()->create();
    $anotherUser = User::factory()->create();
    $tags = Tag::factory(3, ["name" => fake()->name()])->create();
    $office = Office::factory()->for($anotherUser)->create();

    $office->tags()->attach($tags);

    $this->actingAs($user);

    $anotherTag = Tag::factory(["name" => fake()->name()])->create();

    $response = $this->putJson('api/offices/' . $office->id, [
        'title' => 'Amazing Office',
        'tags' => [$tags[0]->id, $anotherTag->id]
    ]);

    expect($response->assertStatus(Response::HTTP_FORBIDDEN));
});

it('Marks the office as pending if dirty', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $user = User::factory()->create();

    $office = Office::factory()->for($user)->create();

    Notification::fake();

    $this->actingAs($user);

    $response = $this->putJson('api/offices/' . $office->id, [
        'lat' => '48.866667',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('offices', [
        'id' => $office->id,
        'approval_status' => Office::APPROVAL_PENDING
    ]);

    Notification::assertSentTo($admin, OfficePendingApprovalNotification::class);
});

it('Can create an office only if the token is correct', function () {
    $user = User::factory()->createQuietly();

    $this->actingAs($user);

    $token = $user->createToken('office.create')->plainTextToken;

    $response = $this->postJson('/api/offices', [], [
        'Authorization' => 'Bearer' . $token
    ]);
});

it('Can delete office when logged-in', function () {
    $user = User::factory()->create();

    $office = Office::factory()->for($user)->create();

    $this->actingAs($user);

    $response = $this->delete('api/offices/' . $office->id);

    $response->assertOk();

    $this->assertSoftDeleted($office);
});

it('Cannot delete office that has reservations', function () {
    $user = User::factory()->create();

    $office = Office::factory()->for($user)->create();

    Reservation::factory(3)->for($office)->create();

    $this->actingAs($user);

    $response = $this->deleteJson('api/offices/' . $office->id);

    $response->assertUnprocessable();

    $this->assertDatabaseHas(Office::class, [
        'id' => $office->id,
        'deleted_at' => null
    ]);
});
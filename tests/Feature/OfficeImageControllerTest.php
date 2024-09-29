<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;


test('Uploads An Image And Stores It Under The Office', function () {
    Storage::fake();

    $user = User::factory()->create();
    $office = Office::factory()->for($user)->create();

    Sanctum::actingAs($user, ['*']);

    $response = $this->post("api/offices/{$office->id}/images", [
        'image' => UploadedFile::fake()->image('image.jpg')
    ]);

    $response->assertCreated();
});

it('Deletes An Image', function () {
    Storage::put('api/office_image.jpg', 'empty');

    $user = User::factory()->create();
    $office = Office::factory()->for($user)->create();

    $office->images()->create([
        'path' => 'image.jpg'
    ]);

    $image = $office->images()->create([
        'path' => 'office_image.jpg'
    ]);

    Sanctum::actingAs($user, ['*']);

    $response = $this->deleteJson("api/offices/{$office->id}/images/{$image->id}");

    $response->assertOk();

    $this->assertModelMissing($image);

    Storage::assertMissing('office_image.jpg');
});

it('Doesnt Delete Image That BelongsTo Another Resource', function () {
    $user = User::factory()->create();
    $office = Office::factory()->for($user)->create();
    $office2 = Office::factory()->for($user)->create();

    $image = $office2->images()->create([
        'path' => 'office_image.jpg'
    ]);

    Sanctum::actingAs($user, ['*']);

    $response = $this->deleteJson("api/offices/{$office->id}/images/{$image->id}");

    $response->assertNotFound();
});


it('Doesnt Delete The Only Image', function () {
    $user = User::factory()->create();
    $office = Office::factory()->for($user)->create();

    $image = $office->images()->create([
        'path' => 'office_image.jpg'
    ]);

    Sanctum::actingAs($user, ['*']);

    $response = $this->deleteJson("api/offices/{$office->id}/images/{$image->id}");

    $response->assertUnprocessable();

    $response->assertJsonValidationErrors(['image' => 'Cannot delete the only image']);
});

it('Doesnt Delete The Featured Image', function () {
    $user = User::factory()->create();
    $office = Office::factory()->for($user)->create();

    $office->images()->create([
        'path' => 'image.jpg'
    ]);

    $image = $office->images()->create([
        'path' => 'office_image.jpg'
    ]);

    $office->update(['featured_image_id' => $image->id]);

    Sanctum::actingAs($user, ['*']);

    $response = $this->deleteJson("api/offices/{$office->id}/images/{$image->id}");

    $response->assertUnprocessable();

    $response->assertJsonValidationErrors(['image' => 'Cannot delete the featured image']);
});

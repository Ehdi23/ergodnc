<?php

use App\Models\User;
use App\Models\Office;
use Illuminate\Http\UploadedFile;

use function Pest\Laravel\actingAs;
use Illuminate\Support\Facades\Storage;

test('Image creation test', function () {

    Storage::fake('public');

    $user = User::factory()->create();

    $office = Office::factory()->for($user)->create();

    $this->actingAs($user);

    $response = $this->post("api/offices/{$office->id}/images", [
        'image' => \Illuminate\Http\UploadedFile::fake()->image('image.jpg')
    ]);

    $response->assertCreated();

    \Illuminate\Support\Facades\File::exists(
        $response->json('data.path')
    );
});

test('Delete an image', function () {
    // Création et stockage de l'image
    Storage::fake('public');

    // Crée un fichier d'exemple dans le disque 'public'
    Storage::disk('public')->put('/office_image.jpg', 'empty');
    
    // Simule une image téléchargée
    $fakeImage = UploadedFile::fake()->image('office-image.jpg');

    $user = User::factory()->create();

    // Crée un office associé à l'utilisateur
    $office = Office::factory()->for($user)->create();

    // Ajoute une image associée à l'office
    $image = $office->images()->create([
        'path' => 'office_image.jpg',
    ]);

    // Ajoute une deuxième image pour éviter le blocage lors de la suppression de la seule image
    $office->images()->create([
        'path' => 'image.jpg',
    ]);

    // Associe l'utilisateur avec les permissions nécessaires et fait agir comme lui
    $this->actingAs($user);

    // Supprime l'image via une requête HTTP JSON
    $response = $this->deleteJson("api/offices/{$office->id}/images/{$image->id}");

    // Vérifie la réponse
    $response->assertStatus(200);

    // Vérifie que l'image a bien été supprimée
    $this->assertModelMissing($image);

    // Vérifie que l'image n'est plus présente dans le stockage public
    Storage::disk('public')->assertMissing('office_image.jpg');
});

test('Doesn\'t delete an image', function () {
    $user = User::factory()->create();
    $office = Office::factory()->for($user)->create();

    // Ajoute une image associée à l'office
    $image = $office->images()->create([
        'path' => 'office_image.jpg',
    ]);

    $this->actingAs($user);

    $response = $this->deleteJson("api/offices/{$office->id}/images/{$image->id}");

    // Vérifie la réponse
    $response->assertUnprocessable();

    $response->assertJsonValidationErrors(['image' => 'Cannot delete the only image']);
});

test('Doesn\'t delete image that belongs to another resource', function () {
    $user = User::factory()->create();
    $office = Office::factory()->for($user)->create();
    $office2 = Office::factory()->for($user)->create();

    // Ajoute une image associée à l'office
    $image = $office2->images()->create([
        'path' => 'office_image.jpg',
    ]);

    $this->actingAs($user);

    $response = $this->deleteJson("api/offices/{$office->id}/images/{$image->id}");

    // Vérifie la réponse
    $response->assertUnprocessable();

    $response->assertJsonValidationErrors(['image' => 'Cannot delete this image.']);
});

test('Doesn\'t delete the featured image', function () {
    $user = User::factory()->create();
    $office = Office::factory()->for($user)->create();

    // Ajoute une image associée à l'office
    $image = $office->images()->create([
        'path' => 'office_image.jpg',
    ]);

    $office->images()->create([
        'path' => 'image.jpg',
    ]);

    $office->update(['featured_image_id' => $image->id]);

    $this->actingAs($user);

    $response = $this->deleteJson("api/offices/{$office->id}/images/{$image->id}");

    // Vérifie la réponse
    $response->assertUnprocessable();

    $response->assertJsonValidationErrors(['image' => 'Cannot delete the featured image']);
});


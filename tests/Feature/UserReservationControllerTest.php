<?php

use App\Models\User;
use App\Models\Office;
use App\Models\Reservation;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use App\Notifications\NewHostReservation;
use App\Notifications\NewUserReservation;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;



it('Lists Reservations That Belong To The User', function () {
    $user = User::factory()->create();

    [$reservation] = Reservation::factory()->for($user)->count(2)->create();

    $image = $reservation->office->images()->create([
        'path' => 'office_image.jpg'
    ]);

    $reservation->office()->update(['featured_image_id' => $image->id]);

    Reservation::factory()->count(3)->create();

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('api/reservations');

    $response
        ->assertJsonStructure(['data', 'meta', 'links'])
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => ['*' => ['id', 'office']]])
        ->assertJsonPath('data.0.office.featured_image.id', $image->id);
});

/**
 * @test
 */
it('Lists Reservation Filtered By Date Range', function () {
    $user = User::factory()->create();

    $fromDate = '2021-03-03';
    $toDate = '2021-04-04';

    // Within the date range
    // ...
    $reservations = Reservation::factory()->for($user)->createMany([
        [
            'start_date' => '2021-03-01',
            'end_date' => '2021-03-15',
        ],
        [
            'start_date' => '2021-03-25',
            'end_date' => '2021-04-15',
        ],
        [
            'start_date' => '2021-03-25',
            'end_date' => '2021-03-29',
        ],
        [
            'start_date' => '2021-03-01',
            'end_date' => '2021-04-15',
        ],
    ]);

    // Within the range but belongs to a different user
    // ...
    Reservation::factory()->create([
        'start_date' => '2021-03-25',
        'end_date' => '2021-03-29',
    ]);

    // Outside the date range
    // ...
    Reservation::factory()->for($user)->create([
        'start_date' => '2021-02-25',
        'end_date' => '2021-03-01',
    ]);

    Reservation::factory()->for($user)->create([
        'start_date' => '2021-05-01',
        'end_date' => '2021-05-01',
    ]);

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('api/reservations?' . http_build_query([
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ]));

    $response
        ->assertJsonCount(4, 'data');

    $this->assertEquals($reservations->pluck('id')->toArray(), collect($response->json('data'))->pluck('id')->toArray());
});

/**
 * @test
 */
it('Filters Results By Status', function () {
    $user = User::factory()->create();

    $reservation = Reservation::factory()->for($user)->create([
        'status' => Reservation::STATUS_ACTIVE
    ]);

    $reservation2 = Reservation::factory()->for($user)->cancelled()->create();

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('api/reservations?' . http_build_query([
        'status' => Reservation::STATUS_ACTIVE,
    ]));

    $response
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reservation->id);
});

/**
 * @test
 */
it('Filters Results By Office', function () {
    $user = User::factory()->create();

    $office = Office::factory()->create();

    $reservation = Reservation::factory()->for($office)->for($user)->create();

    $reservation2 = Reservation::factory()->for($user)->create();

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('api/reservations?' . http_build_query([
        'office_id' => $office->id,
    ]));

    $response
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reservation->id);
});

it('Makes Reservations', function () {
    $user = User::factory()->create();

    $office = Office::factory()->create([
        'price_per_day' => 1000,
        'monthly_discount' => 10,
    ]);

    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('api/reservations', [
        'office_id' => $office->id,
        'start_date' => now()->addDay(),
        'end_date' => now()->addDays(40),
    ]);

    $response->assertCreated();

    $response->assertJsonPath('data.price', 36000)
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.office_id', $office->id)
        ->assertJsonPath('data.status', Reservation::STATUS_ACTIVE);
});

it('Cannot Make Reservation On Non Existing Office', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('api/reservations', [
        'office_id' => 10000,
        'start_date' => now()->addDay(),
        'end_date' => now()->addDays(41),
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['office_id' => 'Invalid office_id']);
});

it('Cannot Make Reservation On Office That Belongs To The User', function () {
    $user = User::factory()->create();

    $office = Office::factory()->for($user)->create();

    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('api/reservations', [
        'office_id' => $office->id,
        'start_date' => now()->addDay(),
        'end_date' => now()->addDays(41),
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['office_id' => 'You cannot make a reservation on your own office']);
});

it('Cannot Make Reservation On Office That Is Pending Or Hidden', function ()
{
    $user = User::factory()->create();

    $office = Office::factory()->create([
        'approval_status' => Office::APPROVAL_PENDING
    ]);

    $office2 = Office::factory()->create([
        'hidden' => true
    ]);

    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('api/reservations', [
        'office_id' => $office->id,
        'start_date' => now()->addDay(),
        'end_date' => now()->addDays(41),
    ]);

    $response2 = $this->postJson('api/reservations', [
        'office_id' => $office2->id,
        'start_date' => now()->addDay(),
        'end_date' => now()->addDays(41),
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['office_id' => 'You cannot make a reservation on a hidden office']);

    $response2->assertUnprocessable()
        ->assertJsonValidationErrors(['office_id' => 'You cannot make a reservation on a hidden office']);
});

it('Cannot Make Reservation Less Than 2 Days', function ()
{
    $user = User::factory()->create();

    $office = Office::factory()->create();

    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('api/reservations', [
        'office_id' => $office->id,
        'start_date' => now()->addDay(),
        'end_date' => now()->addDay(),
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['end_date' => 'The end date field must be a date after start date.']);
});

it('Cannot Make Reservation On SameDay', function ()
{
    $user = User::factory()->create();

    $office = Office::factory()->create();

    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('api/reservations', [
        'office_id' => $office->id,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['start_date' => 'The start date field must be a date after today.']);
});

it('Make Reservation For 2 Days', function ()
{
    $user = User::factory()->create();

    $office = Office::factory()->create();

    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('api/reservations', [
        'office_id' => $office->id,
        'start_date' => now()->addDay(),
        'end_date' => now()->addDays(2),
    ]);

    $response->assertCreated();
});

it('Cannot Make Reservation Thats Conflicting', function ()
{
    $user = User::factory()->create();

    $fromDate = now()->addDays(2)->toDateString();
    $toDate = now()->addDay(15)->toDateString();

    $office = Office::factory()->create();

    Reservation::factory()->for($office)->create([
        'start_date' => now()->addDay(2),
        'end_date' => $toDate,
    ]);

    Sanctum::actingAs($user, ['*']);

    dump(DB::table('reservations')->get());

    $response = $this->postJson('api/reservations', [
        'office_id' => $office->id,
        'start_date' => $fromDate,
        'end_date' => $toDate,
    ]);

    // dd(DB::getQueryLog());

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['office_id' => 'You cannot make a reservation during this time']);
});

it('Sends Notifications On New Reservations', function ()
{
    Notification::fake();

    $user = User::factory()->create();

    $office = Office::factory()->create();

    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('api/reservations', [
        'office_id' => $office->id,
        'start_date' => now()->addDay(),
        'end_date' => now()->addDays(2),
    ]);

    Notification::assertSentTo($user, NewUserReservation::class);
    Notification::assertSentTo($office->user, NewHostReservation::class);

    $response->assertCreated();
});
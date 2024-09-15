<?php

use App\Models\Office;

it('Returning count of 3 offices', function () {
    Office::factory(3)->create();

    $response = $this->get('api/offices');

    expect($response->assertJsonCount(3, 'data'));
});

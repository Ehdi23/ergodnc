<?php

use function PHPUnit\Framework\assertNotNull;

test('gives us 3 tags', function () {
    $response = $this->get('api/tags');
    
    expect($response->json('data'))
    ->toBeArray()
    ->toHaveCount(3);
});

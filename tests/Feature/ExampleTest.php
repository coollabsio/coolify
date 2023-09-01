<?php

it('returns a successful response', function () {
    $response = $this->get('/api/health');

    $response->assertStatus(200);
});

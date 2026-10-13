<?php

describe('trafficCityRows', function () {
    it('builds labels, keeps coordinates, and sorts by requests', function () {
        $rows = trafficCityRows([
            ['value' => 'SG||Singapore', 'requests' => 3, 'bytesOut' => 30, 'lat' => 1.29, 'lon' => 103.85],
            ['value' => 'DE|BE|Berlin', 'requests' => 9, 'bytesOut' => 900, 'lat' => 52.52, 'lon' => 13.405],
        ]);

        expect($rows)->toBe([
            ['key' => 'DE|BE|Berlin', 'country' => 'DE', 'label' => 'Berlin, BE, DE', 'name' => 'Berlin', 'detail' => 'BE, Germany', 'requests' => 9, 'bytesOut' => 900, 'lat' => 52.52, 'lon' => 13.405],
            ['key' => 'SG||Singapore', 'country' => 'SG', 'label' => 'Singapore, SG', 'name' => 'Singapore', 'detail' => 'Singapore', 'requests' => 3, 'bytesOut' => 30, 'lat' => 1.29, 'lon' => 103.85],
        ]);
    });

    it('omits a subdivision that is not a letter code', function (string $value, string $label, string $detail) {
        $rows = trafficCityRows([
            ['value' => $value, 'requests' => 1, 'bytesOut' => 1],
        ]);

        expect($rows[0]['label'])->toBe($label)
            ->and($rows[0]['detail'])->toBe($detail);
    })->with([
        'a name' => ['DE|Land Berlin|Berlin', 'Berlin, DE', 'Germany'],
        'a number' => ['AT|3|Neunkirchen', 'Neunkirchen, AT', 'Austria'],
        'one letter' => ['SE|O|Lidköping', 'Lidköping, SE', 'Sweden'],
    ]);

    it('labels an unknown city with its country and gives it no coordinates', function () {
        $rows = trafficCityRows([
            ['value' => 'DE|BE|Berlin', 'requests' => 9, 'bytesOut' => 900, 'lat' => 52.52, 'lon' => 13.405],
            ['value' => 'DE||', 'requests' => 2, 'bytesOut' => 20, 'lat' => 51.0, 'lon' => 9.0],
        ]);

        expect($rows[1])->toBe([
            'key' => 'DE||', 'country' => 'DE', 'label' => 'Unknown, DE', 'name' => 'Unknown', 'detail' => 'Germany', 'requests' => 2, 'bytesOut' => 20, 'lat' => null, 'lon' => null,
        ]);
    });

    it('merges the remainder and malformed values into one Other row', function () {
        $rows = trafficCityRows([
            ['value' => 'DE|BE|Berlin', 'requests' => 9, 'bytesOut' => 900],
            ['value' => '__other__', 'requests' => 40, 'bytesOut' => 40],
            ['value' => 'Berlin', 'requests' => 1, 'bytesOut' => 10],
            ['value' => 'DE|Berlin', 'requests' => 1, 'bytesOut' => 10],
            ['value' => '', 'requests' => 1, 'bytesOut' => 10],
        ]);

        // "Other" has the most requests here and still stays last.
        expect($rows)->toHaveCount(2)
            ->and($rows[1])->toBe([
                'key' => '__other__', 'country' => '', 'label' => 'Other', 'name' => 'Other', 'detail' => '', 'requests' => 43, 'bytesOut' => 70, 'lat' => null, 'lon' => null,
            ]);
    });

    it('keeps a city name that contains quotes or HTML unchanged', function () {
        $rows = trafficCityRows([
            ['value' => "US|MO|O'Fallon <b>", 'requests' => 1, 'bytesOut' => 1],
        ]);

        expect($rows[0]['name'])->toBe("O'Fallon <b>")
            ->and($rows[0]['label'])->toBe("O'Fallon <b>, MO, US");
    });

    it('accepts a lower-case country code', function () {
        $rows = trafficCityRows([
            ['value' => 'de|BE|Berlin', 'requests' => 1, 'bytesOut' => 1],
        ]);

        expect($rows[0]['country'])->toBe('DE')
            ->and($rows[0]['label'])->toBe('Berlin, BE, DE');
    });

    it('skips rows with no requests', function () {
        $rows = trafficCityRows([
            ['value' => 'DE|BE|Berlin', 'requests' => 9, 'bytesOut' => 900],
            ['value' => 'FR|IDF|Paris', 'requests' => 0, 'bytesOut' => 0],
        ]);

        expect($rows)->toHaveCount(1);
    });

    it('returns nothing when no row has a real city', function (array $input) {
        expect(trafficCityRows($input))->toBe([]);
    })->with([
        'old sentinel' => [[]],
        'country-only database' => [[
            ['value' => 'DE||', 'requests' => 5, 'bytesOut' => 50],
            ['value' => 'US||', 'requests' => 3, 'bytesOut' => 30],
        ]],
        'only the remainder' => [[
            ['value' => '__other__', 'requests' => 5, 'bytesOut' => 50],
        ]],
    ]);
});

describe('trafficGeoMarkers', function () {
    it('returns country markers and city markers as two sets', function () {
        $markers = trafficGeoMarkers(
            [
                ['value' => 'DE', 'requests' => 12],
                ['value' => 'us', 'requests' => 7],
                ['value' => '', 'requests' => 3],
                ['value' => 'FR', 'requests' => 0],
            ],
            [
                ['value' => 'DE|BE|Berlin', 'requests' => 9, 'lat' => 52.52, 'lon' => 13.405],
                ['value' => 'DE||', 'requests' => 3],
                ['value' => 'FR|IDF|Paris', 'requests' => 0, 'lat' => 48.85, 'lon' => 2.35],
            ],
        );

        expect($markers)->toBe([
            'countries' => [
                ['code' => 'DE', 'requests' => 12],
                ['code' => 'US', 'requests' => 7],
            ],
            'cities' => [
                ['lat' => 52.52, 'lon' => 13.405, 'requests' => 9],
            ],
        ]);
    });

    it('has no city markers when Sentinel sends no city data', function () {
        expect(trafficGeoMarkers([['value' => 'DE', 'requests' => 12]], [])['cities'])->toBe([]);
    });

    it('accepts the rows that trafficCityRows returns', function () {
        $rows = trafficCityRows([
            ['value' => 'DE|BE|Berlin', 'requests' => 9, 'bytesOut' => 900, 'lat' => 52.52, 'lon' => 13.405],
        ]);

        expect(trafficGeoMarkers([], $rows)['cities'])->toBe([
            ['lat' => 52.52, 'lon' => 13.405, 'requests' => 9],
        ]);
    });
});

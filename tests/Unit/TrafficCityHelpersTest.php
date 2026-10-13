<?php

describe('trafficCityRows', function () {
    it('builds labels, keeps coordinates, and sorts by requests', function () {
        $rows = trafficCityRows([
            ['value' => 'SG||Singapore', 'requests' => 3, 'bytesOut' => 30, 'lat' => 1.29, 'lon' => 103.85],
            ['value' => 'DE|BE|Berlin', 'requests' => 9, 'bytesOut' => 900, 'lat' => 52.52, 'lon' => 13.405],
        ]);

        expect($rows)->toBe([
            ['key' => 'DE|BE|Berlin', 'country' => 'DE', 'label' => 'Berlin, BE, DE', 'requests' => 9, 'bytesOut' => 900, 'lat' => 52.52, 'lon' => 13.405],
            ['key' => 'SG||Singapore', 'country' => 'SG', 'label' => 'Singapore, SG', 'requests' => 3, 'bytesOut' => 30, 'lat' => 1.29, 'lon' => 103.85],
        ]);
    });

    it('omits a subdivision that is not a letter code', function (string $value, string $label) {
        $rows = trafficCityRows([
            ['value' => $value, 'requests' => 1, 'bytesOut' => 1],
        ]);

        expect($rows[0]['label'])->toBe($label);
    })->with([
        'a name' => ['DE|Land Berlin|Berlin', 'Berlin, DE'],
        'a number' => ['AT|3|Neunkirchen', 'Neunkirchen, AT'],
        'one letter' => ['SE|O|Lidköping', 'Lidköping, SE'],
    ]);

    it('labels an unknown city with its country and gives it no coordinates', function () {
        $rows = trafficCityRows([
            ['value' => 'DE|BE|Berlin', 'requests' => 9, 'bytesOut' => 900, 'lat' => 52.52, 'lon' => 13.405],
            ['value' => 'DE||', 'requests' => 2, 'bytesOut' => 20, 'lat' => 51.0, 'lon' => 9.0],
        ]);

        expect($rows[1])->toBe([
            'key' => 'DE||', 'country' => 'DE', 'label' => 'Unknown, DE', 'requests' => 2, 'bytesOut' => 20, 'lat' => null, 'lon' => null,
        ]);
    });

    it('merges the remainder and malformed values into one Other row', function () {
        $rows = trafficCityRows([
            ['value' => 'DE|BE|Berlin', 'requests' => 9, 'bytesOut' => 900],
            ['value' => '__other__', 'requests' => 4, 'bytesOut' => 40],
            ['value' => 'Berlin', 'requests' => 1, 'bytesOut' => 10],
            ['value' => 'DE|Berlin', 'requests' => 1, 'bytesOut' => 10],
            ['value' => '', 'requests' => 1, 'bytesOut' => 10],
            ['value' => 'deu|BE|Berlin', 'requests' => 1, 'bytesOut' => 10],
        ]);

        expect($rows)->toHaveCount(2)
            ->and($rows[1])->toBe([
                'key' => '__other__', 'country' => '', 'label' => 'Other', 'requests' => 8, 'bytesOut' => 80, 'lat' => null, 'lon' => null,
            ]);
    });

    it('keeps a pipe-free city name that contains quotes or HTML unchanged', function () {
        $rows = trafficCityRows([
            ['value' => "US|MO|O'Fallon <b>", 'requests' => 1, 'bytesOut' => 1],
        ]);

        expect($rows[0]['label'])->toBe("O'Fallon <b>, MO, US");
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
    $countries = [
        ['value' => 'DE', 'requests' => 12],
        ['value' => 'us', 'requests' => 7],
        ['value' => '', 'requests' => 3],
        ['value' => 'FR', 'requests' => 0],
    ];

    it('uses city markers when a city row has coordinates', function () use ($countries) {
        $markers = trafficGeoMarkers($countries, [
            ['value' => 'DE|BE|Berlin', 'requests' => 9, 'lat' => 52.52, 'lon' => 13.405],
            ['value' => 'DE||', 'requests' => 3],
            ['value' => 'FR|IDF|Paris', 'requests' => 0, 'lat' => 48.85, 'lon' => 2.35],
        ]);

        expect($markers)->toBe([
            ['lat' => 52.52, 'lon' => 13.405, 'requests' => 9],
        ]);
    });

    it('uses country markers when no city row has coordinates', function (array $cities) use ($countries) {
        expect(trafficGeoMarkers($countries, $cities))->toBe([
            ['code' => 'DE', 'requests' => 12],
            ['code' => 'US', 'requests' => 7],
        ]);
    })->with([
        'old sentinel' => [[]],
        'country-only database' => [[['value' => 'DE||', 'requests' => 12]]],
    ]);

    it('accepts the rows that trafficCityRows returns', function () {
        $rows = trafficCityRows([
            ['value' => 'DE|BE|Berlin', 'requests' => 9, 'bytesOut' => 900, 'lat' => 52.52, 'lon' => 13.405],
        ]);

        expect(trafficGeoMarkers([], $rows))->toBe([
            ['lat' => 52.52, 'lon' => 13.405, 'requests' => 9],
        ]);
    });
});

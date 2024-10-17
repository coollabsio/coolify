# How to use Laravel Dusk in local development

## Pre-requisites

- Google Chrome installed on your machine (for the Chrome driver)
- everything else is already set up in the project


## Running Dusk in local development

In order to use Laravel Dusk in local development, you need to run these commands:

```bash
docker exec -it coolify php artisan dusk:chrome-driver --detect
```

The chrome driver will be installed under `./vendor/laravel/dusk/bin/chromedriver-linux`.

Then you need to run the chrome-driver by hand. You can find the driver in the following path:
```bash
docker exec -it coolify ./vendor/laravel/dusk/bin/chromedriver-linux --port=9515 
```

### Running the tests on Apple Silicon

If you are using an Apple Silicon machine, you need to install the Chrome driver locally on your machine with the following command:

```bash
php artisan dusk:chrome-driver --detect
# then run it with the following command
./vendor/laravel/dusk/bin/chromedriver-mac-arm --port=9515                                                                                                                                    130 ↵
```

### Running the tests

Finally, you can run the tests with the following command:
```bash
docker exec -it coolify php artisan dusk
```

That's it. You should see the tests running in the terminal.
For proof, you can check the screenshot in the `tests/Browser/screenshots` folder.

```

   PASS  Tests\Browser\LoginTest
  ✓ login                                                                                                                                                                                         3.63s  

  Tests:    1 passed (1 assertions)
  Duration: 3.79s


```
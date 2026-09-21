<?php
require __DIR__.'/../app/I18n/UiTranslator.php';
$dict = json_decode(file_get_contents(__DIR__.'/../resources/i18n/tr-ui.json'), true, 512, JSON_THROW_ON_ERROR);
$tr = new App\I18n\UiTranslator($dict);
$in = file_get_contents($argv[1]);
$out = $tr->translate($in);
file_put_contents($argv[2], $out);
echo "IN ".strlen($in)." OUT ".strlen($out)."\n";

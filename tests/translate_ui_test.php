<?php

require __DIR__ . '/../app/I18n/UiTranslator.php';

use App\I18n\UiTranslator;

$passed = 0;
$failed = 0;
$total = 0;

function expect_eq(string $name, string $expected, string $actual): void
{
    global $passed, $failed, $total;
    $total++;
    if ($expected === $actual) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
    echo "  expected: " . var_export($expected, true) . "\n";
    echo "  actual:   " . var_export($actual, true) . "\n";
}

function expect_true(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed, $total;
    $total++;
    if ($cond) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$dict = [
    'Save' => 'Kaydet',
    'Deploy' => 'Dağıt',
    'Settings' => 'Ayarlar',
    'Status' => 'Durum',
    'Save & Exit' => 'Kaydet ve Çık',
    'Are you sure?' => 'Emin misiniz?',
];

$jsonPath = __DIR__ . '/../resources/i18n/tr-ui.json';
if (is_file($jsonPath)) {
    $fileDict = json_decode((string) file_get_contents($jsonPath), true);
    if (is_array($fileDict)) {
        $dict = array_merge($fileDict, $dict);
    }
}

$tr = new UiTranslator($dict);

expect_eq(
    'plain text translation',
    '<button>Kaydet</button>',
    $tr->translate('<button>Save</button>')
);

expect_eq(
    'whitespace preserved',
    '<button> Kaydet </button>',
    $tr->translate('<button> Save </button>')
);

$scriptHtml = '<p>Save</p><script>var label = "Save";</script><span>Deploy</span>';
$scriptOut = $tr->translate($scriptHtml);
expect_true(
    'script inner unchanged',
    str_contains($scriptOut, '<script>var label = "Save";</script>')
        && str_contains($scriptOut, '<p>Kaydet</p>')
        && str_contains($scriptOut, '<span>Dağıt</span>'),
    $scriptOut
);

expect_eq(
    'code inner unchanged',
    '<p>Kaydet</p><code>Save</code>',
    $tr->translate('<p>Save</p><code>Save</code>')
);

expect_eq(
    'comment inner unchanged',
    '<p>Kaydet</p><!-- Save -->',
    $tr->translate('<p>Save</p><!-- Save -->')
);

expect_eq(
    'placeholder translation',
    '<input placeholder="Kaydet">',
    $tr->translate('<input placeholder="Save">')
);

expect_eq(
    'title translation',
    '<a title="Ayarlar">x</a>',
    $tr->translate('<a title="Settings">x</a>')
);

expect_eq(
    'unknown text unchanged',
    '<p>HelloWorldNotInDict</p>',
    $tr->translate('<p>HelloWorldNotInDict</p>')
);

expect_eq(
    'multiline text',
    "<p>\nKaydet\n</p>",
    $tr->translate("<p>\nSave\n</p>")
);

expect_eq(
    'amp entity text',
    '<p>Kaydet ve Çık</p>',
    $tr->translate('<p>Save &amp; Exit</p>')
);

expect_eq(
    'wire:confirm translation',
    '<button wire:confirm="Emin misiniz?">Kaydet</button>',
    $tr->translate('<button wire:confirm="Are you sure?">Save</button>')
);

expect_eq(
    'real dictionary Actions',
    '<span>İşlemler</span>',
    $tr->translate('<span>Actions</span>')
);

expect_eq(
    'empty and single char untouched',
    '<p> </p><i>.</i>',
    $tr->translate('<p> </p><i>.</i>')
);

$trLive = new UiTranslator(['Save' => 'Kaydet']);

expect_eq(
    'livewire json html translated snapshot unchanged',
    '{"components":[{"effects":{"html":"<div>Kaydet</div>"},"snapshot":"Save should stay"}]}',
    $trLive->translateLivewireJson(
        '{"components":[{"effects":{"html":"<div>Save</div>"},"snapshot":"Save should stay"}]}'
    )
);

expect_eq(
    'livewire json without components unchanged',
    '{"ok":true,"html":"<div>Save</div>"}',
    $trLive->translateLivewireJson('{"ok":true,"html":"<div>Save</div>"}')
);

expect_eq(
    'livewire broken json unchanged',
    '{not json',
    $trLive->translateLivewireJson('{not json')
);

$lwScript = '{"components":[{"effects":{"html":"<p>Save</p><script>var label = \"Save\";</script>"}}]}';
$lwScriptOut = $trLive->translateLivewireJson($lwScript);
$lwHtml = json_decode($lwScriptOut, true)['components'][0]['effects']['html'] ?? '';
expect_true(
    'livewire json script block preserved',
    str_contains($lwHtml, '<script>var label = "Save";</script>')
        && str_contains($lwHtml, '<p>Kaydet</p>'),
    $lwScriptOut
);

expect_eq(
    'pattern hours ago',
    '<span>13 saat önce</span>',
    $trLive->translate('<span>13 hours ago</span>')
);

expect_eq(
    'pattern 1 hour ago',
    '<span>1 saat önce</span>',
    $trLive->translate('<span>1 hour ago</span>')
);

expect_eq(
    'pattern resources',
    '<span>6 kaynak</span>',
    $trLive->translate('<span>6 resources</span>')
);

expect_eq(
    'pattern 1 env',
    '<span>1 ortam</span>',
    $trLive->translate('<span>1 env</span>')
);

expect_eq(
    'pattern en-dash of',
    '<span>1–3 / 25</span>',
    $trLive->translate('<span>1–3 of 25</span>')
);

expect_eq(
    'pattern hyphen of',
    '<span>2–5 / 10</span>',
    $trLive->translate('<span>2-5 of 10</span>')
);

$trDictFirst = new UiTranslator(['13 hours ago' => 'özel çeviri']);
expect_eq(
    'dictionary before pattern',
    '<span>özel çeviri</span>',
    $trDictFirst->translate('<span>13 hours ago</span>')
);

expect_eq(
    'script block pattern unchanged',
    '<p>Kaydet</p><script>13 hours ago</script>',
    $trLive->translate('<p>Save</p><script>13 hours ago</script>')
);

echo ($failed === 0)
    ? "TESTS PASSED {$passed}/{$total}\n"
    : "TESTS FAILED {$passed}/{$total}\n";

exit($failed === 0 ? 0 : 1);

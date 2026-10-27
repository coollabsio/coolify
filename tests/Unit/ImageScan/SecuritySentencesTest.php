<?php

use App\Enums\SecurityActionType;
use App\Enums\SecurityStatusType;
use App\Services\ImageScan\ResourceSecurity;
use App\Services\ImageScan\SecurityAction;
use App\Services\ImageScan\SecurityActions;
use App\Services\ImageScan\SecuritySentences;
use Carbon\CarbonImmutable;

function sentenceAction(SecurityActionType $type = SecurityActionType::UpdateImage, ?string $sentence = null, ?string $tag = '16.4-alpine'): SecurityAction
{
    return new SecurityAction(
        type: $type,
        sentence: $sentence ?? "Update to {$tag}.",
        target: $tag,
        copy: null,
        fixedIds: [],
        button: true,
        image: 'postgres:16.1-alpine',
        ref: "postgres:{$tag}",
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function sentenceSecurity(SecurityStatusType $status, array $overrides = []): ResourceSecurity
{
    return new ResourceSecurity(...[...$overrides, 'status' => $status]);
}

function sentenceUpdateNow(array $overrides = []): ResourceSecurity
{
    return sentenceSecurity(SecurityStatusType::UpdateNow, [
        'actions' => [sentenceAction()], 'problemCount' => 1, 'fixedByPrimary' => 1, 'exploitedCount' => 1, 'anyExploited' => true,
        ...$overrides,
    ]);
}

function sentenceUpdateAvailable(array $overrides = []): ResourceSecurity
{
    return sentenceSecurity(SecurityStatusType::UpdateAvailable, [
        'actions' => [sentenceAction()], 'problemCount' => 3, 'criticalCount' => 1, 'highCount' => 2, 'fixedByPrimary' => 3,
        'primaryCritical' => 1, 'primaryHigh' => 2,
        ...$overrides,
    ]);
}

it('writes the update_now headline for one problem that attackers are using', function () {
    expect(SecuritySentences::headline(sentenceUpdateNow()))
        ->toBe('This image has a security problem that attackers are using. Update to 16.4-alpine to fix it.');
});

it('writes the update_now headline for many problems', function () {
    $security = sentenceUpdateNow(['problemCount' => 3, 'fixedByPrimary' => 3, 'exploitedCount' => 3]);

    expect(SecuritySentences::headline($security))
        ->toBe('This image has 3 security problems that attackers are using. Update to 16.4-alpine to fix them.');
});

it('says "to fix {k} of them" when the action fixes only some', function () {
    $security = sentenceUpdateNow(['problemCount' => 3, 'fixedByPrimary' => 2, 'exploitedCount' => 3]);

    expect(SecuritySentences::headline($security))
        ->toBe('This image has 3 security problems that attackers are using. Update to 16.4-alpine to fix 2 of them.');
});

it('uses the likely wording when no finding is exploited', function () {
    $security = sentenceUpdateNow(['problemCount' => 2, 'fixedByPrimary' => 2, 'exploitedCount' => 2, 'anyExploited' => false]);

    expect(SecuritySentences::headline($security))
        ->toBe('This image has 2 security problems that attackers are likely to use. Update to 16.4-alpine to fix them.');
});

it('adds nothing to the sentence of an action other than update_image', function () {
    $pull = sentenceAction(SecurityActionType::PullLatest, 'A newer build of this image fixes it. Pull it and restart.');
    $rebuild = sentenceAction(SecurityActionType::Rebuild, 'Rebuild without cache to get the fixed system packages.');

    expect(SecuritySentences::headline(sentenceUpdateNow(['actions' => [$pull]])))
        ->toBe('This image has a security problem that attackers are using. A newer build of this image fixes it. Pull it and restart.')
        ->and(SecuritySentences::headline(sentenceUpdateNow(['actions' => [$rebuild]])))
        ->toBe('This image has a security problem that attackers are using. Rebuild without cache to get the fixed system packages.');
});

it('does not append "to fix" to an update_image sentence that is not "Update to {tag}."', function () {
    $coolify = sentenceAction(SecurityActionType::UpdateImage, SecurityActions::COOLIFY_TEXT);
    $compose = sentenceAction(SecurityActionType::UpdateImage, 'Change the version to 2.10.4 in the compose file of this app, then redeploy.');

    expect(SecuritySentences::headline(sentenceUpdateNow(['actions' => [$coolify]])))->toEndWith(' Update Coolify to get a newer version.')
        ->and(SecuritySentences::headline(sentenceUpdateNow(['actions' => [$compose]])))->toEndWith('compose file of this app, then redeploy.');
});

it('appends "to fix" to a merged service update sentence', function () {
    $merged = sentenceAction(SecurityActionType::UpdateImage, 'Update n8n and n8n runners to 2.10.4.');

    expect(SecuritySentences::headline(sentenceUpdateNow(['actions' => [$merged], 'problemCount' => 2, 'fixedByPrimary' => 2])))
        ->toEndWith('Update n8n and n8n runners to 2.10.4 to fix them.');
});

it('writes the update_available headline', function () {
    expect(SecuritySentences::headline(sentenceUpdateAvailable()))
        ->toBe('Update to 16.4-alpine. This fixes 3 serious problems (1 critical, 2 high). Attackers are not known to use them.');
});

it('writes update_available for one problem and leaves out a zero part', function () {
    $one = sentenceUpdateAvailable(['fixedByPrimary' => 1, 'primaryCritical' => 0, 'primaryHigh' => 1]);
    $criticalOnly = sentenceUpdateAvailable(['fixedByPrimary' => 2, 'primaryCritical' => 2, 'primaryHigh' => 0]);

    expect(SecuritySentences::headline($one))
        ->toBe('Update to 16.4-alpine. This fixes 1 serious problem (1 high). Attackers are not known to use it.')
        ->and(SecuritySentences::headline($criticalOnly))
        ->toBe('Update to 16.4-alpine. This fixes 2 serious problems (2 critical). Attackers are not known to use them.');
});

it('leaves out "Attackers are not known to use" when a problem that attackers use has no fix', function () {
    $security = sentenceUpdateAvailable(['exploitedWithoutFix' => true, 'exploitedCount' => 1]);

    expect(SecuritySentences::headline($security))
        ->toBe('Update to 16.4-alpine. This fixes 3 serious problems (1 critical, 2 high).');
});

it('writes the exploited_without_fix extra line for update_now and update_available', function () {
    expect(SecuritySentences::lines(sentenceUpdateNow(['exploitedWithoutFix' => true, 'exploitedCount' => 2])))
        ->toBe(['One more problem that attackers are using has no fix yet.'])
        ->and(SecuritySentences::lines(sentenceUpdateNow(['exploitedWithoutFix' => true, 'exploitedCount' => 4])))
        ->toBe(['3 more problems that attackers are using have no fix yet.'])
        ->and(SecuritySentences::lines(sentenceUpdateAvailable(['exploitedWithoutFix' => true, 'exploitedCount' => 1])))
        ->toBe(['One more problem that attackers are using has no fix yet.'])
        ->and(SecuritySentences::lines(sentenceUpdateAvailable(['exploitedWithoutFix' => true, 'exploitedCount' => 2])))
        ->toBe(['2 more problems that attackers are using have no fix yet.']);
});

it('writes no extra line without the exploited_without_fix flag or for other statuses', function () {
    expect(SecuritySentences::lines(sentenceUpdateNow()))->toBe([])
        ->and(SecuritySentences::lines(sentenceSecurity(SecurityStatusType::NoFix, ['exploitedWithoutFix' => true, 'exploitedCount' => 1])))->toBe([])
        ->and(SecuritySentences::lines(sentenceSecurity(SecurityStatusType::Ok)))->toBe([]);
});

it('writes the no_fix headline', function () {
    $tail = ', and no fixed version exists yet. Coolify checks every day and tells you when a fix is out. Until then, limit who can reach this app, or ignore the problem if the affected part is not used.';

    expect(SecuritySentences::headline(sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 1, 'anyExploited' => true])))
        ->toBe('This image has a security problem that attackers are using'.$tail)
        ->and(SecuritySentences::headline(sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 2, 'anyExploited' => true])))
        ->toBe('This image has 2 security problems that attackers are using'.$tail);
});

it('uses the builder text for a no_fix image that a builder builds', function () {
    $security = sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 1, 'anyExploited' => true]);

    expect(SecuritySentences::headline($security, builderImage: true))->toBe(SecurityActions::BUILDER_TEXT);
});

it('writes the ok headline', function () {
    expect(SecuritySentences::headline(sentenceSecurity(SecurityStatusType::Ok)))->toBe('No action needed.');
});

it('writes the check_needed headline with each reason', function () {
    $cases = [
        ['images_unchecked', ['checked' => 1, 'total' => 3], '1 of 3 images were checked'],
        ['db_outdated', [], 'the vulnerability database is out of date'],
        ['image_changed', [], 'the running image is not the checked image'],
        ['versions_unchecked', [], 'Coolify could not check for newer versions'],
    ];

    foreach ($cases as [$code, $context, $reason]) {
        $security = sentenceSecurity(SecurityStatusType::CheckNeeded, ['checkReason' => $code, 'checkContext' => $context]);

        expect(SecuritySentences::checkReason($code, $context))->toBe($reason)
            ->and(SecuritySentences::headline($security))->toBe("Coolify needs to check this image again: {$reason}.");
    }
});

it('writes the stale reason with the time of the last check', function () {
    $time = CarbonImmutable::now()->subDays(3);

    expect(SecuritySentences::checkReason('stale', ['time' => $time->toIso8601String()]))->toBe('the last check was 3 days ago');

    $security = sentenceSecurity(SecurityStatusType::CheckNeeded, ['checkReason' => 'stale', 'checkContext' => ['time' => $time->toIso8601String()], 'checkedAt' => $time]);
    expect(SecuritySentences::headline($security))->toBe('Coolify needs to check this image again: the last check was 3 days ago.');
});

it('falls back to the checked time when the stale context has no time', function () {
    $time = CarbonImmutable::now()->subDays(4);
    $security = sentenceSecurity(SecurityStatusType::CheckNeeded, ['checkReason' => 'stale', 'checkedAt' => $time]);

    expect(SecuritySentences::headline($security))->toBe('Coolify needs to check this image again: the last check was 4 days ago.');
});

it('writes a neutral text for not_checked', function () {
    $texts = [
        'instance' => 'Image scanning is off for this Coolify instance.',
        'server' => 'Image scanning is off for this server.',
        'application' => 'Image scanning is off for this app.',
        'not_scanned' => 'This image has not been scanned yet.',
    ];

    foreach ($texts as $code => $text) {
        expect(SecuritySentences::headline(sentenceSecurity(SecurityStatusType::NotChecked, ['checkReason' => $code])))->toBe($text);
    }
    expect(SecuritySentences::headline(sentenceSecurity(SecurityStatusType::NotChecked)))->toBe('This image has not been scanned yet.');
});

it('writes the why-more line in the right grammar', function () {
    expect(SecuritySentences::whyMore(0))->toBe('')
        ->and(SecuritySentences::whyMore(1))->toBe('and 1 more serious problem that this update fixes')
        ->and(SecuritySentences::whyMore(4))->toBe('and 4 more serious problems that this update fixes');
});

it('names the badge of each status', function () {
    expect(SecuritySentences::badge(SecurityStatusType::UpdateNow))->toBe('Update now')
        ->and(SecuritySentences::badge(SecurityStatusType::UpdateAvailable))->toBe('Security update')
        ->and(SecuritySentences::badge(SecurityStatusType::NoFix))->toBe('No fix yet')
        ->and(SecuritySentences::badge(SecurityStatusType::CheckNeeded))->toBe('Check needed')
        ->and(SecuritySentences::badge(SecurityStatusType::Ok))->toBeNull()
        ->and(SecuritySentences::badge(SecurityStatusType::NotChecked))->toBeNull();
});

it('writes the database update confirmation for a patch release', function () {
    expect(SecuritySentences::databaseUpdateConfirmation('PostgreSQL', '16.1-alpine', '16.4-alpine'))
        ->toBe('This is a patch release of PostgreSQL 16. Your data stays as it is. The database restarts and is unavailable for a few seconds.');
});

it('takes the major version of the confirmation from the new tag', function () {
    expect(SecuritySentences::databaseUpdateConfirmation('MySQL', 'v8.0.35', 'v8.0.40'))
        ->toContain('patch release of MySQL 8.')
        ->and(SecuritySentences::databaseUpdateConfirmation('Redis', 'latest', 'latest'))
        ->toContain('patch release of Redis.');
});

it('never writes an em dash, "safe" or "secure" in any sentence', function () {
    $time = CarbonImmutable::now()->subDays(3);
    $all = [];
    $securities = [
        sentenceUpdateNow(), sentenceUpdateNow(['problemCount' => 3, 'fixedByPrimary' => 2, 'anyExploited' => false, 'exploitedWithoutFix' => true, 'exploitedCount' => 5]),
        sentenceUpdateAvailable(), sentenceUpdateAvailable(['exploitedWithoutFix' => true, 'exploitedCount' => 1, 'fixedByPrimary' => 1, 'primaryCritical' => 1, 'primaryHigh' => 0]),
        sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 1]), sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 3]),
        sentenceSecurity(SecurityStatusType::Ok),
        sentenceSecurity(SecurityStatusType::NotChecked),
    ];
    foreach (['instance', 'server', 'application', 'not_scanned'] as $code) {
        $securities[] = sentenceSecurity(SecurityStatusType::NotChecked, ['checkReason' => $code]);
    }
    foreach (['images_unchecked', 'stale', 'db_outdated', 'image_changed', 'versions_unchecked'] as $code) {
        $securities[] = sentenceSecurity(SecurityStatusType::CheckNeeded, ['checkReason' => $code, 'checkContext' => ['checked' => 1, 'total' => 2, 'time' => $time->toIso8601String()], 'checkedAt' => $time]);
    }
    foreach ($securities as $security) {
        $all[] = SecuritySentences::headline($security);
        $all[] = SecuritySentences::headline($security, builderImage: true);
        array_push($all, ...SecuritySentences::lines($security));
        $all[] = (string) SecuritySentences::badge($security->status);
    }
    foreach ([0, 1, 5] as $n) {
        $all[] = SecuritySentences::whyMore($n);
    }
    $all[] = SecuritySentences::databaseUpdateConfirmation('PostgreSQL', '16.1', '16.4');
    $all[] = SecurityActions::BUILDER_TEXT;

    foreach ($all as $text) {
        expect($text)->not->toContain('—')
            ->and(strtolower($text))->not->toMatch('/\b(safe|secure)\b/');
    }
});

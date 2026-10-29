<?php

use App\Enums\SecurityActionType;
use App\Enums\SecurityStatusType;
use App\Models\ImageScan;
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
    $kev = ['counts' => ['kev_unfixed' => 1]];

    expect(SecuritySentences::lines(sentenceUpdateNow(['exploitedWithoutFix' => true, 'exploitedCount' => 2, 'facts' => $kev])))
        ->toBe(['One more problem that attackers are using has no fix yet.'])
        ->and(SecuritySentences::lines(sentenceUpdateNow(['exploitedWithoutFix' => true, 'exploitedCount' => 4, 'facts' => $kev])))
        ->toBe(['3 more problems that attackers are using have no fix yet.'])
        ->and(SecuritySentences::lines(sentenceUpdateAvailable(['exploitedWithoutFix' => true, 'exploitedCount' => 1, 'facts' => $kev])))
        ->toBe(['One more problem that attackers are using has no fix yet.'])
        ->and(SecuritySentences::lines(sentenceUpdateAvailable(['exploitedWithoutFix' => true, 'exploitedCount' => 2, 'facts' => $kev])))
        ->toBe(['2 more problems that attackers are using have no fix yet.']);
});

it('says "likely to use" in the extra line when no problem without a fix is a known exploited one', function () {
    expect(SecuritySentences::lines(sentenceUpdateNow(['exploitedWithoutFix' => true, 'exploitedCount' => 2])))
        ->toBe(['One more problem that attackers are likely to use has no fix yet.'])
        ->and(SecuritySentences::lines(sentenceUpdateAvailable(['exploitedWithoutFix' => true, 'exploitedCount' => 3, 'facts' => ['counts' => ['kev_unfixed' => 0]]])))
        ->toBe(['3 more problems that attackers are likely to use have no fix yet.']);
});

it('writes no extra line when nothing is left without a fix', function () {
    expect(SecuritySentences::lines(sentenceUpdateNow(['exploitedWithoutFix' => true, 'exploitedCount' => 1])))->toBe([])
        ->and(SecuritySentences::lines(sentenceUpdateAvailable(['exploitedWithoutFix' => true, 'exploitedCount' => 0])))->toBe([]);
});

it('writes no extra line without the exploited_without_fix flag or for other statuses', function () {
    expect(SecuritySentences::lines(sentenceUpdateNow()))->toBe([])
        ->and(SecuritySentences::lines(sentenceSecurity(SecurityStatusType::NoFix, ['exploitedWithoutFix' => true, 'exploitedCount' => 1])))->toBe([])
        ->and(SecuritySentences::lines(sentenceSecurity(SecurityStatusType::Ok)))->toBe([]);
});

it('says "likely to use" for a no_fix image when no problem is a known exploited one', function () {
    $security = sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 2, 'anyExploited' => false]);

    expect(SecuritySentences::headline($security))
        ->toStartWith('This image has 2 security problems that attackers are likely to use, and no fixed version exists yet. Coolify checks every day');
});

it('writes the no_fix headline', function () {
    $middle = ', and no fixed version exists yet. Coolify checks every day and tells you when a fix is out. Until then, limit who can reach this app, or ';

    expect(SecuritySentences::headline(sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 1, 'anyExploited' => true])))
        ->toBe('This image has a security problem that attackers are using'.$middle.'ignore the problem if the affected part is not used.')
        ->and(SecuritySentences::headline(sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 2, 'anyExploited' => true])))
        ->toBe('This image has 2 security problems that attackers are using'.$middle.'ignore the problems if the affected parts are not used.');
});

it('writes the builder no_fix headline with the problem first', function () {
    $builder = fn (bool $kev, int $count): string => SecuritySentences::headline(sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => $count, 'anyExploited' => $kev, 'builderImage' => true]));

    expect($builder(true, 1))->toBe('This image has a security problem that attackers are using. '.SecurityActions::BUILDER_TEXT)
        ->and($builder(false, 2))->toBe('This image has 2 security problems that attackers are likely to use. '.SecurityActions::BUILDER_TEXT)
        ->and(SecuritySentences::headline(sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 1, 'anyExploited' => true])))
        ->not->toContain(SecurityActions::BUILDER_TEXT);
});

it('says what a pull_latest action fixes in update_now', function () {
    $pull = sentenceAction(SecurityActionType::PullLatest, 'A newer build of this image fixes it. Pull it and restart.');
    $compose = sentenceAction(SecurityActionType::PullLatest, 'A newer build of this image fixes it. Redeploy this app to pull it.');
    $coolify = sentenceAction(SecurityActionType::PullLatest, SecurityActions::COOLIFY_TEXT);
    $head = 'This image has a security problem that attackers are using. ';

    expect(SecuritySentences::headline(sentenceUpdateNow(['actions' => [$pull]])))->toBe($head.'A newer build of this image fixes it. Pull it and restart.')
        ->and(SecuritySentences::headline(sentenceUpdateNow(['actions' => [$compose]])))->toBe($head.'A newer build of this image fixes it. Redeploy this app to pull it.')
        ->and(SecuritySentences::headline(sentenceUpdateNow(['actions' => [$coolify]])))->toBe($head.SecurityActions::COOLIFY_TEXT)
        ->and(SecuritySentences::headline(sentenceUpdateNow(['actions' => [$pull], 'problemCount' => 3, 'fixedByPrimary' => 3])))
        ->toBe('This image has 3 security problems that attackers are using. A newer build of this image fixes them. Pull it and restart.')
        ->and(SecuritySentences::headline(sentenceUpdateNow(['actions' => [$pull], 'problemCount' => 3, 'fixedByPrimary' => 2])))
        ->toEndWith('A newer build of this image fixes 2 of them. Pull it and restart.');
});

it('says a newer build is available for a pull_latest action in update_available', function () {
    $pull = sentenceAction(SecurityActionType::PullLatest, 'A newer build of this image fixes it. Pull it and restart.');
    $compose = sentenceAction(SecurityActionType::PullLatest, 'A newer build of this image fixes it. Redeploy this app to pull it.');
    $coolify = sentenceAction(SecurityActionType::PullLatest, SecurityActions::COOLIFY_TEXT);

    expect(SecuritySentences::headline(sentenceUpdateAvailable(['actions' => [$pull]])))
        ->toBe('A newer build of this image is available. Pull it and restart. This fixes 3 serious problems (1 critical, 2 high). Attackers are not known to use them.')
        ->and(SecuritySentences::headline(sentenceUpdateAvailable(['actions' => [$compose]])))
        ->toStartWith('A newer build of this image is available. Redeploy this app to pull it. This fixes')
        ->and(SecuritySentences::headline(sentenceUpdateAvailable(['actions' => [$coolify]])))
        ->toStartWith(SecurityActions::COOLIFY_TEXT.' This fixes');
});

it('puts "This fixes" before the major version note', function () {
    $upgrade = sentenceAction(SecurityActionType::UpgradePackage, 'Upgrade lodash to 5.0.0 or later, then redeploy. This is a major version change. Check for breaking changes.');

    expect(SecuritySentences::headline(sentenceUpdateAvailable(['actions' => [$upgrade], 'fixedByPrimary' => 1, 'primaryCritical' => 1, 'primaryHigh' => 0])))
        ->toBe('Upgrade lodash to 5.0.0 or later, then redeploy. This fixes 1 serious problem (1 critical). This is a major version change. Check for breaking changes. Attackers are not known to use it.');
});

it('says when the primary action moves the image off an end-of-life OS', function () {
    $eol = ['name' => 'Alpine', 'version' => '3.16', 'fixed' => true];
    $stats = ['action_stats' => [['eol' => 1]]];
    $sentence = 'It moves off Alpine 3.16, which no longer gets security fixes.';

    expect(SecuritySentences::headline(sentenceUpdateAvailable(['eol' => $eol, 'facts' => $stats])))
        ->toBe('Update to 16.4-alpine. This fixes 3 serious problems (1 critical, 2 high). '.$sentence.' Attackers are not known to use them.')
        ->and(SecuritySentences::headline(sentenceUpdateNow(['eol' => $eol, 'facts' => $stats])))
        ->toEndWith('Update to 16.4-alpine to fix it. '.$sentence)
        ->and(SecuritySentences::headline(sentenceUpdateAvailable(['eol' => $eol, 'facts' => ['action_stats' => [['eol' => 0]]]])))
        ->not->toContain('no longer gets')
        ->and(SecuritySentences::headline(sentenceUpdateAvailable(['eol' => ['name' => 'Alpine', 'version' => '3.16', 'fixed' => false]])))
        ->not->toContain('no longer gets');
});

it('says the last check is incomplete for an unknown check_needed reason', function () {
    expect(SecuritySentences::headline(sentenceSecurity(SecurityStatusType::CheckNeeded, ['checkReason' => 'something_new'])))
        ->toBe('Coolify needs to check this image again: the last check is incomplete.')
        ->and(SecuritySentences::headline(sentenceSecurity(SecurityStatusType::CheckNeeded)))
        ->toBe('Coolify needs to check this image again: the last check is incomplete.');
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
        sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 2, 'builderImage' => true]),
        sentenceSecurity(SecurityStatusType::CheckNeeded, ['checkReason' => 'unknown']),
        sentenceUpdateNow(['actions' => [sentenceAction(SecurityActionType::PullLatest, 'A newer build of this image fixes it. Pull it and restart.')], 'eol' => ['name' => 'Alpine', 'version' => '3.16', 'fixed' => true], 'facts' => ['action_stats' => [['eol' => 1]]]]),
        sentenceUpdateAvailable(['actions' => [sentenceAction(SecurityActionType::PullLatest, 'A newer build of this image fixes it. Pull it and restart.')], 'eol' => ['name' => 'Alpine', 'version' => '3.16', 'fixed' => true], 'facts' => ['action_stats' => [['eol' => 1]]]]),
    ];
    foreach (['instance', 'server', 'application', 'not_scanned'] as $code) {
        $securities[] = sentenceSecurity(SecurityStatusType::NotChecked, ['checkReason' => $code]);
    }
    foreach (['images_unchecked', 'stale', 'db_outdated', 'image_changed', 'versions_unchecked'] as $code) {
        $securities[] = sentenceSecurity(SecurityStatusType::CheckNeeded, ['checkReason' => $code, 'checkContext' => ['checked' => 1, 'total' => 2, 'time' => $time->toIso8601String()], 'checkedAt' => $time]);
    }
    foreach ($securities as $security) {
        $all[] = SecuritySentences::headline($security);
        $all[] = SecuritySentences::headline(sentenceSecurity($security->status, [...get_object_vars($security), 'builderImage' => true]));
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

/**
 * @param  array<string, mixed>  $attributes
 */
function sentenceScan(array $attributes = []): ImageScan
{
    return (new ImageScan)->forceFill([
        'image_ref' => 'postgres:16-alpine',
        'candidates' => ['status' => 'ok', 'checked_at' => CarbonImmutable::now()->subHours(3)->toIso8601String(), 'entries' => []],
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function sentenceCandidate(string $ref, string $kind): array
{
    return ['ref' => $ref, 'kind' => $kind, 'better' => true, 'fixed_ids' => [], 'scanned_at' => CarbonImmutable::now()->subHours(2)->toIso8601String()];
}

describe('image rows', function () {
    it('starts the sentence of an image with its ref', function () {
        expect(SecuritySentences::imageSentence(sentenceUpdateNow(), 'n8nio/n8n:1.68.0'))
            ->toBe('n8nio/n8n:1.68.0 has a security problem that attackers are using. Update to 16.4-alpine to fix it.')
            ->and(SecuritySentences::imageSentence(sentenceUpdateNow(['problemCount' => 2, 'fixedByPrimary' => 2, 'exploitedCount' => 2]), 'n8nio/n8n:1.68.0'))
            ->toBe('n8nio/n8n:1.68.0 has 2 security problems that attackers are using. Update to 16.4-alpine to fix them.');
    });

    it('says that a newer build fixes critical problems', function (int $critical, string $problems) {
        $pull = sentenceAction(SecurityActionType::PullLatest, 'A newer build of this image fixes it. Pull it and restart.', '16-alpine');
        $security = sentenceUpdateAvailable(['actions' => [$pull], 'fixedByPrimary' => $critical, 'primaryCritical' => $critical, 'primaryHigh' => 0]);

        expect(SecuritySentences::imageSentence($security, 'postgres:16-alpine'))->toBe("postgres:16-alpine has a newer build that fixes {$problems}.");
    })->with([
        'one' => [1, 'a critical problem'],
        'two' => [2, '2 critical problems'],
    ]);

    it('names the severities of an update and the action', function () {
        expect(SecuritySentences::imageSentence(sentenceUpdateAvailable(), 'postgres:16.1-alpine'))
            ->toBe('postgres:16.1-alpine has 3 serious problems (1 critical, 2 high). Update to 16.4-alpine to fix them.');
    });

    it('adds the attack note only when the sentence does not mention attackers', function () {
        expect(SecuritySentences::attackNote(sentenceUpdateAvailable()))->toBe('No known attacks on these problems.')
            ->and(SecuritySentences::attackNote(sentenceUpdateAvailable(['fixedByPrimary' => 1])))->toBe('No known attacks on this problem.')
            ->and(SecuritySentences::attackNote(sentenceUpdateAvailable(['exploitedWithoutFix' => true])))->toBeNull()
            ->and(SecuritySentences::attackNote(sentenceUpdateNow()))->toBeNull()
            ->and(SecuritySentences::attackNote(sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 1, 'anyExploited' => true])))->toBeNull()
            ->and(SecuritySentences::attackNote(sentenceSecurity(SecurityStatusType::Ok)))->toBeNull();
    });

    it('says that no fixed version exists yet, with the advice on its own line', function (int $count, bool $exploited, string $sentence, string $advice) {
        $security = sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => $count, 'anyExploited' => $exploited]);

        expect(SecuritySentences::imageSentence($security, 'mysql:8'))->toBe($sentence)
            ->and(SecuritySentences::noFixAdvice($security))->toBe($advice);
    })->with([
        'one, using' => [1, true, 'mysql:8 has a security problem that attackers are using. No fixed version exists yet, and Coolify checks every day.',
            'Until then, limit who can reach this app, or ignore the problem if the affected part is not used.'],
        'two, likely' => [2, false, 'mysql:8 has 2 security problems that attackers are likely to use. No fixed version exists yet, and Coolify checks every day.',
            'Until then, limit who can reach this app, or ignore the problems if the affected parts are not used.'],
    ]);

    it('gives no advice to a builder image, whose sentence names the builder update', function () {
        $security = sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 1, 'anyExploited' => true, 'builderImage' => true]);

        expect(SecuritySentences::imageSentence($security, 'app:abc'))->toBe('app:abc has a security problem that attackers are using. '.SecurityActions::BUILDER_TEXT)
            ->and(SecuritySentences::noFixAdvice($security))->toBeNull();
    });

    it('counts the known issues of an image', function () {
        expect(SecuritySentences::knownIssues(12))->toBe('12 known issues')
            ->and(SecuritySentences::knownIssues(1))->toBe('1 known issue')
            ->and(SecuritySentences::knownIssues(0))->toBe('No known issues')
            ->and(SecuritySentences::imagesNeedingUpdate(2, 3))->toBe('2 of 3 images need an update')
            ->and(SecuritySentences::imagesNeedingUpdate(1, 3))->toBe('1 of 3 images needs an update')
            ->and(SecuritySentences::imagesNeedingUpdate(0, 3))->toBeNull()
            ->and(SecuritySentences::shortDigest('sha256:3f1a2b4c5d6e7f8091a2b3c4d5e6f708'))->toBe('3f1a2b4c5d6e');
    });
});

describe('provenance', function () {
    it('says that the registry has a newer build of the same tag, with what its scan found', function () {
        $scan = sentenceScan(['candidates' => ['status' => 'ok', 'entries' => [[
            ...sentenceCandidate('postgres:16-alpine', 'same_tag'), 'fixes' => ['critical' => 2, 'high' => 0, 'medium' => 0], 'added' => 0,
        ]]]]);
        $action = new SecurityAction(SecurityActionType::PullLatest, 'A newer build of this image fixes it. Pull it and restart.', '16-alpine', null, [], true, 'postgres:16-alpine', 'postgres:16-alpine');

        expect(SecuritySentences::provenance($scan, $action, sentenceUpdateAvailable()))
            ->toBe('Docker Hub has a newer build of 16-alpine. Coolify scanned it 2 hours ago: it fixes these problems and adds none that attackers use.');
    });

    it('says that the tag is the newest release of the line, and the other problems it adds', function (array $stored, string $other) {
        $scan = sentenceScan([
            'image_ref' => 'ghcr.io/n8n-io/n8n:1.68.0',
            'candidates' => ['status' => 'ok', 'entries' => [[...sentenceCandidate('ghcr.io/n8n-io/n8n:1.71.2', 'line'), ...$stored]]],
        ]);
        $action = new SecurityAction(SecurityActionType::UpdateImage, 'Update to 1.71.2.', '1.71.2', null, [], true, 'ghcr.io/n8n-io/n8n:1.68.0', 'ghcr.io/n8n-io/n8n:1.71.2');

        expect(SecuritySentences::provenance($scan, $action, sentenceUpdateNow()))
            ->toBe('1.71.2 is the newest 1.x release on GitHub Container Registry. Coolify scanned it 2 hours ago: it fixes these problems and adds none that attackers use.'.$other);
    })->with([
        'adds several' => [['fixes' => ['critical' => 1, 'high' => 0, 'medium' => 0], 'added' => 3], ' It has 3 other problems that attackers are not known to use.'],
        'adds one' => [['added' => 1], ' It has 1 other problem that attackers are not known to use.'],
        'adds none' => [['added' => 0], ''],
        'old entry without added' => [['fixes' => ['critical' => 0, 'high' => 2, 'medium' => 1]], ''],
    ]);

    it('claims no scan when no candidate entry is stored', function () {
        $action = new SecurityAction(SecurityActionType::UpdateImage, 'Update to 1.71.2.', '1.71.2', null, [], true, 'n8nio/n8n:1.68.0', 'n8nio/n8n:1.71.2');

        expect(SecuritySentences::provenance(sentenceScan(['image_ref' => 'n8nio/n8n:1.68.0']), $action, sentenceUpdateNow()))->toBeNull();
    });

    it('says that the new base image is the newest release', function () {
        $scan = sentenceScan([
            'image_ref' => 'app:abc1234',
            'base_image_ref' => 'node:20.11-alpine',
            'candidates' => ['status' => 'skipped', 'entries' => [], 'base' => [
                'image' => 'node:20.11-alpine', 'status' => 'ok', 'entries' => [sentenceCandidate('node:20.18-alpine', 'line')],
            ]],
        ]);
        $action = new SecurityAction(SecurityActionType::ChangeBase, 'Change the base image to node:20.18-alpine in your Dockerfile, then redeploy.', 'node:20.18-alpine', 'FROM node:20.18-alpine', [], false, 'app:abc1234');

        expect(SecuritySentences::provenance($scan, $action, sentenceUpdateAvailable()))
            ->toBe('node:20.18-alpine is the newest release of your base image. Coolify scanned it 2 hours ago: it fixes these problems and adds none that attackers use.');
    });

    it('names the first fixed version of a package upgrade', function (SecurityActionType $type) {
        $action = new SecurityAction($type, 'Upgrade lodash to 4.17.21 or later, then redeploy.', 'lodash', 'lodash 4.17.21', [], false, 'app:abc1234');

        expect(SecuritySentences::provenance(sentenceScan(), $action, sentenceUpdateAvailable(), ['fixed_in' => '4.17.21']))
            ->toBe('The advisory lists lodash 4.17.21 as the first fixed version.');
    })->with([SecurityActionType::UpgradePackage, SecurityActionType::UpgradeInDockerfile]);

    it('says that the current base image has the fixed system packages', function () {
        $action = new SecurityAction(SecurityActionType::Rebuild, 'Rebuild without cache to get the fixed system packages.', null, null, [], true, 'app:abc1234');

        expect(SecuritySentences::provenance(sentenceScan(['base_image_ref' => 'node:20.18-alpine']), $action, sentenceUpdateAvailable()))
            ->toBe('The current node:20.18-alpine image already has the fixed system packages.');
    });

    it('says when Coolify last checked the registry for a fix', function () {
        $noFix = sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 1, 'anyExploited' => true]);

        expect(SecuritySentences::provenance(sentenceScan(['candidates' => ['status' => 'no_better', 'checked_at' => CarbonImmutable::now()->subHours(3)->toIso8601String(), 'entries' => []]]), null, $noFix))
            ->toBe('Coolify checked Docker Hub 3 hours ago: you already run the newest 16.x version.')
            ->and(SecuritySentences::provenance(sentenceScan(['image_ref' => 'quay.io/keycloak/keycloak:26.0', 'candidates' => ['status' => 'no_better', 'checked_at' => CarbonImmutable::now()->subHours(3)->toIso8601String(), 'entries' => [
                [...sentenceCandidate('quay.io/keycloak/keycloak:26.0', 'same_tag'), 'better' => false],
                [...sentenceCandidate('quay.io/keycloak/keycloak:26.4', 'line'), 'better' => false],
            ]]]), null, sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 2])))
            ->toBe('Coolify checked Quay 3 hours ago: a newer build of 26.0 and 26.4 are newer, but not safer.')
            ->and(SecuritySentences::provenance(sentenceScan(['candidates' => ['status' => 'failed', 'entries' => []]]), null, $noFix))->toBeNull()
            ->and(SecuritySentences::provenance(sentenceScan(), null, sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 1, 'builderImage' => true])))->toBeNull();
    });

    it('names the packages of a no-fix problem and whether a fixed version of them exists', function (string $fixState, ?string $fixedIn, string $text) {
        $finding = ['id' => 'CVE-2024-6119', 'aliases' => [], 'severity' => 'medium', 'package' => 'openssl', 'version' => '3.5.8', 'type' => 'rpm',
            'artifactId' => 'a', 'fixedIn' => $fixedIn, 'fixState' => $fixState, 'kev' => false, 'epss' => 0.5, 'risk' => 1.0, 'summary' => '', 'displayId' => 'CVE-2024-6119'];
        $why = [['title' => 'TLS name checks', 'display_id' => 'CVE-2024-6119', 'link' => null, 'score' => 7.5, 'severity' => 'medium', 'signal' => 'likely', 'package' => 'openssl', 'version' => '3.5.8']];
        $scan = sentenceScan(['image_ref' => 'mysql:8', 'findings' => [$finding], 'candidates' => ['status' => 'no_better', 'checked_at' => CarbonImmutable::now()->subHours(3)->toIso8601String(), 'entries' => []]]);

        expect(SecuritySentences::provenance($scan, null, sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 1, 'why' => $why])))
            ->toBe('Coolify checked Docker Hub 3 hours ago: you already run the newest 8.x version. '.$text);
    })->with([
        'no fixed package' => ['not-fixed', null, 'The problem is in openssl, and no fixed version of it exists yet.'],
        'a fixed package the image does not include' => ['fixed', '3.5.9', 'The problem is in openssl. Fixed versions exist, but the image does not include them yet.'],
    ]);

    it('says whether the newest major version would fix the problems, without offering it', function (bool $fixes, array $fixedIds, string $text) {
        $why = [['title' => 'TLS name checks', 'display_id' => 'CVE-2024-6119', 'link' => null, 'score' => 7.5, 'severity' => 'medium', 'signal' => 'likely', 'package' => '', 'version' => '']];
        $major = [...sentenceCandidate('mysql:26', 'major'), 'better' => false, 'fixes_problems' => $fixes, 'fixed_ids' => $fixedIds];
        $scan = sentenceScan(['image_ref' => 'mysql:8', 'candidates' => ['status' => 'no_better', 'checked_at' => CarbonImmutable::now()->subHours(3)->toIso8601String(), 'entries' => [$major]]]);

        expect(SecuritySentences::provenance($scan, null, sentenceSecurity(SecurityStatusType::NoFix, ['problemCount' => 1, 'why' => $why])))
            ->toBe('Coolify checked Docker Hub 3 hours ago: you already run the newest 8.x version. '.$text);
    })->with([
        'fixes it' => [true, ['CVE-2024-6119'], 'mysql:26, a new major version, fixes it. Coolify does not change major versions for you, because they can need a data migration or code changes: read its upgrade notes first.'],
        'not safer' => [false, ['CVE-2024-6119'], 'The newest major version, mysql:26, is not safer either.'],
        'safer, but not for these problems' => [true, [], 'The newest major version, mysql:26, is not safer either.'],
    ]);

    it('names the patch line of an exact tag', function () {
        $scan = sentenceScan(['image_ref' => 'mysql:8.0.40', 'candidates' => ['status' => 'ok', 'checked_at' => CarbonImmutable::now()->subHours(3)->toIso8601String(), 'entries' => [sentenceCandidate('mysql:8.0.45', 'line')]]]);
        $action = new SecurityAction(SecurityActionType::UpdateImage, 'Update to 8.0.45.', '8.0.45', null, [], true, 'mysql:8.0.40', 'mysql:8.0.45');

        expect(SecuritySentences::provenance($scan, $action, sentenceUpdateAvailable()))
            ->toBe('8.0.45 is the newest 8.0.x release on Docker Hub. Coolify scanned it 2 hours ago: it fixes these problems and adds none that attackers use.');
    });
});

<?php

declare(strict_types=1);

use App\Registries\LegalDocumentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * These are the URLs a Play Store listing points at. They must answer a bare,
 * unauthenticated GET with readable text, and they must keep announcing that the
 * text is a placeholder until a lawyer has actually reviewed it.
 */
it('registers the three documents the store listing requires', function (): void {
    $registry = app(LegalDocumentRegistry::class);

    expect($registry->has('privacy'))->toBeTrue()
        ->and($registry->has('terms'))->toBeTrue()
        ->and($registry->has('account-deletion'))->toBeTrue();
});

it('serves each document to a signed-out visitor on its short public path', function (string $path): void {
    $this->assertGuest();

    $this->get($path)->assertOk();
})->with(['/privacy', '/terms', '/account-deletion']);

it('also serves each document on the canonical legal path', function (string $slug): void {
    $this->get("/legal/{$slug}")->assertOk();
})->with(['privacy', 'terms', 'account-deletion']);

it('announces on every page that the content is an unreviewed placeholder', function (string $path): void {
    $text = strip_tags($this->get($path)->assertOk()->getContent());

    // strip_tags deliberately: the notice has to be readable, not merely present
    // in the markup as an attribute or a comment.
    expect($text)->toContain('Placeholder')
        ->and($text)->toContain('not');
})->with(['/privacy', '/terms', '/account-deletion']);

it('states the 18-month retention window and the email-reuse consequence', function (): void {
    // The window is the one fact a departing user is most likely to be caught by,
    // and it has to match config('prune.retention_months') or the page is a lie.
    expect(config('prune.retention_months'))->toBe(18);

    foreach (['/privacy', '/account-deletion'] as $path) {
        $text = strip_tags($this->get($path)->assertOk()->getContent());

        expect($text)->toContain('18 months');
        expect(strtolower($text))->toContain('cannot');
        expect(strtolower($text))->toContain('email address');
    }
});

it('describes the collection surfaces the app actually has', function (): void {
    $text = strtolower(strip_tags($this->get('/privacy')->assertOk()->getContent()));

    // Each of these is a real behaviour in the codebase. A privacy policy that
    // omits one of them contradicts the Play Data safety form and fails review.
    expect($text)->toContain('foreground')          // expo-location, foreground only
        ->and($text)->toContain('access_fine_location')
        ->and($text)->toContain('camera')            // expo-camera
        ->and($text)->toContain('read_media_images')
        ->and($text)->toContain('digitalocean spaces') // media storage
        ->and($text)->toContain('offline')           // WatermelonDB local database
        ->and($text)->toContain('exif');             // photo metadata, now stripped on the device
});

it('describes photo metadata as stripped on the device, and still names what is not', function (): void {
    // This page used to warn that metadata was NOT removed, and that warning was
    // true when it was written. STOURIFY-40 made it false: the mobile app now
    // strips EXIF before a photo ever leaves the phone. A policy that describes
    // last month's behaviour is the same defect as one that overclaims, so the
    // wording is pinned here in both directions.
    $text = strtolower(strip_tags($this->get('/privacy')->assertOk()->getContent()));

    expect($text)->toContain('removed on your device')
        // The old disclaimer, gone. Its presence alongside the new paragraph
        // would leave the page contradicting itself.
        ->and($text)->not->toContain('does not currently strip')
        // And the honest remainder: JPEG is covered, video and other formats are
        // not, so the page must keep saying so.
        ->and($text)->toContain('jpeg')
        ->and($text)->toContain('video');
});

it('does not claim collection the app does not perform', function (): void {
    $text = strtolower(strip_tags($this->get('/privacy')->assertOk()->getContent()));

    // The honest negatives are as load-bearing as the positives: there is no
    // analytics SDK, no crash reporter, no push token and no ad identifier
    // anywhere in the mobile app, and Data safety must say so.
    expect($text)->toContain('no analytics')
        ->and($text)->toContain('no device identifiers')
        ->and($text)->toContain('no advertising')
        ->and($text)->toContain('do not sell');
});

it('leaves the operator-supplied legal details as visible bracketed placeholders', function (): void {
    $privacy = $this->get('/privacy')->assertOk()->getContent();

    // If these ever disappear it means somebody filled them in — or, worse, that
    // an agent invented a company name. Both are worth failing a build over.
    expect($privacy)->toContain('[LEGAL ENTITY NAME]')
        ->and($privacy)->toContain('[PRIVACY CONTACT EMAIL]');
});

it('links the documents to one another so a reader can reach all three', function (): void {
    $content = $this->get('/privacy')->assertOk()->getContent();

    expect($content)->toContain('/terms')
        ->and($content)->toContain('/account-deletion');
});

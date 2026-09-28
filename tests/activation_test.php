<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_aianatomy;

use mod_aianatomy\local\unlock;

/**
 * Activation (one-time unlock) against a mocked LMS Labs: no real request is made and no credits are spent.
 *
 * @package    mod_aianatomy
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aianatomy\local\unlock
 */
final class activation_test extends \advanced_testcase {
    /** @var array requests seen by the mock */
    private array $requests = [];

    /** @var array scripted replies: route => list of [status, body array|string|null] (last one repeats) */
    private array $replies = [];

    /** Release SHA in the mocked catalogue (any valid SHA: the plugin always reads it at runtime). */
    const SHA = 'a4e4b5e570c8e55789d6894d92cb160749581e0eec38dbe9034e8fbf9b95b3c2';

    /**
     * The live manifest shape: an associative plugins map keyed by component.
     *
     * @param array $override fields of the mod_aianatomy entry
     * @return array reply
     */
    private static function catalogue(array $override = []): array {
        return [200, ['plugins' => [
            'mod_other' => ['component' => 'mod_other', 'version' => '2.0.0', 'status' => 'ready', 'zipExists' => true,
                'creditsRequired' => 10, 'acquisitionMode' => 'credit-unlock', 'sha256' => str_repeat('a', 64)],
            'mod_aianatomy' => $override + ['component' => 'mod_aianatomy', 'version' => '1.2.3', 'status' => 'ready',
                'zipExists' => true, 'creditsRequired' => 50, 'acquisitionMode' => 'credit-unlock', 'sha256' => self::SHA],
        ]]];
    }

    /**
     * Mock LMS Labs.
     *
     * @param array $replies
     */
    private function mock(array $replies): void {
        $this->resetAfterTest();
        set_config('lmslabs_siteid', 'site-42', 'mod_aianatomy');
        set_config('lmslabs_apikey', 'secret-key-123', 'mod_aianatomy');
        set_config('lmslabs_useown', 1, 'mod_aianatomy');
        unset_config('unlockstate', 'mod_aianatomy');
        unset_config('unlockpending', 'mod_aianatomy');
        $this->requests = [];
        $this->replies = $replies + ['/api/plugin-versions' => [self::catalogue()]];
        unlock::$transport = function (array $req): array {
            // The route part of the URL (a forced staging base may add a prefix).
            $path = preg_replace('~^.*?(/api/)~', '/api/', (string)parse_url($req['url'], PHP_URL_PATH));
            $this->requests[] = ['method' => $req['method'], 'path' => $path,
                'body' => $req['body'] === null ? null : json_decode($req['body'], true)];
            $list = &$this->replies[$path];
            $reply = count($list) > 1 ? array_shift($list) : $list[0];
            return [$reply[0], is_array($reply[1]) ? json_encode($reply[1]) : (string)$reply[1]];
        };
    }

    /**
     * Requests to a route.
     *
     * @param string $path
     * @return array
     */
    private function sent(string $path): array {
        return array_values(array_filter($this->requests, fn($r) => $r['path'] === $path));
    }

    protected function tearDown(): void {
        unlock::$transport = null;
        parent::tearDown();
    }

    /** Verify reply for a locked site. */
    const LOCKED = [200, ['unlocked' => false, 'credits' => 120, 'unlockedAt' => null, 'entitlementSource' => null]];

    /**
     * Locked site: the free check sends the contract body; the review offers the live price and buys nothing.
     */
    public function test_locked(): void {
        $this->mock(['/api/plugin-unlock/verify' => [self::LOCKED]]);
        $state = unlock::verify();
        $this->assertSame('locked', $state['status']);
        $this->assertSame(120, $state['credits']);
        $this->assertSame(['pluginId' => 'aianatomy', 'siteId' => 'site-42', 'apiKey' => 'secret-key-123'],
            $this->sent('/api/plugin-unlock/verify')[0]['body']);
        $review = unlock::review();
        $this->assertTrue($review['canbuy']);
        $this->assertSame(50, $review['release']['price']);
        $this->assertSame(self::SHA, $review['release']['sha']);
        $this->assertSame('1.2.3', $review['release']['version']);
        $this->assertCount(0, $this->sent('/api/plugin-unlock'));
        $this->assertStringNotContainsString('secret-key-123', (string)get_config('mod_aianatomy', 'unlockstate'));
    }

    /**
     * Unlocked site with an unlimited account (verify: credits = -1): nothing is offered or bought.
     */
    public function test_unlocked(): void {
        $this->mock(['/api/plugin-unlock/verify' => [[200, ['unlocked' => true, 'credits' => -1,
            'unlockedAt' => '2026-10-01T10:00:00Z', 'entitlementSource' => 'credits']]]]);
        $review = unlock::review();
        $this->assertFalse($review['canbuy']);
        $this->assertSame('unlocked', $review['blocked']);
        $this->assertTrue($review['state']['unlimited']);
        $this->assertNull($review['state']['credits']);
        $r = unlock::buy(50, self::SHA);
        $this->assertSame('already', $r['outcome']);
        $this->assertNull($r['consumed']);
        $this->assertCount(0, $this->sent('/api/plugin-unlock'));
    }

    /**
     * Cancelled confirmation: reviewing (and then not confirming) never reaches the purchase route.
     */
    public function test_cancelled_confirmation(): void {
        $this->mock(['/api/plugin-unlock/verify' => [self::LOCKED]]);
        $this->assertTrue(unlock::review()['canbuy']);
        foreach ($this->requests as $req) {
            $this->assertNotSame('/api/plugin-unlock', $req['path']);
        }
        $this->assertNull(unlock::pending());
        $this->assertSame('locked', unlock::state()['status']);
    }

    /**
     * New unlock: the purchase body carries the confirmed live price and SHA; creditsConsumed and remainingCredits
     * are read from the real response fields.
     */
    public function test_new_unlock(): void {
        $this->mock([
            '/api/plugin-unlock/verify' => [self::LOCKED, [200, ['unlocked' => true, 'credits' => 70,
                'entitlementSource' => 'credits']]],
            '/api/plugin-unlock' => [[200, ['success' => true, 'creditsConsumed' => 50, 'entitlementSource' => 'credits',
                'remainingCredits' => 70, 'message' => 'Plugin unlocked', 'downloadUrl' => 'https://lms-labs.com/d/x']]],
        ]);
        $r = unlock::buy(50, self::SHA);
        $this->assertSame('unlocked', $r['outcome']);
        $this->assertSame(50, $r['consumed']);
        $this->assertNull($r['historic']);
        $this->assertSame(70, $r['balance']['credits']);
        $this->assertSame('Plugin unlocked', $r['message']);
        $body = $this->sent('/api/plugin-unlock')[0]['body'];
        $this->assertSame(['pluginId' => 'aianatomy', 'pluginComponent' => 'mod_aianatomy', 'siteId' => 'site-42',
            'apiKey' => 'secret-key-123', 'releaseSha256' => self::SHA, 'expectedCredits' => 50], $body);
        $this->assertIsInt($body['expectedCredits']);
        $this->assertNull(unlock::pending());
        $this->assertSame('unlocked', unlock::state()['status']);
    }

    /**
     * Purchase restoration at zero credits: creditsConsumed = 0 with a marketplace or purchase entitlement.
     */
    public function test_zero_credit_restoration(): void {
        foreach (['marketplace', 'purchase'] as $source) {
            $this->mock([
                '/api/plugin-unlock/verify' => [[200, ['unlocked' => false, 'credits' => 5]],
                    [200, ['unlocked' => true, 'credits' => 5, 'entitlementSource' => $source]]],
                '/api/plugin-unlock' => [[200, ['success' => true, 'creditsConsumed' => 0, 'entitlementSource' => $source,
                    'remainingCredits' => 5, 'message' => 'Unlocked from existing purchase']]],
            ]);
            $r = unlock::buy(50, self::SHA);
            $this->assertSame('restored', $r['outcome'], $source);
            $this->assertSame(0, $r['consumed'], $source);
            $this->assertSame($source, $r['source']);
            $this->assertSame(5, $r['balance']['credits']);
            $this->assertNull(unlock::pending());
        }
    }

    /**
     * alreadyUnlocked (e.g. a race or earlier completed request): creditsConsumed is history, never a new debit;
     * remainingCredits "unlimited" is read as unlimited.
     */
    public function test_already_unlocked_reply(): void {
        $this->mock([
            '/api/plugin-unlock/verify' => [self::LOCKED, [0, null]],
            '/api/plugin-unlock' => [[200, ['success' => true, 'alreadyUnlocked' => true, 'creditsConsumed' => 50,
                'entitlementSource' => 'credits', 'remainingCredits' => 'unlimited', 'message' => 'Already unlocked']]],
        ]);
        $r = unlock::buy(50, self::SHA);
        $this->assertSame('already', $r['outcome']);
        $this->assertNull($r['consumed']);
        $this->assertSame(50, $r['historic']);
        $this->assertTrue($r['balance']['unlimited']);
        // The follow-up check was unreachable: the granted access is kept.
        $this->assertSame('unlocked', unlock::state()['status']);
        $this->assertTrue(unlock::state()['unlimited']);
    }

    /**
     * Insufficient credits: the review warns but buys nothing; LMS Labs refuses with 402 and currentCredits.
     */
    public function test_insufficient_credits(): void {
        $this->mock([
            '/api/plugin-unlock/verify' => [[200, ['unlocked' => false, 'credits' => 20]]],
            '/api/plugin-unlock' => [[402, ['success' => false, 'error' => 'insufficient_credits',
                'message' => 'Not enough credits', 'currentCredits' => 12]]],
        ]);
        $review = unlock::review();
        $this->assertTrue($review['canbuy']);
        $this->assertSame('insufficient', $review['warning']);
        $this->assertCount(0, $this->sent('/api/plugin-unlock'));
        $r = unlock::buy(50, self::SHA);
        $this->assertSame('insufficient', $r['outcome']);
        $this->assertNull($r['consumed']);
        $this->assertSame(12, $r['balance']['credits']);
        $this->assertStringContainsString('insufficient_credits', $r['error']);
        $this->assertStringContainsString('Not enough credits', $r['error']);
        $this->assertNull(unlock::pending());
        $this->assertSame(12, unlock::state()['credits']);
    }

    /**
     * 409s are told apart: stale price, ambiguous Marketplace purchase, and other conflicts; the server message
     * is kept for display.
     */
    public function test_conflicts(): void {
        $cases = [
            ['stale_credit_price', 'The credit price changed to 60', 'stale'],
            ['marketplace_entitlement_ambiguous', 'Several Marketplace purchases match this site', 'ambiguous'],
            ['release_unavailable', 'This release is not available for unlock', 'conflict'],
        ];
        foreach ($cases as [$code, $message, $outcome]) {
            $this->mock([
                '/api/plugin-unlock/verify' => [self::LOCKED],
                '/api/plugin-unlock' => [[409, ['success' => false, 'error' => $code, 'message' => $message]]],
            ]);
            $r = unlock::buy(50, self::SHA);
            $this->assertSame($outcome, $r['outcome'], $code);
            $this->assertStringContainsString($message, $r['error'], $code);
            $this->assertStringContainsString($code, $r['error'], $code);
            $this->assertNull(unlock::pending(), $code);
        }
    }

    /**
     * Uncertain delivery: no answer keeps a pending marker; no new purchase can be submitted until a free access
     * check has settled it.
     */
    public function test_uncertain_delivery(): void {
        $this->mock([
            '/api/plugin-unlock/verify' => [self::LOCKED, [200, ['unlocked' => true, 'credits' => 70,
                'entitlementSource' => 'credits']]],
            '/api/plugin-unlock' => [[0, null]],
        ]);
        $r = unlock::buy(50, self::SHA);
        $this->assertSame('uncertain', $r['outcome']);
        $this->assertSame('network', $r['error']);
        $this->assertNull($r['consumed']);
        $this->assertNotNull(unlock::pending());
        $before = count($this->requests);
        $again = unlock::buy(50, self::SHA);
        $this->assertSame('blocked', $again['outcome']);
        $this->assertSame('pending', $again['error']);
        $this->assertCount($before, $this->requests);
        $review = unlock::review();
        $this->assertSame('unlocked', $review['state']['resolved']);
        $this->assertSame('unlocked', $review['blocked']);
        $this->assertNull(unlock::pending());
        $this->assertCount(1, $this->sent('/api/plugin-unlock'));
    }

    /**
     * A 5xx or unreadable 2xx reply is also an unknown outcome; a later "locked" check re-enables the review.
     */
    public function test_uncertain_server_error(): void {
        $this->mock([
            '/api/plugin-unlock/verify' => [self::LOCKED],
            '/api/plugin-unlock' => [[502, 'Bad gateway']],
        ]);
        $this->assertSame('uncertain', unlock::buy(50, self::SHA)['outcome']);
        $this->assertNotNull(unlock::pending());
        $this->assertSame('locked', unlock::verify()['resolved']);
        $this->assertNull(unlock::pending());
        $this->assertTrue(unlock::review()['canbuy']);
        // An unreadable 2xx too.
        $this->mock(['/api/plugin-unlock/verify' => [self::LOCKED], '/api/plugin-unlock' => [[200, '<html>ok</html>']]]);
        $this->assertSame('uncertain', unlock::buy(50, self::SHA)['outcome']);
    }

    /**
     * If the follow-up check cannot reach LMS Labs either, the marker stays and purchases stay disabled.
     */
    public function test_uncertain_check_unreachable(): void {
        $this->mock([
            '/api/plugin-unlock/verify' => [self::LOCKED, [0, null]],
            '/api/plugin-unlock' => [[504, 'Gateway timeout']],
        ]);
        $this->assertSame('uncertain', unlock::buy(50, self::SHA)['outcome']);
        $state = unlock::verify();
        $this->assertSame('unknown', $state['status']);
        $this->assertNull($state['resolved']);
        $this->assertNotNull(unlock::pending());
        $this->assertSame('pending', unlock::review()['blocked']);
        $this->assertCount(1, $this->sent('/api/plugin-unlock'));
    }

    /**
     * Only JSON booleans can settle an uncertain charge. Truthy strings must not grant access.
     */
    public function test_malformed_boolean_replies_remain_pending(): void {
        foreach ([null, 0, 1, 'false', 'true', [], new \stdClass()] as $value) {
            $this->mock(['/api/plugin-unlock/verify' => [[200, ['unlocked' => $value, 'credits' => 120]]]]);
            set_config('unlockpending', json_encode(['time' => time()]), 'mod_aianatomy');
            $this->assertSame('unknown', unlock::verify()['status']);
            $this->assertNotNull(unlock::pending());
        }
        foreach (['true', 1, null] as $value) {
            $this->mock([
                '/api/plugin-unlock/verify' => [self::LOCKED],
                '/api/plugin-unlock' => [[200, ['success' => $value, 'creditsConsumed' => 50]]],
            ]);
            $this->assertSame('uncertain', unlock::buy(50, self::SHA)['outcome']);
            $this->assertNotNull(unlock::pending());
        }
        $this->mock([
            '/api/plugin-unlock/verify' => [self::LOCKED],
            '/api/plugin-unlock' => [[200, ['success' => true, 'alreadyUnlocked' => 'false']]],
        ]);
        $this->assertSame('uncertain', unlock::buy(50, self::SHA)['outcome']);
        $this->assertNotNull(unlock::pending());
    }

    /**
     * The live price or SHA changed after the admin confirmed: nothing is sent.
     */
    public function test_price_changed(): void {
        $this->mock(['/api/plugin-unlock/verify' => [self::LOCKED]]);
        $this->assertSame('changed', unlock::buy(40, self::SHA)['outcome']);
        $this->assertSame('changed', unlock::buy(50, str_repeat('b', 64))['outcome']);
        $this->assertCount(0, $this->sent('/api/plugin-unlock'));
    }

    /**
     * Eligibility: only acquisitionMode "credit-unlock", a positive whole price, a valid SHA, an available status
     * and zipExists true may be bought.
     */
    public function test_release_verification(): void {
        $cases = [
            [[500, 'down'], 'unreachable'],
            [[200, ['plugins' => ['mod_other' => ['component' => 'mod_other', 'sha256' => self::SHA]]]], 'notlisted'],
            [self::catalogue(['zipExists' => false]), 'nozip'],
            [self::catalogue(['zipExists' => null]), 'nozip'],
            [self::catalogue(['sha256' => 'xyz']), 'nosha'],
            [self::catalogue(['creditsRequired' => 0]), 'noprice'],
            [self::catalogue(['creditsRequired' => -5]), 'noprice'],
            [self::catalogue(['creditsRequired' => 50.5]), 'noprice'],
            [self::catalogue(['creditsRequired' => 'fifty']), 'noprice'],
            [self::catalogue(['status' => 'hidden']), 'notavailable'],
            [self::catalogue(['status' => 'testing']), 'notavailable'],
            [self::catalogue(['acquisitionMode' => 'usd-purchase']), 'mode'],
            [self::catalogue(['acquisitionMode' => 'paid']), 'mode'],
            [self::catalogue(['acquisitionMode' => 'purchase']), 'mode'],
            [self::catalogue(['acquisitionMode' => 'buy']), 'mode'],
            [self::catalogue(['acquisitionMode' => 'Credit-Unlock']), 'mode'],
        ];
        foreach ($cases as [$reply, $reason]) {
            $this->mock(['/api/plugin-unlock/verify' => [self::LOCKED], '/api/plugin-versions' => [$reply]]);
            $review = unlock::review();
            $this->assertFalse($review['canbuy'], $reason);
            $this->assertSame('release', $review['blocked'], $reason);
            $this->assertSame($reason, $review['release']['reason']);
            $this->assertSame('changed', unlock::buy(50, self::SHA)['outcome'], $reason);
            $this->assertCount(0, $this->sent('/api/plugin-unlock'), $reason);
        }
        // The live manifest shape is accepted (a string of digits is a whole number too).
        $this->mock(['/api/plugin-unlock/verify' => [self::LOCKED],
            '/api/plugin-versions' => [self::catalogue(['creditsRequired' => '50'])]]);
        $this->assertTrue(unlock::review()['canbuy']);
    }

    /**
     * An unverifiable access check never offers a purchase.
     */
    public function test_unable_to_verify(): void {
        $this->mock(['/api/plugin-unlock/verify' => [[403, ['code' => 'NO_ENTITLEMENT', 'message' => 'no entitlement']]]]);
        $state = unlock::verify();
        $this->assertSame('unknown', $state['status']);
        $this->assertStringContainsString('NO_ENTITLEMENT', $state['error']);
        $this->assertSame('unverified', unlock::review()['blocked']);
        $this->assertSame('blocked', unlock::buy(50, self::SHA)['outcome']);
        $this->assertCount(0, $this->sent('/api/plugin-unlock'));
    }

    /**
     * Balance fields per route.
     */
    public function test_balance_fields(): void {
        $this->resetAfterTest();
        $this->assertSame(['credits' => 120, 'unlimited' => false], unlock::balance(['credits' => 120]));
        $this->assertSame(['credits' => null, 'unlimited' => true], unlock::balance(['credits' => -1]));
        $this->assertSame(['credits' => 70, 'unlimited' => false], unlock::balance(['remainingCredits' => 70]));
        $this->assertSame(['credits' => null, 'unlimited' => true], unlock::balance(['remainingCredits' => 'unlimited']));
        $this->assertSame(['credits' => 12, 'unlimited' => false], unlock::balance(['currentCredits' => 12]));
        $this->assertSame(['credits' => null, 'unlimited' => false], unlock::balance(['message' => 'x']));
    }

    /**
     * Server-side requests never follow redirects (credential-bearing bodies must not be re-sent elsewhere).
     */
    public function test_no_redirects(): void {
        $this->resetAfterTest();
        $options = unlock::http_options(['Accept' => 'application/json'], '{}', 20);
        $this->assertFalse($options['allow_redirects']);
        $this->assertFalse($options['http_errors']);
    }
}

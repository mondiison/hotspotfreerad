<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Services\ZeroTierControllerService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZeroTierControllerServiceTest extends TestCase
{
    private string $tokenPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokenPath = storage_path('framework/testing/zerotier-authtoken.secret');
        File::ensureDirectoryExists(dirname($this->tokenPath));
        File::put($this->tokenPath, "test-auth-token\n");

        config([
            'services.zerotier.controller_url' => 'http://127.0.0.1:9993',
            'services.zerotier.auth_token_path' => $this->tokenPath,
            'services.zerotier.network_id' => 'abcd1234abcd1234',
        ]);
    }

    protected function tearDown(): void
    {
        File::delete($this->tokenPath);

        parent::tearDown();
    }

    public function test_list_members_fails_cleanly_when_the_auth_token_is_not_readable(): void
    {
        config(['services.zerotier.auth_token_path' => '/does/not/exist']);

        $result = app(ZeroTierControllerService::class)->listMembers();

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    public function test_list_members_fails_cleanly_when_the_network_id_is_not_configured(): void
    {
        config(['services.zerotier.network_id' => 'YOUR_ZEROTIER_NETWORK_ID']);

        $result = app(ZeroTierControllerService::class)->listMembers();

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    public function test_list_members_maps_the_controller_response(): void
    {
        // Real controller shape, confirmed live: the bulk list is a flat
        // {address: revision} map, not an array of member objects -- full
        // detail needs a second GET per address, whose response has
        // authorized/ipAssignments as top-level fields (no "config" wrapper).
        Http::fake([
            'http://127.0.0.1:9993/controller/network/abcd1234abcd1234/member' => Http::response([
                'aaaa111111' => 3,
                'bbbb222222' => 1,
            ]),
            'http://127.0.0.1:9993/controller/network/abcd1234abcd1234/member/aaaa111111' => Http::response([
                'id' => 'aaaa111111',
                'authorized' => true,
                'ipAssignments' => ['10.9.0.10'],
            ]),
            'http://127.0.0.1:9993/controller/network/abcd1234abcd1234/member/bbbb222222' => Http::response([
                'id' => 'bbbb222222',
                'authorized' => false,
                'ipAssignments' => [],
            ]),
        ]);

        $result = app(ZeroTierControllerService::class)->listMembers();

        $this->assertTrue($result['success']);
        $this->assertSame([
            ['node_id' => 'aaaa111111', 'authorized' => true, 'ip_assignments' => ['10.9.0.10']],
            ['node_id' => 'bbbb222222', 'authorized' => false, 'ip_assignments' => []],
        ], $result['members']);

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-ZT1-Auth', 'test-auth-token')
            && $request->url() === 'http://127.0.0.1:9993/controller/network/abcd1234abcd1234/member'
        );
    }

    public function test_list_members_catches_a_connection_failure_instead_of_throwing(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $result = app(ZeroTierControllerService::class)->listMembers();

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    public function test_authorize_member_posts_the_expected_body(): void
    {
        Http::fake([
            'http://127.0.0.1:9993/controller/network/abcd1234abcd1234/member/*' => Http::response(['nodeId' => 'aaaa111111']),
        ]);

        $router = new Router([
            'zerotier_node_id' => 'aaaa111111',
            'zerotier_ip' => '10.9.0.10',
        ]);

        $result = app(ZeroTierControllerService::class)->authorizeMember($router);

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'http://127.0.0.1:9993/controller/network/abcd1234abcd1234/member/aaaa111111'
                && $body['authorized'] === true
                && $body['ipAssignments'] === ['10.9.0.10'];
        });
    }

    public function test_deauthorize_member_posts_authorized_false(): void
    {
        Http::fake([
            'http://127.0.0.1:9993/controller/network/abcd1234abcd1234/member/*' => Http::response(['nodeId' => 'aaaa111111']),
        ]);

        $result = app(ZeroTierControllerService::class)->deauthorizeMember('aaaa111111');

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['authorized'] === false;
        });
    }
}

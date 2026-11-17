<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CentralSessionMiddlewareTest extends TestCase
{
    private function request(string $method = 'GET', bool $csrf = false): Request
    {
        $request = Request::create('/api/fees', $method);
        $request->headers->set('Cookie', 'stayledger_session=opaque-session-cookie');
        if ($csrf) {
            $request->headers->set('X-CSRF-TOKEN', 'opaque-csrf-token');
        }
        return $request;
    }

    public function test_missing_cookie_is_rejected_without_reaching_controller(): void
    {
        $request = Request::create('/api/fees', 'GET');
        $response = (new EnsureAuthenticated)->handle(
            $request, fn () => new Response('UNEXPECTED')
        );
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_validated_central_session_is_accepted(): void
    {
        Http::fake(['*/me' => Http::response(['user' => [
            'id' => 5, 'name' => 'Staff', 'role' => 'staff', 'is_active' => true,
        ]], 200)]);

        $request = $this->request();
        $response = (new EnsureAuthenticated)->handle(
            $request, fn () => new Response('OK')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getContent());
        $this->assertSame(5, $request->attributes->get('stayledger_user')['id']);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/me')
            && $req->hasHeader('Cookie', 'stayledger_session=opaque-session-cookie'));
    }

    public function test_mutation_requires_csrf_and_central_verification(): void
    {
        $response = (new EnsureAuthenticated)->handle(
            $this->request('POST'), fn () => new Response('UNEXPECTED')
        );
        $this->assertSame(419, $response->getStatusCode());

        Http::fake(['*/integration/verify' => Http::response(['user' => [
            'id' => 7, 'role' => 'admin', 'is_active' => true,
        ]], 200)]);

        $response = (new EnsureAuthenticated)->handle(
            $this->request('POST', true), fn () => new Response('OK')
        );
        $this->assertSame(200, $response->getStatusCode());
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/integration/verify')
            && $req->hasHeader('X-CSRF-TOKEN', 'opaque-csrf-token'));
    }

    public function test_expired_session_cannot_reach_controller(): void
    {
        Http::fake(['*/me' => Http::response(['message' => 'Unauthorized'], 401)]);
        $response = (new EnsureAuthenticated)->handle(
            $this->request(), fn () => new Response('UNEXPECTED')
        );
        $this->assertSame(401, $response->getStatusCode());
    }
}

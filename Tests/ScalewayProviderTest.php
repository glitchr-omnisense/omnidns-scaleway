<?php

namespace Omnidns\Scaleway\Tests;

use Omnidns\Exception\InvalidConfigException;
use Omnidns\Exception\ProviderException;
use Omnidns\Model\Contact;
use Omnidns\Model\Record;
use Omnidns\Registry;
use Omnidns\Scaleway\ScalewayFactory;
use Omnidns\Scaleway\ScalewayProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scaleway's answers recorded, written from its Domains and DNS API
 * reference (no key at hand: not captured), each request checked: the
 * path, the X-Auth-Token, the body.
 */
final class ScalewayProviderTest extends TestCase
{
    private Router $router;

    public function testAvailability(): void
    {
        $provider = $this->provider([
            'GET /search-domains?domains=omnidns-test.fr&strict_search=true' => ['available_domains' => [['domain' => 'omnidns-test.fr', 'available' => true, 'tld' => ['name' => 'fr', 'dnssec_support' => true, 'duration_in_years_min' => 1, 'duration_in_years_max' => 10]]]],
            'GET /search-domains?domains=scaleway.com&strict_search=true' => ['available_domains' => [['domain' => 'scaleway.com', 'available' => false, 'tld' => ['name' => 'com']]]],
        ]);

        self::assertTrue($provider->check('omnidns-test.fr')->available);
        self::assertNull($provider->check('omnidns-test.fr')->price, 'Scaleway quotes no price here');
        self::assertSame([false, 'not available'], [($b = $provider->check('scaleway.com'))->available, $b->reason]);
        self::assertContains('X-Auth-Token: sk', $this->router->requests[0]['headers']);
    }

    public function testARegistrationWithAContactScalewayHolds(): void
    {
        $order = $this->provider(['POST /buy-domains' => ['project_id' => 'proj-1', 'task_id' => 'task-1', 'domains' => ['omnidns-test.fr']]], 'proj-1')->register('omnidns-test.fr', 1, Contact::handle('contact-9'));

        self::assertSame(['register', 'ordered', 'task-1'], [$order->action, $order->status, $order->id]);
        self::assertSame('{"domains":["omnidns-test.fr"],"duration_in_years":1,"project_id":"proj-1","owner_contact_id":"contact-9"}', $this->router->body('POST /buy-domains'));
    }

    public function testARegistrationWithAContactInFull(): void
    {
        $this->provider(['POST /buy-domains' => ['project_id' => 'proj-1', 'domains' => ['omnidns-test.fr']]], 'proj-1')->register('omnidns-test.fr', 2, new Contact('Ada', 'Lovelace', 'ada@example.org', '+33.123456789', '1 rue de la Paix', 'Paris', '75002', 'fr', Contact::COMPANY, 'ACME'));

        self::assertSame('{"domains":["omnidns-test.fr"],"duration_in_years":2,"project_id":"proj-1","owner_contact":{"legal_form":"corporate","firstname":"Ada","lastname":"Lovelace","company_name":"ACME","email":"ada@example.org","phone_number":"+33.123456789","address_line_1":"1 rue de la Paix","zip":"75002","city":"Paris","country":"FR"}}', $this->router->body('POST /buy-domains'));
    }

    public function testARegistrationNeedsAProject(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->provider([])->register('omnidns-test.fr', 1, Contact::handle('contact-9'));
    }

    public function testARenewal(): void
    {
        $order = $this->provider(['POST /renew-domains' => ['task_id' => 'task-2']])->renew('example.fr', 2);

        self::assertSame(['renew', 'task-2', 2], [$order->action, $order->id, $order->years]);
        self::assertSame('{"domains":["example.fr"],"duration_in_years":2,"force_late_renewal":false}', $this->router->body('POST /renew-domains'));
    }

    public function testTheDomainsAndTheirTerms(): void
    {
        $provider = $this->provider([
            'GET /registrar/domains?page=1&page_size=100&project_id=proj-1' => ['domains' => [['domain' => 'example.fr', 'expired_at' => '2027-03-01T00:00:00Z', 'auto_renew_status' => 'enabled', 'status' => 'active']], 'total_count' => 1],
            'GET /domains/example.fr' => ['domain' => 'example.fr', 'expired_at' => '2027-03-01T00:00:00Z', 'auto_renew_status' => 'disabled', 'status' => 'active', 'dns_zones' => [['domain' => 'example.fr', 'subdomain' => '', 'ns' => ['ns0.dom.scw.cloud', 'ns1.dom.scw.cloud'], 'status' => 'active']]],
        ], 'proj-1');

        self::assertSame(['example.fr'], $provider->domains());
        $info = $provider->domain('example.fr');
        self::assertSame(['2027-03-01', false, ['active'], ['ns0.dom.scw.cloud', 'ns1.dom.scw.cloud']], [$info->expiresAt?->format('Y-m-d'), $info->autoRenew, $info->status, $info->nameservers]);
    }

    public function testTheNameServers(): void
    {
        $provider = $this->provider([
            'GET /dns-zones/example.fr/nameservers' => ['ns' => [['name' => 'ns0.dom.scw.cloud', 'ip' => []], ['name' => 'ns1.dom.scw.cloud.', 'ip' => []]]],
            'PUT /dns-zones/example.fr/nameservers' => ['ns' => [['name' => 'ns1.example.net', 'ip' => []]]],
        ]);

        self::assertSame(['ns0.dom.scw.cloud', 'ns1.dom.scw.cloud'], $provider->nameservers('example.fr'));
        $provider->setNameservers('example.fr', ['NS1.example.net.']);
        self::assertSame('{"ns":[{"name":"ns1.example.net"}]}', $this->router->body('PUT /dns-zones/example.fr/nameservers'));
    }

    public function testTheRecords(): void
    {
        $records = $this->provider(['GET /dns-zones/example.fr/records?page=1&page_size=100' => ['records' => [
            ['id' => 'r1', 'name' => '', 'type' => 'A', 'data' => '192.0.2.1', 'ttl' => 3600, 'priority' => 0, 'comment' => null],
            ['id' => 'r2', 'name' => '', 'type' => 'MX', 'data' => 'mx.example.net.', 'ttl' => 3600, 'priority' => 10, 'comment' => null],
            ['id' => 'r3', 'name' => 'www', 'type' => 'ALIAS', 'data' => 'example.fr.', 'ttl' => 3600, 'priority' => 0, 'comment' => null],
        ], 'total_count' => 3]])->records('example.fr');

        self::assertSame([['A', '', '192.0.2.1', null, 'r1'], ['MX', '', 'mx.example.net.', 10, 'r2']], array_map(static fn (Record $r) => [$r->type, $r->name, $r->content, $r->priority, $r->id], $records));
    }

    public function testARecordAddedSetAndDeleted(): void
    {
        $provider = $this->provider([
            'PATCH /dns-zones/example.fr/records' => ['records' => [['id' => 'r9', 'name' => '_dmarc', 'type' => 'TXT', 'data' => '"v=DMARC1; p=none"', 'ttl' => 300, 'priority' => 0]]],
            'GET /dns-zones/example.fr/records?page=1&page_size=100&type=TXT&name=_dmarc' => ['records' => [['id' => 'r9', 'name' => '_dmarc', 'type' => 'TXT', 'data' => '"v=DMARC1; p=none"', 'ttl' => 300, 'priority' => 0]], 'total_count' => 1],
        ]);

        self::assertSame('r9', $provider->add('example.fr', new Record('TXT', '_dmarc', '"v=DMARC1; p=none"', 300))->id);
        self::assertSame('{"changes":[{"add":{"records":[{"name":"_dmarc","type":"TXT","data":"\u0022v=DMARC1; p=none\u0022","ttl":300}]}}],"return_all_records":false}', $this->router->body('PATCH /dns-zones/example.fr/records'));

        $provider->update('example.fr', new Record('TXT', '_dmarc', 'v=DMARC1; p=none'), new Record('TXT', '_dmarc', '"v=DMARC1; p=reject"'));
        self::assertSame('{"changes":[{"set":{"id":"r9","records":[{"name":"_dmarc","type":"TXT","data":"\u0022v=DMARC1; p=reject\u0022","ttl":3600}]}}],"return_all_records":false}', $this->router->body('PATCH /dns-zones/example.fr/records'), 'found by name, type and content, set by its id');

        $provider->delete('example.fr', new Record('TXT', '_dmarc', 'x', id: 'r9'));
        self::assertSame('{"changes":[{"delete":{"id":"r9"}}],"return_all_records":false}', $this->router->body('PATCH /dns-zones/example.fr/records'));
    }

    public function testAnErrorScalewayAnswers(): void
    {
        $provider = $this->provider(['GET /domains/nowhere.fr' => [403, ['message' => 'permissions denied', 'type' => 'permissions_denied', 'details' => []]]]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Scaleway answered 403 (permissions denied) for the domain nowhere.fr.');
        $provider->domain('nowhere.fr');
    }

    public function testTheFactory(): void
    {
        $registry = new Registry([new ScalewayFactory((new Router([], ''))->client())], ['scw' => ['factory' => 'scaleway', 'options' => ['secret_key' => 'sk']]]);

        self::assertInstanceOf(ScalewayProvider::class, $registry->get('scw'));
    }

    /** @param array<string, mixed> $routes */
    private function provider(array $routes, ?string $projectId = null): ScalewayProvider
    {
        $this->router = new Router($routes, ScalewayProvider::ENDPOINT);

        return new ScalewayProvider($this->router->client(), 'sk', $projectId);
    }
}

<?php

namespace Omnidns\Scaleway;

use Omnidns\AvailabilityInterface;
use Omnidns\Capabilities;
use Omnidns\DnsInterface;
use Omnidns\DomainsInterface;
use Omnidns\Exception\InvalidArgumentException;
use Omnidns\Exception\InvalidConfigException;
use Omnidns\Exception\ProviderException;
use Omnidns\Http\Json;
use Omnidns\Model\Availability;
use Omnidns\Model\Contact;
use Omnidns\Model\DomainInfo;
use Omnidns\Model\DomainName;
use Omnidns\Model\Order;
use Omnidns\Model\Record;
use Omnidns\NameserversInterface;
use Omnidns\RegistrarInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Scaleway's Domains and DNS API (v2beta1): the registrar's search, purchase
 * and renewal (/search-domains, /buy-domains, /renew-domains), the project's
 * domains, a zone's name servers and records - a record changed through the
 * zone's PATCH of changes (add, set by id, delete by id).
 */
final class ScalewayProvider implements AvailabilityInterface, RegistrarInterface, DomainsInterface, NameserversInterface, DnsInterface
{
    public const ENDPOINT = 'https://api.scaleway.com/domain/v2beta1';
    private const PAGE = 100;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $secretKey,
        private readonly ?string $projectId = null,
        private readonly string $endpoint = self::ENDPOINT,
    ) {
    }

    public function getName(): string
    {
        return 'scaleway';
    }

    public function getTitle(): string
    {
        return 'Scaleway';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(true, true, true, true, true, Record::TYPES, [
            'https://www.scaleway.com/en/contracts/',
            'https://www.scaleway.com/en/developers/api/domains-and-dns/',
        ]);
    }

    public function check(string $domain): Availability
    {
        $domain = DomainName::normalize($domain);
        $answer = $this->call('GET', '/search-domains', ['query' => ['domains' => $domain, 'strict_search' => 'true']], "the availability of $domain");
        foreach (Json::list($answer, 'available_domains') as $item) {
            if ($domain === Json::string($item, 'domain')) {
                $available = Json::bool($item, 'available');

                return new Availability($domain, $available, reason: $available ? null : 'not available');
            }
        }

        return new Availability($domain, false, reason: 'not offered');
    }

    public function register(string $domain, int $years = 1, ?Contact $owner = null): Order
    {
        $domain = DomainName::normalize($domain);
        self::years($years);
        if (null === $owner) {
            throw new InvalidArgumentException('Scaleway needs the owner to register a domain: a contact it holds (Contact::handle()) or one in full.');
        }
        $body = ['domains' => [$domain], 'duration_in_years' => $years, 'project_id' => $this->projectId ?? throw new InvalidConfigException('The "scaleway" provider needs a project_id to register a domain.')];
        if (null !== $owner->handle) {
            $body['owner_contact_id'] = $owner->handle;
        } else {
            $body['owner_contact'] = array_filter([
                'legal_form' => Contact::INDIVIDUAL === $owner->type ? 'individual' : (Contact::ASSOCIATION === $owner->type ? 'association' : 'corporate'),
                'firstname' => $owner->firstName,
                'lastname' => $owner->lastName,
                'company_name' => $owner->organization,
                'email' => $owner->email,
                'phone_number' => $owner->phone,
                'address_line_1' => $owner->street,
                'zip' => $owner->zip,
                'city' => $owner->city,
                'country' => strtoupper($owner->country),
            ], static fn ($v) => null !== $v && '' !== $v);
        }
        $answer = $this->call('POST', '/buy-domains', ['json' => $body], "the registration of $domain");

        return new Order($domain, Order::REGISTER, 'ordered', Json::string($answer, 'task_id'), years: $years);
    }

    public function renew(string $domain, int $years = 1): Order
    {
        $domain = DomainName::normalize($domain);
        self::years($years);
        $answer = $this->call('POST', '/renew-domains', ['json' => ['domains' => [$domain], 'duration_in_years' => $years, 'force_late_renewal' => false]], "the renewal of $domain");

        return new Order($domain, Order::RENEW, 'ordered', Json::string($answer, 'task_id'), years: $years);
    }

    public function domains(): array
    {
        $domains = [];
        for ($page = 1; ; ++$page) {
            $answer = $this->call('GET', '/registrar/domains', ['query' => array_filter(['page' => $page, 'page_size' => self::PAGE, 'project_id' => $this->projectId])], 'the domains');
            foreach (Json::list($answer, 'domains') as $item) {
                if (null !== $name = Json::string($item, 'domain')) {
                    $domains[] = $name;
                }
            }
            if (\count($domains) >= (Json::int($answer, 'total_count') ?? 0) || [] === Json::list($answer, 'domains')) {
                return $domains;
            }
        }
    }

    public function domain(string $domain): DomainInfo
    {
        $domain = DomainName::normalize($domain);
        $data = $this->call('GET', "/domains/$domain", [], "the domain $domain");
        $auto = Json::string($data, 'auto_renew_status');
        $nameservers = [];
        foreach (Json::list($data, 'dns_zones') as $zone) {
            if ('' === (string) Json::string($zone, 'subdomain')) {
                $nameservers = array_values(array_map(static fn (string $ns) => rtrim(strtolower($ns), '.'), array_filter(Json::list($zone, 'ns'), 'is_string')));
            }
        }
        $status = Json::string($data, 'status');

        return new DomainInfo($domain, Json::date($data, 'expired_at'), null === $auto ? null : 'enabled' === $auto || 'enabling' === $auto, null === $status ? [] : [$status], $nameservers ?: null);
    }

    public function nameservers(string $domain): array
    {
        $domain = DomainName::normalize($domain);
        $hosts = [];
        foreach (Json::list($this->call('GET', "/dns-zones/$domain/nameservers", [], "the name servers of $domain"), 'ns') as $ns) {
            if (null !== $name = Json::string($ns, 'name')) {
                $hosts[] = rtrim(strtolower($name), '.');
            }
        }

        return $hosts;
    }

    public function setNameservers(string $domain, array $nameservers): void
    {
        $domain = DomainName::normalize($domain);
        $this->call('PUT', "/dns-zones/$domain/nameservers", ['json' => ['ns' => array_map(static fn (string $ns) => ['name' => rtrim(strtolower($ns), '.')], $nameservers)]], "the name servers of $domain");
    }

    public function records(string $zone, ?string $type = null, ?string $name = null): array
    {
        $zone = DomainName::normalize($zone);
        $records = [];
        $seen = 0;
        for ($page = 1; ; ++$page) {
            $answer = $this->call('GET', "/dns-zones/$zone/records", ['query' => array_filter(['page' => $page, 'page_size' => self::PAGE, 'type' => null === $type ? null : strtoupper($type), 'name' => null === $name ? null : Record::normalizeName($name)], static fn ($v) => null !== $v)], "the records of $zone");
            $list = Json::list($answer, 'records');
            foreach ($list as $data) {
                $record = self::record($data);
                if (null !== $record && (null === $name || $record->name === Record::normalizeName($name))) {
                    $records[] = $record;
                }
            }
            $seen += \count($list);
            if ([] === $list || $seen >= (Json::int($answer, 'total_count') ?? 0)) {
                return $records;
            }
        }
    }

    public function add(string $zone, Record $record): Record
    {
        $zone = DomainName::normalize($zone);
        $answer = $this->patch($zone, ['add' => ['records' => [self::data($record)]]]);

        return self::record(Json::get($answer, 'records', 0)) ?? $record;
    }

    public function update(string $zone, Record $current, Record $new): Record
    {
        $zone = DomainName::normalize($zone);
        $found = $this->find($zone, $current);
        $answer = $this->patch($zone, ['set' => ['id' => $found->id, 'records' => [self::data($new)]]]);

        return self::record(Json::get($answer, 'records', 0)) ?? $new->withId($found->id);
    }

    public function delete(string $zone, Record $record): void
    {
        $zone = DomainName::normalize($zone);
        $found = $this->find($zone, $record);
        $this->patch($zone, ['delete' => ['id' => $found->id]]);
    }

    private function find(string $zone, Record $record): Record
    {
        if (null !== $record->id) {
            return $record;
        }

        return $record->findIn($this->records($zone, $record->type, $record->name)) ?? throw ProviderException::answered('Scaleway', "a record of $zone", 'no such record');
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array<mixed>
     */
    private function patch(string $zone, array $change): array
    {
        return $this->call('PATCH', "/dns-zones/$zone/records", ['json' => ['changes' => [$change], 'return_all_records' => false]], "a record of $zone");
    }

    /** @return array<string, mixed> */
    private static function data(Record $record): array
    {
        return array_filter(['name' => $record->name, 'type' => $record->type, 'data' => $record->content, 'ttl' => $record->ttl ?? 3600, 'priority' => $record->priority], static fn ($v) => null !== $v);
    }

    private static function record(mixed $data): ?Record
    {
        $type = Json::string($data, 'type');
        if (null === $type || !\in_array($type, Record::TYPES, true)) {
            return null;
        }

        return new Record($type, (string) Json::string($data, 'name'), (string) Json::string($data, 'data'), Json::int($data, 'ttl'), 'MX' === $type ? Json::int($data, 'priority') : null, Json::string($data, 'id'));
    }

    private static function years(int $years): void
    {
        if ($years < 1 || $years > 10) {
            throw new InvalidArgumentException(\sprintf('A domain is registered or renewed for 1 to 10 years, not %d.', $years));
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function call(string $method, string $path, array $options, string $what): array
    {
        return Json::request($this->http, $method, rtrim($this->endpoint, '/').$path, ['headers' => ['X-Auth-Token' => $this->secretKey, 'Accept' => 'application/json']] + $options, 'Scaleway', $what);
    }
}

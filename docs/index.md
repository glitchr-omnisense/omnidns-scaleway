# omnidns/scaleway

Scaleway for [omnisense/omnidns](https://github.com/glitchr-omnisense/omnidns): whether a domain is
free, registering and renewing it, the project's domains and their terms, their name servers, and
the records of their zones - Scaleway's Domains and DNS API (`https://api.scaleway.com/domain/v2beta1`,
still a beta version: its shapes may change) on the application's HTTP client. Scaleway publishes
no PHP SDK and none maintained was found.

| Operation | Scaleway API |
|---|---|
| `check()` | `GET /search-domains?domains=&strict_search=true` |
| `register()` | `POST /buy-domains` |
| `renew()` | `POST /renew-domains` |
| `domains()`, `domain()` | `GET /registrar/domains` (100 a page), `GET /domains/{name}` |
| `nameservers()`, `setNameservers()` | `GET`, `PUT /dns-zones/{name}/nameservers` |
| `records()` | `GET /dns-zones/{zone}/records` (100 a page) |
| `add()`, `update()`, `delete()` | `PATCH /dns-zones/{zone}/records`, one change: `add`, `set` by id, `delete` by id |

## Installation

```sh
composer require omnidns/scaleway symfony/http-client
```

Requires PHP 8.2, `omnisense/omnidns` (1.x) and `symfony/http-client-contracts` (^3.0): the
application gives the HTTP client to `ScalewayFactory`'s constructor.

## Credentials

An **API key**'s secret with DomainsDNSFullAccess (or DomainsDNSReadOnly to read), and the
**project id** domains are bought into.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `secret_key` | string | - | yes | sent as `X-Auth-Token` |
| `project_id` | string | none | to register | the project domains are bought into; it also narrows the domains listed (the key's default project otherwise) |
| `endpoint` | string | `https://api.scaleway.com/domain/v2beta1` | no | |

## Capabilities

| Contract | Supported |
|---|---|
| `AvailabilityInterface` | yes, no price |
| `RegistrarInterface` | yes |
| `DomainsInterface` | yes, the apex zone's name servers in the same answer |
| `NameserversInterface` | yes |
| `DnsInterface` | yes; ALIAS and the other types outside omnidns's left out |

Terms: Scaleway's contracts, the Domains and DNS API.

## Behaviour

**Availability.** Strict search: the entry for the domain, `available` as Scaleway says; no price
quoted (`price` null); not in the answer: `not offered`.

**Registration.** The owner is required: a contact Scaleway holds (`Contact::handle()`, sent as
`owner_contact_id`) or in full (`owner_contact`: `legal_form` `individual`, `association` or
`corporate` for a company, names, `company_name`, e-mail, `phone_number`, `address_line_1`, zip,
city, country). `project_id` is required (`InvalidConfigException` otherwise). Billed to the
organization. The `Order`: status `ordered`, Scaleway's `task_id` as its id, no price.

**Renewal.** `duration_in_years`, `force_late_renewal: false`; the same order.

**Domains.** Pages of 100 (narrowed to `project_id` when set) until the total count. One
domain: `expired_at`, `auto_renew_status` (`enabled` or `enabling` is true, another value false),
`status`, the name servers of its apex zone (`dns_zones[].ns` where `subdomain` is empty); no
creation date.

**Records.** Filtered by type and name; each record's id, name, `data`, TTL and an MX's
priority. A change is the zone's PATCH with one change and `return_all_records: false`; an added
record has a TTL of 3600 when it gives none. An update or a deletion of a record without an id
finds it by content first.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnidns\Model\Contact;
use Omnidns\Model\Record;
use Omnidns\Registry;
use Omnidns\Scaleway\ScalewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new ScalewayFactory(HttpClient::create(['timeout' => 20]))], [
    'scaleway' => ['factory' => 'scaleway', 'options' => ['secret_key' => getenv('SCW_SECRET_KEY'), 'project_id' => getenv('SCW_PROJECT_ID')]],
]);

if ($registry->availability('scaleway')->check('example.fr')->available) {
    $order = $registry->registrar('scaleway')->register('example.fr', 1, Contact::handle(getenv('SCW_CONTACT_ID')));
    echo 'task ', $order->id, "\n";
}
$registry->dns('scaleway')->add('example.fr', new Record('A', '', '192.0.2.10'));
```

Not run live (no key); the project check was run on 2026-10-26 (`The "scaleway" provider needs a
project_id to register a domain.`).

## Errors

- A status of 300 or more: `ProviderException` `Scaleway answered <status> (<Scaleway's
  message>) for <what>.`, the status as its code.
- A network failure: `UnreachableException`.
- No owner, a duration outside 1 to 10 years: `InvalidArgumentException`; no `project_id` to
  register: `InvalidConfigException`.

## Verified, and not

`Tests/ScalewayProviderTest.php`, on recorded answers written from Scaleway's API reference (not
captured): availability, a registration with a held contact and with one in full, the project
required, a renewal, the domains and their terms, the name servers, the records, an addition, a
set found by content, a deletion by id, an error's message. Green in the family's harness on
2026-10-26. **Not run live**: no Scaleway key at hand.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Scaleway SAS. Whoever runs it complies with Scaleway's
[contracts](https://www.scaleway.com/en/contracts/) and pays the orders it places; see the README's
Responsibility section.

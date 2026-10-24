# omnidns/scaleway

Scaleway for [omniscient/omnidns](https://github.com/glitchr-studio/omnidns), under any PHP host:
whether a domain is free, registering and renewing it, the project's domains and their terms,
their name servers, and the records of their zones - Scaleway's Domains and DNS API (v2beta1) on
the application's HTTP client (any `symfony/http-client-contracts` implementation).

```php
use Omnidns\Registry;
use Omnidns\Scaleway\ScalewayFactory;

$registry = new Registry([new ScalewayFactory($httpClient)], [
    'scaleway' => ['factory' => 'scaleway', 'options' => ['secret_key' => $secretKey, 'project_id' => $projectId]],
]);
$registry->domains('scaleway')->domains();
```

Options: `secret_key` (required: an API key's secret with DomainsDNSFullAccess, or
DomainsDNSReadOnly to read), `project_id` (required to register; it also narrows the domains
listed), `endpoint`.

**Reuse**: Scaleway publishes no PHP SDK and none maintained was found; this one is a handful of
calls on the application's client.

- **Availability**: `/search-domains`, strict; Scaleway quotes no price there (`price` null).
- **Registration**: `/buy-domains` into the project, the owner as a contact Scaleway holds
  (`Contact::handle()`) or in full; billed to the organization.
- **Renewal**: `/renew-domains`.
- **Domains**: `/registrar/domains` (100 a page), each one's term, auto-renewal, status and name
  servers.
- **Records**: `/dns-zones/{zone}/records`, changed through the zone's PATCH of changes (add, set
  by id, delete by id). Types outside omnidns's (ALIAS...) are left out.

## Terms

Scaleway's [contracts](https://www.scaleway.com/en/contracts/) and its
[Domains and DNS API](https://www.scaleway.com/en/developers/api/domains-and-dns/), still a beta
version (v2beta1): its shapes may change. A registration or a renewal is a purchase from Scaleway.

## Verified, and not

- Verified on recorded answers, written from Scaleway's API reference (not captured): availability,
  a registration with a held contact and with one in full, the project required, a renewal, the
  domains and their terms, the name servers, the records, an addition, a set found by content, a
  deletion by id, an error's message; `BareTest`; PHPStan at the highest level.
- **Not verified live**: no Scaleway key at hand.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Scaleway SAS. It reaches their service on behalf of whoever runs it; its MIT licence covers this
package's code only and grants no right to the service or its data. Whoever installs and runs it
is responsible for complying with Scaleway's terms, for paying the orders it places, for obtaining what
they require (an account, a key, a contract), and for any use made of what it returns. The authors
publish it free of charge and accept no liability for how it is used (see [LICENSE](LICENSE)).

## Licence

MIT.

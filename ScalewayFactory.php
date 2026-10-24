<?php

namespace Omnidns\Scaleway;

use Omnidns\Config;
use Omnidns\ProviderFactory;
use Omnidns\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Scaleway, from its options:
 *
 *   secret_key  an API key's secret (required), with DomainsDNSFullAccess (or DomainsDNSReadOnly to read)
 *   project_id  the project domains are bought into and listed from (required to register; the key's
 *               default project otherwise)
 *   endpoint    https://api.scaleway.com/domain/v2beta1
 */
final class ScalewayFactory extends ProviderFactory
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnidns.factory_name' => 'scaleway',
            'omnidns.factory_title' => 'Scaleway',
            'omnidns.required_options' => ['secret_key'],
            'project_id' => '',
            'endpoint' => ScalewayProvider::ENDPOINT,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new ScalewayProvider($this->http, (string) $c->string('secret_key'), $c->string('project_id'), $c->string('endpoint') ?? ScalewayProvider::ENDPOINT);
    }
}

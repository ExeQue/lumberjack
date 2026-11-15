<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Enums\HealthResultStatus;
use SPFLib\Decoder;
use SPFLib\Exception as SpfException;
use SPFLib\OnlineSemanticValidator;
use SPFLib\Semantic\OnlineIssue;
use SPFLib\Term\Mechanism;
use SPFLib\Term\Mechanism\AllMechanism;
use SPFLib\Term\Modifier;
use SPFLib\Term\Modifier\RedirectModifier;

/**
 * Reads what receiving mail servers look up about a domain that sends mail (MX, SPF,
 * DMARC, DKIM keys and the reverse DNS of its mail servers) and says what they would
 * object to. DNS only: connects to nothing and sends no mail.
 */
class MailDomainInspector
{
    /** DMARC policies from weakest to strongest. */
    public const array POLICIES = ['none', 'quarantine', 'reject'];

    public const int MAX_SELECTORS = 5;

    /** ponytail: reverse DNS of the first mail servers only, which bounds the lookups of one run. */
    private const int MAX_MAIL_HOSTS = 3;

    /** @var list<string> */
    private array $failed = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly DnsResolver $dns)
    {
    }

    /**
     * @param  list<string>  $selectors  the DKIM selectors the domain signs with; DNS cannot list them
     * @param  string  $minPolicy  the weakest DMARC policy that does not warn
     */
    public function inspect(string $domain, array $selectors = [], string $minPolicy = 'quarantine'): HealthResultData
    {
        $this->failed = [];
        $this->warnings = [];
        $domain = strtolower(rtrim(trim($domain), '.'));

        $this->mailServers($domain);
        $this->spf($domain);
        $policy = $this->dmarc($domain, $minPolicy);

        foreach (array_slice($selectors, 0, self::MAX_SELECTORS) as $selector) {
            $this->dkim($domain, $selector);
        }

        $metrics = ['failed' => count($this->failed), 'warnings' => count($this->warnings)];
        $found = implode(' ', [...$this->failed, ...$this->warnings]);

        return match (true) {
            $this->failed !== [] => new HealthResultData(HealthResultStatus::Failed, HealthResultData::message($found), $metrics),
            $this->warnings !== [] => new HealthResultData(HealthResultStatus::Warning, HealthResultData::message($found), $metrics),
            default => new HealthResultData(HealthResultStatus::Ok, __($selectors === [] ? 'health.results.mail_ok' : 'health.results.mail_ok_dkim', ['policy' => $policy]), $metrics),
        };
    }

    /**
     * A domain that only sends may have no MX; one that has them needs reverse DNS that
     * points back, or the large receivers refuse its mail.
     */
    private function mailServers(string $domain): void
    {
        $hosts = array_map(fn (string $host): string => strtolower(rtrim($host, '.')), $this->dns->records($domain, 'MX'));

        if ($hosts === []) {
            $this->warnings[] = __('health.results.mail_mx_none');

            return;
        }

        if (array_filter($hosts) === []) {
            $this->failed[] = __('health.results.mail_mx_null');

            return;
        }

        foreach (array_slice(array_values(array_filter($hosts)), 0, self::MAX_MAIL_HOSTS) as $host) {
            foreach ([...$this->dns->records($host, 'A'), ...$this->dns->records($host, 'AAAA')] as $ip) {
                $this->reverseDns($host, $ip);
            }
        }
    }

    private function reverseDns(string $host, string $ip): void
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return;
        }

        $names = $this->dns->records(strlen($packed) === 4
            ? implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa'
            : implode('.', array_reverse(str_split(bin2hex($packed)))).'.ip6.arpa', 'PTR');

        if ($names === []) {
            $this->failed[] = __('health.results.mail_ptr_none', ['ip' => $ip, 'host' => $host]);

            return;
        }

        foreach ($names as $name) {
            foreach ([...$this->dns->records($name, 'A'), ...$this->dns->records($name, 'AAAA')] as $forward) {
                if (@inet_pton($forward) === $packed) {
                    return;
                }
            }
        }

        $this->failed[] = __('health.results.mail_ptr_unconfirmed', ['ip' => $ip, 'name' => rtrim($names[0], '.')]);
    }

    /**
     * One record that parses, whose includes resolve within the 10 lookups a receiver
     * makes, and that does not end by letting anyone send.
     */
    private function spf(string $domain): void
    {
        $records = array_values(array_filter($this->dns->records($domain, 'TXT'), fn (string $txt): bool => preg_match('/^v=spf1( |$)/i', $txt) === 1));

        if (count($records) !== 1) {
            $this->failed[] = $records === [] ? __('health.results.mail_spf_none') : __('health.results.mail_spf_many', ['count' => count($records)]);

            return;
        }

        $decoder = new Decoder(new SpfDnsResolver($this->dns));

        try {
            $record = $decoder->getRecordFromTXT($records[0]);
        } catch (SpfException $e) {
            $this->failed[] = __('health.results.mail_spf_invalid', ['error' => $e->getMessage()]);

            return;
        }

        if ($record === null) {
            return;
        }

        $lookups = false;

        foreach (new OnlineSemanticValidator($decoder)->validateRecord($record, $domain) as $issue) {
            $own = $issue->getDomain() === $domain;

            match (true) {
                // The record's own count and the count with its includes say the same thing.
                in_array($issue->getCode(), [OnlineIssue::CODE_TOO_MANY_DNS_LOOKUPS, OnlineIssue::CODE_TOO_MANY_DNS_LOOKUPS_ONLINE], true) => $lookups = true,
                $issue->getLevel() === OnlineIssue::LEVEL_FATAL && ! $own => $this->failed[] = __('health.results.mail_spf_include', ['domain' => $issue->getDomain()]),
                $issue->getLevel() === OnlineIssue::LEVEL_FATAL => $this->failed[] = __('health.results.mail_spf_invalid', ['error' => $issue->getDescription()]),
                // What an included record of someone else's could do better is theirs to fix.
                $issue->getLevel() === OnlineIssue::LEVEL_WARNING && $own => $this->warnings[] = __('health.results.mail_spf_warning', ['warning' => $issue->getDescription()]),
                default => null,
            };
        }

        if ($lookups) {
            $this->failed[] = __('health.results.mail_spf_lookups');
        }

        $all = array_find($record->getMechanisms(), fn (Mechanism $mechanism): bool => $mechanism instanceof AllMechanism);
        $redirects = array_find($record->getModifiers(), fn (Modifier $modifier): bool => $modifier instanceof RedirectModifier) !== null;

        match (true) {
            $all === null && ! $redirects => $this->warnings[] = __('health.results.mail_spf_open'),
            $all?->getQualifier() === Mechanism::QUALIFIER_PASS => $this->failed[] = __('health.results.mail_spf_pass_all'),
            $all?->getQualifier() === Mechanism::QUALIFIER_NEUTRAL => $this->warnings[] = __('health.results.mail_spf_neutral'),
            default => null,
        };
    }

    /**
     * The record of the domain itself or, without one, of the nearest parent that has
     * one, whose subdomain policy (sp) then applies when it names one.
     *
     * @return string the policy that applies, empty without a valid one
     */
    private function dmarc(string $domain, string $minPolicy): string
    {
        $labels = explode('.', $domain);

        for ($skip = 0; count($labels) - $skip >= 2; $skip++) {
            $records = array_values(array_filter(
                $this->dns->records('_dmarc.'.implode('.', array_slice($labels, $skip)), 'TXT'),
                fn (string $txt): bool => preg_match('/^v=DMARC1\s*(;|$)/i', trim($txt)) === 1,
            ));

            if ($records !== []) {
                break;
            }
        }

        if (count($records ?? []) !== 1) {
            $this->failed[] = ($records ?? []) === [] ? __('health.results.mail_dmarc_none') : __('health.results.mail_dmarc_many', ['count' => count($records)]);

            return '';
        }

        $tags = self::tags($records[0]);
        $policy = strtolower($skip > 0 ? ($tags['sp'] ?? $tags['p'] ?? '') : ($tags['p'] ?? ''));

        if (! in_array($policy, self::POLICIES, true)) {
            $this->failed[] = __('health.results.mail_dmarc_invalid');

            return '';
        }

        if (array_search($policy, self::POLICIES, true) < array_search($minPolicy, self::POLICIES, true)) {
            $this->warnings[] = __('health.results.mail_dmarc_weak', ['policy' => $policy, 'min' => $minPolicy]);
        } elseif ($policy !== 'none' && is_numeric($tags['pct'] ?? null) && (int) $tags['pct'] < 100) {
            $this->warnings[] = __('health.results.mail_dmarc_pct', ['pct' => (int) $tags['pct']]);
        }

        return $policy;
    }

    private function dkim(string $domain, string $selector): void
    {
        $records = $this->dns->records($selector.'._domainkey.'.$domain, 'TXT');

        match (true) {
            $records === [] => $this->failed[] = __('health.results.mail_dkim_none', ['selector' => $selector]),
            (self::tags($records[0])['p'] ?? '') === '' => $this->failed[] = __('health.results.mail_dkim_revoked', ['selector' => $selector]),
            default => null,
        };
    }

    /**
     * The tag=value pairs of a DMARC or DKIM record.
     *
     * @return array<string, string>
     */
    private static function tags(string $record): array
    {
        $tags = [];

        foreach (explode(';', $record) as $pair) {
            [$tag, $value] = [...explode('=', $pair, 2), ''];
            $tags[strtolower(trim($tag))] = trim($value);
        }

        return $tags;
    }
}
